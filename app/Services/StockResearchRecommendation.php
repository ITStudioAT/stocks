<?php

namespace App\Services;

class StockResearchRecommendation
{
    /**
     * @param  array<string, mixed>  $assessment
     * @return array{
     *     recommendation: string,
     *     percentages: array{buy: int, hold: int, sell: int},
     *     justification: string,
     *     method: string
     * }
     */
    public function resolve(mixed $candidate, array $assessment): array
    {
        if (($assessment['status'] ?? null) !== 'reliable') {
            return $this->holdBecauseCoverageIsInsufficient();
        }

        $validatedCandidate = $this->validatedCandidate($candidate);

        if ($validatedCandidate !== null) {
            return $validatedCandidate;
        }

        return $this->fallbackForImpact($assessment['current_impact'] ?? null);
    }

    /**
     * @return array{
     *     recommendation: string,
     *     percentages: array{buy: int, hold: int, sell: int},
     *     justification: string,
     *     method: string
     * }|null
     */
    private function validatedCandidate(mixed $candidate): ?array
    {
        if (! is_array($candidate)) {
            return null;
        }

        $buy = $this->percentage($candidate['buy_pct'] ?? null);
        $hold = $this->percentage($candidate['hold_pct'] ?? null);
        $sell = $this->percentage($candidate['sell_pct'] ?? null);
        $justification = is_string($candidate['justification'] ?? null)
            ? trim($candidate['justification'])
            : '';

        if ($buy === null || $hold === null || $sell === null || $justification === '') {
            return null;
        }

        if ($buy + $hold + $sell !== 100) {
            return null;
        }

        $percentages = ['buy' => $buy, 'hold' => $hold, 'sell' => $sell];

        return [
            'recommendation' => $this->dominantRecommendation($percentages),
            'percentages' => $percentages,
            'justification' => str($justification)->limit(2000, '')->toString(),
            'method' => 'ai_evidence_distribution_with_server_guardrails',
        ];
    }

    private function percentage(mixed $value): ?int
    {
        if (! is_int($value) || $value < 0 || $value > 100) {
            return null;
        }

        return $value;
    }

    /**
     * @param  array{buy: int, hold: int, sell: int}  $percentages
     */
    private function dominantRecommendation(array $percentages): string
    {
        $maximum = max($percentages);

        if ($percentages['hold'] === $maximum) {
            return 'hold';
        }

        return $percentages['buy'] === $maximum ? 'buy' : 'sell';
    }

    /**
     * @return array{
     *     recommendation: string,
     *     percentages: array{buy: int, hold: int, sell: int},
     *     justification: string,
     *     method: string
     * }
     */
    private function holdBecauseCoverageIsInsufficient(): array
    {
        return [
            'recommendation' => 'hold',
            'percentages' => ['buy' => 0, 'hold' => 100, 'sell' => 0],
            'justification' => 'HOLD dominiert, weil die aktuelle Daten- oder Quellenabdeckung keine belastbare aktive BUY- oder SELL-Bewertung zulässt.',
            'method' => 'server_guardrail_insufficient_coverage',
        ];
    }

    /**
     * @return array{
     *     recommendation: string,
     *     percentages: array{buy: int, hold: int, sell: int},
     *     justification: string,
     *     method: string
     * }
     */
    private function fallbackForImpact(mixed $impact): array
    {
        return match ($impact) {
            'positive' => [
                'recommendation' => 'buy',
                'percentages' => ['buy' => 65, 'hold' => 30, 'sell' => 5],
                'justification' => 'Die belastbaren aktuellen Ereignisse werden insgesamt positiv bewertet.',
                'method' => 'server_fallback_from_reliable_event_assessment',
            ],
            'negative' => [
                'recommendation' => 'sell',
                'percentages' => ['buy' => 5, 'hold' => 30, 'sell' => 65],
                'justification' => 'Die belastbaren aktuellen Ereignisse werden insgesamt negativ bewertet.',
                'method' => 'server_fallback_from_reliable_event_assessment',
            ],
            default => [
                'recommendation' => 'hold',
                'percentages' => ['buy' => 20, 'hold' => 60, 'sell' => 20],
                'justification' => 'Die belastbaren aktuellen Ereignisse ergeben ein gemischtes Bild.',
                'method' => 'server_fallback_from_reliable_event_assessment',
            ],
        };
    }
}
