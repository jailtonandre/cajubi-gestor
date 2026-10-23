<a {{ $attributes->class('btn-secondary w-fit max-w-full') }} wire:navigate>
    <x-ui-icon name="arrow" class="size-5 shrink-0 rotate-180"/>
    <span>{{ $slot }}</span>
</a>
