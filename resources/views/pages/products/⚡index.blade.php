<?php

use App\Models\Product;
use App\Services\ProductCostCalculator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Produtos')] class extends Component
{
    use WithPagination;

    #[Url(as: 'busca', except: '')]
    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function listing(): array
    {
        $products = Product::query()
            ->when($this->search !== '', fn ($query) => $query->where(fn ($query) => $query->whereLike('name', '%'.$this->search.'%')->orWhereLike('sku', '%'.$this->search.'%')))
            ->orderBy('name')->orderBy('id')->paginate(12);
        $costs = [];
        $calculator = app(ProductCostCalculator::class);
        foreach ($products as $product) {
            try {
                $costs[$product->id] = ['value' => $calculator->calculate($product), 'warning' => null];
            } catch (ValidationException $exception) {
                $costs[$product->id] = ['value' => null, 'warning' => $exception->validator->errors()->first()];
            }
        }

        return compact('products', 'costs');
    }
};
?>

<div class="grid gap-7">
    <x-success/>
    <div class="dashboard-heading flex-wrap">
        <div><h1>Produtos</h1><p>Seus produtos, suas composições e o custo de cada unidade.</p></div>
        <a href="{{ route('products.create') }}" wire:navigate class="btn-primary"><span aria-hidden="true" class="text-lg">+</span> Novo produto</a>
    </div>
    <section class="panel overflow-hidden" aria-label="Lista de produtos">
        <div class="flex flex-wrap items-center justify-between gap-4 border-b border-stone-200 p-5">
            <div class="relative w-full sm:max-w-sm">
                <label for="search" class="sr-only">Buscar por nome ou SKU</label>
                <input id="search" wire:model.live.debounce.300ms="search" type="search" class="field pl-10" placeholder="Buscar por nome ou SKU">
                <svg class="absolute top-3 left-3 size-4 text-stone-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><circle cx="10" cy="10" r="6"/><path d="m15 15 6 6"/></svg>
            </div>
            <span class="text-xs text-stone-400">{{ $this->listing['products']->total() }} produto(s)</span>
        </div>
        @if($this->listing['products']->isEmpty())
            <div class="empty-state">
                <span class="flex size-14 items-center justify-center rounded-2xl bg-cajubi-50 text-2xl text-cajubi-700" aria-hidden="true">◇</span>
                <h2 class="font-semibold">{{ $search ? 'Nenhum produto encontrado' : 'Seu catálogo começa aqui' }}</h2>
                <p class="max-w-sm text-sm leading-6 text-stone-500">{{ $search ? 'Tente outro nome ou SKU na busca.' : 'Cadastre um produto e adicione sua composição para conhecer o custo de cada unidade.' }}</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[680px] text-sm">
                    <thead class="table-head"><tr><th class="px-6 py-4">Produto / SKU</th><th class="px-4 py-4">Peso líquido</th><th class="px-4 py-4">Status</th><th class="px-4 py-4">Custo por unidade</th><th class="px-6 py-4"><span class="sr-only">Ações</span></th></tr></thead>
                    <tbody class="divide-y divide-stone-100">
                    @foreach($this->listing['products'] as $product)
                        <tr wire:key="product-{{ $product->id }}" class="hover:bg-stone-50/70">
                            <td class="px-6 py-4"><div class="flex items-center gap-3"><x-catalog-thumbnail :record="$product"/><div><a class="font-semibold text-stone-800 hover:text-cajubi-700" href="{{ route('products.edit', $product) }}" wire:navigate>{{ $product->name }}</a><p class="mt-1 text-xs text-stone-400">{{ $product->sku }}</p></div></div></td>
                            <td class="px-4 py-5 tabular-nums">{{ \App\Services\DecimalPresentation::weight($product->net_weight_grams) }}</td>
                            <td class="px-4 py-5"><span @class(['rounded-full px-2.5 py-1 text-xs font-medium', 'bg-cajubi-50 text-cajubi-700' => $product->status === 'active', 'bg-stone-100 text-stone-500' => $product->status !== 'active'])>{{ $product->status === 'active' ? 'Ativo' : 'Inativo' }}</span></td>
                            <td class="px-4 py-5">
                                @if($this->listing['costs'][$product->id]['value'] !== null)
                                    <x-money :value="$this->listing['costs'][$product->id]['value']" class="font-semibold text-stone-800"/>
                                @else
                                    <span class="inline-flex rounded-md bg-amber-50 px-2 py-1 text-xs font-medium text-amber-800">Custo pendente</span>
                                    <p class="mt-1 max-w-52 text-xs text-stone-500">{{ $this->listing['costs'][$product->id]['warning'] }}</p>
                                @endif
                            </td>
                            <td class="px-6 py-5 text-right"><a href="{{ route('products.edit', $product) }}" wire:navigate class="font-medium text-cajubi-700 hover:underline" aria-label="Editar {{ $product->name }}">Editar <span aria-hidden="true">↗</span></a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-stone-100 px-6 py-4">{{ $this->listing['products']->links() }}</div>
        @endif
    </section>
    <p class="flex gap-2 text-xs leading-5 text-stone-500"><span class="text-cajubi-yellow" aria-hidden="true">●</span> O custo acompanha os valores atuais dos insumos e a composição cadastrada.</p>
</div>

