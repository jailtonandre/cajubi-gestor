<?php

use App\Models\Supply;
use App\Services\CatalogImage;
use App\Services\DecimalPresentation;
use App\Services\SupplyCatalog;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Title('Cadastro e custos do insumo')] class extends Component
{
    use WithFileUploads;
    use WithPagination;

    public ?TemporaryUploadedFile $image = null;

    public bool $removeImage = false;

    public function updatedImage(): void
    {
        $this->validate(['image' => CatalogImage::rules()], attributes: ['image' => 'imagem']);
    }

    #[Locked]
    public ?Supply $supply = null;

    public string $name = '';

    public string $description = '';

    public string $unit = 'kg';

    public string $unit_cost = '';

    public function mount(?Supply $supply = null): void
    {
        if ($supply?->exists) {
            $this->supply = $supply;
            $this->name = $supply->name;
            $this->description = $supply->description ?? '';
            $this->unit = $supply->unit;
        }
    }

    public function save(SupplyCatalog $catalog, CatalogImage $images): void
    {
        $this->validate(['image' => CatalogImage::rules()], attributes: ['image' => 'imagem']);
        $cost = DecimalPresentation::input($this->unit_cost);
        $saved = $images->save($this->supply, $this->image, $this->removeImage, fn (): Supply => $this->supply
            ? $catalog->update($this->supply, ['name' => $this->name, 'description' => $this->description, 'unit' => $this->unit, 'unit_cost' => $cost])
            : $catalog->create(['name' => $this->name, 'description' => $this->description, 'unit' => $this->unit, 'unit_cost' => $cost]));
        $this->reset('image', 'removeImage');
        if ($this->supply) {
            $this->supply = $saved;
            $this->unit_cost = '';
            $this->resetPage();
            unset($this->history, $this->currentCost);
            session()->flash('success', 'Insumo atualizado. O histórico anterior foi preservado.');
        } else {
            $supply = $saved;
            session()->flash('success', 'Insumo cadastrado com sucesso.');
            $this->redirectRoute('supplies.show', ['supply' => $supply], navigate: true);
        }
    }

    #[Computed]
    public function history(): ?\Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return $this->supply?->costs()->orderByDesc('id')->paginate(10);
    }

    #[Computed]
    public function currentCost(): ?\App\Models\SupplyCost
    {
        return $this->supply?->currentCost()->first();
    }
};
?>

<div class="grid max-w-5xl gap-6">
    <x-success/>
    <x-back-link :href="route('supplies.index')">Voltar para insumos</x-back-link>
    <div class="grid gap-2"><p class="eyebrow">Base de custos / {{ $supply ? 'Editar insumo' : 'Novo insumo' }}</p><h1 class="page-title">{{ $supply ? $supply->name : 'Novo insumo' }}</h1><p class="text-sm text-stone-500">{{ $supply ? 'Edite os dados do insumo e atualize seu custo preservando o histórico.' : 'Cadastre a matéria-prima, embalagem ou outro item usado nos produtos.' }}</p></div>
    <div class="grid items-start gap-6 lg:grid-cols-[1fr_280px]">
        <section class="panel p-6 sm:p-7" aria-labelledby="form-insumo">
            <h2 id="form-insumo" class="mb-1 font-semibold">{{ $supply ? 'Editar informações do insumo' : 'Informações do insumo' }}</h2>
            <p class="mb-6 text-xs text-stone-500">Campos com * são obrigatórios.</p>
            <form wire:submit="save" class="grid gap-5">
                <x-errors/>
                <x-catalog-image-field :record="$supply" :image="$image" :removing="$removeImage"/>
                <x-field label="Nome do insumo *" for="name"><input id="name" wire:model="name" class="field" required maxlength="255" autocomplete="off"></x-field>
                <x-field label="Descrição" for="description"><textarea id="description" wire:model="description" class="field" rows="3" maxlength="5000"></textarea></x-field>
                @if(! $supply)
                    <x-field label="Unidade de medida *" for="unit" hint="Use esta mesma unidade ao informar o consumo na composição.">
                        <select id="unit" wire:model="unit" class="field"><option value="kg">Quilograma (kg)</option><option value="g">Grama (g)</option><option value="un">Unidade (un)</option><option value="l">Litro (l)</option><option value="ml">Mililitro (ml)</option><option value="m">Metro (m)</option></select>
                    </x-field>
                @else
                    <p class="rounded-lg bg-stone-50 p-3 text-sm text-stone-600">Unidade de medida: <strong>{{ $supply->unit }}</strong></p>
                    <p class="text-xs text-stone-500">A unidade permanece fixa para preservar as quantidades das composições e o histórico de custos.</p>
                    @error('unit')<p class="text-sm text-red-700" role="alert">{{ $message }}</p>@enderror
                @endif
                <x-field :label="$supply ? 'Novo custo unitário (R$)' : 'Custo unitário (R$) *'" for="unit_cost" :hint="$supply ? 'Deixe em branco para manter o custo atual. Preencha para adicionar um novo valor ao histórico, com até 4 casas decimais.' : 'Valor por unidade de medida. Use vírgula e até 4 casas decimais.'">
                    <input id="unit_cost" wire:model="unit_cost" class="field" inputmode="decimal" placeholder="0,0000" @required(!$supply)>
                </x-field>
                <div class="flex flex-wrap justify-end gap-3 border-t border-stone-100 pt-5">
                    @if($supply)
                        <x-back-link :href="route('supplies.index')">Voltar para insumos</x-back-link>
                    @else
                        <a href="{{ route('supplies.index') }}" wire:navigate class="btn-secondary">Cancelar</a>
                    @endif
                    <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="save,image"><span wire:loading.remove wire:target="save">{{ $supply ? 'Salvar alterações' : 'Cadastrar insumo' }}</span><span wire:loading wire:target="save">Salvando…</span></button>
                </div>
            </form>
        </section>
        <aside class="overflow-hidden rounded-xl border border-cajubi-200 bg-cajubi-50">
            <div class="h-1 bg-cajubi-yellow"></div>
            <div class="grid gap-4 p-6">
                <p class="text-xs font-semibold text-cajubi-700">{{ $supply ? 'CUSTO ATUAL' : 'CUSTOS SEMPRE ATUALIZADOS' }}</p>
                @if($supply && $this->currentCost)
                    <x-money :value="$this->currentCost->unit_cost" class="text-2xl font-semibold tracking-tight text-cajubi-800"/>
                    <p class="text-xs text-cajubi-700">por {{ $supply->unit }} · registrado em {{ $this->currentCost->created_at->timezone('America/Sao_Paulo')->format('d/m/Y') }}</p>
                @elseif($supply)
                    <p class="text-sm leading-6 text-amber-900">Este insumo ainda não tem custo. Registre um valor para calcular os produtos que o utilizam.</p>
                @else
                    <p class="text-sm leading-6 text-cajubi-800">O custo informado será usado nas composições dos produtos.</p>
                @endif
                <p class="border-t border-cajubi-200 pt-4 text-xs leading-5 text-cajubi-700">Novos registros atualizam o cálculo dos produtos e preservam todos os valores anteriores.</p>
            </div>
        </aside>
    </div>
    @if($supply)
        <section class="panel overflow-hidden" aria-labelledby="historico">
            <div class="border-b border-stone-200 p-6"><h2 id="historico" class="font-semibold">Histórico de custos</h2><p class="mt-1 text-xs text-stone-500">Do registro mais recente para o mais antigo.</p></div>
            @if($this->history->isEmpty())
                <p class="p-6 text-sm text-stone-500">Nenhum custo registrado.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[440px] text-sm">
                        <thead class="table-head"><tr><th class="px-6 py-4">Registrado em</th><th class="px-6 py-4">Custo unitário</th><th class="px-6 py-4">Referência</th></tr></thead>
                        <tbody class="divide-y divide-stone-100">
                        @foreach($this->history as $cost)
                            <tr wire:key="cost-{{ $cost->id }}"><td class="px-6 py-4 text-stone-500">{{ $cost->created_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i:s') }}</td><td class="px-6 py-4"><x-money :value="$cost->unit_cost" class="font-medium"/> <span class="text-xs text-stone-400">/ {{ $supply->unit }}</span></td><td class="px-6 py-4">@if($this->currentCost?->id === $cost->id)<span class="rounded-full bg-cajubi-50 px-2.5 py-1 text-xs font-medium text-cajubi-700">Atual</span>@else<span class="text-xs text-stone-400">Anterior</span>@endif</td></tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-stone-100 px-6 py-4">{{ $this->history->links() }}</div>
            @endif
        </section>
    @endif
</div>

