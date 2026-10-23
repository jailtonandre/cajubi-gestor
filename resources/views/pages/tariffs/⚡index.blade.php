<?php

use App\Models\TariffProfile;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Tarifas de marketplaces')] class extends Component
{
    use WithPagination;

    public string $search = '';
    public string $channel = 'mercado_livre';
    public string $activity = '';
    #[Locked]
    public ?TariffProfile $profile = null;

    public function mount(?TariffProfile $profile = null): void
    {
        if ($profile?->exists) {
            $this->profile = $profile;
            $this->channel = $profile->channel;
        } elseif (in_array(request('marketplace'), ['mercado_livre', 'shopee'], true)) {
            $this->channel = request('marketplace');
        }
    }

    public function updatedChannel(): void
    {
        $this->profile = null;
        $this->resetPage();
    }

    #[On('tariff-marketplace-changed')]
    public function selectMarketplace(string $channel): void
    {
        if (! in_array($channel, ['mercado_livre', 'shopee'], true)) { return; }
        $this->channel = $channel;
        $this->updatedChannel();
    }

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedActivity(): void { $this->resetPage(); }

    #[Computed]
    public function counts(): array
    {
        return TariffProfile::query()->selectRaw('channel, count(*) as total')->groupBy('channel')->pluck('total', 'channel')->all();
    }

    #[Computed]
    public function profiles(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return TariffProfile::query()
            ->where('channel', $this->channel)
            ->when($this->search !== '', fn ($query) => $query->where(fn ($query) => $query->whereLike('category', '%'.$this->search.'%')->orWhereLike('listing_type', '%'.$this->search.'%')))
            ->when(in_array($this->activity, ['active', 'inactive'], true), fn ($query) => $query->where('is_active', $this->activity === 'active'))
            ->orderBy('listing_type')->orderBy('id')->paginate(12);
    }
};
?>

<div class="-mx-1 grid gap-5 sm:-mx-2">
    <x-success/>
    <div class="grid gap-2"><p class="eyebrow">Condições comerciais</p><h1 class="page-title">Tarifas de marketplaces</h1><p class="text-sm text-stone-500">Cadastre as regras de cobrança usadas no simulador e nos cenários.</p></div>
    <div class="grid items-start gap-5 xl:grid-cols-[minmax(0,0.9fr)_minmax(0,1.35fr)]">
        <section class="panel min-w-0 overflow-hidden" aria-label="Regras de tarifas">
            <div class="grid grid-cols-2 gap-2 border-b border-stone-200 p-3" role="group" aria-label="Marketplace">
                @foreach(['mercado_livre' => 'Mercado Livre', 'shopee' => 'Shopee'] as $value => $label)
                    <button type="button" wire:click="$set('channel', '{{ $value }}')" aria-pressed="{{ $channel === $value ? 'true' : 'false' }}" @class(['rounded-lg border px-3 py-2.5 text-sm font-semibold transition', 'border-cajubi-700 bg-cajubi-50 text-cajubi-700' => $channel === $value, 'border-stone-200 text-stone-500 hover:bg-stone-50' => $channel !== $value])>{{ $label }} <span class="text-xs">({{ $this->counts[$value] ?? 0 }})</span></button>
                @endforeach
            </div>
            <div class="grid gap-3 p-3">
                <a href="{{ route('tariffs.create', ['marketplace' => $channel]) }}" wire:navigate class="btn-primary justify-self-end px-3! py-2! text-xs!">+ Nova regra</a>
                <div class="grid gap-2 sm:grid-cols-[minmax(0,1fr)_105px]">
                    <div><label for="search" class="sr-only">Buscar categoria ou modalidade</label><input id="search" wire:model.live.debounce.300ms="search" class="field py-2! text-xs!" type="search" placeholder="Buscar regra"></div>
                    <div><label for="activity" class="sr-only">Filtrar situação</label><select id="activity" wire:model.live="activity" class="field py-2! text-xs!"><option value="">Todas</option><option value="active">Ativas</option><option value="inactive">Desativadas</option></select></div>
                </div>
            </div>
            <div class="overflow-x-auto" role="region" aria-label="Tabela de regras de tarifa" tabindex="0">
                <table class="w-full min-w-[360px] table-fixed text-left text-xs">
                    <caption class="sr-only">Regras do marketplace selecionado. Escolha uma modalidade para editar.</caption>
                    <thead class="border-y border-stone-200 bg-stone-50 text-[10px] font-medium uppercase tracking-wide text-stone-500">
                        <tr><th scope="col" class="w-[43%] px-4 py-3">Modalidade / categoria</th><th scope="col" class="w-[18%] px-2 py-3 text-right">Comissão</th><th scope="col" class="w-[18%] px-2 py-3 text-right">Tarifa fixa</th><th scope="col" class="w-[21%] px-3 py-3 text-center">Situação</th></tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse($this->profiles as $rule)
                            @php($selected = $profile?->id === $rule->id)
                            <tr wire:key="tariff-{{ $rule->id }}" @class(['transition-colors hover:bg-cajubi-50/70', 'bg-cajubi-50' => $selected])>
                                <th scope="row" @class(['border-l-3 py-3.5 pl-3 pr-2 align-top font-normal', 'border-cajubi-700' => $selected, 'border-transparent' => ! $selected])>
                                    <a href="{{ route('tariffs.edit', $rule) }}" wire:navigate aria-label="Consultar e editar {{ $rule->listing_type }}" @if($selected) aria-current="page" @endif class="inline-flex min-h-6 items-center gap-1.5 text-sm font-semibold text-stone-900 hover:text-cajubi-700 hover:underline">
                                        {{ $rule->listing_type }}
                                        @if($selected)<svg class="size-3.5 shrink-0 text-cajubi-700" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m4 10 4 4 8-8"/></svg><span class="sr-only">Regra selecionada</span>@endif
                                    </a>
                                    <p class="mt-1 line-clamp-2 break-words text-[11px] leading-4 text-stone-500" title="{{ $rule->category }}">{{ $rule->category }}</p>
                                </th>
                                <td class="px-2 py-4 text-right align-top tabular-nums">
                                    @if($rule->pricing_model === 'tiered')<span class="text-stone-500">Por faixa</span>
                                    @elseif($rule->commission_percent !== null){{ \App\Services\DecimalPresentation::format($rule->commission_percent, 2) }}%
                                    @else<span aria-label="Comissão desconhecida" title="Comissão desconhecida" class="text-stone-400">—</span>@endif
                                </td>
                                <td class="px-2 py-4 text-right align-top tabular-nums">
                                    @if($rule->pricing_model === 'tiered')<span class="text-stone-500">Por faixa</span>
                                    @elseif($rule->fixed_fee !== null)<x-money :value="$rule->fixed_fee" class="whitespace-nowrap"/>
                                    @else<span aria-label="Tarifa fixa desconhecida" title="Tarifa fixa desconhecida" class="text-stone-400">—</span>@endif
                                    <span class="mt-1 block text-[10px] text-stone-500">{{ $rule->charge_basis === 'order' ? 'por pedido' : 'por item' }}</span>
                                </td>
                                <td class="px-2 py-4 text-center align-top">
                                    <span @class(['inline-block rounded-md px-1.5 py-1 text-[10px] font-medium', 'bg-cajubi-100 text-cajubi-800' => $rule->confidence === 'confirmed', 'bg-amber-100 text-amber-900' => $rule->confidence !== 'confirmed'])>{{ $rule->confidence === 'confirmed' ? 'Confirmado' : 'Estimado' }}</span>
                                    @if(! $rule->is_active)<span class="mt-1 block text-[10px] text-stone-500">Desativada</span>@endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-10 text-center"><h2 class="text-sm font-semibold">Nenhuma regra encontrada</h2><p class="mt-2 text-xs text-stone-500">Cadastre uma regra ou ajuste os filtros.</p></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($this->profiles->hasPages())<div class="border-t border-stone-100 px-4 py-3">{{ $this->profiles->links() }}</div>@endif
            @if($channel === 'shopee')
                <details class="border-t border-stone-200 p-4 text-xs leading-6 text-stone-600"><summary class="cursor-pointer font-semibold">Referência Shopee CNPJ — pendente de conferência</summary><p class="mt-2">Segundo a referência fornecida pelo usuário, atualizada em 01/10/2026, a tarifa fixa é de R$ 4,50 por item até R$ 79,99. As tarifas de R$ 16, R$ 20 e R$ 26 dependem de limites e condições ainda não confirmados. Confira a tabela oficial antes de completar as faixas e comissões. Nenhuma dessas informações é cadastrada automaticamente como confirmada.</p><p class="mt-2">Subsídios Pix e de frete dependem de condições e não são somados automaticamente ao repasse do vendedor.</p></details>
            @endif
        </section>
        <livewire:pages::tariffs.form :profile="$profile" :marketplace="$channel" :key="'tariff-editor-'.($profile?->id ?? 'new').'-'.$channel" />
    </div>
    <p class="text-xs leading-5 text-stone-500">“Confirmado” é uma classificação manual. As referências não são verificadas automaticamente por integração oficial.</p>
</div>
