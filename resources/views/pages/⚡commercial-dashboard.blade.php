<?php

use App\Services\CommercialDashboard;
use App\Services\DashboardPresentation;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Painel Comercial')] class extends Component
{
    protected CommercialDashboard $dashboard;

    protected DashboardPresentation $presentation;

    public function boot(CommercialDashboard $dashboard, DashboardPresentation $presentation): void
    {
        $this->dashboard = $dashboard;
        $this->presentation = $presentation;
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function overview(): array
    {
        return $this->dashboard->snapshot();
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function visual(): array
    {
        return ['status' => $this->presentation->status($this->overview), 'progress' => $this->presentation->progress($this->overview['sales']),
            'attention' => $this->presentation->attention($this->overview),
            'chart' => $this->overview['has_cash_movements'] ? $this->presentation->chart($this->overview['forecast']) : null];
    }
};
?>

<div class="commercial-dashboard">
    @php($overview = $this->overview)
    @php($sales = $overview['sales'])
    @php($forecast = $overview['forecast'])
    @php($visual = $this->visual)
    <div class="dashboard-heading">
        <div><h1>Painel Comercial</h1><p>Resumo da sua operação na cajubi. Acompanhe os principais números e o que precisa de atenção.</p></div>
        <a href="{{ $visual['status']['url'] ?? '#attention-title' }}" @if(isset($visual['status']['url'])) wire:navigate @endif class="dashboard-status tone-{{ $visual['status']['tone'] }}"><x-ui-icon :name="$visual['status']['icon']"/><span><strong>{{ $visual['status']['label'] }}</strong><small>{{ $visual['status']['description'] }}</small></span><x-ui-icon name="chevron" class="status-chevron"/></a>
    </div>

    <section class="dashboard-indicators" aria-label="Visão geral · vendas reais de todo o período">
        <article class="dashboard-stat"><span class="dashboard-icon-badge tone-success"><x-ui-icon name="cart"/></span><div class="dashboard-stat-copy"><h2>Vendas registradas</h2><p class="dashboard-stat-value">{{ $sales['count'] }}</p><p class="dashboard-stat-note">Pedidos não cancelados</p></div></article>
        @foreach(['gross' => ['Valor vendido', 'box', 'success', 'unknown_gross', 'Total dos produtos vendidos'], 'expected' => ['Líquido esperado', 'chart', 'gold', 'unknown_expected', 'Após tarifas e descontos informados'], 'received' => ['Total recebido', 'bank', 'success', null, 'Recebimentos menos estornos'], 'pending' => ['A receber', 'clock', 'gold', 'unknown_expected', 'Saldo dos repasses conhecidos']] as $key => [$label, $icon, $tone, $unknown, $note])
            <article wire:key="indicator-{{ $key }}" class="dashboard-stat"><span class="dashboard-icon-badge tone-{{ $tone }}"><x-ui-icon :name="$icon"/></span><div class="dashboard-stat-copy"><h2>{{ $label }}</h2>
                <div class="dashboard-stat-value">
                    @if($sales['count'] === 0)<span class="dashboard-unknown">—</span>
                    @elseif($unknown !== null && $sales[$unknown] === $sales['count'])<span class="dashboard-unknown-text">Aguardando dados</span>
                    @else<x-money :value="$sales[$key]"/>
                    @endif
                </div>
                @if($unknown !== null && $sales[$unknown] > 0)<p class="dashboard-stat-note text-amber-800">{{ $sales[$unknown] }} venda(s) sem dados suficientes; total limitado aos valores conhecidos.</p>
                @elseif($sales['count'] === 0)<p class="dashboard-stat-note">Nenhuma venda registrada</p>
                @elseif(in_array($key, ['received', 'pending']) && $visual['progress'])
                    @php($percentage = $visual['progress'][$key])
                    <p class="dashboard-stat-note"><strong>{{ \App\Services\DecimalPresentation::format($percentage, 1) }}%</strong> do esperado</p>
                    <div class="dashboard-progress" role="progressbar" aria-label="{{ $key === 'received' ? 'Proporção recebida' : 'Proporção a receber' }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percentage }}"><span class="{{ $key === 'pending' ? 'progress-gold' : '' }}" style="width: {{ $percentage }}%"></span></div>
                @else<p class="dashboard-stat-note">{{ $note }}</p>
                @endif
            </div></article>
        @endforeach
    </section>
    @if($sales['cancelled'] > 0)<p class="dashboard-footnote">{{ $sales['cancelled'] }} venda(s) cancelada(s) fora dos indicadores. Saldo recebido: <x-money :value="$sales['cancelled_received']"/>. <a href="{{ route('sales') }}" wire:navigate>Conferir histórico</a></p>@endif

    <div class="dashboard-middle-grid">
        <section class="dashboard-card dashboard-cash" aria-labelledby="cash-title">
            <div class="dashboard-card-heading"><h2 id="cash-title"><span class="dashboard-icon-badge tone-success"><x-ui-icon name="wallet"/></span>Fluxo de caixa <span class="heading-aside">8 semanas</span></h2><a href="{{ route('cash-flow') }}" wire:navigate class="dashboard-text-link">Abrir fluxo de caixa <x-ui-icon name="arrow"/></a></div>
            @if($forecast)
                <p class="dashboard-period">Previsão manual · {{ $overview['projection']->starts_on->format('d/m/Y') }} a {{ $overview['projection']->starts_on->addDays(55)->format('d/m/Y') }}</p>
                @if($overview['projection_expired'])<p class="dashboard-inline-note tone-warning">Este período já terminou. Revise a data inicial no Fluxo de Caixa para avaliar as próximas semanas.</p>@endif
                @if($overview['has_cash_movements'])
                <div class="dashboard-cash-values">
                    <div><h3>Saldo inicial</h3><x-money :value="$overview['projection']->opening_balance"/><small>Informado na projeção</small></div>
                    <div><h3>Menor saldo projetado</h3><x-money :value="$forecast['minimum']" :class="$forecast['first_shortfall'] ? 'text-red-700' : ''"/><small>Inclui saldos diários</small></div>
                    <div><h3>Capital adicional</h3><x-money :value="$forecast['additional_capital']"/><small>Necessário no período</small></div>
                    <div><h3>Saldo ao final</h3><x-money :value="$forecast['closing']" :class="str_starts_with($forecast['closing'], '-') ? 'text-red-700' : 'text-cajubi-700'"/><small>Fim da oitava semana</small></div>
                </div>
                <x-cash-chart :chart="$visual['chart']"/>
                @php($lowestPoint = $visual['chart']['points'][$visual['chart']['minimum_index']])
                <p class="dashboard-chart-caption"><span class="chart-legend-dot"></span>Menor ponto exibido: <x-money :value="$lowestPoint['value']"/> · {{ $lowestPoint['label'] }}.</p>
                <details class="dashboard-data-details"><summary>Consultar valores do gráfico</summary><p class="dashboard-footnote">Pontos = saldo inicial e fechamentos semanais. O menor saldo considera também os dias entre esses pontos.</p><div class="overflow-x-auto"><table class="dashboard-table"><thead><tr><th>Período</th><th>Saldo exibido</th></tr></thead><tbody>@foreach($visual['chart']['points'] as $point)<tr wire:key="chart-data-{{ $loop->index }}"><td>{{ $point['label'] }} · {{ $point['date'] }}</td><td><x-money :value="$point['value']"/></td></tr>@endforeach</tbody></table></div></details>
                @else
                    <div class="dashboard-empty dashboard-empty-chart"><h3>Sem movimentações previstas cadastradas.</h3><p>Adicione entradas e saídas para acompanhar as oito semanas.</p><p>Saldo inicial informado: <x-money :value="$overview['projection']->opening_balance"/>.</p><a href="{{ route('cash-flow') }}" wire:navigate class="dashboard-text-link">Preparar fluxo de caixa <x-ui-icon name="arrow"/></a></div>
                @endif
                @if($forecast['first_shortfall'])<p class="dashboard-inline-note tone-danger">Falta de caixa prevista a partir de {{ \Carbon\CarbonImmutable::parse($forecast['first_shortfall'])->format('d/m/Y') }} no período salvo.</p>@endif
            @else
                <div class="dashboard-empty dashboard-empty-chart"><h3>Projeção ainda não cadastrada</h3><p>Informe o saldo inicial e as movimentações para acompanhar as oito semanas.</p><a href="{{ route('cash-flow') }}" wire:navigate class="dashboard-text-link">Preparar fluxo de caixa <x-ui-icon name="arrow"/></a></div>
            @endif
            <p class="dashboard-footnote">Previsão manual e recebimentos reais são acompanhados separadamente.</p>
        </section>

        <section class="dashboard-card dashboard-attention" aria-labelledby="attention-title">
            <div class="dashboard-card-heading"><h2 id="attention-title"><x-ui-icon name="alert" class="text-amber-700"/>Pontos de atenção</h2><a href="#premises-title" class="dashboard-text-link">Ver premissas <x-ui-icon name="arrow"/></a></div>
            @if($visual['attention'] === [])<div class="dashboard-empty"><span class="dashboard-icon-badge tone-success"><x-ui-icon name="check"/></span><p>Nenhuma pendência identificada nos dados disponíveis.</p></div>
            @else
                <ul class="dashboard-attention-list">
                    @foreach($visual['attention'] as $card)<li wire:key="attention-{{ $loop->index }}"><a href="{{ $card['url'] }}" wire:navigate class="dashboard-attention-item tone-{{ $card['tone'] }}"><span class="dashboard-icon-badge"><x-ui-icon :name="$card['icon']" :class="$card['tone'] === 'neutral' ? 'text-amber-700' : ''"/></span><span><strong>{{ $card['title'] }}</strong><small>{{ $card['description'] }}</small>@if(isset($card['action']))<span class="dashboard-attention-action">{{ $card['action'] }}</span>@endif</span><x-ui-icon name="chevron" class="attention-chevron"/></a></li>@endforeach
                </ul>
            @endif
        </section>
    </div>

    <div class="dashboard-bottom-grid">
        <section class="dashboard-card dashboard-products" aria-labelledby="products-title">
            <div class="dashboard-card-heading"><h2 id="products-title"><x-ui-icon name="box" class="text-cajubi-700"/>Principais produtos</h2><a href="{{ route('sales') }}" wire:navigate class="dashboard-text-link">Ver vendas <x-ui-icon name="arrow"/></a></div>
            @if($overview['product_sales'] === [])
                <div class="dashboard-empty"><h3>Nenhuma venda não cancelada registrada</h3><p>Os produtos aparecem aqui após a primeira venda.</p><a href="{{ route('sales', ['nova' => 1]) }}" wire:navigate class="dashboard-text-link">Registrar venda <x-ui-icon name="arrow"/></a></div>
            @else
                <div class="dashboard-table-scroll"><table class="dashboard-table"><thead><tr><th>Produto</th><th>Qtd.</th><th>Valor vendido</th><th>Líquido esperado</th><th>Recebido</th><th>A receber</th></tr></thead><tbody>
                    @foreach($overview['product_sales'] as $row)<tr wire:key="sold-product-{{ $row['product']->id }}"><td><a href="{{ route('products.edit', $row['product']) }}" wire:navigate class="dashboard-product-name"><span class="dashboard-product-avatar"><x-ui-icon name="box"/></span><span>{{ $row['product']->name }}</span></a></td><td>{{ $row['quantity'] }}</td>@foreach(['gross', 'expected', 'received', 'pending'] as $key)<td wire:key="product-{{ $row['product']->id }}-{{ $key }}">@if(($key === 'gross' && $row['unknown_gross'] > 0) || (in_array($key, ['expected', 'pending']) && $row['unknown_expected'] > 0))<span class="text-xs text-amber-800">Dados incompletos</span>@else<x-money :value="$row[$key]"/>@endif</td>@endforeach</tr>@endforeach
                </tbody></table></div><p class="dashboard-footnote">Até 6 produtos com vendas não canceladas, em ordem alfabética. Não representa um ranking.</p>
            @endif
        </section>

        <section class="dashboard-card dashboard-premises" aria-labelledby="premises-title">
            <div class="dashboard-card-heading"><h2 id="premises-title"><x-ui-icon name="settings"/>Premissas comerciais</h2><a href="#reference-details" class="dashboard-text-link">Ver todas <x-ui-icon name="arrow"/></a></div>
            <div class="dashboard-premise-list">
                <a href="{{ route('products.index') }}" wire:navigate class="dashboard-premise-item"><span class="dashboard-icon-badge tone-success"><x-ui-icon name="box"/></span><span><strong>Custos dos produtos</strong><small>{{ $overview['product_count'] }} produto(s) ativo(s)</small></span><x-ui-icon name="chevron"/></a>
                @foreach(['mercado_livre' => 'Tarifas do Mercado Livre', 'shopee' => 'Tarifas da Shopee'] as $channel => $label)
                    @php($references = collect($overview['tariffs'])->filter(fn ($reference) => $reference['profile']->channel === $channel))
                    <a href="{{ route('tariffs.index', ['marketplace' => $channel]) }}" wire:navigate class="dashboard-premise-item"><span class="dashboard-icon-badge tone-gold"><x-ui-icon name="percent"/></span><span><strong>{{ $label }}</strong>
                        <small>{{ $references->isEmpty() ? 'Nenhuma regra ativa cadastrada' : $references->count().' regra(s) ativa(s)' }}</small>
                    </span><x-ui-icon name="chevron"/></a>
                @endforeach
            </div>
        </section>

        <section class="dashboard-card dashboard-quick" aria-labelledby="quick-title"><div class="dashboard-card-heading"><h2 id="quick-title"><x-ui-icon name="bolt" class="text-cajubi-700"/>Acesso rápido</h2></div><div class="dashboard-quick-grid">
            @foreach([['sales', ['nova' => 1], 'cart', 'success', 'Registrar venda', 'Incluir novo pedido'], ['simulator', [], 'calculator', 'gold', 'Simular preço', 'Calcular margem'], ['scenarios', [], 'bars', 'neutral', 'Ver cenários', 'Testar estratégias'], ['cash-flow', [], 'trend', 'gold', 'Fluxo de caixa', 'Revisar projeção']] as [$route, $parameters, $icon, $tone, $title, $description])
                <a wire:key="quick-{{ $route }}" href="{{ route($route, $parameters) }}" wire:navigate class="dashboard-quick-item tone-{{ $tone }}"><x-ui-icon :name="$icon"/><strong class="text-stone-800">{{ $title }}</strong><small class="text-stone-600">{{ $description }}</small></a>
            @endforeach
        </div></section>
    </div>

    <details id="reference-details" class="dashboard-card dashboard-reference-details" x-data x-init="if (location.hash === '#reference-details') $el.open = true" x-on:hashchange.window="if (location.hash === '#reference-details') $el.open = true"><summary>Referências de custos e tarifas <span>Consultar valores e datas</span><x-ui-icon name="chevron"/></summary><div class="p-5"><x-dashboard-references :overview="$overview"/></div></details>
</div>

