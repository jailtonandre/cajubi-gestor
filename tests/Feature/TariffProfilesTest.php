<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\TariffProfile;
use App\Models\User;
use App\Services\ProductComposition;
use App\Services\SupplyCatalog;
use App\Services\TariffCatalog;
use Database\Seeders\UserProvidedTariffSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TariffProfilesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $connection = DB::connection('sqlite');
        if (config('database.default') !== 'sqlite' || $connection->getDriverName() !== 'sqlite' || $connection->getDatabaseName() !== ':memory:') {
            throw new \RuntimeException('Tariff tests require SQLite in memory.');
        }
        $this->withoutVite();
        $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true])->assertExitCode(0);
        $this->actingAs(User::factory()->create());
    }

    public function test_creation_editing_and_deactivation_preserve_complete_history(): void
    {
        $catalog = app(TariffCatalog::class);
        $profile = $catalog->save($this->data());
        $catalog->save(array_replace($this->data(), ['commission_percent' => '19', 'fixed_fee' => '0', 'source' => 'Nova fonte', 'confidence' => 'confirmed']), $profile);
        $catalog->setActive($profile, false);

        $snapshots = $profile->revisions()->orderBy('id')->get()->pluck('snapshot');
        $this->assertCount(3, $snapshots);
        $this->assertSame('14.0000', $snapshots[0]['commission_percent']);
        $this->assertNull($snapshots[0]['fixed_fee']);
        $this->assertSame('Fonte de teste', $snapshots[0]['source']);
        $this->assertSame('19.0000', $snapshots[1]['commission_percent']);
        $this->assertSame('0.0000', $snapshots[1]['fixed_fee']);
        $this->assertSame('confirmed', $snapshots[1]['confidence']);
        $this->assertFalse($snapshots[2]['is_active']);
        $this->assertFalse($profile->fresh()->is_active);
        $catalog->setActive($profile, true);
        $this->assertTrue($profile->fresh()->is_active);
        $this->assertDatabaseCount('tariff_revisions', 4);
    }

    public function test_unchanged_save_does_not_create_duplicate_history(): void
    {
        $catalog = app(TariffCatalog::class);
        $profile = $catalog->save($this->data());
        $catalog->save($this->data(), $profile);
        $this->assertDatabaseCount('tariff_revisions', 1);
    }

    public function test_user_references_are_idempotent_and_never_overwrite_manual_edits(): void
    {
        $this->travelTo(Carbon::parse('2026-10-19 12:00:00', 'America/Sao_Paulo'));
        $this->seed(UserProvidedTariffSeeder::class);
        $profiles = TariffProfile::query()->orderBy('id')->get();
        $this->assertSame(['14.0000', '19.0000', '0.0000'], $profiles->pluck('commission_percent')->all());
        foreach ($profiles as $profile) {
            $this->assertNull($profile->fixed_fee);
            $this->assertSame('estimated', $profile->confidence);
            $this->assertFalse($profile->conditions_verified);
            $this->assertSame('2026-10-19', $profile->observed_on->format('Y-m-d'));
            $this->assertStringContainsString('fornecida pelo usuário', $profile->source);
        }
        $catalog = app(TariffCatalog::class);
        $catalog->save(array_replace($this->data(), ['commission_percent' => '15']), $profiles[0]);
        $this->seed(UserProvidedTariffSeeder::class);
        $this->assertDatabaseCount('tariff_profiles', 3);
        $this->assertDatabaseCount('tariff_revisions', 4);
        $this->assertSame('15.0000', $profiles[0]->fresh()->commission_percent);
        $this->assertDatabaseMissing('tariff_profiles', ['channel' => 'shopee']);
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_values_do_not_change_profile_or_history(string $field, mixed $value): void
    {
        $catalog = app(TariffCatalog::class);
        $profile = $catalog->save($this->data());
        try {
            $catalog->save(array_replace($this->data(), [$field => $value]), $profile);
            $this->fail('Expected invalid '.$field);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
        $this->assertSame('14.0000', $profile->fresh()->commission_percent);
        $this->assertDatabaseCount('tariff_revisions', 1);
    }

    public static function invalidFields(): array
    {
        return [
            'commission negative' => ['commission_percent', '-1'],
            'commission too high' => ['commission_percent', '100.0001'],
            'float commission' => ['commission_percent', 0.1],
            'negative fee' => ['fixed_fee', '-1'],
            'excess fee precision' => ['fixed_fee', '1.00001'],
            'source required' => ['source', ''],
            'category required' => ['category', ' '],
            'unknown channel' => ['channel', 'amazon'],
            'invalid confidence' => ['confidence', 'official'],
            'invalid date' => ['valid_from', 'invalid'],
        ];
    }

    public function test_invalid_validity_range_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(TariffCatalog::class)->save(array_replace($this->data(), ['valid_from' => '2026-10-20', 'valid_until' => '2026-10-19']));
    }

    public function test_warnings_identify_unknown_expired_future_and_unverified_profiles(): void
    {
        $this->travelTo(Carbon::parse('2026-10-19 12:00:00', 'America/Sao_Paulo'));
        $catalog = app(TariffCatalog::class);
        $profile = $catalog->save(array_replace($this->data(), ['commission_percent' => null, 'valid_from' => '2026-10-01', 'valid_until' => '2026-10-18', 'review_on' => '2026-10-18']));
        $warnings = implode(' ', $catalog->warnings($profile));
        $this->assertStringContainsString('Comissão desconhecida', $warnings);
        $this->assertStringContainsString('Tarifa fixa desconhecida', $warnings);
        $this->assertStringContainsString('Condições não verificadas', $warnings);
        $this->assertStringContainsString('vigência encerrada', $warnings);
        $this->assertStringContainsString('revisão pendente', $warnings);
        $future = $catalog->save(array_replace($this->data(), ['valid_from' => '2026-10-20']));
        $this->assertStringContainsString('ainda não começou', implode(' ', $catalog->warnings($future)));
    }

    public function test_cross_channel_and_disabled_profiles_cannot_be_selected(): void
    {
        $catalog = app(TariffCatalog::class);
        $profile = $catalog->save($this->data());
        foreach (['shopee', 'mercado_livre'] as $channel) {
            if ($channel === 'mercado_livre') {
                $catalog->setActive($profile, false);
            }
            try {
                $catalog->simulationParameters($profile->id, $channel);
                $this->fail('Expected invalid profile.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('tariff_profile_id', $exception->errors());
            }
        }
    }

    public function test_tariff_pages_create_edit_consult_and_deactivate_profiles(): void
    {
        $this->get(route('tariffs.index'))->assertOk()->assertSee('Tarifas');
        $this->get(route('tariffs.create'))->assertOk()->assertSee('Nova regra');
        Livewire::test('pages::tariffs.form')
            ->set('channel', 'shopee')->set('category', 'Categoria de teste')->set('listing_type', 'Teste')
            ->set('commission_percent', '12,3456')->set('source', 'Fonte manual de teste')
            ->call('save')->assertHasNoErrors();
        $profile = TariffProfile::query()->sole();
        $this->assertSame('12.3456', $profile->commission_percent);
        $this->assertNull($profile->fixed_fee);
        $this->get(route('tariffs.edit', $profile))->assertOk()->assertSee('Histórico de alterações')->assertSee('Fonte manual de teste');
        Livewire::test('pages::tariffs.form', ['profile' => $profile])
            ->set('fixed_fee', '0')->set('notes', 'Observação de teste')->call('save')->assertHasNoErrors();
        Livewire::test('pages::tariffs.form', ['profile' => $profile->fresh()])->call('deactivate');
        $this->assertFalse($profile->fresh()->is_active);
        $this->assertDatabaseCount('tariff_revisions', 3);
        Livewire::test('pages::tariffs.index')->set('channel', 'shopee')->set('activity', 'inactive')->assertSee('Teste');
        $this->get('/tarifas/99999/editar')->assertNotFound();
    }

    public function test_profile_selection_prefills_only_known_values_and_keeps_shipping_individual(): void
    {
        $profile = app(TariffCatalog::class)->save($this->data());
        Livewire::test('pages::simulator')
            ->set('channel', 'mercado_livre')->set('seller_shipping', '8')->set('fixed_fee', '99')
            ->set('tariff_profile_id', (string) $profile->id)
            ->assertSet('commission_percent', '14,0000')->assertSet('fixed_fee', '')
            ->assertSet('seller_shipping', '8')->assertSee('Tarifa fixa desconhecida')
            ->assertSee('Condições não verificadas')
            ->set('fixed_fee', '5')->set('commission_percent', '15');
        $this->assertNull($profile->fresh()->fixed_fee);
        $this->assertSame('14.0000', $profile->fresh()->commission_percent);
        $this->assertDatabaseCount('tariff_revisions', 1);
    }

    public function test_profiles_integrate_with_both_modes_without_modifying_costs(): void
    {
        $profile = app(TariffCatalog::class)->save(array_replace($this->data(), ['commission_percent' => '10', 'fixed_fee' => '5']));
        $product = Product::factory()->create();
        $supply = app(SupplyCatalog::class)->create(['name' => 'Teste', 'unit' => 'un', 'unit_cost' => '20']);
        app(ProductComposition::class)->replace($product, [['supply_id' => $supply->id, 'quantity' => '1']]);
        $component = Livewire::test('pages::simulator')->set('product_id', (string) $product->id)
            ->set('channel', 'mercado_livre')->set('tariff_profile_id', (string) $profile->id)
            ->set('sale_price', '100')->set('seller_shipping', '8')->set('other_variable_expenses', '2')
            ->assertSee('R$ 55,00')->assertSee('55,00%');
        $component->set('mode', 'find_price')->set('desired_contribution', '55')->assertSee('R$ 100,00')->assertSee('atendida')
            ->set('fixed_fee', '')->assertSee('Aguardando informações')->assertDontSee('R$ 100,00');
        $this->assertDatabaseCount('supply_costs', 1);
        $this->assertDatabaseCount('tariff_revisions', 1);
        $this->assertSame('20.0000', $supply->fresh()->currentCost->unit_cost);
    }

    public function test_unknown_commission_is_not_zero_and_explicit_zero_is_loaded(): void
    {
        $catalog = app(TariffCatalog::class);
        $unknown = $catalog->save(array_replace($this->data(), ['commission_percent' => null]));
        $zero = $catalog->save(array_replace($this->data(), ['commission_percent' => '0', 'fixed_fee' => '0']));
        Livewire::test('pages::simulator')->set('channel', 'mercado_livre')
            ->set('tariff_profile_id', (string) $unknown->id)->assertSet('commission_percent', '')->assertSet('fixed_fee', '')
            ->set('tariff_profile_id', (string) $zero->id)->assertSet('commission_percent', '0,0000')->assertSet('fixed_fee', '0,0000')
            ->set('channel', 'shopee')->assertSet('tariff_profile_id', '')->assertSet('commission_percent', '')->assertSet('fixed_fee', '');
    }

    public function test_changed_profile_warns_without_overwriting_manual_values_until_reapplied(): void
    {
        $catalog = app(TariffCatalog::class);
        $profile = $catalog->save($this->data());
        $component = Livewire::test('pages::simulator')->set('channel', 'mercado_livre')->set('tariff_profile_id', (string) $profile->id)->set('commission_percent', '15');
        $catalog->save(array_replace($this->data(), ['commission_percent' => '19']), $profile);
        $component->set('sale_price', '100')->assertSet('commission_percent', '15')->assertSee('alterada desde a seleção')
            ->call('applyTariffProfile')->assertSet('commission_percent', '19,0000')->assertDontSee('alterada desde a seleção');
    }

    public function test_invalid_or_deactivated_profile_blocks_result_even_with_manual_values(): void
    {
        $profile = app(TariffCatalog::class)->save($this->data());
        Livewire::test('pages::simulator')->set('channel', 'shopee')->set('tariff_profile_id', (string) $profile->id)
            ->assertSet('commission_percent', '')->assertSee('Selecione uma regra ativa do canal informado.');
        app(TariffCatalog::class)->setActive($profile, false);
        Livewire::test('pages::simulator')->set('channel', 'mercado_livre')->set('tariff_profile_id', (string) $profile->id)
            ->assertSee('Selecione uma regra ativa do canal informado.');
    }

    private function data(): array
    {
        return [
            'channel' => 'mercado_livre', 'category' => 'Categoria de teste', 'listing_type' => 'Clássico',
            'commission_percent' => '14', 'fixed_fee' => null,
            'conditions' => 'Condições de teste', 'conditions_verified' => false,
            'valid_from' => null, 'valid_until' => null, 'review_on' => null, 'observed_on' => null,
            'source' => 'Fonte de teste', 'notes' => null, 'confidence' => 'estimated', 'is_active' => true,
        ];
    }
}
