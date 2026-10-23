<?php

namespace Database\Factories;

use App\Models\CashMovement;
use App\Models\CashProjection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashMovement>
 */
class CashMovementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cash_projection_id' => CashProjection::factory(),
            'submission_key' => fake()->uuid(),
            'description' => fake()->sentence(3),
            'direction' => 'outflow',
            'amount' => '10.00',
            'expected_on' => '2026-10-22',
        ];
    }
}
