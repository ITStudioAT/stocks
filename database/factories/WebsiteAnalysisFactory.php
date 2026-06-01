<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use App\Models\WebsiteAnalysis;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebsiteAnalysis>
 */
class WebsiteAnalysisFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'client_id' => Client::factory(),
            'user_id' => User::factory(),
            'url' => 'https://example.com',
            'host' => 'example.com',
            'status' => 'queued',
            'analysis_step' => 'pending',
            'pages_count' => 0,
            'assets_count' => 0,
            'reachability_checked_count' => 0,
            'reachability_total_count' => 0,
        ];
    }
}
