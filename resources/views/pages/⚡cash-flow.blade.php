<?php

use App\Models\CashMovement;
use App\Models\CashProjection;
use App\Services\CashFlowIntegration;
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
    public string $nature = 'other';
    public bool $is_realized = false;
    public bool $manual_review_confirmed = false;
    public bool $activation_confirmed = false;
    public array $replacement_sales = [];
    #[Locked]
    public array $manual_decisions_open = [];
    #[Locked]
    public string $integration_preview_hash = '';

    public bool $show_movement_form = false;

    public string $opening_balance = '0,00';

    public string $starts_on = '';

    public string $description = '';

    public string $direction = 'outflow';

    public string $amount = '';

    public string $expected_on = '';

    protected CashFlowIntegration $integration;

    public function boot(CashFlowIntegration $integration): void
    {
        $this->integration = $integration;
    }

    #[Locked]
    public ?int $editing_id = null;

    #[Locked]
    public string $submission_key = '';

    public function mount(): void
    {
        $projection = CashProjection::query()->firstOrCreate(['id' => 1], [
            'opening_balance' => '0.00', 'starts_on' => now(CashFlowIntegration::TIMEZONE)->toDateString(),
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
        unset($this->projection, $this->forecast, $this->cashData, $this->visual);
        $this->integration_preview_hash = '';
        $this->activation_confirmed = false;
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
            'nature' => ['required', Rule::in(['other', 'capital_contribution'])],
            'is_realized' => ['boolean'],
        ], [
            'amount.regex' => 'Informe um valor em reais, com até 12 dígitos e 2 casas decimais.',
            'amount.gt' => 'O valor deve ser maior que zero.',
        ], ['description' => 'descrição', 'direction' => 'tipo', 'amount' => 'valor', 'expected_on' => 'data prevista']);
        if ($this->is_realized) {
            $this->validate(['expected_on' => ['before_or_equal:'.now(CashFlowIntegration::TIMEZONE)->toDateString()]]);
        }
        if ($this->nature === 'capital_contribution') {
            $this->validate(['direction' => [Rule::in(['inflow'])]], ['direction.in' => 'Aporte de capital deve ser uma entrada.']);
        }
        unset($validated['is_realized']);
        $validated['realized_on'] = $this->is_realized ? $this->expected_on : null;
        \Illuminate\Support\Facades\DB::transaction(function () use ($validated): void {
            $projection = CashProjection::query()->lockForUpdate()->findOrFail(1);
            if ($projection->sales_integration_enabled) {
                $this->validate(['manual_review_confirmed' => ['accepted']], ['manual_review_confirmed.accepted' => 'Confirme que este lançamento é independente dos registros automáticos de Vendas.']);
            }
            $movements = $projection->movements();
            $movement = $this->editing_id !== null ? $movements->lockForUpdate()->findOrFail($this->editing_id)
                : $movements->firstOrNew(['submission_key' => $this->submission_key]);
            if ($this->editing_id === null && $movement->exists) {
                return;
            }
            $movement->fill($validated);
            $movement->integration_review = $this->manual_review_confirmed ? 'independent' : 'unreviewed';
            $movement->replacement_sale_id = null;
            $movement->integration_reviewed_at = $this->manual_review_confirmed ? now() : null;
            $movement->save();
        });
        $this->cancelEdit();
        unset($this->movements, $this->forecast, $this->cashData, $this->visual);
        $this->integration_preview_hash = '';
        $this->activation_confirmed = false;
        session()->flash('success', 'Movimentação manual salva.');
    }

    public function editMovement(int $id): void
    {
        $movement = CashProjection::query()->findOrFail(1)->movements()->findOrFail($id);
        $this->resetValidation();
        $this->show_movement_form = true;
        $this->editing_id = $movement->id;
        $this->description = $movement->description;
        $this->direction = $movement->direction;
        $this->amount = DecimalPresentation::format($movement->amount, 2);
        $this->expected_on = ($movement->realized_on ?? $movement->expected_on)->toDateString();
        $this->nature = $movement->nature;
        $this->is_realized = $movement->realized_on !== null;
        $this->manual_review_confirmed = false;
        $this->dispatch('cash-movement-opened');
    }

    public function deleteMovement(int $id): void
    {
        CashProjection::query()->findOrFail(1)->movements()->findOrFail($id)->delete();
        if ($this->editing_id === $id) {
            $this->cancelEdit();
        }
        unset($this->movements, $this->forecast, $this->cashData, $this->visual);
        $this->integration_preview_hash = '';
        $this->activation_confirmed = false;
        session()->flash('success', 'Movimentação manual removida.');
    }

    public function newMovement(): void
    {
        $this->cancelEdit();
        $this->show_movement_form = true;
        $this->dispatch('cash-movement-opened');
    }

    /** @return array{inflows: string, outflows: string, chart: array<string, mixed>} */
    #[Computed]
    public function visual(): array
    {
        $forecast = $this->forecast;

        return [
            'inflows' => (string) \Brick\Math\BigDecimal::sum(...array_column($forecast['weeks'], 'inflows')),
            'outflows' => (string) \Brick\Math\BigDecimal::sum(...array_column($forecast['weeks'], 'outflows')),
            'chart' => app(\App\Services\DashboardPresentation::class)->chart($forecast),
        ];
    }

    public function cancelEdit(): void
    {
        $this->show_movement_form = false;
        $this->reset(['editing_id', 'description', 'direction', 'amount', 'expected_on', 'nature', 'is_realized', 'manual_review_confirmed']);
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
        return $this->cashData['manuals'];
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function forecast(): array
    {
        return $this->cashData['forecast'];
    }
    /** @return array<string, mixed> */
    #[Computed]
    public function cashData(): array
    {
        $data = $this->integration->snapshot($this->projection, ! $this->projection->sales_integration_enabled);
        if ($data['legacy_forecast'] !== null) {
            $data['preview'] = $data;
            $data['enabled'] = false;
            $data['forecast'] = $data['legacy_forecast'];
            $data['entries'] = $data['legacy_entries'];
        }

        return $data;
    }

    public function rendering(): void
    {
        if (! $this->cashData['enabled']) {
            $fingerprint = $this->integrationPreview['fingerprint'];
            if ($this->integration_preview_hash !== $fingerprint) {
                $this->activation_confirmed = false;
                $this->integration_preview_hash = $fingerprint;
            }
        }
    }

    public function changeManualDecision(int $id): void
    {
        $manual = $this->projection->movements()->findOrFail($id);
        $this->manual_decisions_open[$id] = true;
        $this->replacement_sales[$id] = $manual->replacement_sale_id;
    }

    public function cancelManualDecision(int $id): void
    {
        unset($this->manual_decisions_open[$id]);
        $this->resetValidation();
    }

    /** @return Collection<int, \App\Models\Sale> */
    #[Computed]
    public function reviewSales(): Collection
    {
        return \App\Models\Sale::query()->orderByDesc('sold_on')->orderByDesc('id')->get(['id', 'order_reference', 'marketplace']);
    }

    public function reviewMovement(int $id, string $review): void
    {
        $saleId = $this->replacement_sales[$id] ?? null;
        $this->integration->reviewManual($this->projection, $id, $review, $saleId === null || $saleId === '' ? null : (int) $saleId);
        unset($this->manual_decisions_open[$id]);
        $this->integration_preview_hash = '';
        $this->activation_confirmed = false;
        unset($this->cashData, $this->forecast, $this->visual, $this->movements);
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function integrationPreview(): array
    {
        return $this->cashData['preview'] ?? $this->cashData;
    }

    public function activateIntegration(): void
    {
        $this->validate(['activation_confirmed' => ['accepted']]);
        $this->integration->activate($this->projection, $this->integration_preview_hash);
        $this->integration_preview_hash = '';
        unset($this->projection, $this->cashData, $this->forecast, $this->visual);
        session()->flash('success', 'Integração ativada após revisão. Saldo inicial preservado.');
    }
};
?>
<div class="cash-flow-page grid gap-3" @if(!$this->projection->sales_integration_enabled) wire:poll.visible.30s @endif x-data="{}" x-on:cash-movement-opened.window="$nextTick(() => $refs.movementForm?.focus())">
    @php($forecast = $this->forecast)
    @php($visual = $this->visual)
    @php($cashData = $this->cashData)
    <x-errors/>
    <x-success/>
    <header class="flex flex-wrap items-center justify-between gap-4">
        <div><h1 class="page-title">Fluxo de Caixa</h1><p class="mt-2 text-sm text-stone-500">Planeje entradas e saídas nas próximas oito semanas.</p></div>
        <div class="flex flex-wrap items-center gap-3"><span class="rounded-lg border border-stone-200 bg-white px-4 py-2 text-sm">8 semanas</span><button type="button" wire:click="newMovement" @if($show_movement_form) wire:confirm="Descartar o formulário atual e iniciar uma nova movimentação?" @endif class="btn-primary">+ Nova movimentação</button></div>
    </header>
    <section aria-label="Resumo financeiro" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="cash-flow-stat"><span class="cash-flow-icon"><x-ui-icon name="bank"/></span><div><h2>Saldo inicial</h2><p><x-money :value="$this->projection->opening_balance" :class="str_starts_with($this->projection->opening_balance, '-') ? 'text-red-700' : 'text-cajubi-800'"/></p><small>{{ $this->projection->starts_on->format('d/m/Y') }}</small></div></div>
        <div class="cash-flow-stat"><span class="cash-flow-icon cash-flow-out">↓</span><div><h2>Saídas no período</h2><p class="text-red-700"><x-money :value="$visual['outflows']"/></p><small>Realizadas: <x-money :value="$forecast['realized_outflows']"/> · Previstas: <x-money :value="$forecast['planned_outflows']"/></small></div></div>
        <div class="cash-flow-stat"><span class="cash-flow-icon cash-flow-in">↑</span><div><h2>Entradas no período</h2><p class="text-blue-700"><x-money :value="$visual['inflows']"/></p><small>Realizadas: <x-money :value="$forecast['realized_inflows']"/> · Previstas: <x-money :value="$forecast['planned_inflows']"/></small></div></div>
        <div class="cash-flow-stat cash-flow-closing"><span class="cash-flow-icon"><x-ui-icon name="chart"/></span><div><h2>Saldo final projetado</h2><p><x-money :value="$forecast['closing']" :class="str_starts_with($forecast['closing'], '-') ? 'text-red-700' : 'text-cajubi-800'"/></p><small>Ao final das oito semanas</small></div></div>
    </section>
    <details class="panel p-4" @if(!$cashData['enabled'] || $cashData['reviews']->isNotEmpty()) open @endif>
        <summary class="cursor-pointer text-sm font-semibold text-cajubi-800">{{ $cashData['enabled'] ? 'Integração ativa · Revisões e origens' : 'Integração com Vendas · Confira a prévia' }}@if($cashData['reviews']->isNotEmpty()) · {{ $cashData['reviews']->count() }} revisão(ões) pendente(s) @endif</summary>
        <div class="mt-4 grid gap-3">
            @if($cashData['reviews']->isNotEmpty())<p class="text-sm text-amber-900">Manuais sem revisão não são somados enquanto a integração estiver ativa.</p>@endif
            @foreach($this->movements as $manual)
                @php($reviewOpen = $manual->integration_review === 'unreviewed' || isset($manual_decisions_open[$manual->id]))
                <div wire:key="review-{{ $manual->id }}" class="grid gap-3 rounded-lg border border-stone-200 p-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div><strong class="text-sm">{{ $manual->description }}</strong><span class="ml-2 text-sm text-stone-500">{{ ($manual->realized_on ?? $manual->expected_on)->format('d/m/Y') }} · <x-money :value="$manual->amount"/></span></div>
                        @if($manual->integration_review !== 'unreviewed')
                            <div class="flex flex-wrap items-center gap-3 text-sm"><span class="rounded-md bg-cajubi-50 px-2 py-1 text-cajubi-800">Revisado · {{ $manual->integration_review === 'independent' ? 'Independente' : 'Substituído pela venda' }}</span>@if(!$reviewOpen)<button type="button" wire:click="changeManualDecision({{ $manual->id }})" class="text-cajubi-800 underline">Alterar decisão</button>@endif @if($manual->replacement_sale_id)<a href="{{ route('sales', ['pedido' => $manual->replacement_sale_id]) }}" wire:navigate class="text-cajubi-800 underline">Ver origem</a>@endif</div>
                        @endif
                    </div>
                    @if($reviewOpen)
                        <div class="flex flex-wrap items-end gap-3">
                            <button type="button" wire:click="reviewMovement({{ $manual->id }}, 'independent')" wire:confirm="Confirmar que este lançamento não repete valores de Vendas?" wire:loading.attr="disabled" class="rounded-lg border border-cajubi-700 px-3 py-2 text-sm font-medium text-cajubi-800 focus-visible:outline-2">Independente</button>
                            <div class="min-w-0 flex-1"><x-field label="Venda que substitui o manual" for="replacement_sales.{{ $manual->id }}"><select id="replacement_sales.{{ $manual->id }}" wire:model="replacement_sales.{{ $manual->id }}" class="field"><option value="">Selecione a venda</option>@foreach($this->reviewSales as $reviewSale)<option value="{{ $reviewSale->id }}">{{ $reviewSale->order_reference }} · {{ $reviewSale->marketplace }}</option>@endforeach</select></x-field></div>
                            <button type="button" wire:click="reviewMovement({{ $manual->id }}, 'replaced')" wire:confirm="Preservar este manual sem somá-lo, usando os registros da venda selecionada?" wire:loading.attr="disabled" class="rounded-lg bg-cajubi-700 px-3 py-2 text-sm font-medium text-white focus-visible:outline-2">Substituir pela venda</button>
                            @if($manual->integration_review !== 'unreviewed')<button type="button" wire:click="cancelManualDecision({{ $manual->id }})" class="text-sm text-stone-600 underline">Cancelar</button>@endif
                        </div>
                    @endif
                    @if(!$cashData['entries']->contains('key', 'manual:'.$manual->id))
                        <div class="flex flex-wrap items-center gap-3 text-sm text-stone-500"><span>{{ ($manual->realized_on ?? $manual->expected_on)->toDateString() < $forecast['starts_on'] || ($manual->realized_on ?? $manual->expected_on)->toDateString() > $forecast['ends_on'] ? 'Fora do período' : 'Não somado à projeção' }}</span><button type="button" wire:click="editMovement({{ $manual->id }})" @if($show_movement_form) wire:confirm="Descartar o formulário atual para editar esta movimentação?" @endif class="text-cajubi-800 underline">Editar</button><button type="button" wire:click="deleteMovement({{ $manual->id }})" wire:confirm="Remover este lançamento manual?" wire:loading.attr="disabled" class="text-red-700 underline">Remover</button></div>
                    @endif
                </div>
            @endforeach
            @if(!$cashData['enabled'])
                @php($previewData = $this->integrationPreview)
                @php($preview = $previewData['forecast'])
                <div class="grid gap-3 rounded-lg bg-cajubi-50 p-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                    <div>Saldo final atual<strong class="mt-1 block"><x-money :value="$forecast['closing']"/></strong></div>
                    <div>Saldo final integrado<strong class="mt-1 block text-lg text-cajubi-800"><x-money :value="$preview['closing']"/></strong></div>
                    <div>Entradas realizadas / previstas<strong class="mt-1 block"><x-money :value="$preview['realized_inflows']"/> / <x-money :value="$preview['planned_inflows']"/></strong></div>
                    <div>Saídas realizadas / previstas<strong class="mt-1 block"><x-money :value="$preview['realized_outflows']"/> / <x-money :value="$preview['planned_outflows']"/></strong></div>
                </div>
                <details class="text-sm"><summary class="cursor-pointer text-cajubi-800 underline">Ver detalhes da prévia</summary><div class="mt-3 grid gap-2">@foreach($previewData['entries'] as $previewEntry)<p wire:key="preview-{{ $previewEntry->key }}">{{ $previewEntry->date->format('d/m/Y') }} · {{ $previewEntry->description }} · {{ $previewEntry->stage === 'realized' ? 'Realizada' : 'Prevista' }} · <x-money :value="$previewEntry->amount"/></p>@endforeach @foreach($previewData['pending'] as $previewPending)<p wire:key="preview-pending-{{ $previewPending['sale_id'] }}" class="text-amber-900">Não alocado: {{ $previewPending['reference'] }} · {{ $previewPending['reason'] }}</p>@endforeach</div></details>
                @if($cashData['reviews']->isEmpty())
                    <label class="flex items-start gap-2 text-sm"><input type="checkbox" wire:model="activation_confirmed" class="mt-1">Revisei a prévia e o saldo inicial antes das operações da data-base.</label>
                    <button type="button" wire:click="activateIntegration" wire:loading.attr="disabled" wire:confirm="Ativar a projeção dinâmica com os dados revisados, preservando o saldo inicial?" class="btn-primary justify-self-start">Ativar integração revisada</button>
                @else
                    <p class="text-sm text-amber-900">Conclua as revisões para ativar. A prévia é atualizada automaticamente.</p>
                @endif
            @endif
        </div>
    </details>
    @if(\Brick\Math\BigDecimal::of($forecast['capital_inflows'])->isPositive())<p class="text-sm text-stone-600">Aportes de capital no período: <x-money :value="$forecast['capital_inflows']"/>. Já incluídos nas entradas; não são receitas de vendas.</p>@endif
    <div class="cash-flow-central">
        <div class="cash-flow-column">
            <section class="panel grid gap-4 p-5" aria-labelledby="parameters-title">
                <h2 id="parameters-title" class="text-lg font-semibold">Ponto de partida</h2>
        <form wire:submit="saveParameters" class="grid items-start gap-4 sm:grid-cols-[1fr_1fr_auto]">
            <x-field label="Saldo inicial disponível (R$)" for="opening_balance">
                <input id="opening_balance" wire:model="opening_balance" class="field" inputmode="decimal" required>
            </x-field>
            <x-field label="Data inicial" for="starts_on">
                <input id="starts_on" wire:model="starts_on" class="field" type="date" required>
            </x-field>
            <button class="btn-primary sm:mt-7" wire:loading.attr="disabled" wire:target="saveParameters">Salvar parâmetros</button>
        </form>
                <p class="text-sm text-stone-500">Dinheiro disponível antes das movimentações da data inicial. Alterar o período preserva os lançamentos.</p>
                <p wire:dirty wire:target="opening_balance,starts_on" class="text-sm text-amber-800">Há parâmetros não salvos.</p>
            </section>
    <section class="panel cash-flow-evolution min-w-0 p-5" aria-labelledby="evolution-title">
        <h2 id="evolution-title" class="text-lg font-semibold">Evolução do saldo</h2>
        <x-cash-chart :chart="$visual['chart']" :expanded="true"/>
        <p class="text-xs leading-5 text-stone-500">Saldo inicial e fechamentos semanais; entradas e saídas da mesma data são somadas.</p>
    </section>
        </div>
        <div class="cash-flow-column">
        <section class="panel grid min-w-0 gap-4 p-5" aria-labelledby="movements-title">
            <div class="flex flex-wrap items-center justify-between gap-3"><h2 id="movements-title" class="text-lg font-semibold">Movimentações do período</h2><button type="button" wire:click="newMovement" @if($show_movement_form) wire:confirm="Descartar o formulário atual e iniciar uma nova movimentação?" @endif class="btn-primary">+ Nova movimentação</button></div>
            @if($show_movement_form)
            <form x-ref="movementForm" tabindex="-1" wire:submit="saveMovement" class="grid gap-4 rounded-xl border border-cajubi-200 bg-cajubi-50/30 p-4">
                <h3 class="font-semibold">{{ $editing_id ? 'Editar movimentação' : 'Nova movimentação' }}</h3>
                <x-field label="Tipo" for="direction"><select id="direction" wire:model="direction" class="field"><option value="outflow">Saída</option><option value="inflow">Entrada</option></select></x-field>
                <x-field label="Descrição" for="description"><input id="description" wire:model="description" class="field" maxlength="150" required></x-field>
                <x-field label="Valor (R$)" for="amount" hint="Informe o valor a receber ou pagar, com até 2 casas decimais."><input id="amount" wire:model="amount" class="field" inputmode="decimal" placeholder="0,00" required></x-field>
                <x-field label="Data da movimentação" for="expected_on"><input id="expected_on" wire:model="expected_on" class="field" type="date" required></x-field>
                <x-field label="Natureza" for="nature"><select id="nature" wire:model="nature" class="field"><option value="other">Outra movimentação manual</option><option value="capital_contribution">Aporte de capital</option></select></x-field>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="is_realized">Movimentação já realizada na data informada</label>
                <label class="flex items-start gap-2 text-sm"><input type="checkbox" wire:model="manual_review_confirmed" class="mt-1">Confirmo que este lançamento é independente dos recebimentos, estornos e repasses automáticos de Vendas.</label>
                @error('manual_review_confirmed')<p role="alert" class="text-sm text-red-700">{{ $message }}</p>@enderror
                <button class="btn-primary" wire:loading.attr="disabled" wire:target="saveMovement">{{ $editing_id ? 'Salvar alterações' : 'Adicionar previsão' }}</button>
                <button type="button" wire:click="cancelEdit" wire:confirm="Descartar as alterações deste formulário?" class="btn-secondary">Cancelar</button>
            </form>
            @endif
            @if($forecast['excluded_count'] > 0)<p class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">{{ $forecast['excluded_count'] }} lançamento(s) fora dos 56 dias. Permanecem salvos e não entram nos totais desta projeção.</p>@endif
            <div class="overflow-x-auto rounded-lg border border-stone-200">
                <table class="w-full text-left text-sm">
                    <thead class="table-head"><tr><th scope="col" class="px-3 py-3">Data</th><th scope="col" class="px-3 py-3">Descrição</th><th scope="col" class="px-3 py-3">Tipo</th><th scope="col" class="px-3 py-3 text-right">Valor</th><th scope="col" class="px-3 py-3"><span class="sr-only">Ações</span></th></tr></thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse($cashData['entries'] as $entry)
                            <tr wire:key="cash-entry-{{ $entry->key }}">
                                <td class="whitespace-nowrap px-3 py-3">{{ $entry->date->format('d/m/Y') }}</td>
                                <th scope="row" class="min-w-40 max-w-72 px-3 py-3 font-medium"><span class="line-clamp-2" title="{{ $entry->description }}">{{ $entry->description }}</span><span class="block text-xs font-normal text-stone-500">{{ $entry->source === 'manual' ? 'Manual' : 'Vendas' }} · {{ $entry->stage === 'realized' ? 'Realizada' : 'Prevista' }}@if($entry->nature === 'capital_contribution') · Aporte de capital @endif @if($entry->stage === 'planned' && $entry->date->toDateString() < now(\App\Services\CashFlowIntegration::TIMEZONE)->toDateString()) · Atrasada: data original preservada @endif</span></th>
                                <td class="px-3 py-3"><span @class(['inline-block rounded-md px-2 py-1 text-xs font-medium', 'bg-red-50 text-red-700' => $entry->direction === 'outflow', 'bg-blue-50 text-blue-700' => $entry->direction === 'inflow'])>{{ $entry->direction === 'inflow' ? 'Entrada' : 'Saída' }}</span></td>
                                <td class="whitespace-nowrap px-3 py-3 text-right font-semibold"><x-money :value="$entry->amount"/></td>
                                <td class="px-3 py-3">@if($entry->source === 'manual')<div class="flex gap-3"><button type="button" wire:click="editMovement({{ $entry->source_id }})" @if($show_movement_form) wire:confirm="Descartar o formulário atual para editar esta movimentação?" @endif class="text-cajubi-700 underline" aria-label="Editar {{ $entry->description }}">Editar</button><button type="button" wire:click="deleteMovement({{ $entry->source_id }})" wire:confirm="Remover este lançamento manual?" wire:loading.attr="disabled" class="text-red-700 underline" aria-label="Remover {{ $entry->description }}">Remover</button></div>@else<a href="{{ route('sales', ['pedido' => $entry->sale_id]) }}" wire:navigate class="text-cajubi-800 underline">Ver origem</a>@endif</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-8 text-center text-stone-500">Nenhuma movimentação no período.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="text-sm text-stone-500">Registros de Vendas são automáticos e editados na origem. Manuais não são associados por semelhança.</p>
        </section>
            <section class="panel p-3" aria-labelledby="minimum-title">
                <div @class(['cash-flow-minimum', 'cash-flow-minimum-negative' => $forecast['first_shortfall'] !== null])>
                    <h2 id="minimum-title">Menor saldo do período</h2>
                    <p class="cash-flow-minimum-value"><x-money :value="$forecast['minimum']"/></p>
                    <p class="cash-flow-capital">Capital adicional necessário: <strong><x-money :value="$forecast['additional_capital']"/></strong></p>
                    @if($forecast['first_shortfall'])<p class="mt-2 text-sm">Primeira falta de caixa: <strong>{{ \Carbon\CarbonImmutable::parse($forecast['first_shortfall'])->format('d/m/Y') }}</strong></p>@endif
                    <p class="cash-flow-minimum-note">Menor valor encontrado entre os saldos diários das próximas 8 semanas.</p>
                </div>
            </section>
        </div>
    </div>

    @if($cashData['enabled'] && $cashData['pending'] !== [])
        <section class="panel grid gap-3 border-amber-200 p-5" aria-label="Pendências sem previsão confiável"><h2 class="font-semibold text-amber-900">Pendências sem previsão confiável</h2><p class="text-sm text-stone-600">Não entram no saldo projetado. Revise na venda de origem; valores desconhecidos não são zero.</p>@foreach($cashData['pending'] as $pending)<div wire:key="cash-pending-{{ $pending['sale_id'] }}" class="flex flex-wrap items-center justify-between gap-3 border-t border-stone-100 pt-3 text-sm"><span>{{ $pending['reference'] }} · {{ $pending['reason'] }} · @if($pending['amount'] !== null)<x-money :value="$pending['amount']"/>@else Valor desconhecido @endif</span><a href="{{ route('sales', ['pedido' => $pending['sale_id']]) }}" wire:navigate class="text-cajubi-800 underline">Revisar venda</a></div>@endforeach</section>
    @endif
    <section class="panel min-w-0 overflow-hidden" aria-labelledby="projection-title">
        <div class="grid gap-1 p-5"><h2 id="projection-title" class="text-lg font-semibold">Projeção semanal</h2><p class="text-sm text-stone-500">Oito semanas · {{ \Carbon\CarbonImmutable::parse($forecast['starts_on'])->format('d/m/Y') }} a {{ \Carbon\CarbonImmutable::parse($forecast['ends_on'])->format('d/m/Y') }}</p></div>
        <div class="overflow-x-auto">
            <table class="w-full whitespace-nowrap text-sm">
                <thead class="table-head"><tr><th class="px-5 py-3">Semana / período</th><th class="px-5 py-3 text-right">Saldo inicial</th><th class="px-5 py-3 text-right">Entradas</th><th class="px-5 py-3 text-right">Saídas</th><th class="px-5 py-3 text-right">Saldo final</th></tr></thead>
                <tbody class="divide-y divide-stone-100">
                    @foreach($forecast['weeks'] as $week)
                        <tr wire:key="week-{{ $week['number'] }}" @class(['bg-red-50 text-red-900' => $week['negative']])>
                            <th scope="row" class="px-5 py-3 text-left font-medium">Semana {{ $week['number'] }}<span class="mt-1 block text-xs font-normal">{{ $week['starts_on'] }} a {{ $week['ends_on'] }}</span>@if($week['negative'])<span class="mt-1 block text-xs">Falta de caixa · mínimo <x-money :value="$week['minimum']"/></span>@endif</th>
                            <td class="px-5 py-3 text-right"><x-money :value="$week['opening']"/></td><td class="px-5 py-3 text-right"><x-money :value="$week['inflows']"/><small class="mt-1 block text-xs text-stone-500">Realizadas: <x-money :value="$week['realized_inflows']"/> · Previstas: <x-money :value="$week['planned_inflows']"/></small></td><td class="px-5 py-3 text-right"><x-money :value="$week['outflows']"/><small class="mt-1 block text-xs text-stone-500">Realizadas: <x-money :value="$week['realized_outflows']"/> · Previstas: <x-money :value="$week['planned_outflows']"/></small></td><td class="px-5 py-3 text-right font-semibold"><x-money :value="$week['closing']"/></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

    </section>
</div>
