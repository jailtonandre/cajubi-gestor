<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\TariffProfile;
use App\Models\User;
use App\Services\ProductComposition;
use App\Services\ScenarioComparison;
use App\Services\SupplyCatalog;
use App\Services\TariffCatalog;
use App\Services\TariffPricing;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TieredTariffsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            throw new \RuntimeException('Tier tests require SQLite in memory.');
        }
        $this->withoutVite();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->actingAs(User::factory()->create());
    }

    #[DataProvider('boundaries')]
    public function test_price_selects_the_correct_inclusive_tier(string $price, ?string $fee): void
    {
        $profile = app(TariffCatalog::class)->save($this->rule());
        $selection = app(TariffCatalog::class)->simulationParameters($profile->id, 'shopee', $price);
        $this->assertSame($fee, $selection['fixed_fee']);
        $this->assertSame($fee === null, $selection['tier'] === null);
    }

    public static function boundaries(): array
    {
        return [['0.01', '4.50'], ['79.99', '4.50'], ['80.00', '16'], ['99.99', '16'], ['100', null], ['79.995', null]];
    }

    #[DataProvider('invalidTiers')]
    public function test_invalid_ranges_never_write_a_rule_or_revision(array $tiers): void
    {
        try {
            app(TariffCatalog::class)->save(array_replace($this->rule(), ['tiers' => $tiers]));
            $this->fail('Invalid ranges must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('tiers', array_key_first($exception->errors()));
        }
        $this->assertDatabaseCount('tariff_profiles', 0);
        $this->assertDatabaseCount('tariff_revisions', 0);
    }

    public static function invalidTiers(): array
    {
        $tier = ['min_price' => '0', 'max_price' => '79.99', 'commission_percent' => '10', 'fixed_fee' => '4.50'];

        return [
            'empty' => [[]],
            'overlap' => [[$tier, array_replace($tier, ['min_price' => '79.99', 'max_price' => null])]],
            'gap' => [[$tier, array_replace($tier, ['min_price' => '80.01', 'max_price' => null])]],
            'reversed' => [[array_replace($tier, ['min_price' => '80'])]],
            'unbounded not last' => [[array_replace($tier, ['max_price' => null]), $tier]],
            'negative' => [[array_replace($tier, ['fixed_fee' => '-1'])]],
            'commission over 100' => [[array_replace($tier, ['commission_percent' => '100.01'])]],
            'fraction cent' => [[array_replace($tier, ['max_price' => '79.999'])]],
        ];
    }

    public function test_editing_and_deactivation_preserve_tier_snapshots(): void
    {
        $catalog = app(TariffCatalog::class);
        $profile = $catalog->save($this->rule());
        $data = $this->rule();
        $data['tiers'][0]['fixed_fee'] = '5';
        $catalog->save($data, $profile);
        $catalog->setActive($profile, false);
        $snapshots = $profile->revisions()->orderBy('id')->get()->pluck('snapshot');
        $this->assertSame('4.50', $snapshots[0]['tiers'][0]['fixed_fee']);
        $this->assertSame('5', $snapshots[1]['tiers'][0]['fixed_fee']);
        $this->assertFalse($snapshots[2]['is_active']);
        $this->assertSame('tiered', $snapshots[0]['pricing_model']);
        $this->assertNull($snapshots[0]['commission_percent']);
        $this->get(route('tariffs.edit', $profile))->assertOk()->assertSee('Histórico de alterações')->assertSee('Faixa 1');
    }

    public function test_marketplace_switch_clears_new_form_but_existing_rule_is_immutable(): void
    {
        Livewire::test('pages::tariffs.index')->dispatch('tariff-marketplace-changed', channel: 'shopee')
            ->assertSet('channel', 'shopee')->assertSee('Referência Shopee CNPJ');
        Livewire::test('pages::tariffs.form')->set('listing_type', 'Clássico')->set('category', 'Categoria')
            ->set('commission_percent', '14')->set('source', 'Referência')->set('pricing_model', 'tiered')
            ->set('channel', 'shopee')->assertSet('listing_type', '')->assertSet('category', '')
            ->assertSet('commission_percent', '')->assertSet('source', '')->assertSet('tiers', [])->assertSet('pricing_model', 'single')
            ->assertDispatched('tariff-marketplace-changed', channel: 'shopee');
        $profile = app(TariffCatalog::class)->save($this->rule());
        Livewire::test('pages::tariffs.form', ['profile' => $profile])->set('channel', 'mercado_livre')->assertSet('channel', 'shopee');
        try {
            app(TariffCatalog::class)->save(array_replace($this->rule(), ['channel' => 'mercado_livre']), $profile);
            $this->fail('An existing rule cannot change marketplace.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('channel', $exception->errors());
        }
        $this->assertSame('shopee', $profile->fresh()->channel);
        $this->assertDatabaseCount('tariff_revisions', 1);
    }

    public function test_direct_and_scenario_calculations_use_tiers_without_changing_costs(): void
    {
        $data = $this->parameters();
        $service = app(TariffPricing::class);
        $lower = $service->simulate($data);
        $upper = $service->simulate(array_replace($data, ['sale_price' => '80']));
        $this->assertTrue(BigDecimal::of($lower['contribution'])->isEqualTo('42.491'));
        $this->assertTrue(BigDecimal::of($upper['contribution'])->isEqualTo('23'));
        $scenario = app(ScenarioComparison::class)->evaluate($data + ['quantity' => '10', 'cost_mode' => 'current']);
        $this->assertTrue(BigDecimal::of($scenario['contribution_total'])->isEqualTo('424.91'));
        $this->assertDatabaseCount('supply_costs', 1);
        $this->assertDatabaseCount('tariff_revisions', 1);
    }

    public function test_price_changes_update_livewire_tiers_and_keep_explicit_overrides(): void
    {
        $data = $this->parameters();
        Livewire::test('pages::simulator')->set('product_id', (string) $data['product_id'])->set('channel', 'shopee')
            ->set('sale_price', '79,99')->set('tariff_profile_id', (string) $data['tariff_profile_id'])
            ->assertSet('fixed_fee', '4,50')->set('sale_price', '80')->assertSet('fixed_fee', '16')
            ->set('fixed_fee', '0')->set('sale_price', '79,99')->assertSet('fixed_fee', '0')->assertSet('commission_percent', '10')
            ->call('applyTariffProfile')->assertSet('fixed_fee', '4,50');
        Livewire::test('pages::scenarios')->set('rows.0.channel', 'shopee')->set('rows.0.sale_price', '79,99')
            ->set('rows.0.tariff_profile_id', (string) $data['tariff_profile_id'])->assertSet('rows.0.fixed_fee', '4,50')
            ->set('rows.0.sale_price', '80')->assertSet('rows.0.fixed_fee', '16')
            ->set('rows.0.fixed_fee', '0')->set('rows.0.sale_price', '79,99')->assertSet('rows.0.fixed_fee', '0');
    }

    public function test_inverse_compares_discontinuous_tiers_and_rounds_up_then_verifies(): void
    {
        $data = $this->parameters();
        $service = app(TariffPricing::class);
        $result = $service->findPrice($data + ['desired_contribution' => '30']);
        $this->assertSame('66.12', $result['suggested_price']);
        $this->assertTrue(BigDecimal::of($result['contribution'])->isGreaterThanOrEqualTo('30'));
        $previous = $service->simulate(array_replace($data, ['sale_price' => '66.11']));
        $this->assertTrue(BigDecimal::of($previous['contribution'])->isLessThan('30'));
        Livewire::test('pages::simulator')->set('product_id', (string) $data['product_id'])->set('channel', 'shopee')
            ->set('tariff_profile_id', (string) $data['tariff_profile_id'])->set('seller_shipping', '5')->set('other_variable_expenses', '0')
            ->set('mode', 'find_price')->set('desired_contribution', '30')->assertSee('R$ 66,12')->assertSee('atendida');
    }

    public function test_inverse_can_choose_a_tier_boundary_with_a_lower_fee(): void
    {
        $data = $this->parameters();
        $profile = TariffProfile::findOrFail($data['tariff_profile_id']);
        $rule = $this->rule();
        $rule['tiers'][0]['fixed_fee'] = '60';
        $rule['tiers'][1]['fixed_fee'] = '0';
        $rule['tiers'][1]['commission_percent'] = '0';
        app(TariffCatalog::class)->save($rule, $profile);
        $result = app(TariffPricing::class)->findPrice($data + ['desired_contribution' => '30']);
        $this->assertSame('80.00', $result['suggested_price']);
        $this->assertTrue(BigDecimal::of($result['contribution'])->isEqualTo('55'));
    }

    public function test_inverse_continues_when_one_tier_exceeds_the_supported_price_limit(): void
    {
        $data = $this->parameters();
        $profile = TariffProfile::findOrFail($data['tariff_profile_id']);
        $rule = $this->rule();
        $rule['tiers'][0]['commission_percent'] = '99.9999';
        $rule['tiers'][1]['commission_percent'] = '0';
        $rule['tiers'][1]['max_price'] = null;
        app(TariffCatalog::class)->save($rule, $profile);
        $result = app(TariffPricing::class)->findPrice($data + ['desired_contribution' => '1000000']);
        $this->assertSame('1000041.00', $result['suggested_price']);
    }

    public function test_no_applicable_tier_blocks_even_with_manual_fees(): void
    {
        $data = array_replace($this->parameters(), ['sale_price' => '100', 'commission_manual' => true, 'fixed_manual' => true, 'commission_percent' => '0', 'fixed_fee' => '0']);
        $this->expectException(ValidationException::class);
        app(TariffPricing::class)->simulate($data);
    }

    public function test_unknown_tier_cost_does_not_default_to_zero(): void
    {
        $data = $this->parameters();
        $profile = TariffProfile::findOrFail($data['tariff_profile_id']);
        $rule = $this->rule();
        $rule['tiers'][1]['fixed_fee'] = null;
        app(TariffCatalog::class)->save($rule, $profile);
        $this->expectException(ValidationException::class);
        app(TariffPricing::class)->findPrice($data + ['desired_contribution' => '10']);
    }

    public function test_order_fee_requires_explicit_unit_allocation(): void
    {
        $data = $this->parameters();
        $profile = TariffProfile::findOrFail($data['tariff_profile_id']);
        app(TariffCatalog::class)->save(array_replace($this->rule(), ['charge_basis' => 'order']), $profile);
        try {
            app(TariffPricing::class)->simulate($data);
            $this->fail('An order fee is not a per-item fee.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('fixed_fee', $exception->errors());
        }
        $result = app(TariffPricing::class)->simulate(array_replace($data, ['fixed_manual' => true, 'fixed_fee' => '2.25']));
        $this->assertSame('2.25', $result['fixed_fee']);
    }

    public function test_inverse_rejects_unreachable_target_with_finite_coverage(): void
    {
        $this->expectException(ValidationException::class);
        app(TariffPricing::class)->findPrice($this->parameters() + ['desired_contribution' => '1000']);
    }

    public function test_form_saves_brazilian_decimal_tiers_and_allows_an_unbounded_last_range(): void
    {
        Livewire::test('pages::tariffs.form', ['marketplace' => 'shopee'])
            ->set('listing_type', 'Modalidade de teste')->set('category', 'Categoria')->set('source', 'Fonte de teste')
            ->set('pricing_model', 'tiered')->assertCount('tiers', 0)
            ->set('tierDraft.min_price', '0,01')->set('tierDraft.max_price', '79,99')
            ->set('tierDraft.commission_percent', '10,1234')->set('tierDraft.fixed_fee', '4,50')
            ->call('addTier')->assertCount('tiers', 1)->assertSet('tierDraft.min_price', '')
            ->set('tierDraft.min_price', '80,00')
            ->set('tierDraft.commission_percent', '0')->set('tierDraft.fixed_fee', '0')->call('addTier')
            ->call('save')->assertHasNoErrors();
        $profile = TariffProfile::query()->sole();
        $this->assertNull($profile->tiers[1]['max_price']);
        $selection = app(TariffCatalog::class)->simulationParameters($profile->id, 'shopee', '1000000');
        $this->assertSame('0', $selection['fixed_fee']);
        $this->assertSame('0', $selection['commission_percent']);
        $this->assertSame('10.1234', $profile->tiers[0]['commission_percent']);
    }

    public function test_existing_four_tiers_load_without_changes_and_edit_in_the_single_editor(): void
    {
        $data = $this->rule();
        $data['tiers'][] = ['min_price' => '100', 'max_price' => '199.99', 'commission_percent' => null, 'fixed_fee' => '0'];
        $data['tiers'][] = ['min_price' => '200', 'max_price' => '299.99', 'commission_percent' => '0', 'fixed_fee' => null];
        $profile = app(TariffCatalog::class)->save($data);
        $original = $profile->fresh()->tiers;

        $page = Livewire::test('pages::tariffs.form', ['profile' => $profile])
            ->assertCount('tiers', 4)->assertSet('tierDraft.min_price', '')
            ->assertSee('Tabela de faixas adicionadas')->assertSee('Desconhecida')
            ->call('editTier', 1)->assertSet('tierDraft.min_price', '80,00')
            ->set('tierDraft.commission_percent', '14,1234')->call('addTier')
            ->assertCount('tiers', 4)->assertSet('editingTier', null)->assertSet('tierDraft.min_price', '');

        $this->assertSame($original, $profile->fresh()->tiers);
        $this->assertDatabaseCount('tariff_revisions', 1);
        $page->call('save')->assertHasNoErrors();
        $saved = $profile->fresh()->tiers;
        $this->assertCount(4, $saved);
        $this->assertSame('14.1234', $saved[1]['commission_percent']);
        $this->assertSame($original[0], $saved[0]);
        $this->assertSame($original[2], $saved[2]);
        $this->assertSame($original[3], $saved[3]);
        $this->assertDatabaseCount('tariff_revisions', 2);
    }

    public function test_removal_only_updates_the_table_until_the_whole_rule_is_saved(): void
    {
        $profile = app(TariffCatalog::class)->save($this->rule());
        $original = $profile->tiers;
        $page = Livewire::test('pages::tariffs.form', ['profile' => $profile])
            ->call('removeTier', 1)->assertCount('tiers', 1)->assertSet('tiersChanged', true)
            ->set('tierDraft.min_price', '80,00')->set('tierDraft.fixed_fee', '0')->call('addTier')
            ->assertCount('tiers', 2);

        $this->assertSame($original, $profile->fresh()->tiers);
        $page->call('save')->assertHasNoErrors();
        $saved = $profile->fresh()->tiers;
        $this->assertCount(2, $saved);
        $this->assertSame($original[0], $saved[0]);
        $this->assertNull($saved[1]['max_price']);
        $this->assertNull($saved[1]['commission_percent']);
        $this->assertSame('0', $saved[1]['fixed_fee']);
    }

    public function test_pending_tier_cannot_be_silently_saved_overwritten_or_removed(): void
    {
        $profile = app(TariffCatalog::class)->save($this->rule());
        $original = $profile->tiers;
        $page = Livewire::test('pages::tariffs.form', ['profile' => $profile])
            ->call('editTier', 0)->set('tierDraft.fixed_fee', '9,1234')
            ->call('editTier', 1)->assertHasErrors(['tierDraft'])->assertSet('editingTier', 0)
            ->assertSet('tierDraft.fixed_fee', '9,1234')
            ->call('removeTier', 1)->assertHasErrors(['tierDraft'])->assertCount('tiers', 2)
            ->call('save')->assertHasErrors(['tierDraft'])->assertSee('Adicione ou atualize a faixa em edição')
            ->set('pricing_model', 'single')->assertSet('pricing_model', 'tiered')
            ->call('deactivate')->assertHasErrors(['tierDraft']);

        $this->assertSame($original, $profile->fresh()->tiers);
        $this->assertTrue($profile->fresh()->is_active);
        $this->assertDatabaseCount('tariff_revisions', 1);
        $page->call('cancelTier')->assertSet('editingTier', null)->assertSet('tierDraft.fixed_fee', '')
            ->call('save')->assertHasNoErrors();
        $this->assertSame($original, $profile->fresh()->tiers);
        $this->assertDatabaseCount('tariff_revisions', 1);
    }

    public function test_draft_precision_and_final_continuity_validation_preserve_unsaved_values(): void
    {
        $page = Livewire::test('pages::tariffs.form', ['marketplace' => 'shopee'])
            ->set('listing_type', 'Teste')->set('category', 'Categoria')->set('source', 'Fonte sintética')
            ->set('pricing_model', 'tiered')->set('tierDraft.min_price', '0,001')
            ->call('addTier')->assertHasErrors(['tierDraft.min_price'])->assertCount('tiers', 0)
            ->assertSet('tierDraft.min_price', '0,001')
            ->set('tierDraft.min_price', '0')->set('tierDraft.max_price', '79,99')->call('addTier')
            ->set('tierDraft.min_price', '80,01')->call('addTier')
            ->call('save')->assertHasErrors(['tiers.1.min_price'])->assertCount('tiers', 2)
            ->assertSee('sem sobreposição ou lacunas');

        $this->assertDatabaseCount('tariff_profiles', 0);
        $this->assertDatabaseCount('tariff_revisions', 0);
        $page->call('editTier', 1)->set('tierDraft.min_price', '80,00')->call('addTier')
            ->call('save')->assertHasNoErrors();
        $this->assertCount(2, TariffProfile::query()->sole()->tiers);
    }

    public function test_switching_marketplaces_does_not_clear_an_unadded_tier(): void
    {
        Livewire::test('pages::tariffs.form', ['marketplace' => 'shopee'])
            ->set('pricing_model', 'tiered')->set('tierDraft.min_price', '0')
            ->set('channel', 'mercado_livre')->assertSet('channel', 'shopee')
            ->assertSet('tierDraft.min_price', '0')->assertHasErrors(['tierDraft']);
        $this->assertDatabaseCount('tariff_profiles', 0);
    }

    public function test_unknown_applicable_values_block_direct_calculation_until_explicit_manual_input(): void
    {
        $data = $this->parameters();
        $profile = TariffProfile::findOrFail($data['tariff_profile_id']);
        $rule = $this->rule();
        $rule['tiers'][0]['commission_percent'] = null;
        $rule['tiers'][0]['fixed_fee'] = null;
        app(TariffCatalog::class)->save($rule, $profile);
        try {
            app(TariffPricing::class)->simulate($data);
            $this->fail('Unknown tariffs cannot produce a result.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('commission_percent', $exception->errors());
            $this->assertArrayHasKey('fixed_fee', $exception->errors());
        }
        $result = app(TariffPricing::class)->simulate(array_replace($data, ['commission_percent' => '0', 'fixed_fee' => '0', 'commission_manual' => true, 'fixed_manual' => true]));
        $this->assertTrue(BigDecimal::of($result['contribution'])->isEqualTo('54.99'));
        $this->assertNull($profile->fresh()->tiers[0]['fixed_fee']);
    }

    public function test_inverse_rejects_a_commission_of_one_hundred_percent(): void
    {
        $data = $this->parameters();
        $profile = TariffProfile::findOrFail($data['tariff_profile_id']);
        $rule = $this->rule();
        $rule['tiers'][0]['commission_percent'] = '100';
        app(TariffCatalog::class)->save($rule, $profile);
        $this->expectException(ValidationException::class);
        app(TariffPricing::class)->findPrice($data + ['desired_contribution' => '10']);
    }

    private function rule(): array
    {
        // Synthetic fixtures, never commercial defaults.
        return ['channel' => 'shopee', 'category' => 'Categoria de teste', 'listing_type' => 'Modalidade de teste',
            'pricing_model' => 'tiered', 'charge_basis' => 'item', 'commission_percent' => null, 'fixed_fee' => null,
            'tiers' => [
                ['min_price' => '0.01', 'max_price' => '79.99', 'commission_percent' => '10', 'fixed_fee' => '4.50'],
                ['min_price' => '80.00', 'max_price' => '99.99', 'commission_percent' => '20', 'fixed_fee' => '16'],
            ], 'source' => 'Referência sintética de teste', 'confidence' => 'estimated', 'conditions_verified' => false, 'is_active' => true];
    }

    private function parameters(): array
    {
        $product = Product::factory()->create();
        $supply = app(SupplyCatalog::class)->create(['name' => 'Teste', 'unit' => 'un', 'unit_cost' => '20']);
        app(ProductComposition::class)->replace($product, [['supply_id' => $supply->id, 'quantity' => '1']]);
        $profile = app(TariffCatalog::class)->save($this->rule());

        return ['product_id' => $product->id, 'channel' => 'shopee', 'tariff_profile_id' => $profile->id,
            'sale_price' => '79.99', 'seller_shipping' => '5', 'other_variable_expenses' => '0', 'commission_manual' => false, 'fixed_manual' => false];
    }
}
