<?php

namespace Tests\Feature;

use App\Models\CashMovement;
use App\Models\CashProjection;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\CashFlowEntry;
use App\Services\CashFlowIntegration;
use App\Services\CashFlowProjection;
use App\Services\CommercialDashboard;
use App\Services\ProductComposition;
use App\Services\SalesLedger;
use App\Services\SupplyCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CashFlowIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            throw new \RuntimeException('Integration tests require SQLite in memory.');
        }
        $this->travelTo(CarbonImmutable::parse('2026-10-27 12:00:00', CashFlowIntegration::TIMEZONE));
        $this->withoutVite();
        $this->artisan('migrate', ['--force' => true, '--no-interaction' => true])->assertExitCode(0);
        $this->actingAs(User::factory()->create());
    }

    public function test_required_scenario_is_dynamic_exact_and_shared_with_dashboard(): void
    {
        $projection = CashProjection::factory()->create(['id' => 1, 'opening_balance' => '2500.00', 'starts_on' => '2026-10-22']);
        CashMovement::factory()->for($projection)->createMany([
            ['description' => 'Balança', 'amount' => '169.90', 'expected_on' => '2026-10-27'],
            ['description' => 'Seladora', 'amount' => '359.00', 'expected_on' => '2026-10-27'],
        ]);
        $sale = $this->sale();
        $snapshot = $sale->cost_snapshot;
        $this->receipt($sale, '100.00', '2026-10-26');
        $this->activate($projection);

        $data = app(CashFlowIntegration::class)->snapshot($projection);

        $this->assertSame('100.00', $data['forecast']['realized_inflows']);
        $this->assertSame('63.31', $data['forecast']['planned_inflows']);
        $this->assertSame('528.90', $data['forecast']['planned_outflows']);
        $this->assertSame('0.00', $data['forecast']['realized_outflows']);
        $this->assertSame('2134.41', $data['forecast']['closing']);
        $this->assertCount(4, $data['entries']);
        $this->assertSame(4, $data['entries']->pluck('key')->unique()->count());
        $this->assertSame($data['forecast'], app(CommercialDashboard::class)->snapshot()['forecast']);
        Livewire::test('pages::cash-flow')->assertSee('R$ 2.134,41')->assertSee('Repasse previsto')->assertSee('Realizadas:');
        $this->assertSame('2500.00', $projection->fresh()->opening_balance);
        $this->assertSame($snapshot, $sale->fresh()->cost_snapshot);
        $this->assertDatabaseCount('cash_movements', 2);
        $this->assertDatabaseCount('sale_receipts', 1);
        $this->assertSame($data['forecast'], app(CashFlowIntegration::class)->snapshot($projection)['forecast']);
    }

    #[DataProvider('baseDates')]
    public function test_actual_receipts_respect_base_date_but_always_reduce_pending(string $date, string $actual, string $closing): void
    {
        $projection = CashProjection::factory()->create(['opening_balance' => '2500.00', 'starts_on' => '2026-10-22']);
        $sale = $this->sale();
        $this->receipt($sale, '100.00', $date);
        $this->activate($projection);

        $forecast = app(CashFlowIntegration::class)->snapshot($projection)['forecast'];

        $this->assertSame($actual, $forecast['realized_inflows']);
        $this->assertSame('63.31', $forecast['planned_inflows']);
        $this->assertSame($closing, $forecast['closing']);
    }

    public static function baseDates(): array
    {
        return [['2026-10-21', '0.00', '2563.31'], ['2026-10-22', '100.00', '2663.31'], ['2026-10-23', '100.00', '2663.31']];
    }

    public function test_settlement_removes_forecast_and_financial_changes_recompute_only_remaining_amount(): void
    {
        $projection = CashProjection::factory()->create(['opening_balance' => '0.00']);
        $sale = $this->sale();
        $this->receipt($sale, '100.00', '2026-10-26');
        $this->receipt($sale, '63.31', '2026-10-27');
        $this->activate($projection);
        $this->assertSame('0.00', app(CashFlowIntegration::class)->snapshot($projection)['forecast']['planned_inflows']);

        $data = $this->saleData($sale->product);
        $data['gross_amount'] = '226.59';
        app(SalesLedger::class)->save($sale->fresh(), $data, $sale->fresh()->version);
        $forecast = app(CashFlowIntegration::class)->snapshot($projection)['forecast'];

        $this->assertSame('36.69', $forecast['planned_inflows']);
        $this->assertSame('200.00', $forecast['closing']);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_refund_is_actual_outflow_and_requires_explicit_review_before_replacing_expectation(): void
    {
        $projection = CashProjection::factory()->create(['opening_balance' => '0.00']);
        $sale = $this->sale();
        $this->receipt($sale, '100.00', '2026-10-26');
        $this->receipt($sale, '10.00', '2026-10-27', 'refund');
        $this->activate($projection);
        $integration = app(CashFlowIntegration::class);
        $paused = $integration->snapshot($projection);
        $this->assertSame('90.00', $paused['forecast']['closing']);
        $this->assertSame('10.00', $paused['forecast']['realized_outflows']);
        $this->assertSame('0.00', $paused['forecast']['planned_inflows']);
        $this->assertSame('73.31', $paused['pending'][0]['amount']);

        Livewire::test('pages::sales')->call('viewSale', $sale->id)->assertSee('Previsão de caixa suspensa')
            ->call('confirmCashForecast')->assertHasNoErrors();

        $this->assertSame('73.31', $integration->snapshot($projection)['forecast']['planned_inflows']);
        $this->assertDatabaseHas('sale_revisions', ['sale_id' => $sale->id, 'event' => 'cash_forecast_reviewed']);
        $this->receipt($sale->fresh(), '5.00', '2026-10-27', 'refund');
        $this->assertSame('0.00', $integration->snapshot($projection)['forecast']['planned_inflows']);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_cancellation_preserves_realized_receipts_and_refunds_but_removes_future_cash(): void
    {
        $projection = CashProjection::factory()->create(['opening_balance' => '0.00']);
        $sale = $this->sale();
        $this->receipt($sale, '100.00', '2026-10-26');
        $sale = app(SalesLedger::class)->cancel($sale->fresh(), 'Cancelamento', $sale->fresh()->version);
        $this->receipt($sale, '40.00', '2026-10-27', 'refund');
        $this->activate($projection);

        $data = app(CashFlowIntegration::class)->snapshot($projection);

        $this->assertSame('60.00', $data['forecast']['closing']);
        $this->assertSame('100.00', $data['forecast']['realized_inflows']);
        $this->assertSame('40.00', $data['forecast']['realized_outflows']);
        $this->assertSame('0.00', $data['forecast']['planned_inflows']);
        $this->assertDatabaseCount('sale_receipts', 2);
    }

    public function test_unknown_undated_overdue_closed_and_negative_expectations_are_visible_without_fake_inflows(): void
    {
        $projection = CashProjection::factory()->create(['opening_balance' => '0.00']);
        $unknown = $this->sale(['order_reference' => 'UNKNOWN', 'marketplace_fees' => '']);
        $this->receipt($unknown, '20.00', '2026-10-26');
        $this->sale(['order_reference' => 'UNDATED', 'expected_on' => '']);
        $this->sale(['order_reference' => 'OVERDUE', 'expected_on' => '2026-10-26']);
        $this->sale(['order_reference' => 'CLOSED', 'reconciliation_closed' => true, 'reconciliation_note' => 'Encerrado']);
        $this->sale(['order_reference' => 'NEGATIVE', 'marketplace_fees' => '200.00']);
        $this->activate($projection);

        $data = app(CashFlowIntegration::class)->snapshot($projection);

        $this->assertCount(5, $data['pending']);
        $this->assertNull(collect($data['pending'])->firstWhere('reference', 'UNKNOWN')['amount']);
        $this->assertSame('20.00', $data['forecast']['closing']);
        $this->assertSame('0.00', $data['forecast']['planned_inflows']);
        Livewire::test('pages::cash-flow')->assertSee('Previsão vencida')->assertSee('Valor desconhecido');
        $this->assertSame('2026-10-26', Sale::where('order_reference', 'OVERDUE')->sole()->expected_on->toDateString());
    }

    public function test_existing_manuals_need_review_and_replacements_preserve_original_values(): void
    {
        $projection = CashProjection::factory()->create(['opening_balance' => '2500.00']);
        $sale = $this->sale();
        $this->receipt($sale, '100.00', '2026-10-26');
        $manual = CashMovement::factory()->for($projection)->create(['direction' => 'inflow', 'description' => 'Comercial antigo', 'amount' => '189.90']);
        $integration = app(CashFlowIntegration::class);
        $this->assertSame('2689.90', $integration->snapshot($projection)['forecast']['closing']);
        $this->expectValidation(fn () => $integration->activate($projection, $integration->snapshot($projection, true)['fingerprint']), 'integration');
        $this->assertFalse($projection->fresh()->sales_integration_enabled);
        $this->assertNull($manual->fresh()->replacement_sale_id);

        $integration->reviewManual($projection, $manual->id, 'replaced', $sale->id);
        $preview = $integration->snapshot($projection, true);
        $this->assertSame('2663.31', $preview['forecast']['closing']);
        $this->assertSame('2689.90', $integration->snapshot($projection)['forecast']['closing']);
        $integration->activate($projection, $preview['fingerprint']);

        $this->assertSame('2663.31', $integration->snapshot($projection)['forecast']['closing']);
        $this->assertSame('189.90', $manual->fresh()->amount);
        $this->assertSame('Comercial antigo', $manual->fresh()->description);
        $this->assertDatabaseCount('cash_movements', 1);
    }

    public function test_stale_activation_preview_is_rejected_without_changing_opening_balance(): void
    {
        $projection = CashProjection::factory()->create(['opening_balance' => '2500.00']);
        $manual = CashMovement::factory()->for($projection)->create(['integration_review' => 'independent']);
        $integration = app(CashFlowIntegration::class);
        $preview = $integration->snapshot($projection, true);
        $manual->update(['amount' => '99.00']);

        $this->expectValidation(fn () => $integration->activate($projection, $preview['fingerprint']), 'integration');

        $this->assertFalse($projection->fresh()->sales_integration_enabled);
        $this->assertSame('2500.00', $projection->fresh()->opening_balance);
    }

    public function test_manual_capital_is_separate_and_changing_base_date_never_rewrites_balance(): void
    {
        $projection = CashProjection::factory()->create(['id' => 1, 'opening_balance' => '2500.00', 'starts_on' => '2026-10-22']);
        $this->activate($projection);
        $page = Livewire::test('pages::cash-flow')->call('newMovement')->set('description', 'Aporte')
            ->set('direction', 'inflow')->set('nature', 'capital_contribution')->set('amount', '100,00')
            ->set('expected_on', '2026-10-27')->set('is_realized', true)->call('saveMovement')
            ->assertHasErrors(['manual_review_confirmed']);
        $this->assertDatabaseCount('cash_movements', 0);
        $page->set('manual_review_confirmed', true)->call('saveMovement')->assertHasNoErrors();
        $forecast = $page->get('forecast');
        $this->assertSame('100.00', $forecast['capital_inflows']);
        $this->assertSame('100.00', $forecast['realized_inflows']);
        $this->assertSame('2600.00', $forecast['closing']);

        $page->set('starts_on', '2026-10-28')->call('saveParameters')->assertHasNoErrors();
        $this->assertSame('2500.00', $page->get('forecast')['closing']);
        $this->assertSame('2500.00', $projection->fresh()->opening_balance);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('cash_movements', 1);
    }

    public function test_56_day_horizon_and_stable_origin_keys_prevent_double_counting(): void
    {
        $projection = CashProjection::factory()->create(['opening_balance' => '0.00', 'starts_on' => '2026-10-22']);
        $this->sale(['order_reference' => 'LAST', 'expected_on' => '2026-12-16']);
        $this->sale(['order_reference' => 'OUTSIDE', 'expected_on' => '2026-12-17']);
        $this->activate($projection);
        $data = app(CashFlowIntegration::class)->snapshot($projection);
        $this->assertSame('163.31', $data['forecast']['closing']);
        $this->assertSame(1, $data['forecast']['excluded_count']);
        $entry = $data['entries']->first();

        $twice = app(CashFlowProjection::class)->calculate($projection, [$entry, $entry]);

        $this->assertSame('163.31', $twice['closing']);
        $this->assertInstanceOf(CashFlowEntry::class, $entry);
    }

    public function test_actual_horizon_limits_include_last_day_and_exclude_following_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-17 12:00:00', CashFlowIntegration::TIMEZONE));
        $projection = CashProjection::factory()->create(['opening_balance' => '0.00', 'starts_on' => '2026-10-22']);
        $sale = $this->sale();
        $this->receipt($sale, '100.00', '2026-12-16');
        $this->receipt($sale, '63.31', '2026-12-17');
        $this->activate($projection);

        $data = app(CashFlowIntegration::class)->snapshot($projection);

        $this->assertSame('100.00', $data['forecast']['realized_inflows']);
        $this->assertSame('0.00', $data['forecast']['planned_inflows']);
        $this->assertSame('100.00', $data['forecast']['closing']);
    }

    public function test_activation_requires_acknowledgement_and_fresh_preview_in_the_interface(): void
    {
        $projection = CashProjection::factory()->create(['id' => 1, 'opening_balance' => '2500.00']);
        $manual = CashMovement::factory()->for($projection)->create(['amount' => '10.00']);
        $page = Livewire::test('pages::cash-flow')
            ->call('activateIntegration')->assertHasErrors(['activation_confirmed'])
            ->set('activation_confirmed', true)->call('activateIntegration')->assertHasErrors(['integration']);
        $this->assertFalse($projection->fresh()->sales_integration_enabled);

        $page->call('reviewMovement', $manual->id, 'independent')
            ->set('activation_confirmed', true)->call('activateIntegration')->assertHasNoErrors();

        $this->assertTrue($projection->fresh()->sales_integration_enabled);
        $this->assertSame('2500.00', $projection->fresh()->opening_balance);
        $this->assertSame('10.00', $manual->fresh()->amount);
    }

    public function test_timezone_boundary_uses_sao_paulo_without_shifting_calendar_dates(): void
    {
        config(['app.timezone' => 'UTC']);
        $this->travelTo(CarbonImmutable::parse('2026-10-27 01:00:00', 'UTC'));
        $projection = CashProjection::factory()->create(['opening_balance' => '0.00', 'starts_on' => '2026-10-22']);
        $this->sale(['expected_on' => '2026-10-26']);
        $this->activate($projection);

        $data = app(CashFlowIntegration::class)->snapshot($projection);

        $this->assertSame('163.31', $data['forecast']['planned_inflows']);
        $this->assertSame('2026-10-26', $data['entries']->first()->date->toDateString());
        $this->assertSame([], $data['pending']);
    }

    public function test_unreviewed_manual_added_after_activation_cannot_duplicate_an_automatic_receipt(): void
    {
        $projection = CashProjection::factory()->create(['id' => 1, 'opening_balance' => '0.00']);
        $this->sale();
        $this->activate($projection);
        $manual = CashMovement::factory()->for($projection)->create(['direction' => 'inflow', 'amount' => '163.31']);

        $data = app(CashFlowIntegration::class)->snapshot($projection);

        $this->assertSame('163.31', $data['forecast']['closing']);
        $this->assertCount(1, $data['reviews']);
        $this->assertSame('163.31', $manual->fresh()->amount);
        Livewire::test('pages::cash-flow')->assertSee('Manuais sem revisão não são somados');
    }

    public function test_review_can_be_reopened_cancelled_and_changed_without_rewriting_financial_records(): void
    {
        $projection = CashProjection::factory()->create(['id' => 1, 'opening_balance' => '2500.00', 'starts_on' => '2026-10-22']);
        $manual = CashMovement::factory()->for($projection)->create(['description' => 'Manual revisável', 'amount' => '100.00', 'expected_on' => '2026-10-27']);
        $sale = $this->sale();
        $page = Livewire::test('pages::cash-flow')->call('reviewMovement', $manual->id, 'independent')
            ->assertSee('Revisado')->assertSee('Alterar decisão')->assertDontSee('Venda que substitui o manual');
        $reviewedAt = $manual->fresh()->integration_reviewed_at->toISOString();
        $this->travel(1)->minutes();
        $page->call('reviewMovement', $manual->id, 'independent');
        $this->assertSame($reviewedAt, $manual->fresh()->integration_reviewed_at->toISOString());

        $page->call('changeManualDecision', $manual->id)->assertSee('Venda que substitui o manual')
            ->call('cancelManualDecision', $manual->id)->assertDontSee('Venda que substitui o manual');
        $this->assertSame('independent', $manual->fresh()->integration_review);
        $page->call('changeManualDecision', $manual->id)->set('replacement_sales.'.$manual->id, $sale->id)
            ->call('reviewMovement', $manual->id, 'replaced')->assertHasNoErrors()->assertSee('Substituído pela venda')
            ->assertDontSee('Venda que substitui o manual');

        $this->assertSame($sale->id, $manual->fresh()->replacement_sale_id);
        $this->assertSame('100.00', $manual->fresh()->amount);
        $this->assertSame('2026-10-27', $manual->fresh()->expected_on->toDateString());
        $this->assertDatabaseCount('cash_movements', 1);
    }

    public function test_automatic_preview_rejects_external_changes_and_requires_new_confirmation(): void
    {
        $projection = CashProjection::factory()->create(['id' => 1, 'opening_balance' => '2500.00', 'starts_on' => '2026-10-22']);
        $sale = $this->sale();
        $page = Livewire::test('pages::cash-flow')->assertSee('Saldo final integrado')->assertDontSee('Gerar prévia integrada');
        $fingerprint = $page->get('integration_preview_hash');
        $this->assertNotSame('', $fingerprint);
        $page->set('activation_confirmed', true);
        $this->receipt($sale, '100.00', '2026-10-26');

        $page->call('activateIntegration')->assertHasErrors(['integration'])->assertSet('activation_confirmed', false);

        $this->assertFalse($projection->fresh()->sales_integration_enabled);
        $this->assertNotSame($fingerprint, $page->get('integration_preview_hash'));
        $page->set('activation_confirmed', true)->call('activateIntegration')->assertHasNoErrors();
        $this->assertTrue($projection->fresh()->sales_integration_enabled);
        $this->assertSame('2500.00', $projection->fresh()->opening_balance);
    }

    public function test_automatic_preview_refreshes_after_manual_review_parameters_and_external_refresh(): void
    {
        CashProjection::factory()->create(['id' => 1, 'opening_balance' => '2500.00', 'starts_on' => '2026-10-22']);
        $projection = CashProjection::query()->findOrFail(1);
        $manual = CashMovement::factory()->for($projection)->create(['amount' => '10.00', 'expected_on' => '2026-10-27']);
        $sale = $this->sale();
        $page = Livewire::test('pages::cash-flow');
        $initialHash = $page->get('integration_preview_hash');
        $page->call('reviewMovement', $manual->id, 'independent');
        $reviewHash = $page->get('integration_preview_hash');
        $this->assertNotSame($initialHash, $reviewHash);
        $page->set('activation_confirmed', true)->set('opening_balance', '2600,00')->call('saveParameters')
            ->assertSet('activation_confirmed', false)->assertHasNoErrors();
        $parameterHash = $page->get('integration_preview_hash');
        $this->assertNotSame($reviewHash, $parameterHash);
        $this->receipt($sale, '100.00', '2026-10-26');
        $page->call('$refresh');
        $this->assertNotSame($parameterHash, $page->get('integration_preview_hash'));
        $this->assertFalse($projection->fresh()->sales_integration_enabled);
    }

    public function test_mixed_entries_are_rendered_chronologically_with_numeric_same_day_order(): void
    {
        $projection = CashProjection::factory()->create(['id' => 1, 'opening_balance' => '2500.00', 'starts_on' => '2026-10-22']);
        CashMovement::factory()->for($projection)->create(['id' => 10, 'description' => 'Seladora', 'amount' => '359.00', 'expected_on' => '2026-10-27']);
        CashMovement::factory()->for($projection)->create(['id' => 2, 'description' => 'Balança', 'amount' => '169.90', 'expected_on' => '2026-10-27']);
        $sale = $this->sale();
        $this->receipt($sale, '100.00', '2026-10-26');
        $this->activate($projection);
        $receiptId = $sale->receipts()->firstOrFail()->id;

        $data = app(CashFlowIntegration::class)->snapshot($projection);

        $this->assertSame(['receipt:'.$receiptId, 'manual:2', 'manual:10', 'sale-expected:'.$sale->id], $data['entries']->pluck('key')->all());
        Livewire::test('pages::cash-flow')->assertSeeInOrder(['cash-entry-receipt:'.$receiptId, 'cash-entry-manual:2', 'cash-entry-manual:10', 'cash-entry-sale-expected:'.$sale->id], false)
            ->assertDontSee('Ativar integração revisada');
        $this->assertSame('2134.41', $data['forecast']['closing']);
        $this->assertSame('2071.10', $data['forecast']['minimum']);
    }

    public function test_active_integration_render_and_repeated_activation_preserve_existing_review_metadata(): void
    {
        $projection = CashProjection::factory()->create(['id' => 1, 'opening_balance' => '2500.00', 'starts_on' => '2026-10-22']);
        $manual = CashMovement::factory()->for($projection)->create(['amount' => '10.00']);
        $this->activate($projection);
        $projectionBefore = $projection->fresh()->getAttributes();
        $manualBefore = $manual->fresh()->getAttributes();
        $this->travel(1)->minutes();

        Livewire::test('pages::cash-flow')->assertSee('Integração ativa')->assertDontSee('Ativar integração revisada')->assertDontSee('wire:poll', false);
        app(CashFlowIntegration::class)->activate($projection, 'old-preview');

        $this->assertSame($projectionBefore, $projection->fresh()->getAttributes());
        $this->assertSame($manualBefore, $manual->fresh()->getAttributes());
    }

    private function activate(CashProjection $projection): void
    {
        $integration = app(CashFlowIntegration::class);
        foreach ($projection->movements()->where('integration_review', 'unreviewed')->get() as $manual) {
            $integration->reviewManual($projection, $manual->id, 'independent', null);
        }
        $integration->activate($projection, $integration->snapshot($projection, true)['fingerprint']);
    }

    /** @param array<string, mixed> $overrides */
    private function sale(array $overrides = []): Sale
    {
        $supply = app(SupplyCatalog::class)->create(['name' => 'Insumo teste', 'unit' => 'kg', 'unit_cost' => '50']);
        $product = Product::factory()->create();
        app(ProductComposition::class)->replace($product, [['supply_id' => $supply->id, 'quantity' => '1']]);

        return app(SalesLedger::class)->save(null, array_replace($this->saleData($product), $overrides));
    }

    /** @return array<string, mixed> */
    private function saleData(Product $product): array
    {
        return ['product_id' => $product->id, 'marketplace' => 'mercado_livre', 'order_reference' => 'SCENARIO',
            'sold_on' => '2026-10-20', 'quantity' => '1', 'gross_amount' => '189.90', 'seller_discount' => '0',
            'marketplace_fees' => '26.59', 'seller_freight' => '0', 'other_adjustment' => '0', 'expedition_cost' => '0',
            'paid_labor' => '0', 'expected_on' => '2026-11-14', 'reconciliation_closed' => false];
    }

    private function receipt(Sale $sale, string $amount, string $date, string $kind = 'receipt'): void
    {
        app(SalesLedger::class)->receive($sale, ['reference' => (string) Str::uuid(), 'amount' => $amount,
            'received_on' => $date, 'kind' => $kind, 'notes' => $kind === 'refund' ? 'Correção identificada' : ''], (string) Str::uuid());
    }

    private function expectValidation(callable $operation, string $field): void
    {
        try {
            $operation();
            $this->fail('Expected validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }
}
