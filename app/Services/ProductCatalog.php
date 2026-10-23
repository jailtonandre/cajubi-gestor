<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ProductCatalog
{
    /** @param array<string, mixed> $data */
    public function save(array $data, ?Product $product = null): Product
    {
        $data['name'] = is_string($data['name'] ?? null) ? trim($data['name']) : ($data['name'] ?? null);
        $data['sku'] = is_string($data['sku'] ?? null) ? mb_strtoupper(trim($data['sku'])) : ($data['sku'] ?? null);
        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['required', 'string', 'max:100', Rule::unique('products')->ignore($product)],
            'net_weight_grams' => ['required', 'string', 'regex:/\A[0-9]{1,9}(\.[0-9]{1,3})?\z/', 'gt:0'],
            'description' => ['nullable', 'string'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ])->validate();

        $product ??= new Product;
        $product->fill($validated)->save();

        return $product->refresh();
    }
}
