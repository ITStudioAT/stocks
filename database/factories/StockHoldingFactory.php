<?php

namespace Database\Factories;

use App\Models\Depot;
use App\Models\StockHolding;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockHolding>
 */
class StockHoldingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'depot_id' => Depot::factory(),
            'symbol' => strtoupper($this->faker->unique()->bothify('???')),
            'name' => $this->faker->company(),
            'isin' => strtoupper($this->faker->unique()->bothify('??##########')),
            'wkn' => strtoupper($this->faker->unique()->bothify('???###')),
            'exchange' => 'NASDAQ',
            'mic_code' => 'XNAS',
            'instrument_type' => 'Common Stock',
            'country' => 'United States',
            'currency' => 'USD',
            'latest_price' => $this->faker->randomFloat(6, 10, 500),
            'latest_price_fetched_at' => now(),
            'latest_price_source' => 'AI SDK web search',
            'latest_price_source_url' => 'https://example.com/market-data',
            'latest_price_as_of' => now()->toIso8601String(),
        ];
    }
}
