<?php

namespace App\Services;

use App\Models\StockAiResearch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class StockResearchNowRelevant
{
    /**
     * @return array<string, mixed>
     */
    public function build(StockAiResearch $research): array
    {
        $developments = collect($research->developments ?? [])
            ->filter(fn (mixed $development): bool => is_array($development))
            ->values();
        $events = is_array($research->calculated_events) ? $research->calculated_events : [];
        $positions = collect($events['etf_positions']['positions'] ?? [])
            ->filter(fn (mixed $position): bool => is_array($position))
            ->values();
        $calculatedAt = $events['calculated_at'] ?? $research->finished_at?->toIso8601String();

        return [
            'generated_at' => $calculatedAt,
            'history_usage' => 'comparison_only',
            'blocks' => [
                $this->block(
                    'last_72_hours',
                    'Heute / letzte 72 Stunden',
                    $this->last72Hours($developments->all(), $events, $calculatedAt),
                    'Keine belegte Meldung oder Marktbeobachtung in den letzten 72 Stunden.',
                ),
                $this->block(
                    'current_top_positions',
                    'Aktuelle Top-Positionen',
                    $positions
                        ->reject(fn (array $position): bool => ($position['membership'] ?? null) === 'left_scope')
                        ->map(fn (array $position): array => $this->positionItem($position, false))
                        ->all(),
                    'Keine aktuelle Top-Positionsliste verfügbar.',
                ),
                $this->block(
                    'position_changes',
                    'Änderungen seit dem letzten offiziellen Factsheet',
                    ($events['etf_positions']['comparison_status'] ?? null) === 'compared'
                        && ! empty($events['etf_positions']['current_data_as_of'])
                        && ! empty($events['etf_positions']['previous_data_as_of'])
                        ? $positions
                            ->filter(fn (array $position): bool => ($position['membership'] ?? null) !== 'unchanged'
                                || ($position['weight_direction'] ?? null) !== 'unchanged')
                            ->map(fn (array $position): array => $this->positionItem($position, true))
                            ->all()
                        : [],
                    'Kein vergleichbarer datierter offizieller Positions-Snapshot verfügbar.',
                    $events['etf_positions']['coverage'] ?? 'partial',
                ),
                $this->block(
                    'latest_earnings',
                    'Zuletzt veröffentlichte Zahlen',
                    collect($events['earnings'] ?? [])->map(fn (array $event): array => $this->earningsItem($event))->all(),
                    'Keine aktuellen veröffentlichten Zahlen in der Abdeckung.',
                ),
                $this->block(
                    'upcoming_events',
                    'Nächste Termine',
                    collect($events['upcoming_events'] ?? [])->map(fn (array $event): array => $this->upcomingItem($event))->all(),
                    'Keine anstehenden Ergebnistermine in der Abdeckung.',
                ),
                $this->block(
                    'current_guidance',
                    'Aktuelle Guidance',
                    collect($events['guidance'] ?? [])->map(fn (array $event): array => $this->guidanceItem($event))->all(),
                    $events['guidance_coverage']['reason'] ?? 'Keine vergleichbare strukturierte Guidance verfügbar.',
                    ($events['guidance_coverage']['status'] ?? null) === 'available' ? 'complete' : 'partial',
                ),
                $this->block(
                    'rumors',
                    'Gerüchte',
                    $developments
                        ->where('category', 'rumor')
                        ->map(fn (array $development): array => $this->developmentItem($development))
                        ->all(),
                    'Keine belastbaren aktuellen Gerüchte gefunden.',
                ),
                $this->block(
                    'unusual_activity',
                    'Außergewöhnliche Aktivitäten',
                    [
                        ...$developments
                            ->where('category', 'unusual_activity')
                            ->map(fn (array $development): array => $this->developmentItem($development))
                            ->all(),
                        ...collect($events['unusual_volume'] ?? [])
                            ->map(fn (array $event): array => $this->unusualVolumeItem($event))
                            ->all(),
                    ],
                    'Keine belastbare außergewöhnliche Aktivität festgestellt.',
                ),
                $this->coverageBlock($events['data_coverage'] ?? [], $calculatedAt),
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function block(
        string $key,
        string $title,
        array $items,
        string $emptyMessage,
        string $coverage = 'complete',
    ): array {
        return [
            'key' => $key,
            'title' => $title,
            'items' => array_values($items),
            'empty_message' => $emptyMessage,
            'coverage' => $coverage,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $developments
     * @param  array<string, mixed>  $events
     * @return array<int, array<string, mixed>>
     */
    private function last72Hours(array $developments, array $events, mixed $calculatedAt): array
    {
        try {
            $reference = Carbon::parse((string) $calculatedAt);
        } catch (\Throwable) {
            return [];
        }

        $items = collect($developments)
            ->filter(function (array $development) use ($reference): bool {
                $timestamp = $development['published_at'] ?? $development['event_at'] ?? null;

                if (! is_string($timestamp)) {
                    return false;
                }

                try {
                    $eventTime = Carbon::parse($timestamp);
                } catch (\Throwable) {
                    return false;
                }

                return $eventTime->betweenIncluded($reference->copy()->subHours(72), $reference);
            })
            ->map(fn (array $development): array => $this->developmentItem($development));

        $marketItems = collect($events['market_reactions'] ?? [])
            ->filter(fn (mixed $event): bool => is_array($event))
            ->map(fn (array $event): array => $this->marketReactionItem($event));

        return $items
            ->concat($marketItems)
            ->sortByDesc('event_at')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $development
     * @return array<string, mixed>
     */
    private function developmentItem(array $development): array
    {
        return [
            'type' => 'development',
            'subject' => $development['subject'] ?? null,
            'title' => $development['headline'] ?? null,
            'detail' => $development['details'] ?? null,
            'relevance' => $development['relevance'] ?? null,
            'event_at' => $development['event_at'] ?? null,
            'published_at' => $development['published_at'] ?? null,
            'retrieved_at' => $development['retrieved_at'] ?? null,
            'data_as_of' => $development['data_as_of'] ?? null,
            'freshness' => $development['freshness'] ?? null,
            'coverage' => $development['coverage'] ?? 'partial',
            'status' => $development['status'] ?? null,
            'source_title' => $development['source_title'] ?? null,
            'source_url' => $development['source_url'] ?? null,
            'current_impact' => $development['current_impact'] ?? 'no_reliable_assessment',
            'materiality' => $development['materiality'] ?? null,
            'source_confidence' => $development['source_confidence'] ?? null,
            'affected_etf_share_pct' => $development['affected_etf_share_pct'] ?? null,
            'time_horizon' => $development['time_horizon'] ?? null,
            'assessment_status' => $development['assessment_status'] ?? 'no_reliable_assessment',
            'assessment_reason' => $development['assessment_reason'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $position
     * @return array<string, mixed>
     */
    private function positionItem(array $position, bool $change): array
    {
        $subject = $position['subject']['name'] ?? $position['subject']['symbol'] ?? 'Position';
        $currentWeight = $position['current_weight_pct'] ?? null;
        $rank = $position['current_rank'] ?? null;
        $parts = [];

        if (is_numeric($rank)) {
            $parts[] = 'Rang '.(int) $rank;
        }

        if (is_numeric($currentWeight)) {
            $parts[] = number_format((float) $currentWeight, 2, ',', '.').' %';
        }

        if ($change && is_numeric($position['weight_change_pp'] ?? null)) {
            $parts[] = sprintf('%+.2f Prozentpunkte', (float) $position['weight_change_pp']);
        }

        return [
            'type' => $change ? 'position_change' : 'current_position',
            'subject' => $subject,
            'title' => $change ? $this->positionChangeTitle($position, (string) $subject) : (string) $subject,
            'detail' => implode(' · ', $parts),
            'event_at' => $position['event_at'] ?? null,
            'retrieved_at' => $position['retrieved_at'] ?? null,
            'data_as_of' => $position['data_as_of'] ?? null,
            'freshness' => $position['freshness'] ?? null,
            'coverage' => $position['coverage'] ?? 'partial',
        ];
    }

    /**
     * @param  array<string, mixed>  $position
     */
    private function positionChangeTitle(array $position, string $subject): string
    {
        return match ($position['membership'] ?? null) {
            'entered_scope' => "{$subject}: neu im beobachteten Top-Positionsbereich",
            'left_scope' => "{$subject}: nicht mehr im beobachteten Top-Positionsbereich",
            default => match ($position['weight_direction'] ?? null) {
                'increased' => "{$subject}: Gewicht gestiegen",
                'decreased' => "{$subject}: Gewicht gesunken",
                default => "{$subject}: Position unverändert",
            },
        };
    }

    /** @param array<string, mixed> $event */
    private function earningsItem(array $event): array
    {
        $symbol = $event['subject']['name'] ?? $event['subject']['symbol'] ?? 'Unternehmen';
        $eps = $event['eps'] ?? [];
        $revenue = $event['revenue'] ?? [];
        $parts = [];

        if (is_numeric($eps['actual'] ?? null)) {
            $parts[] = 'EPS '.number_format((float) $eps['actual'], 2, ',', '.')
                .$this->surpriseText($eps['surprise_pct'] ?? null);
        }

        if (is_numeric($revenue['actual'] ?? null)) {
            $parts[] = 'Umsatz '.number_format((float) $revenue['actual'], 2, ',', '.')
                .$this->surpriseText($revenue['surprise_pct'] ?? null);
        }

        return $this->serverItem($event, (string) $symbol.' meldet Zahlen', implode(' · ', $parts));
    }

    /** @param array<string, mixed> $event */
    private function upcomingItem(array $event): array
    {
        $symbol = $event['subject']['name'] ?? $event['subject']['symbol'] ?? 'Unternehmen';

        return $this->serverItem(
            $event,
            (string) $symbol.': Ergebnistermin',
            ! empty($event['timing']) ? 'Zeitpunkt: '.$event['timing'] : 'Termin laut aktuellem Ergebniskalender',
        );
    }

    /** @param array<string, mixed> $event */
    private function guidanceItem(array $event): array
    {
        $symbol = $event['subject']['name'] ?? $event['subject']['symbol'] ?? 'Unternehmen';
        $classification = match ($event['classification'] ?? null) {
            'raised' => 'angehoben',
            'lowered' => 'gesenkt',
            'confirmed' => 'bestätigt',
            default => 'gemischt',
        };

        return $this->serverItem(
            $event,
            (string) $symbol.': Guidance '.$classification,
            implode(' · ', array_filter([$event['metric'] ?? null, $event['period'] ?? null, $event['unit'] ?? null])),
        );
    }

    /** @param array<string, mixed> $event */
    private function unusualVolumeItem(array $event): array
    {
        $symbol = $event['subject']['name'] ?? $event['subject']['symbol'] ?? 'Wertpapier';
        $ratio = is_numeric($event['relative_volume'] ?? null)
            ? number_format((float) $event['relative_volume'], 2, ',', '.').'x des 5-Tage-Medians'
            : 'auffälliges Volumen';

        return $this->serverItem($event, (string) $symbol.': auffälliges Volumen', $ratio);
    }

    /** @param array<string, mixed> $event */
    private function marketReactionItem(array $event): array
    {
        $symbol = $event['subject']['name'] ?? $event['subject']['symbol'] ?? 'Wertpapier';
        $reaction = is_numeric($event['reaction_pct'] ?? null)
            ? sprintf('%+.2f %%', (float) $event['reaction_pct'])
            : 'keine berechenbare Reaktion';
        $label = ($event['is_same_day'] ?? false) ? 'heutige Kursreaktion' : 'letzte Handelssitzung';

        return $this->serverItem($event, (string) $symbol.': '.$label, $reaction);
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function serverItem(array $event, string $title, string $detail): array
    {
        return [
            'type' => $event['type'] ?? 'calculated_event',
            'subject' => $event['subject']['name'] ?? $event['subject']['symbol'] ?? null,
            'title' => $title,
            'detail' => $detail,
            'event_at' => $event['event_at'] ?? null,
            'published_at' => $event['published_at'] ?? null,
            'retrieved_at' => $event['retrieved_at'] ?? null,
            'data_as_of' => $event['data_as_of'] ?? null,
            'freshness' => $event['freshness'] ?? null,
            'coverage' => $event['coverage'] ?? 'partial',
            'source_title' => $event['source_title'] ?? null,
            'source_url' => $event['source_url'] ?? null,
        ];
    }

    private function surpriseText(mixed $surprise): string
    {
        return is_numeric($surprise) ? sprintf(' (%+.2f %% vs. Konsens)', (float) $surprise) : '';
    }

    /**
     * @param  array<string, mixed>  $coverage
     * @return array<string, mixed>
     */
    private function coverageBlock(array $coverage, mixed $calculatedAt): array
    {
        $items = collect($coverage['datasets'] ?? [])
            ->filter(fn (mixed $dataset): bool => is_array($dataset))
            ->map(fn (array $dataset): array => [
                'type' => 'coverage',
                'subject' => $dataset['symbol'] ?? null,
                'title' => Str::headline((string) ($dataset['dataset'] ?? 'Datenquelle')),
                'detail' => $dataset['status'] ?? 'unknown',
                'retrieved_at' => $dataset['retrieved_at'] ?? null,
                'coverage' => in_array($dataset['status'] ?? null, ['fresh', 'cached_fresh'], true)
                    ? 'complete'
                    : (($dataset['status'] ?? null) === 'unavailable' ? 'source_failed' : 'partial'),
            ])
            ->all();

        if ($items === []) {
            $items[] = [
                'type' => 'coverage',
                'title' => 'Rechercheabdeckung',
                'detail' => $coverage['status'] ?? 'unavailable',
                'retrieved_at' => $coverage['as_of'] ?? $calculatedAt,
                'coverage' => ($coverage['coverage_complete'] ?? false) ? 'complete' : 'partial',
            ];
        }

        return $this->block(
            'data_coverage',
            'Datenstand und Rechercheabdeckung',
            $items,
            'Keine Abdeckungsdaten verfügbar.',
            ($coverage['coverage_complete'] ?? false) ? 'complete' : 'partial',
        );
    }
}
