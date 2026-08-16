<?php

namespace Database\Factories;

use App\Models\StockAiResearch;
use App\Models\StockAiResearchSource;
use App\Models\StockHolding;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockAiResearchSource>
 */
class StockAiResearchSourceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $url = $this->faker->unique()->url();

        return [
            'stock_ai_research_id' => StockAiResearch::factory(),
            'user_id' => User::factory(),
            'stock_holding_id' => StockHolding::factory(),
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'title' => $this->faker->sentence(),
        ];
    }
}
