<?php

use App\Models\CashMovement;
use App\Models\CashProjection;
use App\Services\CashFlowProjection;
use App\Services\DecimalPresentation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Fluxo de Caixa')] class extends Component
{
    public string $opening_balance = '0,00';

    public string $starts_on = '';

    public string $description = '';

    public string $direction = 'outflow';

    public string $amount = '';

    public string $expected_on = '';

    protected CashFlowProjection $calculator;

    public function boot(CashFlowProjection $calculator): void
    {
        $this->calculator = $calculator;
    }

    #[Locked]
    public ?int $editing_id = null;

    #[Locked]
    public string $submission_key = '';

    public function mount(): void
    {
        $projection = CashProjection::query()->firstOrCreate(['id' => 1], [
            'opening_balance' => '0.00', 'starts_on' => now()->toDateString(),
        ]);
        $this->opening_balance = DecimalPresentation::format($projection->opening_balance, 2);
        $this->starts_on = $projection->starts_on->toDateString();
        $this->cancelEdit();
    }

    public function saveParameters(): void
    {
        $this->opening_balance = DecimalPresentation::input($this->opening_balance);
        $validated = $this->validate([
            'opening_balance' => ['required', 'regex:/\A-?[0-9]{1,12}(\.[0-9]{1,2})?\z/'],
            'starts_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:9998-12-31'],
        ], [
            'opening_balance.regex' => 'Informe o saldo em reais, com até 12 dígitos e 2 casas decimais.',
        ], ['opening_balance' => 'saldo inicial', 'starts_on' => 'data inicial']);
        CashProjection::query()->findOrFail(1)->update($validated);
        $this->opening_balance = DecimalPresentation::format($this->opening_balance, 2);
        unset($this->projection, $this->forecast);
        session()->flash('success', 'Parâmetros da projeção salvos.');
    }

    public function saveMovement(): void
    {
        $this->amount = DecimalPresentation::input($this->amount);
        $this->description = trim($this->description);
        $validated = $this->validate([
            'description' => ['required', 'string', 'max:150'],
            'direction' => ['required', Rule::in(['inflow', 'outflow'])],
            'amount' => ['required', 'regex:/\A[0-9]{1,12}(\.[0-9]{1,2})?\z/', 'numeric', 'gt:0'],
            'expected_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:9999-12-31'],
        ], [
            'amount.regex' => 'Informe um valor em reais, com até 12 dígitos e 2 casas decimais.',
            'amount.gt' => 'O valor deve ser maior que zero.',
        ], ['description' => 'descrição', 'direction' => 'tipo', 'amount' => 'valor', 'expected_on' => 'data prevista']);
        $movements = CashProjection::query()->findOrFail(1)->movements();
        if ($this->editing_id !== null) {
            $movements->findOrFail($this->editing_id)->update($validated);
        } else {
            $movements->firstOrCreate(['submission_key' => $this->submission_key], $validated);
        }
        $this->cancelEdit();
        unset($this->movements, $this->forecast);
        session()->flash('success', 'Movimentação prevista salva.');
    }

    public function editMovement(int $id): void
    {
        $movement = CashProjection::query()->findOrFail(1)->movements()->findOrFail($id);
        $this->resetValidation();
        $this->editing_id = $movement->id;
        $this->description = $movement->description;
        $this->direction = $movement->direction;
        $this->amount = DecimalPresentation::format($movement->amount, 2);
        $this->expected_on = $movement->expected_on->toDateString();
    }

    public function deleteMovement(int $id): void
    {
        CashProjection::query()->findOrFail(1)->movements()->findOrFail($id)->delete();
        if ($this->editing_id === $id) {
            $this->cancelEdit();
        }
        unset($this->movements, $this->forecast);
        session()->flash('success', 'Movimentação prevista removida.');
    }

    public function cancelEdit(): void
    {
        $this->reset(['editing_id', 'description', 'direction', 'amount', 'expected_on']);
        $this->submission_key = (string) Str::uuid();
        $this->resetValidation();
    }

    #[Computed]
    public function projection(): CashProjection
    {
        return CashProjection::query()->findOrFail(1);
    }

    /** @return Collection<int, CashMovement> */
    #[Computed]
    public function movements(): Collection
    {
        return $this->projection->movements()->orderBy('expected_on')->orderBy('id')->get();
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function forecast(): array
    {
        return $this->calculator->calculate($this->projection, $this->movements);
    }
};
?>

<div class="grid gap-6">
    <x-success/>
    <div class="grid gap-2">
        <p class="eyebrow">Planejamento financeiro / 8 semanas</p>
        <h1 class="page-title">Fluxo de Caixa</h1>
        <p class="text-sm text-stone-500">Veja se o dinheiro disponível cobre os pagamentos e recebimentos previstos.</p>
    </div>

    <section class="panel p-5 sm:p-6" aria-labelledby="parameters-title">
        <h2 id="parameters-title" class="mb-4 font-semibold">Ponto de partida</h2>
        <form wire:submit="saveParameters" class="grid items-start gap-4 sm:grid-cols-[1fr_1fr_auto]">
            <x-field label="Saldo inicial disponível (R$)" for="opening_balance" hint="Dinheiro disponível antes dos lançamentos da data inicial.">
                <input id="opening_balance" wire:model="opening_balance" class="field" inputmode="decimal" required>
            </x-field>
            <x-field label="Data inicial" for="starts_on">
                <input id="starts_on" wire:model="starts_on" class="field" type="date" required>
            </x-field>
            <button class="btn-primary sm:mt-7" wire:loading.attr="disabled" wire:target="saveParameters">Salvar parâmetros</button>
        </form>
        <p class="mt-3 text-xs text-stone-500">O resumo usa os parâmetros salvos. Ao mudar o período, os lançamentos são preservados.</p>
    </section>

    @php($forecast = $this->forecast)
    <section aria-label="Resumo" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="panel grid gap-3 p-5"><p class="text-xs text-stone-500">Saldo inicial</p><x-money :value="$this->projection->opening_balance" class="text-2xl font-semibold"/></div>
        <div class="panel grid gap-3 p-5"><p class="text-xs text-stone-500">Menor saldo previsto</p><x-money :value="$forecast['minimum']" :class="$forecast['first_shortfall'] !== null ? 'text-2xl font-semibold text-red-700' : 'text-2xl font-semibold'"/></div>
        <div class="panel grid gap-3 border-cajubi-200 bg-cajubi-50 p-5"><p class="text-xs text-cajubi-800">Capital adicional necessário</p><x-money :value="$forecast['additional_capital']" class="text-2xl font-semibold text-cajubi-800"/></div>
        <div class="panel grid gap-3 p-5"><p class="text-xs text-stone-500">Saldo ao final de oito semanas</p><x-money :value="$forecast['closing']" class="text-2xl font-semibold"/></div>
    </section>
    <p class="text-xs leading-5 text-stone-500">O menor saldo considera o saldo inicial e os fechamentos diários. Entradas e saídas da mesma data são somadas, sem ordem intradiária. O capital adicional cobre a maior falta de caixa prevista; não representa lucro nem faturamento.</p>
    @if($forecast['first_shortfall'])
        <p class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800" role="status">Primeira falta de caixa prevista em {{ \Carbon\CarbonImmutable::parse($forecast['first_shortfall'])->format('d/m/Y') }}. Revise os prazos ou o capital disponível.</p>
    @endif

    <section class="panel overflow-hidden" aria-labelledby="projection-title">
        <div class="grid gap-1 p-5"><h2 id="projection-title" class="font-semibold">Projeção semanal</h2><p class="text-xs text-stone-500">Oito períodos consecutivos de sete dias. Valores em reais.</p></div>
        <div class="overflow-x-auto">
            <table class="w-full whitespace-nowrap text-sm">
                <thead class="table-head"><tr><th class="px-5 py-3">Semana / período</th><th class="px-5 py-3 text-right">Saldo inicial</th><th class="px-5 py-3 text-right">Entradas</th><th class="px-5 py-3 text-right">Saídas</th><th class="px-5 py-3 text-right">Saldo final</th></tr></thead>
                <tbody class="divide-y divide-stone-100">
                    @foreach($forecast['weeks'] as $week)
                        <tr wire:key="week-{{ $week['number'] }}" @class(['bg-red-50 text-red-900' => $week['negative']])>
                            <th scope="row" class="px-5 py-4 text-left font-medium">Semana {{ $week['number'] }}<span class="mt-1 block text-xs font-normal">{{ $week['starts_on'] }} a {{ $week['ends_on'] }}</span>@if($week['negative'])<span class="mt-1 block text-xs">Falta de caixa · mínimo <x-money :value="$week['minimum']"/></span>@endif</th>
                            <td class="px-5 py-4 text-right"><x-money :value="$week['opening']"/></td><td class="px-5 py-4 text-right"><x-money :value="$week['inflows']"/></td><td class="px-5 py-4 text-right"><x-money :value="$week['outflows']"/></td><td class="px-5 py-4 text-right font-semibold"><x-money :value="$week['closing']"/></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section aria-labelledby="movements-title" class="grid gap-5">
        <div class="grid gap-1"><h2 id="movements-title" class="text-lg font-semibold">Movimentações previstas</h2><p class="text-xs text-stone-500">São previsões, sem registrar vendas ou pagamentos realizados. A projeção não substitui conciliação financeira.</p></div>
        <div class="grid items-start gap-5 xl:grid-cols-[320px_1fr]">
            <form wire:submit="saveMovement" class="panel grid gap-4 p-5">
                <h3 class="font-semibold">{{ $editing_id ? 'Editar movimentação' : 'Nova movimentação' }}</h3>
                <x-field label="Tipo" for="direction"><select id="direction" wire:model="direction" class="field"><option value="outflow">Saída prevista</option><option value="inflow">Entrada prevista</option></select></x-field>
                <x-field label="Descrição" for="description"><input id="description" wire:model="description" class="field" maxlength="150" required></x-field>
                <x-field label="Valor (R$)" for="amount" hint="Informe o valor a receber ou pagar, com até 2 casas decimais."><input id="amount" wire:model="amount" class="field" inputmode="decimal" placeholder="0,00" required></x-field>
                <x-field label="Data prevista" for="expected_on"><input id="expected_on" wire:model="expected_on" class="field" type="date" required></x-field>
                <button class="btn-primary" wire:loading.attr="disabled" wire:target="saveMovement">{{ $editing_id ? 'Salvar alterações' : 'Adicionar previsão' }}</button>
                @if($editing_id)<button type="button" wire:click="cancelEdit" class="btn-secondary">Cancelar edição</button>@endif
            </form>
            <div class="panel overflow-hidden">
                @if($forecast['excluded_count'] > 0)<p class="border-b border-amber-200 bg-amber-50 p-4 text-xs text-amber-900">{{ $forecast['excluded_count'] }} lançamento(s) fora dos 56 dias. Permanecem salvos e não entram nos totais desta projeção.</p>@endif
                <ul class="divide-y divide-stone-100">
                    @forelse($this->movements as $movement)
                        @php($outside = $movement->expected_on->toDateString() < $forecast['starts_on'] || $movement->expected_on->toDateString() > $forecast['ends_on'])
                        <li wire:key="movement-{{ $movement->id }}" class="flex flex-wrap items-center justify-between gap-4 p-5">
                            <div class="grid min-w-0 gap-1"><p class="break-words text-sm font-medium">{{ $movement->description }}</p><p class="text-xs text-stone-500">{{ $movement->direction === 'inflow' ? 'Entrada prevista' : 'Saída prevista' }} · {{ $movement->expected_on->format('d/m/Y') }}</p>@if($outside)<span class="text-xs font-medium text-amber-800">Fora do período · excluído dos totais</span>@endif</div>
                            <div class="flex flex-wrap items-center gap-3"><x-money :value="$movement->amount" class="text-sm font-semibold"/><button type="button" wire:click="editMovement({{ $movement->id }})" class="btn-secondary" aria-label="Editar {{ $movement->description }}">Editar</button><button type="button" wire:click="deleteMovement({{ $movement->id }})" wire:confirm="Remover esta movimentação prevista?" wire:loading.attr="disabled" class="text-sm text-red-700 underline" aria-label="Remover {{ $movement->description }}">Remover</button></div>
                        </li>
                    @empty
                        <li class="empty-state"><h3 class="font-semibold">Comece pelas previsões de pagamento e recebimento</h3><p class="max-w-sm text-sm text-stone-500">Adicione cada valor na data em que espera pagar ou receber. Nenhuma taxa ou recorrência é aplicada automaticamente.</p></li>
                    @endforelse
                </ul>
            </div>
        </div>
    </section>
</div>
