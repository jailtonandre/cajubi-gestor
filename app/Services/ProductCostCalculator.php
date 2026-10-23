<?php

namespace App\Services;

use App\Models\Product;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProductCostCalculator
{
    /**
     * Round only the final total, preserving precision through nested kits.
     *
     * @param  array<int, string>  $supplyUnitCosts  Temporary input costs, never persisted.
     */
    public function calculate(Product $product, array $supplyUnitCosts = []): string
    {
        return $this->breakdown($product, $supplyUnitCosts)['unit_cost'];
    }

    /**
     * @param  array<int, string>  $supplyUnitCosts
     * @return array{unit_cost: string, exact_cost: string, supplies: array<int, array{name: string, unit: string, quantity: string, unit_cost: string}>}
     */
    public function breakdown(Product $product, array $supplyUnitCosts = []): array
    {
        Validator::make(['cost_overrides' => $supplyUnitCosts], [
            'cost_overrides' => ['array'],
            'cost_overrides.*' => ['required', 'string', 'regex:/\A[0-9]{1,10}(\.[0-9]{1,4})?\z/'],
        ])->validate();
        foreach (array_keys($supplyUnitCosts) as $id) {
            if (! preg_match('/\A[1-9][0-9]*\z/', (string) $id)) {
                throw ValidationException::withMessages(['cost_overrides' => 'Selecione um insumo válido da composição.']);
            }
        }
        $used = [];
        $consumption = [];
        $total = $this->sum($product, [], $supplyUnitCosts, $used, $consumption, BigDecimal::of('1'));
        foreach (array_keys($supplyUnitCosts) as $id) {
            if (! isset($used[$id])) {
                throw ValidationException::withMessages(['cost_overrides' => 'Um dos insumos alternativos não faz parte da composição deste produto.']);
            }
        }

        return ['unit_cost' => (string) $total->toScale(4, RoundingMode::HalfUp), 'exact_cost' => (string) $total, 'supplies' => $consumption];
    }

    /**
     * Return the distinct inputs in the entire product composition, including kits.
     *
     * @param  list<int>  $path
     */
    public function supplies(Product $product, array $path = []): Collection
    {
        if (in_array($product->id, $path, true)) {
            throw ValidationException::withMessages(['composition' => 'A composição contém um ciclo.']);
        }
        $path[] = $product->id;
        $supplies = new Collection;
        foreach ($product->components()->with(['supply', 'componentProduct'])->get() as $component) {
            if ($component->supply) {
                $supplies->push($component->supply);
            } elseif ($component->componentProduct) {
                $supplies = $supplies->merge($this->supplies($component->componentProduct, $path));
            }
        }

        return $supplies->unique('id')->values()->load('currentCost');
    }

    /**
     * @param  list<int>  $path
     * @param  array<int, string>  $supplyUnitCosts
     * @param  array<int, bool>  $used
     * @param  array<int, array{name: string, unit: string, quantity: string, unit_cost: string}>  $consumption
     */
    private function sum(Product $product, array $path, array $supplyUnitCosts, array &$used, array &$consumption, BigDecimal $factor): BigDecimal
    {
        if (in_array($product->id, $path, true)) {
            throw ValidationException::withMessages(['composition' => 'A composição contém um ciclo.']);
        }
        $path[] = $product->id;
        $components = $product->components()->with(['supply.currentCost', 'componentProduct'])->get();
        if ($components->isEmpty()) {
            throw ValidationException::withMessages(['composition' => 'Produto sem composição de custos.']);
        }

        $total = BigDecimal::of('0');
        foreach ($components as $component) {
            if (($component->supply_id === null) === ($component->component_product_id === null)) {
                throw ValidationException::withMessages(['composition' => 'Componente inválido.']);
            }
            if ($component->supply_id !== null) {
                if (array_key_exists($component->supply_id, $supplyUnitCosts)) {
                    $cost = $supplyUnitCosts[$component->supply_id];
                    $used[$component->supply_id] = true;
                } else {
                    $cost = $component->supply?->currentCost?->unit_cost;
                }
                if ($cost === null) {
                    throw ValidationException::withMessages(['composition' => 'Insumo sem custo cadastrado.']);
                }
                $unitCost = BigDecimal::of($cost);
                $quantity = BigDecimal::of($component->quantity)->multipliedBy($factor);
                $previous = $consumption[$component->supply_id]['quantity'] ?? '0';
                $consumption[$component->supply_id] = [
                    'name' => $component->supply->name, 'unit' => $component->supply->unit,
                    'quantity' => (string) $quantity->plus($previous), 'unit_cost' => $cost,
                ];
            } else {
                $unitCost = $this->sum($component->componentProduct, $path, $supplyUnitCosts, $used, $consumption, $factor->multipliedBy($component->quantity));
            }
            $total = $total->plus($unitCost->multipliedBy($component->quantity));
        }

        return $total;
    }
}
