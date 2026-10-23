<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\PriceSimulator;
use App\Services\ProductComposition;
use App\Services\SupplyCatalog;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PriceSimulatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $connection = DB::connection('sqlite');
        if (config('database.default') !== 'sqlite' || $connection->getDriverName() !== 'sqlite' || $connection->getDatabaseName() !== ':memory:') {
            throw new \RuntimeException('Simulator tests require SQLite in memory.');
        }
        $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true])->assertExitCode(0);
    }

    #[DataProvider('contributionScenarios')]
    public function test_separates_marketplace_payout_and_contribution(string $channel, string $price, string $commission, string $payout, string $contribution, string $percent, string $tone): void
    {
        $product = $this->productWithCost('20');
        $result = app(PriceSimulator::class)->simulate(array_replace($this->parameters($product), ['channel' => $channel, 'sale_price' => $price, 'commission_percent' => $commission]));

        $this->assertSame($payout, $result['marketplace_payout']);
        $this->assertSame($contribution, $result['contribution']);
        $this->assertSame($percent, $result['contribution_percent']);
        $this->assertSame($tone, $result['tone']);
        $this->assertSame('20.0000', $result['product_cost']);
    }

    public static function contributionScenarios(): array
    {
        return [
            'positive Mercado Livre' => ['mercado_livre', '100', '10', '85.00', '55.0000', '55.0000', 'positive'],
            'negative Shopee' => ['shopee', '20', '10', '13.00', '-17.0000', '-85.0000', 'negative'],
            'break even' => ['mercado_livre', '35', '0', '30.00', '0.0000', '0.0000', 'zero'],
            'negative payout allowed' => ['shopee', '1', '100', '-5.00', '-35.0000', '-3500.0000', 'negative'],
        ];
    }

    public function test_preserves_fractional_cent_precision_and_accepts_explicit_zero_expenses(): void
    {
        $product = $this->productWithCost('0.1');
        $parameters = array_replace($this->parameters($product), ['sale_price' => '0.3', 'commission_percent' => '0.1', 'fixed_fee' => '0', 'seller_shipping' => '0', 'other_variable_expenses' => '0']);
        $result = app(PriceSimulator::class)->simulate($parameters);

        $this->assertSame('0.0003', $result['commission_amount']);
        $this->assertSame('0.2997', $result['marketplace_payout']);
        $this->assertSame('0.1997', $result['contribution']);
        $this->assertSame('66.5667', $result['contribution_percent']);
    }

    #[DataProvider('missingFields')]
    public function test_missing_parameters_do_not_become_zero(string $field): void
    {
        $parameters = $this->parameters($this->productWithCost('20'));
        unset($parameters[$field]);
        $this->expectException(ValidationException::class);
        app(PriceSimulator::class)->simulate($parameters);
    }

    public static function missingFields(): array
    {
        return array_map(fn (string $field): array => [$field], [
            'product_id', 'channel', 'sale_price', 'commission_percent', 'fixed_fee', 'seller_shipping', 'other_variable_expenses',
        ]);
    }

    #[DataProvider('invalidValues')]
    public function test_invalid_values_are_rejected(string $field, mixed $value): void
    {
        $parameters = $this->parameters($this->productWithCost('20'));
        $parameters[$field] = $value;

        try {
            app(PriceSimulator::class)->simulate($parameters);
            $this->fail('Expected invalid '.$field);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public static function invalidValues(): array
    {
        return [
            'zero price' => ['sale_price', '0'],
            'negative price' => ['sale_price', '-1'],
            'negative commission' => ['commission_percent', '-1'],
            'commission above 100' => ['commission_percent', '100.0001'],
            'negative fixed fee' => ['fixed_fee', '-1'],
            'negative shipping' => ['seller_shipping', '-1'],
            'negative other expenses' => ['other_variable_expenses', '-1'],
            'float money' => ['sale_price', 0.3],
            'float commission' => ['commission_percent', 1.5],
            'excess precision' => ['fixed_fee', '1.00001'],
            'scientific notation' => ['sale_price', '1e2'],
            'unknown channel' => ['channel', 'amazon'],
            'missing product' => ['product_id', 999999],
            'empty shipping' => ['seller_shipping', ''],
            'null expenses' => ['other_variable_expenses', null],
        ];
    }

    public function test_missing_product_composition_prevents_simulation(): void
    {
        $product = Product::factory()->create();
        try {
            app(PriceSimulator::class)->simulate($this->parameters($product));
            $this->fail('Expected missing cost error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('product_id', $exception->errors());
        }
    }

    public function test_latest_supply_cost_is_used_without_writing_commercial_data(): void
    {
        $product = $this->productWithCost('20');
        $supply = $product->components()->first()->supply;
        app(SupplyCatalog::class)->updateCost($supply, '25.1234');

        $result = app(PriceSimulator::class)->simulate($this->parameters($product));

        $this->assertSame('25.1234', $result['product_cost']);
        $this->assertSame('49.8766', $result['contribution']);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_components', 1);
        $this->assertDatabaseCount('supplies', 1);
        $this->assertDatabaseCount('supply_costs', 2);
        $this->assertSame(['20.0000', '25.1234'], $supply->costs()->orderBy('id')->get()->pluck('unit_cost')->all());
    }

    #[DataProvider('inverseScenarios')]
    public function test_inverse_price_meets_target_and_one_cent_less_does_not(string $target, string $commission, string $expectedPrice): void
    {
        $product = $this->productWithCost('20');
        $parameters = array_replace($this->parameters($product), ['desired_contribution' => $target, 'commission_percent' => $commission]);
        unset($parameters['sale_price']);
        $simulator = app(PriceSimulator::class);

        $result = $simulator->findPrice($parameters);

        $this->assertSame($expectedPrice, $result['suggested_price']);
        $this->assertTrue(BigDecimal::of($result['contribution'])->isGreaterThanOrEqualTo($target));
        $forward = $simulator->simulate($parameters + ['sale_price' => $result['suggested_price']]);
        $this->assertSame($forward['contribution'], $result['contribution']);
        $previousPrice = (string) BigDecimal::of($result['suggested_price'])->minus('0.01');
        $previous = $simulator->simulate($parameters + ['sale_price' => $previousPrice]);
        $this->assertTrue(BigDecimal::of($previous['contribution'])->isLessThan($target));
    }

    public static function inverseScenarios(): array
    {
        return [
            'exact cent' => ['55', '10', '100.00'],
            'round upwards' => ['10', '12', '51.14'],
            'fractional cent target' => ['10.0001', '12', '51.14'],
            'explicit zero target' => ['0', '10', '38.89'],
            'zero commission' => ['10', '0', '45.00'],
            'near 100 percent' => ['20', '99.9999', '55000000.00'],
        ];
    }

    #[DataProvider('invalidInverseValues')]
    public function test_inverse_rejects_invalid_or_unachievable_targets(string $field, mixed $value): void
    {
        $parameters = $this->parameters($this->productWithCost('20')) + ['desired_contribution' => '10'];
        $parameters[$field] = $value;
        try {
            app(PriceSimulator::class)->findPrice($parameters);
            $this->fail('Expected invalid inverse parameter.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public static function invalidInverseValues(): array
    {
        return [
            '100 percent' => ['commission_percent', '100'],
            'over 100 percent' => ['commission_percent', '100.0001'],
            'negative commission' => ['commission_percent', '-1'],
            'negative target' => ['desired_contribution', '-1'],
            'float target' => ['desired_contribution', 0.1],
            'target precision' => ['desired_contribution', '1.00001'],
            'empty target' => ['desired_contribution', ''],
            'target above price limit' => ['desired_contribution', '9999999999.9999'],
            'negative fee' => ['fixed_fee', '-1'],
            'missing shipping' => ['seller_shipping', null],
            'missing expenses' => ['other_variable_expenses', ''],
        ];
    }

    #[DataProvider('missingFields')]
    public function test_inverse_requires_all_parameters_without_assuming_zero(string $field): void
    {
        $field = $field === 'sale_price' ? 'desired_contribution' : $field;
        $parameters = $this->parameters($this->productWithCost('20')) + ['desired_contribution' => '10'];
        unset($parameters[$field]);
        $this->expectException(ValidationException::class);
        app(PriceSimulator::class)->findPrice($parameters);
    }

    public function test_inverse_uses_minimum_positive_cent_for_zero_cost_and_zero_target(): void
    {
        $product = $this->productWithCost('0');
        $parameters = array_replace($this->parameters($product), ['desired_contribution' => '0', 'commission_percent' => '0', 'fixed_fee' => '0', 'seller_shipping' => '0', 'other_variable_expenses' => '0']);
        $result = app(PriceSimulator::class)->findPrice($parameters);
        $this->assertSame('0.01', $result['suggested_price']);
        $this->assertSame('0.0100', $result['contribution']);
    }

    public function test_inverse_requires_known_product_cost_and_does_not_write_data(): void
    {
        $product = Product::factory()->create();
        $parameters = $this->parameters($product) + ['desired_contribution' => '10'];
        try {
            app(PriceSimulator::class)->findPrice($parameters);
            $this->fail('Expected missing cost error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('product_id', $exception->errors());
        }
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('supply_costs', 0);
    }

    private function productWithCost(string $cost): Product
    {
        $product = Product::factory()->create();
        $supply = app(SupplyCatalog::class)->create(['name' => 'Insumo sintético de teste', 'unit' => 'un', 'unit_cost' => $cost]);
        app(ProductComposition::class)->replace($product, [['supply_id' => $supply->id, 'quantity' => '1']]);

        return $product;
    }

    private function parameters(Product $product): array
    {
        return ['product_id' => $product->id, 'channel' => 'mercado_livre', 'sale_price' => '100', 'commission_percent' => '10', 'fixed_fee' => '5', 'seller_shipping' => '8', 'other_variable_expenses' => '2'];
    }
}
