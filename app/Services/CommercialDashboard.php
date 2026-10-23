<?php

namespace App\Services;

use App\Models\CashProjection;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Supply;
use App\Models\TariffProfile;
use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;

class CommercialDashboard
{
    public function __construct(private SalesLedger $sales, private CashFlowProjection $cash, private ProductCostCalculator $costs, private TariffCatalog $tariffs) {}

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $sales = $this->sales->summary();
        $projection = CashProjection::query()->withCount('movements')->find(1);
        $forecast = $projection ? $this->cash->calculate($projection, $projection->movements()
            ->whereDate('expected_on', '>=', $projection->starts_on->toDateString())
            ->whereDate('expected_on', '<=', $projection->starts_on->addDays(55)->toDateString())->cursor()) : null;
        $products = $this->productReferences();
        $tariffs = TariffProfile::query()->where('is_active', true)->orderBy('channel')->orderBy('category')->orderBy('id')->get()
            ->map(fn (TariffProfile $profile): array => ['profile' => $profile, 'warnings' => $this->tariffs->warnings($profile)])->all();
        $alerts = [];
        foreach (['divergent_count' => 'venda(s) com repasse divergente', 'pending_count' => 'venda(s) com repasse pendente',
            'partial_count' => 'venda(s) com recebimento parcial', 'incomplete_financial' => 'venda(s) com informações financeiras incompletas'] as $key => $label) {
            if ($sales[$key] > 0) {
                $alerts[] = ['label' => $sales[$key].' '.$label, 'url' => route('sales'), 'urgent' => $key === 'divergent_count'];
            }
        }
        if ($forecast && $forecast['first_shortfall'] !== null) {
            $alerts[] = ['label' => 'Falta de caixa prevista no período', 'url' => route('cash-flow'), 'urgent' => true];
        }
        $expiredProjection = $forecast !== null && $forecast['ends_on'] < now('America/Sao_Paulo')->toDateString();
        if ($expiredProjection) {
            $alerts[] = ['label' => 'Período da projeção encerrado; revise as datas', 'url' => route('cash-flow'), 'urgent' => false];
        }
        foreach ($products as $reference) {
            if ($reference['warning'] !== null) {
                $alerts[] = ['label' => $reference['product']->name.': custo indisponível', 'url' => route('products.edit', $reference['product']), 'urgent' => false];
            }
        }

        $hasCashMovements = $forecast !== null && collect($forecast['weeks'])->contains(fn (array $week): bool => ! BigDecimal::of($week['inflows'])->isZero() || ! BigDecimal::of($week['outflows'])->isZero());

        return ['sales' => $sales, 'projection' => $projection, 'forecast' => $forecast, 'has_cash_movements' => $hasCashMovements, 'projection_expired' => $expiredProjection, 'alerts' => $alerts,
            'products' => $products, 'product_count' => Product::query()->where('status', 'active')->count(),
            'tariffs' => $tariffs, 'tariff_count' => count($tariffs),
            'estimated_tariffs' => collect($tariffs)->filter(fn (array $reference): bool => $reference['profile']->confidence === 'estimated')->count(),
            'product_sales' => $this->productSales()];
    }

    /** @return list<array{product: Product, quantity: int, gross: string, expected: string, received: string, pending: string, unknown_gross: int, unknown_expected: int}> */
    private function productSales(): array
    {
        $products = Product::query()->whereIn('id', Sale::query()->whereNull('cancelled_at')->select('product_id'))
            ->orderBy('name')->orderBy('id')->limit(6)->get();
        $rows = [];
        foreach ($products as $product) {
            $rows[$product->id] = ['product' => $product, 'quantity' => 0, 'gross' => '0.00', 'expected' => '0.00', 'received' => '0.00', 'pending' => '0.00', 'unknown_gross' => 0, 'unknown_expected' => 0];
        }
        if ($rows === []) {
            return [];
        }
        Sale::query()->whereNull('cancelled_at')->whereIn('product_id', array_keys($rows))->with('receipts')->chunkById(200, function ($sales) use (&$rows): void {
            foreach ($sales as $sale) {
                $figures = $this->sales->figures($sale);
                $row = &$rows[$sale->product_id];
                $row['quantity'] += $sale->quantity;
                foreach (['gross' => $sale->gross_amount, 'expected' => $figures['expected'], 'received' => $figures['received'], 'pending' => $figures['pending']] as $key => $value) {
                    if ($value !== null) {
                        $row[$key] = (string) BigDecimal::of($row[$key])->plus($value);
                    }
                }
                $row['unknown_gross'] += $sale->gross_amount === null ? 1 : 0;
                $row['unknown_expected'] += $figures['expected'] === null ? 1 : 0;
                unset($row);
            }
        });

        return array_values($rows);
    }

    /** @return list<array{product: Product, cost: ?string, warning: ?string, supply_ids: list<int>, earliest_reference: ?string, latest_reference: ?string}> */
    private function productReferences(): array
    {
        $references = [];
        $supplyIds = [];
        foreach (Product::query()->where('status', 'active')->orderBy('name')->orderBy('id')->limit(6)->get() as $product) {
            $reference = ['product' => $product, 'cost' => null, 'warning' => null, 'supply_ids' => [], 'earliest_reference' => null, 'latest_reference' => null];
            try {
                $breakdown = $this->costs->breakdown($product);
                $reference['cost'] = $breakdown['unit_cost'];
                $reference['supply_ids'] = array_keys($breakdown['supplies']);
                $supplyIds = array_merge($supplyIds, $reference['supply_ids']);
            } catch (ValidationException $exception) {
                $reference['warning'] = collect($exception->errors())->flatten()->first();
            }
            $references[] = $reference;
        }
        $supplies = $supplyIds === [] ? collect() : Supply::query()->with('currentCost')->whereIn('id', array_unique($supplyIds))->get()->keyBy('id');
        foreach ($references as &$reference) {
            $dates = [];
            foreach ($reference['supply_ids'] as $id) {
                $date = $supplies->get($id)?->currentCost?->created_at;
                if ($date) {
                    $dates[] = $date->timezone('America/Sao_Paulo')->format('Y-m-d');
                }
            }
            if ($dates !== []) {
                sort($dates);
                $reference['earliest_reference'] = $dates[0];
                $reference['latest_reference'] = $dates[count($dates) - 1];
            }
        }
        unset($reference);

        return $references;
    }
}
