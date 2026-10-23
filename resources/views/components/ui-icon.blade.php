@props(['name' => 'box'])
<svg {{ $attributes->class('ui-icon') }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @switch($name)
        @case('image')<rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8" cy="8" r="1.5"/><path d="m3 16 5-5 4 4 3-3 6 6"/>@break
        @case('cart')<path d="M3 3h2l3 13h11l3-9H6M9 21h.01M18 21h.01"/>@break
        @case('bank')<path d="m3 8 9-5 9 5H3ZM4 21h16M6 10v7m6-7v7m6-7v7M3 18h18"/>@break
        @case('clock')<path d="M7 3h10M7 21h10M8 3v4l4 5-4 5v4m8-18v4l-4 5 4 5v4"/>@break
        @case('chart')<path d="M12 3v9h9M9 3.5A9 9 0 1 0 20.5 15M15 3.5a9 9 0 0 1 5.5 5.5H15V3.5Z"/>@break
        @case('alert')<path d="m12 3 10 18H2L12 3ZM12 9v5m0 3h.01"/>@break
        @case('check')<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>@break
        @case('arrow')<path d="M4 12h16m-6-6 6 6-6 6"/>@break
        @case('chevron')<path d="m9 5 7 7-7 7"/>@break
        @case('percent')<path d="m5 19 14-14"/><circle cx="7" cy="7" r="3"/><circle cx="17" cy="17" r="3"/>@break
        @case('settings')<path d="m9 4 1-2h4l1 2 2 1 2-.2 2 3-1 2v4l1 2-2 3-2-.2-2 1-1 2h-4l-1-2-2-1-2 .2-2-3 1-2v-4l-1-2 2-3L7 5l2-1Z"/><circle cx="12" cy="12" r="3"/>@break
        @case('bolt')<path d="m13 2-9 12h7l-1 8 10-13h-7l1-7Z"/>@break
        @case('calculator')<rect x="5" y="2" width="14" height="20" rx="2"/><path d="M8 6h8M8 10h1m6 0h1M8 14h1m6 0h1M8 18h1m6 0h1"/>@break
        @case('trend')<path d="m3 17 6-6 4 4 8-11m-6 0h6v6"/>@break
        @case('bars')<path d="M4 20V10h4v10m2 0V4h4v16m2 0v-7h4v7M2 20h20"/>@break
        @case('calendar')<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 2v6m10-6v6M3 11h18M7 15h2m4 0h2"/>@break
        @case('wallet')<rect x="3" y="5" width="18" height="15" rx="2"/><path d="M3 9h18m-5 4h5v4h-5zM5 5V3h13"/>@break
        @default<path d="m12 3 9 5v8l-9 5-9-5V8l9-5ZM3 8l9 5 9-5m-9 5v8M7.5 5.5l9 5"/>
    @endswitch
</svg>
