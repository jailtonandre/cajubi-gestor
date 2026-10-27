@props(['chart', 'expanded' => false])
@php
    $verticalFactor = $expanded ? 1.65 : 1;
    $chartY = fn (float $y): float => 26 + ($y - 26) * $verticalFactor;
    $chartLine = implode(' ', array_map(fn (array $point): string => $point['x'].','.$chartY($point['y']), $chart['points']));
    $chartArea = '76,'.$chartY(136).' '.$chartLine.' 692,'.$chartY(136);
@endphp
<div class="dashboard-chart-scroll">
    <svg class="dashboard-chart" viewBox="0 0 740 {{ $expanded ? 245 : 180 }}" role="img" aria-labelledby="cash-chart-title cash-chart-description">
        <title id="cash-chart-title">Projeção de caixa de oito semanas</title>
        <desc id="cash-chart-description">Saldo inicial e oito fechamentos semanais da projeção salva. Os pontos não representam os saldos de cada dia.</desc>
        <defs><linearGradient id="cash-chart-fill" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#078137" stop-opacity="{{ $expanded ? '.20' : '.16' }}"/><stop offset="100%" stop-color="#078137" stop-opacity=".015"/></linearGradient></defs>
        @foreach($chart['ticks'] as $tick)
            <g wire:key="chart-tick-{{ $loop->index }}"><line x1="76" x2="692" y1="{{ $chartY($tick['y']) }}" y2="{{ $chartY($tick['y']) }}" stroke="#e9edf0"/><text x="64" y="{{ $chartY($tick['y']) + 4 }}" text-anchor="end" class="chart-label">R$ {{ \App\Services\DecimalPresentation::format($tick['value'], 2) }}</text></g>
        @endforeach
        <polygon points="{{ $chartArea }}" fill="url(#cash-chart-fill)"/>
        <polyline points="{{ $chartLine }}" fill="none" stroke="#078137" stroke-width="2.5" stroke-linejoin="round"/>
        @foreach($chart['points'] as $point)
            <g wire:key="chart-point-{{ $loop->index }}">
                @if($loop->index === $chart['minimum_index'])<line x1="{{ $point['x'] }}" x2="{{ $point['x'] }}" y1="{{ $chartY($point['y']) }}" y2="{{ $chartY(136) }}" stroke="#a1abb4" stroke-dasharray="3 4"/>@endif
                <circle cx="{{ $point['x'] }}" cy="{{ $chartY($point['y']) }}" r="{{ $loop->index === $chart['minimum_index'] ? 5.5 : 4 }}" fill="{{ str_starts_with($point['value'], '-') ? '#dc2626' : ($loop->index === $chart['minimum_index'] ? '#e4a014' : '#078137') }}" stroke="white" stroke-width="1.5"><title>{{ $point['label'] }} · {{ $point['date'] }} · R$ {{ \App\Services\DecimalPresentation::format($point['value'], 2) }}</title></circle>
                <text x="{{ $point['x'] }}" y="{{ $chartY(136) + 27 }}" text-anchor="middle" class="chart-label">{{ $point['label'] }}</text>
            </g>
        @endforeach
    </svg>
</div>

