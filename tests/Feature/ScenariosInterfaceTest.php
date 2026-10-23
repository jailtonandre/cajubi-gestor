<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\TariffProfile;
use App\Models\User;
use App\Services\ProductComposition;
use App\Services\SupplyCatalog;
use Database\Seeders\UserProvidedTariffSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class ScenariosInterfaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $connection = DB::connection('sqlite');
        if (config('database.default') !== 'sqlite' || $connection->getDriverName() !== 'sqlite' || $connection->getDatabaseName() !== ':memory:') {
            throw new \RuntimeException('Scenario interface tests require SQLite in memory.');
        }
        $this->withoutVite();
        $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true])->assertExitCode(0);
        $this->actingAs(User::factory()->create());
    }

    public function test_page_navigation_and_empty_scenarios_render_without_fictitious_parameters(): void
    {
        $this->get(route('scenarios'))->assertOk()->assertSee('Cenários')->assertSee('Cenário pendente')->assertSee('não previsões');
        $this->get(route('products.index'))->assertOk()->assertSee(route('scenarios'));
        Livewire::test('pages::scenarios')->assertSet('rows.0.sale_price', '')->assertSet('rows.0.quantity', '')
            ->assertSet('rows.0.fixed_fee', '')->assertSet('rows.0.seller_shipping', '');
    }

    public function test_rows_can_be_added_duplicated_and_removed_without_persistence(): void
    {
        Livewire::test('pages::scenarios')->set('rows.0.name', 'Hipótese A')
            ->call('duplicateScenario', 0)->assertSet('rows.2.name', 'Cópia de Hipótese A')
            ->call('removeScenario', 0)->assertSet('rows.0.name', 'Cenário 2')
            ->call('removeScenario', 1)->call('removeScenario', 0)->assertSet('reference_key', '')
            ->call('addScenario')->assertSet('rows.0.sale_price', '');
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('supply_costs', 0);
    }

    public function test_comparison_reacts_to_values_quantities_and_cost_sensitivity(): void
    {
        $product = $this->product();
        $supply = $product->components()->first()->supply;
        $component = $this->completed($product)
            ->assertSee('R$ 55,00')->assertSee('R$ 5.500,00')
            ->set('rows.1.channel', 'mercado_livre')->set('rows.1.sale_price', '100')
            ->set('rows.1.commission_percent', '20')->set('rows.1.fixed_fee', '5')
            ->set('rows.1.seller_shipping', '8')->set('rows.1.other_variable_expenses', '2')
            ->set('rows.1.quantity', '100')->assertSee('123 unidades')->assertSee('23 adicionais')
            ->set('rows.1.cost_mode', 'alternative')->assertSee('Informe pelo menos um custo alternativo')
            ->set('rows.1.cost_overrides.'.$supply->id, '30')->assertSee('Custos alternativos hipotéticos')
            ->assertSee('R$ 35,00')
            ->set('rows.1.quantity', '0')->assertSee('0 unidade(s)')
            ->set('rows.0.sale_price', '10')->assertSee('Contribuição negativa')
            ->assertSee('mais vendas aumentam a perda');

        $component->set('rows.1.seller_shipping', '')->assertSee('Cenário pendente');
        $this->assertSame('20.0000', $supply->fresh()->currentCost->unit_cost);
        $this->assertDatabaseCount('supply_costs', 1);
    }

    public function test_profile_selection_preserves_warnings_and_unknown_fixed_fee(): void
    {
        $this->seed(UserProvidedTariffSeeder::class);
        $profile = TariffProfile::query()->where('listing_type', 'Clássico')->firstOrFail();
        Livewire::test('pages::scenarios')->set('rows.0.channel', 'mercado_livre')
            ->set('rows.0.seller_shipping', '8')->set('rows.0.tariff_profile_id', (string) $profile->id)
            ->assertSet('rows.0.commission_percent', '14,0000')->assertSet('rows.0.fixed_fee', '')
            ->assertSet('rows.0.seller_shipping', '8')->assertSee('Estimado')->assertSee('Condições não verificadas')
            ->assertSee('Tarifa fixa desconhecida')->set('rows.0.commission_percent', '15');
        $this->assertSame('14.0000', $profile->fresh()->commission_percent);
        $this->assertNull($profile->fresh()->fixed_fee);
        $this->assertDatabaseCount('tariff_revisions', 3);
    }

    public function test_product_change_resets_cost_overrides_and_invalid_profile_blocks_comparison(): void
    {
        $first = $this->product();
        $second = Product::factory()->create();
        $supply = $first->components()->first()->supply;
        $component = $this->completed($first)->set('rows.0.cost_mode', 'alternative')
            ->set('rows.0.cost_overrides.'.$supply->id, '30')
            ->set('product_id', (string) $second->id)->assertSet('rows.0.cost_mode', 'current')
            ->assertSet('rows.0.cost_overrides', [])->assertSee('Produto sem composição');
        $component->set('product_id', (string) $first->id)->set('rows.0.tariff_profile_id', '999999')
            ->assertSee('Cenário pendente')->assertDontSee('R$ 55,00');
    }

    private function product(): Product
    {
        $product = Product::factory()->create();
        $supply = app(SupplyCatalog::class)->create(['name' => 'Insumo de teste', 'unit' => 'kg', 'unit_cost' => '20']);
        app(ProductComposition::class)->replace($product, [['supply_id' => $supply->id, 'quantity' => '1']]);

        return $product;
    }

    private function completed(Product $product): Testable
    {
        return Livewire::test('pages::scenarios')->set('product_id', (string) $product->id)
            ->set('rows.0.channel', 'mercado_livre')->set('rows.0.sale_price', '100')
            ->set('rows.0.commission_percent', '10')->set('rows.0.fixed_fee', '5')
            ->set('rows.0.seller_shipping', '8')->set('rows.0.other_variable_expenses', '2')
            ->set('rows.0.quantity', '100');
    }
}
