<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductComponent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProductComposition
{
    /**
     * Supply quantities use its registered unit; product quantities count units.
     *
     * @param  list<array{supply_id?: int, component_product_id?: int, quantity: string}>  $components
     */
    public function replace(Product $product, array $components): void
    {
        Validator::make(['components' => $components], [
            'components' => ['required', 'array', 'list', 'min:1'],
            'components.*' => ['array:supply_id,component_product_id,quantity'],
            'components.*.supply_id' => ['nullable', 'integer', 'exists:supplies,id', 'distinct'],
            'components.*.component_product_id' => ['nullable', 'integer', 'exists:products,id', 'distinct'],
            'components.*.quantity' => ['required', 'string', 'regex:/\A[0-9]{1,8}(\.[0-9]{1,6})?\z/', 'gt:0'],
        ])->validate();

        DB::transaction(function () use ($product, $components): void {
            // Serialize graph edits so concurrent updates cannot introduce a cycle.
            Product::query()->orderBy('id')->lockForUpdate()->get(['id']);
            Product::query()->findOrFail($product->id);

            foreach ($components as $index => $component) {
                $supplyId = $component['supply_id'] ?? null;
                $childId = $component['component_product_id'] ?? null;
                if (($supplyId === null) === ($childId === null)) {
                    throw ValidationException::withMessages(["components.$index" => 'Informe exatamente um insumo ou produto.']);
                }
                if ($childId !== null && $this->reaches((int) $childId, $product->id, [])) {
                    throw ValidationException::withMessages(["components.$index" => 'A composição não pode conter ciclos.']);
                }
            }

            $product->components()->delete();
            $product->components()->createMany($components);
            $product->unsetRelation('components');
        });
    }

    /** @param list<int> $visited */
    private function reaches(int $current, int $target, array $visited): bool
    {
        if ($current === $target) {
            return true;
        }
        if (in_array($current, $visited, true)) {
            return false;
        }
        $visited[] = $current;
        foreach (ProductComponent::query()->where('product_id', $current)->whereNotNull('component_product_id')->pluck('component_product_id') as $childId) {
            if ($this->reaches((int) $childId, $target, $visited)) {
                return true;
            }
        }

        return false;
    }
}
