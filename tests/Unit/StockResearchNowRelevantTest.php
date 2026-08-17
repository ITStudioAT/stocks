<?php

namespace Tests\Unit;

use App\Models\StockAiResearch;
use App\Services\StockResearchNowRelevant;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StockResearchNowRelevantTest extends TestCase
{
    #[Test]
    public function it_builds_all_current_dashboard_blocks_from_persisted_facts(): void
    {
        $research = new StockAiResearch;
        $research->finished_at = Carbon::parse('2026-08-17T12:00:00+02:00');
        $research->developments = [[
            'category' => 'rumor',
            'subject' => 'Alpha',
            'headline' => 'Übernahmebericht wurde widerlegt',
            'details' => 'Der Emittent hat den Bericht zurückgewiesen.',
            'event_at' => '2026-08-17T09:00:00+02:00',
            'published_at' => '2026-08-17T09:10:00+02:00',
            'retrieved_at' => '2026-08-17T10:00:00+02:00',
            'data_as_of' => '2026-08-17',
            'freshness' => 'current',
            'coverage' => 'complete',
            'status' => 'debunked',
            'source_url' => 'https://example.com/denial',
            'source_title' => 'Issuer denial',
            'current_impact' => 'mixed',
            'materiality' => 'medium',
            'source_confidence' => 'high',
            'time_horizon' => 'today_72h',
            'assessment_status' => 'reliable',
        ]];
        $research->analyst_consensus = [[
            'as_of' => '2026-08-17',
            'analyst_count' => 10,
            'buy_pct' => 60,
            'hold_pct' => 30,
            'sell_pct' => 10,
            'source_title' => 'Consensus Provider',
            'source_url' => 'https://example.com/consensus',
        ]];
        $research->calculated_events = [
            'calculated_at' => '2026-08-17T12:00:00+02:00',
            'etf_positions' => [
                'comparison_status' => 'no_previous_snapshot',
                'coverage' => 'partial',
                'positions' => [[
                    'subject' => ['symbol' => 'ALPHA.US', 'name' => 'Alpha'],
                    'membership' => 'baseline_only',
                    'current_rank' => 1,
                    'current_weight_pct' => 8.25,
                    'event_at' => '2026-08-17',
                    'coverage' => 'partial',
                    'source_title' => 'Official factsheet',
                    'source_url' => 'https://example.com/factsheet',
                ]],
            ],
            'earnings' => [],
            'upcoming_events' => [[
                'type' => 'scheduled_earnings',
                'subject' => ['symbol' => 'ALPHA.US', 'name' => 'Alpha'],
                'event_at' => '2026-08-22',
                'coverage' => 'complete',
            ]],
            'guidance' => [],
            'guidance_coverage' => ['status' => 'unavailable', 'reason' => 'Keine Guidance.'],
            'market_reactions' => [],
            'unusual_volume' => [],
            'data_coverage' => [
                'status' => 'partial',
                'coverage_complete' => false,
                'datasets' => [['dataset' => 'earnings_calendar', 'status' => 'fresh']],
            ],
        ];

        $result = (new StockResearchNowRelevant)->build($research);
        $blocks = collect($result['blocks'])->keyBy('key');

        $this->assertSame([
            'current_top_positions',
            'position_news',
            'unusual_activity',
            'upcoming_events',
            'latest_earnings',
            'current_guidance',
            'rumors',
            'politics',
            'analyst_consensus',
            'other_developments',
            'position_changes',
            'data_coverage',
        ], array_column($result['blocks'], 'key'));
        $this->assertSame('Alpha: Ergebnistermin', $blocks['upcoming_events']['items'][0]['title']);
        $this->assertSame('debunked', $blocks['rumors']['items'][0]['status']);
        $this->assertSame('Kein vergleichbarer datierter offizieller Positions-Snapshot verfügbar.', $blocks['position_changes']['empty_message']);
        $this->assertStringContainsString('BUY 60,0 %', $blocks['analyst_consensus']['items'][0]['detail']);
        $this->assertSame('Official factsheet', $blocks['current_top_positions']['items'][0]['source_title']);
        $this->assertFalse($blocks['data_coverage']['coverage'] === 'complete');
    }
}
