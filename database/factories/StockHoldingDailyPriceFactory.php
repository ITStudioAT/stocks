<?php

namespace Database\Factories;

use App\Models\StockHolding;
use App\Models\StockHoldingDailyPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockHoldingDailyPrice>
 */
class StockHoldingDailyPriceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $close = $this->faker->randomFloat(4, 10, 500);

        return [
            'stock_holding_id' => StockHolding::factory(),
            'trading_date' => $this->faker->dateTimeBetween('-1 year')->format('Y-m-d'),
            'open' => $close - $this->faker->randomFloat(4, -2, 2),
            'high' => $close + $this->faker->randomFloat(4, 0, 4),
            'low' => max(0.01, $close - $this->faker->randomFloat(4, 0, 4)),
            'close' => $close,
            'adjusted_close' => $close,
            'volume' => $this->faker->numberBetween(1000, 1000000),
            'currency' => 'USD',
            'source_key' => 'eodhd_eod',
            'source_name' => 'EODHD EOD',
            'source_url' => 'https://eodhd.com/api/eod/AAPL.US',
            'raw_payload' => [],
        ];
    }
}
