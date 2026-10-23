<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sale>
 */
class SaleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'marketplace' => 'mercado_livre',
            'order_reference' => fake()->unique()->numerify('PED-########'),
            'sold_on' => '2026-10-22',
            'product_id' => Product::factory(),
            'product_snapshot' => ['name' => 'Produto registrado', 'sku' => 'PRODUTO'],
            'quantity' => 1,
        ];
    }
}
