<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class DashboardPresentation
{
    /** @param array<string, mixed> $overview
     * @return list<array{title: string, description: string, tone: string, icon: string, url: string, action?: string}>
     */
    public function attention(array $overview): array
    {
        $cards = [];
        foreach (['divergent_count' => ['venda(s) com repasse divergente', 'Confira os valores e a conclusão do repasse.', 'warning', 'alert'],
            'pending_count' => ['venda(s) com repasse pendente', 'Acompanhe os recebimentos no módulo Vendas.', 'warning', 'wallet'],
            'partial_count' => ['venda(s) com recebimento parcial', 'Confira o saldo ainda a receber.', 'warning', 'clock'],
            'incomplete_financial' => ['venda(s) com informações financeiras incompletas', 'Complete os valores e as datas disponíveis.', 'info', 'wallet']] as $key => [$title, $description, $tone, $icon]) {
            if ($overview['sales'][$key] > 0) {
                $cards[] = ['title' => $overview['sales'][$key].' '.$title, 'description' => $description, 'tone' => $tone, 'icon' => $icon, 'url' => route('sales')];
            }
        }
        if ($overview['forecast'] && $overview['forecast']['first_shortfall'] !== null) {
            array_unshift($cards, ['title' => 'Falta de caixa prevista no período', 'description' => 'Revise os pagamentos e o capital disponível.', 'tone' => 'danger', 'icon' => 'alert', 'url' => route('cash-flow')]);
        }
        if ($overview['projection_expired']) {
            $cards[] = ['title' => 'Período da projeção encerrado; revise as datas', 'description' => 'Atualize o período no Fluxo de Caixa.', 'tone' => 'warning', 'icon' => 'calendar', 'url' => route('cash-flow')];
        }
        $missingCosts = collect($overview['products'])->whereNotNull('warning')->count();
        if ($missingCosts > 0) {
            $cards[] = ['title' => $missingCosts.' produto(s) com custo incompleto', 'description' => 'Revise a composição dos produtos exibidos.', 'tone' => 'warning', 'icon' => 'box', 'url' => route('products.index')];
        }
        foreach ($overview['tariffs'] as $reference) {
            if ($reference['warnings'] === []) {
                continue;
            }
            $profile = $reference['profile'];
            $reasons = array_map(fn (string $warning): string => match ($warning) {
                'Regra estimada: confirme as tarifas antes de usar.' => 'Tarifas ainda estimadas: confirmar os valores.',
                'Comissão desconhecida. Informe o valor na simulação.' => 'Percentual de comissão não informado.',
                'Tarifa fixa desconhecida. Informe o valor ou zero explícito se não se aplicar.' => 'Tarifa fixa não informada.',
                'Cobrança por pedido: informe manualmente o rateio da tarifa fixa por unidade na simulação.' => 'Cobrança por pedido: conferir o rateio por unidade.',
                'Condições não verificadas. Confira a aplicabilidade à sua venda.' => 'Condições adicionais ainda não verificadas.',
                'Regra desatualizada: vigência encerrada.' => 'Vigência encerrada.',
                'Sem data de revisão definida; confirme se a referência continua atual.' => 'Data de revisão não informada.',
                'Regra desatualizada: revisão pendente.' => 'Revisão da tarifa pendente.',
                default => $warning,
            }, $reference['warnings']);
            $confidence = $profile->confidence === 'confirmed'
                ? ($profile->pricing_model !== 'tiered' && $profile->commission_percent !== null ? 'Percentual confirmado. ' : 'Regra classificada como confirmada. ') : '';
            $cards[] = ['title' => ($profile->channel === 'mercado_livre' ? 'Mercado Livre' : 'Shopee').' — '.$profile->listing_type,
                'description' => $confidence.implode(' ', $reasons), 'tone' => 'neutral', 'icon' => 'percent',
                'url' => route('tariffs.edit', $profile), 'action' => 'Revisar tarifa'];
        }

        return $cards;
    }

    /** @param array<string, mixed> $overview
     * @return array{label: string, description: string, tone: string, icon: string, url?: string}
     */
    public function status(array $overview): array
    {
        if ($overview['forecast'] && $overview['forecast']['first_shortfall'] !== null) {
            return ['label' => 'Caixa em risco', 'description' => 'Há falta de caixa no período projetado.', 'tone' => 'danger', 'icon' => 'alert'];
        }
        if ($overview['sales']['count'] === 0) {
            return ['label' => 'Sem vendas registradas', 'description' => 'Registre a primeira venda para acompanhar os resultados.', 'tone' => 'neutral', 'icon' => 'cart', 'url' => route('sales', ['nova' => 1])];
        }
        if ($overview['sales']['unknown_expected'] > 0) {
            return ['label' => 'Dados insuficientes', 'description' => 'Complete os valores das vendas para conferir os repasses.', 'tone' => 'neutral', 'icon' => 'wallet', 'url' => route('sales')];
        }
        if (! $overview['forecast'] || ! $overview['has_cash_movements'] || $overview['projection_expired']) {
            return ['label' => 'Revisar previsão de caixa', 'description' => $overview['projection_expired'] ? 'Atualize o período da projeção.' : 'Informe entradas e saídas previstas no Fluxo de Caixa.', 'tone' => 'neutral', 'icon' => 'calendar', 'url' => route('cash-flow')];
        }
        if ($overview['alerts'] !== [] || $overview['estimated_tariffs'] > 0 || collect($overview['tariffs'])->contains(fn (array $reference): bool => $reference['warnings'] !== [])) {
            return ['label' => 'Atenção necessária', 'description' => 'Existem pendências e informações a conferir.', 'tone' => 'warning', 'icon' => 'alert'];
        }

        return ['label' => 'Operação em dia', 'description' => 'Sem pendências nos dados disponíveis.', 'tone' => 'success', 'icon' => 'check'];
    }

    /** @param array<string, mixed> $sales
     * @return ?array{received: string, pending: string}
     */
    public function progress(array $sales): ?array
    {
        $expected = BigDecimal::of($sales['expected']);
        if ($sales['count'] === 0 || $sales['unknown_expected'] > 0 || $sales['divergent_count'] > 0 || ! $expected->isPositive() || BigDecimal::of($sales['received'])->isNegative() || BigDecimal::of($sales['received'])->isGreaterThan($expected) || ! BigDecimal::of($sales['received'])->plus($sales['pending'])->isEqualTo($expected)) {
            return null;
        }

        return ['received' => (string) BigDecimal::of($sales['received'])->multipliedBy(100)->dividedBy($expected, 1, RoundingMode::HalfUp),
            'pending' => (string) BigDecimal::of($sales['pending'])->multipliedBy(100)->dividedBy($expected, 1, RoundingMode::HalfUp)];
    }

    /** @param array<string, mixed> $forecast
     * @return array{points: list<array{x: float, y: float, value: string, label: string, date: string}>, ticks: list<array{y: float, value: string}>, line: string, area: string, minimum_index: int}
     */
    public function chart(array $forecast): array
    {
        $values = array_merge([$forecast['weeks'][0]['opening']], array_column($forecast['weeks'], 'closing'));
        $minimum = BigDecimal::min(...$values);
        $maximum = BigDecimal::max(...$values);
        $padding = $maximum->minus($minimum)->isZero() ? BigDecimal::max('1', $maximum->abs()->multipliedBy('0.1')) : $maximum->minus($minimum)->multipliedBy('0.16');
        $lower = $minimum->minus($padding);
        $upper = $maximum->plus($padding);
        $range = $upper->minus($lower);
        $points = [];
        foreach ($values as $index => $value) {
            $ratio = BigDecimal::of($value)->minus($lower)->dividedBy($range, 8, RoundingMode::HalfUp)->toFloat();
            $points[] = ['x' => 76.0 + $index * 77.0, 'y' => 126.0 - $ratio * 100.0, 'value' => $value,
                'label' => $index === 0 ? 'Início' : 'Sem. '.$index,
                'date' => $index === 0 ? $forecast['starts_on'] : $forecast['weeks'][$index - 1]['ends_on']];
        }
        $ticks = [];
        foreach ([0, 1, 2] as $index) {
            $ticks[] = ['y' => 26.0 + $index * 50.0, 'value' => (string) $upper->minus($range->multipliedBy((string) $index)->dividedBy('2', 8, RoundingMode::HalfUp))->toScale(2, RoundingMode::HalfUp)];
        }
        $line = implode(' ', array_map(fn (array $point): string => $point['x'].','.$point['y'], $points));
        $minimumIndex = 0;
        foreach ($points as $index => $point) {
            if (BigDecimal::of($point['value'])->isLessThan($points[$minimumIndex]['value'])) {
                $minimumIndex = $index;
            }
        }

        return ['points' => $points, 'ticks' => $ticks, 'line' => $line, 'area' => '76,136 '.$line.' 692,136', 'minimum_index' => $minimumIndex];
    }
}
