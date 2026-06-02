<?php

namespace Database\Factories;

use App\Models\Depot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Depot>
 */
class DepotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->words(2, true),
            'account_balance' => $this->faker->randomFloat(2, 0, 250000),
            'is_active' => false,
            'provider' => $this->faker->company(),
            'account_number' => $this->faker->numerify('DEP-######'),
            'description' => $this->faker->optional()->sentence(),
        ];
    }
}
