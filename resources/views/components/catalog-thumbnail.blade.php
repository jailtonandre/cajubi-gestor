@props(['record'])
@if($record->image_path)
    <img src="{{ Storage::disk('public')->url($record->image_path) }}" alt="" loading="lazy" decoding="async" width="52" height="52" class="size-[52px] shrink-0 rounded-lg border border-stone-100 bg-stone-50 object-contain">
@else
    <span class="flex size-[52px] shrink-0 items-center justify-center rounded-lg border border-stone-100 bg-stone-50 text-stone-400" role="img" aria-label="Sem imagem"><x-ui-icon name="box"/></span>
@endif
