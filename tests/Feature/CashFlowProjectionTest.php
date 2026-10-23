<?php

namespace Tests\Feature;

use App\Models\CashMovement;
use App\Models\CashProjection;
use App\Models\User;
use App\Services\CashFlowProjection;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CashFlowProjectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            throw new \RuntimeException('Cash flow tests require SQLite in memory.');
        }
        $this->withoutVite();
        $this->artisan('migrate', ['--force' => true, '--no-interaction' => true])->assertExitCode(0);
        $this->actingAs(User::factory()->create());
    }

    public function test_eight_seven_day_periods_propagate_balances_and_include_only_the_56_days(): void
    {
        $projection = CashProjection::factory()->create(['opening_balance' => '100.10']);
        CashMovement::factory()->for($projection)->createMany([
            ['expected_on' => '2026-10-21', 'amount' => '999.00'],
            ['expected_on' => '2026-10-22', 'direction' => 'inflow', 'amount' => '0.20'],
            ['expected_on' => '2026-10-28', 'amount' => '0.10'],
            ['expected_on' => '2026-10-29', 'amount' => '10.00'],
            ['expected_on' => '2026-12-16', 'direction' => 'inflow', 'amount' => '20.02'],
            ['expected_on' => '2026-12-17', 'direction' => 'inflow', 'amount' => '888.00'],
        ]);

        $result = app(CashFlowProjection::class)->calculate($projection, $projection->movements);

        $this->assertCount(8, $result['weeks']);
        $this->assertSame('22/10/2026', $result['weeks'][0]['starts_on']);
        $this->assertSame('28/10/2026', $result['weeks'][0]['ends_on']);
        $this->assertSame(['100.20', '90.20', '90.20', '90.20', '90.20', '90.20', '90.20', '110.22'], array_column($result['weeks'], 'closing'));
        $this->assertSame(['100.10', '100.20', '90.20', '90.20', '90.20', '90.20', '90.20', '90.20'], array_column($result['weeks'], 'opening'));
        $this->assertSame('0.20', $result['weeks'][0]['inflows']);
        $this->assertSame('0.10', $result['weeks'][0]['outflows']);
        $this->assertSame('10.00', $result['weeks'][1]['outflows']);
        $this->assertSame('20.02', $result['weeks'][7]['inflows']);
        $this->assertSame('16/12/2026', $result['weeks'][7]['ends_on']);
        $this->assertSame(2, $result['excluded_count']);
        $this->assertSame('90.20', $result['minimum']);
        $this->assertSame('0.00', $result['additional_capital']);
        $this->assertNull($result['first_shortfall']);
        $this->assertSame('110.22', $result['closing']);
    }

    public function test_late_receipt_does_not_hide_a_shortfall_within_the_same_week(): void
    {
        $projection = CashProjection::factory()->create(['opening_balance' => '100.00']);
        CashMovement::factory()->for($projection)->createMany([
            ['expected_on' => '2026-10-23', 'amount' => '150.01'],
            ['expected_on' => '2026-10-26', 'direction' => 'inflow', 'amount' => '200.00'],
            ['expected_on' => '2026-11-05', 'amount' => '400.00'],
        ]);

        $result = app(CashFlowProjection::class)->calculate($projection, $projection->movements);

        $this->assertSame('149.99', $result['weeks'][0]['closing']);
        $this->assertTrue($result['weeks'][0]['negative']);
        $this->assertSame('-50.01', $result['weeks'][0]['minimum']);
        $this->assertSame('2026-10-23', $result['first_shortfall']);
        $this->assertSame('-250.01', $result['minimum']);
        $this->assertSame('250.01', $result['additional_capital']);
        $this->assertSame('-250.01', $result['closing']);
    }

    public function test_same_day_movements_are_netted_and_order_does_not_change_the_forecast(): void
    {
        $projection = CashProjection::factory()->create();
        CashMovement::factory()->for($projection)->createMany([
            ['amount' => '10.20'],
            ['direction' => 'inflow', 'amount' => '10.30'],
        ]);
        $calculator = app(CashFlowProjection::class);

        $result = $calculator->calculate($projection, $projection->movements);

        $this->assertSame('0.10', $result['closing']);
        $this->assertSame('0.00', $result['minimum']);
        $this->assertSame($result, $calculator->calculate($projection, $projection->movements->reverse()));
    }

    public function test_negative_opening_balance_requires_capital_even_without_movements(): void
    {
        $projection = CashProjection::factory()->create(['opening_balance' => '-12.34']);

        $result = app(CashFlowProjection::class)->calculate($projection, []);

        $this->assertSame('-12.34', $result['minimum']);
        $this->assertSame('12.34', $result['additional_capital']);
        $this->assertSame('2026-10-22', $result['first_shortfall']);
    }

    public function test_user_can_save_reopen_edit_and_delete_previsions_without_creating_real_transactions(): void
    {
        $this->travelTo('2026-10-22 10:00:00');
        $this->get(route('cash-flow'))->assertOk()->assertSee('Fluxo de Caixa')->assertSee('Projeção semanal');
        $page = Livewire::test('pages::cash-flow')
            ->set('opening_balance', '1.000,10')->set('starts_on', '2026-10-22')
            ->call('saveParameters')->assertHasNoErrors()
            ->set('description', 'Compra de castanhas')->set('amount', '100,20')->set('expected_on', '2026-10-23')
            ->call('saveMovement')->assertHasNoErrors()->assertSee('R$ 899,90');
        $movement = CashMovement::query()->sole();
        $this->assertSame('100.20', $movement->amount);
        $this->assertDatabaseCount('cash_projections', 1);

        Livewire::test('pages::cash-flow')->assertSet('opening_balance', '1.000,10')->assertSet('starts_on', '2026-10-22')->assertSee('Compra de castanhas');
        $page->call('editMovement', $movement->id)->assertSet('amount', '100,20')
            ->set('direction', 'inflow')->set('description', 'Recebimento previsto')
            ->set('amount', '200,30')->set('expected_on', '2026-10-29')
            ->call('saveMovement')->assertHasNoErrors()->assertSee('R$ 1.200,40');
        $this->assertDatabaseCount('cash_movements', 1);
        $this->assertDatabaseHas('cash_movements', ['id' => $movement->id, 'direction' => 'inflow']);
        $this->assertSame('2026-10-29', $movement->fresh()->expected_on->toDateString());
        $this->assertSame('200.30', $movement->fresh()->amount);
        $page->call('editMovement', $movement->id)->call('deleteMovement', $movement->id)->assertSet('editing_id', null);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_replaying_the_same_submission_does_not_duplicate_a_movement(): void
    {
        $page = Livewire::test('pages::cash-flow');
        $key = $page->get('submission_key');
        CashMovement::factory()->for(CashProjection::query()->findOrFail(1))->create(['submission_key' => $key]);

        $page->set('description', 'Reenvio')->set('amount', '10,00')->set('expected_on', '2026-10-22')
            ->call('saveMovement')->assertHasNoErrors();

        $this->assertDatabaseCount('cash_movements', 1);
        $this->assertDatabaseMissing('cash_movements', ['description' => 'Reenvio']);
        $page->call('saveMovement')->assertHasErrors(['description', 'amount', 'expected_on']);
        $this->assertDatabaseCount('cash_movements', 1);
    }

    public function test_changing_horizon_preserves_entries_and_marks_excluded_entries(): void
    {
        $page = Livewire::test('pages::cash-flow')->set('starts_on', '2026-10-22')->call('saveParameters');
        CashMovement::factory()->for(CashProjection::query()->findOrFail(1))->create(['description' => 'Compra futura', 'expected_on' => '2026-12-17', 'amount' => '50.00']);

        $page->call('$refresh')->assertSee('Fora do período')->assertSee('R$ 0,00')
            ->set('starts_on', '2026-10-23')->call('saveParameters')->assertHasNoErrors()->assertDontSee('Fora do período')->assertSee('R$ -50,00');

        $this->assertDatabaseCount('cash_movements', 1);
        Livewire::test('pages::cash-flow')->assertSet('starts_on', '2026-10-23')->assertSee('Compra futura');
    }

    #[DataProvider('invalidMovements')]
    public function test_rejects_invalid_previsions_without_writing_data(string $field, string $value): void
    {
        $page = Livewire::test('pages::cash-flow')
            ->set('description', 'Previsão')->set('amount', '10,00')->set('expected_on', '2026-10-22');

        $page->set($field, $value)->call('saveMovement')->assertHasErrors([$field]);

        $this->assertDatabaseCount('cash_movements', 0);
    }

    public static function invalidMovements(): array
    {
        return [
            'missing description' => ['description', ' '],
            'long description' => ['description', str_repeat('a', 151)],
            'unknown direction' => ['direction', 'sale'],
            'zero amount' => ['amount', '0'],
            'negative amount' => ['amount', '-1,00'],
            'fractional cent' => ['amount', '1,001'],
            'overflow' => ['amount', '1000000000000'],
            'exponent' => ['amount', '1e3'],
            'invalid date' => ['expected_on', '2026-02-30'],
            'missing date' => ['expected_on', ''],
        ];
    }

    public function test_invalid_parameters_preserve_saved_values_and_cancel_does_not_save_edits(): void
    {
        $page = Livewire::test('pages::cash-flow')->set('starts_on', '2026-10-22')->call('saveParameters');
        $movement = CashMovement::factory()->for(CashProjection::query()->findOrFail(1))->create();

        $page->set('opening_balance', '1,001')->set('starts_on', '2026-02-30')->call('saveParameters')->assertHasErrors(['opening_balance', 'starts_on']);

        $this->assertSame('0.00', CashProjection::query()->findOrFail(1)->opening_balance);
        $this->assertSame('2026-10-22', CashProjection::query()->findOrFail(1)->starts_on->toDateString());
        $page->call('editMovement', $movement->id)->set('amount', '999,00')->call('cancelEdit')->assertSet('editing_id', null);
        $this->assertSame('10.00', $movement->fresh()->amount);
    }

    public function test_forecast_descriptions_are_escaped(): void
    {
        Livewire::test('pages::cash-flow');
        CashMovement::factory()->for(CashProjection::query()->findOrFail(1))->create(['description' => '<script>alert(1)</script>']);

        $this->get(route('cash-flow'))->assertOk()->assertSee('<script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_negative_localized_opening_balance_can_be_saved_and_saved_again(): void
    {
        Livewire::test('pages::cash-flow')->set('opening_balance', '-1.000,10')
            ->call('saveParameters')->assertHasNoErrors()->assertSet('opening_balance', '-1.000,10')
            ->call('saveParameters')->assertHasNoErrors()->assertSee('R$ 1.000,10');

        $this->assertSame('-1000.10', CashProjection::query()->findOrFail(1)->opening_balance);
    }
}
