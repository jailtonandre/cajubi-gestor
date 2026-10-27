<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\SaleReceipt;
use App\Models\User;
use App\Services\SalesLedger;
use App\Services\SalesPresentation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SalesInterfaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            throw new \RuntimeException('Sales interface tests require SQLite in memory.');
        }
        config(['app.timezone' => 'America/Sao_Paulo']);
        $this->travelTo(CarbonImmutable::parse('2026-10-26 12:00:00', 'America/Sao_Paulo'));
        $this->withoutVite();
        $this->artisan('migrate', ['--force' => true, '--no-interaction' => true])->assertExitCode(0);
        $this->actingAs(User::factory()->create());
    }

    #[DataProvider('deadlines')]
    public function test_receipt_deadlines_follow_calendar_days(?string $date, string $label, string $window): void
    {
        $sale = $this->sale(['expected_on' => $date]);

        $deadline = app(SalesPresentation::class)->deadline($sale, app(SalesLedger::class)->figures($sale));

        $this->assertSame($label, $deadline['label']);
        $this->assertSame($window, $deadline['window']);
        $this->assertSame($window === 'overdue', $deadline['overdue']);
    }

    public static function deadlines(): array
    {
        return [
            'no date' => [null, 'Sem previsão', 'undated'],
            'three days late' => ['2026-10-23', 'Atrasado há 3 dias', 'overdue'],
            'one day late' => ['2026-10-25', 'Atrasado há 1 dia', 'overdue'],
            'today' => ['2026-10-26', 'Previsto para hoje', 'today'],
            'tomorrow' => ['2026-10-27', 'Recebe amanhã', 'next7'],
            'seven days' => ['2026-11-02', 'Recebe em 7 dias', 'next7'],
            'eight days' => ['2026-11-03', 'Recebe em 8 dias', 'later'],
        ];
    }

    public function test_deadlines_use_configured_timezone_at_utc_midnight(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-27 01:00:00', 'UTC'));
        $sale = $this->sale(['expected_on' => '2026-10-27']);

        $deadline = app(SalesPresentation::class)->deadline($sale, app(SalesLedger::class)->figures($sale));

        $this->assertSame('Recebe amanhã', $deadline['label']);
    }

    public function test_partial_receipts_refunds_settlement_and_cancellation_preserve_open_amounts(): void
    {
        $partial = $this->sale(['order_reference' => 'PARCIAL', 'expected_on' => '2026-10-25']);
        SaleReceipt::factory()->for($partial)->create(['amount' => '30.00']);
        SaleReceipt::factory()->for($partial)->create(['kind' => 'refund', 'amount' => '15.00', 'notes' => 'Correção']);
        $settled = $this->sale(['order_reference' => 'RECEBIDO', 'expected_on' => '2026-10-25']);
        SaleReceipt::factory()->for($settled)->create(['amount' => '90.00']);
        $overpaid = $this->sale(['order_reference' => 'DIVERGENTE', 'expected_on' => '2026-10-25']);
        SaleReceipt::factory()->for($overpaid)->create(['amount' => '91.00']);
        $cancelled = $this->sale(['order_reference' => 'CANCELADO', 'cancelled_at' => now(), 'cancellation_reason' => 'Cancelamento']);
        SaleReceipt::factory()->for($cancelled)->create(['amount' => '20.00']);

        $page = Livewire::test('pages::sales');
        $overview = $page->get('overview');

        $this->assertSame('75.00', $overview['summary']['pending']);
        $this->assertSame('0.00', $overview['windows']['overdue']['amount']);
        $this->assertSame(0, $overview['windows']['overdue']['count']);
        $this->assertSame('196.00', $overview['summary']['received']);
        $this->assertSame('20.00', $overview['summary']['cancelled_received']);
        $this->assertSame(1, $overview['counts']['divergent']);
        $this->assertSame(1, $overview['counts']['partial']);
        $page->call('showReceivables', 'overdue');
        $this->assertSame([], $page->get('sales')->getCollection()->pluck('sale.id')->all());

        SaleReceipt::factory()->for($settled)->create(['kind' => 'refund', 'amount' => '10.00', 'notes' => 'Correção']);
        $page->call('$refresh');
        $this->assertSame('85.00', $page->get('overview')['summary']['pending']);
        $this->assertSame('0.00', $page->get('overview')['windows']['overdue']['amount']);
        $this->assertDatabaseCount('sales', 4);
        $this->assertDatabaseCount('sale_receipts', 6);
    }

    public function test_closed_shortfall_stays_divergent_without_promising_a_new_receipt(): void
    {
        $sale = $this->sale(['expected_on' => '2026-10-27', 'reconciliation_closed' => true, 'reconciliation_note' => 'Extrato conferido']);
        SaleReceipt::factory()->for($sale)->create(['amount' => '80.00']);

        $page = Livewire::test('pages::sales')->assertSee('Conferência concluída')->assertSee('Divergente');
        $overview = $page->get('overview');

        $this->assertSame('10.00', $overview['summary']['pending']);
        $this->assertSame(0, $overview['windows']['next7']['count']);
        $page->call('editSale', $sale->id)->assertSee('Conciliação dos recebimentos')
            ->set('form.reconciliation_closed', false)->call('saveSale')->assertHasNoErrors()->assertSee('Recebe amanhã');
        $this->assertSame('10.00', $page->get('overview')['windows']['next7']['amount']);
        $this->assertSame('Parcial', $page->get('overview')['rows'][0]['figures']['status']);
    }

    public function test_unknown_amounts_are_flagged_and_never_estimated_as_zero(): void
    {
        $this->sale(['expected_on' => '2026-10-26']);
        $this->sale(['gross_amount' => null, 'marketplace_fees' => null, 'expected_on' => '2026-10-26']);

        $page = Livewire::test('pages::sales')->assertSee('Incompleto')->assertSee('Valor em aberto desconhecido');
        $overview = $page->get('overview');

        $this->assertSame('90.00', $overview['windows']['today']['amount']);
        $this->assertSame(1, $overview['windows']['today']['unknown']);
        $this->assertSame('90.00', $overview['summary']['expected']);
        $this->assertSame(1, $overview['summary']['unknown_expected']);
        $this->assertSame(1, $overview['summary']['unknown_contribution']);
        $this->assertDatabaseCount('sale_receipts', 0);
    }

    public function test_period_search_status_and_receipt_shortcuts_filter_the_same_saved_sales(): void
    {
        $old = $this->sale(['sold_on' => '2026-09-26', 'order_reference' => 'ANTIGO']);
        $first = $this->sale(['sold_on' => '2026-09-27', 'order_reference' => 'LIMITE', 'marketplace' => 'shopee']);
        $current = $this->sale(['order_reference' => 'ATUAL', 'product_snapshot' => ['name' => 'Castanha torrada', 'sku' => 'TORRADA'], 'expected_on' => '2026-10-26']);
        SaleReceipt::factory()->for($current)->create(['amount' => '90.00']);

        $page = Livewire::test('pages::sales');
        $this->assertSame(2, $page->get('summary')['count']);
        $page->set('search', 'SHOPEE');
        $this->assertSame([$first->id], $page->get('sales')->getCollection()->pluck('sale.id')->all());
        $page->set('search', 'castanha');
        $this->assertSame([$current->id], $page->get('sales')->getCollection()->pluck('sale.id')->all());
        $page->set('search', 'ATUAL')->set('status', 'received');
        $this->assertSame([$current->id], $page->get('sales')->getCollection()->pluck('sale.id')->all());
        $page->call('showReceivables', 'today');
        $this->assertSame(0, $page->get('sales')->total());
        $page->call('showReceivables', '')->set('period', 'all')->set('search', 'ANTIGO');
        $this->assertSame([$old->id], $page->get('sales')->getCollection()->pluck('sale.id')->all());
        $this->assertDatabaseCount('sales', 3);
    }

    public function test_filter_changes_reset_pagination_and_keep_period_totals_independent_of_search(): void
    {
        Sale::factory()->count(16)->create(['sold_on' => '2026-10-22']);

        $page = Livewire::test('pages::sales')->call('gotoPage', 2)->assertSet('paginators.page', 2)
            ->set('search', 'SEM-RESULTADOS')->assertSet('paginators.page', 1);

        $this->assertSame(0, $page->get('sales')->total());
        $this->assertSame(16, $page->get('summary')['count']);
    }

    public function test_progressive_form_keeps_unknown_fields_blank_and_shows_advanced_validation(): void
    {
        $page = Livewire::test('pages::sales')->call('newSale')->assertSet('form.marketplace_fees', '')
            ->assertSet('form.seller_discount', '')->assertSee('Informações financeiras avançadas')
            ->assertDontSee('Conciliação dos recebimentos')
            ->set('form.marketplace_fees', '1,001')->call('saveSale')->assertHasErrors(['form.marketplace_fees']);
        $document = new \DOMDocument;
        @$document->loadHTML($page->html());
        $xpath = new \DOMXPath($document);

        $this->assertSame(1, $xpath->query('//details[@open]//input[@id="form.marketplace_fees"]')->length);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_revisions', 0);
    }

    /** @param array<string, mixed> $overrides */
    private function sale(array $overrides = []): Sale
    {
        return Sale::factory()->create(array_replace([
            'sold_on' => '2026-10-20', 'quantity' => 1, 'gross_amount' => '100.00',
            'seller_discount' => '0.00', 'marketplace_fees' => '10.00', 'seller_freight' => '0.00',
            'other_adjustment' => '0.00', 'expedition_cost' => '0.00', 'paid_labor' => '0.00',
            'cost_captured_at' => now(),
            'cost_snapshot' => ['exact_cost' => '10.00', 'unit_cost' => '10.0000', 'supplies' => []],
            'expected_on' => '2026-10-27',
        ], $overrides));
    }
}
