<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleReceipt;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SalesLedger
{
    public const MONEY_FIELDS = ['gross_amount', 'seller_discount', 'marketplace_fees', 'seller_freight', 'other_adjustment', 'expedition_cost', 'paid_labor'];

    public function __construct(private ProductCostCalculator $costs) {}

    /** @param array<string, mixed> $data */
    public function save(?Sale $sale, array $data, ?int $version = null): Sale
    {
        try {
            return DB::transaction(function () use ($sale, $data, $version): Sale {
                $record = $sale ? Sale::query()->lockForUpdate()->findOrFail($sale->id) : new Sale;
                $this->assertEditable($record, $version);
                $reference = $data['order_reference'] ?? null;
                $data['order_reference'] = is_string($reference) ? Str::upper(trim($reference)) : $reference;
                foreach (self::MONEY_FIELDS as $field) {
                    $value = $data[$field] ?? null;
                    $data[$field] = is_string($value) ? (trim($value) === '' ? null : DecimalPresentation::input($value)) : $value;
                }
                $data['expected_on'] = ($data['expected_on'] ?? '') === '' ? null : $data['expected_on'];
                $data['reconciliation_closed'] = $data['reconciliation_closed'] ?? false;
                $data['freight_source'] = ($data['freight_source'] ?? '') === '' ? null : $data['freight_source'];
                $rules = [
                    'marketplace' => ['required', Rule::in(['mercado_livre', 'shopee'])],
                    'order_reference' => ['bail', 'required', 'string', 'max:100', Rule::unique('sales')->where('marketplace', is_string($data['marketplace'] ?? null) ? $data['marketplace'] : '')->ignore($record)],
                    'sold_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:'.now('America/Sao_Paulo')->toDateString()],
                    'product_id' => ['required', 'integer', 'exists:products,id'],
                    'quantity' => ['required', 'integer', 'min:1', 'max:999999999'],
                    'expected_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:sold_on', 'before_or_equal:9999-12-31'],
                    'notes' => ['nullable', 'string', 'max:3000'],
                    'freight_source' => ['nullable', Rule::in(['marketplace', 'external'])],
                    'reconciliation_closed' => ['required', 'boolean'],
                    'reconciliation_note' => ['nullable', 'required_if:reconciliation_closed,true', 'string', 'max:3000'],
                ];
                foreach (self::MONEY_FIELDS as $field) {
                    $rules[$field] = ['nullable', 'string', $field === 'other_adjustment' ? 'regex:/\A-?[0-9]{1,12}(\.[0-9]{1,2})?\z/' : 'regex:/\A[0-9]{1,12}(\.[0-9]{1,2})?\z/'];
                }
                $validated = Validator::make($data, $rules, [
                    'order_reference.unique' => 'Este pedido já está registrado neste marketplace, inclusive se cancelado.',
                    'regex' => 'Informe :attribute em reais, com até 12 dígitos e 2 casas decimais.',
                    'reconciliation_note.required_if' => 'Explique a conclusão da conferência.',
                ], ['marketplace' => 'marketplace', 'order_reference' => 'pedido', 'sold_on' => 'data da venda', 'product_id' => 'produto',
                    'quantity' => 'quantidade', 'expected_on' => 'data prevista', 'gross_amount' => 'valor vendido',
                    'seller_discount' => 'desconto do vendedor', 'marketplace_fees' => 'tarifas cobradas', 'seller_freight' => 'frete descontado',
                    'other_adjustment' => 'outros ajustes', 'expedition_cost' => 'expedição', 'paid_labor' => 'mão de obra paga'])->validate();
                if ($record->exists && $record->receipts()->exists()) {
                    foreach (['marketplace', 'order_reference', 'product_id'] as $field) {
                        if ((string) $record->$field !== (string) $validated[$field]) {
                            throw ValidationException::withMessages([$field => 'Com recebimentos registrados, preserve a identificação e o produto da venda.']);
                        }
                    }
                    if ($validated['sold_on'] > $record->receipts()->min('received_on')) {
                        throw ValidationException::withMessages(['sold_on' => 'A venda não pode ocorrer depois de um recebimento registrado.']);
                    }
                }
                $changedProduct = ! $record->exists || $record->product_id !== (int) $validated['product_id'];
                $record->fill($validated);
                if ($record->gross_amount !== null && $record->seller_discount !== null && BigDecimal::of($record->seller_discount)->isGreaterThan($record->gross_amount)) {
                    throw ValidationException::withMessages(['seller_discount' => 'O desconto do vendedor não pode superar o valor dos produtos.']);
                }
                if ($changedProduct) {
                    $product = Product::query()->findOrFail($validated['product_id']);
                    $record->product_snapshot = ['name' => $product->name, 'sku' => $product->sku];
                    $this->snapshotCost($record, $product);
                }
                if ($record->reconciliation_closed && $this->figures($record)['expected'] === null) {
                    throw ValidationException::withMessages(['reconciliation_closed' => 'Complete os valores do repasse antes de concluir a conferência.']);
                }
                $record->version = $record->exists ? $record->version + 1 : 1;
                $record->save();
                $this->revision($record, $sale ? 'updated' : 'created');

                return $record->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['order_reference' => 'Este pedido já está registrado neste marketplace.']);
        }
    }

    /** @param array<string, mixed> $data */
    public function receive(Sale $sale, array $data, string $submissionKey): SaleReceipt
    {
        return DB::transaction(function () use ($sale, $data, $submissionKey): SaleReceipt {
            $record = Sale::query()->lockForUpdate()->findOrFail($sale->id);
            Validator::make(['submission_key' => $submissionKey], ['submission_key' => ['required', 'uuid']])->validate();
            $existing = $record->receipts()->where('submission_key', $submissionKey)->first();
            if ($existing) {
                return $existing;
            }
            $reference = $data['reference'] ?? null;
            $data['reference'] = is_string($reference) ? Str::upper(trim($reference)) : $reference;
            $data['amount'] = is_string($data['amount'] ?? null) ? DecimalPresentation::input($data['amount']) : ($data['amount'] ?? null);
            $validated = Validator::make($data, [
                'reference' => ['bail', 'required', 'string', 'max:100', Rule::unique('sale_receipts')->where('sale_id', $record->id)],
                'kind' => ['required', Rule::in(['receipt', 'refund'])],
                'amount' => ['required', 'string', 'regex:/\A[0-9]{1,12}(\.[0-9]{1,2})?\z/', 'numeric', 'gt:0'],
                'received_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$record->sold_on->toDateString(), 'before_or_equal:'.now('America/Sao_Paulo')->toDateString()],
                'notes' => ['nullable', 'required_if:kind,refund', 'string', 'max:3000'],
            ], ['reference.unique' => 'Esta referência de recebimento ou estorno já foi registrada neste pedido.',
                'notes.required_if' => 'Identifique o motivo do estorno ou da correção.'],
                ['reference' => 'referência do repasse', 'kind' => 'tipo', 'amount' => 'valor', 'received_on' => 'data efetiva', 'notes' => 'observações'])->validate();
            $timeline = $record->receipts()->get()->map(fn (SaleReceipt $receipt): array => ['date' => $receipt->received_on->toDateString(), 'value' => $receipt->kind === 'refund' ? '-'.$receipt->amount : $receipt->amount])->all();
            $timeline[] = ['date' => $validated['received_on'], 'value' => $validated['kind'] === 'refund' ? '-'.$validated['amount'] : $validated['amount']];
            $days = [];
            foreach ($timeline as $entry) {
                $days[$entry['date']] = ($days[$entry['date']] ?? BigDecimal::of('0.00'))->plus($entry['value']);
            }
            ksort($days);
            $balance = BigDecimal::of('0.00');
            foreach ($days as $amount) {
                $balance = $balance->plus($amount);
                if ($balance->isNegative()) {
                    throw ValidationException::withMessages(['amount' => 'O estorno não pode superar o valor recebido até essa data.']);
                }
            }
            $receipt = $record->receipts()->create($validated + ['submission_key' => $submissionKey]);
            $record->version++;
            $record->save();
            $this->revision($record, $validated['kind']);

            return $receipt;
        });
    }

    public function cancel(Sale $sale, string $reason, int $version): Sale
    {
        return DB::transaction(function () use ($sale, $reason, $version): Sale {
            $record = Sale::query()->lockForUpdate()->findOrFail($sale->id);
            $this->assertEditable($record, $version);
            $validated = Validator::make(['cancellation_reason' => trim($reason)], ['cancellation_reason' => ['required', 'string', 'max:3000']])->validate();
            $record->cancelled_at = now();
            $record->cancellation_reason = $validated['cancellation_reason'];
            $record->version++;
            $record->save();
            $this->revision($record, 'cancelled');

            return $record->refresh();
        });
    }

    public function captureCost(Sale $sale, int $version): Sale
    {
        return DB::transaction(function () use ($sale, $version): Sale {
            $record = Sale::query()->lockForUpdate()->findOrFail($sale->id);
            $this->assertEditable($record, $version);
            if ($record->cost_snapshot !== null) {
                throw ValidationException::withMessages(['cost' => 'A referência de custo já foi preservada e não pode ser substituída.']);
            }
            $this->snapshotCost($record, $record->product);
            if ($record->cost_snapshot === null) {
                throw ValidationException::withMessages(['cost' => $record->cost_note]);
            }
            $record->version++;
            $record->save();
            $this->revision($record, 'cost_captured');

            return $record->refresh();
        });
    }

    /** @return array{revenue: ?string, expected: ?string, cost: ?string, contribution: ?string, received: string, refunds: string, credits: string, difference: ?string, pending: ?string, status: string, incomplete: bool} */
    public function figures(Sale $sale): array
    {
        $revenue = $sale->gross_amount !== null && $sale->seller_discount !== null ? BigDecimal::of($sale->gross_amount)->minus($sale->seller_discount) : null;
        $expected = $revenue;
        foreach (['marketplace_fees', 'other_adjustment'] as $field) {
            $expected = $expected !== null && $sale->$field !== null ? $expected->minus($sale->$field) : null;
        }
        $freightKnown = $sale->seller_freight !== null && (BigDecimal::of($sale->seller_freight)->isZero() || $sale->freight_source !== null);
        $expected = $expected !== null && $freightKnown ? $expected->minus($sale->freight_source === 'marketplace' ? $sale->seller_freight : '0.00') : null;
        $cost = $sale->cost_snapshot !== null ? BigDecimal::of($sale->cost_snapshot['exact_cost'])->multipliedBy($sale->quantity)->toScale(2, RoundingMode::HalfUp) : null;
        $contribution = $expected !== null && $cost !== null && $sale->expedition_cost !== null && $sale->paid_labor !== null
            ? $expected->minus($cost)->minus($sale->expedition_cost)->minus($sale->paid_labor)->minus($sale->freight_source === 'external' ? $sale->seller_freight : '0.00') : null;
        $credits = BigDecimal::of('0.00');
        $refunds = BigDecimal::of('0.00');
        foreach ($sale->receipts as $receipt) {
            if ($receipt->kind === 'refund') {
                $refunds = $refunds->plus($receipt->amount);
            } else {
                $credits = $credits->plus($receipt->amount);
            }
        }
        $received = $credits->minus($refunds);
        $difference = $expected !== null ? $received->minus($expected) : null;
        $pending = $expected !== null ? BigDecimal::max('0.00', $expected->minus($received)) : null;
        $status = 'Pendente';
        if ($expected !== null) {
            if (($sale->reconciliation_closed && ! $difference->isZero()) || ($received->isGreaterThan($expected) && ! $sale->receipts->isEmpty())) {
                $status = 'Divergente';
            } elseif ($difference->isZero() && (! $sale->receipts->isEmpty() || $sale->reconciliation_closed)) {
                $status = 'Recebido';
            } elseif ($received->isPositive()) {
                $status = 'Parcial';
            }
        }

        return ['revenue' => $revenue === null ? null : (string) $revenue, 'expected' => $expected === null ? null : (string) $expected,
            'cost' => $cost === null ? null : (string) $cost, 'contribution' => $contribution === null ? null : (string) $contribution,
            'received' => (string) $received, 'credits' => (string) $credits, 'refunds' => (string) $refunds,
            'difference' => $difference === null ? null : (string) $difference, 'pending' => $pending === null ? null : (string) $pending,
            'status' => $status, 'incomplete' => $expected === null];
    }

    /** @return array{count: int, cancelled: int, gross: string, expected: string, received: string, pending: string, cancelled_received: string, unknown_gross: int, unknown_expected: int, divergent_count: int, pending_count: int, partial_count: int, incomplete_financial: int} */
    public function summary(): array
    {
        $summary = ['count' => 0, 'cancelled' => 0, 'gross' => '0.00', 'expected' => '0.00', 'received' => '0.00', 'pending' => '0.00', 'cancelled_received' => '0.00', 'unknown_gross' => 0, 'unknown_expected' => 0,
            'divergent_count' => 0, 'pending_count' => 0, 'partial_count' => 0, 'incomplete_financial' => 0];
        Sale::query()->with('receipts')->chunkById(200, function ($sales) use (&$summary): void {
            foreach ($sales as $sale) {
                $figures = $this->figures($sale);
                if ($sale->cancelled_at !== null) {
                    $summary['cancelled']++;
                    $summary['cancelled_received'] = (string) BigDecimal::of($summary['cancelled_received'])->plus($figures['received']);

                    continue;
                }
                $summary['count']++;
                if ($figures['status'] === 'Divergente') {
                    $summary['divergent_count']++;
                } elseif ($figures['status'] === 'Parcial') {
                    $summary['partial_count']++;
                } elseif ($figures['pending'] !== null && BigDecimal::of($figures['pending'])->isPositive()) {
                    $summary['pending_count']++;
                }
                if ($figures['contribution'] === null || $sale->expected_on === null) {
                    $summary['incomplete_financial']++;
                }
                if ($sale->gross_amount === null) {
                    $summary['unknown_gross']++;
                } else {
                    $summary['gross'] = (string) BigDecimal::of($summary['gross'])->plus($sale->gross_amount);
                }
                $summary['received'] = (string) BigDecimal::of($summary['received'])->plus($figures['received']);
                if ($figures['expected'] === null) {
                    $summary['unknown_expected']++;
                } else {
                    $summary['expected'] = (string) BigDecimal::of($summary['expected'])->plus($figures['expected']);
                    $summary['pending'] = (string) BigDecimal::of($summary['pending'])->plus($figures['pending']);
                }
            }
        });

        return $summary;
    }

    private function assertEditable(Sale $sale, ?int $version): void
    {
        if ($sale->cancelled_at !== null) {
            throw ValidationException::withMessages(['sale' => 'Venda cancelada: os dados e históricos foram preservados. Registre recebimentos ou estornos nos detalhes.']);
        }
        if ($sale->exists && $version !== null && $sale->version !== $version) {
            throw ValidationException::withMessages(['sale' => 'Esta venda foi alterada. Reabra os detalhes antes de salvar.']);
        }
    }

    private function snapshotCost(Sale $sale, Product $product): void
    {
        try {
            $sale->cost_snapshot = $this->costs->breakdown($product);
            $sale->cost_captured_at = now();
            $sale->cost_note = null;
        } catch (ValidationException $exception) {
            $sale->cost_snapshot = null;
            $sale->cost_captured_at = null;
            $sale->cost_note = collect($exception->errors())->flatten()->implode(' ');
        }
    }

    private function revision(Sale $sale, string $event): void
    {
        $snapshot = $sale->fresh()->attributesToArray();
        $snapshot['receipts'] = $sale->receipts()->orderBy('id')->get()->toArray();
        $sale->revisions()->create(['event' => $event, 'snapshot' => $snapshot]);
    }
}
