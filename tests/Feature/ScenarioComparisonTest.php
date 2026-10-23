<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Supply;
use App\Services\ProductComposition;
use App\Services\ProductCostCalculator;
use App\Services\ScenarioComparison;
use App\Services\SupplyCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ScenarioComparisonTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $connection = DB::connection('sqlite');
        if (config('database.default') !== 'sqlite' || $connection->getDriverName() !== 'sqlite' || $connection->getDatabaseName() !== ':memory:') {
            throw new \RuntimeException('Comparison tests require SQLite in memory.');
        }
        $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true])->assertExitCode(0);
    }

    public function test_user_example_uses_exact_contributions_and_rounds_required_volume_up(): void
    {
        $unit = Product::factory()->create();
        $kit = Product::factory()->create(['net_weight_grams' => '2000']);
        $supply = app(SupplyCatalog::class)->create(['name' => 'Insumo sintético de teste', 'unit' => 'kg', 'unit_cost' => '53.7650']);
        app(ProductComposition::class)->replace($unit, [['supply_id' => $supply->id, 'quantity' => '1']]);
        app(ProductComposition::class)->replace($kit, [['component_product_id' => $unit->id, 'quantity' => '2']]);
        $service = app(ScenarioComparison::class);
        $parameters = array_replace($this->parameters($kit), ['sale_price' => '189.90', 'seller_shipping' => '21.75', 'quantity' => '100']);
        $classic = $service->evaluate($parameters + ['commission_percent' => '14']);
        $premium = $service->evaluate($parameters + ['commission_percent' => '19']);

        $this->assertSame('107.5300', $classic['product_cost']);
        $this->assertSame('34.0340', $classic['contribution']);
        $this->assertSame('24.5390', $premium['contribution']);
        $this->assertSame('18990.00', $classic['gross_revenue']);
        $this->assertSame('4833.6000', $classic['fees_and_expenses_total']);
        $this->assertSame('16331.4000', $classic['payout_total']);
        $this->assertSame('10753.0000', $classic['product_cost_total']);
        $this->assertSame('3403.4000', $classic['contribution_total']);
        $this->assertSame('2453.9000', $premium['contribution_total']);
        $volume = $service->matchVolume($premium, $classic);
        $this->assertSame('139', $volume['required_units']);
        $this->assertSame('39', $volume['additional_units']);
        $this->assertSame('39', $volume['extra_vs_reference_units']);
        $this->assertSame('39.0000', $volume['extra_percent']);
        $this->assertSame('3410.9210', $service->evaluate(array_replace($parameters, ['commission_percent' => '19', 'quantity' => '139']))['contribution_total']);
        $this->assertSame('3386.3820', $service->evaluate(array_replace($parameters, ['commission_percent' => '19', 'quantity' => '138']))['contribution_total']);
    }

    public function test_totals_do_not_multiply_unit_contribution_rounded_to_cents(): void
    {
        $product = $this->product('0.1');
        $result = app(ScenarioComparison::class)->evaluate($this->parameters($product) + ['commission_percent' => '0.1']);
        $this->assertSame('0.1997', $result['contribution']);
        $this->assertSame('19.9700', $result['contribution_total']);
        $this->assertSame('0.0300', $result['marketplace_fees_total']);
    }

    public function test_alternative_supply_cost_propagates_through_kits_without_writing_history(): void
    {
        $child = $this->product('10');
        $supply = $child->components()->first()->supply;
        $kit = Product::factory()->create();
        app(ProductComposition::class)->replace($kit, [['component_product_id' => $child->id, 'quantity' => '2']]);
        $parameters = $this->parameters($kit) + ['commission_percent' => '0'];
        $alternative = app(ScenarioComparison::class)->evaluate(array_replace($parameters, ['cost_mode' => 'alternative', 'cost_overrides' => [$supply->id => '12.3456']]));
        $current = app(ScenarioComparison::class)->evaluate($parameters);

        $this->assertSame('24.6912', $alternative['product_cost']);
        $this->assertSame('20.0000', $current['product_cost']);
        $this->assertStringContainsString('hipotéticos', implode(' ', $alternative['warnings']));
        $this->assertSame('10.0000', $supply->fresh()->currentCost->unit_cost);
        $this->assertDatabaseCount('supply_costs', 1);
        $this->assertDatabaseCount('product_components', 2);
    }

    public function test_explicit_zero_cost_and_zero_sales_are_hypotheses_not_missing_values(): void
    {
        $product = $this->product('10');
        $supply = $product->components()->first()->supply;
        $parameters = array_replace($this->parameters($product), ['commission_percent' => '0', 'quantity' => '0', 'cost_mode' => 'alternative', 'cost_overrides' => [$supply->id => '0']]);
        $result = app(ScenarioComparison::class)->evaluate($parameters);
        $this->assertSame('0.0000', $result['product_cost']);
        $this->assertSame('0.0000', $result['contribution_total']);
        $this->assertSame('0.0', $result['gross_revenue']);
        $this->assertSame('10.0000', $supply->fresh()->currentCost->unit_cost);
    }

    #[DataProvider('invalidValues')]
    public function test_incomplete_or_invalid_values_do_not_produce_results(string $field, mixed $value): void
    {
        $parameters = $this->parameters($this->product('10')) + ['commission_percent' => '14'];
        $parameters[$field] = $value;
        $this->expectException(ValidationException::class);
        app(ScenarioComparison::class)->evaluate($parameters);
    }

    public static function invalidValues(): array
    {
        return [
            'missing quantity' => ['quantity', ''],
            'negative quantity' => ['quantity', '-1'],
            'fractional quantity' => ['quantity', '1.5'],
            'float quantity' => ['quantity', 1.5],
            'missing shipping' => ['seller_shipping', ''],
            'unknown fixed fee' => ['fixed_fee', null],
            'missing commission' => ['commission_percent', ''],
            'negative price' => ['sale_price', '-1'],
            'float money' => ['sale_price', 0.3],
            'unknown cost mode' => ['cost_mode', 'invented'],
            'alternative without values' => ['cost_mode', 'alternative'],
        ];
    }

    public function test_invalid_or_unrelated_cost_overrides_are_rejected(): void
    {
        $product = $this->product('10');
        $supply = $product->components()->first()->supply;
        $other = Supply::factory()->create();
        foreach ([[$supply->id => '-1'], [$supply->id => 0.1], [$supply->id => '1.00001'], [$other->id => '1']] as $overrides) {
            try {
                app(ProductCostCalculator::class)->calculate($product, $overrides);
                $this->fail('Expected invalid alternative input cost.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
        $this->assertDatabaseCount('supply_costs', 1);
    }

    #[DataProvider('nonPositiveVolumes')]
    public function test_compensation_handles_nonpositive_contributions(string $candidateUnit, string $referenceTotal, string $message): void
    {
        $result = app(ScenarioComparison::class)->matchVolume(
            ['contribution' => $candidateUnit, 'quantity' => '100'],
            ['contribution_total' => $referenceTotal, 'quantity' => '100']
        );
        $this->assertNull($result['required_units']);
        $this->assertStringContainsString($message, $result['message']);
    }

    public static function nonPositiveVolumes(): array
    {
        return [
            'negative unit' => ['-1', '100', 'Não há volume'],
            'zero unit' => ['0', '100', 'Não há volume'],
            'negative reference' => ['10', '-100', 'não se aplica'],
            'zero reference' => ['10', '0', 'não se aplica'],
        ];
    }

    public function test_existing_volume_may_already_cover_reference_without_additional_units(): void
    {
        $result = app(ScenarioComparison::class)->matchVolume(
            ['contribution' => '5', 'quantity' => '30'],
            ['contribution_total' => '100', 'quantity' => '10']
        );
        $this->assertSame('20', $result['required_units']);
        $this->assertSame('0', $result['additional_units']);
        $this->assertSame('10', $result['extra_vs_reference_units']);
    }

    public function test_sensitivity_can_explicitly_fill_an_unknown_input_cost(): void
    {
        $product = Product::factory()->create();
        $supply = Supply::factory()->create();
        app(ProductComposition::class)->replace($product, [['supply_id' => $supply->id, 'quantity' => '0.5']]);
        $parameters = $this->parameters($product) + ['commission_percent' => '0'];
        $result = app(ScenarioComparison::class)->evaluate(array_replace($parameters, ['cost_mode' => 'alternative', 'cost_overrides' => [$supply->id => '20']]));
        $this->assertSame('10.0000', $result['product_cost']);
        $this->assertDatabaseCount('supply_costs', 0);
        $this->assertCount(1, app(ProductCostCalculator::class)->supplies($product));
    }

    private function product(string $cost): Product
    {
        $product = Product::factory()->create();
        $supply = app(SupplyCatalog::class)->create(['name' => 'Insumo de teste', 'unit' => 'kg', 'unit_cost' => $cost]);
        app(ProductComposition::class)->replace($product, [['supply_id' => $supply->id, 'quantity' => '1']]);

        return $product;
    }

    private function parameters(Product $product): array
    {
        return ['product_id' => $product->id, 'channel' => 'mercado_livre', 'sale_price' => '0.3',
            'fixed_fee' => '0', 'seller_shipping' => '0', 'other_variable_expenses' => '0',
            'quantity' => '100', 'cost_mode' => 'current', 'cost_overrides' => []];
    }
}
