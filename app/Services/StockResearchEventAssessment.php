<?php

namespace App\Services;

use App\Models\StockHolding;
use Illuminate\Support\Str;

class StockResearchEventAssessment
{
    /**
     * @param  array<int, array<string, mixed>>  $developments
     * @param  array<string, mixed>  $calculatedEvents
     * @return array<int, array<string, mixed>>
     */
    public function enrich(array $developments, StockHolding $holding, array $calculatedEvents): array
    {
        $positions = collect($calculatedEvents['etf_positions']['positions'] ?? [])
            ->filter(fn (mixed $position): bool => is_array($position))
            ->values();

        return collect($developments)
            ->map(function (array $development) use ($holding, $positions): array {
                $sourceConfidence = $this->sourceConfidence($development);
                $affectedShare = $this->affectedShare($development, $holding, $positions->all());
                $isReliable = ($development['coverage'] ?? null) === 'complete'
                    && $sourceConfidence !== 'low'
                    && ($development['impact'] ?? null) !== 'unclear';

                return [
                    ...$development,
                    'proposed_impact' => $development['impact'],
                    'proposed_materiality' => $development['materiality'],
                    'current_impact' => $isReliable
                        ? $development['impact']
                        : 'no_reliable_assessment',
                    'materiality' => $isReliable ? $development['materiality'] : null,
                    'source_confidence' => $sourceConfidence,
                    'affected_etf_share_pct' => $affectedShare,
                    'time_horizon' => $development['time_horizon'],
                    'assessment_status' => $isReliable ? 'reliable' : 'no_reliable_assessment',
                    'assessment_reason' => $isReliable
                        ? 'Strukturierte Ereignisklassifikation mit vollständiger Abdeckung und belastbarer Quelle.'
                        : $this->unreliableReason($development, $sourceConfidence),
                    'assessment_method' => 'structured_event_classification_with_server_coverage_guardrails',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $developments
     * @return array<string, mixed>
     */
    public function summarize(array $developments): array
    {
        $reliable = collect($developments)
            ->filter(fn (mixed $development): bool => is_array($development)
                && ($development['assessment_status'] ?? null) === 'reliable')
            ->values();

        if ($reliable->isEmpty()) {
            return [
                'status' => 'no_reliable_assessment',
                'current_impact' => 'no_reliable_assessment',
                'materiality' => null,
                'source_confidence' => $this->lowestConfidence($developments),
                'affected_etf_share_pct' => $this->totalAffectedShare($developments),
                'time_horizons' => $this->timeHorizons($developments),
                'reason' => 'Keine belastbare Gesamteinschätzung bei unvollständiger Abdeckung, niedriger Quellenqualität oder unklarem Einfluss.',
                'method' => 'aggregate_reliable_current_events_only',
            ];
        }

        $impacts = $reliable->pluck('current_impact')->unique()->values();
        $impact = $impacts->count() === 1 ? $impacts->first() : 'mixed';

        return [
            'status' => 'reliable',
            'current_impact' => $impact,
            'materiality' => $this->highestMateriality($reliable->pluck('materiality')->all()),
            'source_confidence' => $this->lowestConfidence($reliable->all()),
            'affected_etf_share_pct' => $this->totalAffectedShare($reliable->all()),
            'time_horizons' => $this->timeHorizons($reliable->all()),
            'reason' => 'Aggregation ausschließlich belastbarer aktueller Ereignisse; keine Kauf- oder Verkaufsempfehlung.',
            'method' => 'aggregate_reliable_current_events_only',
        ];
    }

    /**
     * @param  array<string, mixed>  $development
     */
    private function sourceConfidence(array $development): string
    {
        $confidence = match ($development['source_type'] ?? null) {
            'issuer', 'regulator', 'exchange', 'market_data' => 'high',
            'reputable_media' => 'medium',
            default => 'low',
        };

        if (in_array($development['status'] ?? null, ['unconfirmed', 'stale'], true)) {
            return $confidence === 'high' ? 'medium' : 'low';
        }

        return $confidence;
    }

    /**
     * @param  array<string, mixed>  $development
     * @param  array<int, array<string, mixed>>  $positions
     */
    private function affectedShare(array $development, StockHolding $holding, array $positions): ?float
    {
        if (! $this->isFund($holding)) {
            return 100.0;
        }

        $subject = Str::lower((string) ($development['subject'] ?? ''));
        $subjectSymbol = Str::upper((string) ($development['subject_symbol'] ?? ''));

        foreach ($positions as $position) {
            $qualifiedSymbol = Str::upper((string) ($position['subject']['symbol'] ?? ''));
            $symbol = Str::lower(Str::before($qualifiedSymbol, '.'));
            $name = Str::lower((string) ($position['subject']['name'] ?? ''));
            $matchesSymbol = $subjectSymbol !== ''
                ? ($subjectSymbol === $qualifiedSymbol || Str::before($subjectSymbol, '.') === Str::before($qualifiedSymbol, '.'))
                : (mb_strlen($symbol) >= 2 && str_contains($subject, $symbol));
            $matchesName = mb_strlen($name) >= 3 && (str_contains($subject, $name) || str_contains($name, $subject));

            if (($matchesSymbol || $matchesName) && is_numeric($position['current_weight_pct'] ?? null)) {
                return round((float) $position['current_weight_pct'], 4);
            }
        }

        return null;
    }

    private function isFund(StockHolding $holding): bool
    {
        return Str::contains(Str::upper((string) $holding->instrument_type), ['ETF', 'FUND', 'FONDS']);
    }

    /**
     * @param  array<string, mixed>  $development
     */
    private function unreliableReason(array $development, string $sourceConfidence): string
    {
        return match (true) {
            ($development['coverage'] ?? null) !== 'complete' => 'Keine belastbare Einschätzung, weil die Rechercheabdeckung unvollständig oder eine Datenquelle ausgefallen ist.',
            $sourceConfidence === 'low' => 'Keine belastbare Einschätzung wegen niedrigen Quellenvertrauens.',
            default => 'Keine belastbare Einschätzung, weil der aktuelle Einfluss nicht eindeutig belegt ist.',
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $developments
     */
    private function lowestConfidence(array $developments): ?string
    {
        $values = collect($developments)
            ->pluck('source_confidence')
            ->filter(fn (mixed $value): bool => is_string($value))
            ->all();

        foreach (['low', 'medium', 'high'] as $confidence) {
            if (in_array($confidence, $values, true)) {
                return $confidence;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $developments
     */
    private function totalAffectedShare(array $developments): ?float
    {
        $shares = collect($developments)
            ->filter(fn (mixed $development): bool => is_array($development))
            ->mapWithKeys(function (array $development): array {
                $share = $development['affected_etf_share_pct'] ?? null;

                return is_numeric($share)
                    ? [Str::lower((string) ($development['subject'] ?? '')) => (float) $share]
                    : [];
            });

        return $shares->isEmpty() ? null : round(min(100, $shares->sum()), 4);
    }

    /**
     * @param  array<int, array<string, mixed>>  $developments
     * @return array<int, string>
     */
    private function timeHorizons(array $developments): array
    {
        return collect($developments)
            ->pluck('time_horizon')
            ->filter(fn (mixed $value): bool => is_string($value))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, mixed>  $materialities
     */
    private function highestMateriality(array $materialities): ?string
    {
        foreach (['high', 'medium', 'low'] as $materiality) {
            if (in_array($materiality, $materialities, true)) {
                return $materiality;
            }
        }

        return null;
    }
}
