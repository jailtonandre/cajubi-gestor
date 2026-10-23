<?php

namespace Database\Factories;

use App\Models\CashProjection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashProjection>
 */
class CashProjectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'opening_balance' => '0.00',
            'starts_on' => '2026-10-22',
        ];
    }
}
