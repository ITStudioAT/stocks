<?php

namespace Database\Factories;

use App\Models\IndexWatchItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IndexWatchItem>
 */
class IndexWatchItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'symbol' => strtoupper($this->faker->unique()->bothify('????')),
            'name' => $this->faker->company().' Index',
            'isin' => strtoupper($this->faker->unique()->bothify('??##########')),
            'wkn' => strtoupper($this->faker->unique()->bothify('???###')),
            'exchange' => 'XETRA',
            'mic_code' => 'XETR',
            'instrument_type' => 'INDEX',
            'country' => 'Germany',
            'currency' => 'EUR',
            'raw_payload' => [],
        ];
    }
}
