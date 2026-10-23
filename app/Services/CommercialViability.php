<?php

namespace App\Services;

use App\Models\Product;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CommercialViability
{
    public function __construct(private ProductCostCalculator $costs) {}

    /**
     * @param  array{product_id: mixed, units: mixed, additional_cost: mixed}  $data
     * @param  array<int, string>  $supplyUnitCosts
     * @return array<string, mixed>
     */
    public function lot(array $data, array $supplyUnitCosts = []): array
    {
        $validated = Validator::make($data, [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'units' => ['required', 'string', 'regex:/\A[1-9][0-9]{0,8}\z/'],
            'additional_cost' => $this->amountRules(),
        ], [
            'product_id.required' => 'Selecione um produto.',
            'units.required' => 'Informe a quantidade de unidades acabadas.',
            'units.regex' => 'A quantidade deve ser um inteiro positivo de até 9 dígitos.',
            'additional_cost.required' => 'Informe o adicional do lote, ou zero explícito.',
            'additional_cost.regex' => 'O adicional deve ser não negativo, com até 4 casas decimais.',
        ])->validate();
        $product = Product::query()->findOrFail($validated['product_id']);
        $breakdown = $this->costs->breakdown($product, $supplyUnitCosts);
        $units = BigDecimal::of($validated['units']);
        $material = BigDecimal::of($breakdown['exact_cost'])->multipliedBy($units);
        $total = $material->plus($validated['additional_cost']);
        $supplies = [];
        foreach ($breakdown['supplies'] as $id => $supply) {
            $quantity = BigDecimal::of($supply['quantity'])->multipliedBy($units);
            $supplies[$id] = array_replace($supply, [
                'quantity' => (string) $quantity,
                'total_cost' => (string) $quantity->multipliedBy($supply['unit_cost']),
            ]);
        }

        return ['product_id' => $product->id, 'units' => (string) $units,
            'finished_weight_grams' => (string) BigDecimal::of($product->net_weight_grams)->multipliedBy($units),
            'supplies' => $supplies, 'material_cost' => (string) $material->toScale(4, RoundingMode::HalfUp),
            'additional_cost' => $validated['additional_cost'], 'total_cost' => (string) $total->toScale(4, RoundingMode::HalfUp),
            'unit_cost' => (string) $total->dividedBy($units, 4, RoundingMode::HalfUp)];
    }

    /**
     * Gross resources never deduct hypothetical sales. Working capital overlaps with
     * inventory and lot additions already counted in initial resources.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $lot  A result produced by lot().
     * @return array<string, string>
     */
    public function funding(array $data, array $lot): array
    {
        $validated = Validator::make($data, [
            'expenses_reviewed' => ['required', 'accepted'],
            'expenses' => ['present', 'array', 'list', 'max:100'],
            'expenses.*.name' => ['required', 'string', 'max:150'],
            'expenses.*.category' => ['required', Rule::in(['equipment', 'inventory', 'startup'])],
            'expenses.*.coverage' => ['required', Rule::in(['additional', 'included_in_lot'])],
            'expenses.*.amount' => $this->amountRules(),
            'receipt_days' => ['required', 'string', 'regex:/\A[0-9]{1,4}\z/'],
            'supplier_days' => ['required', 'string', 'regex:/\A[0-9]{1,4}\z/'],
            'monthly_operating' => $this->amountRules(),
            'reserve' => $this->amountRules(),
        ], [
            'expenses_reviewed.accepted' => 'Revise os desembolsos iniciais e confirme a lista, mesmo se não houver outros gastos.',
            'required' => 'Informe :attribute. Use zero explícito quando não se aplicar.',
            'regex' => 'Informe :attribute com um valor não negativo válido.',
            'expenses.*.name.required' => 'Identifique cada desembolso inicial.',
            'expenses.*.category.required' => 'Selecione a categoria de cada desembolso.',
            'expenses.*.coverage.required' => 'Informe se cada desembolso é adicional ou já está incluído no lote.',
            'expenses.*.amount.required' => 'Informe o valor de cada desembolso, ou zero explícito.',
        ], ['receipt_days' => 'prazo de recebimento em dias', 'supplier_days' => 'prazo de pagamento em dias',
            'monthly_operating' => 'despesas operacionais por mês', 'reserve' => 'reserva financeira',
            'expenses.*.amount' => 'valor do desembolso', 'expenses.*.category' => 'categoria do desembolso',
            'expenses.*.coverage' => 'cobertura do desembolso'])->validate();

        $groups = ['equipment' => BigDecimal::of('0'), 'inventory' => BigDecimal::of('0'), 'startup' => BigDecimal::of('0')];
        $excluded = BigDecimal::of('0');
        foreach ($validated['expenses'] as $index => $expense) {
            if ($expense['coverage'] === 'included_in_lot') {
                if ($expense['category'] === 'equipment') {
                    throw ValidationException::withMessages(["expenses.$index.coverage" => 'Equipamentos não fazem parte do custo do lote. Registre como desembolso adicional.']);
                }
                $excluded = $excluded->plus($expense['amount']);
            } else {
                $groups[$expense['category']] = $groups[$expense['category']]->plus($expense['amount']);
            }
        }

        $gap = max(0, (int) $validated['receipt_days'] - (int) $validated['supplier_days']);
        $inventoryExposure = $groups['inventory'];
        if ((int) $validated['supplier_days'] <= (int) $validated['receipt_days']) {
            $inventoryExposure = $inventoryExposure->plus($lot['material_cost']);
        }
        $operating = BigDecimal::of($validated['monthly_operating'])->multipliedBy($validated['receipt_days'])->dividedBy('30', 2, RoundingMode::Ceiling);
        $extraWorking = $operating->plus($validated['reserve']);
        $overlap = $inventoryExposure->plus($lot['additional_cost']);
        $initial = BigDecimal::of($lot['total_cost'])->plus($groups['equipment'])->plus($groups['inventory'])->plus($groups['startup']);

        return ['equipment' => (string) $groups['equipment'], 'extra_inventory' => (string) $groups['inventory'],
            'startup_expenses' => (string) $groups['startup'], 'excluded_duplicates' => (string) $excluded,
            'initial_resources' => (string) $initial, 'gap_days' => (string) $gap,
            'inventory_exposure' => (string) $inventoryExposure, 'operating_bridge' => (string) $operating,
            'reserve' => $validated['reserve'], 'already_in_initial' => (string) $overlap,
            'working_capital' => (string) $overlap->plus($extraWorking), 'additional_working' => (string) $extraWorking,
            'total_resources' => (string) $initial->plus($extraWorking)];
    }

    /** @return list<string> */
    private function amountRules(): array
    {
        return ['required', 'string', 'regex:/\A[0-9]{1,10}(\.[0-9]{1,4})?\z/'];
    }
}
