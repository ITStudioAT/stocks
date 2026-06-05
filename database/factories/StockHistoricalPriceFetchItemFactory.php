<?php

namespace Database\Factories;

use App\Models\StockHistoricalPriceFetchItem;
use App\Models\StockHistoricalPriceFetchRun;
use App\Models\StockHolding;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockHistoricalPriceFetchItem>
 */
class StockHistoricalPriceFetchItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fetch_run_id' => StockHistoricalPriceFetchRun::factory(),
            'stock_holding_id' => StockHolding::factory(),
            'status' => 'queued',
            'date_from' => now()->subYear()->toDateString(),
            'date_to' => now()->toDateString(),
            'stored_count' => 0,
            'error_message' => null,
        ];
    }
}
