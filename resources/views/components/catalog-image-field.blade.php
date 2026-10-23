@props(['record' => null, 'image' => null, 'removing' => false])
@php
    $hasSelectedImage = $image && ! $errors->has('image');
    $hasStoredImage = $record?->image_path && ! $removing;
@endphp
<div {{ $attributes->class('grid gap-3') }} x-data>
    <label for="image" class="text-sm font-medium text-stone-700">Imagem (opcional)</label>
    <div class="flex flex-col gap-5 rounded-xl border border-dashed border-cajubi-200 bg-cajubi-50/40 p-5 sm:flex-row sm:items-center">
        <div class="flex size-32 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-stone-200 bg-white text-cajubi-700">
            @if($hasSelectedImage)
                <img src="{{ $image->temporaryUrl() }}" alt="Pré-visualização da imagem selecionada" class="size-full object-contain">
            @elseif($hasStoredImage)
                <img src="{{ Storage::disk('public')->url($record->image_path) }}" alt="Imagem cadastrada de {{ $record->name }}" class="size-full object-contain">
            @else
                <x-ui-icon name="image" class="size-10!"/>
            @endif
        </div>
        <div class="grid min-w-0 flex-1 gap-3">
            <div class="grid gap-1">
                <p class="text-sm font-medium text-stone-700">{{ $hasSelectedImage ? 'Imagem selecionada' : ($hasStoredImage ? 'Imagem cadastrada' : 'Adicione uma fotografia') }}</p>
                <p id="image-hint" class="text-xs text-stone-500">JPG, PNG ou WebP — até 2 MB</p>
                @if($hasSelectedImage)<p class="break-all text-xs text-stone-600">{{ $image->getClientOriginalName() }}</p>@endif
            </div>
            <input id="image" x-ref="imageInput" type="file" wire:model="image" accept="image/jpeg,image/png,image/webp" class="sr-only" tabindex="-1" aria-describedby="image-hint image-upload-status{{ $errors->has('image') ? ' image-error' : '' }}" @if($errors->has('image')) aria-invalid="true" @endif>
            <div class="flex flex-wrap gap-3">
                <button type="button" x-on:click="$refs.imageInput.value = ''; $refs.imageInput.click()" class="btn-primary" aria-controls="image" wire:loading.attr="disabled" wire:target="image,removeSelectedImage">
                    <x-ui-icon name="image"/>{{ $hasSelectedImage || $hasStoredImage ? 'Substituir imagem' : 'Selecionar imagem' }}
                </button>
                @if($image || $hasStoredImage)
                    <button type="button" wire:click="removeSelectedImage" x-on:click="$refs.imageInput.value = ''" class="btn-secondary" wire:loading.attr="disabled" wire:target="image,removeSelectedImage">Remover imagem</button>
                @endif
            </div>
            <p id="image-upload-status" wire:loading wire:target="image" class="text-xs text-cajubi-700" role="status">Carregando imagem…</p>
            @if($removing && $record?->image_path)
                <p class="text-xs leading-5 text-stone-600" role="status">A imagem cadastrada será removida ao salvar. <button type="button" wire:click="undoImageRemoval" class="font-semibold text-cajubi-700 underline">Desfazer remoção</button></p>
            @endif
        </div>
    </div>
    @error('image')<p id="image-error" class="text-sm text-red-700" role="alert">{{ $message }}</p>@enderror
</div>
