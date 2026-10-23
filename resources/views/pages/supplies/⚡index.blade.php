<?php

use App\Models\Supply;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Insumos')] class extends Component
{
    use WithPagination;

    #[Url(as: 'busca', except: '')]
    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function supplies(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return Supply::query()->with('currentCost')
            ->when($this->search !== '', fn ($query) => $query->whereLike('name', '%'.$this->search.'%'))
            ->orderBy('name')->orderBy('id')->paginate(12);
    }
};
?>

<div class="grid gap-7">
    <x-success/>
    <div class="dashboard-heading flex-wrap">
        <div><h1>Insumos</h1><p>Mantenha os custos atualizados, com todo o histórico preservado.</p></div>
        <a href="{{ route('supplies.create') }}" wire:navigate class="btn-primary"><span class="text-lg" aria-hidden="true">+</span> Novo insumo</a>
    </div>
    <section class="panel overflow-hidden" aria-label="Lista de insumos">
        <div class="flex flex-wrap items-center justify-between gap-4 border-b border-stone-200 p-5">
            <div class="relative w-full sm:max-w-sm">
                <label for="search" class="sr-only">Buscar insumo por nome</label>
                <input id="search" wire:model.live.debounce.300ms="search" class="field pl-10" type="search" placeholder="Buscar insumo por nome">
                <svg class="absolute top-3 left-3 size-4 text-stone-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><circle cx="10" cy="10" r="6"/><path d="m15 15 6 6"/></svg>
            </div>
            <span class="text-xs text-stone-400">{{ $this->supplies->total() }} insumo(s)</span>
        </div>
        @if($this->supplies->isEmpty())
            <div class="empty-state">
                <span class="flex size-14 items-center justify-center rounded-2xl bg-cajubi-50 text-2xl text-cajubi-700" aria-hidden="true">≋</span>
                <h2 class="font-semibold">{{ $search ? 'Nenhum insumo encontrado' : 'Comece pela base dos seus custos' }}</h2>
                <p class="max-w-sm text-sm leading-6 text-stone-500">{{ $search ? 'Tente outro nome na busca.' : 'Cadastre cada insumo, sua unidade de medida e o custo unitário para usar na composição dos produtos.' }}</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[640px] text-sm">
                    <thead class="table-head"><tr><th class="px-6 py-4">Insumo</th><th class="px-4 py-4">Unidade</th><th class="px-4 py-4">Custo atual</th><th class="px-4 py-4">Último registro</th><th class="px-6 py-4"><span class="sr-only">Ações</span></th></tr></thead>
                    <tbody class="divide-y divide-stone-100">
                    @foreach($this->supplies as $supply)
                        <tr wire:key="supply-{{ $supply->id }}" class="hover:bg-stone-50/70">
                            <td class="px-6 py-4"><div class="flex items-center gap-3"><x-catalog-thumbnail :record="$supply"/><a class="font-semibold hover:text-cajubi-700" href="{{ route('supplies.show', $supply) }}" wire:navigate>{{ $supply->name }}</a></div></td>
                            <td class="px-4 py-5 text-stone-500">{{ $supply->unit }}</td>
                            <td class="px-4 py-5">@if($supply->currentCost)<x-money :value="$supply->currentCost->unit_cost" class="font-semibold"/> <span class="text-xs text-stone-400">/ {{ $supply->unit }}</span>@else<span class="rounded bg-amber-50 px-2 py-1 text-xs text-amber-800">Custo não informado</span>@endif</td>
                            <td class="px-4 py-5 text-xs text-stone-500">{{ $supply->currentCost?->created_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') ?? 'Sem registro' }}</td>
                            <td class="px-6 py-5 text-right"><a href="{{ route('supplies.show', $supply) }}" wire:navigate class="font-medium text-cajubi-700 hover:underline" aria-label="Editar insumo {{ $supply->name }}">Editar insumo <span aria-hidden="true">↗</span></a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-stone-100 px-6 py-4">{{ $this->supplies->links() }}</div>
        @endif
    </section>
    <p class="flex gap-2 text-xs leading-5 text-stone-500"><span class="text-cajubi-yellow" aria-hidden="true">●</span> Cada novo custo é registrado no histórico e passa a valer no cálculo dos produtos.</p>
</div>

