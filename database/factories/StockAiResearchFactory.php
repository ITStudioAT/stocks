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
            'developments' => [
                [
                    'category' => 'other',
                    'subject' => $this->faker->company(),
                    'subject_symbol' => null,
                    'event_at' => now()->subHour()->toIso8601String(),
                    'published_at' => now()->subMinutes(45)->toIso8601String(),
                    'retrieved_at' => now()->toIso8601String(),
                    'data_as_of' => now()->toDateString(),
                    'freshness' => 'current',
                    'coverage' => 'partial',
                    'headline' => $this->faker->sentence(),
                    'details' => $this->faker->sentence(),
                    'relevance' => $this->faker->sentence(),
                    'status' => 'confirmed',
                    'source_type' => 'issuer',
                    'impact' => 'mixed',
                    'current_impact' => 'no_reliable_assessment',
                    'materiality' => null,
                    'source_confidence' => 'high',
                    'affected_etf_share_pct' => null,
                    'time_horizon' => 'current_quarter',
                    'impact_rationale' => $this->faker->sentence(),
                    'assessment_status' => 'no_reliable_assessment',
                    'assessment_reason' => 'Keine belastbare Einschätzung bei unvollständiger Abdeckung.',
                    'assessment_method' => 'structured_event_classification_with_server_coverage_guardrails',
                    'source_title' => 'Company investor relations',
                    'source_url' => 'https://example.com/company-update',
                ],
            ],
            'calculation_snapshot' => null,
            'calculated_events' => null,
            'assessment' => [
                'status' => 'no_reliable_assessment',
                'current_impact' => 'no_reliable_assessment',
                'materiality' => null,
                'source_confidence' => 'high',
                'affected_etf_share_pct' => null,
                'time_horizons' => ['current_quarter'],
                'reason' => 'Keine belastbare Gesamteinschätzung.',
                'method' => 'aggregate_reliable_current_events_only',
            ],
            'stronger_case' => null,
            'weaker_case' => null,
            'trump_connection' => 'Kein materieller Zusammenhang gefunden.',
            'recommendation' => null,
            'justification' => null,
            'known_information' => [$this->faker->sentence()],
            'message' => 'KI-Analyse abgeschlossen.',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ];
    }
}
