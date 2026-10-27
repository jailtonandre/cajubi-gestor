<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\CashProjection;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

class CashFlowProjection
{
    /**
     * @param  iterable<CashMovement|CashFlowEntry>  $movements
     * @return array{weeks: list<array{number: int, starts_on: string, ends_on: string, opening: string, inflows: string, outflows: string, closing: string, minimum: string, negative: bool}>, minimum: string, additional_capital: string, closing: string, first_shortfall: ?string, excluded_count: int, starts_on: string, ends_on: string}
     */
    public function calculate(CashProjection $projection, iterable $movements): array
    {
        $start = CarbonImmutable::parse($projection->starts_on->toDateString(), CashFlowIntegration::TIMEZONE);
        $end = $start->addDays(55);
        $days = [];
        $excluded = 0;
        $seen = [];
        $totals = array_fill_keys(['realized_inflows', 'planned_inflows', 'realized_outflows', 'planned_outflows', 'capital_inflows'], '0.00');
        foreach ($movements as $movement) {
            if ($movement instanceof CashFlowEntry) {
                if (isset($seen[$movement->key])) {
                    continue;
                }
                $seen[$movement->key] = true;
            }
            $date = ($movement instanceof CashFlowEntry ? $movement->date : ($movement->realized_on ?? $movement->expected_on))->toDateString();
            if ($date < $start->toDateString() || $date > $end->toDateString()) {
                $excluded++;

                continue;
            }
            $direction = $movement->direction;
            $stage = $movement instanceof CashFlowEntry ? $movement->stage : ($movement->realized_on ? 'realized' : 'planned');
            $totalKey = $stage.'_'.($direction === 'inflow' ? 'inflows' : 'outflows');
            $totals[$totalKey] = (string) BigDecimal::of($totals[$totalKey])->plus($movement->amount);
            if ($movement->nature === 'capital_contribution' && $direction === 'inflow') {
                $totals['capital_inflows'] = (string) BigDecimal::of($totals['capital_inflows'])->plus($movement->amount);
            }
            $days[$date][$totalKey] = ($days[$date][$totalKey] ?? BigDecimal::of('0.00'))->plus($movement->amount);
            $days[$date][$direction] = ($days[$date][$direction] ?? BigDecimal::of('0.00'))->plus($movement->amount);
        }

        $balance = BigDecimal::of($projection->opening_balance)->toScale(2);
        $minimum = $balance;
        $firstShortfall = $balance->isNegative() ? $start->toDateString() : null;
        $weeks = [];
        for ($week = 0; $week < 8; $week++) {
            $opening = $balance;
            $inflows = BigDecimal::of('0.00');
            $outflows = BigDecimal::of('0.00');
            $weeklyMinimum = $balance;
            $weekTotals = array_fill_keys(['realized_inflows', 'planned_inflows', 'realized_outflows', 'planned_outflows'], '0.00');
            for ($day = 0; $day < 7; $day++) {
                $date = $start->addDays($week * 7 + $day)->toDateString();
                foreach ($weekTotals as $key => $value) {
                    $weekTotals[$key] = (string) BigDecimal::of($value)->plus($days[$date][$key] ?? '0.00');
                }
                $incoming = $days[$date]['inflow'] ?? BigDecimal::of('0.00');
                $outgoing = $days[$date]['outflow'] ?? BigDecimal::of('0.00');
                $inflows = $inflows->plus($incoming);
                $outflows = $outflows->plus($outgoing);
                $balance = $balance->plus($incoming)->minus($outgoing);
                $weeklyMinimum = BigDecimal::min($weeklyMinimum, $balance);
                $minimum = BigDecimal::min($minimum, $balance);
                if ($firstShortfall === null && $balance->isNegative()) {
                    $firstShortfall = $date;
                }
            }
            $weeks[] = ['number' => $week + 1, 'starts_on' => $start->addDays($week * 7)->format('d/m/Y'),
                'ends_on' => $start->addDays($week * 7 + 6)->format('d/m/Y'),
                'opening' => (string) $opening, 'inflows' => (string) $inflows, 'outflows' => (string) $outflows,
                'closing' => (string) $balance, 'minimum' => (string) $weeklyMinimum, 'negative' => $weeklyMinimum->isNegative()] + $weekTotals;
        }

        return ['weeks' => $weeks, 'minimum' => (string) $minimum,
            'additional_capital' => (string) BigDecimal::max('0.00', $minimum->negated()),
            'closing' => (string) $balance, 'first_shortfall' => $firstShortfall, 'excluded_count' => $excluded,
            'starts_on' => $start->toDateString(), 'ends_on' => $end->toDateString()] + $totals;
    }
}
