<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\CashProjection;
use Brick\Math\BigDecimal;

class CashFlowProjection
{
    /**
     * @param  iterable<CashMovement>  $movements
     * @return array{weeks: list<array{number: int, starts_on: string, ends_on: string, opening: string, inflows: string, outflows: string, closing: string, minimum: string, negative: bool}>, minimum: string, additional_capital: string, closing: string, first_shortfall: ?string, excluded_count: int, starts_on: string, ends_on: string}
     */
    public function calculate(CashProjection $projection, iterable $movements): array
    {
        $start = $projection->starts_on;
        $end = $start->addDays(55);
        $days = [];
        $excluded = 0;
        foreach ($movements as $movement) {
            $date = $movement->expected_on->toDateString();
            if ($date < $start->toDateString() || $date > $end->toDateString()) {
                $excluded++;

                continue;
            }
            $direction = $movement->direction;
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
            for ($day = 0; $day < 7; $day++) {
                $date = $start->addDays($week * 7 + $day)->toDateString();
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
                'closing' => (string) $balance, 'minimum' => (string) $weeklyMinimum, 'negative' => $weeklyMinimum->isNegative()];
        }

        return ['weeks' => $weeks, 'minimum' => (string) $minimum,
            'additional_capital' => (string) BigDecimal::max('0.00', $minimum->negated()),
            'closing' => (string) $balance, 'first_shortfall' => $firstShortfall, 'excluded_count' => $excluded,
            'starts_on' => $start->toDateString(), 'ends_on' => $end->toDateString()];
    }
}
