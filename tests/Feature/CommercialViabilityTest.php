<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Supply;
use App\Models\User;
use App\Services\CommercialViability;
use App\Services\DecimalPresentation;
use App\Services\ProductComposition;
use App\Services\ProductCostCalculator;
use App\Services\SupplyCatalog;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CommercialViabilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            throw new \RuntimeException('Viability tests require SQLite in memory.');
        }
        $this->withoutVite();
        $this->artisan('migrate', ['--force' => true, '--no-interaction' => true])->assertExitCode(0);
        $this->actingAs(User::factory()->create());
    }

    public function test_nested_kits_aggregate_shared_inputs_and_separate_finished_weight_from_raw_material(): void
    {
        [$unit, $nut, $bag] = $this->product();
        $inner = Product::factory()->create(['net_weight_grams' => '2000']);
        $kit = Product::factory()->create(['net_weight_grams' => '4000']);
        app(ProductComposition::class)->replace($inner, [['component_product_id' => $unit->id, 'quantity' => '2'], ['supply_id' => $bag->id, 'quantity' => '1']]);
        app(ProductComposition::class)->replace($kit, [['component_product_id' => $inner->id, 'quantity' => '2']]);
        $result = app(CommercialViability::class)->lot(['product_id' => $kit->id, 'units' => '5', 'additional_cost' => '30']);
        $this->assertSame('20000.000', $result['finished_weight_grams']);
        $this->assertTrue(BigDecimal::of($result['supplies'][$nut->id]['quantity'])->isEqualTo('21'));
        $this->assertTrue(BigDecimal::of($result['supplies'][$bag->id]['quantity'])->isEqualTo('30'));
        $this->assertSame('1110.0000', $result['material_cost']);
        $this->assertSame('1140.0000', $result['total_cost']);
        $this->assertSame('228.0000', $result['unit_cost']);
        $this->assertSame('222.0000', app(ProductCostCalculator::class)->calculate($kit));
    }

    public function test_additional_cost_is_per_lot_and_unit_price_is_not_rounded_before_multiplication(): void
    {
        $supply = app(SupplyCatalog::class)->create(['name' => 'Insumo de teste', 'unit' => 'kg', 'unit_cost' => '0.3333']);
        $product = Product::factory()->create();
        app(ProductComposition::class)->replace($product, [['supply_id' => $supply->id, 'quantity' => '0.333333']]);
        $result = app(CommercialViability::class)->lot(['product_id' => $product->id, 'units' => '100', 'additional_cost' => '1']);
        $this->assertSame('11.1100', $result['material_cost']);
        $this->assertSame('12.1100', $result['total_cost']);
        $this->assertSame('0.1211', $result['unit_cost']);
        $this->assertSame('10', DecimalPresentation::quantity('10'));
        $this->assertSame('0,000000000001', DecimalPresentation::quantity('0.000000000001'));
    }

    #[DataProvider('invalidLotData')]
    public function test_missing_negative_or_fractional_batch_parameters_remain_pending(string $field, mixed $value): void
    {
        [$product] = $this->product();
        $data = array_replace(['product_id' => $product->id, 'units' => '10', 'additional_cost' => '0'], [$field => $value]);
        try {
            app(CommercialViability::class)->lot($data);
            $this->fail('Invalid lot must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public static function invalidLotData(): array
    {
        return [['units', ''], ['units', '0'], ['units', '-1'], ['units', '1.5'], ['units', '1000000000'],
            ['additional_cost', ''], ['additional_cost', '-1'], ['additional_cost', '1.00001'], ['additional_cost', 0.5], ['product_id', '999999']];
    }

    public function test_missing_composition_and_unknown_costs_block_batches_but_an_explicit_alternative_can_supply_the_cost(): void
    {
        $product = Product::factory()->create();
        $service = app(CommercialViability::class);
        $data = ['product_id' => $product->id, 'units' => '1', 'additional_cost' => '0'];
        try {
            $service->lot($data);
            $this->fail('Empty composition must be pending.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('sem composição', $exception->validator->errors()->first());
        }
        $supply = Supply::factory()->create();
        app(ProductComposition::class)->replace($product, [['supply_id' => $supply->id, 'quantity' => '1']]);
        try {
            $service->lot($data);
            $this->fail('Unknown costs must not be zero.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('sem custo', $exception->validator->errors()->first());
        }
        $this->assertSame('0.0000', $service->lot($data, [$supply->id => '0'])['total_cost']);
        $this->assertDatabaseCount('supply_costs', 0);
    }

    public function test_funding_separates_categories_and_does_not_add_inventory_twice(): void
    {
        [$product] = $this->product();
        $service = app(CommercialViability::class);
        $lot = $service->lot(['product_id' => $product->id, 'units' => '10', 'additional_cost' => '20']);
        $data = $this->fundingData();
        $data['expenses'] = [
            ['name' => 'Equipamento', 'category' => 'equipment', 'coverage' => 'additional', 'amount' => '100'],
            ['name' => 'Estoque além do lote', 'category' => 'inventory', 'coverage' => 'additional', 'amount' => '50'],
            ['name' => 'Despesa inicial', 'category' => 'startup', 'coverage' => 'additional', 'amount' => '25'],
            ['name' => 'Castanha já na composição', 'category' => 'inventory', 'coverage' => 'included_in_lot', 'amount' => '525'],
            ['name' => 'Frete já no adicional', 'category' => 'startup', 'coverage' => 'included_in_lot', 'amount' => '20'],
        ];
        $result = $service->funding($data, $lot);
        $this->assertSame('100', $result['equipment']);
        $this->assertSame('50', $result['extra_inventory']);
        $this->assertSame('25', $result['startup_expenses']);
        $this->assertSame('545', $result['excluded_duplicates']);
        $this->assertSame('740.0000', $result['initial_resources']);
        $this->assertSame('300.00', $result['operating_bridge']);
        $this->assertSame('20', $result['gap_days']);
        $this->assertSame('615.0000', $result['already_in_initial']);
        $this->assertSame('1015.0000', $result['working_capital']);
        $this->assertSame('1140.0000', $result['total_resources']);
    }

    public function test_supplier_credit_changes_exposure_without_deducting_unconfirmed_sales_from_gross_resources(): void
    {
        [$product] = $this->product();
        $service = app(CommercialViability::class);
        $lot = $service->lot(['product_id' => $product->id, 'units' => '1', 'additional_cost' => '0']);
        $later = $service->funding(array_replace($this->fundingData(), ['supplier_days' => '40']), $lot);
        $sameDay = $service->funding(array_replace($this->fundingData(), ['supplier_days' => '30']), $lot);
        $this->assertSame('0', $later['gap_days']);
        $this->assertSame('0', $later['inventory_exposure']);
        $this->assertSame('400.00', $later['working_capital']);
        $this->assertSame('454.5000', $later['total_resources']);
        $this->assertSame('454.5000', $sameDay['working_capital']);
        $this->assertSame($later['total_resources'], $sameDay['total_resources']);
    }

    public function test_operating_cash_is_covered_until_receipt_and_rounded_up_to_the_cent(): void
    {
        [$product] = $this->product();
        $service = app(CommercialViability::class);
        $lot = $service->lot(['product_id' => $product->id, 'units' => '1', 'additional_cost' => '0']);
        $result = $service->funding(array_replace($this->fundingData(), ['receipt_days' => '1', 'supplier_days' => '10', 'monthly_operating' => '1', 'reserve' => '0']), $lot);
        $this->assertSame('0.04', $result['operating_bridge']);
        $this->assertSame('0.04', $result['working_capital']);
    }

    #[DataProvider('invalidFunding')]
    public function test_unknown_or_invalid_funding_values_block_the_total(string $field, mixed $value): void
    {
        [$product] = $this->product();
        $service = app(CommercialViability::class);
        $lot = $service->lot(['product_id' => $product->id, 'units' => '1', 'additional_cost' => '0']);
        try {
            $service->funding(array_replace($this->fundingData(), [$field => $value]), $lot);
            $this->fail('Incomplete funding must not produce a total.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public static function invalidFunding(): array
    {
        return [['receipt_days', ''], ['receipt_days', '-1'], ['receipt_days', '1.5'], ['supplier_days', ''], ['supplier_days', '-1'],
            ['monthly_operating', ''], ['monthly_operating', '-1'], ['reserve', ''], ['reserve', '-1'], ['expenses_reviewed', false]];
    }

    public function test_equipment_cannot_be_hidden_as_a_cost_already_in_the_batch(): void
    {
        [$product] = $this->product();
        $service = app(CommercialViability::class);
        $lot = $service->lot(['product_id' => $product->id, 'units' => '1', 'additional_cost' => '0']);
        $data = $this->fundingData();
        $data['expenses'] = [['name' => 'Balança', 'category' => 'equipment', 'coverage' => 'included_in_lot', 'amount' => '100']];
        $this->expectException(ValidationException::class);
        $service->funding($data, $lot);
    }

    public function test_explicit_zero_operating_cost_and_reserve_are_valid(): void
    {
        [$product] = $this->product();
        $service = app(CommercialViability::class);
        $lot = $service->lot(['product_id' => $product->id, 'units' => '1', 'additional_cost' => '0']);
        $result = $service->funding(array_replace($this->fundingData(), ['receipt_days' => '0', 'supplier_days' => '0', 'monthly_operating' => '0', 'reserve' => '0']), $lot);
        $this->assertSame('54.5000', $result['total_resources']);
    }

    public function test_interface_reacts_to_lot_and_alternative_cost_without_writing_commercial_records(): void
    {
        [$product, $nut] = $this->product();
        $this->get(route('viability'))->assertOk()->assertSee('Viabilidade comercial')->assertSee('Lote pendente');
        $this->get(route('products.index'))->assertSee(route('viability'));
        $before = $this->commercialState();
        $component = Livewire::test('pages::viability')->set('product_id', (string) $product->id)
            ->set('lots.0.units', '10')->set('lots.0.additional_cost', '20')
            ->set('receipt_days', '30')->set('supplier_days', '10')->set('monthly_operating', '300')->set('reserve', '100')
            ->set('expenses_reviewed', true)->assertSee('R$ 965,00')
            ->set('sensitivity_supply_id', (string) $nut->id)->set('prices.0', '43')
            ->assertSee('R$ 891,50')->assertSee('R$ 491,50')
            ->set('prices.1', '55')->assertSee('R$ 1.017,50')
            ->call('addLot')->assertCount('lots', 3)->call('removeLot', 2)
            ->call('addExpense')->assertSet('expenses_reviewed', false)
            ->set('expenses.0.name', '<script>alert(1)</script>')->assertDontSeeHtml('<script>alert(1)</script>')
            ->call('removeExpense', 0)->call('addPrice')->call('removePrice', 2);
        $component->set('product_id', '')->assertSet('sensitivity_supply_id', '')->assertSee('Selecione um produto');
        $this->assertSame($before, $this->commercialState());
    }

    public function test_removing_the_selected_lot_requires_reviewing_expenses_for_the_next_lot(): void
    {
        $component = Livewire::test('pages::viability')->set('expenses_reviewed', true);
        $next = $component->get('lots')[1]['key'];
        $component->call('removeLot', 0)->assertSet('selected_lot', $next)->assertSet('expenses_reviewed', false)
            ->call('removeLot', 0)->assertSet('selected_lot', '')->assertSee('Adicione um lote para começar.');
    }

    private function product(): array
    {
        $product = Product::factory()->create(['net_weight_grams' => '1000']);
        $nut = app(SupplyCatalog::class)->create(['name' => 'Castanha sintética de teste', 'unit' => 'kg', 'unit_cost' => '50']);
        $bag = app(SupplyCatalog::class)->create(['name' => 'Embalagem de teste', 'unit' => 'un', 'unit_cost' => '2']);
        app(ProductComposition::class)->replace($product, [['supply_id' => $nut->id, 'quantity' => '1.05'], ['supply_id' => $bag->id, 'quantity' => '1']]);

        return [$product, $nut, $bag];
    }

    private function fundingData(): array
    {
        return ['expenses_reviewed' => true, 'expenses' => [], 'receipt_days' => '30', 'supplier_days' => '10', 'monthly_operating' => '300', 'reserve' => '100'];
    }

    private function commercialState(): string
    {
        $state = [];
        foreach (['products', 'supplies', 'supply_costs', 'product_components', 'tariff_profiles', 'tariff_revisions'] as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->toArray();
        }

        return json_encode($state, JSON_THROW_ON_ERROR);
    }
}
