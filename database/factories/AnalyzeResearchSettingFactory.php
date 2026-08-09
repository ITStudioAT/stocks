<?php

namespace Database\Factories;

use App\Models\AnalyzeResearchSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalyzeResearchSetting>
 */
class AnalyzeResearchSettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'settings' => [
                'rows' => 200,
                'buy_rules' => [
                    ['enabled' => true, 'from' => -4.0, 'to' => -4.0],
                    ['enabled' => true, 'from' => -3.0, 'to' => -3.0],
                    ['enabled' => true, 'from' => -2.0, 'to' => -2.0],
                    ['enabled' => true, 'from' => -1.0, 'to' => -1.0],
                    ['enabled' => true, 'from' => 0.0, 'to' => 0.0],
                ],
                'buy_step' => 0.1,
                'sell' => ['from' => 3.0, 'to' => 3.0, 'step' => 0.1],
                'invest' => ['from' => 7000.0, 'to' => 7000.0, 'step' => 100.0],
                'max_invest' => ['enabled' => false, 'from' => 80000.0, 'to' => 80000.0, 'step' => 1000.0],
            ],
        ];
    }
}
