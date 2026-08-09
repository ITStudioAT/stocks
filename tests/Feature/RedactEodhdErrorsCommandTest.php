<?php

namespace Tests\Feature;

use App\Models\AppConfig;
use App\Models\EodhdExchange;
use App\Models\EodhdExchangeImportRun;
use App\Models\IndexEodhdSyncRun;
use App\Models\IndexWatchItem;
use App\Models\IndexWatchItemIntradayCandle;
use App\Models\IndexWatchItemPrice;
use App\Models\IndexWatchItemRealtimePrice;
use App\Models\StockEodhdSyncRun;
use App\Models\StockHolding;
use App\Models\StockHoldingDailyPrice;
use App\Models\StockHoldingIntradayCandle;
use App\Models\StockPrice;
use App\Models\StockRealtimePrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RedactEodhdErrorsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_redacts_historical_error_payloads_idempotently(): void
    {
        config(['services.eodhd.key' => 'configured-secret-token']);

        $stockRun = StockEodhdSyncRun::query()->create([
            'id' => 'stock-redaction-test',
            'date_from' => '2025-08-01',
            'date_to' => '2026-08-01',
            'steps' => [[
                'key' => 'eod',
                'message' => 'Failed for api_token=rotated-secret-token&fmt=json',
            ]],
            'error' => 'Connection failed with configured-secret-token',
        ]);
        $safeStockRun = StockEodhdSyncRun::query()->create([
            'id' => 'stock-safe-redaction-test',
            'date_from' => '2025-08-01',
            'date_to' => '2026-08-01',
            'steps' => [],
        ]);
        DB::table('stock_eodhd_sync_runs')
            ->where('id', $safeStockRun->id)
            ->update(['steps' => '[ { "key": "eod", "message": "No provider secret" } ]']);
        $safePayload = DB::table('stock_eodhd_sync_runs')
            ->where('id', $safeStockRun->id)
            ->value('steps');
        $indexRun = IndexEodhdSyncRun::query()->create([
            'id' => 'index-redaction-test',
            'date_from' => '2025-08-01',
            'date_to' => '2026-08-01',
            'steps' => [],
            'index_progress' => [[
                'message' => '{"api_token":"json-secret-token"}',
            ]],
            'summary' => [
                'error' => 'api%5Ftoken=encoded-secret-token',
            ],
        ]);
        $exchangeRun = EodhdExchangeImportRun::query()->create([
            'id' => 'exchange-redaction-test',
            'error_summary' => [
                'message' => 'api_token => exchange-secret-token',
            ],
        ]);
        $schedulerConfig = AppConfig::query()->create([
            'key' => 'v2_index_realtime.schedule',
            'value' => [
                'last_error' => 'api_token=scheduler-secret-token',
                'provider_error' => [
                    'api_token' => 'structured-scheduler-secret-token',
                ],
            ],
        ]);
        $unrelatedConfig = AppConfig::query()->create([
            'key' => 'unrelated.setting',
            'value' => [
                'message' => 'api_token=unrelated-value',
            ],
        ]);
        $failedJobId = DB::table('failed_jobs')->insertGetId([
            'uuid' => 'eodhd-redaction-failed-job',
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'Connection failed for api_token=failed-job-secret-token&fmt=json',
            'failed_at' => now(),
        ]);
        $historicalRawPayloadTargets = $this->createHistoricalRawPayloads();

        $this->artisan('security:redact-eodhd-errors --chunk=1')
            ->expectsOutputToContain('15 database record(s)')
            ->assertSuccessful();

        $sanitizedValues = json_encode([
            $stockRun->refresh()->steps,
            $stockRun->error,
            $indexRun->refresh()->index_progress,
            $indexRun->summary,
            $exchangeRun->refresh()->error_summary,
            $schedulerConfig->refresh()->value,
            DB::table('failed_jobs')->where('id', $failedJobId)->value('exception'),
            $this->rawPayloadValues($historicalRawPayloadTargets),
        ], JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('configured-secret-token', $sanitizedValues);
        $this->assertStringNotContainsString('rotated-secret-token', $sanitizedValues);
        $this->assertStringNotContainsString('json-secret-token', $sanitizedValues);
        $this->assertStringNotContainsString('encoded-secret-token', $sanitizedValues);
        $this->assertStringNotContainsString('exchange-secret-token', $sanitizedValues);
        $this->assertStringNotContainsString('scheduler-secret-token', $sanitizedValues);
        $this->assertStringNotContainsString('structured-scheduler-secret-token', $sanitizedValues);
        $this->assertStringNotContainsString('failed-job-secret-token', $sanitizedValues);
        $this->assertStringNotContainsString('historical-raw-secret-token', $sanitizedValues);
        $this->assertStringContainsString('[redacted]', $sanitizedValues);
        $this->assertSame(
            'api_token=unrelated-value',
            $unrelatedConfig->refresh()->value['message'],
        );
        $this->assertSame(
            $safePayload,
            DB::table('stock_eodhd_sync_runs')->where('id', $safeStockRun->id)->value('steps'),
        );

        $this->artisan('security:redact-eodhd-errors')
            ->expectsOutputToContain('0 database record(s)')
            ->assertSuccessful();
    }

    public function test_it_rejects_unsafe_chunk_sizes(): void
    {
        $this->artisan('security:redact-eodhd-errors --chunk=0')
            ->expectsOutputToContain('chunk size must be between 1 and 5000')
            ->assertExitCode(2);
    }

    /**
     * @return array<int, array{table: string, id: int, columns: array<int, string>}>
     */
    private function createHistoricalRawPayloads(): array
    {
        $rawPayload = [
            'api_token' => 'historical-raw-secret-token',
            'nested' => [
                'url' => 'https://eodhd.test/data?api_token=historical-raw-secret-token&fmt=json',
            ],
        ];
        $holding = StockHolding::factory()->create();
        $index = IndexWatchItem::factory()->create(['raw_payload' => $rawPayload]);
        $exchange = EodhdExchange::query()->create([
            'code' => 'RAW',
            'detail_code' => 'RAW',
            'raw_exchange' => $rawPayload,
            'raw_details' => $rawPayload,
        ]);
        $legacyQuoteId = DB::table('stock_price_quotes')->insertGetId([
            'stock_holding_id' => $holding->id,
            'source_key' => 'eodhd_realtime',
            'source_name' => 'EODHD real-time',
            'source_url' => 'https://eodhd.test/real-time/RAW.US',
            'source_quality' => 'market_data_vendor',
            'price_type' => 'last',
            'fetched_at' => now(),
            'freshness_status' => 'fresh',
            'validation_status' => 'valid',
            'raw_payload' => json_encode($rawPayload, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $stockPrice = StockPrice::factory()->create(['raw_payload' => $rawPayload]);
        $realtimePrice = StockRealtimePrice::factory()->create([
            'stock_holding_id' => $holding->id,
            'raw_payload' => $rawPayload,
        ]);
        $dailyPrice = StockHoldingDailyPrice::factory()->create([
            'stock_holding_id' => $holding->id,
            'raw_payload' => $rawPayload,
        ]);
        $intradayCandle = StockHoldingIntradayCandle::query()->create([
            'stock_holding_id' => $holding->id,
            'trading_date' => '2026-08-08',
            'interval' => '5m',
            'as_of' => '2026-08-08 12:00:00',
            'close' => 100,
            'raw_payload' => $rawPayload,
        ]);
        $indexPrice = IndexWatchItemPrice::query()->create([
            'index_watch_item_id' => $index->id,
            'trading_date' => '2026-08-08',
            'actual_price' => 100,
            'raw_payload' => $rawPayload,
        ]);
        $indexRealtimePrice = IndexWatchItemRealtimePrice::query()->create([
            'index_watch_item_id' => $index->id,
            'trading_date' => '2026-08-08',
            'price' => 100,
            'as_of' => '2026-08-08 12:00:00',
            'raw_payload' => $rawPayload,
        ]);
        $indexIntradayCandle = IndexWatchItemIntradayCandle::query()->create([
            'index_watch_item_id' => $index->id,
            'trading_date' => '2026-08-08',
            'interval' => '5m',
            'as_of' => '2026-08-08 12:00:00',
            'close' => 100,
            'raw_payload' => $rawPayload,
        ]);

        return [
            ['table' => 'eodhd_exchanges', 'id' => $exchange->id, 'columns' => ['raw_exchange', 'raw_details']],
            ['table' => 'stock_price_quotes', 'id' => $legacyQuoteId, 'columns' => ['raw_payload']],
            ['table' => 'stock_prices', 'id' => $stockPrice->id, 'columns' => ['raw_payload']],
            ['table' => 'stock_realtime_prices', 'id' => $realtimePrice->id, 'columns' => ['raw_payload']],
            ['table' => 'stock_holding_daily_prices', 'id' => $dailyPrice->id, 'columns' => ['raw_payload']],
            ['table' => 'stock_holding_intraday_candles', 'id' => $intradayCandle->id, 'columns' => ['raw_payload']],
            ['table' => 'index_watch_items', 'id' => $index->id, 'columns' => ['raw_payload']],
            ['table' => 'index_watch_item_prices', 'id' => $indexPrice->id, 'columns' => ['raw_payload']],
            ['table' => 'index_watch_item_realtime_prices', 'id' => $indexRealtimePrice->id, 'columns' => ['raw_payload']],
            ['table' => 'index_watch_item_intraday_candles', 'id' => $indexIntradayCandle->id, 'columns' => ['raw_payload']],
        ];
    }

    /**
     * @param  array<int, array{table: string, id: int, columns: array<int, string>}>  $targets
     * @return array<int, mixed>
     */
    private function rawPayloadValues(array $targets): array
    {
        return collect($targets)
            ->flatMap(function (array $target): array {
                $record = DB::table($target['table'])->where('id', $target['id'])->firstOrFail();

                return collect($target['columns'])
                    ->map(fn (string $column): mixed => $record->{$column})
                    ->all();
            })
            ->all();
    }
}
