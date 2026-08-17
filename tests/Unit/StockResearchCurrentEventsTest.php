<?php

namespace Tests\Unit;

use App\Services\StockResearchCurrentEvents;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class StockResearchCurrentEventsTest extends TestCase
{
    #[Test]
    public function it_calculates_present_events_from_provider_values_and_a_comparison_snapshot(): void
    {
        $calculator = new StockResearchCurrentEvents;
        $previousSnapshot = [
            'captured_at' => '2026-08-16T10:00:00+02:00',
            'etf' => [
                'classification' => 'provider_reported_etf_holdings',
                'scope' => 'top_3',
                'data_as_of' => null,
                'retrieved_at' => '2026-08-16T10:00:00+02:00',
                'positions' => [
                    ['rank' => 1, 'symbol' => 'A.US', 'name' => 'Alpha', 'weight_pct' => 10],
                    ['rank' => 2, 'symbol' => 'B.US', 'name' => 'Beta', 'weight_pct' => 5],
                    ['rank' => 3, 'symbol' => 'C.US', 'name' => 'Gamma', 'weight_pct' => 3],
                ],
            ],
            'guidance' => [
                ['symbol' => 'A.US', 'metric' => 'revenue', 'period' => 'FY2026', 'unit' => 'USDm', 'value' => 100, 'low' => null, 'high' => null],
                ['symbol' => 'B.US', 'metric' => 'revenue', 'period' => 'FY2026', 'unit' => 'USDm', 'value' => 80, 'low' => null, 'high' => null],
                ['symbol' => 'D.US', 'metric' => 'revenue', 'period' => 'FY2026', 'unit' => 'USDm', 'value' => 60, 'low' => null, 'high' => null],
            ],
        ];
        $evidence = [
            'as_of' => '2026-08-17T12:00:00+02:00',
            'provider' => 'EODHD',
            'status' => 'complete',
            'coverage_complete' => true,
            'coverage' => [[
                'dataset' => 'earnings_calendar',
                'symbol' => 'A.US,B.US',
                'status' => 'fresh',
                'retrieved_at' => '2026-08-17T11:59:00+02:00',
            ]],
            'etf_snapshot' => [
                'classification' => 'provider_reported_etf_holdings',
                'source_as_of' => null,
                'retrieved_at' => '2026-08-17T11:59:00+02:00',
                'positions' => [
                    ['rank' => 1, 'symbol' => 'A.US', 'name' => 'Alpha', 'weight_pct' => 8],
                    ['rank' => 2, 'symbol' => 'B.US', 'name' => 'Beta', 'weight_pct' => 6],
                    ['rank' => 3, 'symbol' => 'D.US', 'name' => 'Delta', 'weight_pct' => 4],
                ],
            ],
            'earnings_calendar' => [
                [
                    'symbol' => 'A.US',
                    'report_date' => '2026-08-17',
                    'fiscal_period_end' => '2026-06-30',
                    'status' => 'reported',
                    'currency' => 'USD',
                    'eps_actual' => 1.2,
                    'eps_estimate' => 1,
                    'revenue_actual' => 1200,
                    'revenue_estimate' => 1000,
                ],
                [
                    'symbol' => 'B.US',
                    'report_date' => '2026-08-22',
                    'fiscal_period_end' => '2026-06-30',
                    'status' => 'scheduled',
                    'timing' => 'after_market',
                    'currency' => 'USD',
                    'eps_actual' => null,
                    'eps_estimate' => 0.8,
                ],
            ],
            'company_fundamentals' => [],
            'structured_guidance' => [
                ['symbol' => 'A.US', 'metric' => 'revenue', 'period' => 'FY2026', 'unit' => 'USDm', 'current' => ['value' => 110], 'event_at' => '2026-08-17'],
                ['symbol' => 'B.US', 'metric' => 'revenue', 'period' => 'FY2026', 'unit' => 'USDm', 'current' => ['value' => 80], 'event_at' => '2026-08-17'],
                ['symbol' => 'D.US', 'metric' => 'revenue', 'period' => 'FY2026', 'unit' => 'USDm', 'current' => ['value' => 55], 'event_at' => '2026-08-17'],
            ],
            'current_market' => [[
                'symbol' => 'A.US',
                'provider_as_of' => '2026-08-17T11:55:00+02:00',
                'received_at' => '2026-08-17T11:59:00+02:00',
                'close' => 110,
                'previous_close' => 100,
                'change_pct' => 999,
                'session_volume' => 250,
            ]],
            'recent_market_context' => [
                'A.US' => [
                    ['date' => '2026-08-16', 'volume' => 100],
                    ['date' => '2026-08-15', 'volume' => 110],
                    ['date' => '2026-08-14', 'volume' => 90],
                    ['date' => '2026-08-13', 'volume' => 105],
                    ['date' => '2026-08-12', 'volume' => 95],
                ],
            ],
        ];

        $currentSnapshot = $calculator->snapshot($evidence);
        $events = $calculator->calculate($evidence, $currentSnapshot, $previousSnapshot, 'previous-research');

        $positions = collect($events['etf_positions']['positions'])->keyBy('subject.symbol');
        $this->assertSame('compared', $events['etf_positions']['comparison_status']);
        $this->assertSame(-2.0, $positions['A.US']['weight_change_pp']);
        $this->assertSame('decreased', $positions['A.US']['weight_direction']);
        $this->assertSame('entered_scope', $positions['D.US']['membership']);
        $this->assertSame('left_scope', $positions['C.US']['membership']);
        $this->assertSame(20.0, $events['earnings'][0]['eps']['surprise_pct']);
        $this->assertSame('beat', $events['earnings'][0]['eps']['outcome']);
        $this->assertSame(20.0, $events['earnings'][0]['revenue']['surprise_pct']);
        $this->assertSame('B.US', $events['upcoming_events'][0]['subject']['symbol']);
        $this->assertSame('2026-08-22', $events['upcoming_events'][0]['event_at']);
        $this->assertSame(['raised', 'confirmed', 'lowered'], array_column($events['guidance'], 'classification'));
        $this->assertSame(10.0, $events['market_reactions'][0]['reaction_pct']);
        $this->assertSame(2.5, $events['market_reactions'][0]['relative_volume']);
        $this->assertTrue($events['market_reactions'][0]['is_unusual_volume']);
        $this->assertSame(0.8, $events['etf_relevance'][0]['estimated_contribution_pct_points']);
        $this->assertTrue($events['etf_relevance'][0]['approximate']);
        $this->assertSame('comparison_only', $events['history_usage']);
        $this->assertTrue($events['data_coverage']['coverage_complete']);
        $this->assertSame('fresh', $events['data_coverage']['datasets'][0]['status']);
    }

    #[Test]
    public function it_keeps_missing_or_incomplete_comparisons_explicit(): void
    {
        $calculator = new StockResearchCurrentEvents;
        $evidence = [
            'as_of' => '2026-08-17T12:00:00+02:00',
            'etf_snapshot' => ['positions' => []],
            'current_market' => [[
                'symbol' => 'A.US',
                'provider_as_of' => '2026-08-16T20:00:00+02:00',
                'close' => 10,
                'previous_close' => 0,
                'session_volume' => 10,
            ]],
            'recent_market_context' => ['A.US' => [
                ['date' => '2026-08-15', 'volume' => 5],
                ['date' => '2026-08-14', 'volume' => 6],
            ]],
        ];

        $snapshot = $calculator->snapshot($evidence);
        $events = $calculator->calculate($evidence, $snapshot);

        $this->assertSame('current_snapshot_unavailable', $events['etf_positions']['comparison_status']);
        $this->assertSame([], $events['market_reactions']);
        $this->assertSame([], $events['unusual_volume']);
        $this->assertSame('unavailable', $events['guidance_coverage']['status']);
    }

    #[Test]
    public function it_exposes_current_etf_weights_without_inventing_a_change_on_the_first_snapshot(): void
    {
        $calculator = new StockResearchCurrentEvents;
        $evidence = [
            'as_of' => '2026-08-17T12:00:00+02:00',
            'etf_snapshot' => [
                'classification' => 'provider_reported_etf_holdings',
                'source_as_of' => null,
                'retrieved_at' => '2026-08-17T11:59:00+02:00',
                'positions' => [[
                    'rank' => 1,
                    'symbol' => 'A.US',
                    'name' => 'Alpha',
                    'weight_pct' => 8.25,
                ]],
            ],
        ];

        $snapshot = $calculator->snapshot($evidence);
        $events = $calculator->calculate($evidence, $snapshot);

        $this->assertSame('no_previous_snapshot', $events['etf_positions']['comparison_status']);
        $this->assertSame('baseline_only', $events['etf_positions']['positions'][0]['membership']);
        $this->assertSame(8.25, $events['etf_positions']['positions'][0]['current_weight_pct']);
        $this->assertNull($events['etf_positions']['positions'][0]['weight_change_pp']);
    }
}
