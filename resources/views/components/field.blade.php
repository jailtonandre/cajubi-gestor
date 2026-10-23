@props(['label', 'for', 'hint' => null])
<div {{ $attributes->class('grid content-start gap-2') }}>
    <label for="{{ $for }}" class="text-sm font-medium text-stone-700">{{ $label }}</label>
    {{ $slot }}
    @if($hint)<p class="text-xs leading-5 text-stone-500">{{ $hint }}</p>@endif
    @error($for)<p class="text-sm text-red-700" role="alert">{{ $message }}</p>@enderror
</div>

