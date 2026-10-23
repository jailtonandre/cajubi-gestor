<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Supply;
use App\Models\User;
use App\Services\CatalogImage;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CatalogImagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            throw new RuntimeException('Image tests require SQLite in memory.');
        }
        $this->withoutVite();
        $this->artisan('migrate', ['--force' => true, '--no-interaction' => true])->assertExitCode(0);
        $this->actingAs(User::factory()->create());
    }

    public static function catalogs(): array
    {
        return ['products' => ['products', Product::class], 'supplies' => ['supplies', Supply::class]];
    }

    private function createPage(string $catalog): Testable
    {
        $page = Livewire::test('pages::'.$catalog.'.form')->set('name', 'Imagem real');

        return $catalog === 'products'
            ? $page->set('sku', 'FOTO')->set('net_weight_grams', '500')
            : $page->set('unit_cost', '10,00');
    }

    public static function imageFormats(): array
    {
        $cases = [];
        foreach (self::catalogs() as [$catalog, $model]) {
            foreach (['jpg', 'png', 'webp'] as $extension) {
                $cases[$catalog.' '.$extension] = [$catalog, $model, $extension];
            }
        }

        return $cases;
    }

    #[DataProvider('imageFormats')]
    public function test_upload_is_stored_and_shown_in_listing_and_edit_form(string $catalog, string $model, string $extension): void
    {
        Storage::fake('public');
        $this->createPage($catalog)->set('image', UploadedFile::fake()->image('foto.'.$extension))
            ->assertSee('Pré-visualização da imagem selecionada')->call('save')->assertHasNoErrors();
        $record = $model::query()->sole();
        $this->assertStringStartsWith($catalog.'/', $record->image_path);
        $this->assertNotSame($catalog.'/foto.'.$extension, $record->image_path);
        Storage::disk('public')->assertExists($record->image_path);
        $this->get(route($catalog.'.index'))->assertOk()->assertSee(Storage::disk('public')->url($record->image_path), false);
        $this->get(route($catalog === 'products' ? 'products.edit' : 'supplies.show', $record))
            ->assertOk()->assertSee('Imagem cadastrada de Imagem real');
    }

    #[DataProvider('catalogs')]
    public function test_creation_and_editing_without_image_remain_optional(string $catalog, string $model): void
    {
        Storage::fake('public');
        $this->createPage($catalog)->call('save')->assertHasNoErrors();
        $record = $model::query()->sole();
        $this->assertNull($record->image_path);
        Livewire::test('pages::'.$catalog.'.form', [$catalog === 'products' ? 'product' : 'supply' => $record])
            ->set('name', 'Editado sem imagem')->call('save')->assertHasNoErrors();
        $this->assertSame('Editado sem imagem', $record->fresh()->name);
        $this->assertNull($record->fresh()->image_path);
        Storage::disk('public')->assertDirectoryEmpty($catalog);
    }

    public static function invalidImages(): array
    {
        $cases = [];
        foreach (['products', 'supplies'] as $catalog) {
            foreach (['text', 'svg', 'gif', 'large'] as $kind) {
                $cases[$catalog.' '.$kind] = [$catalog, $kind];
            }
        }

        return $cases;
    }

    #[DataProvider('invalidImages')]
    public function test_invalid_uploads_are_rejected_without_persisting_records(string $catalog, string $kind): void
    {
        Storage::fake('public');
        $file = match ($kind) {
            'text' => UploadedFile::fake()->create('foto.jpg', 10, 'text/plain'),
            'svg' => UploadedFile::fake()->createWithContent('foto.svg', '<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10"/></svg>'),
            'gif' => UploadedFile::fake()->image('foto.gif'),
            'large' => UploadedFile::fake()->image('foto.jpg')->size(2049),
        };
        $this->createPage($catalog)->set('image', $file)->call('save')->assertHasErrors(['image']);
        $this->assertDatabaseCount($catalog, 0);
        Storage::disk('public')->assertDirectoryEmpty($catalog);
    }

    #[DataProvider('catalogs')]
    public function test_replacement_and_explicit_removal_clean_up_only_after_success(string $catalog, string $model): void
    {
        Storage::fake('public');
        $record = $model::factory()->create();
        $record->forceFill(['image_path' => $catalog.'/previous.jpg'])->save();
        Storage::disk('public')->put($record->image_path, 'previous');
        $parameters = [$catalog === 'products' ? 'product' : 'supply' => $record];
        Livewire::test('pages::'.$catalog.'.form', $parameters)->call('save')->assertHasNoErrors();
        Storage::disk('public')->assertExists($catalog.'/previous.jpg');
        $this->assertSame($catalog.'/previous.jpg', $record->fresh()->image_path);

        Livewire::test('pages::'.$catalog.'.form', $parameters)
            ->set('image', UploadedFile::fake()->image('replacement.jpg'))->call('save')->assertHasNoErrors();
        $replacement = $record->fresh()->image_path;
        Storage::disk('public')->assertExists($replacement);
        Storage::disk('public')->assertMissing($catalog.'/previous.jpg');

        Livewire::test('pages::'.$catalog.'.form', [$catalog === 'products' ? 'product' : 'supply' => $record->fresh()])
            ->set('removeImage', true)->call('save')->assertHasNoErrors();
        $this->assertNull($record->fresh()->image_path);
        Storage::disk('public')->assertMissing($replacement);
    }

    #[DataProvider('catalogs')]
    public function test_validation_failure_preserves_existing_image_even_when_removal_is_requested(string $catalog, string $model): void
    {
        Storage::fake('public');
        $record = $model::factory()->create();
        $record->forceFill(['image_path' => $catalog.'/previous.jpg'])->save();
        Storage::disk('public')->put($record->image_path, 'previous');
        Livewire::test('pages::'.$catalog.'.form', [$catalog === 'products' ? 'product' : 'supply' => $record])
            ->set('name', '')->set('removeImage', true)->set('image', UploadedFile::fake()->image('new.png'))
            ->call('save')->assertHasErrors(['name']);
        $this->assertSame($catalog.'/previous.jpg', $record->fresh()->image_path);
        Storage::disk('public')->assertExists($catalog.'/previous.jpg');
        $this->assertCount(1, Storage::disk('public')->files($catalog));
    }

    #[DataProvider('catalogs')]
    public function test_database_failure_during_image_save_rolls_back_metadata_and_removes_new_file(string $catalog, string $model): void
    {
        Storage::fake('public');
        $record = $model::factory()->create();
        $record->forceFill(['image_path' => $catalog.'/previous.jpg'])->save();
        Storage::disk('public')->put($record->image_path, 'previous');
        $connection = $record->getConnection();
        $connection->statement('CREATE TRIGGER reject_image BEFORE UPDATE OF image_path ON '.$catalog." BEGIN SELECT RAISE(ABORT, 'image failure'); END");
        try {
            app(CatalogImage::class)->save($record, UploadedFile::fake()->image('new.png'), false, function () use ($record): Product|Supply {
                $record->name = 'Must roll back';
                $record->save();

                return $record;
            });
            $this->fail('Expected the database to reject the image update.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('image failure', $exception->getMessage());
        }
        $this->assertSame($catalog.'/previous.jpg', $record->fresh()->image_path);
        $this->assertNotSame('Must roll back', $record->fresh()->name);
        Storage::disk('public')->assertExists($catalog.'/previous.jpg');
        $this->assertCount(1, Storage::disk('public')->files($catalog));
    }

    #[DataProvider('catalogs')]
    public function test_record_deletion_removes_associated_image_after_commit_and_rollback_preserves_it(string $catalog, string $model): void
    {
        Storage::fake('public');
        $record = $model::factory()->create();
        $record->forceFill(['image_path' => $catalog.'/previous.jpg'])->save();
        Storage::disk('public')->put($record->image_path, 'previous');
        DB::beginTransaction();
        $record->delete();
        Storage::disk('public')->assertExists($catalog.'/previous.jpg');
        DB::rollBack();
        Storage::disk('public')->assertExists($catalog.'/previous.jpg');
        $model::query()->findOrFail($record->id)->delete();
        Storage::disk('public')->assertMissing($catalog.'/previous.jpg');
        $this->assertDatabaseMissing($catalog, ['id' => $record->id]);
    }

    public function test_cleanup_does_not_delete_shared_images_or_paths_outside_controlled_directory(): void
    {
        Storage::fake('public');
        $first = Product::factory()->create();
        $second = Product::factory()->create();
        foreach ([$first, $second] as $record) {
            $record->forceFill(['image_path' => 'products/shared.jpg'])->save();
        }
        Storage::disk('public')->put('products/shared.jpg', 'shared');
        $first->delete();
        Storage::disk('public')->assertExists('products/shared.jpg');
        $second->delete();
        Storage::disk('public')->assertMissing('products/shared.jpg');
        Storage::disk('public')->put('outside.jpg', 'outside');
        Storage::disk('public')->put('supplies/supply.jpg', 'other directory');
        foreach (['outside.jpg', 'products/../outside.jpg', 'products/../../outside.jpg', '/outside.jpg', 'supplies/supply.jpg'] as $path) {
            app(CatalogImage::class)->deleteUnused($path, 'products');
        }
        Storage::disk('public')->assertExists(['outside.jpg', 'supplies/supply.jpg']);
    }

    public function test_contextual_breadcrumbs_use_existing_links_and_identify_the_current_page(): void
    {
        $product = Product::factory()->create();
        $supply = Supply::factory()->create();
        $pages = [
            ['commercial-dashboard', [], 'Início', 'home', 'Painel Comercial'],
            ['products.index', [], 'Início', 'home', 'Produtos'],
            ['supplies.index', [], 'Início', 'home', 'Insumos'],
            ['products.create', [], 'Produtos', 'products.index', 'Novo produto'],
            ['products.edit', [$product], 'Produtos', 'products.index', 'Editar produto'],
            ['supplies.create', [], 'Insumos', 'supplies.index', 'Novo insumo'],
            ['supplies.show', [$supply], 'Insumos', 'supplies.index', 'Editar insumo'],
        ];
        foreach ($pages as [$page, $parameters, $parent, $parentRoute, $current]) {
            $response = $this->get(route($page, $parameters))->assertOk()->assertDontSee('Uso interno');
            $html = $response->getContent();
            preg_match('~<nav aria-label="Breadcrumb".*?</nav>~s', $html, $matches);
            $this->assertStringContainsString('href="'.route($parentRoute).'"', $matches[0]);
            $this->assertStringContainsString('>'.$parent.'</a>', $matches[0]);
            $this->assertStringContainsString('aria-current="page"', $matches[0]);
            $this->assertStringContainsString('>'.$current.'</li>', $matches[0]);
            $this->assertSame(1, substr_count($matches[0], '<a '));
        }
        $this->get(route('account.edit'))->assertOk()->assertSee('>Minha conta</li>', false);
    }
}
