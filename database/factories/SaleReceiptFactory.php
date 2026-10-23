<?php

namespace Database\Factories;

use App\Models\Sale;
use App\Models\SaleReceipt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SaleReceipt>
 */
class SaleReceiptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sale_id' => Sale::factory(),
            'submission_key' => fake()->uuid(),
            'reference' => fake()->unique()->numerify('REP-########'),
            'kind' => 'receipt',
            'amount' => '10.00',
            'received_on' => '2026-10-22',
        ];
    }
}
