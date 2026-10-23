<?php

use App\Models\TariffProfile;
use App\Services\DecimalPresentation;
use App\Services\TariffCatalog;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Validator;

new #[Title('Regra de tarifa')] class extends Component
{
    use WithPagination;

    #[Locked]
    public ?TariffProfile $profile = null;

    public string $channel = '';
    public string $category = '';
    public string $listing_type = '';
    public string $commission_percent = '';
    public string $fixed_fee = '';
    public string $pricing_model = 'single';
    public string $charge_basis = 'item';
    public array $tiers = [];
    /** @var array{min_price: string, max_price: string, commission_percent: string, fixed_fee: string} */
    public array $tierDraft = ['min_price' => '', 'max_price' => '', 'commission_percent' => '', 'fixed_fee' => ''];
    #[Locked]
    public ?int $editingTier = null;
    public bool $tiersChanged = false;
    #[Locked]
    public string $tierMarketplace = 'mercado_livre';
    public string $conditions = '';
    public bool $conditions_verified = false;
    public string $valid_from = '';
    public string $valid_until = '';
    public string $review_on = '';
    public string $observed_on = '';
    public string $source = '';
    public string $notes = '';
    public string $confidence = 'estimated';
    public bool $is_active = true;
    protected TariffCatalog $catalog;

    public function boot(TariffCatalog $catalog): void
    {
        $this->catalog = $catalog;
    }

    public function mount(?TariffProfile $profile = null, string $marketplace = 'mercado_livre'): void
    {
        $this->channel = in_array($marketplace, ['mercado_livre', 'shopee'], true) ? $marketplace : 'mercado_livre';
        if ($profile?->exists) {
            $this->profile = $profile;
            foreach (['channel', 'category', 'listing_type', 'conditions', 'source', 'notes', 'confidence', 'pricing_model', 'charge_basis'] as $field) {
                $this->$field = $profile->$field ?? '';
            }
            foreach (['conditions_verified', 'is_active'] as $field) {
                $this->$field = $profile->$field;
            }
            foreach (['valid_from', 'valid_until', 'review_on', 'observed_on'] as $field) {
                $this->$field = $profile->$field?->format('Y-m-d') ?? '';
            }
            $this->commission_percent = $profile->commission_percent !== null ? str_replace('.', ',', $profile->commission_percent) : '';
            $this->fixed_fee = $profile->fixed_fee !== null ? str_replace('.', ',', $profile->fixed_fee) : '';
            $this->tiers = array_map(fn ($tier) => array_map(fn ($value) => $value === null ? '' : str_replace('.', ',', $value), $tier), $profile->tiers ?? []);
        }
        $this->tierMarketplace = $this->channel;
    }

    public function updatedChannel(): void
    {
        if ($this->profile) { $this->channel = $this->profile->channel; return; }
        if ($this->hasPendingTier() || $this->tiersChanged) {
            $this->channel = $this->tierMarketplace;
            $this->addError('tierDraft', 'Salve a regra ou descarte as alterações antes de trocar o marketplace.');
            return;
        }
        $this->reset('category', 'listing_type', 'commission_percent', 'fixed_fee', 'pricing_model', 'charge_basis', 'tiers', 'conditions', 'conditions_verified', 'valid_from', 'valid_until', 'review_on', 'observed_on', 'source', 'notes', 'confidence', 'is_active');
        $this->resetValidation();
        $this->tierMarketplace = $this->channel;
        $this->dispatch('tariff-marketplace-changed', channel: $this->channel);
    }

    public function updatedPricingModel(): void
    {
        if ($this->hasPendingTier() && $this->pricing_model !== 'tiered') {
            $this->pricing_model = 'tiered';
            $this->addError('tierDraft', 'Conclua ou cancele a faixa em edição antes de alterar o tipo de tarifa.');
        }
    }

    public function addTier(): void
    {
        $normalized = array_map(fn ($value) => $value === '' ? null : DecimalPresentation::input($value), $this->tierDraft);
        Validator::make(['tierDraft' => $normalized], [
            'tierDraft' => ['array:min_price,max_price,commission_percent,fixed_fee'],
            'tierDraft.min_price' => ['required', 'string', 'regex:/\A[0-9]{1,10}(\.[0-9]{1,2})?\z/'],
            'tierDraft.max_price' => ['nullable', 'string', 'regex:/\A[0-9]{1,10}(\.[0-9]{1,2})?\z/'],
            'tierDraft.commission_percent' => ['nullable', 'string', 'regex:/\A[0-9]{1,3}(\.[0-9]{1,4})?\z/'],
            'tierDraft.fixed_fee' => ['nullable', 'string', 'regex:/\A[0-9]{1,10}(\.[0-9]{1,4})?\z/'],
        ], attributes: ['tierDraft.min_price' => 'preço inicial', 'tierDraft.max_price' => 'preço final', 'tierDraft.commission_percent' => 'comissão', 'tierDraft.fixed_fee' => 'tarifa fixa'])->validate();

        if ($this->editingTier !== null) {
            abort_unless(array_key_exists($this->editingTier, $this->tiers), 422);
            $this->tiers[$this->editingTier] = $this->tierDraft;
        } else {
            $this->tiers[] = $this->tierDraft;
        }
        $this->tiersChanged = true;
        $this->cancelTier();
    }

    public function editTier(int $index): void
    {
        if ($this->hasPendingTier()) {
            $this->addError('tierDraft', 'Conclua ou cancele a faixa em edição antes de selecionar outra.');
            return;
        }
        abort_unless(array_key_exists($index, $this->tiers), 422);
        $this->tierDraft = $this->tiers[$index];
        $this->editingTier = $index;
        $this->resetValidation();
    }

    public function cancelTier(): void
    {
        $this->reset('tierDraft', 'editingTier');
        $this->resetValidation();
    }

    private function hasPendingTier(): bool
    {
        return $this->editingTier !== null || array_any($this->tierDraft, fn ($value) => $value !== '');
    }

    public function removeTier(int $index): void
    {
        if ($this->hasPendingTier()) {
            $this->addError('tierDraft', 'Conclua ou cancele a faixa em edição antes de remover uma faixa.');
            return;
        }
        unset($this->tiers[$index]);
        $this->tiers = array_values($this->tiers);
        $this->tiersChanged = true;
        $this->resetValidation();
    }

    public function save(): void
    {
        if ($this->hasPendingTier()) {
            $this->addError('tierDraft', 'Adicione ou atualize a faixa em edição, ou cancele-a, antes de salvar a regra.');
            return;
        }
        $profile = $this->catalog->save([
            'channel' => $this->channel, 'category' => $this->category, 'listing_type' => $this->listing_type,
            'pricing_model' => $this->pricing_model, 'charge_basis' => $this->charge_basis,
            'tiers' => $this->pricing_model === 'tiered' ? array_map(fn ($tier) => array_map(fn ($value) => $value === '' ? null : DecimalPresentation::input($value), $tier), $this->tiers) : null,
            'commission_percent' => $this->commission_percent === '' ? null : DecimalPresentation::input($this->commission_percent),
            'fixed_fee' => $this->fixed_fee === '' ? null : DecimalPresentation::input($this->fixed_fee),
            'conditions' => $this->conditions, 'conditions_verified' => $this->conditions_verified,
            'valid_from' => $this->valid_from, 'valid_until' => $this->valid_until,
            'review_on' => $this->review_on, 'observed_on' => $this->observed_on,
            'source' => $this->source, 'notes' => $this->notes,
            'confidence' => $this->confidence, 'is_active' => $this->is_active,
        ], $this->profile);
        $this->tiersChanged = false;
        session()->flash('success', 'Regra salva. O histórico de alterações foi preservado.');
        $this->redirectRoute('tariffs.edit', ['profile' => $profile], navigate: true);
    }

    public function deactivate(): void
    {
        if ($this->hasPendingTier() || $this->tiersChanged) {
            $this->addError('tierDraft', 'Salve a regra ou descarte as alterações de faixas antes de desativá-la.');
            return;
        }
        abort_unless($this->profile?->exists, 404);
        $this->catalog->setActive($this->profile, false);
        session()->flash('success', 'Regra desativada. O histórico foi preservado.');
        $this->redirectRoute('tariffs.edit', ['profile' => $this->profile], navigate: true);
    }

    #[Computed]
    public function warnings(): array
    {
        return $this->profile ? $this->catalog->warnings($this->profile) : [];
    }

    #[Computed]
    public function revisions(): ?\Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return $this->profile?->revisions()->orderByDesc('id')->paginate(10, ['*'], 'revisionsPage');
    }
};
?>

<div class="grid min-w-0 gap-4"
    x-data="{ hasTierChanges() { return this.$wire.tiersChanged || this.$wire.editingTier !== null || Object.values(this.$wire.tierDraft).some(value => value !== '') } }"
    x-on:beforeunload.window="if (hasTierChanges()) { $event.preventDefault(); $event.returnValue = '' }"
    x-on:livewire:navigate.document="if (hasTierChanges() && !window.confirm('Há alterações de faixas não salvas. Deseja sair e descartá-las?')) $event.preventDefault()"
    x-on:click.capture.document="if ($event.target.closest('[data-tariff-marketplace-switch]') && $event.target.closest('[data-tariff-marketplace-switch]').dataset.tariffMarketplaceSwitch !== $wire.channel && hasTierChanges() && !window.confirm('Há alterações de faixas não salvas. Deseja trocar de marketplace e descartá-las?')) { $event.preventDefault(); $event.stopImmediatePropagation() }">
    <form wire:submit="save" @class(['tariff-editor panel grid gap-4 border-t-3 p-4 sm:p-5', 'border-t-cajubi-700' => $profile !== null, 'border-t-cajubi-yellow' => $profile === null])>
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-100 pb-4">
            <div class="min-w-0"><div class="mb-1 flex items-center gap-2"><span @class(['rounded px-2 py-0.5 text-[9px] font-semibold uppercase tracking-wide', 'bg-cajubi-50 text-cajubi-700' => $profile !== null, 'bg-amber-50 text-amber-900' => $profile === null])>{{ $profile ? 'Em edição' : 'Novo cadastro' }}</span></div><h2 class="text-lg font-semibold leading-6 tracking-tight">{{ $profile ? 'Editar regra de tarifa' : 'Nova regra de tarifa' }}</h2><p class="mt-1 text-xs text-stone-500">{{ $channel === 'shopee' ? 'Shopee' : 'Mercado Livre' }}{{ $profile ? ' · '.$profile->listing_type : '' }}</p></div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="is_active" class="size-4 accent-cajubi-700"> Ativa</label>
        </div>
        <x-errors/>
        <div class="grid gap-3.5 sm:grid-cols-2">
            <x-field label="Marketplace *" for="channel"><select id="channel" wire:model.live="channel" class="field" required @disabled($profile !== null)><option value="mercado_livre">Mercado Livre</option><option value="shopee">Shopee</option></select></x-field>
            <x-field label="Modalidade de anúncio *" for="listing_type"><input id="listing_type" wire:model="listing_type" class="field" required maxlength="100" placeholder="Nome da modalidade"></x-field>
            <x-field label="Categoria *" for="category" class="sm:col-span-2"><input id="category" wire:model="category" class="field" required maxlength="255"></x-field>
        </div>
        <fieldset><legend class="mb-2 text-xs font-medium text-stone-700">Tipo de tarifa</legend><div class="grid gap-3 sm:grid-cols-2">
            @foreach(['single' => ['Tarifa única', 'Mesmo percentual e valor fixo para qualquer preço.'], 'tiered' => ['Tarifas por faixa de preço', 'Percentual e valor fixo conforme o preço do item.']] as $value => $option)
                <label @class(['flex cursor-pointer items-start gap-2 rounded-lg border p-3 text-sm', 'border-cajubi-700 bg-cajubi-50' => $pricing_model === $value, 'border-stone-200' => $pricing_model !== $value])><input type="radio" wire:model.live="pricing_model" value="{{ $value }}" class="mt-1 accent-cajubi-700"><span><span class="block font-medium">{{ $option[0] }}</span><span class="mt-1 block text-[11px] leading-4 text-stone-500">{{ $option[1] }}</span></span></label>
            @endforeach
        </div></fieldset>

        @if($pricing_model === 'single')
            <div class="grid gap-3.5 sm:grid-cols-2">
                <x-field label="Comissão (%)" for="commission_percent" hint="Em branco se desconhecida; 0 se não se aplicar."><input id="commission_percent" wire:model="commission_percent" class="field" inputmode="decimal"></x-field>
                <x-field label="Tarifa fixa (R$)" for="fixed_fee" hint="Em branco se desconhecida; 0 se não se aplicar."><input id="fixed_fee" wire:model="fixed_fee" class="field" inputmode="decimal"></x-field>
            </div>
        @else
            <section class="grid gap-3" aria-label="Faixas de preço">
                <div><h3 class="text-sm font-semibold">Faixas de preço</h3><p class="mt-1 text-xs leading-5 text-stone-500">Limites inclusivos, em centavos. A próxima faixa começa um centavo após a anterior. Limite final em branco somente na última faixa, para não limitar o preço. Valores fora da cobertura não serão calculados.</p></div>
                <div class="grid gap-3 rounded-lg border border-stone-200 bg-stone-50/50 p-4">
                    <h4 class="text-xs font-semibold">{{ $editingTier !== null ? 'Editar faixa '.($editingTier + 1) : 'Nova faixa' }}</h4>
                    <div class="grid gap-3 sm:grid-cols-2 2xl:grid-cols-4">
                        @foreach(['min_price' => 'Preço inicial (R$) *', 'max_price' => 'Preço final (R$)', 'commission_percent' => 'Comissão (%)', 'fixed_fee' => 'Tarifa fixa (R$)'] as $field => $label)
                            <x-field :label="$label" for="tierDraft.{{ $field }}"><input id="tierDraft.{{ $field }}" wire:model="tierDraft.{{ $field }}" class="field" inputmode="decimal"></x-field>
                        @endforeach
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" wire:click="addTier" wire:loading.attr="disabled" class="btn-secondary">{{ $editingTier !== null ? 'Atualizar faixa' : 'Adicionar faixa' }}</button>
                        <button type="button" wire:click="cancelTier" wire:confirm="Descartar os valores do editor de faixa? As faixas da tabela serão preservadas." class="text-xs font-medium text-stone-500 hover:text-cajubi-700">Cancelar faixa</button>
                    </div>
                    @error('tierDraft')<p role="alert" class="text-xs text-red-700">{{ $message }}</p>@enderror
                </div>
                <p class="text-xs text-stone-500">Comissão e tarifa fixa em branco permanecem desconhecidas. Zero deve ser explícito. As faixas da tabela serão gravadas juntas ao salvar a regra.</p>
                <div class="overflow-x-auto rounded-lg border border-stone-200" role="region" aria-label="Tabela de faixas adicionadas" tabindex="0">
                    <table class="w-full min-w-[560px] text-left text-xs">
                        <caption class="sr-only">Faixas adicionadas à regra em edição, com limites inclusivos.</caption>
                        <thead class="table-head"><tr><th scope="col" class="px-3 py-3">Preço inicial</th><th scope="col" class="px-3 py-3">Preço final</th><th scope="col" class="px-3 py-3">Comissão</th><th scope="col" class="px-3 py-3">Tarifa fixa</th><th scope="col" class="px-3 py-3">Ações</th></tr></thead>
                        <tbody class="divide-y divide-stone-100">
                            @forelse($tiers as $index => $tier)
                                <tr wire:key="tariff-tier-{{ $index }}" aria-label="Faixa {{ $index + 1 }}" @class(['bg-cajubi-50' => $editingTier === $index])>
                                    @foreach(['min_price', 'max_price', 'commission_percent', 'fixed_fee'] as $field)
                                        <td class="px-3 py-3 tabular-nums">
                                            @if(($tier[$field] ?? '') === ''){{ $field === 'max_price' ? 'Sem limite' : 'Desconhecida' }}
                                            @elseif($field === 'commission_percent'){{ $tier[$field] }}%
                                            @else R$ {{ $tier[$field] }} @endif
                                            @error('tiers.'.$index.'.'.$field)<p role="alert" class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
                                        </td>
                                    @endforeach
                                    <td class="px-3 py-3"><div class="flex gap-3"><button type="button" wire:click="editTier({{ $index }})" class="font-medium text-cajubi-700 hover:underline" aria-label="Editar faixa {{ $index + 1 }}">Editar</button><button type="button" wire:click="removeTier({{ $index }})" wire:confirm="Remover esta faixa da tabela? A exclusão será aplicada ao salvar a regra." class="text-stone-500 hover:text-red-700" aria-label="Remover faixa {{ $index + 1 }}">Remover</button></div></td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-3 py-5 text-center text-stone-500">Nenhuma faixa adicionada.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($tiersChanged)<p class="text-xs font-medium text-cajubi-700" role="status">Há alterações nas faixas ainda não salvas na regra.</p>@endif
            </section>
        @endif
        <div class="grid gap-3.5 sm:grid-cols-2">
            <x-field label="Classificação *" for="confidence"><select id="confidence" wire:model="confidence" class="field"><option value="estimated">Estimado</option><option value="confirmed">Confirmado</option></select></x-field>
            <x-field label="Cobrança da tarifa fixa" for="charge_basis" hint="Por pedido: informe o rateio na simulação."><select id="charge_basis" wire:model="charge_basis" class="field"><option value="item">Por item</option><option value="order">Por pedido</option></select></x-field>
        </div>
        <x-field label="Fonte *" for="source" hint="Origem da referência, sem verificação automática."><input id="source" wire:model="source" class="field" required maxlength="2000"></x-field>
        <details class="rounded-lg border border-stone-200 bg-stone-50/40 p-3.5">
            <summary class="cursor-pointer text-sm font-medium text-stone-700">Informações complementares <span class="mt-0.5 block text-[11px] font-normal text-stone-500">Vigência, condições, revisão e observações.</span></summary>
            <div class="mt-4 grid gap-3.5 sm:grid-cols-2">
                <x-field label="Data da consulta à fonte" for="observed_on"><input id="observed_on" wire:model="observed_on" class="field" type="date"></x-field>
                <x-field label="Revisar até" for="review_on"><input id="review_on" wire:model="review_on" class="field" type="date"></x-field>
                <x-field label="Início da vigência" for="valid_from"><input id="valid_from" wire:model="valid_from" class="field" type="date"></x-field>
                <x-field label="Fim da vigência" for="valid_until"><input id="valid_until" wire:model="valid_until" class="field" type="date"></x-field>
                <label class="flex items-center gap-2 text-sm sm:col-span-2"><input type="checkbox" wire:model="conditions_verified" class="accent-cajubi-700"> Condições verificadas para esta modalidade</label>
                <x-field label="Condições de aplicação" for="conditions" class="sm:col-span-2"><textarea id="conditions" wire:model="conditions" class="field" rows="3"></textarea></x-field>
                <x-field label="Observações" for="notes" class="sm:col-span-2"><textarea id="notes" wire:model="notes" class="field" rows="2"></textarea></x-field>
            </div>
        </details>
        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-stone-100 pt-4">
            @if($profile?->is_active)<button type="button" wire:click="deactivate" wire:confirm="Desativar esta regra? O histórico será preservado." class="mr-auto text-xs text-stone-500 hover:text-red-700" wire:loading.attr="disabled">Desativar regra</button>@endif
            <a href="{{ route('tariffs.index') }}" wire:navigate class="btn-secondary px-4! py-2! text-xs!">Cancelar</a>
            <button type="submit" wire:loading.attr="disabled" class="btn-primary px-4! py-2! text-xs!"><span wire:loading.remove wire:target="save">{{ $profile ? 'Salvar alterações' : 'Criar regra' }}</span><span wire:loading wire:target="save">Salvando…</span></button>
        </div>
    </form>
    @if($profile)
        <details class="panel overflow-hidden">
            <summary class="cursor-pointer px-4 py-3.5 text-sm font-semibold">Histórico de alterações <span class="mt-0.5 block text-[11px] font-normal text-stone-500">Revisões anteriores e detalhes das tarifas.</span></summary>
            <div class="divide-y divide-stone-100">
            @foreach($this->revisions as $revision)
                @php($snapshot = $revision->snapshot)
                <details wire:key="revision-{{ $revision->id }}" class="px-4 py-3">
                    <summary class="cursor-pointer text-xs font-medium leading-6 text-stone-700"><span class="mr-2 tabular-nums">{{ $revision->created_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</span><span @class(['mr-2 rounded px-1.5 py-0.5 text-[10px]', 'bg-cajubi-50 text-cajubi-700' => ($snapshot['confidence'] ?? '') === 'confirmed', 'bg-amber-50 text-amber-900' => ($snapshot['confidence'] ?? '') !== 'confirmed'])>{{ ($snapshot['confidence'] ?? '') === 'confirmed' ? 'Confirmado' : 'Estimado' }}</span><span class="text-stone-500">{{ ($snapshot['pricing_model'] ?? 'single') === 'tiered' ? 'Por faixa' : (isset($snapshot['commission_percent']) ? \App\Services\DecimalPresentation::format($snapshot['commission_percent'], 2).'%' : 'Comissão pendente') }} · {{ ($snapshot['is_active'] ?? false) ? 'Ativa' : 'Desativada' }}</span><span class="sr-only"> — Expandir detalhes da revisão</span></summary>
                    <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                        <div><dt class="text-xs text-stone-500">Tipo de tarifa / cobrança</dt><dd class="mt-1">{{ ($snapshot['pricing_model'] ?? 'single') === 'tiered' ? 'Por faixa de preço' : 'Tarifa única' }} · {{ ($snapshot['charge_basis'] ?? 'item') === 'order' ? 'Por pedido' : 'Por item' }}</dd></div>
                        @foreach($snapshot['tiers'] ?? [] as $tier)
                            <div class="rounded-lg bg-stone-50 p-3 sm:col-span-2"><dt class="text-xs text-stone-500">Faixa {{ $loop->iteration }}</dt><dd class="mt-1 text-xs leading-6">De <x-money :value="$tier['min_price']"/> até @if($tier['max_price'] !== null)<x-money :value="$tier['max_price']"/>@else sem limite @endif · Comissão: {{ $tier['commission_percent'] !== null ? \App\Services\DecimalPresentation::format($tier['commission_percent'], 4).'%' : 'Desconhecida' }} · Fixa: @if($tier['fixed_fee'] !== null)<x-money :value="$tier['fixed_fee']"/>@else Desconhecida @endif</dd></div>
                        @endforeach
                        @foreach(['channel' => 'Canal', 'category' => 'Categoria', 'listing_type' => 'Modalidade', 'source' => 'Fonte', 'conditions' => 'Condições', 'notes' => 'Observações'] as $key => $label)
                            <div wire:key="revision-{{ $revision->id }}-{{ $key }}"><dt class="text-xs text-stone-500">{{ $label }}</dt><dd class="mt-1 whitespace-pre-line break-words">{{ $key === 'channel' ? (($snapshot[$key] ?? '') === 'mercado_livre' ? 'Mercado Livre' : 'Shopee') : ($snapshot[$key] ?? 'Não informado') }}</dd></div>
                        @endforeach
                        <div><dt class="text-xs text-stone-500">Comissão</dt><dd class="mt-1">{{ isset($snapshot['commission_percent']) ? \App\Services\DecimalPresentation::format($snapshot['commission_percent'], 4).'%' : 'Desconhecida' }}</dd></div>
                        <div><dt class="text-xs text-stone-500">Tarifa fixa</dt><dd class="mt-1">@if(isset($snapshot['fixed_fee']))<x-money :value="$snapshot['fixed_fee']"/>@else Desconhecida @endif</dd></div>
                        @foreach(['observed_on' => 'Consulta', 'valid_from' => 'Início da vigência', 'valid_until' => 'Fim da vigência', 'review_on' => 'Revisar até'] as $key => $label)
                            <div wire:key="revision-{{ $revision->id }}-{{ $key }}"><dt class="text-xs text-stone-500">{{ $label }}</dt><dd class="mt-1">{{ !empty($snapshot[$key]) ? \Illuminate\Support\Carbon::parse($snapshot[$key])->format('d/m/Y') : 'Não informado' }}</dd></div>
                        @endforeach
                        <div><dt class="text-xs text-stone-500">Condições verificadas</dt><dd class="mt-1">{{ ($snapshot['conditions_verified'] ?? false) ? 'Sim' : 'Não' }}</dd></div>
                    </dl>
                </details>
            @endforeach
            </div>
            <div class="border-t border-stone-100 px-4 py-3">{{ $this->revisions->links() }}</div>
        </details>
    @endif
</div>

