<?php

namespace Database\Factories;

use App\Models\StockRealtimePrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockRealtimePrice>
 */
class StockRealtimePriceFactory extends Factory
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
            'stock_holding_id' => null,
            'legacy_stock_price_id' => null,
            'instrument_key' => "isin:{$isin}",
            'quote_hash' => hash('sha256', $this->faker->unique()->uuid()),
            'source_key' => 'eodhd_realtime',
            'source_name' => 'EODHD real-time',
            'source_url' => "https://eodhd.com/api/real-time/{$isin}.XETRA?fmt=json",
            'source_quality' => 'market_data_vendor',
            'venue' => 'XETRA',
            'mic' => 'XETR',
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
