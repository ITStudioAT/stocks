<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class StockResearchCurrentEvents
{
    private const PositionChangeTolerance = 0.005;

    private const UnusualVolumeThreshold = 2.0;

    private const VolumeBaselineSessions = 5;

    private const MinimumVolumeBaselineSessions = 3;

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    public function snapshot(array $evidence): array
    {
        $capturedAt = $this->dateTime($evidence['as_of'] ?? null)
            ?? now('Europe/Vienna')->toIso8601String();

        return [
            'version' => 1,
            'captured_at' => $capturedAt,
            'etf' => [
                'classification' => $this->string($evidence['etf_snapshot']['classification'] ?? null),
                'scope' => $this->positionScope($evidence['etf_snapshot']['positions'] ?? []),
                'data_as_of' => $this->dateTimeOrDate($evidence['etf_snapshot']['source_as_of'] ?? null),
                'retrieved_at' => $this->dateTime($evidence['etf_snapshot']['retrieved_at'] ?? null),
                'positions' => $this->positions($evidence['etf_snapshot']['positions'] ?? []),
            ],
            'guidance' => $this->guidanceSnapshot($evidence['structured_guidance'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @param  array<string, mixed>  $currentSnapshot
     * @param  array<string, mixed>|null  $previousSnapshot
     * @return array<string, mixed>
     */
    public function calculate(
        array $evidence,
        array $currentSnapshot,
        ?array $previousSnapshot = null,
        ?string $comparisonSnapshotId = null,
    ): array {
        $marketReactions = $this->marketReactions($evidence, $currentSnapshot['captured_at']);
        $guidance = $this->guidanceEvents(
            $currentSnapshot['guidance'] ?? [],
            $previousSnapshot['guidance'] ?? [],
            $comparisonSnapshotId,
        );

        return [
            'version' => 1,
            'calculated_at' => $currentSnapshot['captured_at'],
            'history_usage' => 'comparison_only',
            'comparison_snapshot_id' => $comparisonSnapshotId,
            'etf_positions' => $this->positionEvents(
                $currentSnapshot['etf'] ?? [],
                $previousSnapshot['etf'] ?? null,
                $comparisonSnapshotId,
            ),
            'earnings' => $this->earningsEvents($evidence),
            'upcoming_events' => $this->upcomingEvents($evidence),
            'guidance' => $guidance['items'],
            'guidance_coverage' => $guidance['coverage'],
            'market_reactions' => $marketReactions,
            'etf_relevance' => $this->etfRelevance(
                $currentSnapshot['etf'] ?? [],
                $marketReactions,
            ),
            'unusual_volume' => array_values(array_filter(
                $marketReactions,
                fn (array $reaction): bool => $reaction['is_unusual_volume'] === true,
            )),
            'data_coverage' => $this->dataCoverage($evidence),
        ];
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>|null  $previous
     * @return array<string, mixed>
     */
    private function positionEvents(
        array $current,
        ?array $previous,
        ?string $comparisonSnapshotId,
    ): array {
        $currentPositions = $this->positions($current['positions'] ?? []);
        $previousPositions = $this->positions($previous['positions'] ?? []);
        $comparisonStatus = match (true) {
            $currentPositions === [] => 'current_snapshot_unavailable',
            $previousPositions === [] => 'no_previous_snapshot',
            default => 'compared',
        };
        $items = [];

        if ($comparisonStatus === 'no_previous_snapshot') {
            foreach ($currentPositions as $position) {
                $items[] = $this->positionEvent(
                    $position,
                    null,
                    'baseline_only',
                    $current,
                    null,
                    null,
                );
            }
        } elseif ($comparisonStatus === 'compared') {
            $previousByIdentity = $this->positionsByIdentity($previousPositions);
            $currentByIdentity = $this->positionsByIdentity($currentPositions);

            foreach ($currentPositions as $position) {
                $identity = $this->positionIdentity($position);
                $priorPosition = $previousByIdentity[$identity] ?? null;
                $membership = $priorPosition === null ? 'entered_scope' : 'unchanged';
                $items[] = $this->positionEvent(
                    $position,
                    $priorPosition,
                    $membership,
                    $current,
                    $previous,
                    $comparisonSnapshotId,
                );
            }

            foreach ($previousPositions as $position) {
                if (! isset($currentByIdentity[$this->positionIdentity($position)])) {
                    $items[] = $this->positionEvent(
                        null,
                        $position,
                        'left_scope',
                        $current,
                        $previous,
                        $comparisonSnapshotId,
                    );
                }
            }
        }

        return [
            'comparison_status' => $comparisonStatus,
            'scope' => $current['scope'] ?? null,
            'classification' => $current['classification'] ?? null,
            'current_observed_at' => $current['retrieved_at'] ?? null,
            'previous_observed_at' => $previous['retrieved_at'] ?? null,
            'current_data_as_of' => $current['data_as_of'] ?? null,
            'previous_data_as_of' => $previous['data_as_of'] ?? null,
            'coverage' => ($current['data_as_of'] ?? null) === null ? 'partial' : 'complete',
            'scope_note' => 'Aufgenommen und verlassen beziehen sich nur auf die beobachtete Top-Positionsliste, nicht auf das vollständige ETF-Portfolio.',
            'positions' => $items,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $current
     * @param  array<string, mixed>|null  $previous
     * @param  array<string, mixed>  $currentSnapshot
     * @param  array<string, mixed>|null  $previousSnapshot
     * @return array<string, mixed>
     */
    private function positionEvent(
        ?array $current,
        ?array $previous,
        string $membership,
        array $currentSnapshot,
        ?array $previousSnapshot,
        ?string $comparisonSnapshotId,
    ): array {
        $currentWeight = $this->number($current['weight_pct'] ?? null);
        $previousWeight = $this->number($previous['weight_pct'] ?? null);
        $weightChange = $currentWeight !== null && $previousWeight !== null
            ? round($currentWeight - $previousWeight, 4)
            : null;
        $currentRank = $this->integer($current['rank'] ?? null);
        $previousRank = $this->integer($previous['rank'] ?? null);

        return [
            'type' => 'etf_position_change',
            'subject' => [
                'symbol' => $current['symbol'] ?? $previous['symbol'] ?? null,
                'name' => $current['name'] ?? $previous['name'] ?? null,
            ],
            'membership' => $membership,
            'weight_direction' => $this->weightDirection($weightChange),
            'current_rank' => $currentRank,
            'previous_rank' => $previousRank,
            'rank_change' => $currentRank !== null && $previousRank !== null
                ? $previousRank - $currentRank
                : null,
            'current_weight_pct' => $currentWeight,
            'previous_weight_pct' => $previousWeight,
            'weight_change_pp' => $weightChange,
            'event_at' => $currentSnapshot['data_as_of'] ?? $currentSnapshot['retrieved_at'] ?? null,
            'published_at' => null,
            'retrieved_at' => $currentSnapshot['retrieved_at'] ?? null,
            'data_as_of' => $currentSnapshot['data_as_of'] ?? null,
            'freshness' => ($currentSnapshot['data_as_of'] ?? null) === null ? 'delayed' : 'current',
            'coverage' => ($currentSnapshot['data_as_of'] ?? null) === null ? 'partial' : 'complete',
            'source_datasets' => ['etf_snapshot'],
            'comparison_snapshot_id' => $comparisonSnapshotId,
            'calculation' => [
                'method' => 'current_weight_pct - previous_weight_pct',
                'inputs' => [$currentWeight, $previousWeight],
                'result' => $weightChange,
            ],
            'approximate' => false,
        ];
    }

    private function weightDirection(?float $difference): ?string
    {
        if ($difference === null) {
            return null;
        }

        return match (true) {
            $difference > self::PositionChangeTolerance => 'increased',
            $difference < -self::PositionChangeTolerance => 'decreased',
            default => 'unchanged',
        };
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<int, array<string, mixed>>
     */
    private function earningsEvents(array $evidence): array
    {
        $records = is_array($evidence['earnings_calendar'] ?? null)
            ? $evidence['earnings_calendar']
            : [];

        foreach ($evidence['company_fundamentals'] ?? [] as $company) {
            if (is_array($company) && is_array($company['earnings_window'] ?? null)) {
                $records = [...$records, ...$company['earnings_window']];
            }
        }

        $events = [];

        foreach ($records as $record) {
            if (! is_array($record) || ($record['status'] ?? null) !== 'reported') {
                continue;
            }

            $symbol = $this->symbol($record['symbol'] ?? null);
            $eventAt = $this->dateTimeOrDate($record['report_date'] ?? null);

            if ($symbol === null || $eventAt === null) {
                continue;
            }

            $eps = $this->actualVersusConsensus(
                $record['eps_actual'] ?? null,
                $record['eps_estimate'] ?? null,
            );
            $revenue = $this->actualVersusConsensus(
                $record['revenue_actual'] ?? null,
                $record['revenue_estimate'] ?? null,
            );

            if ($eps['actual'] === null && $revenue['actual'] === null) {
                continue;
            }

            $key = $symbol.'|'.$eventAt;

            if (isset($events[$key])) {
                continue;
            }

            $events[$key] = [
                'type' => 'earnings_actual_vs_consensus',
                'subject' => ['symbol' => $symbol, 'name' => null],
                'event_at' => $eventAt,
                'published_at' => null,
                'retrieved_at' => $this->dateTime($evidence['as_of'] ?? null),
                'data_as_of' => $this->dateTimeOrDate($record['fiscal_period_end'] ?? null),
                'freshness' => $this->freshness($eventAt, $this->dateTime($evidence['as_of'] ?? null)),
                'coverage' => $eps['consensus'] !== null || $revenue['consensus'] !== null ? 'complete' : 'partial',
                'source_datasets' => ['earnings_calendar'],
                'comparison_snapshot_id' => null,
                'currency' => $this->string($record['currency'] ?? null),
                'eps' => $eps,
                'revenue' => $revenue,
                'calculation' => [
                    'method' => '(actual - consensus) / abs(consensus) * 100',
                    'inputs' => [
                        'eps_actual' => $eps['actual'],
                        'eps_consensus' => $eps['consensus'],
                        'revenue_actual' => $revenue['actual'],
                        'revenue_consensus' => $revenue['consensus'],
                    ],
                    'result' => [
                        'eps_surprise_pct' => $eps['surprise_pct'],
                        'revenue_surprise_pct' => $revenue['surprise_pct'],
                    ],
                ],
                'approximate' => false,
            ];
        }

        return array_values($events);
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<int, array<string, mixed>>
     */
    private function upcomingEvents(array $evidence): array
    {
        $records = is_array($evidence['earnings_calendar'] ?? null)
            ? $evidence['earnings_calendar']
            : [];

        foreach ($evidence['company_fundamentals'] ?? [] as $company) {
            if (is_array($company) && is_array($company['earnings_window'] ?? null)) {
                $records = [...$records, ...$company['earnings_window']];
            }
        }

        $retrievedAt = $this->dateTime($evidence['as_of'] ?? null);
        $events = [];

        foreach ($records as $record) {
            if (! is_array($record) || ($record['status'] ?? null) !== 'scheduled') {
                continue;
            }

            $symbol = $this->symbol($record['symbol'] ?? null);
            $eventAt = $this->dateTimeOrDate($record['report_date'] ?? null);

            if ($symbol === null || $eventAt === null) {
                continue;
            }

            $key = $symbol.'|'.$eventAt;

            if (isset($events[$key])) {
                continue;
            }

            $events[$key] = [
                'type' => 'scheduled_earnings',
                'subject' => ['symbol' => $symbol, 'name' => null],
                'event_at' => $eventAt,
                'published_at' => null,
                'retrieved_at' => $retrievedAt,
                'data_as_of' => $this->dateTimeOrDate($record['fiscal_period_end'] ?? null),
                'freshness' => 'current',
                'coverage' => 'complete',
                'source_datasets' => ['earnings_calendar'],
                'comparison_snapshot_id' => null,
                'timing' => $this->string($record['timing'] ?? null),
                'currency' => $this->string($record['currency'] ?? null),
                'eps_consensus' => $this->number($record['eps_estimate'] ?? null),
                'revenue_consensus' => $this->number($record['revenue_estimate'] ?? null),
                'calculation' => [
                    'method' => 'provider scheduled earnings record; no forecast generated',
                    'inputs' => ['report_date' => $eventAt],
                    'result' => 'scheduled',
                ],
                'approximate' => false,
            ];
        }

        return collect($events)->sortBy('event_at')->values()->all();
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function dataCoverage(array $evidence): array
    {
        $datasets = collect($evidence['coverage'] ?? [])
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(fn (array $item): array => [
                'dataset' => $this->string($item['dataset'] ?? null),
                'symbol' => $this->string($item['symbol'] ?? null),
                'status' => $this->string($item['status'] ?? null) ?? 'unknown',
                'retrieved_at' => $this->dateTime($item['retrieved_at'] ?? null),
            ])
            ->values()
            ->all();

        return [
            'provider' => $this->string($evidence['provider'] ?? null),
            'status' => $this->string($evidence['status'] ?? null) ?? 'unavailable',
            'coverage_complete' => ($evidence['coverage_complete'] ?? false) === true,
            'as_of' => $this->dateTime($evidence['as_of'] ?? null),
            'datasets' => $datasets,
        ];
    }

    /**
     * @return array{actual: ?float, consensus: ?float, difference: ?float, surprise_pct: ?float, outcome: ?string}
     */
    private function actualVersusConsensus(mixed $actualValue, mixed $consensusValue): array
    {
        $actual = $this->number($actualValue);
        $consensus = $this->number($consensusValue);
        $difference = $actual !== null && $consensus !== null ? round($actual - $consensus, 6) : null;
        $surprise = $difference !== null && $consensus !== 0.0
            ? round($difference / abs($consensus) * 100, 4)
            : null;
        $outcome = match (true) {
            $difference === null => null,
            $difference > 0 => 'beat',
            $difference < 0 => 'miss',
            default => 'inline',
        };

        return [
            'actual' => $actual,
            'consensus' => $consensus,
            'difference' => $difference,
            'surprise_pct' => $surprise,
            'outcome' => $outcome,
        ];
    }

    /**
     * @param  array<int, mixed>  $currentRecords
     * @param  array<int, mixed>  $previousRecords
     * @return array{items: array<int, array<string, mixed>>, coverage: array<string, string>}
     */
    private function guidanceEvents(
        array $currentRecords,
        array $previousRecords,
        ?string $comparisonSnapshotId,
    ): array {
        $previousByIdentity = [];

        foreach ($previousRecords as $record) {
            if (is_array($record) && ($identity = $this->guidanceIdentity($record)) !== null) {
                $previousByIdentity[$identity] = $record;
            }
        }

        $items = [];

        foreach ($currentRecords as $current) {
            if (! is_array($current) || ($identity = $this->guidanceIdentity($current)) === null) {
                continue;
            }

            $previous = $previousByIdentity[$identity]
                ?? (is_array($current['previous_disclosed'] ?? null)
                    ? [...$current, ...$current['previous_disclosed']]
                    : null);
            $classification = is_array($previous)
                ? $this->guidanceClassification($current, $previous)
                : null;

            if ($classification === null) {
                continue;
            }

            $items[] = [
                'type' => 'guidance_change',
                'subject' => ['symbol' => $current['symbol'], 'name' => null],
                'metric' => $current['metric'],
                'period' => $current['period'],
                'unit' => $current['unit'],
                'classification' => $classification,
                'current' => $this->guidanceValues($current),
                'previous' => $this->guidanceValues($previous),
                'event_at' => $current['event_at'] ?? null,
                'published_at' => $current['published_at'] ?? null,
                'retrieved_at' => $current['retrieved_at'] ?? null,
                'data_as_of' => $current['data_as_of'] ?? null,
                'freshness' => $current['freshness'] ?? 'current',
                'coverage' => 'complete',
                'source_datasets' => ['structured_guidance'],
                'source_title' => $current['source_title'] ?? null,
                'source_url' => $current['source_url'] ?? null,
                'comparison_snapshot_id' => $comparisonSnapshotId,
                'calculation' => [
                    'method' => 'compare matching structured guidance range or value',
                    'inputs' => [
                        'current' => $this->guidanceValues($current),
                        'previous' => $this->guidanceValues($previous),
                    ],
                    'result' => $classification,
                ],
                'approximate' => false,
            ];
        }

        return [
            'items' => $items,
            'coverage' => $items === []
                ? [
                    'status' => 'unavailable',
                    'reason' => 'Keine vergleichbaren strukturierten aktuellen und vorherigen Guidance-Werte verfügbar.',
                ]
                : [
                    'status' => 'available',
                    'reason' => 'Strukturierte Guidance-Werte wurden serverseitig verglichen.',
                ],
        ];
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $previous
     */
    private function guidanceClassification(array $current, array $previous): ?string
    {
        $currentValues = $this->guidanceValues($current);
        $previousValues = $this->guidanceValues($previous);

        if ($currentValues === $previousValues) {
            return 'confirmed';
        }

        if ($currentValues['value'] !== null && $previousValues['value'] !== null) {
            return $currentValues['value'] > $previousValues['value'] ? 'raised' : 'lowered';
        }

        if (
            $currentValues['low'] !== null
            && $currentValues['high'] !== null
            && $previousValues['low'] !== null
            && $previousValues['high'] !== null
        ) {
            $raised = $currentValues['low'] >= $previousValues['low']
                && $currentValues['high'] >= $previousValues['high']
                && ($currentValues['low'] > $previousValues['low'] || $currentValues['high'] > $previousValues['high']);
            $lowered = $currentValues['low'] <= $previousValues['low']
                && $currentValues['high'] <= $previousValues['high']
                && ($currentValues['low'] < $previousValues['low'] || $currentValues['high'] < $previousValues['high']);

            return match (true) {
                $raised => 'raised',
                $lowered => 'lowered',
                default => 'mixed',
            };
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array{value: ?float, low: ?float, high: ?float}
     */
    private function guidanceValues(array $record): array
    {
        return [
            'value' => $this->number($record['value'] ?? null),
            'low' => $this->number($record['low'] ?? null),
            'high' => $this->number($record['high'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<int, array<string, mixed>>
     */
    private function marketReactions(array $evidence, string $calculatedAt): array
    {
        $contexts = is_array($evidence['recent_market_context'] ?? null)
            ? $evidence['recent_market_context']
            : [];
        $calculationDate = Carbon::parse($calculatedAt)->timezone('Europe/Vienna')->toDateString();
        $events = [];

        foreach ($evidence['current_market'] ?? [] as $record) {
            if (! is_array($record) || ($symbol = $this->symbol($record['symbol'] ?? null)) === null) {
                continue;
            }

            $close = $this->number($record['close'] ?? null);
            $previousClose = $this->number($record['previous_close'] ?? null);
            $providerAsOf = $this->dateTime($record['provider_as_of'] ?? null);

            if ($close === null || $previousClose === null || $previousClose <= 0 || $providerAsOf === null) {
                continue;
            }

            $sessionDate = Carbon::parse($providerAsOf)->timezone('Europe/Vienna')->toDateString();
            $priceChange = round($close - $previousClose, 6);
            $reaction = round($priceChange / $previousClose * 100, 4);
            $baselineVolumes = collect($contexts[$symbol] ?? [])
                ->filter(fn (mixed $item): bool => is_array($item))
                ->filter(fn (array $item): bool => ($this->dateTimeOrDate($item['date'] ?? null) ?? '') < $sessionDate)
                ->sortByDesc('date')
                ->take(self::VolumeBaselineSessions)
                ->map(fn (array $item): ?float => $this->number($item['volume'] ?? null))
                ->filter(fn (?float $volume): bool => $volume !== null && $volume > 0)
                ->values();
            $medianVolume = $baselineVolumes->count() >= self::MinimumVolumeBaselineSessions
                ? $this->median($baselineVolumes)
                : null;
            $sessionVolume = $this->number($record['session_volume'] ?? null);
            $relativeVolume = $medianVolume !== null && $sessionVolume !== null
                ? round($sessionVolume / $medianVolume, 4)
                : null;
            $isSameDay = $sessionDate === $calculationDate;
            $sessionComplete = ! $isSameDay;
            $retrievedAt = $this->dateTime($record['received_at'] ?? null)
                ?? $this->dateTime($evidence['as_of'] ?? null);

            $events[] = [
                'type' => 'market_reaction',
                'subject' => ['symbol' => $symbol, 'name' => null],
                'event_at' => $providerAsOf,
                'published_at' => null,
                'retrieved_at' => $retrievedAt,
                'data_as_of' => $providerAsOf,
                'freshness' => $this->freshness($providerAsOf, $retrievedAt, true),
                'coverage' => $relativeVolume === null ? 'partial' : 'complete',
                'source_datasets' => ['current_market', 'recent_market_context'],
                'comparison_snapshot_id' => null,
                'session_date' => $sessionDate,
                'is_same_day' => $isSameDay,
                'session_label' => $isSameDay ? 'today' : 'latest_available_session',
                'session_complete' => $sessionComplete,
                'close' => $close,
                'previous_close' => $previousClose,
                'price_change' => $priceChange,
                'reaction_pct' => $reaction,
                'session_volume' => $sessionVolume,
                'baseline_sessions' => $baselineVolumes->count(),
                'median_volume' => $medianVolume,
                'relative_volume' => $relativeVolume,
                'is_unusual_volume' => $relativeVolume !== null && $relativeVolume >= self::UnusualVolumeThreshold
                    ? true
                    : ($sessionComplete && $relativeVolume !== null ? false : null),
                'calculation' => [
                    'method' => 'close versus previous close; session volume versus median of up to five preceding completed sessions',
                    'inputs' => [
                        'close' => $close,
                        'previous_close' => $previousClose,
                        'session_volume' => $sessionVolume,
                        'baseline_volumes' => $baselineVolumes->all(),
                    ],
                    'result' => [
                        'reaction_pct' => $reaction,
                        'relative_volume' => $relativeVolume,
                    ],
                ],
                'approximate' => false,
            ];
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $etfSnapshot
     * @param  array<int, array<string, mixed>>  $marketReactions
     * @return array<int, array<string, mixed>>
     */
    private function etfRelevance(array $etfSnapshot, array $marketReactions): array
    {
        $reactionsBySymbol = [];

        foreach ($marketReactions as $reaction) {
            $symbol = $reaction['subject']['symbol'] ?? null;

            if (is_string($symbol)) {
                $reactionsBySymbol[$symbol] = $reaction;
            }
        }

        $items = [];

        foreach ($this->positions($etfSnapshot['positions'] ?? []) as $position) {
            $symbol = $position['symbol'] ?? null;
            $weight = $this->number($position['weight_pct'] ?? null);
            $reaction = is_string($symbol) ? ($reactionsBySymbol[$symbol] ?? null) : null;
            $reactionPercent = $this->number($reaction['reaction_pct'] ?? null);

            if (! is_string($symbol) || $weight === null || $reactionPercent === null) {
                continue;
            }

            $contribution = round($weight * $reactionPercent / 100, 4);
            $items[] = [
                'type' => 'estimated_etf_relevance',
                'subject' => ['symbol' => $symbol, 'name' => $position['name'] ?? null],
                'event_at' => $reaction['event_at'],
                'published_at' => null,
                'retrieved_at' => $reaction['retrieved_at'],
                'data_as_of' => $reaction['data_as_of'],
                'freshness' => $reaction['freshness'],
                'coverage' => ($etfSnapshot['data_as_of'] ?? null) === null ? 'partial' : $reaction['coverage'],
                'source_datasets' => ['etf_snapshot', 'current_market'],
                'comparison_snapshot_id' => null,
                'weight_pct' => $weight,
                'reaction_pct' => $reactionPercent,
                'estimated_contribution_pct_points' => $contribution,
                'calculation' => [
                    'method' => 'position_weight_pct * latest_session_reaction_pct / 100',
                    'inputs' => [$weight, $reactionPercent],
                    'result' => $contribution,
                ],
                'approximate' => true,
                'approximation_note' => 'Grobe isolierte Beitragsnäherung ohne Währungs-, Cash-, Derivate- oder Rebalancing-Effekte; keine Renditeattribution und keine Prognose.',
            ];
        }

        return $items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function positions(mixed $positions): array
    {
        if (! is_array($positions)) {
            return [];
        }

        $normalized = [];

        foreach ($positions as $position) {
            if (! is_array($position)) {
                continue;
            }

            $symbol = $this->symbol($position['symbol'] ?? null);
            $name = $this->string($position['name'] ?? null);

            if ($symbol === null && $name === null) {
                continue;
            }

            $normalized[] = [
                'rank' => $this->integer($position['rank'] ?? null),
                'symbol' => $symbol,
                'name' => $name,
                'weight_pct' => $this->number($position['weight_pct'] ?? null),
            ];
        }

        return $normalized;
    }

    private function positionScope(mixed $positions): string
    {
        return 'top_'.count(is_array($positions) ? $positions : []);
    }

    /**
     * @param  array<int, array<string, mixed>>  $positions
     * @return array<string, array<string, mixed>>
     */
    private function positionsByIdentity(array $positions): array
    {
        $indexed = [];

        foreach ($positions as $position) {
            $indexed[$this->positionIdentity($position)] = $position;
        }

        return $indexed;
    }

    /**
     * @param  array<string, mixed>  $position
     */
    private function positionIdentity(array $position): string
    {
        return $this->symbol($position['symbol'] ?? null)
            ?? mb_strtolower($this->string($position['name'] ?? null) ?? 'unknown');
    }

    /**
     * @param  array<int, mixed>  $records
     * @return array<int, array<string, mixed>>
     */
    private function guidanceSnapshot(array $records): array
    {
        $snapshot = [];

        foreach ($records as $record) {
            if (! is_array($record) || $this->guidanceIdentity($record) === null) {
                continue;
            }

            $values = $this->guidanceValues(is_array($record['current'] ?? null) ? $record['current'] : $record);

            if (! array_filter($values, fn (?float $value): bool => $value !== null)) {
                continue;
            }

            $snapshot[] = [
                'symbol' => $this->symbol($record['symbol'] ?? null),
                'metric' => $this->string($record['metric'] ?? null),
                'period' => $this->string($record['period'] ?? null),
                'unit' => $this->string($record['unit'] ?? null),
                ...$values,
                'previous_disclosed' => is_array($record['previous'] ?? null)
                    ? $this->guidanceValues($record['previous'])
                    : null,
                'event_at' => $this->dateTimeOrDate($record['event_at'] ?? null),
                'published_at' => $this->dateTimeOrDate($record['published_at'] ?? null),
                'retrieved_at' => $this->dateTime($record['retrieved_at'] ?? null),
                'data_as_of' => $this->dateTimeOrDate($record['data_as_of'] ?? null),
                'freshness' => $this->string($record['freshness'] ?? null),
                'source_title' => $this->string($record['source_title'] ?? null),
                'source_url' => $this->string($record['source_url'] ?? null),
            ];
        }

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function guidanceIdentity(array $record): ?string
    {
        $symbol = $this->symbol($record['symbol'] ?? null);
        $metric = $this->string($record['metric'] ?? null);
        $period = $this->string($record['period'] ?? null);
        $unit = $this->string($record['unit'] ?? null);

        if ($symbol === null || $metric === null || $period === null || $unit === null) {
            return null;
        }

        return implode('|', [$symbol, mb_strtolower($metric), mb_strtolower($period), mb_strtolower($unit)]);
    }

    private function median(Collection $values): float
    {
        $sorted = $values->sort()->values();
        $middle = intdiv($sorted->count(), 2);

        if ($sorted->count() % 2 === 1) {
            return (float) $sorted[$middle];
        }

        return ((float) $sorted[$middle - 1] + (float) $sorted[$middle]) / 2;
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }

    private function integer(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function symbol(mixed $value): ?string
    {
        $symbol = $this->string($value);

        return $symbol === null ? null : mb_strtoupper($symbol);
    }

    private function dateTime(mixed $value): ?string
    {
        $value = $this->string($value);

        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    private function dateTimeOrDate(mixed $value): ?string
    {
        $value = $this->string($value);

        if ($value === null) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $value;
        }

        return $this->dateTime($value);
    }

    private function freshness(?string $reference, ?string $retrievedAt, bool $hasTimestamp = false): string
    {
        if ($reference === null || $retrievedAt === null) {
            return 'stale';
        }

        try {
            $referenceTime = Carbon::parse($reference, 'Europe/Vienna');
            $retrievedTime = Carbon::parse($retrievedAt, 'Europe/Vienna');
        } catch (\Throwable) {
            return 'stale';
        }

        $ageInSeconds = max(0, $retrievedTime->getTimestamp() - $referenceTime->getTimestamp());

        return match (true) {
            $hasTimestamp && $ageInSeconds <= 15 * 60 => 'live',
            $ageInSeconds <= 24 * 60 * 60 => 'current',
            $ageInSeconds <= 7 * 24 * 60 * 60 => 'delayed',
            default => 'stale',
        };
    }
}
