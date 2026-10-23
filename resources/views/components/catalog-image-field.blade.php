@props(['record' => null, 'image' => null, 'removing' => false])
<div {{ $attributes->class('grid gap-3') }}>
    <x-field label="Imagem (opcional)" for="image" hint="JPEG, PNG ou WebP, até 2 MB.">
        <input id="image" type="file" wire:model="image" accept="image/jpeg,image/png,image/webp" class="field" aria-describedby="image-upload-status">
    </x-field>
    <p id="image-upload-status" wire:loading wire:target="image" class="text-xs text-stone-500" role="status">Carregando imagem…</p>
    @if($image && ! $errors->has('image'))
        <img src="{{ $image->temporaryUrl() }}" alt="Pré-visualização da imagem selecionada" class="size-28 rounded-xl border border-stone-200 bg-stone-50 object-contain">
    @elseif($record?->image_path && ! $removing)
        <img src="{{ Storage::disk('public')->url($record->image_path) }}" alt="Imagem cadastrada de {{ $record->name }}" class="size-28 rounded-xl border border-stone-200 bg-stone-50 object-contain">
    @endif
    @if($record?->image_path)
        <label class="flex items-center gap-2 text-sm text-stone-600"><input type="checkbox" wire:model.live="removeImage" class="accent-cajubi-700"> Remover imagem cadastrada ao salvar</label>
    @endif
</div>
