<?php

namespace Tests\Feature;

use App\Models\CashMovement;
use App\Models\CashProjection;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Supply;
use App\Models\TariffProfile;
use App\Models\User;
use App\Services\CommercialDashboard;
use App\Services\DashboardPresentation;
use App\Services\ProductComposition;
use App\Services\SalesLedger;
use App\Services\SupplyCatalog;
use App\Services\TariffCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class CommercialDashboardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            throw new \RuntimeException('Dashboard tests require SQLite in memory.');
        }
        $this->travelTo('2026-10-22 12:00:00');
        $this->withoutVite();
        $this->artisan('migrate', ['--force' => true, '--no-interaction' => true])->assertExitCode(0);
        $this->actingAs(User::factory()->create());
    }

    public function test_empty_dashboard_is_home_and_does_not_create_financial_data(): void
    {
        $this->get('/')->assertRedirect(route('commercial-dashboard'));

        $this->get(route('commercial-dashboard'))->assertOk()->assertSee('Painel Comercial')
            ->assertSee('Nenhuma venda não cancelada registrada')->assertSee('Projeção ainda não cadastrada')
            ->assertSee('Nenhuma pendência identificada nos dados disponíveis.')
            ->assertSee('Nenhum produto ativo cadastrado')->assertSee('Nenhuma regra de tarifa ativa');

        $this->assertDatabaseCount('cash_projections', 0);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_receipts', 0);
    }

    public function test_sales_indicators_reuse_ledger_and_handle_partial_divergent_incomplete_and_cancelled_sales(): void
    {
        $product = $this->product();
        $ledger = app(SalesLedger::class);
        $partial = $this->sale($product, 'PARTIAL', '100.00', '10.00');
        $this->receive($partial, '30.00');
        $overpaid = $this->sale($product, 'OVERPAID', '200.00', '20.00');
        $this->receive($overpaid, '200.00');
        $this->sale($product, 'PENDING', '50.00', '5.00');
        $cancelled = $this->sale($product, 'CANCELLED', '300.00', '30.00');
        $this->receive($cancelled, '100.00');
        $ledger->cancel($cancelled, 'Cancelado', $cancelled->fresh()->version);
        $this->sale($product, 'UNKNOWN', null, null);

        $overview = app(CommercialDashboard::class)->snapshot();

        $this->assertSame($ledger->summary(), $overview['sales']);
        foreach (['count' => 4, 'gross' => '350.00', 'expected' => '315.00', 'received' => '230.00', 'pending' => '105.00',
            'divergent_count' => 1, 'partial_count' => 1, 'pending_count' => 1, 'unknown_gross' => 1, 'unknown_expected' => 1, 'incomplete_financial' => 1] as $key => $value) {
            $this->assertSame($value, $overview['sales'][$key], $key);
        }
        $this->assertSame('100.00', $overview['sales']['cancelled_received']);
        $this->assertCount(1, $overview['product_sales']);
        $this->assertSame(4, $overview['product_sales'][0]['quantity']);
        $this->assertSame('350.00', $overview['product_sales'][0]['gross']);
        $this->assertSame('315.00', $overview['product_sales'][0]['expected']);
        $this->assertSame('230.00', $overview['product_sales'][0]['received']);
        $this->assertSame('105.00', $overview['product_sales'][0]['pending']);
        $this->assertSame(1, $overview['product_sales'][0]['unknown_expected']);
        $labels = array_column($overview['alerts'], 'label');
        $this->assertContains('1 venda(s) com repasse divergente', $labels);
        $this->assertContains('1 venda(s) com recebimento parcial', $labels);
        $this->assertContains('1 venda(s) com repasse pendente', $labels);
        $this->assertContains('1 venda(s) com informações financeiras incompletas', $labels);
        $this->get(route('commercial-dashboard'))->assertOk()->assertSee('R$ 350,00')->assertSee('R$ 230,00')->assertSee('total limitado aos valores conhecidos.');
    }

    public function test_all_unknown_amounts_are_shown_as_unknown_instead_of_zero_totals(): void
    {
        $this->sale($this->product(), 'UNKNOWN', null, null);

        $this->get(route('commercial-dashboard'))->assertOk()->assertSee('Aguardando dados')
            ->assertSee('1 venda(s) sem dados suficientes')->assertDontSee('Nenhuma venda não cancelada registrada');

        $overview = app(CommercialDashboard::class)->snapshot();
        $this->assertSame(1, $overview['sales']['unknown_expected']);
        $this->assertSame(0, $overview['sales']['divergent_count']);
        $this->assertSame(0, $overview['sales']['pending_count']);
    }

    public function test_cash_forecast_is_independent_of_actual_sales_and_uses_saved_56_day_horizon(): void
    {
        $sale = $this->sale($this->product(), 'REAL-SALE', '1000.00', '0.00');
        $this->receive($sale, '1000.00');
        $projection = CashProjection::factory()->create(['opening_balance' => '100.00', 'starts_on' => '2026-10-22']);
        CashMovement::factory()->for($projection)->createMany([
            ['expected_on' => '2026-10-21', 'amount' => '999.00'],
            ['expected_on' => '2026-10-23', 'amount' => '200.00'],
            ['expected_on' => '2026-10-24', 'direction' => 'inflow', 'amount' => '25.00'],
            ['expected_on' => '2026-12-16', 'direction' => 'inflow', 'amount' => '10.00'],
            ['expected_on' => '2026-12-17', 'direction' => 'inflow', 'amount' => '999.00'],
        ]);

        $overview = app(CommercialDashboard::class)->snapshot();

        $this->assertSame('1000.00', $overview['sales']['received']);
        $this->assertSame('-100.00', $overview['forecast']['minimum']);
        $this->assertSame('100.00', $overview['forecast']['additional_capital']);
        $this->assertSame('-65.00', $overview['forecast']['closing']);
        $this->assertSame('2026-10-23', $overview['forecast']['first_shortfall']);
        $this->assertContains('Falta de caixa prevista no período', array_column($overview['alerts'], 'label'));
        $this->get(route('commercial-dashboard'))->assertOk()->assertSee('R$ -65,00')->assertSee('23/10/2026')->assertSee('Previsão manual e recebimentos reais são acompanhados separadamente.');
        $this->assertSame('100.00', $projection->fresh()->opening_balance);
        $this->assertDatabaseCount('cash_movements', 5);
    }

    public function test_cancelled_divergent_sale_does_not_generate_active_sales_alerts(): void
    {
        $sale = $this->sale($this->product(), 'CANCELLED', '10.00', '0.00');
        $this->receive($sale, '20.00');
        app(SalesLedger::class)->cancel($sale, 'Cancelado', $sale->fresh()->version);

        $overview = app(CommercialDashboard::class)->snapshot();

        $this->assertSame(0, $overview['sales']['divergent_count']);
        $this->assertSame([], $overview['alerts']);
        $this->assertSame('20.00', $overview['sales']['cancelled_received']);
        $this->get(route('commercial-dashboard'))->assertOk()->assertSee('cancelada(s) fora dos indicadores.');
    }

    public function test_product_costs_and_reference_dates_come_from_current_compositions_and_supply_history(): void
    {
        $product = $this->product();
        $old = app(CommercialDashboard::class)->snapshot();
        $this->assertSame('10.2500', $old['products'][0]['cost']);
        $this->travelTo('2026-10-23 12:00:00');
        app(SupplyCatalog::class)->updateCost(Supply::query()->sole(), '12.3456');

        $overview = app(CommercialDashboard::class)->snapshot();

        $this->assertSame('12.3456', $overview['products'][0]['cost']);
        $this->assertSame('2026-10-23', $overview['products'][0]['earliest_reference']);
        $this->assertSame('2026-10-23', $overview['products'][0]['latest_reference']);
        $this->assertSame($product->id, $overview['products'][0]['product']->id);
        $this->get(route('commercial-dashboard'))->assertOk()->assertSee('R$ 12,3456')->assertSee('23/10/2026');
    }

    public function test_invalid_compositions_are_identified_only_in_the_bounded_product_preview(): void
    {
        Product::factory()->count(7)->sequence(fn ($sequence) => ['name' => 'Produto '.$sequence->index])->create();

        $overview = app(CommercialDashboard::class)->snapshot();

        $this->assertCount(6, $overview['products']);
        $this->assertCount(6, $overview['alerts']);
        $this->assertSame(7, $overview['product_count']);
        $this->assertNull($overview['products'][0]['cost']);
        $this->assertSame('Produto sem composição de custos.', $overview['products'][0]['warning']);
        $this->get(route('commercial-dashboard'))->assertOk()->assertSee('Prévia de até 6 produtos ativos')->assertSee('Revisar composição');
    }

    public function test_tariff_confidence_dates_and_existing_warnings_are_presented_without_assuming_fixed_fees(): void
    {
        $confirmed = $this->tariff('Confirmada', 'confirmed');
        $estimated = $this->tariff('Estimada', 'estimated');

        $overview = app(CommercialDashboard::class)->snapshot();

        $this->assertSame(app(TariffCatalog::class)->warnings($confirmed), $overview['tariffs'][0]['warnings']);
        $this->assertSame(app(TariffCatalog::class)->warnings($estimated), $overview['tariffs'][1]['warnings']);
        $this->get(route('commercial-dashboard'))->assertOk()->assertSee('Confirmada no cadastro')->assertSee('Estimada')
            ->assertSee('Fixa: não informada')->assertSee('Referência: 21/10/2026')->assertSee('Condições adicionais ainda não verificadas.');
    }

    public function test_shortcuts_and_attention_links_lead_to_existing_modules_and_sale_form(): void
    {
        $product = $this->product();
        $this->sale($product, 'PENDING', '100.00', '10.00');

        $response = $this->get(route('commercial-dashboard'))->assertOk();

        foreach (['sales', 'simulator', 'scenarios', 'cash-flow', 'viability', 'products.index', 'tariffs.index'] as $route) {
            $response->assertSee('href="'.route($route).'"', false);
        }
        $response->assertSee('href="'.route('sales', ['nova' => 1]).'"', false);
        $this->get(route('sales', ['nova' => 1]))->assertOk()->assertSee('Identificador do pedido *')->assertSee('Salvar venda');
    }

    public function test_opening_and_refreshing_dashboard_never_writes_to_domain_tables(): void
    {
        $product = $this->product();
        $sale = $this->sale($product, 'READ-ONLY', '100.00', '10.00');
        $this->receive($sale, '10.00');
        $projection = CashProjection::factory()->create();
        CashMovement::factory()->for($projection)->create();
        $this->tariff('Leitura', 'estimated');
        $tables = ['products', 'product_components', 'supplies', 'supply_costs', 'sales', 'sale_receipts', 'sale_revisions', 'cash_projections', 'cash_movements', 'tariff_profiles', 'tariff_revisions'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        Livewire::test('pages::commercial-dashboard')->assertSee('Painel Comercial')->call('$refresh')->assertSee('Fluxo de caixa');

        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson(), $table);
        }
    }

    public function test_expired_projection_is_labelled_without_moving_its_dates(): void
    {
        $projection = CashProjection::factory()->create(['starts_on' => '2026-01-01']);

        $this->get(route('commercial-dashboard'))->assertOk()->assertSee('Este período já terminou.')->assertSee('revise as datas')
            ->assertSee('Sem movimentações previstas cadastradas.');

        $this->assertSame('2026-01-01', $projection->fresh()->starts_on->toDateString());
    }

    public function test_chart_contains_only_initial_balance_and_actual_weekly_closings(): void
    {
        $projection = CashProjection::factory()->create(['opening_balance' => '100.00']);
        CashMovement::factory()->for($projection)->create(['amount' => '150.00']);
        $overview = app(CommercialDashboard::class)->snapshot();

        $chart = app(DashboardPresentation::class)->chart($overview['forecast']);

        $this->assertCount(9, $chart['points']);
        $this->assertSame(['100.00', '-50.00', '-50.00', '-50.00', '-50.00', '-50.00', '-50.00', '-50.00', '-50.00'], array_column($chart['points'], 'value'));
        $this->assertSame('Início', $chart['points'][0]['label']);
        $this->assertSame('Sem. 8', $chart['points'][8]['label']);
        $this->assertSame(1, $chart['minimum_index']);
        $this->get(route('commercial-dashboard'))->assertOk()->assertSee('Caixa em risco')->assertSee('Saldo inicial e oito fechamentos semanais');
    }

    public function test_progress_bars_use_valid_complete_totals_and_disappear_for_overpayment(): void
    {
        $sale = $this->sale($this->product(), 'PROGRESS', '100.00', '0.00');
        $this->receive($sale, '25.00');
        $overview = app(CommercialDashboard::class)->snapshot();

        $this->assertSame(['received' => '25.0', 'pending' => '75.0'], app(DashboardPresentation::class)->progress($overview['sales']));
        $this->get(route('commercial-dashboard'))->assertOk()->assertSee('aria-valuenow="25.0"', false)->assertSee('aria-valuenow="75.0"', false);
        $this->receive($sale, '100.00');
        $this->assertNull(app(DashboardPresentation::class)->progress(app(CommercialDashboard::class)->snapshot()['sales']));
        $this->get(route('commercial-dashboard'))->assertOk()->assertDontSee('role="progressbar"', false);
    }

    public function test_incomplete_or_zero_expected_amounts_never_produce_percentages(): void
    {
        $product = $this->product();
        $this->sale($product, 'ZERO', '0.00', '0.00');
        $presentation = app(DashboardPresentation::class);
        $this->assertNull($presentation->progress(app(CommercialDashboard::class)->snapshot()['sales']));
        $this->sale($product, 'UNKNOWN', '100.00', null);

        $overview = app(CommercialDashboard::class)->snapshot();

        $this->assertNull($presentation->progress($overview['sales']));
        $this->assertSame('Dados insuficientes', $presentation->status($overview)['label']);
        $this->get(route('commercial-dashboard'))->assertOk()->assertDontSee('role="progressbar"', false);
    }

    public function test_general_status_is_positive_only_with_complete_sales_forecast_and_no_alerts(): void
    {
        $sale = $this->sale($this->product(), 'COMPLETE', '100.00', '0.00');
        $this->receive($sale, '100.00');
        $projection = CashProjection::factory()->create(['opening_balance' => '100.00']);
        CashMovement::factory()->for($projection)->create(['direction' => 'inflow']);
        $presentation = app(DashboardPresentation::class);

        $this->assertSame('Operação em dia', $presentation->status(app(CommercialDashboard::class)->snapshot())['label']);
        $this->tariff('Estimativa', 'estimated');

        $this->assertSame('Atenção necessária', $presentation->status(app(CommercialDashboard::class)->snapshot())['label']);
        $this->get(route('commercial-dashboard'))->assertOk()->assertSee('Tarifas ainda estimadas')->assertDontSee('Operação em dia');
    }

    public function test_confirmed_tariffs_identify_each_missing_field_and_link_to_the_correct_rule(): void
    {
        $classic = $this->tariff('Clássico', 'confirmed', ['conditions_verified' => true, 'valid_from' => '2026-10-19']);
        $premium = $this->tariff('Premium', 'confirmed', ['conditions_verified' => true]);
        $before = TariffProfile::query()->orderBy('id')->get()->toJson();

        $cards = app(DashboardPresentation::class)->attention(app(CommercialDashboard::class)->snapshot());

        $this->assertCount(2, $cards);
        $this->assertSame('Mercado Livre — Clássico', $cards[0]['title']);
        $this->assertSame('Percentual confirmado. Tarifa fixa não informada.', $cards[0]['description']);
        $this->assertSame('Percentual confirmado. Tarifa fixa não informada. Início da vigência não informado.', $cards[1]['description']);
        $this->assertSame(route('tariffs.edit', $classic), $cards[0]['url']);
        $this->assertSame(route('tariffs.edit', $premium), $cards[1]['url']);
        $this->assertSame('Revisar tarifa', $cards[0]['action']);
        $this->get(route('commercial-dashboard'))->assertOk()->assertSee('Percentual confirmado.')
            ->assertSee('href="'.route('tariffs.edit', $premium).'"', false)
            ->assertDontSee('Condições adicionais ainda não verificadas.')->assertDontSee('Condições de tarifas a conferir');
        $this->assertSame($before, TariffProfile::query()->orderBy('id')->get()->toJson());
        $this->assertNull($classic->fresh()->fixed_fee);
    }

    public function test_tariff_alerts_cover_all_active_rules_without_repeated_tariff_queries(): void
    {
        for ($index = 1; $index <= 4; $index++) {
            $this->tariff('Completa '.$index, 'confirmed', ['fixed_fee' => '0', 'conditions_verified' => true, 'valid_from' => '2026-10-19']);
        }
        $pending = $this->tariff('Quinta com pendência', 'confirmed', ['conditions_verified' => true, 'valid_from' => '2026-10-19']);
        $estimated = $this->tariff('Sexta estimada', 'estimated', ['fixed_fee' => '0', 'conditions_verified' => true, 'valid_from' => '2026-10-19']);
        DB::enableQueryLog();
        $overview = app(CommercialDashboard::class)->snapshot();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(1, array_filter($queries, fn (array $query): bool => str_contains($query['query'], '"tariff_profiles"')));
        $this->assertCount(6, $overview['tariffs']);
        $this->assertSame(6, $overview['tariff_count']);
        $this->assertSame(1, $overview['estimated_tariffs']);
        $cards = app(DashboardPresentation::class)->attention($overview);
        $this->assertSame([route('tariffs.edit', $pending), route('tariffs.edit', $estimated)], array_column($cards, 'url'));
        $this->assertSame('Percentual confirmado. Tarifa fixa não informada.', $cards[0]['description']);
        $this->assertSame('Tarifas ainda estimadas: confirmar os valores.', $cards[1]['description']);
        $this->get(route('tariffs.edit', $pending))->assertOk()->assertSee('Quinta com pendência');
        $this->get(route('commercial-dashboard'))->assertOk()->assertSee('Quinta com pendência')->assertSee('Sexta estimada');
    }

    public function test_disabled_tariffs_and_absent_marketplace_do_not_create_pending_alerts(): void
    {
        $this->tariff('Grátis desativado', 'estimated', ['is_active' => false]);
        $overview = app(CommercialDashboard::class)->snapshot();
        $this->assertSame([], $overview['tariffs']);
        $this->assertSame(0, $overview['estimated_tariffs']);
        $this->assertSame([], app(DashboardPresentation::class)->attention($overview));
        $this->get(route('commercial-dashboard'))->assertOk()->assertDontSee('Grátis desativado')->assertSee('Nenhuma regra ativa cadastrada');
    }

    public function test_explicit_zero_fee_and_verified_conditions_do_not_create_a_tariff_alert(): void
    {
        $complete = $this->tariff('Completa', 'confirmed', ['fixed_fee' => '0', 'conditions_verified' => true, 'valid_from' => '2026-10-19']);
        $overview = app(CommercialDashboard::class)->snapshot();
        $this->assertSame([], $overview['tariffs'][0]['warnings']);
        $this->assertSame([], app(DashboardPresentation::class)->attention($overview));
        $this->assertSame('0.0000', $complete->fresh()->fixed_fee);
        $this->get(route('commercial-dashboard'))->assertOk()->assertDontSee('Condições adicionais ainda não verificadas.');
    }

    public function test_unverified_conditions_are_distinct_from_confirmed_percentages(): void
    {
        $this->tariff('Condições pendentes', 'confirmed', ['fixed_fee' => '0', 'valid_from' => '2026-10-19']);
        $cards = app(DashboardPresentation::class)->attention(app(CommercialDashboard::class)->snapshot());
        $this->assertSame('Percentual confirmado. Condições adicionais ainda não verificadas.', $cards[0]['description']);
    }

    public function test_confirmed_classification_does_not_imply_an_unknown_percentage_was_provided(): void
    {
        $this->tariff('Comissão desconhecida', 'confirmed', ['commission_percent' => null, 'fixed_fee' => '0', 'conditions_verified' => true, 'valid_from' => '2026-10-19']);
        $cards = app(DashboardPresentation::class)->attention(app(CommercialDashboard::class)->snapshot());
        $this->assertSame('Regra classificada como confirmada. Percentual de comissão não informado.', $cards[0]['description']);
    }

    public function test_tariff_validity_and_review_reasons_remain_visible_per_rule(): void
    {
        $this->tariff('Futura', 'confirmed', ['fixed_fee' => '0', 'conditions_verified' => true, 'valid_from' => '2026-11-01']);
        $this->tariff('Encerrada', 'confirmed', ['fixed_fee' => '0', 'conditions_verified' => true, 'valid_from' => '2026-01-01', 'valid_until' => '2026-10-21']);
        $this->tariff('Revisar', 'confirmed', ['fixed_fee' => '0', 'conditions_verified' => true, 'valid_from' => '2026-01-01', 'review_on' => '2026-10-21']);
        $cards = app(DashboardPresentation::class)->attention(app(CommercialDashboard::class)->snapshot());
        $this->assertCount(3, $cards);
        $this->assertStringContainsString('A vigência desta regra ainda não começou.', $cards[0]['description']);
        $this->assertStringContainsString('Vigência encerrada.', $cards[1]['description']);
        $this->assertStringContainsString('Revisão da tarifa pendente.', $cards[2]['description']);
    }

    public function test_no_sales_is_a_neutral_state_separate_from_tariff_pending_information(): void
    {
        $this->tariff('Clássico', 'confirmed');
        $overview = app(CommercialDashboard::class)->snapshot();
        $status = app(DashboardPresentation::class)->status($overview);
        $this->assertSame('Sem vendas registradas', $status['label']);
        $this->assertSame('neutral', $status['tone']);
        $this->assertSame(route('sales', ['nova' => 1]), $status['url']);
        $this->get(route('commercial-dashboard'))->assertOk()->assertSee('Registre a primeira venda')->assertSee('Mercado Livre — Clássico')
            ->assertDontSee('role="progressbar"', false)->assertDontSee('cash-chart-title', false);
    }

    public function test_saved_projection_without_movements_uses_compact_empty_state_and_preserves_balance(): void
    {
        $projection = CashProjection::factory()->create(['opening_balance' => '850.00']);
        $overview = app(CommercialDashboard::class)->snapshot();
        $this->assertFalse($overview['has_cash_movements']);
        $this->assertSame('850.00', $overview['forecast']['closing']);
        $this->get(route('commercial-dashboard'))->assertOk()->assertSee('Sem movimentações previstas cadastradas.')
            ->assertSee('Saldo inicial informado:')->assertSee('R$ 850,00')->assertDontSee('cash-chart-title', false);
        $this->assertSame('850.00', $projection->fresh()->opening_balance);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_movements_outside_saved_period_do_not_produce_a_misleading_flat_chart(): void
    {
        $projection = CashProjection::factory()->create();
        CashMovement::factory()->for($projection)->create(['expected_on' => '2027-01-01']);
        $this->assertFalse(app(CommercialDashboard::class)->snapshot()['has_cash_movements']);
        $this->get(route('commercial-dashboard'))->assertOk()->assertDontSee('cash-chart-title', false);
        $this->assertDatabaseCount('cash_movements', 1);
    }

    public function test_negative_opening_balance_keeps_cash_risk_visible_even_without_movements(): void
    {
        CashProjection::factory()->create(['opening_balance' => '-100.00']);
        $overview = app(CommercialDashboard::class)->snapshot();
        $this->assertFalse($overview['has_cash_movements']);
        $this->assertSame('100.00', $overview['forecast']['additional_capital']);
        $this->get(route('commercial-dashboard'))->assertOk()->assertSee('Caixa em risco')->assertSee('Falta de caixa prevista a partir de')->assertDontSee('cash-chart-title', false);
    }

    private function product(): Product
    {
        $supply = Supply::factory()->create();
        app(SupplyCatalog::class)->updateCost($supply, '10.2500');
        $product = Product::factory()->create(['name' => 'Castanha de teste']);
        app(ProductComposition::class)->replace($product, [['supply_id' => $supply->id, 'quantity' => '1']]);

        return $product;
    }

    private function sale(Product $product, string $reference, ?string $gross, ?string $fees): Sale
    {
        return app(SalesLedger::class)->save(null, ['marketplace' => 'shopee', 'order_reference' => $reference,
            'sold_on' => '2026-10-21', 'product_id' => $product->id, 'quantity' => 1, 'gross_amount' => $gross,
            'seller_discount' => '0.00', 'marketplace_fees' => $fees, 'seller_freight' => '0.00',
            'other_adjustment' => '0.00', 'expedition_cost' => '0.00', 'paid_labor' => '0.00', 'expected_on' => '2026-10-28']);
    }

    private function receive(Sale $sale, string $amount): void
    {
        app(SalesLedger::class)->receive($sale, ['reference' => (string) Str::uuid(), 'kind' => 'receipt', 'amount' => $amount, 'received_on' => '2026-10-22'], (string) Str::uuid());
    }

    private function tariff(string $listing, string $confidence, array $overrides = []): TariffProfile
    {
        return app(TariffCatalog::class)->save(array_replace(['channel' => 'mercado_livre', 'category' => 'Castanhas', 'listing_type' => $listing,
            'commission_percent' => '14', 'fixed_fee' => null, 'confidence' => $confidence, 'conditions_verified' => false,
            'is_active' => true, 'source' => 'Referência de teste', 'observed_on' => '2026-10-21', 'review_on' => '2026-11-01'], $overrides));
    }
}
