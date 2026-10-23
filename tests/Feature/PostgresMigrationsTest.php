<?php

namespace Tests\Feature;

use App\Models\CashMovement;
use App\Models\CashProjection;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleReceipt;
use App\Models\TariffProfile;
use App\Services\CashFlowProjection;
use App\Services\ProductComposition;
use App\Services\ProductCostCalculator;
use App\Services\SalesLedger;
use App\Services\SupplyCatalog;
use Database\Seeders\UserProvidedTariffSeeder;
use Dotenv\Dotenv;
use Illuminate\Database\ConfigurationUrlParser;
use Illuminate\Database\Connectors\PostgresConnector;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PostgresMigrationsTest extends TestCase
{
    public function test_migrations_and_product_costs_work_in_an_isolated_postgresql_database(): void
    {
        if (getenv('CAJUBI_POSTGRES_TESTS') !== '1') {
            $this->markTestSkipped('Set CAJUBI_POSTGRES_TESTS=1 to create a disposable PostgreSQL database.');
        }

        $settings = config('database.connections.pgsql');
        $environment = Dotenv::parse(file_get_contents(base_path('.env')));
        foreach (['DB_HOST' => 'host', 'DB_PORT' => 'port', 'DB_USERNAME' => 'username', 'DB_PASSWORD' => 'password', 'DB_DATABASE' => 'database', 'DB_URL' => 'url'] as $key => $setting) {
            if (array_key_exists($key, $environment)) {
                $settings[$setting] = $environment[$key];
            }
        }
        $settings = (new ConfigurationUrlParser)->parseConfiguration($settings);
        $database = 'cajubi_test_'.bin2hex(random_bytes(8));
        $this->assertNotSame($settings['database'], $database);

        $settings['url'] = null;
        unset($settings['connect_via_database'], $settings['connect_via_port']);
        $settings['database'] = 'postgres';
        $admin = (new PostgresConnector)->connect($settings);
        $admin->exec('CREATE DATABASE "'.$database.'"');

        try {
            $settings['database'] = $database;
            config(['database.default' => 'pgsql', 'database.connections.pgsql' => $settings]);
            DB::purge('pgsql');
            $this->assertSame($database, DB::connection()->selectOne('SELECT current_database() AS name')->name);

            $this->artisan('migrate', ['--database' => 'pgsql', '--force' => true, '--no-interaction' => true])->assertExitCode(0);
            foreach (['products', 'supplies', 'supply_costs', 'product_components', 'tariff_profiles', 'tariff_revisions'] as $table) {
                $this->assertTrue(Schema::hasTable($table), $table);
            }

            $this->seed(UserProvidedTariffSeeder::class);
            $this->seed(UserProvidedTariffSeeder::class);
            $this->assertDatabaseCount('tariff_profiles', 3);
            $this->assertDatabaseCount('tariff_revisions', 3);
            $tariff = TariffProfile::query()->orderBy('id')->firstOrFail();
            $this->assertSame('14.0000', $tariff->commission_percent);
            $this->assertSame('single', $tariff->pricing_model);
            $this->assertSame('item', $tariff->charge_basis);
            $this->assertNull($tariff->tiers);
            $this->assertNull($tariff->fixed_fee);
            $this->assertRejected(fn () => $tariff->update(['fixed_fee' => '-1']), '23514');
            $this->assertRejected(fn () => $tariff->update(['commission_percent' => '100.0001']), '23514');
            $this->assertSame('14.0000', $tariff->fresh()->revisions()->first()->snapshot['commission_percent']);

            $supply = app(SupplyCatalog::class)->create(['name' => 'PostgreSQL test fixture', 'unit' => 'kg', 'unit_cost' => '10.2500']);
            $supply = app(SupplyCatalog::class)->update($supply, ['name' => 'PostgreSQL edited supply', 'description' => 'Descrição editada', 'unit' => 'kg', 'unit_cost' => '']);
            $this->assertSame('Descrição editada', $supply->description);
            $this->assertSame('PostgreSQL edited supply', $supply->name);
            $this->assertSame(1, $supply->costs()->count());
            $product = Product::factory()->create();
            $kit = Product::factory()->create(['net_weight_grams' => '2000']);
            app(ProductComposition::class)->replace($product, [['supply_id' => $supply->id, 'quantity' => '1.5']]);
            app(ProductComposition::class)->replace($kit, [['component_product_id' => $product->id, 'quantity' => '2']]);
            $this->assertSame('30.7500', app(ProductCostCalculator::class)->calculate($kit));

            app(SupplyCatalog::class)->updateCost($supply, '12.0000');
            $this->assertSame('36.0000', app(ProductCostCalculator::class)->calculate($kit));
            $this->assertSame(['10.2500', '12.0000'], $supply->costs()->orderBy('id')->get()->pluck('unit_cost')->all());

            $this->assertRejected(fn () => $product->update(['net_weight_grams' => '0']), '23514');
            $this->assertRejected(fn () => $product->update(['status' => 'invalid']), '23514');
            $this->assertRejected(fn () => $supply->costs()->create(['unit_cost' => '-0.0001']), '23514');
            $this->assertRejected(fn () => $product->components()->create(['quantity' => '1']), '23514');
            $this->assertRejected(fn () => $product->components()->create(['supply_id' => $supply->id, 'component_product_id' => $kit->id, 'quantity' => '1']), '23514');
            $this->assertRejected(fn () => $kit->components()->create(['supply_id' => $supply->id, 'quantity' => '0']), '23514');
            $this->assertRejected(fn () => $product->components()->create(['component_product_id' => $product->id, 'quantity' => '1']), '23514');
            $this->assertRejected(fn () => Product::factory()->create(['sku' => $product->sku]), '23505');
            $this->assertRejected(fn () => $product->components()->create(['supply_id' => $supply->id, 'quantity' => '1']), '23505');
            $this->assertRejected(fn () => $kit->components()->create(['component_product_id' => $product->id, 'quantity' => '1']), '23505');
            $this->assertRejected(fn () => $supply->delete(), '23001');
            $this->assertRejected(fn () => $product->delete(), '23001');
            $this->assertRejected(fn () => $product->components()->create(['supply_id' => 999999, 'quantity' => '1']), '23503');

            $this->artisan('migrate', ['--database' => 'pgsql', '--force' => true, '--no-interaction' => true])->assertExitCode(0);
            $this->assertDatabaseCount('products', 2);
            $this->assertDatabaseCount('supply_costs', 2);
            $this->assertDatabaseCount('product_components', 2);

            $projection = CashProjection::factory()->create(['opening_balance' => '100.10']);
            $movement = CashMovement::factory()->for($projection)->create(['amount' => '150.20']);
            $forecast = app(CashFlowProjection::class)->calculate($projection, $projection->movements);
            $this->assertSame('50.10', $forecast['additional_capital']);
            $this->assertRejected(fn () => $movement->update(['amount' => '0']), '23514');
            $this->assertRejected(fn () => $movement->update(['direction' => 'sale']), '23514');
            $this->assertRejected(fn () => CashMovement::factory()->for($projection)->create(['submission_key' => $movement->submission_key]), '23505');

            $sale = app(SalesLedger::class)->save(null, ['marketplace' => 'shopee', 'order_reference' => 'POSTGRES-1',
                'sold_on' => '2026-10-21', 'product_id' => $product->id, 'quantity' => '2', 'gross_amount' => '100.10',
                'seller_discount' => '0.10', 'marketplace_fees' => '12.34', 'seller_freight' => '0.00',
                'other_adjustment' => '0.00', 'expedition_cost' => '3.00', 'paid_labor' => '0.00']);
            $receipt = app(SalesLedger::class)->receive($sale, ['reference' => 'POSTGRES-REP-1', 'kind' => 'receipt', 'amount' => '50.00', 'received_on' => '2026-10-22'], (string) Str::uuid());
            $this->assertSame('37.66', app(SalesLedger::class)->figures($sale->fresh('receipts'))['pending']);
            $this->assertRejected(fn () => $sale->update(['quantity' => '0']), '23514');
            $this->assertRejected(fn () => $receipt->update(['amount' => '-1']), '23514');
            $this->assertRejected(fn () => Sale::factory()->create(['product_id' => $product->id, 'marketplace' => 'shopee', 'order_reference' => 'POSTGRES-1']), '23505');
            $this->assertRejected(fn () => SaleReceipt::factory()->for($sale)->create(['reference' => 'POSTGRES-REP-1']), '23505');
            $this->assertRejected(fn () => $sale->delete(), '23001');
        } finally {
            DB::purge('pgsql');
            $admin->exec('DROP DATABASE "'.$database.'"');
        }
    }

    private function assertRejected(callable $operation, string $sqlState): void
    {
        try {
            DB::transaction($operation);
            $this->fail('Expected PostgreSQL constraint violation.');
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, $exception->errorInfo[0]);
        }
    }
}
