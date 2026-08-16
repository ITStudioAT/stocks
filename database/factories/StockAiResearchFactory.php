<?php

namespace Database\Factories;

use App\Models\StockAiResearch;
use App\Models\StockHolding;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockAiResearch>
 */
class StockAiResearchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => 'stock-ai-research-'.$this->faker->uuid(),
            'user_id' => User::factory(),
            'stock_holding_id' => StockHolding::factory(),
            'status' => 'finished',
            'has_material_update' => true,
            'summary' => $this->faker->sentence(),
            'stronger_case' => $this->faker->sentence(),
            'weaker_case' => $this->faker->sentence(),
            'trump_connection' => 'Kein materieller Zusammenhang gefunden.',
            'recommendation' => 'hold',
            'justification' => $this->faker->sentence(),
            'known_information' => [$this->faker->sentence()],
            'message' => 'KI-Analyse abgeschlossen.',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ];
    }
}
