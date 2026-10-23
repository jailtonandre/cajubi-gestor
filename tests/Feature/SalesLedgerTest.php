<?php

namespace Tests\Feature;

use App\Models\CashMovement;
use App\Models\CashProjection;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Supply;
use App\Models\User;
use App\Services\ProductComposition;
use App\Services\SalesLedger;
use App\Services\SupplyCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SalesLedgerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            throw new \RuntimeException('Sales tests require SQLite in memory.');
        }
        $this->travelTo('2026-10-22 12:00:00');
        $this->withoutVite();
        $this->artisan('migrate', ['--force' => true, '--no-interaction' => true])->assertExitCode(0);
        $this->actingAs(User::factory()->create());
    }

    public function test_user_can_create_view_and_edit_a_sale_progressively_without_presuming_receipts(): void
    {
        $product = $this->product();
        $this->get(route('sales'))->assertOk()->assertSee('Vendas')->assertSee('Nova venda');

        $page = Livewire::test('pages::sales')->call('newSale')
            ->set('form.product_id', (string) $product->id)->set('form.order_reference', ' pedido-1 ')
            ->set('form.quantity', '2')->call('saveSale')->assertHasNoErrors()->assertSee('Nenhum recebimento registrado.');

        $sale = Sale::query()->sole();
        $this->assertSame('PEDIDO-1', $sale->order_reference);
        $this->assertNull($sale->gross_amount);
        $this->assertNull($sale->marketplace_fees);
        $this->assertNull($sale->expected_on);
        $this->assertDatabaseCount('sale_receipts', 0);
        $page->call('editSale', $sale->id)->set('form.gross_amount', '100,10')->set('form.seller_discount', '0,00')
            ->call('saveSale')->assertHasNoErrors()->assertSee('R$ 100,10');
        $this->assertSame('100.10', $sale->fresh()->gross_amount);
        $this->assertNull($sale->fresh()->marketplace_fees);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('sale_revisions', 2);
    }

    public function test_contribution_uses_real_fees_and_order_expedition_once(): void
    {
        $sale = app(SalesLedger::class)->save(null, $this->data($this->product()));

        $figures = app(SalesLedger::class)->figures($sale);

        $this->assertSame('90.10', $figures['revenue']);
        $this->assertSame('71.76', $figures['expected']);
        $this->assertSame('20.50', $figures['cost']);
        $this->assertSame('46.26', $figures['contribution']);
        $this->assertSame('0.00', $figures['received']);
        $this->assertSame('71.76', $figures['pending']);
        $this->assertSame('Pendente', $figures['status']);
    }

    public function test_external_freight_and_signed_adjustment_are_counted_once(): void
    {
        $data = $this->data($this->product());
        $data['freight_source'] = 'external';
        $data['other_adjustment'] = '-1,00';
        $sale = app(SalesLedger::class)->save(null, $data);

        $figures = app(SalesLedger::class)->figures($sale);

        $this->assertSame('78.76', $figures['expected']);
        $this->assertSame('48.26', $figures['contribution']);
        $sale->freight_source = null;
        $this->assertNull(app(SalesLedger::class)->figures($sale)['expected']);
    }

    public function test_historical_cost_and_product_name_survive_supply_and_product_changes(): void
    {
        $product = $this->product();
        $ledger = app(SalesLedger::class);
        $sale = $ledger->save(null, $this->data($product));
        $snapshot = $sale->cost_snapshot;
        $supply = Supply::query()->sole();
        app(SupplyCatalog::class)->updateCost($supply, '99.0000');
        $product->update(['name' => 'Nome alterado']);
        $data = $this->data($product);
        $data['quantity'] = '3';

        $updated = $ledger->save($sale, $data, $sale->version);

        $this->assertSame($snapshot, $updated->cost_snapshot);
        $this->assertSame('30.75', $ledger->figures($updated)['cost']);
        $this->assertNotSame('Nome alterado', $updated->product_snapshot['name']);
        $this->assertSame($snapshot, $updated->revisions()->oldest('id')->first()->snapshot['cost_snapshot']);
    }

    public function test_missing_cost_is_preserved_as_unknown_and_can_be_captured_only_once(): void
    {
        $product = Product::factory()->create();
        $ledger = app(SalesLedger::class);
        $sale = $ledger->save(null, $this->data($product));
        $this->assertNull($sale->cost_snapshot);
        $this->assertNull($ledger->figures($sale)['contribution']);
        $supply = Supply::factory()->create();
        app(SupplyCatalog::class)->updateCost($supply, '7.1234');
        app(ProductComposition::class)->replace($product, [['supply_id' => $supply->id, 'quantity' => '1']]);

        $updated = $ledger->captureCost($sale, $sale->version);

        $this->assertSame('14.25', $ledger->figures($updated)['cost']);
        $this->assertSame('cost_captured', $updated->revisions()->latest('id')->first()->event);
        $this->expectException(ValidationException::class);
        $ledger->captureCost($updated, $updated->version);
    }

    public function test_order_uniqueness_is_per_marketplace_and_includes_cancelled_sales(): void
    {
        $ledger = app(SalesLedger::class);
        $data = $this->data($this->product());
        $sale = $ledger->save(null, $data);
        $ledger->cancel($sale, 'Pedido cancelado', $sale->version);
        $data['marketplace'] = 'shopee';
        $ledger->save(null, $data);
        $data['marketplace'] = 'mercado_livre';
        $data['order_reference'] = '  ped-1 ';

        $this->assertInvalid(fn () => $ledger->save(null, $data), 'order_reference');

        $this->assertDatabaseCount('sales', 2);
    }

    public function test_partial_full_and_overpaid_receipts_have_distinct_reconciliation_states(): void
    {
        $ledger = app(SalesLedger::class);
        $sale = $ledger->save(null, $this->data($this->product()));
        $ledger->receive($sale, $this->receipt('REP-1', '20,00'), (string) Str::uuid());
        $partial = $ledger->figures($sale->fresh('receipts'));
        $this->assertSame('Parcial', $partial['status']);
        $this->assertSame('-51.76', $partial['difference']);
        $this->assertSame('51.76', $partial['pending']);
        $ledger->receive($sale, $this->receipt('REP-2', '51,76'), (string) Str::uuid());
        $full = $ledger->figures($sale->fresh('receipts'));
        $this->assertSame('Recebido', $full['status']);
        $this->assertSame('0.00', $full['difference']);
        $ledger->receive($sale, $this->receipt('REP-3', '0,01'), (string) Str::uuid());

        $overpaid = $ledger->figures($sale->fresh('receipts'));

        $this->assertSame('Divergente', $overpaid['status']);
        $this->assertSame('0.01', $overpaid['difference']);
        $this->assertSame('0.00', $overpaid['pending']);
    }

    public function test_shortfall_is_divergent_only_after_explicit_conclusion_and_can_be_reopened(): void
    {
        $ledger = app(SalesLedger::class);
        $data = $this->data($this->product());
        $sale = $ledger->save(null, $data);
        $ledger->receive($sale, $this->receipt('REP-1', '70,00'), (string) Str::uuid());
        $data['reconciliation_closed'] = true;
        $data['reconciliation_note'] = 'Extrato conferido; ajuste ainda em análise.';

        $closed = $ledger->save($sale, $data, $sale->fresh()->version);

        $this->assertSame('Divergente', $ledger->figures($closed)['status']);
        $this->assertSame('1.76', $ledger->figures($closed)['pending']);
        $data['reconciliation_closed'] = false;
        $open = $ledger->save($closed, $data, $closed->version);
        $this->assertSame('Parcial', $ledger->figures($open)['status']);
        $this->assertSame('Extrato conferido; ajuste ainda em análise.', $open->revisions()->where('event', 'updated')->first()->snapshot['reconciliation_note']);
    }

    public function test_unknown_financial_data_remain_unknown_even_with_actual_receipts(): void
    {
        $ledger = app(SalesLedger::class);
        $data = $this->data($this->product());
        $data['marketplace_fees'] = '';
        $sale = $ledger->save(null, $data);
        $ledger->receive($sale, $this->receipt('REP-1', '20,00'), (string) Str::uuid());

        $figures = $ledger->figures($sale->fresh('receipts'));

        $this->assertNull($figures['expected']);
        $this->assertNull($figures['difference']);
        $this->assertNull($figures['pending']);
        $this->assertSame('20.00', $figures['received']);
        $summary = $ledger->summary();
        $this->assertSame(1, $summary['unknown_expected']);
        $this->assertSame('0.00', $summary['expected']);
        $this->assertSame('20.00', $summary['received']);
    }

    public function test_receipt_replay_and_duplicate_reference_do_not_create_duplicates(): void
    {
        $ledger = app(SalesLedger::class);
        $sale = $ledger->save(null, $this->data($this->product()));
        $key = (string) Str::uuid();
        $first = $ledger->receive($sale, $this->receipt('REP-1', '20,00'), $key);

        $replayed = $ledger->receive($sale, $this->receipt('REP-1', '20,00'), $key);
        $this->assertInvalid(fn () => $ledger->receive($sale, $this->receipt(' rep-1 ', '20,00'), (string) Str::uuid()), 'reference');

        $this->assertSame($first->id, $replayed->id);
        $this->assertDatabaseCount('sale_receipts', 1);
        $this->assertDatabaseCount('sale_revisions', 2);
    }

    public function test_cancellation_keeps_financial_history_and_receipts_and_allows_identified_refunds(): void
    {
        $ledger = app(SalesLedger::class);
        $data = $this->data($this->product());
        $sale = $ledger->save(null, $data);
        $ledger->receive($sale, $this->receipt('REP-1', '20,00'), (string) Str::uuid());
        $cancelled = $ledger->cancel($sale, 'Cancelado pelo comprador', $sale->fresh()->version);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertSame('20.00', $ledger->summary()['cancelled_received']);
        $this->assertSame(0, $ledger->summary()['count']);
        $this->assertInvalid(fn () => $ledger->save($cancelled, $data), 'sale');
        $refund = $this->receipt('EST-1', '5,00');
        $refund['kind'] = 'refund';
        $refund['notes'] = 'Devolução parcial após cancelamento';

        $ledger->receive($cancelled, $refund, (string) Str::uuid());

        $this->assertSame('15.00', $ledger->figures($cancelled->fresh('receipts'))['received']);
        $this->assertDatabaseCount('sale_receipts', 2);
        $this->assertSame(['created', 'receipt', 'cancelled', 'refund'], $cancelled->revisions()->orderBy('id')->pluck('event')->all());
        $this->assertSame('20.00', $cancelled->revisions()->where('event', 'cancelled')->first()->snapshot['receipts'][0]['amount']);
    }

    public function test_backdated_or_excessive_refund_cannot_precede_available_receipts(): void
    {
        $ledger = app(SalesLedger::class);
        $sale = $ledger->save(null, $this->data($this->product()));
        $ledger->receive($sale, $this->receipt('REP-1', '20,00'), (string) Str::uuid());
        $refund = $this->receipt('EST-1', '5,00');
        $refund['kind'] = 'refund';
        $refund['notes'] = 'Correção';
        $refund['received_on'] = '2026-10-21';
        $this->assertInvalid(fn () => $ledger->receive($sale, $refund, (string) Str::uuid()), 'amount');
        $refund['received_on'] = '2026-10-22';
        $refund['amount'] = '20,01';

        $this->assertInvalid(fn () => $ledger->receive($sale, $refund, (string) Str::uuid()), 'amount');

        $this->assertDatabaseCount('sale_receipts', 1);
    }

    public function test_receipts_protect_order_identity_and_stale_edits_do_not_overwrite_data(): void
    {
        $ledger = app(SalesLedger::class);
        $data = $this->data($this->product());
        $sale = $ledger->save(null, $data);
        $ledger->receive($sale, $this->receipt('REP-1', '20,00'), (string) Str::uuid());
        $this->assertInvalid(fn () => $ledger->save($sale, $data, $sale->version), 'sale');
        $data['order_reference'] = 'OUTRO-PEDIDO';

        $this->assertInvalid(fn () => $ledger->save($sale, $data, $sale->fresh()->version), 'order_reference');

        $this->assertSame('PED-1', $sale->fresh()->order_reference);
        $this->assertDatabaseCount('sale_revisions', 2);
    }

    public function test_livewire_records_receipts_and_cancels_without_affecting_forecast_or_catalog(): void
    {
        $product = $this->product();
        $ledger = app(SalesLedger::class);
        $sale = $ledger->save(null, $this->data($product));
        $projection = CashProjection::factory()->create(['opening_balance' => '123.45']);
        $forecast = CashMovement::factory()->for($projection)->create();
        $forecastBefore = $forecast->fresh()->attributesToArray();
        $productBefore = $product->fresh()->attributesToArray();

        Livewire::test('pages::sales')->call('viewSale', $sale->id)->set('receipt.reference', 'UI-1')
            ->set('receipt.amount', '20,00')->set('receipt.received_on', '2026-10-22')
            ->call('addReceipt')->assertHasNoErrors()->assertSee('Parcial')
            ->set('cancellation_reason', 'Cancelamento de teste')->call('cancelSale')->assertHasNoErrors()->assertSee('Cancelada em');

        $this->assertDatabaseCount('sale_receipts', 1);
        $this->assertDatabaseCount('cash_movements', 1);
        $this->assertSame('123.45', $projection->fresh()->opening_balance);
        $this->assertSame($forecastBefore, $forecast->fresh()->attributesToArray());
        $this->assertSame($productBefore, $product->fresh()->attributesToArray());
        $this->assertDatabaseCount('supply_costs', 1);
        $this->assertDatabaseCount('tariff_profiles', 0);
    }

    #[DataProvider('invalidSales')]
    public function test_invalid_sale_data_are_rejected_without_history_writes(string $field, mixed $value, ?string $errorField = null): void
    {
        $data = $this->data($this->product());
        $data[$field] = $value;

        $this->assertInvalid(fn () => app(SalesLedger::class)->save(null, $data), $errorField ?? $field);

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_revisions', 0);
    }

    public static function invalidSales(): array
    {
        return [
            'marketplace' => ['marketplace', 'unknown'], 'empty order' => ['order_reference', ' '],
            'array marketplace' => ['marketplace', ['shopee']], 'array order' => ['order_reference', ['PED-1']],
            'long order' => ['order_reference', str_repeat('a', 101)], 'future sale' => ['sold_on', '2026-10-23'],
            'invalid sale date' => ['sold_on', '2026-02-30'], 'missing product' => ['product_id', 99999],
            'zero quantity' => ['quantity', 0], 'fractional quantity' => ['quantity', '1.5'],
            'negative gross' => ['gross_amount', '-1'], 'fractional cent' => ['marketplace_fees', '1,001'],
            'overflow' => ['gross_amount', '1000000000000'], 'exponent' => ['gross_amount', '1e3'],
            'excess seller discount' => ['seller_discount', '101,00'], 'invalid expected date' => ['expected_on', '2026-02-30'],
            'early expected date' => ['expected_on', '2026-10-20'], 'invalid freight source' => ['freight_source', 'guess'],
            'conclusion without reason' => ['reconciliation_closed', true, 'reconciliation_note'],
        ];
    }

    #[DataProvider('invalidReceipts')]
    public function test_invalid_actual_receipts_do_not_modify_financial_history(string $field, mixed $value): void
    {
        $ledger = app(SalesLedger::class);
        $sale = $ledger->save(null, $this->data($this->product()));
        $data = $this->receipt('REF-1', '10,00');
        $data[$field] = $value;

        $this->assertInvalid(fn () => $ledger->receive($sale, $data, (string) Str::uuid()), $field);

        $this->assertDatabaseCount('sale_receipts', 0);
        $this->assertDatabaseCount('sale_revisions', 1);
    }

    public static function invalidReceipts(): array
    {
        return ['empty reference' => ['reference', ''], 'invalid kind' => ['kind', 'pending'],
            'zero' => ['amount', '0'], 'negative' => ['amount', '-1'], 'fractional cent' => ['amount', '0,001'],
            'future' => ['received_on', '2026-10-23'], 'before sale' => ['received_on', '2026-10-20'], 'invalid date' => ['received_on', '2026-02-30']];
    }

    public function test_untrusted_order_and_notes_are_escaped_in_details_and_history(): void
    {
        $data = $this->data($this->product());
        $data['notes'] = '<script>alert(1)</script>';
        $sale = app(SalesLedger::class)->save(null, $data);

        Livewire::test('pages::sales')->call('viewSale', $sale->id)->assertSee('<script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_changing_product_before_receipts_preserves_the_original_cost_in_history(): void
    {
        $ledger = app(SalesLedger::class);
        $firstProduct = $this->product();
        $sale = $ledger->save(null, $this->data($firstProduct));
        $originalCost = $sale->cost_snapshot;
        $secondProduct = $this->product();
        app(SupplyCatalog::class)->updateCost(Supply::query()->latest('id')->firstOrFail(), '20.00');

        $updated = $ledger->save($sale, $this->data($secondProduct), $sale->version);

        $this->assertSame('40.00', $ledger->figures($updated)['cost']);
        $this->assertSame($secondProduct->id, $updated->product_id);
        $this->assertSame($originalCost, $updated->revisions()->oldest('id')->firstOrFail()->snapshot['cost_snapshot']);
        $this->assertSame($firstProduct->id, $updated->revisions()->oldest('id')->firstOrFail()->snapshot['product_id']);
    }

    public function test_incomplete_reconciliation_and_unidentified_refunds_cannot_be_confirmed(): void
    {
        $ledger = app(SalesLedger::class);
        $data = $this->data($this->product());
        $sale = $ledger->save(null, $data);
        $refund = $this->receipt('REF-1', '10,00');
        $refund['kind'] = 'refund';
        $this->assertInvalid(fn () => $ledger->receive($sale, $refund, (string) Str::uuid()), 'notes');
        $this->assertInvalid(fn () => $ledger->cancel($sale, ' ', $sale->version), 'cancellation_reason');
        $data['reconciliation_closed'] = true;
        $data['reconciliation_note'] = 'Conferido';
        $data['marketplace_fees'] = '';

        $this->assertInvalid(fn () => $ledger->save($sale, $data, $sale->version), 'reconciliation_closed');

        $this->assertDatabaseCount('sale_receipts', 0);
        $this->assertDatabaseCount('sale_revisions', 1);
        $this->assertNull($sale->fresh()->cancelled_at);
    }

    public function test_livewire_shows_validation_errors_without_saving_incomplete_records(): void
    {
        Livewire::test('pages::sales')->call('newSale')->call('saveSale')
            ->assertHasErrors(['form.order_reference', 'form.product_id']);
        $this->assertDatabaseCount('sales', 0);
        $sale = app(SalesLedger::class)->save(null, $this->data($this->product()));

        Livewire::test('pages::sales')->call('viewSale', $sale->id)
            ->set('receipt.kind', 'refund')->set('receipt.reference', 'REF-1')->set('receipt.amount', '10,00')
            ->set('receipt.received_on', '2026-10-22')->call('addReceipt')->assertHasErrors(['receipt.notes'])
            ->assertSee('Identifique o motivo do estorno ou da correção.');

        $this->assertDatabaseCount('sale_receipts', 0);
    }

    private function product(): Product
    {
        $supply = Supply::factory()->create();
        app(SupplyCatalog::class)->updateCost($supply, '10.2500');
        $product = Product::factory()->create();
        app(ProductComposition::class)->replace($product, [['supply_id' => $supply->id, 'quantity' => '1']]);

        return $product;
    }

    /** @return array<string, mixed> */
    private function data(Product $product): array
    {
        return ['marketplace' => 'mercado_livre', 'order_reference' => 'PED-1', 'sold_on' => '2026-10-21',
            'product_id' => $product->id, 'quantity' => '2', 'gross_amount' => '100,10', 'seller_discount' => '10,00',
            'marketplace_fees' => '12,34', 'seller_freight' => '5,00', 'freight_source' => 'marketplace', 'other_adjustment' => '1,00',
            'expedition_cost' => '3,00', 'paid_labor' => '2,00', 'expected_on' => '2026-10-28'];
    }

    /** @return array{reference: string, amount: string, kind: string, received_on: string} */
    private function receipt(string $reference, string $amount): array
    {
        return ['reference' => $reference, 'amount' => $amount, 'kind' => 'receipt', 'received_on' => '2026-10-22'];
    }

    private function assertInvalid(callable $operation, string $field): void
    {
        try {
            $operation();
            $this->fail('Expected validation error for '.$field);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }
}
