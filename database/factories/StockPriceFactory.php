<?php

namespace Database\Factories;

use App\Models\StockPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockPrice>
 */
class StockPriceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $asOf = now();
        $isin = strtoupper($this->faker->unique()->bothify('??##########'));

        return [
            'instrument_key' => "isin:{$isin}",
            'quote_hash' => hash('sha256', $this->faker->unique()->uuid()),
            'source_key' => 'tradegate',
            'source_name' => 'Tradegate Exchange',
            'source_url' => "https://example.com/market-data/{$isin}",
            'source_quality' => 'official_venue',
            'venue' => 'Tradegate',
            'mic' => 'TGAT',
            'isin' => $isin,
            'wkn' => strtoupper($this->faker->unique()->bothify('???###')),
            'symbol' => strtoupper($this->faker->unique()->bothify('???')),
            'currency' => 'EUR',
            'price' => $this->faker->randomFloat(8, 10, 500),
            'price_type' => 'last',
            'as_of' => $asOf,
            'fetched_at' => $asOf,
            'freshness_status' => 'fresh',
            'validation_status' => 'valid',
            'validation_errors' => [],
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Vienna',
        ];
    }
}
