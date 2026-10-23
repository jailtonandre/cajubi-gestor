<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Supply;
use App\Models\User;
use App\Services\ProductComposition;
use App\Services\SupplyCatalog;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class SimulatorInterfaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $connection = DB::connection('sqlite');
        if (config('database.default') !== 'sqlite' || $connection->getDriverName() !== 'sqlite' || $connection->getDatabaseName() !== ':memory:') {
            throw new \RuntimeException('Simulator tests require SQLite in memory.');
        }
        $this->withoutVite();
        $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true])->assertExitCode(0);
        $this->actingAs(User::factory()->create());
    }

    public function test_simulator_page_and_navigation_render_without_assumed_values(): void
    {
        $this->get(route('simulator'))->assertOk()->assertSee('Simulador de preços')->assertSee('Aguardando informações')->assertSee('Nenhum produto cadastrado');
        $this->get(route('products.index'))->assertOk()->assertSee(route('simulator'));
        Livewire::test('pages::simulator')
            ->assertSet('channel', '')
            ->assertSet('commission_percent', '')
            ->assertSet('fixed_fee', '')
            ->assertSet('seller_shipping', '')
            ->assertSet('other_variable_expenses', '');
    }

    public function test_results_update_as_values_change_and_disappear_when_information_is_missing(): void
    {
        $product = $this->productWithCost();
        $component = $this->completeSimulation($product)
            ->assertSee('R$ 55,00')
            ->assertSee('55,00%')
            ->assertSee('R$ 85,00')
            ->assertSee('Ver composição financeira detalhada');

        $component->set('sale_price', '20')
            ->assertSee('R$ -17,00')
            ->assertSee('-85,00%')
            ->assertSee('Esta venda não cobre');

        $component->set('seller_shipping', '')
            ->assertSee('Aguardando informações')
            ->assertDontSee('R$ -17,00')
            ->assertSee('Informe frete do vendedor');

        $this->assertDatabaseCount('supply_costs', 1);
        $this->assertDatabaseCount('products', 1);
    }

    public function test_channel_change_clears_expenses_and_requires_explicit_values_again(): void
    {
        $this->completeSimulation($this->productWithCost())
            ->set('channel', 'shopee')
            ->assertSet('commission_percent', '')
            ->assertSet('fixed_fee', '')
            ->assertSet('seller_shipping', '')
            ->assertSet('other_variable_expenses', '')
            ->assertSee('Aguardando informações')
            ->assertDontSee('R$ 55,00');
    }

    public function test_brazilian_decimals_and_zero_expenses_work_reactively(): void
    {
        $product = $this->productWithCost();
        $this->completeSimulation($product)
            ->set('sale_price', '100,50')
            ->set('commission_percent', '0')
            ->set('fixed_fee', '0')
            ->set('seller_shipping', '0')
            ->set('other_variable_expenses', '0')
            ->assertSee('R$ 80,50')
            ->assertSee('80,10%');

        $this->assertSame('20.0000', $product->components()->first()->supply->currentCost->unit_cost);
    }

    public function test_negative_input_hides_contribution_and_shows_validation_feedback(): void
    {
        $this->completeSimulation($this->productWithCost())
            ->set('fixed_fee', '-1')
            ->assertSee('Aguardando informações')
            ->assertSee('valor não negativo')
            ->assertDontSee('R$ 55,00');
    }

    public function test_missing_product_cost_is_shown_and_cannot_generate_a_margin(): void
    {
        $product = Product::factory()->create();
        $component = $this->completeSimulation($product)
            ->assertSee('Produto sem composição de custos.')
            ->assertSee('Aguardando informações')
            ->assertSee('Revisar composição')
            ->assertDontSee('55,00%');

        $supply = Supply::factory()->create();
        app(ProductComposition::class)->replace($product, [['supply_id' => $supply->id, 'quantity' => '1']]);
        $component->set('sale_price', '101')->assertSee('Insumo sem custo cadastrado.')->assertSee('Aguardando informações');
    }

    public function test_selecting_another_product_recalculates_its_cost(): void
    {
        $first = $this->productWithCost();
        $second = Product::factory()->create();
        $supply = app(SupplyCatalog::class)->create(['name' => 'Outro insumo de teste', 'unit' => 'un', 'unit_cost' => '30']);
        app(ProductComposition::class)->replace($second, [['supply_id' => $supply->id, 'quantity' => '1']]);
        $this->completeSimulation($first)
            ->set('product_id', (string) $second->id)
            ->assertSee('R$ 45,00')
            ->assertSee('45,00%');
    }

    public function test_initial_state_has_simple_guidance_without_a_list_of_pending_fields(): void
    {
        Livewire::test('pages::simulator')
            ->assertSee('Aguardando informações')
            ->assertSee('Simular preço')
            ->assertSee('Encontrar preço de venda')
            ->assertDontSee('Para concluir a simulação')
            ->set('mode', 'find_price')
            ->assertSee('Contribuição desejada por unidade')
            ->assertDontSee('Para concluir a simulação');
    }

    public function test_inverse_mode_reacts_to_target_and_preserves_original_mode_values(): void
    {
        $component = $this->completeSimulation($this->productWithCost())
            ->set('mode', 'find_price')
            ->assertSee('Aguardando informações')
            ->set('desired_contribution', '55')
            ->assertSee('Preço de venda sugerido')
            ->assertSee('R$ 100,00')
            ->assertSee('55,00%')
            ->assertSee('Não é garantia de competitividade');

        $component->set('desired_contribution', '10')
            ->set('commission_percent', '12')
            ->assertSee('R$ 51,14')
            ->set('mode', 'simulate')
            ->assertSet('sale_price', '100')
            ->assertSee('R$ 53,00');

        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('supply_costs', 1);
    }

    public function test_inverse_invalid_commission_or_missing_expense_hides_suggested_price(): void
    {
        $this->completeSimulation($this->productWithCost())
            ->set('mode', 'find_price')
            ->set('desired_contribution', '55')
            ->assertSee('R$ 100,00')
            ->set('commission_percent', '100')
            ->assertSee('Aguardando informações')
            ->assertSee('a comissão deve ser menor que 100%')
            ->assertDontSee('R$ 100,00')
            ->set('commission_percent', '10')
            ->set('seller_shipping', '')
            ->assertSee('Aguardando informações')
            ->assertDontSee('R$ 100,00');
    }

    public function test_invalid_mode_is_rejected_instead_of_calculating(): void
    {
        $this->completeSimulation($this->productWithCost())
            ->set('mode', 'unknown')
            ->assertSee('Selecione um modo de simulação válido.')
            ->assertSee('Aguardando informações')
            ->assertDontSee('R$ 55,00');
    }

    private function productWithCost(): Product
    {
        $product = Product::factory()->create();
        $supply = app(SupplyCatalog::class)->create(['name' => 'Insumo sintético', 'unit' => 'un', 'unit_cost' => '20']);
        app(ProductComposition::class)->replace($product, [['supply_id' => $supply->id, 'quantity' => '1']]);

        return $product;
    }

    private function completeSimulation(Product $product): Testable
    {
        return Livewire::test('pages::simulator')
            ->set('product_id', (string) $product->id)
            ->set('channel', 'mercado_livre')
            ->set('sale_price', '100')
            ->set('commission_percent', '10')
            ->set('fixed_fee', '5')
            ->set('seller_shipping', '8')
            ->set('other_variable_expenses', '2');
    }
}
