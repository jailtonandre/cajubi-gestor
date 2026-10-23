<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Supply;
use App\Models\User;
use App\Services\DecimalPresentation;
use App\Services\ProductComposition;
use App\Services\SupplyCatalog;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class CatalogInterfaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $connection = DB::connection('sqlite');
        if (config('database.default') !== 'sqlite' || $connection->getDriverName() !== 'sqlite' || $connection->getDatabaseName() !== ':memory:') {
            throw new \RuntimeException('Interface tests require SQLite in memory.');
        }

        $this->withoutVite();
        $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true])->assertExitCode(0);
        $this->actingAs(User::factory()->create());
    }

    public function test_pages_render_in_portuguese_and_missing_records_return_404(): void
    {
        $this->get('/')->assertRedirect(route('commercial-dashboard'));
        $this->get(route('products.index'))->assertOk()->assertSee('Seu catálogo começa aqui')->assertSee('Novo produto');
        $this->get(route('products.create'))->assertOk()->assertSee('Composição do produto');
        $this->get(route('supplies.index'))->assertOk()->assertSee('Comece pela base dos seus custos');
        $this->get(route('supplies.create'))->assertOk()->assertSee('Custo unitário');
        $this->get('/produtos/999999/editar')->assertNotFound();
        $this->get('/insumos/999999')->assertNotFound();
    }

    public function test_user_can_create_supply_product_and_kit_and_update_cost_history(): void
    {
        Livewire::test('pages::supplies.form')
            ->set('name', 'Insumo de teste')
            ->set('unit', 'kg')
            ->set('unit_cost', '10,2500')
            ->call('save')
            ->assertHasNoErrors();
        $supply = Supply::query()->sole();
        $this->assertSame('10.2500', $supply->currentCost->unit_cost);

        Livewire::test('pages::products.form')
            ->set('name', 'Produto de teste')
            ->set('sku', ' teste-1 ')
            ->set('net_weight_grams', '1.000,000')
            ->call('addComponent')
            ->set('components.0.target', 'supply:'.$supply->id)
            ->set('components.0.quantity', '1,5')
            ->call('save')
            ->assertHasNoErrors();
        $product = Product::query()->sole();
        $this->assertDatabaseHas('products', ['id' => $product->id, 'sku' => 'TESTE-1', 'net_weight_grams' => '1000']);
        $this->assertDatabaseHas('product_components', ['product_id' => $product->id, 'supply_id' => $supply->id, 'quantity' => '1.5']);
        $this->get(route('products.edit', $product))->assertOk()->assertSee('R$ 15,38');

        Livewire::test('pages::products.form')
            ->set('name', 'Kit de teste')
            ->set('sku', 'KIT-TESTE')
            ->set('net_weight_grams', '2000')
            ->call('addComponent')
            ->set('components.0.target', 'product:'.$product->id)
            ->set('components.0.quantity', '2')
            ->call('save')
            ->assertHasNoErrors();
        $kit = Product::query()->where('sku', 'KIT-TESTE')->sole();
        $this->get(route('products.edit', $kit))->assertOk()->assertSee('R$ 30,75');

        Livewire::test('pages::supplies.form', ['supply' => $supply])
            ->set('unit_cost', '12,0000')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('R$ 12,00')
            ->assertSee('R$ 10,25')
            ->assertSee('O histórico anterior foi preservado');
        $this->assertSame(['10.2500', '12.0000'], $supply->costs()->orderBy('id')->get()->pluck('unit_cost')->all());
        $this->get(route('products.index'))->assertOk()->assertSee('R$ 36,00');
    }

    public function test_product_edits_preserve_sku_and_replace_composition(): void
    {
        $product = Product::factory()->create();
        $first = app(SupplyCatalog::class)->create(['name' => 'Primeiro teste', 'unit' => 'kg', 'unit_cost' => '5']);
        $second = app(SupplyCatalog::class)->create(['name' => 'Segundo teste', 'unit' => 'un', 'unit_cost' => '2']);
        app(ProductComposition::class)->replace($product, [['supply_id' => $first->id, 'quantity' => '1']]);

        Livewire::test('pages::products.form', ['product' => $product])
            ->set('name', 'Nome alterado')
            ->set('status', 'inactive')
            ->set('components.0.target', 'supply:'.$second->id)
            ->set('components.0.quantity', '3')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('products.edit', $product));

        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'Nome alterado', 'sku' => mb_strtoupper($product->sku), 'status' => 'inactive']);
        $this->assertDatabaseMissing('product_components', ['product_id' => $product->id, 'supply_id' => $first->id]);
        $this->assertDatabaseHas('product_components', ['product_id' => $product->id, 'supply_id' => $second->id, 'quantity' => '3']);
    }

    public function test_product_and_composition_are_saved_atomically_on_validation_failure(): void
    {
        $product = Product::factory()->create();
        $supply = Supply::factory()->create();
        app(ProductComposition::class)->replace($product, [['supply_id' => $supply->id, 'quantity' => '1']]);

        Livewire::test('pages::products.form', ['product' => $product])
            ->set('name', 'Não deve persistir')
            ->set('components.0.quantity', '-1')
            ->call('save')
            ->assertHasErrors(['components.0.quantity'])
            ->assertSee('Revise as informações para continuar.');

        $this->assertSame($product->name, $product->fresh()->name);
        $this->assertDatabaseHas('product_components', ['product_id' => $product->id, 'quantity' => '1']);
    }

    public function test_duplicate_sku_has_portuguese_feedback_and_creates_no_product(): void
    {
        $product = Product::factory()->create(['sku' => 'DUPLICADO']);
        Livewire::test('pages::products.form')
            ->set('name', 'Duplicado')
            ->set('sku', 'duplicado')
            ->set('net_weight_grams', '500')
            ->call('save')
            ->assertHasErrors(['sku'])
            ->assertSee('Este SKU já está cadastrado.');
        $this->assertDatabaseCount('products', 1);
    }

    public function test_cycle_is_rejected_without_changing_existing_product(): void
    {
        $product = Product::factory()->create();
        $kit = Product::factory()->create();
        app(ProductComposition::class)->replace($kit, [['component_product_id' => $product->id, 'quantity' => '2']]);

        Livewire::test('pages::products.form', ['product' => $product])
            ->call('addComponent')
            ->set('components.0.target', 'product:'.$kit->id)
            ->set('components.0.quantity', '1')
            ->call('save')
            ->assertHasErrors(['components.0'])
            ->assertSee('A composição não pode conter ciclos.');
        $this->assertDatabaseCount('product_components', 1);
    }

    public function test_invalid_supply_cost_keeps_current_cost_and_history(): void
    {
        $supply = app(SupplyCatalog::class)->create(['name' => 'Teste de custo', 'unit' => 'kg', 'unit_cost' => '1']);
        Livewire::test('pages::supplies.form', ['supply' => $supply])
            ->set('unit_cost', '-1,00')
            ->call('save')
            ->assertHasErrors(['unit_cost'])
            ->assertSee('O campo custo unitário tem formato inválido.');
        $this->assertDatabaseCount('supply_costs', 1);
        $this->assertSame('1.0000', $supply->fresh()->currentCost->unit_cost);
    }

    public function test_missing_cost_and_missing_composition_have_clear_warnings(): void
    {
        $supply = Supply::factory()->create();
        $product = Product::factory()->create();
        $this->get(route('products.index'))->assertOk()->assertSee('Custo pendente')->assertSee('Produto sem composição de custos.');
        app(ProductComposition::class)->replace($product, [['supply_id' => $supply->id, 'quantity' => '1']]);
        $this->get(route('products.edit', $product))->assertOk()->assertSee('Insumo sem custo cadastrado.');
        $this->get(route('supplies.show', $supply))->assertOk()->assertSee('Este insumo ainda não tem custo.');
    }

    public function test_search_matches_name_or_sku_and_escapes_product_names(): void
    {
        $product = Product::factory()->create(['name' => '<script>alert(1)</script>', 'sku' => 'LOCALIZAR']);
        Product::factory()->create(['name' => 'Outro produto', 'sku' => 'OUTRO']);
        Livewire::test('pages::products.index')
            ->set('search', 'localizar')
            ->assertSee($product->name)
            ->assertDontSee('<script>alert(1)</script>', escape: false)
            ->assertDontSee('Outro produto');
        Livewire::test('pages::products.index')->set('search', 'inexistente')->assertSee('Nenhum produto encontrado');
        Supply::factory()->create(['name' => 'Embalagem teste']);
        Livewire::test('pages::supplies.index')->set('search', 'embalagem')->assertSee('Embalagem teste');
    }

    public function test_new_product_can_be_saved_without_composition_and_rows_can_be_removed(): void
    {
        Livewire::test('pages::products.form')
            ->set('name', 'Produto incompleto')
            ->set('sku', 'INCOMPLETO')
            ->set('net_weight_grams', '500')
            ->call('addComponent')
            ->call('removeComponent', 0)
            ->assertSet('components', [])
            ->call('save')
            ->assertHasNoErrors();
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_components', 0);
    }

    public function test_empty_required_fields_and_invalid_target_are_rejected(): void
    {
        Livewire::test('pages::supplies.form')->call('save')->assertHasErrors(['name', 'unit_cost'])->assertSee('O campo nome é obrigatório.');
        Livewire::test('pages::products.form')
            ->set('name', 'Produto teste')
            ->set('sku', 'TARGET')
            ->set('net_weight_grams', '500')
            ->call('addComponent')
            ->set('components.0.target', 'supply:abc')
            ->call('save')
            ->assertHasErrors(['components.0.target']);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('supplies', 0);
    }

    public function test_money_formatting_and_brazilian_input_keep_decimal_precision(): void
    {
        $this->assertSame('9.999.999.999,9999', DecimalPresentation::format('9999999999.9999'));
        $this->assertSame('0,0001', DecimalPresentation::format('0.0001'));
        $this->assertSame('1234.5678', DecimalPresentation::input('1.234,5678'));
        $this->assertSame('0.0001', DecimalPresentation::input('0,0001'));
    }

    public function test_weights_are_displayed_in_grams_or_kilograms_without_redundant_zeros(): void
    {
        $this->assertSame('500 g', DecimalPresentation::weight('500.000'));
        $this->assertSame('1 kg', DecimalPresentation::weight('1000.000'));
        $this->assertSame('2 kg', DecimalPresentation::weight('2000.000'));
        $this->assertSame('1,25 kg', DecimalPresentation::weight('1250.000'));
        $this->assertSame('999,999 g', DecimalPresentation::weight('999.999'));
        $this->assertSame('1,000001 kg', DecimalPresentation::weight('1000.001'));

        $product = Product::factory()->create(['net_weight_grams' => '1000.000']);
        $this->get(route('products.index'))->assertOk()->assertSee('1 kg')->assertDontSee('1.000,000 g');
        $this->assertSame('1000.000', $product->fresh()->net_weight_grams);
    }

    public function test_currency_display_uses_two_decimals_without_changing_stored_costs(): void
    {
        $supply = app(SupplyCatalog::class)->create(['name' => 'Teste de apresentação', 'unit' => 'kg', 'unit_cost' => '50.0050']);
        $this->get(route('supplies.show', $supply))->assertOk()->assertSee('R$ 50,01')->assertDontSee('R$ 50,0050');
        $this->assertSame('50.0050', $supply->fresh()->currentCost->unit_cost);
        $this->assertSame('0,00', DecimalPresentation::format('0.0001', 2));
    }
}
