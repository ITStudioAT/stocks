<?php

namespace Tests\Unit;

use App\Services\StockResearchRecommendation;
use PHPUnit\Framework\TestCase;

class StockResearchRecommendationTest extends TestCase
{
    public function test_it_accepts_a_complete_integer_distribution_for_reliable_research(): void
    {
        $recommendation = (new StockResearchRecommendation)->resolve([
            'buy_pct' => 55,
            'hold_pct' => 35,
            'sell_pct' => 10,
            'justification' => 'Mehrere aktuelle Primärquellen stützen die positive Bewertung.',
        ], [
            'status' => 'reliable',
            'current_impact' => 'positive',
        ]);

        $this->assertSame('buy', $recommendation['recommendation']);
        $this->assertSame(['buy' => 55, 'hold' => 35, 'sell' => 10], $recommendation['percentages']);
        $this->assertSame(100, array_sum($recommendation['percentages']));
        $this->assertSame('ai_evidence_distribution_with_server_guardrails', $recommendation['method']);
    }

    public function test_it_forces_hold_when_coverage_is_not_reliable(): void
    {
        $recommendation = (new StockResearchRecommendation)->resolve([
            'buy_pct' => 100,
            'hold_pct' => 0,
            'sell_pct' => 0,
            'justification' => 'This unsupported recommendation must be ignored.',
        ], [
            'status' => 'no_reliable_assessment',
            'current_impact' => 'positive',
        ]);

        $this->assertSame('hold', $recommendation['recommendation']);
        $this->assertSame(['buy' => 0, 'hold' => 100, 'sell' => 0], $recommendation['percentages']);
        $this->assertSame('server_guardrail_insufficient_coverage', $recommendation['method']);
    }

    public function test_it_replaces_an_invalid_total_with_a_reliable_server_fallback(): void
    {
        $recommendation = (new StockResearchRecommendation)->resolve([
            'buy_pct' => 70,
            'hold_pct' => 40,
            'sell_pct' => 10,
            'justification' => 'The total is invalid.',
        ], [
            'status' => 'reliable',
            'current_impact' => 'negative',
        ]);

        $this->assertSame('sell', $recommendation['recommendation']);
        $this->assertSame(['buy' => 5, 'hold' => 30, 'sell' => 65], $recommendation['percentages']);
        $this->assertSame(100, array_sum($recommendation['percentages']));
    }
}
