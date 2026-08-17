<?php

namespace Tests\Unit;

use App\Models\StockHolding;
use App\Services\StockResearchEventAssessment;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class StockResearchEventAssessmentTest extends TestCase
{
    #[Test]
    public function it_derives_source_confidence_and_etf_share_and_blocks_incomplete_assessments(): void
    {
        $holding = new StockHolding;
        $holding->instrument_type = 'ETF';
        $calculator = new StockResearchEventAssessment;
        $developments = [
            $this->development(),
            $this->development([
                'subject' => 'Beta',
                'coverage' => 'partial',
                'source_type' => 'reputable_media',
                'impact' => 'negative',
                'materiality' => 'low',
            ]),
        ];
        $calculatedEvents = [
            'etf_positions' => [
                'positions' => [
                    ['subject' => ['symbol' => 'ALPHA.US', 'name' => 'Alpha'], 'current_weight_pct' => 8.25],
                    ['subject' => ['symbol' => 'BETA.US', 'name' => 'Beta'], 'current_weight_pct' => 4.5],
                ],
            ],
        ];

        $result = $calculator->enrich($developments, $holding, $calculatedEvents);

        $this->assertSame('positive', $result[0]['current_impact']);
        $this->assertSame('high', $result[0]['source_confidence']);
        $this->assertSame(8.25, $result[0]['affected_etf_share_pct']);
        $this->assertSame('no_reliable_assessment', $result[1]['current_impact']);
        $this->assertNull($result[1]['materiality']);
        $this->assertSame('medium', $result[1]['source_confidence']);
        $this->assertStringContainsString('unvollständig', $result[1]['assessment_reason']);

        $summary = $calculator->summarize($result);

        $this->assertSame('reliable', $summary['status']);
        $this->assertSame('positive', $summary['current_impact']);
        $this->assertSame('high', $summary['materiality']);
        $this->assertSame(8.25, $summary['affected_etf_share_pct']);
    }

    #[Test]
    public function it_returns_no_reliable_assessment_when_no_event_passes_the_guardrails(): void
    {
        $holding = new StockHolding;
        $holding->instrument_type = 'Stock';
        $calculator = new StockResearchEventAssessment;
        $result = $calculator->enrich([
            $this->development([
                'coverage' => 'source_failed',
                'source_type' => 'other',
                'impact' => 'unclear',
            ]),
        ], $holding, []);

        $summary = $calculator->summarize($result);

        $this->assertSame('no_reliable_assessment', $summary['status']);
        $this->assertSame('no_reliable_assessment', $summary['current_impact']);
        $this->assertSame(100.0, $summary['affected_etf_share_pct']);
        $this->assertNull($summary['materiality']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function development(array $overrides = []): array
    {
        return array_replace([
            'subject' => 'Alpha',
            'coverage' => 'complete',
            'source_type' => 'issuer',
            'status' => 'confirmed',
            'impact' => 'positive',
            'materiality' => 'high',
            'time_horizon' => 'current_quarter',
        ], $overrides);
    }
}
