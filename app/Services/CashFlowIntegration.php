<?php

namespace App\Services;

use App\Models\CashProjection;
use App\Models\Sale;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CashFlowIntegration
{
    public const TIMEZONE = 'America/Sao_Paulo';

    public function __construct(private SalesLedger $ledger, private CashFlowProjection $calculator) {}

    public static function needsRefundReview(Sale $sale): bool
    {
        $latest = $sale->receipts->where('kind', 'refund')->max('id');

        return $latest !== null && $latest > ($sale->cash_forecast_reviewed_refund_id ?? 0);
    }

    /** @return array<string, mixed> */
    public function snapshot(CashProjection $projection, bool $preview = false): array
    {
        $outer = DB::transactionLevel() === 0;

        return DB::transaction(function () use ($projection, $outer, $preview): array {
            if ($outer && DB::getDriverName() === 'pgsql') {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }
            $projection = CashProjection::query()->findOrFail($projection->id);
            $manuals = $projection->movements()->orderBy('expected_on')->orderBy('id')->get();
            $enabled = (bool) $projection->sales_integration_enabled || $preview;
            $entries = [];
            $pending = [];
            $reviews = $manuals->where('integration_review', 'unreviewed')->values();
            $legacyEntries = [];
            foreach ($manuals as $manual) {
                $date = $manual->realized_on ?? $manual->expected_on;
                $entry = new CashFlowEntry('manual:'.$manual->id, 'manual', $manual->id, null,
                    $manual->description, CarbonImmutable::parse($date->toDateString(), self::TIMEZONE),
                    $manual->amount, $manual->direction, $manual->realized_on ? 'realized' : 'planned', $manual->nature ?? 'other');
                $legacyEntries[] = $entry;
                if (! $enabled || $manual->integration_review === 'independent') {
                    $entries[] = $entry;
                }
            }
            if ($enabled) {
                $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
                foreach (Sale::query()->with('receipts')->lazyById(200) as $sale) {
                    foreach ($sale->receipts as $receipt) {
                        $entries[] = new CashFlowEntry('receipt:'.$receipt->id, 'receipt', $receipt->id, $sale->id,
                            ($receipt->kind === 'refund' ? 'Estorno' : 'Recebimento').' · '.$sale->order_reference.' · '.$receipt->reference,
                            CarbonImmutable::parse($receipt->received_on->toDateString(), self::TIMEZONE), $receipt->amount,
                            $receipt->kind === 'refund' ? 'outflow' : 'inflow', 'realized', 'commercial');
                    }
                    if ($sale->cancelled_at !== null) {
                        continue;
                    }
                    $figures = $this->ledger->figures($sale);
                    $amount = $figures['pending'];
                    $reason = match (true) {
                        $figures['expected'] === null => 'Repasse líquido desconhecido',
                        BigDecimal::of($figures['expected'])->isNegative() => 'Repasse negativo: revisar obrigação financeira',
                        $amount !== null && BigDecimal::of($amount)->isZero() => null,
                        $sale->reconciliation_closed => 'Conferência concluída com diferença: sem novo repasse presumido',
                        self::needsRefundReview($sale) => 'Revisar previsão após estorno',
                        $sale->expected_on === null => 'Sem data prevista de repasse',
                        $sale->expected_on->toDateString() < $today->toDateString() => 'Previsão vencida: reagende em Vendas',
                        default => null,
                    };
                    if ($reason !== null) {
                        $pending[] = ['sale_id' => $sale->id, 'reference' => $sale->order_reference,
                            'reason' => $reason, 'amount' => $figures['expected'] !== null && BigDecimal::of($figures['expected'])->isNegative() ? $figures['expected'] : $amount, 'expected_on' => $sale->expected_on?->toDateString()];
                    } elseif ($amount !== null && BigDecimal::of($amount)->isPositive()) {
                        $entries[] = new CashFlowEntry('sale-expected:'.$sale->id, 'sale', $sale->id, $sale->id,
                            'Repasse previsto · '.$sale->order_reference,
                            CarbonImmutable::parse($sale->expected_on->toDateString(), self::TIMEZONE), $amount, 'inflow', 'planned', 'commercial');
                    }
                }
            }
            $forecast = $this->calculator->calculate($projection, $entries);
            $visibleEntries = fn (array $items) => collect($items)->filter(fn (CashFlowEntry $entry): bool => $entry->date->toDateString() >= $forecast['starts_on'] && $entry->date->toDateString() <= $forecast['ends_on'])
                ->sort(fn (CashFlowEntry $left, CashFlowEntry $right): int => [$left->date->toDateString(), $left->source, $left->source_id, $left->key] <=> [$right->date->toDateString(), $right->source, $right->source_id, $right->key])->values();

            $fingerprint = hash('sha256', serialize([$projection->opening_balance, $projection->starts_on->toDateString(), $entries, $pending, $manuals->toArray()]));

            return ['fingerprint' => $fingerprint, 'projection' => $projection, 'enabled' => $enabled, 'entries' => $visibleEntries($entries), 'pending' => $pending,
                'reviews' => $reviews, 'forecast' => $forecast, 'manuals' => $manuals,
                'legacy_entries' => $preview && ! $projection->sales_integration_enabled ? $visibleEntries($legacyEntries) : null,
                'legacy_forecast' => $preview && ! $projection->sales_integration_enabled ? $this->calculator->calculate($projection, $legacyEntries) : null];
        });
    }

    public function reviewManual(CashProjection $projection, int $id, string $review, ?int $saleId): void
    {
        Validator::make(['review' => $review, 'sale_id' => $saleId], [
            'review' => ['required', Rule::in(['independent', 'replaced'])],
            'sale_id' => ['nullable', 'required_if:review,replaced', 'integer', 'exists:sales,id'],
        ], ['sale_id.required_if' => 'Selecione a venda que substitui este lançamento.'])->validate();
        DB::transaction(function () use ($projection, $id, $review, $saleId): void {
            CashProjection::query()->lockForUpdate()->findOrFail($projection->id);
            $movement = $projection->movements()->lockForUpdate()->findOrFail($id);
            if ($movement->integration_review === $review && $movement->replacement_sale_id === ($review === 'replaced' ? $saleId : null)) {
                return;
            }
            $movement->integration_review = $review;
            $movement->replacement_sale_id = $review === 'replaced' ? $saleId : null;
            $movement->integration_reviewed_at = now();
            $movement->save();
        });
    }

    public function activate(CashProjection $projection, string $fingerprint): void
    {
        DB::transaction(function () use ($projection, $fingerprint): void {
            $record = CashProjection::query()->lockForUpdate()->findOrFail($projection->id);
            if ($record->sales_integration_enabled) {
                return;
            }
            if ($record->movements()->where('integration_review', 'unreviewed')->exists()) {
                throw ValidationException::withMessages(['integration' => 'Revise todos os lançamentos manuais antes de ativar a integração.']);
            }
            if (! hash_equals($fingerprint, $this->snapshot($record, true)['fingerprint'])) {
                throw ValidationException::withMessages(['integration' => 'Os dados mudaram. Confira a prévia atualizada e confirme novamente antes de ativar.']);
            }
            $record->sales_integration_enabled = true;
            $record->integration_reviewed_at = now();
            $record->save();
        });
    }
}
