<?php

namespace Database\Factories;

use App\Models\Depot;
use App\Models\DepotTransaction;
use App\Models\StockHolding;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DepotTransaction>
 */
class DepotTransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $totalAmount = $this->faker->randomFloat(2, 100, 10000);
        $pieces = $this->faker->randomFloat(8, 1, 100);

        return [
            'depot_id' => Depot::factory(),
            'stock_holding_id' => StockHolding::factory(),
            'type' => 'buy',
            'pieces' => $pieces,
            'total_amount' => $totalAmount,
            'currency' => 'EUR',
            'unit_price' => $totalAmount / $pieces,
            'cash_delta' => -$totalAmount,
            'balance_after' => $this->faker->randomFloat(2, 0, 250000),
            'booked_at' => now(),
            'note' => $this->faker->optional()->sentence(),
            'is_external_cashflow' => false,
            'affects_performance' => false,
        ];
    }
}
