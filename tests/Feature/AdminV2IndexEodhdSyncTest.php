<?php

namespace Tests\Feature;

use App\Jobs\SyncIndexEodhdData;
use App\Models\EodhdExchange;
use App\Models\IndexEodhdSyncRun;
use App\Models\IndexWatchItem;
use App\Models\IndexWatchItemIntradayCandle;
use App\Models\IndexWatchItemPrice;
use App\Models\User;
use App\Services\V2IndexEodhdSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminV2IndexEodhdSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_queue_an_index_eodhd_sync_and_read_its_steps(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-04 21:00:00', 'Europe/Vienna'));
        Queue::fake();

        try {
            IndexWatchItem::factory()->create(['symbol' => 'GDAXI', 'instrument_type' => 'INDEX']);
            IndexWatchItem::factory()->create(['symbol' => 'ATX', 'instrument_type' => 'INDEX']);
            $admin = $this->adminUser();
            $response = $this->actingAs($admin)
                ->postJson('/admin/v2/indices/eodhd-sync');

            $response
                ->assertAccepted()
                ->assertJsonPath('refresh.status', 'queued')
                ->assertJsonPath('refresh.date_from', '2025-08-05')
                ->assertJsonPath('refresh.date_to', '2026-08-04')
                ->assertJsonCount(6, 'refresh.steps')
                ->assertJsonCount(2, 'refresh.index_progress')
                ->assertJsonPath('refresh.index_progress.0.symbol', 'ATX')
                ->assertJsonPath('refresh.index_progress.1.symbol', 'GDAXI')
                ->assertJsonPath('refresh.progress.completed', 0)
                ->assertJsonPath('refresh.progress.total', 8)
                ->assertJsonPath('refresh.steps.0.key', 'check_indices')
                ->assertJsonPath('refresh.steps.5.key', 'summary');

            $refreshId = $response->json('refresh.refresh_id');

            Queue::assertPushed(
                SyncIndexEodhdData::class,
                fn (SyncIndexEodhdData $job): bool => $job->refreshId === $refreshId,
            );

            $this->actingAs($admin)
                ->postJson('/admin/v2/indices/eodhd-sync')
                ->assertAccepted()
                ->assertJsonPath('refresh.refresh_id', $refreshId);
            Queue::assertPushed(SyncIndexEodhdData::class, 1);

            $this->actingAs($admin)
                ->getJson("/admin/v2/indices/eodhd-sync/{$refreshId}")
                ->assertOk()
                ->assertJsonPath('refresh.refresh_id', $refreshId)
                ->assertJsonPath('refresh.status', 'queued');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_sync_requests_only_missing_intraday_periods_and_skips_complete_periods_on_the_next_run(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-04 21:00:00', 'Europe/Vienna'));
        config(['services.eodhd.key' => 'test-token']);

        try {
            $index = IndexWatchItem::factory()->create([
                'symbol' => 'ATX',
                'exchange' => 'INDX',
                'instrument_type' => 'INDEX',
                'currency' => 'EUR',
            ]);
            IndexWatchItem::factory()->create([
                'symbol' => 'EXXX',
                'instrument_type' => 'ETF',
            ]);
            IndexWatchItemPrice::query()->create([
                'index_watch_item_id' => $index->id,
                'trading_date' => '2026-08-03',
                'start_price' => '5000.00000000',
                'actual_price' => '5050.00000000',
                'last_price' => '5050.00000000',
                'intraday_sync_status' => 'complete',
                'intraday_candle_count' => 1,
                'intraday_sync_attempts' => 1,
                'intraday_http_status' => 200,
                'intraday_checked_at' => now(),
            ]);
            IndexWatchItemIntradayCandle::query()->create([
                'index_watch_item_id' => $index->id,
                'trading_date' => '2026-08-03',
                'interval' => '5m',
                'as_of' => '2026-08-03 08:00:00',
                'timestamp' => Carbon::parse('2026-08-03 08:00:00', 'UTC')->timestamp,
                'open' => '5100.00000000',
                'high' => '5110.00000000',
                'low' => '5090.00000000',
                'close' => '5105.00000000',
            ]);

            Http::fake([
                'eodhd.com/api/eod/ATX.INDX*' => Http::response([
                    ['date' => '2026-08-03', 'open' => 5000, 'close' => 5050, 'adjusted_close' => 5050],
                    ['date' => '2026-08-04', 'open' => 5050, 'close' => 5120, 'adjusted_close' => 5120],
                ]),
                'eodhd.com/api/intraday/ATX.INDX*' => Http::response([
                    $this->intradayRecord('2026-08-04 08:05:00', 5110),
                ]),
            ]);

            $syncService = app(V2IndexEodhdSyncService::class);
            $run = $syncService->createRun();
            $syncService->run($run->id);
            $run->refresh();

            $this->assertSame('finished', $run->status);
            $this->assertSame(1, $run->total_indices);
            $this->assertSame(1, $run->eod_missing_count);
            $this->assertSame(1, $run->eod_synced_count);
            $this->assertSame(0, $run->intraday_missing_count);
            $this->assertSame(1, $run->summary['intraday']['checked_missing']);
            $this->assertSame(1, $run->intraday_synced_count);
            $this->assertSame(2, $run->summary['eod']['available']);
            $this->assertSame(1, $run->summary['eod']['already_present']);
            $this->assertSame(1, $run->summary['intraday']['available']);
            $this->assertSame(0, $run->summary['intraday']['already_present']);
            $this->assertSame('ATX', $run->summary['indices'][0]['symbol']);
            $this->assertSame(1, $run->summary['indices'][0]['eod']['synced']);
            $this->assertSame(1, $run->summary['indices'][0]['intraday']['synced']);
            $this->assertSame('finished', $run->index_progress[0]['eod_check']['status']);
            $this->assertSame('finished', $run->index_progress[0]['eod_sync']['status']);
            $this->assertSame('finished', $run->index_progress[0]['intraday_check']['status']);
            $this->assertSame('finished', $run->index_progress[0]['intraday_sync']['status']);
            $this->assertSame(4, $syncService->payload($run)['progress']['completed']);
            $this->assertSame(4, $syncService->payload($run)['progress']['total']);

            $this->assertDatabaseCount('index_watch_item_prices', 2);
            $syncedPrice = $index->prices()->whereDate('trading_date', '2026-08-04')->firstOrFail();
            $this->assertSame('5120.00000000', $syncedPrice->actual_price);
            $this->assertDatabaseCount('index_watch_item_intraday_candles', 2);
            $this->assertDatabaseHas('index_watch_item_intraday_candles', [
                'index_watch_item_id' => $index->id,
                'interval' => '5m',
                'as_of' => '2026-08-04 08:05:00',
                'close' => '5110.00000000',
            ]);

            $intradayRequests = Http::recorded(
                fn (Request $request): bool => str_contains($request->url(), '/intraday/ATX.INDX'),
            )->values();
            $this->assertCount(1, $intradayRequests);
            $this->assertSame(
                Carbon::parse('2026-08-04 00:00:00', 'Europe/Vienna')->utc()->timestamp,
                (int) $intradayRequests[0][0]['from'],
            );
            $this->assertSame(
                Carbon::parse('2026-08-04 23:59:59', 'Europe/Vienna')->utc()->timestamp,
                (int) $intradayRequests[0][0]['to'],
            );

            $secondRun = $syncService->createRun();
            $syncService->run($secondRun->id);
            $secondRun->refresh();

            $this->assertSame('finished', $secondRun->status, json_encode([
                'error' => $secondRun->error,
                'message' => $secondRun->message,
                'summary' => $secondRun->summary,
            ]));
            $this->assertSame('finished', $secondRun->index_progress[0]['intraday_check']['status'], json_encode([
                'progress' => $secondRun->index_progress,
                'summary' => $secondRun->summary,
            ]));
            $this->assertStringContainsString('2 finalized date(s)', $secondRun->index_progress[0]['intraday_sync']['message']);
            $this->assertCount(
                1,
                Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/intraday/ATX.INDX')),
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_persistent_http_404_is_retried_and_reported_as_not_found_instead_of_unsupported(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-04 21:00:00', 'Europe/Vienna'));
        config(['services.eodhd.key' => 'test-token']);

        try {
            IndexWatchItem::factory()->create([
                'symbol' => 'ATX',
                'exchange' => 'INDX',
                'instrument_type' => 'INDEX',
            ]);
            Http::fake([
                'eodhd.com/api/eod/ATX.INDX*' => Http::response([
                    ['date' => '2026-08-04', 'open' => 5050, 'close' => 5120, 'adjusted_close' => 5120],
                ]),
                'eodhd.com/api/intraday/ATX.INDX*' => Http::response([], 404),
            ]);

            $syncService = app(V2IndexEodhdSyncService::class);
            $run = $syncService->createRun();
            $syncService->run($run->id);
            $run->refresh();

            $this->assertSame('partial', $run->status);
            $this->assertSame(0, $run->unsupported_intraday_count);
            $this->assertSame(0, $run->summary['intraday']['no_data_indices']);
            $this->assertSame(0, $run->summary['intraday']['unsupported_indices']);
            $this->assertSame(1, $run->summary['intraday']['not_found_indices']);
            $this->assertSame('not_found', $run->summary['indices'][0]['intraday']['status']);
            $this->assertSame('finished', $run->index_progress[0]['intraday_check']['status']);
            $this->assertSame('not_found', $run->index_progress[0]['intraday_sync']['status']);
            $this->assertStringContainsString('HTTP 404', $run->index_progress[0]['intraday_sync']['message']);
            $this->assertStringContainsString('does not prove', $run->index_progress[0]['intraday_sync']['message']);
            $this->assertCount(
                3,
                Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/intraday/ATX.INDX')),
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_transient_http_404_is_retried_and_can_recover(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-04 21:00:00', 'Europe/Vienna'));
        config(['services.eodhd.key' => 'test-token']);
        $intradayAttempts = 0;

        try {
            IndexWatchItem::factory()->create([
                'symbol' => '000001',
                'exchange' => 'INDX',
                'country' => 'China',
                'instrument_type' => 'INDEX',
            ]);
            Http::fake(function (Request $request) use (&$intradayAttempts) {
                if (str_contains($request->url(), '/eod/000001.INDX')) {
                    return Http::response([
                        ['date' => '2026-08-04', 'open' => 3550, 'close' => 3560, 'adjusted_close' => 3560],
                    ]);
                }

                $intradayAttempts++;

                if ($intradayAttempts === 1) {
                    return Http::response([], 404);
                }

                return Http::response([$this->intradayRecord('2026-08-04 01:35:00', 3555)]);
            });

            $syncService = app(V2IndexEodhdSyncService::class);
            $run = $syncService->createRun();
            $syncService->run($run->id);
            $run->refresh();

            $this->assertSame(2, $intradayAttempts);
            $this->assertSame('finished', $run->status);
            $this->assertSame(0, $run->intraday_missing_count);
            $this->assertSame(0, $run->unsupported_intraday_count);
            $this->assertSame('finished', $run->index_progress[0]['intraday_sync']['status']);
            $this->assertDatabaseCount('index_watch_item_intraday_candles', 1);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_missing_intraday_periods_are_requested_in_blocks_of_at_most_thirty_days(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-04 21:00:00', 'Europe/Vienna'));
        config(['services.eodhd.key' => 'test-token']);

        try {
            IndexWatchItem::factory()->create([
                'symbol' => 'ATX',
                'exchange' => 'INDX',
                'instrument_type' => 'INDEX',
            ]);
            Http::fake(function (Request $request) {
                if (str_contains($request->url(), '/eod/ATX.INDX')) {
                    return Http::response([
                        ['date' => '2026-06-01', 'open' => 4900, 'close' => 4910, 'adjusted_close' => 4910],
                        ['date' => '2026-07-02', 'open' => 5000, 'close' => 5010, 'adjusted_close' => 5010],
                        ['date' => '2026-08-04', 'open' => 5100, 'close' => 5110, 'adjusted_close' => 5110],
                    ]);
                }

                $date = Carbon::createFromTimestampUTC((int) $request['from'])
                    ->setTimezone('Europe/Vienna')
                    ->toDateString();

                return Http::response([$this->intradayRecord("{$date} 08:05:00", 5110)]);
            });

            $syncService = app(V2IndexEodhdSyncService::class);
            $run = $syncService->createRun();
            $syncService->run($run->id);
            $run->refresh();

            $intradayRequests = Http::recorded(
                fn (Request $request): bool => str_contains($request->url(), '/intraday/ATX.INDX'),
            )->values();

            $this->assertSame('finished', $run->status);
            $this->assertCount(3, $intradayRequests);

            foreach ($intradayRequests as [$request]) {
                $from = Carbon::createFromTimestampUTC((int) $request['from']);
                $to = Carbon::createFromTimestampUTC((int) $request['to']);

                $this->assertLessThanOrEqual(30, $from->diffInDays($to));
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_intraday_timeout_is_retried_and_can_recover(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-04 21:00:00', 'Europe/Vienna'));
        config(['services.eodhd.key' => 'test-token']);
        $intradayAttempts = 0;

        try {
            IndexWatchItem::factory()->create([
                'symbol' => 'ATX',
                'exchange' => 'INDX',
                'instrument_type' => 'INDEX',
            ]);
            Http::fake(function (Request $request) use (&$intradayAttempts) {
                if (str_contains($request->url(), '/eod/ATX.INDX')) {
                    return Http::response([
                        ['date' => '2026-08-04', 'open' => 5050, 'close' => 5120, 'adjusted_close' => 5120],
                    ]);
                }

                $intradayAttempts++;

                if ($intradayAttempts === 1) {
                    return Http::failedConnection('cURL error 28: Operation timed out for api_token=test-token')($request);
                }

                return Http::response([$this->intradayRecord('2026-08-04 08:05:00', 5110)]);
            });

            $syncService = app(V2IndexEodhdSyncService::class);
            $run = $syncService->createRun();
            $syncService->run($run->id);
            $run->refresh();

            $this->assertSame(2, $intradayAttempts);
            $this->assertSame('finished', $run->status);
            $this->assertSame('finished', $run->index_progress[0]['intraday_sync']['status']);
            $this->assertDatabaseCount('index_watch_item_intraday_candles', 1);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_exhausted_intraday_timeouts_are_explicit_and_api_tokens_are_redacted(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-04 21:00:00', 'Europe/Vienna'));
        config(['services.eodhd.key' => 'super-secret-token']);
        $intradayAttempts = 0;

        try {
            IndexWatchItem::factory()->create([
                'symbol' => 'ATX',
                'exchange' => 'INDX',
                'instrument_type' => 'INDEX',
            ]);
            Http::fake(function (Request $request) use (&$intradayAttempts) {
                if (str_contains($request->url(), '/eod/ATX.INDX')) {
                    return Http::response([
                        ['date' => '2026-08-04', 'open' => 5050, 'close' => 5120, 'adjusted_close' => 5120],
                    ]);
                }

                $intradayAttempts++;

                return Http::failedConnection(
                    'cURL error 28: Operation timed out for https://eodhd.com/api/intraday/ATX.INDX?api_token=super-secret-token',
                )($request);
            });

            $syncService = app(V2IndexEodhdSyncService::class);
            $run = $syncService->createRun();
            $syncService->run($run->id);
            $run->refresh();
            $payload = $syncService->payload($run);

            $this->assertSame(3, $intradayAttempts);
            $this->assertSame('partial', $run->status);
            $this->assertSame('finished', $run->index_progress[0]['intraday_check']['status']);
            $this->assertSame('timeout', $run->index_progress[0]['intraday_sync']['status']);
            $this->assertSame('timeout', $run->summary['indices'][0]['intraday']['status']);
            $this->assertStringContainsString('after 3 attempts', $run->index_progress[0]['intraday_sync']['message']);
            $this->assertStringNotContainsString('super-secret-token', json_encode($run->summary));
            $this->assertStringNotContainsString('api_token=', json_encode($payload));

            $run->update(['error' => 'Connection failed for api_token=super-secret-token']);
            $this->assertStringNotContainsString('super-secret-token', json_encode($syncService->payload($run->fresh())));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_empty_http_200_intraday_response_is_retried_and_can_recover(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-04 21:00:00', 'Europe/Vienna'));
        config(['services.eodhd.key' => 'test-token']);
        $intradayAttempts = 0;

        try {
            IndexWatchItem::factory()->create([
                'symbol' => '000001',
                'exchange' => 'SHG',
                'country' => 'China',
                'instrument_type' => 'INDEX',
            ]);
            Http::fake(function (Request $request) use (&$intradayAttempts) {
                if (str_contains($request->url(), '/eod/000001.SHG')) {
                    return Http::response([
                        ['date' => '2026-02-02', 'open' => 4079, 'close' => 4015, 'adjusted_close' => 4015],
                    ]);
                }

                $intradayAttempts++;

                if ($intradayAttempts === 1) {
                    return Http::response([]);
                }

                return Http::response([$this->intradayRecord('2026-02-02 01:30:00', 4015)]);
            });

            $syncService = app(V2IndexEodhdSyncService::class);
            $run = $syncService->createRun();
            $syncService->run($run->id);
            $run->refresh();

            $this->assertSame(2, $intradayAttempts);
            $this->assertSame('finished', $run->status);
            $this->assertSame('finished', $run->index_progress[0]['intraday_sync']['status']);
            $this->assertSame(2, $run->index_progress[0]['intraday_blocks'][0]['attempts']);
            $verifiedPrice = IndexWatchItemPrice::query()->firstOrFail();
            $this->assertSame('2026-02-02', $verifiedPrice->trading_date->toDateString());
            $this->assertSame('complete', $verifiedPrice->intraday_sync_status);
            $this->assertSame(1, $verifiedPrice->intraday_candle_count);
            $this->assertSame(2, $verifiedPrice->intraday_sync_attempts);
            $this->assertSame(200, $verifiedPrice->intraday_http_status);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_retry_narrows_a_partially_returned_block_to_its_missing_dates(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-05 21:00:00', 'Europe/Vienna'));
        config(['services.eodhd.key' => 'test-token']);
        $intradayAttempts = 0;

        try {
            IndexWatchItem::factory()->create([
                'symbol' => '000001',
                'exchange' => 'SHG',
                'country' => 'China',
                'instrument_type' => 'INDEX',
            ]);
            Http::fake(function (Request $request) use (&$intradayAttempts) {
                if (str_contains($request->url(), '/eod/000001.SHG')) {
                    return Http::response([
                        ['date' => '2026-08-03', 'open' => 4050, 'close' => 4060, 'adjusted_close' => 4060],
                        ['date' => '2026-08-04', 'open' => 4060, 'close' => 4070, 'adjusted_close' => 4070],
                    ]);
                }

                $intradayAttempts++;

                return $intradayAttempts === 1
                    ? Http::response([$this->intradayRecord('2026-08-03 01:30:00', 4060)])
                    : Http::response([$this->intradayRecord('2026-08-04 01:30:00', 4070)]);
            });

            $syncService = app(V2IndexEodhdSyncService::class);
            $run = $syncService->createRun();
            $syncService->run($run->id);
            $run->refresh();

            $intradayRequests = Http::recorded(
                fn (Request $request): bool => str_contains($request->url(), '/intraday/000001.SHG'),
            )->values();

            $this->assertSame('finished', $run->status);
            $this->assertSame(2, $intradayAttempts);
            $this->assertCount(2, $intradayRequests);
            $this->assertSame(
                Carbon::parse('2026-08-04 00:00:00', 'Asia/Shanghai')->utc()->timestamp,
                (int) $intradayRequests[1][0]['from'],
            );
            $this->assertSame(
                Carbon::parse('2026-08-04 23:59:59', 'Asia/Shanghai')->utc()->timestamp,
                (int) $intradayRequests[1][0]['to'],
            );
            $this->assertSame(2, IndexWatchItemPrice::query()->where('intraday_sync_status', 'complete')->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_official_exchange_holidays_are_excluded_from_intraday_coverage(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-08 21:00:00', 'Europe/Vienna'));
        config(['services.eodhd.key' => 'test-token']);

        try {
            $index = IndexWatchItem::factory()->create([
                'symbol' => 'IBEX',
                'exchange' => 'INDX',
                'country' => 'Spain',
                'instrument_type' => 'INDEX',
            ]);
            EodhdExchange::query()->create([
                'code' => 'MC',
                'detail_code' => 'BMEX',
                'name' => 'Madrid Exchange',
                'country' => 'Spain',
                'operating_mic' => 'BMEX',
                'holidays' => [
                    '2026-04-03' => [
                        'Holiday' => 'Good Friday',
                        'Type' => 'Official',
                    ],
                ],
                'synced_at' => now(),
            ]);

            foreach (['2026-04-02', '2026-04-07'] as $tradingDate) {
                IndexWatchItemPrice::query()->create([
                    'index_watch_item_id' => $index->id,
                    'trading_date' => $tradingDate,
                    'start_price' => 13000,
                    'actual_price' => 13100,
                    'last_price' => 13100,
                    'raw_payload' => ['volume' => 1000],
                    'intraday_sync_status' => 'complete',
                    'intraday_candle_count' => 1,
                    'intraday_checked_at' => now(),
                ]);
                IndexWatchItemIntradayCandle::query()->create([
                    'index_watch_item_id' => $index->id,
                    'trading_date' => $tradingDate,
                    'interval' => '5m',
                    'as_of' => "{$tradingDate} 08:00:00",
                    'timestamp' => Carbon::parse("{$tradingDate} 08:00:00", 'UTC')->timestamp,
                    'open' => 13000,
                    'high' => 13100,
                    'low' => 12900,
                    'close' => 13050,
                ]);
            }

            Http::fake([
                'eodhd.com/api/eod/IBEX.INDX*' => Http::response([
                    ['date' => '2026-04-02', 'open' => 13000, 'close' => 13100, 'adjusted_close' => 13100, 'volume' => 1000],
                    ['date' => '2026-04-03', 'open' => 13100, 'close' => 13100, 'adjusted_close' => 13100, 'volume' => 0],
                    ['date' => '2026-04-07', 'open' => 13100, 'close' => 13200, 'adjusted_close' => 13200, 'volume' => 1000],
                ]),
                'eodhd.com/api/intraday/IBEX.INDX*' => Http::response([]),
            ]);

            $syncService = app(V2IndexEodhdSyncService::class);
            $run = $syncService->createRun();
            $syncService->run($run->id);
            $run->refresh();

            $this->assertSame('finished', $run->status, json_encode($run->summary));
            $this->assertSame(0, $run->intraday_missing_count);
            $this->assertSame(1, $run->summary['intraday']['market_closed_dates']);
            $this->assertSame(2, $run->summary['indices'][0]['intraday']['expected_dates']);
            $this->assertSame(2, $run->summary['indices'][0]['intraday']['verified_dates']);
            $this->assertSame('market_closed', IndexWatchItemPrice::query()
                ->whereDate('trading_date', '2026-04-03')
                ->value('intraday_sync_status'));
            $this->assertSame(
                'Official exchange holiday; excluded from expected intraday trading dates.',
                IndexWatchItemPrice::query()
                    ->whereDate('trading_date', '2026-04-03')
                    ->value('intraday_sync_message'),
            );
            $this->assertCount(
                0,
                Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/intraday/IBEX.INDX')),
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_early_close_dates_remain_required_intraday_trading_dates(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-29 21:00:00', 'Europe/Vienna'));
        config(['services.eodhd.key' => 'test-token']);

        try {
            $index = IndexWatchItem::factory()->create([
                'symbol' => 'IBEX',
                'exchange' => 'INDX',
                'country' => 'Spain',
                'instrument_type' => 'INDEX',
            ]);
            EodhdExchange::query()->create([
                'code' => 'MC',
                'detail_code' => 'BMEX',
                'name' => 'Madrid Exchange',
                'country' => 'Spain',
                'operating_mic' => 'BMEX',
                'holidays' => [
                    '2026-12-24' => [
                        'Holiday' => 'Christmas Eve',
                        'Type' => 'EarlyClose',
                        'EarlyClose' => '14:00:00',
                    ],
                ],
                'synced_at' => now(),
            ]);
            $this->storeCompleteIntradayDate($index, '2026-12-23');
            $this->storeCompleteIntradayDate($index, '2026-12-28');

            Http::fake([
                'eodhd.com/api/eod/IBEX.INDX*' => Http::response([
                    ['date' => '2026-12-23', 'open' => 13000, 'close' => 13100, 'adjusted_close' => 13100, 'volume' => 1000],
                    ['date' => '2026-12-24', 'open' => 13100, 'close' => 13150, 'adjusted_close' => 13150, 'volume' => 0],
                    ['date' => '2026-12-28', 'open' => 13150, 'close' => 13200, 'adjusted_close' => 13200, 'volume' => 1000],
                ]),
                'eodhd.com/api/intraday/IBEX.INDX*' => Http::response([]),
            ]);

            $syncService = app(V2IndexEodhdSyncService::class);
            $run = $syncService->createRun();
            $syncService->run($run->id);
            $run->refresh();

            $this->assertSame('partial', $run->status);
            $this->assertSame(1, $run->intraday_missing_count);
            $this->assertSame(0, $run->summary['intraday']['market_closed_dates']);
            $this->assertSame(['2026-12-24'], $run->summary['indices'][0]['intraday']['no_data_dates']);
            $this->assertSame('no_data', IndexWatchItemPrice::query()
                ->whereDate('trading_date', '2026-12-24')
                ->value('intraday_sync_status'));
            $this->assertCount(
                3,
                Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/intraday/IBEX.INDX')),
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_existing_history_with_one_empty_zero_volume_date_is_treated_as_market_closure_in_the_same_run(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-05 21:00:00', 'Europe/Vienna'));
        config(['services.eodhd.key' => 'test-token']);

        try {
            $index = IndexWatchItem::factory()->create([
                'symbol' => 'N225',
                'exchange' => 'INDX',
                'country' => 'Japan',
                'instrument_type' => 'INDEX',
            ]);
            IndexWatchItemPrice::query()->create([
                'index_watch_item_id' => $index->id,
                'trading_date' => '2026-02-20',
                'start_price' => 56000,
                'actual_price' => 56100,
                'last_price' => 56100,
                'intraday_sync_status' => 'complete',
                'intraday_candle_count' => 1,
                'intraday_checked_at' => now(),
            ]);
            IndexWatchItemIntradayCandle::query()->create([
                'index_watch_item_id' => $index->id,
                'trading_date' => '2026-02-20',
                'interval' => '5m',
                'as_of' => '2026-02-20 00:00:00',
                'timestamp' => Carbon::parse('2026-02-20 00:00:00', 'UTC')->timestamp,
                'open' => 56000,
                'high' => 56100,
                'low' => 55900,
                'close' => 56050,
            ]);
            IndexWatchItemPrice::query()->create([
                'index_watch_item_id' => $index->id,
                'trading_date' => '2026-02-24',
                'start_price' => 56800,
                'actual_price' => 56900,
                'last_price' => 56900,
                'intraday_sync_status' => 'complete',
                'intraday_candle_count' => 1,
                'intraday_checked_at' => now(),
            ]);
            IndexWatchItemIntradayCandle::query()->create([
                'index_watch_item_id' => $index->id,
                'trading_date' => '2026-02-24',
                'interval' => '5m',
                'as_of' => '2026-02-24 00:00:00',
                'timestamp' => Carbon::parse('2026-02-24 00:00:00', 'UTC')->timestamp,
                'open' => 56800,
                'high' => 56900,
                'low' => 56700,
                'close' => 56850,
            ]);
            Http::fake([
                'eodhd.com/api/eod/N225.INDX*' => Http::response([
                    ['date' => '2026-02-20', 'open' => 56000, 'close' => 56100, 'adjusted_close' => 56100],
                    ['date' => '2026-02-23', 'open' => 56725, 'close' => 56703, 'adjusted_close' => 56703, 'volume' => 0],
                    ['date' => '2026-02-24', 'open' => 56800, 'close' => 56900, 'adjusted_close' => 56900],
                ]),
                'eodhd.com/api/intraday/N225.INDX*' => Http::response([]),
            ]);

            $syncService = app(V2IndexEodhdSyncService::class);
            $run = $syncService->createRun();
            $syncService->run($run->id);
            $run->refresh();

            $this->assertSame('finished', $run->status, json_encode($run->summary));
            $this->assertSame(0, $run->intraday_missing_count);
            $this->assertSame(0, $run->unsupported_intraday_count);
            $this->assertSame(0, $run->summary['intraday']['partial_indices']);
            $this->assertSame(0, $run->summary['intraday']['no_data_indices']);
            $this->assertSame(1, $run->summary['intraday']['market_closed_dates']);
            $this->assertSame('finished', $run->summary['indices'][0]['intraday']['status']);
            $this->assertSame(2, $run->summary['indices'][0]['intraday']['expected_dates']);
            $this->assertSame(2, $run->summary['indices'][0]['intraday']['verified_dates']);
            $this->assertSame('finished', $run->summary['indices'][0]['intraday']['blocks'][0]['status']);
            $this->assertSame([], $run->summary['indices'][0]['intraday']['blocks'][0]['missing_dates']);
            $this->assertSame('finished', $run->index_progress[0]['intraday_sync']['status']);
            $this->assertSame('finished', $run->index_progress[0]['intraday_blocks'][0]['status']);
            $this->assertSame('market_closed', IndexWatchItemPrice::query()
                ->whereDate('trading_date', '2026-02-23')
                ->value('intraday_sync_status'));
            $this->assertCount(
                3,
                Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/intraday/N225.INDX')),
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_http_200_without_any_intraday_history_is_no_data_not_unavailable(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-05 21:00:00', 'Europe/Vienna'));
        config(['services.eodhd.key' => 'test-token']);

        try {
            IndexWatchItem::factory()->create([
                'symbol' => 'ATG',
                'exchange' => 'INDX',
                'country' => 'Greece',
                'instrument_type' => 'INDEX',
            ]);
            Http::fake([
                'eodhd.com/api/eod/ATG.INDX*' => Http::response([
                    ['date' => '2026-07-01', 'open' => 2455, 'close' => 2481, 'adjusted_close' => 2481],
                ]),
                'eodhd.com/api/intraday/ATG.INDX*' => Http::response([[
                    'timestamp' => Carbon::parse('2026-07-01 08:00:00', 'UTC')->timestamp,
                    'datetime' => '2026-07-01 08:00:00',
                    'open' => null,
                    'high' => null,
                    'low' => null,
                    'close' => null,
                    'volume' => null,
                ]]),
            ]);

            $syncService = app(V2IndexEodhdSyncService::class);
            $run = $syncService->createRun();
            $syncService->run($run->id);
            $run->refresh();

            $this->assertSame('partial', $run->status);
            $this->assertSame(1, $run->intraday_missing_count);
            $this->assertSame(0, $run->unsupported_intraday_count);
            $this->assertSame(1, $run->summary['intraday']['no_data_indices']);
            $this->assertSame(0, $run->summary['intraday']['unsupported_indices']);
            $this->assertSame('no_data', $run->summary['indices'][0]['intraday']['status']);
            $this->assertSame('no_data', $run->index_progress[0]['intraday_sync']['status']);
            $this->assertStringContainsString('HTTP 200', $run->index_progress[0]['intraday_sync']['message']);
            $this->assertStringContainsString('no usable intraday prices', $run->index_progress[0]['intraday_sync']['message']);
            $this->assertSame('no_data', $run->index_progress[0]['intraday_blocks'][0]['status']);
            $this->assertSame(0, $run->summary['indices'][0]['intraday']['returned_candles']);
            $this->assertSame(1, $run->summary['indices'][0]['intraday']['missing']);

            $secondRun = $syncService->createRun();
            $syncService->run($secondRun->id);
            $secondRun->refresh();

            $this->assertSame('partial', $secondRun->status, json_encode([
                'error' => $secondRun->error,
                'message' => $secondRun->message,
                'summary' => $secondRun->summary,
            ]));
            $this->assertSame('finished', $secondRun->index_progress[0]['intraday_check']['status'], json_encode([
                'progress' => $secondRun->index_progress,
                'summary' => $secondRun->summary,
            ]));
            $this->assertSame('no_data', $secondRun->index_progress[0]['intraday_sync']['status']);
            $this->assertSame(1, $secondRun->summary['intraday']['retry_later_dates']);
            $this->assertSame(1, $secondRun->summary['indices'][0]['intraday']['missing']);
            $this->assertStringContainsString('no duplicate request was sent', $secondRun->index_progress[0]['intraday_sync']['message']);
            $this->assertCount(
                3,
                Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/intraday/ATG.INDX')),
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_http_403_is_reported_as_access_denied(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-04 21:00:00', 'Europe/Vienna'));
        config(['services.eodhd.key' => 'test-token']);

        try {
            IndexWatchItem::factory()->create([
                'symbol' => 'ATX',
                'exchange' => 'INDX',
                'country' => 'Austria',
                'instrument_type' => 'INDEX',
            ]);
            Http::fake([
                'eodhd.com/api/eod/ATX.INDX*' => Http::response([
                    ['date' => '2026-08-04', 'open' => 5050, 'close' => 5120, 'adjusted_close' => 5120],
                ]),
                'eodhd.com/api/intraday/ATX.INDX*' => Http::response(['message' => 'Forbidden'], 403),
            ]);

            $syncService = app(V2IndexEodhdSyncService::class);
            $run = $syncService->createRun();
            $syncService->run($run->id);
            $run->refresh();

            $this->assertSame('partial', $run->status);
            $this->assertSame(0, $run->unsupported_intraday_count);
            $this->assertSame(1, $run->summary['intraday']['access_denied_indices']);
            $this->assertSame('access_denied', $run->summary['indices'][0]['intraday']['status']);
            $this->assertSame('access_denied', $run->index_progress[0]['intraday_sync']['status']);
            $this->assertStringContainsString('HTTP 403', $run->index_progress[0]['intraday_sync']['message']);
            $this->assertCount(
                1,
                Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/intraday/ATX.INDX')),
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_open_current_us_trading_day_is_deferred_without_an_intraday_request(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-04 18:00:00', 'Europe/Vienna'));
        config(['services.eodhd.key' => 'test-token']);

        try {
            $index = IndexWatchItem::factory()->create([
                'symbol' => 'OEX',
                'exchange' => 'INDX',
                'country' => 'USA',
                'instrument_type' => 'INDEX',
            ]);
            IndexWatchItemPrice::query()->create([
                'index_watch_item_id' => $index->id,
                'trading_date' => '2026-08-03',
                'start_price' => 3700,
                'actual_price' => 3710,
                'last_price' => 3710,
                'intraday_sync_status' => 'complete',
                'intraday_candle_count' => 1,
                'intraday_checked_at' => now(),
            ]);
            IndexWatchItemIntradayCandle::query()->create([
                'index_watch_item_id' => $index->id,
                'trading_date' => '2026-08-03',
                'interval' => '5m',
                'as_of' => '2026-08-03 13:30:00',
                'timestamp' => Carbon::parse('2026-08-03 13:30:00', 'UTC')->timestamp,
                'open' => 3700,
                'high' => 3710,
                'low' => 3690,
                'close' => 3705,
            ]);
            Http::fake([
                'eodhd.com/api/eod/OEX.INDX*' => Http::response([
                    ['date' => '2026-08-03', 'open' => 3700, 'close' => 3710, 'adjusted_close' => 3710],
                    ['date' => '2026-08-04', 'open' => 3765, 'close' => 3806, 'adjusted_close' => 3806],
                ]),
            ]);

            $syncService = app(V2IndexEodhdSyncService::class);
            $run = $syncService->createRun();
            $syncService->run($run->id);
            $run->refresh();

            $this->assertSame('finished', $run->status);
            $this->assertSame(0, $run->intraday_missing_count);
            $this->assertSame(1, $run->summary['intraday']['deferred_dates']);
            $this->assertSame('deferred', $run->summary['indices'][0]['intraday']['status']);
            $this->assertSame('finished', $run->index_progress[0]['intraday_check']['status']);
            $this->assertSame('deferred', $run->index_progress[0]['intraday_sync']['status']);
            $this->assertSame(4, $syncService->payload($run)['progress']['completed']);
            $this->assertSame(3, $syncService->payload($run)['progress']['successful']);
            $this->assertSame(1, $syncService->payload($run)['progress']['deferred']);
            $this->assertSame(0, $syncService->payload($run)['progress']['issues']);
            $this->assertCount(
                0,
                Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/intraday/OEX.INDX')),
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_current_us_trading_day_stays_deferred_after_finalized_history_is_synced(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-04 18:00:00', 'Europe/Vienna'));
        config(['services.eodhd.key' => 'test-token']);

        try {
            IndexWatchItem::factory()->create([
                'symbol' => 'OEX',
                'exchange' => 'INDX',
                'country' => 'USA',
                'instrument_type' => 'INDEX',
            ]);
            Http::fake([
                'eodhd.com/api/eod/OEX.INDX*' => Http::response([
                    ['date' => '2026-08-03', 'open' => 3700, 'close' => 3710, 'adjusted_close' => 3710],
                    ['date' => '2026-08-04', 'open' => 3765, 'close' => 3806, 'adjusted_close' => 3806],
                ]),
                'eodhd.com/api/intraday/OEX.INDX*' => Http::response([
                    $this->intradayRecord('2026-08-03 13:30:00', 3705),
                ]),
            ]);

            $syncService = app(V2IndexEodhdSyncService::class);
            $run = $syncService->createRun();
            $syncService->run($run->id);
            $run->refresh();

            $this->assertSame('finished', $run->status);
            $this->assertSame(0, $run->intraday_missing_count);
            $this->assertSame('deferred', $run->summary['indices'][0]['intraday']['status']);
            $this->assertSame('deferred', $run->index_progress[0]['intraday_sync']['status']);
            $intradayRequests = Http::recorded(
                fn (Request $request): bool => str_contains($request->url(), '/intraday/OEX.INDX'),
            )->values();
            $this->assertCount(1, $intradayRequests);
            $requestedUntil = Carbon::createFromTimestampUTC((int) $intradayRequests[0][0]['to'])
                ->setTimezone('America/New_York');
            $this->assertSame('2026-08-03', $requestedUntil->toDateString());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_transient_http_500_is_retried_and_can_recover(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-04 21:00:00', 'Europe/Vienna'));
        config(['services.eodhd.key' => 'test-token']);
        $intradayAttempts = 0;

        try {
            IndexWatchItem::factory()->create([
                'symbol' => 'ATX',
                'exchange' => 'INDX',
                'country' => 'Austria',
                'instrument_type' => 'INDEX',
            ]);
            Http::fake(function (Request $request) use (&$intradayAttempts) {
                if (str_contains($request->url(), '/eod/ATX.INDX')) {
                    return Http::response([
                        ['date' => '2026-08-04', 'open' => 5050, 'close' => 5120, 'adjusted_close' => 5120],
                    ]);
                }

                $intradayAttempts++;

                return $intradayAttempts === 1
                    ? Http::response(['message' => 'Temporary provider failure'], 500)
                    : Http::response([$this->intradayRecord('2026-08-04 08:05:00', 5110)]);
            });

            $syncService = app(V2IndexEodhdSyncService::class);
            $run = $syncService->createRun();
            $syncService->run($run->id);
            $run->refresh();

            $this->assertSame(2, $intradayAttempts);
            $this->assertSame('finished', $run->status);
            $this->assertSame('complete', IndexWatchItemPrice::query()->firstOrFail()->intraday_sync_status);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_last_price_only_placeholder_is_not_treated_as_a_valid_eod_reference(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-04 21:00:00', 'Europe/Vienna'));
        config(['services.eodhd.key' => 'test-token']);

        try {
            $index = IndexWatchItem::factory()->create([
                'symbol' => 'ATG',
                'exchange' => 'INDX',
                'country' => 'Greece',
                'instrument_type' => 'INDEX',
            ]);
            IndexWatchItemPrice::query()->create([
                'index_watch_item_id' => $index->id,
                'trading_date' => '2026-08-04',
                'last_price' => 2440,
                'raw_payload' => ['timestamp' => 'NA', 'close' => 'NA', 'previousClose' => 2440],
            ]);
            Http::fake([
                'eodhd.com/api/eod/ATG.INDX*' => Http::response([
                    ['date' => '2026-08-04', 'open' => 2440, 'close' => 2460, 'adjusted_close' => 2460],
                ]),
                'eodhd.com/api/intraday/ATG.INDX*' => Http::response([
                    $this->intradayRecord('2026-08-04 08:05:00', 2455),
                ]),
            ]);

            $syncService = app(V2IndexEodhdSyncService::class);
            $run = $syncService->createRun();
            $syncService->run($run->id);
            $run->refresh();

            $price = $index->prices()->whereDate('trading_date', '2026-08-04')->firstOrFail();
            $this->assertSame('finished', $run->status);
            $this->assertSame(1, $run->eod_missing_count);
            $this->assertSame(1, $run->eod_synced_count);
            $this->assertSame(1, $run->summary['indices'][0]['intraday']['expected_dates']);
            $this->assertSame('2440.00000000', $price->start_price);
            $this->assertSame('2460.00000000', $price->actual_price);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_non_retryable_intraday_error_is_attempted_once(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-04 21:00:00', 'Europe/Vienna'));
        config(['services.eodhd.key' => 'test-token']);

        try {
            IndexWatchItem::factory()->create([
                'symbol' => 'ATX',
                'exchange' => 'INDX',
                'country' => 'Austria',
                'instrument_type' => 'INDEX',
            ]);
            Http::fake([
                'eodhd.com/api/eod/ATX.INDX*' => Http::response([
                    ['date' => '2026-08-04', 'open' => 5050, 'close' => 5120, 'adjusted_close' => 5120],
                ]),
                'eodhd.com/api/intraday/ATX.INDX*' => Http::response(['message' => 'Invalid request'], 400),
            ]);

            $syncService = app(V2IndexEodhdSyncService::class);
            $run = $syncService->createRun();
            $syncService->run($run->id);
            $run->refresh();

            $this->assertSame('partial', $run->status);
            $this->assertSame('failed', $run->index_progress[0]['intraday_sync']['status']);
            $this->assertSame(1, $run->index_progress[0]['intraday_blocks'][0]['attempts']);
            $this->assertCount(
                1,
                Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/intraday/ATX.INDX')),
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_guest_cannot_start_or_read_an_index_eodhd_sync(): void
    {
        $run = IndexEodhdSyncRun::query()->create([
            'id' => 'index-eodhd-test',
            'date_from' => '2025-08-05',
            'date_to' => '2026-08-04',
            'steps' => [],
        ]);

        $this->postJson('/admin/v2/indices/eodhd-sync')->assertUnauthorized();
        $this->getJson("/admin/v2/indices/eodhd-sync/{$run->id}")->assertUnauthorized();
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function storeCompleteIntradayDate(IndexWatchItem $index, string $tradingDate): void
    {
        IndexWatchItemPrice::query()->create([
            'index_watch_item_id' => $index->id,
            'trading_date' => $tradingDate,
            'start_price' => 13000,
            'actual_price' => 13100,
            'last_price' => 13100,
            'raw_payload' => ['volume' => 1000],
            'intraday_sync_status' => 'complete',
            'intraday_candle_count' => 1,
            'intraday_checked_at' => now(),
        ]);
        IndexWatchItemIntradayCandle::query()->create([
            'index_watch_item_id' => $index->id,
            'trading_date' => $tradingDate,
            'interval' => '5m',
            'as_of' => "{$tradingDate} 08:00:00",
            'timestamp' => Carbon::parse("{$tradingDate} 08:00:00", 'UTC')->timestamp,
            'open' => 13000,
            'high' => 13100,
            'low' => 12900,
            'close' => 13050,
        ]);
    }

    /**
     * @return array<string, int|string>
     */
    private function intradayRecord(string $dateTime, int $close): array
    {
        return [
            'timestamp' => Carbon::parse($dateTime, 'UTC')->timestamp,
            'gmtoffset' => 0,
            'datetime' => $dateTime,
            'open' => $close - 2,
            'high' => $close + 2,
            'low' => $close - 3,
            'close' => $close,
            'volume' => 100,
        ];
    }
}
