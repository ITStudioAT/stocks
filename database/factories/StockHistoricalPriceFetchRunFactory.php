<?php

namespace Database\Factories;

use App\Models\StockHistoricalPriceFetchRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockHistoricalPriceFetchRun>
 */
class StockHistoricalPriceFetchRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => 'history-'.$this->faker->unique()->uuid(),
            'status' => 'queued',
            'date_from' => now()->subYear()->toDateString(),
            'date_to' => now()->toDateString(),
            'total_count' => 1,
            'processed_count' => 0,
            'success_count' => 0,
            'unavailable_count' => 0,
            'failed_count' => 0,
            'current' => null,
            'started_at' => null,
            'finished_at' => null,
            'error_summary' => null,
        ];
    }
}
