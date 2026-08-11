<?php

namespace App\Services;

use App\Models\EodhdExchange;
use App\Models\IndexEodhdSyncRun;
use App\Models\IndexWatchItem;
use App\Models\IndexWatchItemIntradayCandle;
use App\Models\IndexWatchItemPrice;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class V2IndexEodhdSyncService
{
    public const Interval = '5m';

    private const CoverageDays = 365;

    private const IntradayChunkDays = 30;

    private const IntradayRetryDelays = [250, 1000];

    private const IntradayFinalizationDelayHours = 3;

    private const IndexMarketProfiles = [
        'austria' => ['timezone' => 'Europe/Vienna', 'close' => '17:30:00'],
        'china' => ['timezone' => 'Asia/Shanghai', 'close' => '15:00:00'],
        'germany' => ['timezone' => 'Europe/Berlin', 'close' => '17:30:00'],
        'greece' => ['timezone' => 'Europe/Athens', 'close' => '17:20:00'],
        'japan' => ['timezone' => 'Asia/Tokyo', 'close' => '15:30:00'],
        'korea' => ['timezone' => 'Asia/Seoul', 'close' => '15:30:00'],
        'south korea' => ['timezone' => 'Asia/Seoul', 'close' => '15:30:00'],
        'switzerland' => ['timezone' => 'Europe/Zurich', 'close' => '17:30:00'],
        'usa' => ['timezone' => 'America/New_York', 'close' => '16:00:00'],
    ];

    private const IndexSymbolMarketProfiles = [
        '000001' => ['timezone' => 'Asia/Shanghai', 'close' => '15:00:00'],
        'ATG' => ['timezone' => 'Europe/Athens', 'close' => '17:20:00'],
        'ATX' => ['timezone' => 'Europe/Vienna', 'close' => '17:30:00'],
        'DJI' => ['timezone' => 'America/New_York', 'close' => '16:00:00'],
        'GDAXI' => ['timezone' => 'Europe/Berlin', 'close' => '17:30:00'],
        'KS11' => ['timezone' => 'Asia/Seoul', 'close' => '15:30:00'],
        'N225' => ['timezone' => 'Asia/Tokyo', 'close' => '15:30:00'],
        'NDX' => ['timezone' => 'America/New_York', 'close' => '16:00:00'],
        'OEX' => ['timezone' => 'America/New_York', 'close' => '16:00:00'],
        'SSMI' => ['timezone' => 'Europe/Zurich', 'close' => '17:30:00'],
    ];

    public function __construct(
        private EodhdApiClient $apiClient,
        private EodhdErrorSanitizer $errorSanitizer,
        private EodhdMarketData $marketData,
        private CompletedTradingDay $completedTradingDay,
    ) {}

    public function runningRun(): ?IndexEodhdSyncRun
    {
        return IndexEodhdSyncRun::query()
            ->whereIn('status', ['queued', 'running'])
            ->latest()
            ->first();
    }

    public function createRun(): IndexEodhdSyncRun
    {
        $dateTo = $this->completedTradingDay->date();
        $indices = $this->indices();

        return IndexEodhdSyncRun::query()->create([
            'id' => 'index-eodhd-'.Str::uuid()->toString(),
            'date_from' => $dateTo->copy()->subDays(self::CoverageDays - 1)->toDateString(),
            'date_to' => $dateTo->toDateString(),
            'total_indices' => $indices->count(),
            'steps' => $this->initialSteps(),
            'index_progress' => $this->initialIndexProgress($indices),
            'message' => 'Index EODHD sync queued.',
        ]);
    }

    public function run(string $runId): void
    {
        $run = IndexEodhdSyncRun::query()->find($runId);

        if (! $run) {
            return;
        }

        $run->update([
            'status' => 'running',
            'started_at' => $run->started_at ?? now(),
            'message' => 'Checking stored indices...',
        ]);

        try {
            $this->synchronize($run);
        } catch (Throwable $exception) {
            $this->fail($runId, $exception->getMessage());

            throw $exception;
        }
    }

    public function fail(string $runId, string $message): void
    {
        $run = IndexEodhdSyncRun::query()->find($runId);

        if (! $run) {
            return;
        }

        $safeMessage = $this->safeErrorMessage($message, 1000);
        $failureStatus = $this->messageStatus($message);
        $steps = collect($run->steps)
            ->map(function (array $step) use ($failureStatus, $run, $safeMessage): array {
                if ($step['key'] === $run->stage) {
                    return [...$step, 'status' => $failureStatus, 'message' => Str::limit($safeMessage, 255, '')];
                }

                if ($step['status'] === 'pending') {
                    return [...$step, 'status' => 'skipped', 'message' => 'Skipped because the sync stopped.'];
                }

                return $step;
            })
            ->all();
        $indexProgress = collect($run->index_progress ?? [])
            ->map(function (array $indexProgress) use ($failureStatus, $safeMessage): array {
                foreach (['eod_check', 'eod_sync', 'intraday_check', 'intraday_sync'] as $stage) {
                    if (Arr::get($indexProgress, "{$stage}.status") !== 'running') {
                        if (Arr::get($indexProgress, "{$stage}.status") === 'pending') {
                            $indexProgress[$stage] = [
                                'status' => 'skipped',
                                'message' => 'Skipped because the sync stopped.',
                            ];
                        }

                        continue;
                    }

                    $indexProgress[$stage] = [
                        'status' => $failureStatus,
                        'message' => Str::limit($safeMessage, 255, ''),
                    ];
                }

                return $indexProgress;
            })
            ->all();

        $run->update([
            'status' => 'failed',
            'current' => null,
            'steps' => $steps,
            'index_progress' => $indexProgress,
            'message' => 'Index EODHD sync failed.',
            'error' => $safeMessage,
            'finished_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(IndexEodhdSyncRun $run): array
    {
        $progress = $this->progressPayload($run);

        return [
            'refresh_id' => $run->id,
            'status' => $run->status,
            'stage' => $run->stage,
            'processed' => $run->processed_indices,
            'total' => $run->total_indices,
            'current' => $run->current,
            'date_from' => $run->date_from?->toDateString(),
            'date_to' => $run->date_to?->toDateString(),
            'steps' => $this->sanitizePayload($run->steps ?? []),
            'index_progress' => $this->sanitizePayload($run->index_progress ?? []),
            'progress' => $progress,
            'summary' => $this->sanitizePayload($run->summary),
            'message' => $run->message,
            'error' => $run->error ? $this->safeErrorMessage($run->error, 1000) : null,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }

    private function synchronize(IndexEodhdSyncRun $run): void
    {
        $indices = $this->indices();
        $run->update([
            'total_indices' => $indices->count(),
            'index_progress' => $this->initialIndexProgress($indices),
        ]);
        $this->finishStep($run, 'check_indices', "{$indices->count()} index(es) found.");

        $summary = [
            'date_from' => $run->date_from->toDateString(),
            'date_to' => $run->date_to->toDateString(),
            'total_indices' => $indices->count(),
            'eod' => [
                'available' => 0,
                'already_present' => 0,
                'missing' => 0,
                'synced' => 0,
                'returned_rows' => 0,
                'existing_rows' => 0,
                'new_rows' => 0,
            ],
            'intraday' => [
                'available' => 0,
                'already_present' => 0,
                'missing' => 0,
                'synced' => 0,
                'returned_candles' => 0,
                'existing_candles' => 0,
                'stored_candles' => 0,
                'new_candles' => 0,
                'no_data_indices' => 0,
                'partial_indices' => 0,
                'unsupported_indices' => 0,
                'not_found_indices' => 0,
                'access_denied_indices' => 0,
                'deferred_dates' => 0,
                'market_closed_dates' => 0,
                'retry_later_dates' => 0,
                'checked_missing' => 0,
            ],
            'indices' => [],
        ];
        $errors = [];
        $eodPlans = $this->checkEodData($run, $indices, $summary, $errors);
        $this->syncEodData($run, $eodPlans, $summary);
        $this->checkAndSyncIntradayData($run, $indices, $summary, $errors);
        $this->finishRun($run, $summary, $errors);
    }

    /**
     * @param  Collection<int, IndexWatchItem>  $indices
     * @param  array<string, mixed>  $summary
     * @param  array<int, array{symbol: string, stage: string, status: string, message: string}>  $errors
     * @return array<int, array{index: IndexWatchItem, rows: array<int, array<string, mixed>>}>
     */
    private function checkEodData(IndexEodhdSyncRun $run, Collection $indices, array &$summary, array &$errors): array
    {
        $this->startStep($run, 'check_eod', 'Checking EOD coverage...');
        $plans = [];

        foreach ($indices as $position => $index) {
            $this->setCurrent($run, $index, $position, 'Checking EOD data');
            $this->updateIndexProgress($run, $index, 'eod_check', 'running', 'EOD data is being checked.');
            $indexSummary = $this->indexSummary($summary, $index);

            try {
                $rows = $this->fetchEodRows($index, $run->date_from, $run->date_to);
                $existingDates = $index->prices()
                    ->whereBetween('trading_date', [$run->date_from, $run->date_to])
                    ->where(function ($query): void {
                        $query
                            ->whereNotNull('actual_price')
                            ->orWhereNotNull('start_price');
                    })
                    ->pluck('trading_date')
                    ->map(fn (mixed $date): string => Carbon::parse($date)->toDateString())
                    ->flip();
                $missingRows = array_values(array_filter(
                    $rows,
                    fn (array $row): bool => ! $existingDates->has($row['trading_date']),
                ));
                $availableCount = count($rows);
                $missingCount = count($missingRows);
                $summary['eod']['available'] += $availableCount;
                $summary['eod']['already_present'] += $availableCount - $missingCount;
                $summary['eod']['missing'] += $missingCount;
                $summary['eod']['returned_rows'] += $availableCount;
                $summary['eod']['existing_rows'] += $availableCount - $missingCount;
                $indexSummary['eod'] = [
                    'available' => $availableCount,
                    'missing' => $missingCount,
                    'synced' => 0,
                    'returned_rows' => $availableCount,
                    'existing_rows' => $availableCount - $missingCount,
                    'new_rows' => 0,
                    'status' => 'checked',
                ];
                $this->updateIndexProgress(
                    $run,
                    $index,
                    'eod_check',
                    'finished',
                    "{$availableCount} available, {$missingCount} missing.",
                );
                $plans[$index->id] = ['index' => $index, 'rows' => $missingRows];
            } catch (Throwable $exception) {
                $this->throwIfAuthenticationFailed($exception);

                $indexSummary['eod'] = ['available' => 0, 'missing' => 0, 'synced' => 0, 'status' => 'failed'];
                $errors[] = $this->errorPayload($index, 'eod', $exception);
                $this->failIndexProgress($run, $index, ['eod_check', 'eod_sync'], $exception->getMessage());
            }

            $summary['indices'][$index->id] = $indexSummary;
        }

        $run->update(['eod_missing_count' => $summary['eod']['missing']]);
        $this->finishStep($run, 'check_eod', "{$summary['eod']['missing']} missing EOD record(s) found.");

        return $plans;
    }

    /**
     * @param  array<int, array{index: IndexWatchItem, rows: array<int, array<string, mixed>>}>  $plans
     * @param  array<string, mixed>  $summary
     */
    private function syncEodData(IndexEodhdSyncRun $run, array $plans, array &$summary): void
    {
        $this->startStep($run, 'sync_eod', 'Syncing missing EOD records...');

        foreach ($plans as $plan) {
            $index = $plan['index'];
            $run->update(['current' => "Syncing EOD data for {$index->symbol}"]);
            $this->updateIndexProgress($run, $index, 'eod_sync', 'running', 'Missing EOD data is being synced.');
            $storedCount = 0;

            foreach ($plan['rows'] as $row) {
                $price = IndexWatchItemPrice::query()
                    ->where('index_watch_item_id', $row['index_watch_item_id'])
                    ->whereDate('trading_date', $row['trading_date'])
                    ->first();
                $attributes = Arr::except($row, ['index_watch_item_id', 'trading_date', 'created_at']);

                if ($price) {
                    $price->update($attributes);
                } else {
                    IndexWatchItemPrice::query()->create([
                        'index_watch_item_id' => $row['index_watch_item_id'],
                        'trading_date' => $row['trading_date'],
                        ...$attributes,
                    ]);
                }

                $storedCount++;
            }

            $summary['eod']['synced'] += $storedCount;
            $summary['eod']['new_rows'] += $storedCount;
            $summary['indices'][$index->id]['eod']['synced'] = $storedCount;
            $summary['indices'][$index->id]['eod']['new_rows'] = $storedCount;
            $summary['indices'][$index->id]['eod']['status'] = 'finished';
            $run->update(['eod_synced_count' => $summary['eod']['synced']]);
            $this->updateIndexProgress(
                $run,
                $index,
                'eod_sync',
                'finished',
                "{$storedCount} record(s) synced.",
            );
        }

        $this->finishStep($run, 'sync_eod', "{$summary['eod']['synced']} EOD record(s) synced.");
    }

    /**
     * @param  Collection<int, IndexWatchItem>  $indices
     * @param  array<string, mixed>  $summary
     * @param  array<int, array{symbol: string, stage: string, status: string, message: string}>  $errors
     */
    private function checkAndSyncIntradayData(
        IndexEodhdSyncRun $run,
        Collection $indices,
        array &$summary,
        array &$errors,
    ): void {
        $this->startStep($run, 'check_intraday', 'Checking 5-minute intraday coverage...');
        $plans = [];
        $missingTradingDateCount = 0;

        foreach ($indices as $position => $index) {
            $this->setCurrent($run, $index, $position, 'Checking intraday data');
            $this->updateIndexProgress($run, $index, 'intraday_check', 'running', 'Intraday data is being checked.');

            try {
                $coverage = $this->missingIntradayCoverage($index, $run->date_from, $run->date_to);
                $missingTradingDateCount += $coverage['missing_dates'];
                $summary['intraday']['deferred_dates'] += $coverage['deferred_dates'];
                $summary['intraday']['market_closed_dates'] += $coverage['market_closed_dates'];
                $summary['intraday']['retry_later_dates'] += $coverage['retry_later_dates'];
                $summary['intraday']['existing_candles'] += $coverage['stored_candles'];
                $summary['intraday']['stored_candles'] += $coverage['stored_candles'];

                if ($coverage['expected_dates'] === 0
                    && $coverage['deferred_dates'] === 0
                    && $coverage['market_closed_dates'] === 0) {
                    $summary['indices'][$index->id]['intraday'] = [
                        'available' => 0,
                        'missing' => 0,
                        'synced' => 0,
                        'returned_candles' => 0,
                        'existing_candles' => $coverage['stored_candles'],
                        'stored_candles' => $coverage['stored_candles'],
                        'new_candles' => 0,
                        'status' => 'skipped',
                        'expected_dates' => 0,
                        'verified_dates' => 0,
                        'deferred_dates' => 0,
                        'market_closed_dates' => 0,
                        'blocks' => [],
                    ];
                    $this->updateIndexProgress($run, $index, 'intraday_check', 'skipped', 'No valid EOD trading dates are available for comparison.');
                    $this->updateIndexProgress($run, $index, 'intraday_sync', 'skipped', 'Not run because no EOD reference dates are available.');

                    continue;
                }

                if ($coverage['ranges'] === []) {
                    $status = 'finished';
                    $message = "{$coverage['complete_dates']} finalized date(s) contain stored EODHD 5-minute candles.";

                    if ($coverage['retry_later_dates'] > 0) {
                        $status = $coverage['complete_dates'] > 0 ? 'partial' : 'no_data';
                        $message .= " {$coverage['retry_later_dates']} date(s) without usable EODHD prices will be retried after {$coverage['next_retry_at']}; no duplicate request was sent.";

                        if ($status === 'partial') {
                            $summary['intraday']['partial_indices']++;
                        } else {
                            $summary['intraday']['no_data_indices']++;
                        }
                    } elseif ($coverage['deferred_dates'] > 0) {
                        $status = 'deferred';
                        $message .= " {$coverage['deferred_dates']} current date(s) are waiting for EODHD finalization.";
                    } elseif ($coverage['market_closed_dates'] > 0) {
                        $message .= " {$coverage['market_closed_dates']} zero-volume non-trading date(s) were excluded.";
                    }

                    $summary['indices'][$index->id]['intraday'] = [
                        'available' => 0,
                        'missing' => $coverage['missing_dates'],
                        'synced' => 0,
                        'returned_candles' => 0,
                        'existing_candles' => $coverage['stored_candles'],
                        'stored_candles' => $coverage['stored_candles'],
                        'new_candles' => 0,
                        'status' => $status,
                        'expected_dates' => $coverage['expected_dates'],
                        'verified_dates' => $coverage['complete_dates'],
                        'deferred_dates' => $coverage['deferred_dates'],
                        'market_closed_dates' => $coverage['market_closed_dates'],
                        'retry_later_dates' => $coverage['retry_later_dates'],
                        'retry_later_date_values' => $coverage['retry_later_date_values'],
                        'next_retry_at' => $coverage['next_retry_at'],
                        'blocks' => [],
                    ];
                    $this->updateIndexProgress($run, $index, 'intraday_check', 'finished', $message);
                    $this->updateIndexProgress($run, $index, 'intraday_sync', $status, $message);

                    continue;
                }

                $this->updateIndexProgress(
                    $run,
                    $index,
                    'intraday_check',
                    'finished',
                    "{$coverage['complete_dates']} verified, {$coverage['missing_dates']} unverified and {$coverage['deferred_dates']} deferred date(s).",
                );
                $plans[$index->id] = [
                    'index' => $index,
                    'ranges' => $coverage['ranges'],
                    'coverage' => $coverage,
                ];
            } catch (Throwable $exception) {
                $summary['indices'][$index->id]['intraday'] = [
                    'available' => 0,
                    'missing' => 0,
                    'synced' => 0,
                    'status' => $this->exceptionStatus($exception),
                ];
                $errors[] = $this->errorPayload($index, 'intraday', $exception);
                $this->failIndexProgress(
                    $run,
                    $index,
                    ['intraday_check', 'intraday_sync'],
                    $exception->getMessage(),
                );
            }
        }

        $summary['intraday']['missing'] = $missingTradingDateCount;
        $summary['intraday']['checked_missing'] = $missingTradingDateCount;
        $run->update(['intraday_missing_count' => $missingTradingDateCount]);
        $this->finishStep($run, 'check_intraday', "{$missingTradingDateCount} trading date(s) need intraday data.");
        $this->startStep($run, 'sync_intraday', 'Syncing missing intraday periods in small blocks...');

        foreach ($plans as $plan) {
            $this->syncIntradayPlan($run, $plan, $summary, $errors);
        }

        $summary['intraday']['missing'] = collect($summary['indices'])
            ->sum(fn (array $indexSummary): int => max(
                (int) data_get($indexSummary, 'intraday.expected_dates')
                    - (int) data_get($indexSummary, 'intraday.verified_dates'),
                0,
            ));
        $run->update([
            'intraday_missing_count' => $summary['intraday']['missing'],
            'unsupported_intraday_count' => $summary['intraday']['unsupported_indices'],
        ]);
        $this->finishStep(
            $run,
            'sync_intraday',
            "{$summary['intraday']['synced']} intraday candle(s) synced; {$summary['intraday']['missing']} finalized trading date(s) remain unverified.",
        );
    }

    /**
     * @param  array{index: IndexWatchItem, ranges: array<int, array{from: Carbon, to: Carbon, missing_dates: int, dates: array<int, string>}>, coverage: array<string, mixed>}  $plan
     * @param  array<string, mixed>  $summary
     * @param  array<int, array{symbol: string, stage: string, status: string, message: string}>  $errors
     */
    private function syncIntradayPlan(
        IndexEodhdSyncRun $run,
        array $plan,
        array &$summary,
        array &$errors,
    ): void {
        $index = $plan['index'];
        $ranges = $plan['ranges'];
        $rangeCount = count($ranges);
        $returnedCandleCount = 0;
        $storedCount = 0;
        $noDataDates = collect($plan['coverage']['retry_later_date_values']);
        $blocks = [];
        $verifiedDateCount = $plan['coverage']['complete_dates'];
        $existingTimestamps = $this->existingIntradayTimestamps($index, $run->date_from, $run->date_to);
        $existingCandleCount = $existingTimestamps->count();
        $hadStoredCandles = $existingTimestamps->isNotEmpty();

        $this->updateIndexProgress(
            $run,
            $index,
            'intraday_sync',
            'running',
            "Syncing {$rangeCount} missing period(s) in blocks of up to ".self::IntradayChunkDays.' days.',
        );

        foreach ($ranges as $position => $range) {
            $currentRange = $position + 1;
            $dateRange = "{$range['from']->toDateString()} to {$range['to']->toDateString()}";
            $run->update(['current' => "Syncing intraday {$index->symbol}: block {$currentRange}/{$rangeCount} ({$dateRange})"]);
            $this->updateIndexProgress(
                $run,
                $index,
                'intraday_sync',
                'running',
                "Block {$currentRange}/{$rangeCount}: {$dateRange}.",
            );
            $this->updateIntradayBlockProgress($run, $index, [
                'position' => $currentRange,
                'total' => $rangeCount,
                'date_from' => $range['from']->toDateString(),
                'date_to' => $range['to']->toDateString(),
                'status' => 'running',
                'attempts' => 0,
                'records' => 0,
                'synced' => 0,
                'missing_dates' => [],
                'message' => 'Requesting this missing period from EODHD.',
            ]);

            try {
                $result = $this->fetchIntradayBlock($index, $range);
            } catch (Throwable $exception) {
                $this->throwIfAuthenticationFailed($exception);

                $status = $this->exceptionStatus($exception);
                $attempts = $this->shouldRetryIntradayException($exception)
                    ? count(self::IntradayRetryDelays) + 1
                    : 1;
                $message = $this->intradayFailureMessage($status, $dateRange, $exception);
                $block = [
                    'position' => $currentRange,
                    'total' => $rangeCount,
                    'date_from' => $range['from']->toDateString(),
                    'date_to' => $range['to']->toDateString(),
                    'status' => $status,
                    'attempts' => $attempts,
                    'records' => 0,
                    'synced' => 0,
                    'missing_dates' => $range['dates'],
                    'message' => $message,
                ];
                $blocks[] = $block;
                $this->updateIntradayBlockProgress($run, $index, $block);
                $this->markIntradayDates(
                    $index,
                    $range['dates'],
                    $status,
                    $attempts,
                    is_int($exception->getCode()) && $exception->getCode() > 0 ? $exception->getCode() : null,
                    $message,
                );
                $summary['indices'][$index->id]['intraday'] = [
                    'available' => $returnedCandleCount,
                    'missing' => max($plan['coverage']['expected_dates'] - $verifiedDateCount, 0),
                    'synced' => $storedCount,
                    'returned_candles' => $returnedCandleCount,
                    'existing_candles' => $existingCandleCount,
                    'stored_candles' => $existingCandleCount + $storedCount,
                    'new_candles' => $storedCount,
                    'status' => $status,
                    'expected_dates' => $plan['coverage']['expected_dates'],
                    'verified_dates' => $verifiedDateCount,
                    'deferred_dates' => $plan['coverage']['deferred_dates'],
                    'market_closed_dates' => $plan['coverage']['market_closed_dates'],
                    'retry_later_dates' => $plan['coverage']['retry_later_dates'],
                    'next_retry_at' => $plan['coverage']['next_retry_at'],
                    'blocks' => $blocks,
                ];
                $this->incrementIntradayFailureCounter($summary, $status);
                $errors[] = $this->errorPayload($index, 'intraday', $exception, $message);
                $this->updateIndexProgress($run, $index, 'intraday_sync', $status, $message);
                $this->updateIntradayRunCounts($run, $summary);

                return;
            }

            $rows = $result['rows'];
            $returnedCandleCount += count($rows);
            $missingRows = array_values(array_filter(
                $rows,
                fn (array $row): bool => ! $existingTimestamps->has($row['as_of_key']),
            ));
            $currentNewCandleCount = count($missingRows);
            $summary['intraday']['available'] += count($rows);
            $summary['intraday']['already_present'] += count($rows) - $currentNewCandleCount;
            $summary['intraday']['returned_candles'] += count($rows);
            $currentStoredCount = $this->storeIntradayRows($missingRows);
            $storedCount += $currentStoredCount;
            $summary['intraday']['synced'] += $currentStoredCount;
            $summary['intraday']['new_candles'] += $currentStoredCount;
            $summary['intraday']['stored_candles'] += $currentStoredCount;

            foreach ($missingRows as $missingRow) {
                $existingTimestamps->put($missingRow['as_of_key'], true);
            }

            $verifiedDates = collect($range['dates'])->diff($result['missing_dates'])->values()->all();
            $verifiedDateCount += count($verifiedDates);
            $this->markIntradayDates(
                $index,
                $verifiedDates,
                'complete',
                $result['attempts'],
                200,
                count($rows).' EODHD candle(s) returned for this block.',
            );
            $this->markIntradayDates(
                $index,
                $result['missing_dates'],
                'no_data',
                $result['attempts'],
                200,
                'EODHD returned no usable 5-minute candles; response rows without OHLC prices are ignored.',
            );
            $noDataDates = $noDataDates->merge($result['missing_dates'])->unique()->values();
            $blockStatus = match (true) {
                $result['missing_dates'] === [] => 'finished',
                $rows === [] => 'no_data',
                default => 'partial',
            };
            $blockMessage = match ($blockStatus) {
                'finished' => count($rows)." candle(s) received in {$result['attempts']} attempt(s).",
                'no_data' => "EODHD returned HTTP 200 but no usable candles for {$result['attempts']} attempt(s).",
                default => count($result['missing_dates'])." date(s) still have no EODHD data after {$result['attempts']} attempts.",
            };
            $block = [
                'position' => $currentRange,
                'total' => $rangeCount,
                'date_from' => $range['from']->toDateString(),
                'date_to' => $range['to']->toDateString(),
                'status' => $blockStatus,
                'attempts' => $result['attempts'],
                'records' => count($rows),
                'synced' => $currentStoredCount,
                'missing_dates' => $result['missing_dates'],
                'message' => $blockMessage,
            ];
            $blocks[] = $block;
            $this->updateIntradayBlockProgress($run, $index, $block);
            $this->updateIntradayRunCounts($run, $summary);
        }

        $finalCoverage = $this->missingIntradayCoverage($index, $run->date_from, $run->date_to);
        $newMarketClosedDateCount = max(
            $finalCoverage['market_closed_dates'] - $plan['coverage']['market_closed_dates'],
            0,
        );
        $newRetryLaterDateCount = max(
            $finalCoverage['retry_later_dates'] - $plan['coverage']['retry_later_dates'],
            0,
        );
        $summary['intraday']['market_closed_dates'] += $newMarketClosedDateCount;
        $summary['intraday']['retry_later_dates'] += $newRetryLaterDateCount;
        $newMarketClosedDates = collect($finalCoverage['market_closed_date_values'])
            ->diff($plan['coverage']['market_closed_date_values'])
            ->values();

        if ($newMarketClosedDates->isNotEmpty()) {
            $blocks = collect($blocks)
                ->map(function (array $block) use ($newMarketClosedDates): array {
                    $remainingMissingDates = collect($block['missing_dates'])
                        ->diff($newMarketClosedDates)
                        ->values()
                        ->all();

                    if ($remainingMissingDates === $block['missing_dates']) {
                        return $block;
                    }

                    if ($remainingMissingDates === []) {
                        return [
                            ...$block,
                            'status' => 'finished',
                            'missing_dates' => [],
                            'message' => 'Empty dates were confirmed as non-trading dates.',
                        ];
                    }

                    return [
                        ...$block,
                        'missing_dates' => $remainingMissingDates,
                        'message' => count($remainingMissingDates).' date(s) still have no EODHD data.',
                    ];
                })
                ->all();

            foreach ($blocks as $block) {
                $this->updateIntradayBlockProgress($run, $index, $block);
            }
        }

        $noDataDates = $noDataDates
            ->intersect($finalCoverage['missing_date_values'])
            ->values();
        $verifiedDateCount = $finalCoverage['complete_dates'];

        if ($noDataDates->isNotEmpty()) {
            $hasStoredCandles = $hadStoredCandles || $storedCount > 0;
            $status = $hasStoredCandles ? 'partial' : 'no_data';

            if ($status === 'partial') {
                $summary['intraday']['partial_indices']++;
            } else {
                $summary['intraday']['no_data_indices']++;
            }

            $dateList = $this->dateList($noDataDates);
            $summary['indices'][$index->id]['intraday'] = [
                'available' => $returnedCandleCount,
                'missing' => max($finalCoverage['expected_dates'] - $verifiedDateCount, 0),
                'synced' => $storedCount,
                'returned_candles' => $returnedCandleCount,
                'existing_candles' => $existingCandleCount,
                'stored_candles' => $existingCandleCount + $storedCount,
                'new_candles' => $storedCount,
                'status' => $status,
                'expected_dates' => $finalCoverage['expected_dates'],
                'verified_dates' => $verifiedDateCount,
                'deferred_dates' => $finalCoverage['deferred_dates'],
                'market_closed_dates' => $finalCoverage['market_closed_dates'],
                'retry_later_dates' => $finalCoverage['retry_later_dates'],
                'retry_later_date_values' => $finalCoverage['retry_later_date_values'],
                'next_retry_at' => $finalCoverage['next_retry_at'],
                'no_data_dates' => $noDataDates->all(),
                'blocks' => $blocks,
            ];
            $message = $status === 'partial'
                ? "Existing data was preserved, but EODHD has no data for {$noDataDates->count()} date(s): {$dateList}."
                : "EODHD returned HTTP 200 but no usable intraday prices for {$noDataDates->count()} checked date(s): {$dateList}.";
            $this->updateIndexProgress($run, $index, 'intraday_sync', $status, $message);

            return;
        }

        $status = $finalCoverage['deferred_dates'] > 0 ? 'deferred' : 'finished';
        $message = $finalCoverage['deferred_dates'] > 0
            ? "{$storedCount} candle(s) synced; {$finalCoverage['deferred_dates']} current date(s) deferred until EODHD finalization."
            : "{$storedCount} candle(s) synced from {$rangeCount} small period(s).";
        $summary['indices'][$index->id]['intraday'] = [
            'available' => $returnedCandleCount,
            'missing' => 0,
            'synced' => $storedCount,
            'returned_candles' => $returnedCandleCount,
            'existing_candles' => $existingCandleCount,
            'stored_candles' => $existingCandleCount + $storedCount,
            'new_candles' => $storedCount,
            'status' => $status,
            'expected_dates' => $finalCoverage['expected_dates'],
            'verified_dates' => $finalCoverage['complete_dates'],
            'deferred_dates' => $finalCoverage['deferred_dates'],
            'market_closed_dates' => $finalCoverage['market_closed_dates'],
            'retry_later_dates' => 0,
            'blocks' => $blocks,
        ];
        $this->updateIndexProgress(
            $run,
            $index,
            'intraday_sync',
            $status,
            $message,
        );
    }

    /**
     * @return array{
     *     expected_dates: int,
     *     complete_dates: int,
     *     missing_dates: int,
     *     missing_date_values: array<int, string>,
     *     deferred_dates: int,
     *     deferred_date_values: array<int, string>,
     *     market_closed_dates: int,
     *     market_closed_date_values: array<int, string>,
     *     retry_later_dates: int,
     *     retry_later_date_values: array<int, string>,
     *     next_retry_at: ?string,
     *     stored_candles: int,
     *     ranges: array<int, array{from: Carbon, to: Carbon, missing_dates: int, dates: array<int, string>}>
     * }
     */
    private function missingIntradayCoverage(IndexWatchItem $index, Carbon $dateFrom, Carbon $dateTo): array
    {
        $prices = $index->prices()
            ->whereBetween('trading_date', [$dateFrom, $dateTo])
            ->where(function ($query): void {
                $query
                    ->whereNotNull('actual_price')
                    ->orWhereNotNull('start_price');
            })
            ->orderBy('trading_date')
            ->get([
                'id',
                'trading_date',
                'raw_payload',
                'intraday_sync_status',
                'intraday_candle_count',
                'intraday_checked_at',
            ]);
        $dailyCandleCounts = $index->intradayCandles()
            ->where('interval', self::Interval)
            ->whereBetween('trading_date', [$dateFrom, $dateTo])
            ->get(['trading_date'])
            ->countBy(fn (IndexWatchItemIntradayCandle $candle): string => $candle->trading_date->toDateString());
        $marketCalendarDates = $this->marketCalendarDates($index);
        $marketHolidayDates = $marketCalendarDates['holidays'];
        $knownTradingDates = $marketCalendarDates['trading_dates'];
        $deferredPrices = $prices
            ->filter(fn (IndexWatchItemPrice $price): bool => ! $this->intradayDateIsFinalized($index, $price->trading_date));
        $finalizedPrices = $prices->diff($deferredPrices)->values();
        $marketClosedPrices = $finalizedPrices
            ->filter(fn (IndexWatchItemPrice $price): bool => $this->isConfirmedMarketClosure(
                $price,
                $finalizedPrices,
                $dailyCandleCounts,
                $marketHolidayDates,
                $knownTradingDates,
            ));
        $marketClosedPrices
            ->filter(fn (IndexWatchItemPrice $price): bool => $price->intraday_sync_status !== 'market_closed')
            ->each(function (IndexWatchItemPrice $price) use ($marketHolidayDates): void {
                $date = $price->trading_date->toDateString();
                $message = $marketHolidayDates->has($date)
                    ? 'Official exchange holiday; excluded from expected intraday trading dates.'
                    : 'Zero-volume EOD reference between verified sessions; treated as a non-trading date.';

                $price->update([
                    'intraday_sync_status' => 'market_closed',
                    'intraday_candle_count' => 0,
                    'intraday_http_status' => 200,
                    'intraday_sync_message' => $message,
                    'intraday_checked_at' => now(),
                ]);
            });
        $eligiblePrices = $finalizedPrices->diff($marketClosedPrices);
        $completeDates = $eligiblePrices
            ->filter(function (IndexWatchItemPrice $price) use ($dailyCandleCounts): bool {
                $date = $price->trading_date->toDateString();
                $storedCandleCount = (int) $dailyCandleCounts->get($date, 0);
                $verifiedCandleCount = max((int) $price->intraday_candle_count, 1);

                return $price->intraday_sync_status === 'complete'
                    && $storedCandleCount >= $verifiedCandleCount;
            })
            ->map(fn (IndexWatchItemPrice $price): string => $price->trading_date->toDateString())
            ->values();
        $expectedDates = $eligiblePrices
            ->map(fn (IndexWatchItemPrice $price): string => $price->trading_date->toDateString())
            ->values();
        $missingDates = $expectedDates->diff($completeDates)->values();
        $retryHours = max((int) config('services.eodhd.no_data_retry_hours', 12), 1);
        $retryCutoff = now()->subHours($retryHours);
        $retryLaterPrices = $eligiblePrices
            ->filter(fn (IndexWatchItemPrice $price): bool => in_array(
                $price->intraday_sync_status,
                ['no_data', 'not_found'],
                true,
            ))
            ->filter(fn (IndexWatchItemPrice $price): bool => $price->intraday_checked_at?->greaterThan($retryCutoff) === true);
        $retryLaterDates = $retryLaterPrices
            ->map(fn (IndexWatchItemPrice $price): string => $price->trading_date->toDateString())
            ->values();
        $nextRetryAt = $retryLaterPrices
            ->map(fn (IndexWatchItemPrice $price): Carbon => $price->intraday_checked_at->copy()->addHours($retryHours))
            ->min();
        $timezone = $this->exchangeTimezone($index);
        $coveredOrCoolingDownDates = collect($completeDates->all())
            ->merge($retryLaterDates->all())
            ->unique()
            ->values();
        $ranges = collect($this->missingIntradayDateGroups($expectedDates, $coveredOrCoolingDownDates))
            ->map(fn (array $dates): array => [
                'from' => Carbon::parse($dates[0], $timezone)->startOfDay(),
                'to' => Carbon::parse($dates[array_key_last($dates)], $timezone)->endOfDay(),
                'missing_dates' => count($dates),
                'dates' => $dates,
            ])
            ->sortByDesc(fn (array $range): int => $range['from']->timestamp)
            ->values()
            ->all();

        return [
            'expected_dates' => $expectedDates->count(),
            'complete_dates' => $completeDates->count(),
            'missing_dates' => $missingDates->count(),
            'missing_date_values' => $missingDates->all(),
            'deferred_dates' => $deferredPrices->count(),
            'deferred_date_values' => $deferredPrices
                ->map(fn (IndexWatchItemPrice $price): string => $price->trading_date->toDateString())
                ->values()
                ->all(),
            'market_closed_dates' => $marketClosedPrices->count(),
            'market_closed_date_values' => $marketClosedPrices
                ->map(fn (IndexWatchItemPrice $price): string => $price->trading_date->toDateString())
                ->values()
                ->all(),
            'retry_later_dates' => $retryLaterDates->count(),
            'retry_later_date_values' => $retryLaterDates->all(),
            'next_retry_at' => $nextRetryAt?->toIso8601String(),
            'stored_candles' => (int) $dailyCandleCounts->sum(),
            'ranges' => $ranges,
        ];
    }

    /**
     * @param  Collection<int, IndexWatchItemPrice>  $finalizedPrices
     * @param  Collection<string, int>  $dailyCandleCounts
     * @param  Collection<string, true>  $marketHolidayDates
     * @param  Collection<string, true>  $knownTradingDates
     */
    private function isConfirmedMarketClosure(
        IndexWatchItemPrice $price,
        Collection $finalizedPrices,
        Collection $dailyCandleCounts,
        Collection $marketHolidayDates,
        Collection $knownTradingDates,
    ): bool {
        $date = $price->trading_date->toDateString();

        if ((int) $dailyCandleCounts->get($date, 0) > 0) {
            return false;
        }

        if ($marketHolidayDates->has($date)) {
            return true;
        }

        if ($knownTradingDates->has($date)) {
            return false;
        }

        if ($price->intraday_sync_status === 'market_closed') {
            return true;
        }

        $rawPayload = $price->raw_payload;

        if (is_string($rawPayload)) {
            $rawPayload = json_decode($rawPayload, true);
        }

        $volume = data_get($rawPayload, 'volume');

        if ($price->intraday_sync_status !== 'no_data'
            || ! is_numeric($volume)
            || (float) $volume !== 0.0) {
            return false;
        }

        $position = $finalizedPrices->search(fn (IndexWatchItemPrice $candidate): bool => $candidate->is($price));

        if ($position === false) {
            return false;
        }

        $previousPrice = $finalizedPrices->slice(0, $position)->last();
        $nextPrice = $finalizedPrices->slice($position + 1)->first();

        if (! $previousPrice || ! $nextPrice) {
            return false;
        }

        return $previousPrice->intraday_sync_status === 'complete'
            && $nextPrice->intraday_sync_status === 'complete'
            && (int) $dailyCandleCounts->get($previousPrice->trading_date->toDateString(), 0) > 0
            && (int) $dailyCandleCounts->get($nextPrice->trading_date->toDateString(), 0) > 0;
    }

    /**
     * @return array{
     *     holidays: Collection<string, true>,
     *     trading_dates: Collection<string, true>
     * }
     */
    private function marketCalendarDates(IndexWatchItem $index): array
    {
        $exchange = $this->holidayExchangeForIndex($index);
        $holidays = $exchange?->holidays;

        if (! is_array($holidays)) {
            return [
                'holidays' => collect(),
                'trading_dates' => collect(),
            ];
        }

        $marketHolidayDates = [];
        $knownTradingDates = [];
        $holidaysAreList = array_is_list($holidays);

        foreach ($holidays as $date => $holiday) {
            $holidayDate = $holidaysAreList
                ? $this->holidayDate(is_array($holiday) ? ($holiday['Date'] ?? $holiday['date'] ?? null) : $holiday)
                : $this->holidayDate($date);

            if ($holidayDate === null) {
                continue;
            }

            if ($this->isFullMarketHoliday($holiday)) {
                $marketHolidayDates[$holidayDate] = true;

                continue;
            }

            $knownTradingDates[$holidayDate] = true;
        }

        return [
            'holidays' => collect($marketHolidayDates),
            'trading_dates' => collect($knownTradingDates),
        ];
    }

    private function holidayExchangeForIndex(IndexWatchItem $index): ?EodhdExchange
    {
        $marketIdentifiers = collect([$index->exchange, $index->mic_code])
            ->filter(fn (mixed $identifier): bool => is_string($identifier) && trim($identifier) !== '')
            ->map(fn (string $identifier): string => Str::upper(trim($identifier)))
            ->unique()
            ->values();

        if ($marketIdentifiers->isNotEmpty()) {
            $exchange = EodhdExchange::query()
                ->whereNotNull('holidays')
                ->where(function ($query) use ($marketIdentifiers): void {
                    $query
                        ->whereIn('code', $marketIdentifiers)
                        ->orWhereIn('detail_code', $marketIdentifiers)
                        ->orWhereIn('operating_mic', $marketIdentifiers);
                })
                ->latest('synced_at')
                ->first();

            if ($exchange) {
                return $exchange;
            }
        }

        $country = is_string($index->country) ? trim($index->country) : '';

        if ($country === '') {
            return null;
        }

        return EodhdExchange::query()
            ->where('country', $country)
            ->whereNotNull('holidays')
            ->latest('synced_at')
            ->first();
    }

    private function isFullMarketHoliday(mixed $holiday): bool
    {
        if (! is_array($holiday)) {
            return true;
        }

        $earlyClose = $holiday['EarlyClose'] ?? $holiday['early_close'] ?? null;

        if (is_string($earlyClose) && trim($earlyClose) !== '') {
            return false;
        }

        $type = Str::lower((string) ($holiday['Type'] ?? $holiday['type'] ?? ''));

        return ! Str::contains($type, ['early close', 'earlyclose', 'half day', 'half-day', 'partial']);
    }

    private function holidayDate(mixed $date): ?string
    {
        if (! is_string($date)) {
            return null;
        }

        $date = trim($date);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : null;
    }

    /**
     * @param  Collection<int, string>  $expectedDates
     * @param  Collection<int, string>  $coveredDates
     * @return array<int, array<int, string>>
     */
    private function missingIntradayDateGroups(Collection $expectedDates, Collection $coveredDates): array
    {
        $coveredDateLookup = $coveredDates->flip();
        $groups = [];
        $currentGroup = [];

        foreach ($expectedDates as $date) {
            if ($coveredDateLookup->has($date)) {
                if ($currentGroup !== []) {
                    $groups[] = $currentGroup;
                    $currentGroup = [];
                }

                continue;
            }

            if ($currentGroup !== []) {
                $firstDate = Carbon::parse($currentGroup[0]);
                $chunkIsFull = $firstDate->diffInDays(Carbon::parse($date)) >= self::IntradayChunkDays;

                if ($chunkIsFull) {
                    $groups[] = $currentGroup;
                    $currentGroup = [];
                }
            }

            $currentGroup[] = $date;
        }

        if ($currentGroup !== []) {
            $groups[] = $currentGroup;
        }

        return $groups;
    }

    /**
     * @param  array{from: Carbon, to: Carbon, missing_dates: int, dates: array<int, string>}  $range
     * @return array{rows: array<int, array<string, mixed>>, missing_dates: array<int, string>, attempts: int}
     */
    private function fetchIntradayBlock(IndexWatchItem $index, array $range): array
    {
        $requestedDates = collect($range['dates']);
        $requestedDateLookup = $requestedDates->flip();
        $rowsByTimestamp = collect();
        $attempts = 0;
        $requestFrom = $range['from'];
        $requestTo = $range['to'];

        while ($attempts < count(self::IntradayRetryDelays) + 1) {
            $attempts++;

            try {
                $rows = $this->fetchIntradayRows($index, $requestFrom, $requestTo);
            } catch (Throwable $exception) {
                if ($attempts >= count(self::IntradayRetryDelays) + 1 || ! $this->shouldRetryIntradayException($exception)) {
                    throw $exception;
                }

                $this->pauseBeforeIntradayRetry($attempts);

                continue;
            }

            collect($rows)
                ->filter(fn (array $row): bool => $requestedDateLookup->has($row['trading_date']))
                ->each(fn (array $row) => $rowsByTimestamp->put($row['as_of_key'], $row));

            $returnedDates = $rowsByTimestamp
                ->pluck('trading_date')
                ->unique();
            $missingDates = $requestedDates->diff($returnedDates)->values();

            if ($missingDates->isEmpty() || $attempts >= count(self::IntradayRetryDelays) + 1) {
                return [
                    'rows' => $rowsByTimestamp->values()->all(),
                    'missing_dates' => $missingDates->all(),
                    'attempts' => $attempts,
                ];
            }

            $timezone = $this->exchangeTimezone($index);
            $requestFrom = Carbon::parse($missingDates->first(), $timezone)->startOfDay();
            $requestTo = Carbon::parse($missingDates->last(), $timezone)->endOfDay();
            $this->pauseBeforeIntradayRetry($attempts);
        }

        return ['rows' => [], 'missing_dates' => $range['dates'], 'attempts' => $attempts];
    }

    private function shouldRetryIntradayException(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        $statusCode = (int) $exception->getCode();

        return in_array($statusCode, [404, 429], true) || $statusCode >= 500;
    }

    private function pauseBeforeIntradayRetry(int $attempt): void
    {
        $delay = self::IntradayRetryDelays[$attempt - 1]
            ?? self::IntradayRetryDelays[array_key_last(self::IntradayRetryDelays)];

        usleep($delay * 1000);
    }

    /** @param array<int, string> $dates */
    private function markIntradayDates(
        IndexWatchItem $index,
        array $dates,
        string $status,
        int $attempts,
        ?int $httpStatus,
        string $message,
    ): void {
        if ($dates === []) {
            return;
        }

        $dateLookup = collect($dates)->flip();
        $firstDate = collect($dates)->min();
        $lastDate = collect($dates)->max();
        $candleCounts = $index->intradayCandles()
            ->where('interval', self::Interval)
            ->whereBetween('trading_date', [$firstDate, $lastDate])
            ->get(['trading_date'])
            ->filter(fn (IndexWatchItemIntradayCandle $candle): bool => $dateLookup->has($candle->trading_date->toDateString()))
            ->countBy(fn (IndexWatchItemIntradayCandle $candle): string => $candle->trading_date->toDateString());

        $index->prices()
            ->whereBetween('trading_date', [$firstDate, Carbon::parse($lastDate)->endOfDay()])
            ->get()
            ->filter(fn (IndexWatchItemPrice $price): bool => $dateLookup->has($price->trading_date->toDateString()))
            ->each(function (IndexWatchItemPrice $price) use ($attempts, $candleCounts, $httpStatus, $message, $status): void {
                $price->update([
                    'intraday_sync_status' => $status,
                    'intraday_candle_count' => (int) $candleCounts->get($price->trading_date->toDateString(), 0),
                    'intraday_sync_attempts' => $attempts,
                    'intraday_http_status' => $httpStatus,
                    'intraday_sync_message' => $this->safeErrorMessage($message, 255),
                    'intraday_checked_at' => now(),
                ]);
            });
    }

    /** @param array<string, mixed> $block */
    private function updateIntradayBlockProgress(
        IndexEodhdSyncRun $run,
        IndexWatchItem $index,
        array $block,
    ): void {
        $indexProgress = collect($run->fresh()->index_progress ?? [])
            ->map(function (array $item) use ($block, $index): array {
                if ($item['id'] !== $index->id) {
                    return $item;
                }

                $blocks = collect($item['intraday_blocks'] ?? [])
                    ->reject(fn (array $existingBlock): bool => $existingBlock['position'] === $block['position'])
                    ->push($block)
                    ->sortBy('position')
                    ->values()
                    ->all();
                $item['intraday_blocks'] = $blocks;

                return $item;
            })
            ->all();

        $run->update(['index_progress' => $indexProgress]);
    }

    private function intradayDateIsFinalized(IndexWatchItem $index, Carbon $date): bool
    {
        $profile = $this->indexMarketProfile($index);
        $finalizedAt = Carbon::parse($date->toDateString(), $profile['timezone'])
            ->setTimeFromTimeString($profile['close'])
            ->addHours(self::IntradayFinalizationDelayHours);

        return now($profile['timezone'])->greaterThanOrEqualTo($finalizedAt);
    }

    /** @return array{timezone: string, close: string} */
    private function indexMarketProfile(IndexWatchItem $index): array
    {
        $symbol = Str::upper(trim((string) $index->symbol));
        $country = Str::lower(trim((string) $index->country));

        if (array_key_exists($symbol, self::IndexSymbolMarketProfiles)) {
            return self::IndexSymbolMarketProfiles[$symbol];
        }

        return self::IndexMarketProfiles[$country] ?? [
            'timezone' => $this->fallbackExchangeTimezone($index),
            'close' => '17:30:00',
        ];
    }

    private function fallbackExchangeTimezone(IndexWatchItem $index): string
    {
        $details = $this->marketData->exchangeDetailsForCode(
            $this->marketData->exchangeCodeForIndexWatchItem($index),
        );

        return $details['timezone'] ?? config('app.timezone', 'UTC');
    }

    /** @param array<string, mixed> $summary */
    private function incrementIntradayFailureCounter(array &$summary, string $status): void
    {
        if ($status === 'not_found') {
            $summary['intraday']['not_found_indices']++;

            return;
        }

        if ($status === 'access_denied') {
            $summary['intraday']['access_denied_indices']++;
        }
    }

    private function intradayFailureMessage(string $status, string $dateRange, Throwable $exception): string
    {
        return match ($status) {
            'timeout' => "EODHD intraday timed out for {$dateRange} after 3 attempts.",
            'rate_limited' => "EODHD rate limit persisted for {$dateRange} after 3 attempts (HTTP 429).",
            'access_denied' => "EODHD denied intraday access for {$dateRange} (HTTP 403). Check the subscription entitlement.",
            'not_found' => "EODHD returned HTTP 404 for {$dateRange} after 3 attempts. The period remains unverified; this does not prove that the index has no intraday data.",
            default => $this->safeErrorMessage($exception->getMessage(), 255),
        };
    }

    /** @param Collection<int, string> $dates */
    private function dateList(Collection $dates): string
    {
        $visibleDates = $dates->take(5)->implode(', ');
        $remainingCount = max($dates->count() - 5, 0);

        return $remainingCount > 0
            ? "{$visibleDates} (+{$remainingCount} more)"
            : $visibleDates;
    }

    /** @return Collection<string, true> */
    private function existingIntradayTimestamps(IndexWatchItem $index, Carbon $dateFrom, Carbon $dateTo): Collection
    {
        return $index->intradayCandles()
            ->where('interval', self::Interval)
            ->whereBetween('trading_date', [$dateFrom, $dateTo])
            ->get(['as_of'])
            ->mapWithKeys(fn (IndexWatchItemIntradayCandle $candle): array => [
                Carbon::parse($candle->getRawOriginal('as_of'), 'UTC')->format('Y-m-d H:i:s') => true,
            ]);
    }

    /** @param array<string, mixed> $summary */
    private function updateIntradayRunCounts(IndexEodhdSyncRun $run, array $summary): void
    {
        $run->update([
            'intraday_missing_count' => $summary['intraday']['missing'],
            'intraday_synced_count' => $summary['intraday']['synced'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<int, array{symbol: string, stage: string, status: string, message: string}>  $errors
     */
    private function finishRun(IndexEodhdSyncRun $run, array $summary, array $errors): void
    {
        $summary['indices'] = array_values($summary['indices']);
        $summary['errors'] = $errors;
        $failedCount = count(collect($errors)->pluck('symbol')->unique());
        $isPartial = $failedCount > 0
            || $summary['intraday']['no_data_indices'] > 0
            || $summary['intraday']['partial_indices'] > 0
            || $summary['intraday']['not_found_indices'] > 0
            || $summary['intraday']['access_denied_indices'] > 0;
        $this->finishStep($run, 'summary', 'Synchronization summary ready.');
        $run->refresh();
        $run->update([
            'status' => $isPartial ? 'partial' : 'finished',
            'stage' => 'summary',
            'processed_indices' => $run->total_indices,
            'failed_count' => $failedCount,
            'current' => null,
            'summary' => $summary,
            'message' => "Index EODHD sync finished: {$summary['eod']['synced']} EOD and {$summary['intraday']['synced']} intraday record(s) synced; {$summary['intraday']['missing']} finalized trading date(s) remain unverified.",
            'finished_at' => now(),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchEodRows(IndexWatchItem $index, Carbon $dateFrom, Carbon $dateTo): array
    {
        $response = $this->apiClient->get("eod/{$this->eodhdSymbol($index)}", [
            'from' => $dateFrom->toDateString(),
            'to' => $dateTo->toDateString(),
            'period' => 'd',
            'fmt' => 'json',
        ]);
        $payload = $this->responsePayload($response, 'EOD');
        $now = now();

        return collect($payload)
            ->filter(fn (mixed $record): bool => is_array($record))
            ->map(function (array $record) use ($index, $now): ?array {
                $date = $this->date(Arr::get($record, 'date'));
                $open = $this->decimal(Arr::get($record, 'open'));
                $close = $this->decimal(Arr::get($record, 'close'));

                if ($date === null || ($open === null && $close === null)) {
                    return null;
                }

                $asOf = $date->copy()->endOfDay()->utc();

                return [
                    'index_watch_item_id' => $index->id,
                    'trading_date' => $date->toDateString(),
                    'start_price' => $open,
                    'actual_price' => $close,
                    'last_price' => $this->decimal(Arr::get($record, 'adjusted_close')) ?? $close,
                    'actual_price_as_of' => $asOf,
                    'last_price_as_of' => $asOf,
                    'raw_payload' => $record,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchIntradayRows(IndexWatchItem $index, Carbon $dateFrom, Carbon $dateTo): array
    {
        $from = $dateFrom->copy()->startOfDay()->utc();
        $to = $dateTo->copy()->endOfDay()->utc();
        $response = $this->apiClient->get("intraday/{$this->eodhdSymbol($index)}", [
            'fmt' => 'json',
            'interval' => self::Interval,
            'from' => $from->timestamp,
            'to' => $to->timestamp,
        ]);

        $payload = $this->responsePayload($response, 'intraday');
        $timezone = $this->exchangeTimezone($index);
        $sourceUrl = $this->intradaySourceUrl($index, $from, $to);
        $now = now();

        return collect($payload)
            ->filter(fn (mixed $record): bool => is_array($record))
            ->map(function (array $record) use ($index, $now, $sourceUrl, $timezone): ?array {
                $asOf = $this->timestamp(Arr::get($record, 'timestamp'), Arr::get($record, 'datetime'));

                if ($asOf === null) {
                    return null;
                }

                $prices = [
                    'open' => $this->decimal(Arr::get($record, 'open')),
                    'high' => $this->decimal(Arr::get($record, 'high')),
                    'low' => $this->decimal(Arr::get($record, 'low')),
                    'close' => $this->decimal(Arr::get($record, 'close')),
                ];

                if (! collect($prices)->contains(fn (?string $price): bool => $price !== null)) {
                    return null;
                }

                return [
                    'index_watch_item_id' => $index->id,
                    'trading_date' => $asOf->copy()->setTimezone($timezone)->toDateString(),
                    'interval' => self::Interval,
                    'as_of' => $asOf,
                    'as_of_key' => $asOf->format('Y-m-d H:i:s'),
                    'timestamp' => is_numeric(Arr::get($record, 'timestamp')) ? (int) Arr::get($record, 'timestamp') : null,
                    'gmtoffset' => is_numeric(Arr::get($record, 'gmtoffset')) ? (int) Arr::get($record, 'gmtoffset') : null,
                    'datetime' => is_string(Arr::get($record, 'datetime')) ? Arr::get($record, 'datetime') : null,
                    ...$prices,
                    'volume' => is_numeric(Arr::get($record, 'volume')) ? max((int) Arr::get($record, 'volume'), 0) : null,
                    'currency' => $index->currency,
                    'source_key' => 'eodhd_intraday',
                    'source_name' => 'EODHD intraday',
                    'source_url' => $sourceUrl,
                    'raw_payload' => json_encode($record),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function storeIntradayRows(array $rows): int
    {
        $storedCount = 0;

        foreach (array_chunk($rows, 500) as $chunk) {
            $chunk = array_map(function (array $row): array {
                unset($row['as_of_key']);

                return $row;
            }, $chunk);
            $storedCount += IndexWatchItemIntradayCandle::query()->insertOrIgnore($chunk);
        }

        return $storedCount;
    }

    /**
     * @return array<int, mixed>
     */
    private function responsePayload(Response $response, string $dataType): array
    {
        if ($response->status() === 401) {
            throw new RuntimeException(
                'EODHD rejected the configured API token (HTTP 401). Update EODHD_API before retrying.',
                401,
            );
        }

        if ($response->failed()) {
            throw new RuntimeException(
                "EODHD {$dataType} request failed with HTTP {$response->status()}.",
                $response->status(),
            );
        }

        $payload = $this->sanitizePayload($response->json());

        if (! is_array($payload)) {
            throw new RuntimeException("EODHD returned an invalid {$dataType} response.");
        }

        if (($payload['status'] ?? null) === 'error') {
            $message = is_string($payload['message'] ?? null) ? $payload['message'] : "EODHD returned an {$dataType} error.";

            throw new RuntimeException($this->errorSanitizer->message($message));
        }

        return $payload;
    }

    private function throwIfAuthenticationFailed(Throwable $exception): void
    {
        if ((int) $exception->getCode() !== 401) {
            return;
        }

        throw $exception;
    }

    private function startStep(IndexEodhdSyncRun $run, string $stepKey, string $message): void
    {
        $this->updateStep($run, $stepKey, 'running', $message);
    }

    private function finishStep(IndexEodhdSyncRun $run, string $stepKey, string $message): void
    {
        $this->updateStep($run, $stepKey, 'finished', $message);
    }

    private function updateStep(IndexEodhdSyncRun $run, string $stepKey, string $status, string $message): void
    {
        $steps = collect($run->fresh()->steps)
            ->map(function (array $step) use ($message, $status, $stepKey): array {
                if ($step['key'] !== $stepKey) {
                    return $step;
                }

                return [...$step, 'status' => $status, 'message' => $message];
            })
            ->all();
        $run->update([
            'stage' => $stepKey,
            'steps' => $steps,
            'message' => $message,
        ]);
    }

    private function setCurrent(IndexEodhdSyncRun $run, IndexWatchItem $index, int $position, string $action): void
    {
        $run->update([
            'processed_indices' => $position,
            'current' => "{$action}: {$index->symbol}",
        ]);
    }

    /** @return Collection<int, IndexWatchItem> */
    private function indices(): Collection
    {
        return IndexWatchItem::query()
            ->where('instrument_type', 'INDEX')
            ->orderBy('symbol')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, IndexWatchItem>  $indices
     * @return array<int, array<string, mixed>>
     */
    private function initialIndexProgress(Collection $indices): array
    {
        return $indices
            ->map(fn (IndexWatchItem $index): array => [
                'id' => $index->id,
                'symbol' => $index->symbol,
                'name' => $index->name,
                'eod_check' => $this->pendingIndexProgressStage(),
                'eod_sync' => $this->pendingIndexProgressStage(),
                'intraday_check' => $this->pendingIndexProgressStage(),
                'intraday_sync' => $this->pendingIndexProgressStage(),
                'intraday_blocks' => [],
            ])
            ->values()
            ->all();
    }

    /** @return array{status: string, message: null} */
    private function pendingIndexProgressStage(): array
    {
        return [
            'status' => 'pending',
            'message' => null,
        ];
    }

    private function updateIndexProgress(
        IndexEodhdSyncRun $run,
        IndexWatchItem $index,
        string $stage,
        string $status,
        string $message,
    ): void {
        $indexProgress = collect($run->fresh()->index_progress ?? [])
            ->map(function (array $item) use ($index, $message, $stage, $status): array {
                if ($item['id'] !== $index->id) {
                    return $item;
                }

                $item[$stage] = [
                    'status' => $status,
                    'message' => $this->safeErrorMessage($message, 255),
                ];

                return $item;
            })
            ->all();

        $run->update(['index_progress' => $indexProgress]);
    }

    /** @param array<int, string> $stages */
    private function failIndexProgress(
        IndexEodhdSyncRun $run,
        IndexWatchItem $index,
        array $stages,
        string $message,
    ): void {
        $safeMessage = $this->safeErrorMessage($message, 255);
        $failureStatus = $this->messageStatus($message);
        $indexProgress = collect($run->fresh()->index_progress ?? [])
            ->map(function (array $item) use ($failureStatus, $index, $safeMessage, $stages): array {
                if ($item['id'] !== $index->id) {
                    return $item;
                }

                foreach ($stages as $stage) {
                    $status = Arr::get($item, "{$stage}.status");

                    if ($status === 'running') {
                        $item[$stage] = ['status' => $failureStatus, 'message' => $safeMessage];
                    }

                    if ($status === 'pending') {
                        $item[$stage] = ['status' => 'skipped', 'message' => 'Skipped after the preceding failure.'];
                    }
                }

                return $item;
            })
            ->all();

        $run->update(['index_progress' => $indexProgress]);
    }

    /**
     * @return array{completed: int, total: int, successful: int, deferred: int, issues: int, percent: int, estimated_remaining_seconds: ?int}
     */
    private function progressPayload(IndexEodhdSyncRun $run): array
    {
        $terminalStatuses = [
            'finished',
            'partial',
            'no_data',
            'deferred',
            'failed',
            'timeout',
            'rate_limited',
            'access_denied',
            'skipped',
            'not_found',
            'unavailable',
        ];
        $stages = ['eod_check', 'eod_sync', 'intraday_check', 'intraday_sync'];
        $indexProgress = collect($run->index_progress ?? []);
        $total = $indexProgress->count() * count($stages);
        $completed = $indexProgress->sum(function (array $item) use ($stages, $terminalStatuses): int {
            return collect($stages)
                ->filter(fn (string $stage): bool => in_array(Arr::get($item, "{$stage}.status"), $terminalStatuses, true))
                ->count();
        });
        $statuses = $indexProgress
            ->flatMap(fn (array $item): array => collect($stages)
                ->map(fn (string $stage): mixed => Arr::get($item, "{$stage}.status"))
                ->all());
        $successful = $statuses->filter(fn (mixed $status): bool => $status === 'finished')->count();
        $deferred = $statuses->filter(fn (mixed $status): bool => $status === 'deferred')->count();
        $issues = $statuses
            ->filter(fn (mixed $status): bool => in_array($status, $terminalStatuses, true))
            ->reject(fn (mixed $status): bool => in_array($status, ['finished', 'deferred'], true))
            ->count();
        $percent = $total > 0 ? (int) round(($completed / $total) * 100) : 100;
        $estimatedRemainingSeconds = null;

        if ($run->started_at && $completed > 0 && $completed < $total) {
            $elapsedSeconds = max((int) $run->started_at->diffInSeconds(now()), 1);
            $estimatedRemainingSeconds = (int) ceil(
                ($elapsedSeconds / $completed) * ($total - $completed),
            );
        }

        if ($completed === $total) {
            $estimatedRemainingSeconds = 0;
        }

        return [
            'completed' => $completed,
            'total' => $total,
            'successful' => $successful,
            'deferred' => $deferred,
            'issues' => $issues,
            'percent' => $percent,
            'estimated_remaining_seconds' => $estimatedRemainingSeconds,
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function indexSummary(array $summary, IndexWatchItem $index): array
    {
        return $summary['indices'][$index->id] ?? [
            'id' => $index->id,
            'symbol' => $index->symbol,
            'name' => $index->name,
        ];
    }

    /**
     * @return array{symbol: string, stage: string, status: string, message: string}
     */
    private function errorPayload(
        IndexWatchItem $index,
        string $stage,
        Throwable $exception,
        ?string $message = null,
    ): array {
        return [
            'symbol' => $index->symbol,
            'stage' => $stage,
            'status' => $this->exceptionStatus($exception),
            'message' => $this->safeErrorMessage($message ?? $exception->getMessage(), 255),
        ];
    }

    private function exceptionStatus(Throwable $exception): string
    {
        $statusCode = (int) $exception->getCode();

        if ($statusCode === 403) {
            return 'access_denied';
        }

        if ($statusCode === 404) {
            return 'not_found';
        }

        if ($statusCode === 429) {
            return 'rate_limited';
        }

        return $this->messageStatus($exception->getMessage());
    }

    private function messageStatus(string $message): string
    {
        return Str::contains(Str::lower($message), ['timed out', 'timeout'])
            ? 'timeout'
            : 'failed';
    }

    private function safeErrorMessage(string $message, int $limit): string
    {
        return $this->errorSanitizer->message($message, $limit);
    }

    private function sanitizePayload(mixed $value): mixed
    {
        return $this->errorSanitizer->payload($value, 10_000);
    }

    /**
     * @return array<int, array{key: string, label: string, status: string, message: ?string}>
     */
    private function initialSteps(): array
    {
        return collect([
            'check_indices' => 'Check all indices',
            'check_eod' => 'Check EOD data',
            'sync_eod' => 'Sync missing EOD data',
            'check_intraday' => 'Check intraday data',
            'sync_intraday' => 'Sync missing intraday data',
            'summary' => 'Create summary',
        ])->map(fn (string $label, string $key): array => [
            'key' => $key,
            'label' => $label,
            'status' => 'pending',
            'message' => null,
        ])->values()->all();
    }

    private function eodhdSymbol(IndexWatchItem $index): string
    {
        $exchange = Str::upper((string) $index->exchange);
        $exchange = $exchange !== '' ? $exchange : $this->marketData->exchangeCodeForIndexWatchItem($index);

        return Str::upper((string) $index->symbol).'.'.$exchange;
    }

    private function exchangeTimezone(IndexWatchItem $index): string
    {
        return $this->indexMarketProfile($index)['timezone'];
    }

    private function intradaySourceUrl(IndexWatchItem $index, Carbon $from, Carbon $to): string
    {
        $baseUrl = rtrim((string) config('services.eodhd.base_url', 'https://eodhd.com/api'), '/');

        return "{$baseUrl}/intraday/{$this->eodhdSymbol($index)}?fmt=json&interval=".self::Interval."&from={$from->timestamp}&to={$to->timestamp}";
    }

    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    private function timestamp(mixed $timestamp, mixed $datetime): ?Carbon
    {
        if (is_numeric($timestamp) && (int) $timestamp > 0) {
            return Carbon::createFromTimestampUTC((int) $timestamp);
        }

        if (! is_string($datetime) || trim($datetime) === '') {
            return null;
        }

        try {
            return Carbon::parse($datetime, 'UTC')->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function decimal(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        if (! preg_match('/^-?\d+(?:\.\d+)?$/', $value) || (float) $value <= 0) {
            return null;
        }

        return number_format((float) $value, 8, '.', '');
    }
}
