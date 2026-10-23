<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Supply;
use App\Models\User;
use App\Services\ProductComposition;
use App\Services\ProductCostCalculator;
use App\Services\SalesLedger;
use App\Services\SupplyCatalog;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SupplyEditingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            throw new \RuntimeException('Supply editing tests require SQLite in memory.');
        }
        $this->withoutVite();
        $this->artisan('migrate', ['--force' => true, '--no-interaction' => true])->assertExitCode(0);
        $this->actingAs(User::factory()->create());
    }

    public function test_existing_supply_can_be_reopened_and_edited_without_adding_a_price(): void
    {
        $supply = app(SupplyCatalog::class)->create(['name' => 'Castanha', 'description' => 'Descrição original', 'unit' => 'kg', 'unit_cost' => '10.2500']);
        $cost = $supply->currentCost->attributesToArray();
        $this->get(route('supplies.index'))->assertOk()->assertSee('Editar insumo')->assertSee('href="'.route('supplies.show', $supply).'"', false);
        $this->get(route('supplies.show', $supply))->assertOk()->assertSee('Nome do insumo')->assertSee('Descrição')->assertSee('Salvar alterações')->assertSee('Deixe em branco para manter o custo atual.');

        Livewire::test('pages::supplies.form', ['supply' => $supply])
            ->assertSet('name', 'Castanha')->assertSet('description', 'Descrição original')->assertSet('unit_cost', '')
            ->set('name', ' Castanha natural ')->set('description', 'Fornecedor e referência revisados')
            ->call('save')->assertHasNoErrors()->assertSee('Castanha natural');

        $this->assertDatabaseHas('supplies', ['id' => $supply->id, 'name' => 'Castanha natural', 'description' => 'Fornecedor e referência revisados', 'unit' => 'kg']);
        $this->assertDatabaseCount('supply_costs', 1);
        $this->assertSame($cost, $supply->fresh()->currentCost->attributesToArray());
        Livewire::test('pages::supplies.form', ['supply' => $supply->fresh()])->assertSet('description', 'Fornecedor e referência revisados');
    }

    public function test_new_cost_updates_products_and_kits_without_changing_compositions_or_historical_sales(): void
    {
        $this->travelTo('2026-10-22 12:00:00');
        $supply = app(SupplyCatalog::class)->create(['name' => 'Castanha', 'unit' => 'kg', 'unit_cost' => '10.2500']);
        $product = Product::factory()->create();
        $kit = Product::factory()->create();
        app(ProductComposition::class)->replace($product, [['supply_id' => $supply->id, 'quantity' => '1.5']]);
        app(ProductComposition::class)->replace($kit, [['component_product_id' => $product->id, 'quantity' => '2']]);
        $compositionBefore = DB::table('product_components')->orderBy('id')->get()->toJson();
        $sale = app(SalesLedger::class)->save(null, ['marketplace' => 'shopee', 'order_reference' => 'HISTORICO-INSUMO',
            'sold_on' => '2026-10-21', 'product_id' => $product->id, 'quantity' => 2, 'gross_amount' => '100.00',
            'seller_discount' => '0.00', 'marketplace_fees' => '0.00', 'seller_freight' => '0.00', 'other_adjustment' => '0.00',
            'expedition_cost' => '0.00', 'paid_labor' => '0.00']);
        $saleBefore = $sale->attributesToArray();
        $revisionsBefore = $sale->revisions()->get()->toJson();

        Livewire::test('pages::supplies.form', ['supply' => $supply])->set('name', 'Castanha revisada')
            ->set('description', 'Novo fornecedor')->set('unit_cost', '12,0000')->call('save')->assertHasNoErrors()
            ->assertSet('unit_cost', '')->assertSee('R$ 12,00')->assertSee('R$ 10,25');

        $this->assertSame(['10.2500', '12.0000'], $supply->costs()->orderBy('id')->get()->pluck('unit_cost')->all());
        $this->assertSame('18.0000', app(ProductCostCalculator::class)->calculate($product));
        $this->assertSame('36.0000', app(ProductCostCalculator::class)->calculate($kit));
        $this->assertSame($compositionBefore, DB::table('product_components')->orderBy('id')->get()->toJson());
        $this->assertSame($saleBefore, $sale->fresh()->attributesToArray());
        $this->assertSame($revisionsBefore, $sale->revisions()->get()->toJson());
        $this->assertSame('30.75', app(SalesLedger::class)->figures($sale->fresh())['cost']);
        $this->get(route('products.edit', $product))->assertOk()->assertSee('R$ 18,00');
    }

    public function test_unit_change_is_rejected_and_rolls_back_metadata_and_cost_together(): void
    {
        $supply = app(SupplyCatalog::class)->create(['name' => 'Original', 'description' => 'Original', 'unit' => 'kg', 'unit_cost' => '5.0000']);
        $product = Product::factory()->create();
        app(ProductComposition::class)->replace($product, [['supply_id' => $supply->id, 'quantity' => '0.5']]);

        Livewire::test('pages::supplies.form', ['supply' => $supply])->set('name', 'Alterado')
            ->set('description', 'Alterada')->set('unit', 'g')->set('unit_cost', '10,00')
            ->call('save')->assertHasErrors(['unit'])->assertSee('A unidade de medida não pode ser alterada');

        $this->assertDatabaseHas('supplies', ['id' => $supply->id, 'name' => 'Original', 'description' => 'Original', 'unit' => 'kg']);
        $this->assertDatabaseCount('supply_costs', 1);
        $this->assertSame('2.5000', app(ProductCostCalculator::class)->calculate($product));
    }

    #[DataProvider('invalidEdits')]
    public function test_invalid_edit_preserves_metadata_and_price_history(string $field, string $value): void
    {
        $supply = app(SupplyCatalog::class)->create(['name' => 'Original', 'description' => 'Original', 'unit' => 'kg', 'unit_cost' => '5.0000']);
        $before = $supply->fresh()->attributesToArray();
        $page = Livewire::test('pages::supplies.form', ['supply' => $supply])->set('name', 'Novo nome')->set('unit_cost', '10,00');

        $page->set($field, $value)->call('save')->assertHasErrors([$field]);

        $this->assertSame($before, $supply->fresh()->attributesToArray());
        $this->assertDatabaseCount('supply_costs', 1);
        $this->assertSame('5.0000', $supply->fresh()->currentCost->unit_cost);
    }

    public static function invalidEdits(): array
    {
        return ['missing name' => ['name', ' '], 'long name' => ['name', str_repeat('a', 256)],
            'long description' => ['description', str_repeat('a', 5001)], 'negative cost' => ['unit_cost', '-1,00'],
            'excess precision' => ['unit_cost', '0,00001']];
    }

    public function test_description_can_be_created_cleared_and_rendered_safely_and_zero_cost_is_explicit(): void
    {
        Livewire::test('pages::supplies.form')->set('name', 'Insumo novo')->set('description', '<script>alert(1)</script>')
            ->set('unit_cost', '1,2345')->call('save')->assertHasNoErrors();
        $supply = Supply::query()->sole();
        $this->assertSame('<script>alert(1)</script>', $supply->description);
        Livewire::test('pages::supplies.form', ['supply' => $supply])->assertSet('description', '<script>alert(1)</script>');
        $this->get(route('supplies.show', $supply))->assertOk()->assertDontSee('<script>alert(1)</script>', false);

        Livewire::test('pages::supplies.form', ['supply' => $supply])->set('description', '')->set('unit_cost', '0,0000')->call('save')->assertHasNoErrors();

        $this->assertNull($supply->fresh()->description);
        $this->assertSame('0.0000', $supply->fresh()->currentCost->unit_cost);
        $this->assertDatabaseCount('supply_costs', 2);
    }
}
