<?php

use App\Models\Product;
use App\Models\Supply;
use App\Services\CatalogImage;
use App\Services\DecimalPresentation;
use App\Services\ProductCatalog;
use App\Services\ProductComposition;
use App\Services\ProductCostCalculator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new #[Title('Cadastro de produto')] class extends Component
{
    use WithFileUploads;

    public ?TemporaryUploadedFile $image = null;

    public bool $removeImage = false;

    public function updatedImage(): void
    {
        $this->validate(['image' => CatalogImage::rules()], attributes: ['image' => 'imagem']);
        $this->removeImage = false;
    }

    public function removeSelectedImage(): void
    {
        $this->reset('image');
        $this->removeImage = true;
        $this->resetValidation('image');
    }

    public function undoImageRemoval(): void
    {
        $this->removeImage = false;
    }

    #[Locked]
    public ?Product $product = null;

    public string $name = '';

    public string $sku = '';

    public string $net_weight_grams = '';

    public string $description = '';

    public string $status = 'active';

    public array $components = [];

    public function mount(?Product $product = null): void
    {
        if ($product?->exists) {
            $this->product = $product;
            $this->name = $product->name;
            $this->sku = $product->sku;
            $this->net_weight_grams = str_replace('.', ',', $product->net_weight_grams);
            $this->description = $product->description ?? '';
            $this->status = $product->status;
            foreach ($product->components()->get() as $component) {
                $this->components[] = [
                    'key' => (string) Str::uuid(),
                    'target' => $component->supply_id ? 'supply:'.$component->supply_id : 'product:'.$component->component_product_id,
                    'quantity' => str_replace('.', ',', $component->quantity),
                ];
            }
        }
    }

    public function addComponent(): void
    {
        $this->components[] = ['key' => (string) Str::uuid(), 'target' => '', 'quantity' => '1'];
    }

    public function removeComponent(int $index): void
    {
        unset($this->components[$index]);
        $this->components = array_values($this->components);
        $this->resetValidation();
    }

    public function save(ProductCatalog $catalog, ProductComposition $composition, CatalogImage $images): void
    {
        $this->validate([
            'image' => CatalogImage::rules(),
            'components' => ['array', 'list'],
            'components.*' => ['array:key,target,quantity'],
            'components.*.key' => ['required', 'string'],
            'components.*.target' => ['required', 'string', 'regex:/\A(supply|product):[1-9][0-9]*\z/'],
            'components.*.quantity' => ['required', 'string'],
        ]);

        $product = $images->save($this->product, $this->image, $this->removeImage, function () use ($catalog, $composition): Product {
            $product = $catalog->save([
                'name' => $this->name,
                'sku' => $this->sku,
                'net_weight_grams' => DecimalPresentation::input($this->net_weight_grams),
                'description' => $this->description,
                'status' => $this->status,
            ], $this->product);
            $rows = [];
            foreach ($this->components as $row) {
                [$type, $id] = explode(':', $row['target']);
                $rows[] = [
                    $type === 'supply' ? 'supply_id' : 'component_product_id' => (int) $id,
                    'quantity' => DecimalPresentation::input($row['quantity']),
                ];
            }
            if ($rows !== [] || $product->components()->exists()) {
                $composition->replace($product, $rows);
            }

            return $product;
        });

        session()->flash('success', 'Produto salvo com sucesso.');
        $this->redirectRoute('products.edit', ['product' => $product], navigate: true);
    }

    #[Computed]
    public function supplies(): \Illuminate\Database\Eloquent\Collection
    {
        return Supply::query()->orderBy('name')->get();
    }

    #[Computed]
    public function otherProducts(): \Illuminate\Database\Eloquent\Collection
    {
        return Product::query()->when($this->product, fn ($query) => $query->whereKeyNot($this->product->id))->orderBy('name')->get();
    }

    #[Computed]
    public function cost(): array
    {
        if (! $this->product) {
            return ['value' => null, 'warning' => 'Salve o produto com uma composição para calcular seu custo.'];
        }

        try {
            return ['value' => app(ProductCostCalculator::class)->calculate($this->product), 'warning' => null];
        } catch (ValidationException $exception) {
            return ['value' => null, 'warning' => $exception->validator->errors()->first()];
        }
    }
};
?>

<div class="grid gap-6">
    <x-success/>
    <x-back-link :href="route('products.index')">Voltar para produtos</x-back-link>
    <div class="grid gap-2"><p class="eyebrow">Catálogo / {{ $product ? 'Editar' : 'Novo produto' }}</p><h1 class="page-title">{{ $product ? 'Editar produto' : 'Novo produto' }}</h1><p class="text-sm text-stone-500">Defina os dados do produto e o que entra na sua composição.</p></div>
    <form wire:submit="save" class="grid gap-6">
        <x-errors/>
        <div class="grid items-start gap-6 xl:grid-cols-[1fr_290px]">
            <div class="grid min-w-0 gap-6">
                <section class="panel p-6 sm:p-7" aria-labelledby="dados-produto">
                    <h2 id="dados-produto" class="font-semibold">Informações do produto</h2>
                    <p class="mt-1 text-xs text-stone-500">Campos com * são obrigatórios.</p>
                    <div class="mt-6 grid gap-5 sm:grid-cols-2">
                        <x-field label="Nome do produto *" for="name" class="sm:col-span-2"><input id="name" wire:model="name" class="field" required maxlength="255" autocomplete="off"></x-field>
                        <x-catalog-image-field :record="$product" :image="$image" :removing="$removeImage" class="sm:col-span-2"/>
                        <x-field label="SKU *" for="sku" hint="Código único para identificar este produto."><input id="sku" wire:model="sku" class="field uppercase" required maxlength="100" autocomplete="off"></x-field>
                        <x-field label="Peso líquido (g) *" for="net_weight_grams" hint="Informe o peso em gramas. Use vírgula para decimais."><input id="net_weight_grams" wire:model="net_weight_grams" class="field" inputmode="decimal" required></x-field>
                        <x-field label="Status *" for="status"><select id="status" wire:model="status" class="field"><option value="active">Ativo</option><option value="inactive">Inativo</option></select></x-field>
                        <x-field label="Descrição" for="description" class="sm:col-span-2"><textarea id="description" wire:model="description" rows="3" class="field"></textarea></x-field>
                    </div>
                </section>
                <section class="panel p-6 sm:p-7" aria-labelledby="composicao">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="grid gap-1"><h2 id="composicao" class="font-semibold">Composição do produto</h2><p class="max-w-md text-xs leading-5 text-stone-500">Insumos: quantidade na unidade indicada. Produtos: número de unidades consumidas.</p></div>
                        <button type="button" wire:click="addComponent" class="btn-secondary text-xs">+ Adicionar item</button>
                    </div>
                    @if($this->supplies->isEmpty() && $this->otherProducts->isEmpty())
                        <p class="mt-5 rounded-lg bg-amber-50 p-4 text-sm leading-6 text-amber-900">Cadastre um <a href="{{ route('supplies.create') }}" class="font-semibold underline" wire:navigate>insumo com custo</a> para começar a composição. Você também poderá usar outros produtos cadastrados.</p>
                    @endif
                    @if(count($components) === 0)
                        <div class="mt-5 rounded-lg border border-dashed border-stone-200 px-5 py-8 text-center"><p class="text-sm text-stone-600">Nenhum item na composição</p><p class="mt-2 text-xs leading-5 text-stone-400">Adicione insumos ou outros produtos para calcular o custo.<br>Você pode salvar um novo produto e completar a composição depois.</p></div>
                    @endif
                    <div class="mt-5 grid gap-4">
                    @foreach($components as $index => $row)
                        <div wire:key="component-{{ $row['key'] }}" class="grid items-start gap-3 rounded-lg border border-stone-200 bg-stone-50/50 p-4 sm:grid-cols-[1fr_140px_auto]">
                            <x-field label="Insumo ou produto *" for="components.{{ $index }}.target">
                                <select id="components.{{ $index }}.target" wire:model="components.{{ $index }}.target" class="field" required>
                                    <option value="">Selecione um item</option>
                                    <optgroup label="Insumos">
                                    @foreach($this->supplies as $supply)<option wire:key="supply-option-{{ $row['key'] }}-{{ $supply->id }}" value="supply:{{ $supply->id }}">{{ $supply->name }} · {{ $supply->unit }}</option>@endforeach
                                    </optgroup>
                                    <optgroup label="Produtos (unidades)">
                                    @foreach($this->otherProducts as $otherProduct)<option wire:key="product-option-{{ $row['key'] }}-{{ $otherProduct->id }}" value="product:{{ $otherProduct->id }}">{{ $otherProduct->name }} · {{ $otherProduct->sku }}</option>@endforeach
                                    </optgroup>
                                </select>
                            </x-field>
                            <x-field label="Quantidade *" for="components.{{ $index }}.quantity"><input id="components.{{ $index }}.quantity" wire:model="components.{{ $index }}.quantity" class="field" inputmode="decimal" required></x-field>
                            <button type="button" wire:click="removeComponent({{ $index }})" class="mt-7 flex min-h-11 items-center justify-center rounded-lg px-3 text-sm text-stone-400 hover:bg-red-50 hover:text-red-700" aria-label="Remover item {{ $index + 1 }}">Remover</button>
                        </div>
                    @endforeach
                    </div>
                </section>
            </div>
            <aside class="grid gap-4 xl:sticky xl:top-6">
                <section class="overflow-hidden rounded-xl border border-cajubi-200 bg-cajubi-50">
                    <div class="h-1 bg-cajubi-yellow"></div>
                    <div class="grid gap-4 p-6">
                        <p class="text-xs font-semibold text-cajubi-700">CUSTO POR UNIDADE</p>
                        @if($this->cost['value'] !== null)
                            <x-money :value="$this->cost['value']" class="text-2xl font-semibold tracking-tight text-cajubi-800"/>
                            <p class="text-xs leading-5 text-cajubi-700">Composição salva, calculada com os custos atuais dos insumos.</p>
                        @else
                            <p class="text-lg font-semibold text-cajubi-800">Custo pendente</p>
                            <p class="text-sm leading-6 text-cajubi-700">{{ $this->cost['warning'] }}</p>
                        @endif
                    </div>
                </section>
                <p class="px-1 text-xs leading-5 text-stone-500">As alterações na composição entram no cálculo ao salvar o produto.</p>
            </aside>
        </div>
        <div class="flex flex-wrap items-center justify-end gap-3 border-t border-stone-200 pt-6">
            <a href="{{ route('products.index') }}" wire:navigate class="btn-secondary">Cancelar</a>
            <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="save,image"><span wire:loading.remove wire:target="save">Salvar produto</span><span wire:loading wire:target="save">Salvando…</span></button>
        </div>
    </form>
</div>

