<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Supply;
use App\Services\ProductCatalog;
use App\Services\ProductComposition;
use App\Services\ProductCostCalculator;
use App\Services\SupplyCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductCompositionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $connection = DB::connection('sqlite');
        if (config('database.default') !== 'sqlite' || $connection->getDriverName() !== 'sqlite' || $connection->getDatabaseName() !== ':memory:') {
            throw new \RuntimeException('These tests require SQLite in memory.');
        }

        $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true])->assertExitCode(0);
    }

    public function test_calculates_presentations_and_kit_from_current_supply_costs(): void
    {
        $supply = app(SupplyCatalog::class)->create(['name' => 'Synthetic test supply', 'unit' => 'kg', 'unit_cost' => '10.2500']);
        $packaging = app(SupplyCatalog::class)->create(['name' => 'Synthetic packaging', 'unit' => 'un', 'unit_cost' => '0.3500']);
        $half = Product::factory()->create(['net_weight_grams' => '500']);
        $whole = Product::factory()->create();
        $kit = Product::factory()->create(['net_weight_grams' => '2000']);
        $composition = app(ProductComposition::class);
        $calculator = app(ProductCostCalculator::class);

        $composition->replace($half, [['supply_id' => $supply->id, 'quantity' => '0.5']]);
        $composition->replace($whole, [
            ['supply_id' => $supply->id, 'quantity' => '1'],
            ['supply_id' => $packaging->id, 'quantity' => '1'],
        ]);
        $composition->replace($kit, [['component_product_id' => $whole->id, 'quantity' => '2']]);

        $this->assertSame('5.1250', $calculator->calculate($half));
        $this->assertSame('10.6000', $calculator->calculate($whole));
        $this->assertSame('21.2000', $calculator->calculate($kit));

        app(SupplyCatalog::class)->updateCost($supply, '12.0000');

        $this->assertSame('24.7000', $calculator->calculate($kit));
        $this->assertSame(['10.2500', '12.0000'], $supply->costs()->orderBy('id')->get()->pluck('unit_cost')->all());
        $this->assertSame('12.0000', $supply->fresh()->currentCost->unit_cost);
    }

    public function test_rounds_only_after_summing_nested_products(): void
    {
        $supply = app(SupplyCatalog::class)->create(['name' => 'Precision fixture', 'unit' => 'kg', 'unit_cost' => '0.0001']);
        $child = Product::factory()->create();
        $kit = Product::factory()->create();
        app(ProductComposition::class)->replace($child, [['supply_id' => $supply->id, 'quantity' => '0.333333']]);
        app(ProductComposition::class)->replace($kit, [['component_product_id' => $child->id, 'quantity' => '3']]);

        $this->assertSame('0.0001', app(ProductCostCalculator::class)->calculate($kit));
    }

    public function test_saves_updates_and_normalizes_product_sku(): void
    {
        $catalog = app(ProductCatalog::class);
        $data = Product::factory()->make()->getAttributes();
        $data['sku'] = ' test-500 ';
        $product = $catalog->save($data);
        $this->assertSame('TEST-500', $product->sku);
        $this->assertSame('1000.000', $product->net_weight_grams);
        $data['status'] = 'inactive';
        $catalog->save($data, $product);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'status' => 'inactive']);
        $this->assertDatabaseCount('products', 1);
    }

    public function test_rejects_duplicate_normalized_sku(): void
    {
        Product::factory()->create(['sku' => 'TEST']);
        $data = Product::factory()->make()->getAttributes();
        $data['sku'] = ' test ';
        $this->assertInvalid(fn () => app(ProductCatalog::class)->save($data), 'sku');
        $this->assertDatabaseCount('products', 1);
    }

    public function test_database_enforces_unique_sku(): void
    {
        Product::factory()->create(['sku' => 'TEST']);
        $this->expectException(QueryException::class);
        Product::factory()->create(['sku' => 'TEST']);
    }

    #[DataProvider('invalidProducts')]
    public function test_rejects_invalid_product_fields(string $field, mixed $value): void
    {
        $data = Product::factory()->make()->getAttributes();
        $data[$field] = $value;
        $this->assertInvalid(fn () => app(ProductCatalog::class)->save($data), $field);
        $this->assertDatabaseCount('products', 0);
    }

    public static function invalidProducts(): array
    {
        return [
            'empty name' => ['name', ' '],
            'empty sku' => ['sku', ' '],
            'long sku' => ['sku', str_repeat('a', 101)],
            'zero weight' => ['net_weight_grams', '0'],
            'negative weight' => ['net_weight_grams', '-1'],
            'excess weight scale' => ['net_weight_grams', '1.0001'],
            'float weight' => ['net_weight_grams', 1.5],
            'unknown status' => ['status', 'draft'],
        ];
    }

    #[DataProvider('invalidCosts')]
    public function test_rejects_invalid_cost_without_changing_history(mixed $cost): void
    {
        $supply = app(SupplyCatalog::class)->create(['name' => 'Cost fixture', 'unit' => 'kg', 'unit_cost' => '1.0000']);
        $this->assertInvalid(fn () => app(SupplyCatalog::class)->updateCost($supply, $cost), 'unit_cost');
        $this->assertDatabaseCount('supply_costs', 1);
        $this->assertSame('1.0000', $supply->fresh()->currentCost->unit_cost);
    }

    public static function invalidCosts(): array
    {
        return [
            'negative' => ['-0.01'],
            'float' => [0.1],
            'scientific notation' => ['1e2'],
            'comma' => ['1,25'],
            'excess scale' => ['1.00001'],
            'overflow' => ['10000000000'],
            'missing' => [null],
        ];
    }

    public function test_rejects_unknown_supply_unit(): void
    {
        $this->assertInvalid(fn () => app(SupplyCatalog::class)->create(['name' => 'Test', 'unit' => 'box', 'unit_cost' => '1']), 'unit');
        $this->assertDatabaseCount('supplies', 0);
        $this->assertDatabaseCount('supply_costs', 0);
    }

    public function test_accepts_explicit_zero_cost(): void
    {
        $supply = app(SupplyCatalog::class)->create(['name' => 'Zero fixture', 'unit' => 'un', 'unit_cost' => '0']);
        $product = Product::factory()->create();
        app(ProductComposition::class)->replace($product, [['supply_id' => $supply->id, 'quantity' => '1']]);
        $this->assertSame('0.0000', app(ProductCostCalculator::class)->calculate($product));
    }

    public function test_rejects_cycles_and_preserves_previous_composition(): void
    {
        $supply = Supply::factory()->create();
        $first = Product::factory()->create();
        $second = Product::factory()->create();
        $third = Product::factory()->create();
        $composition = app(ProductComposition::class);
        $composition->replace($first, [['supply_id' => $supply->id, 'quantity' => '1']]);
        $composition->replace($second, [['component_product_id' => $first->id, 'quantity' => '2']]);
        $composition->replace($third, [['component_product_id' => $second->id, 'quantity' => '2']]);

        $this->assertInvalid(fn () => $composition->replace($first, [['component_product_id' => $first->id, 'quantity' => '1']]), 'components.0');
        $this->assertInvalid(fn () => $composition->replace($first, [['component_product_id' => $third->id, 'quantity' => '1']]), 'components.0');
        $this->assertDatabaseHas('product_components', ['product_id' => $first->id, 'supply_id' => $supply->id, 'quantity' => '1']);
        $this->assertDatabaseCount('product_components', 3);
    }

    #[DataProvider('invalidComponents')]
    public function test_rejects_invalid_components_atomically(array $component, string $key): void
    {
        $supply = Supply::factory()->create();
        $product = Product::factory()->create();
        app(ProductComposition::class)->replace($product, [['supply_id' => $supply->id, 'quantity' => '1']]);
        $component = array_replace(['supply_id' => $supply->id, 'quantity' => '1'], $component);
        $this->assertInvalid(fn () => app(ProductComposition::class)->replace($product, [$component]), $key);
        $this->assertDatabaseHas('product_components', ['product_id' => $product->id, 'supply_id' => $supply->id, 'quantity' => '1']);
        $this->assertDatabaseCount('product_components', 1);
    }

    public static function invalidComponents(): array
    {
        return [
            'zero quantity' => [['quantity' => '0'], 'components.0.quantity'],
            'negative quantity' => [['quantity' => '-1'], 'components.0.quantity'],
            'float quantity' => [['quantity' => 0.1], 'components.0.quantity'],
            'excess scale' => [['quantity' => '0.0000001'], 'components.0.quantity'],
            'unknown supply' => [['supply_id' => 999999], 'components.0.supply_id'],
            'unknown product' => [['component_product_id' => 999999], 'components.0.component_product_id'],
            'no target' => [['supply_id' => null], 'components.0'],
            'both targets' => [['component_product_id' => 1], 'components.0'],
        ];
    }

    public function test_replaces_composition_and_rejects_duplicates(): void
    {
        $first = Supply::factory()->create();
        $second = Supply::factory()->create();
        $product = Product::factory()->create();
        $composition = app(ProductComposition::class);
        $composition->replace($product, [['supply_id' => $first->id, 'quantity' => '1']]);
        $composition->replace($product, [['supply_id' => $second->id, 'quantity' => '0.5']]);
        $this->assertDatabaseMissing('product_components', ['supply_id' => $first->id]);
        $this->assertDatabaseHas('product_components', ['supply_id' => $second->id, 'quantity' => '0.5']);

        $this->assertInvalid(fn () => $composition->replace($product, [
            ['supply_id' => $first->id, 'quantity' => '1'],
            ['supply_id' => $first->id, 'quantity' => '2'],
        ]), 'components.0.supply_id');
        $this->assertInvalid(fn () => $composition->replace($product, []), 'components');
        $this->assertDatabaseCount('product_components', 1);
    }

    public function test_rejects_calculation_without_composition(): void
    {
        $this->assertInvalid(fn () => app(ProductCostCalculator::class)->calculate(Product::factory()->create()), 'composition');
    }

    public function test_rejects_calculation_without_supply_cost(): void
    {
        $product = Product::factory()->create();
        app(ProductComposition::class)->replace($product, [['supply_id' => Supply::factory()->create()->id, 'quantity' => '1']]);
        $this->assertInvalid(fn () => app(ProductCostCalculator::class)->calculate($product), 'composition');
    }

    public function test_calculator_defends_against_cycles_inserted_outside_service(): void
    {
        $product = Product::factory()->create();
        $product->components()->create(['component_product_id' => $product->id, 'quantity' => '1']);
        $this->assertInvalid(fn () => app(ProductCostCalculator::class)->calculate($product), 'composition');
    }

    public function test_restricts_deletion_of_supplies_in_use(): void
    {
        $product = Product::factory()->create();
        $supply = Supply::factory()->create();
        app(ProductComposition::class)->replace($product, [['supply_id' => $supply->id, 'quantity' => '1']]);
        $this->expectException(QueryException::class);
        $supply->delete();
    }

    private function assertInvalid(callable $action, string $key): void
    {
        try {
            $action();
            $this->fail('Expected validation failure for '.$key);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());
        }
    }
}
