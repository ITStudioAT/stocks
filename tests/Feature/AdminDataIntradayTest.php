<?php

namespace Tests\Feature;

use App\Jobs\ReloadStockHoldingIntradayData;
use App\Models\EodhdExchange;
use App\Models\StockHolding;
use App\Models\StockHoldingIntradayCandle;
use App\Models\User;
use App\Services\StockHoldingIntradayDataReloader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminDataIntradayTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_admin_can_list_intraday_data_grouped_by_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-07 12:00:00', 'Europe/Vienna'));
        $admin = $this->adminUser();
        $holding = StockHolding::factory()->create([
            'symbol' => 'AMES',
            'name' => 'Amundi IBEX 35 UCITS ETF',
            'exchange' => 'XETRA',
            'mic_code' => 'XETR',
            'currency' => 'EUR',
        ]);
        StockHoldingIntradayCandle::query()->create([
            'stock_holding_id' => $holding->id,
            'trading_date' => '2026-06-05',
            'interval' => '5m',
            'as_of' => Carbon::parse('2026-06-05 08:00:00', 'UTC'),
            'timestamp' => 1780646400,
            'gmtoffset' => 0,
            'datetime' => '2026-06-05 08:00:00',
            'open' => '10.10000000',
            'high' => '10.20000000',
            'low' => '10.00000000',
            'close' => '10.15000000',
            'volume' => 1200,
            'currency' => 'EUR',
            'source_key' => 'eodhd_intraday',
            'source_name' => 'EODHD intraday',
            'source_url' => 'https://example.com/intraday/AMES.XETRA',
            'raw_payload' => ['close' => 10.15],
        ]);
        StockHoldingIntradayCandle::query()->create([
            'stock_holding_id' => $holding->id,
            'trading_date' => '2026-06-04',
            'interval' => '5m',
            'as_of' => Carbon::parse('2026-06-04 08:00:00', 'UTC'),
            'timestamp' => 1780560000,
            'datetime' => '2026-06-04 08:00:00',
            'open' => '9.90000000',
            'high' => '10.00000000',
            'low' => '9.80000000',
            'close' => '9.95000000',
            'volume' => 900,
            'currency' => 'EUR',
            'source_key' => 'eodhd_intraday',
            'source_name' => 'EODHD intraday',
            'source_url' => 'https://example.com/intraday/AMES.XETRA',
            'raw_payload' => ['close' => 9.95],
        ]);
        StockHoldingIntradayCandle::query()->create([
            'stock_holding_id' => $holding->id,
            'trading_date' => '2026-06-03',
            'interval' => '5m',
            'as_of' => Carbon::parse('2026-06-03 08:00:00', 'UTC'),
            'timestamp' => 1780473600,
            'datetime' => '2026-06-03 08:00:00',
            'open' => '8.90000000',
            'high' => '9.00000000',
            'low' => '8.80000000',
            'close' => '8.95000000',
            'volume' => 700,
            'currency' => 'EUR',
            'source_key' => 'eodhd_intraday',
            'source_name' => 'EODHD intraday',
            'source_url' => 'https://example.com/intraday/AMES.XETRA',
            'raw_payload' => ['close' => 8.95],
        ]);

        $this->actingAs($admin)
            ->getJson("/admin/data/intraday?stock={$holding->id}")
            ->assertOk()
            ->assertJsonPath('stocks.0.id', $holding->id)
            ->assertJsonPath('selected_stock_id', $holding->id)
            ->assertJsonPath('days.0.trading_date', '2026-06-05')
            ->assertJsonPath('days.0.interval', '5m')
            ->assertJsonPath('days.0.rows.0.close', '10.15000000')
            ->assertJsonPath('days.1.trading_date', '2026-06-04')
            ->assertJsonPath('days.1.rows.0.volume', 900)
            ->assertJsonPath('days.2.trading_date', '2026-06-03')
            ->assertJsonPath('days.2.rows.0.close', '8.95000000')
            ->assertJsonCount(3, 'days');
    }

    public function test_admin_can_queue_all_stock_intraday_reload(): void
    {
        Queue::fake();
        $admin = $this->adminUser();
        $holding = StockHolding::factory()->create();
        StockHolding::factory()->create();

        $response = $this->actingAs($admin)
            ->postJson('/admin/data/intraday/reload', ['selected_stock_id' => $holding->id])
            ->assertAccepted()
            ->assertJsonPath('selected_stock_id', $holding->id)
            ->assertJsonPath('refresh.status', 'queued')
            ->assertJsonPath('refresh.total', 2);

        $refreshId = $response->json('refresh.refresh_id');

        Queue::assertPushed(ReloadStockHoldingIntradayData::class, fn (ReloadStockHoldingIntradayData $job): bool => $job->refreshId === $refreshId);
        $this->assertDatabaseHas('stock_holding_intraday_reload_runs', [
            'id' => $refreshId,
            'stock_holding_id' => null,
            'status' => 'queued',
            'total_count' => 2,
        ]);
    }

    public function test_intraday_import_run_reloads_all_stocks_and_reports_holiday_overview(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-07 12:00:00', 'Europe/Vienna'));
        config(['services.eodhd.key' => 'test-token']);
        $admin = $this->adminUser();
        $holding = StockHolding::factory()->create([
            'symbol' => 'AMES',
            'name' => 'Amundi IBEX 35 UCITS ETF',
            'exchange' => 'XETRA',
            'mic_code' => 'XETR',
            'currency' => 'EUR',
        ]);
        $secondHolding = StockHolding::factory()->create([
            'symbol' => 'LEER',
            'name' => 'Amundi MSCI Eastern Europe',
            'exchange' => 'XETRA',
            'mic_code' => 'XETR',
            'currency' => 'EUR',
        ]);
        EodhdExchange::query()->create([
            'code' => 'XETRA',
            'detail_code' => 'XETR',
            'name' => 'XETRA',
            'country' => 'Germany',
            'currency' => 'EUR',
            'timezone' => 'Europe/Berlin',
            'operating_mic' => 'XETR',
            'trading_hours' => [
                'WorkingDays' => 'Mon, Tue, Wed, Thu, Fri',
            ],
            'holidays' => [
                '2026-06-04' => [
                    'Holiday' => 'Corpus Christi',
                    'Type' => 'Official',
                ],
            ],
            'synced_at' => now(),
        ]);
        StockHoldingIntradayCandle::query()->create([
            'stock_holding_id' => $holding->id,
            'trading_date' => '2026-06-05',
            'interval' => '5m',
            'as_of' => Carbon::parse('2026-06-05 08:05:00', 'UTC'),
            'timestamp' => 1780646700,
            'datetime' => '2026-06-05 08:05:00',
            'volume' => 0,
            'currency' => 'EUR',
            'source_key' => 'eodhd_intraday',
            'source_name' => 'EODHD intraday',
            'source_url' => 'https://example.com/intraday/AMES.XETRA',
            'raw_payload' => ['volume' => 0],
        ]);

        Http::fake([
            'eodhd.com/api/intraday/AMES.XETRA*' => Http::sequence()
                ->push([
                    [
                        'timestamp' => 1780646400,
                        'gmtoffset' => 0,
                        'datetime' => '2026-06-05 08:00:00',
                        'open' => 10.1,
                        'high' => 10.2,
                        'low' => 10,
                        'close' => 10.15,
                        'volume' => 1200,
                    ],
                    [
                        'timestamp' => 1780646700,
                        'gmtoffset' => 0,
                        'datetime' => '2026-06-05 08:05:00',
                        'open' => null,
                        'high' => null,
                        'low' => null,
                        'close' => null,
                        'volume' => 0,
                    ],
                    [
                        'timestamp' => 1780647000,
                        'gmtoffset' => 0,
                        'datetime' => '2026-06-05 08:10:00',
                        'open' => 0,
                        'high' => 0,
                        'low' => 0,
                        'close' => 0,
                        'volume' => 0,
                    ],
                ])
                ->push([
                    [
                        'timestamp' => 1780473600,
                        'gmtoffset' => 0,
                        'datetime' => '2026-06-03 08:00:00',
                        'open' => 9.9,
                        'high' => 10,
                        'low' => 9.8,
                        'close' => 9.95,
                        'volume' => 900,
                    ],
                ]),
            'eodhd.com/api/intraday/LEER.XETRA*' => Http::sequence()
                ->push([
                    [
                        'timestamp' => 1780646400,
                        'gmtoffset' => 0,
                        'datetime' => '2026-06-05 08:00:00',
                        'open' => 20.1,
                        'high' => 20.2,
                        'low' => 20,
                        'close' => 20.15,
                        'volume' => 2200,
                    ],
                ])
                ->push([
                    [
                        'timestamp' => 1780473600,
                        'gmtoffset' => 0,
                        'datetime' => '2026-06-03 08:00:00',
                        'open' => 19.9,
                        'high' => 20,
                        'low' => 19.8,
                        'close' => 19.95,
                        'volume' => 1900,
                    ],
                ]),
        ]);

        $run = app(StockHoldingIntradayDataReloader::class)->createRun();
        app(StockHoldingIntradayDataReloader::class)->import($run->id);

        $this->actingAs($admin)
            ->getJson("/admin/data/intraday/reload/{$run->id}?stock={$holding->id}")
            ->assertOk()
            ->assertJsonPath('selected_stock_id', $holding->id)
            ->assertJsonPath('refresh.status', 'finished')
            ->assertJsonPath('refresh.stored_count', 4)
            ->assertJsonPath('refresh.total', 2)
            ->assertJsonPath('refresh.processed', 2)
            ->assertJsonPath('refresh.success_count', 2)
            ->assertJsonPath('days.0.trading_date', '2026-06-05')
            ->assertJsonPath('days.1.trading_date', '2026-06-04')
            ->assertJsonPath('days.1.overview', 'Market closed: Corpus Christi')
            ->assertJsonCount(0, 'days.1.rows')
            ->assertJsonPath('days.2.trading_date', '2026-06-03')
            ->assertJsonCount(3, 'days');

        $this->assertDatabaseHas('stock_holding_intraday_candles', [
            'stock_holding_id' => $holding->id,
            'trading_date' => '2026-06-05',
            'interval' => '5m',
            'datetime' => '2026-06-05 08:00:00',
            'close' => '10.15000000',
        ]);
        $this->assertDatabaseMissing('stock_holding_intraday_candles', [
            'stock_holding_id' => $holding->id,
            'interval' => '5m',
            'datetime' => '2026-06-05 08:05:00',
        ]);
        $this->assertDatabaseMissing('stock_holding_intraday_candles', [
            'stock_holding_id' => $holding->id,
            'interval' => '5m',
            'datetime' => '2026-06-05 08:10:00',
        ]);
        $this->assertDatabaseHas('stock_holding_intraday_reload_runs', [
            'id' => $run->id,
            'stock_holding_id' => null,
            'status' => 'finished',
            'stored_count' => 4,
            'processed_count' => 2,
            'success_count' => 2,
        ]);
        $this->assertDatabaseHas('stock_holding_intraday_candles', [
            'stock_holding_id' => $secondHolding->id,
            'trading_date' => '2026-06-05',
            'interval' => '5m',
            'datetime' => '2026-06-05 08:00:00',
            'close' => '20.15000000',
        ]);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/intraday/AMES.XETRA')
            && $request['fmt'] === 'json'
            && $request['interval'] === '5m'
            && $request['api_token'] === 'test-token'
            && is_numeric($request['from'])
            && is_numeric($request['to']));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/intraday/LEER.XETRA')
            && $request['fmt'] === 'json'
            && $request['interval'] === '5m'
            && $request['api_token'] === 'test-token'
            && is_numeric($request['from'])
            && is_numeric($request['to']));
        Http::assertSentCount(4);
    }

    public function test_guest_cannot_reload_intraday_data(): void
    {
        $this->postJson('/admin/data/intraday/reload', ['stock_id' => 1])->assertUnauthorized();
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
