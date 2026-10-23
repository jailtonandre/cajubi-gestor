@props(['value'])
<span {{ $attributes->class('tabular-nums whitespace-nowrap') }}>R$ {{ \App\Services\DecimalPresentation::format($value, 2) }}</span>

