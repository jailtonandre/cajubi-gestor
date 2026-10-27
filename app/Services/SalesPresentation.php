<?php

namespace App\Services;

use App\Models\Sale;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

class SalesPresentation
{
    public function __construct(private SalesLedger $ledger) {}

    /**
     * @param  array<string, mixed>  $figures
     * @return array{label: string, window: ?string, overdue: bool}
     */
    public function deadline(Sale $sale, array $figures): array
    {
        if ($sale->cancelled_at !== null) {
            return ['label' => 'Cancelada', 'window' => null, 'overdue' => false];
        }
        if ($figures['pending'] !== null && BigDecimal::of($figures['pending'])->isZero()) {
            $received = $figures['status'] === 'Recebido' || BigDecimal::of($figures['received'])->isPositive();

            return ['label' => $received ? 'Recebido' : 'Sem valor a receber', 'window' => null, 'overdue' => false];
        }
        if ($sale->reconciliation_closed) {
            return ['label' => 'Conferência concluída', 'window' => null, 'overdue' => false];
        }
        if (CashFlowIntegration::needsRefundReview($sale)) {
            return ['label' => 'Revisar previsão após estorno', 'window' => null, 'overdue' => false];
        }
        if ($sale->expected_on === null) {
            return ['label' => 'Sem previsão', 'window' => 'undated', 'overdue' => false];
        }
        $today = CarbonImmutable::now(CashFlowIntegration::TIMEZONE)->startOfDay();
        $date = CarbonImmutable::parse($sale->expected_on->toDateString(), CashFlowIntegration::TIMEZONE);
        $days = (int) $today->diffInDays($date);
        $window = $days < 0 ? 'overdue' : ($days === 0 ? 'today' : ($days <= 7 ? 'next7' : 'later'));
        $label = match (true) {
            $days < 0 => 'Atrasado há '.abs($days).' dia'.(abs($days) === 1 ? '' : 's'),
            $days === 0 => 'Previsto para hoje',
            $days === 1 => 'Recebe amanhã',
            default => 'Recebe em '.$days.' dias',
        };

        return ['label' => $label, 'window' => $window, 'overdue' => $days < 0];
    }

    /**
     * Aggregates ledger results without estimating missing costs or fees.
     *
     * @param  iterable<Sale>  $sales
     * @return array<string, mixed>
     */
    public function overview(iterable $sales): array
    {
        $summary = ['count' => 0, 'cancelled' => 0, 'cancelled_received' => '0.00'];
        foreach (['gross', 'expected', 'received', 'pending', 'revenue', 'fees', 'cost', 'contribution'] as $field) {
            $summary[$field] = '0.00';
            $summary['unknown_'.$field] = 0;
        }
        $windows = [];
        foreach (['today' => 'Hoje', 'next7' => 'Próximos 7 dias', 'later' => 'Após 7 dias', 'overdue' => 'Em atraso', 'undated' => 'Sem previsão'] as $key => $label) {
            $windows[$key] = ['label' => $label, 'amount' => '0.00', 'count' => 0, 'unknown' => 0];
        }
        $channels = ['mercado_livre' => ['label' => 'Mercado Livre', 'amount' => '0.00', 'count' => 0, 'unknown' => 0],
            'shopee' => ['label' => 'Shopee', 'amount' => '0.00', 'count' => 0, 'unknown' => 0]];
        $counts = array_fill_keys(['all', 'pending', 'received', 'overdue', 'partial', 'divergent', 'cancelled'], 0);
        $rows = [];
        foreach ($sales as $sale) {
            $figures = $this->ledger->figures($sale);
            $deadline = $this->deadline($sale, $figures);
            $rows[] = ['sale' => $sale, 'figures' => $figures, 'deadline' => $deadline];
            $counts['all']++;
            if ($sale->cancelled_at !== null) {
                $summary['cancelled']++;
                $summary['cancelled_received'] = (string) BigDecimal::of($summary['cancelled_received'])->plus($figures['received']);
                $counts['cancelled']++;

                continue;
            }
            $summary['count']++;
            foreach (['received' => $figures['status'] === 'Recebido', 'partial' => $figures['status'] === 'Parcial',
                'divergent' => $figures['status'] === 'Divergente', 'overdue' => $deadline['overdue'],
                'pending' => $figures['pending'] === null || BigDecimal::of($figures['pending'])->isPositive()] as $key => $matches) {
                if ($matches) {
                    $counts[$key]++;
                }
            }
            foreach (['gross' => $sale->gross_amount, 'expected' => $figures['expected'], 'received' => $figures['received'],
                'pending' => $figures['pending'], 'revenue' => $figures['revenue'], 'fees' => $sale->marketplace_fees,
                'cost' => $figures['cost'], 'contribution' => $figures['contribution']] as $key => $value) {
                if ($value === null) {
                    $summary['unknown_'.$key]++;
                } else {
                    $summary[$key] = (string) BigDecimal::of($summary[$key])->plus($value);
                }
            }
            $channels[$sale->marketplace]['count']++;
            if ($sale->gross_amount === null) {
                $channels[$sale->marketplace]['unknown']++;
            } else {
                $channels[$sale->marketplace]['amount'] = (string) BigDecimal::of($channels[$sale->marketplace]['amount'])->plus($sale->gross_amount);
            }
            if ($deadline['window'] !== null) {
                $key = $deadline['window'];
                $windows[$key]['count']++;
                if ($figures['pending'] === null) {
                    $windows[$key]['unknown']++;
                } else {
                    $windows[$key]['amount'] = (string) BigDecimal::of($windows[$key]['amount'])->plus($figures['pending']);
                }
            }
        }

        $distribution = null;
        if ($summary['unknown_gross'] === 0 && BigDecimal::of($summary['gross'])->isPositive()) {
            $distribution = (string) BigDecimal::of($channels['mercado_livre']['amount'])
                ->multipliedBy('100')->dividedBy($summary['gross'], 2, RoundingMode::HalfUp);
        }

        return ['summary' => $summary, 'windows' => $windows, 'channels' => $channels, 'counts' => $counts, 'rows' => $rows, 'distribution' => $distribution];
    }
}
