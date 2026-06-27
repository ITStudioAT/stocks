<?php

namespace Tests\Feature;

use App\Models\AppConfig;
use App\Models\EodhdExchange;
use App\Models\StockHolding;
use App\Models\StockHoldingIntradayCandle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminDataHistoricalSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_sync_eodhd_historical_intraday_candles(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        $this->travelTo(Carbon::parse('2026-06-26 18:00:00', 'Europe/Vienna'));
        $admin = $this->adminUser();
        $holding = StockHolding::factory()->create([
            'symbol' => 'AMES',
            'name' => 'Amundi IBEX 35 UCITS ETF Acc',
            'isin' => 'FR0010655746',
            'wkn' => 'A0REJT',
            'exchange' => 'XETRA',
            'mic_code' => 'XETR',
            'country' => 'Germany',
            'currency' => 'EUR',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Vienna',
        ]);
        $coveredHolding = StockHolding::factory()->create([
            'symbol' => 'COV',
            'name' => 'Covered Holding',
            'exchange' => 'XETRA',
            'mic_code' => 'XETR',
            'country' => 'Germany',
            'currency' => 'EUR',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Vienna',
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
            'holidays' => [],
            'synced_at' => now(),
        ]);
        AppConfig::query()->create([
            'key' => 'intraday_candle_backfill.schedule',
            'value' => [
                'daily_time' => '18:30',
                'interval_minutes' => 15,
                'timezone' => 'Europe/Vienna',
                'last_dispatched_at' => null,
                'last_dispatched_on' => null,
                'next_refresh_at' => '2026-06-26T18:30:00+02:00',
            ],
        ]);
        $this->createIntradayCandle($holding, '2026-06-25');
        $this->createIntradayCandle($coveredHolding, '2026-06-26');

        Http::preventStrayRequests();
        Http::fake([
            'eodhd.com/api/intraday/AMES.XETRA*' => Http::response([
                [
                    'timestamp' => Carbon::parse('2026-06-26 07:00:00', 'UTC')->timestamp,
                    'gmtoffset' => 0,
                    'datetime' => '2026-06-26 07:00:00',
                    'open' => 100.25,
                    'high' => 102.00,
                    'low' => 99.75,
                    'close' => 101.50,
                    'volume' => 12345,
                ],
            ]),
        ]);

        $this->actingAs($admin)
            ->postJson('/admin/data/historical/sync')
            ->assertOk()
            ->assertJsonPath('message', 'EODHD historical intraday sync: 1 candle(s) loaded/updated.')
            ->assertJsonPath('requested_count', 1)
            ->assertJsonPath('stored_count', 1)
            ->assertJsonPath('skipped_count', 0)
            ->assertJsonPath('failed_count', 0)
            ->assertJsonPath('date_from', '2026-06-26')
            ->assertJsonPath('date_to', '2026-06-26')
            ->assertJsonPath('refresh.status', 'finished')
            ->assertJsonPath('intraday_backfill_settings.last_dispatched_at', '2026-06-26T18:00:00+02:00')
            ->assertJsonPath('intraday_backfill_settings.next_refresh_at', '2026-06-26T18:15:00+02:00');

        $this->assertDatabaseHas('stock_holding_intraday_candles', [
            'stock_holding_id' => $holding->id,
            'trading_date' => '2026-06-26',
            'interval' => '5m',
            'datetime' => '2026-06-26 07:00:00',
            'close' => '101.50000000',
            'source_key' => 'eodhd_intraday',
            'source_name' => 'EODHD intraday',
            'currency' => 'EUR',
        ]);
        $this->assertSame(3, StockHoldingIntradayCandle::query()->count());
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/intraday/AMES.XETRA')
            && $request['interval'] === '5m'
            && $request['fmt'] === 'json'
            && (int) $request['from'] === Carbon::parse('2026-06-26 00:00:00', 'Europe/Vienna')->utc()->timestamp
            && (int) $request['to'] === Carbon::parse('2026-06-26 23:59:59', 'Europe/Vienna')->utc()->timestamp);
    }

    public function test_guest_cannot_sync_eodhd_historical_intraday_candles(): void
    {
        $this->postJson('/admin/data/historical/sync')->assertUnauthorized();
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function createIntradayCandle(StockHolding $holding, string $tradingDate): StockHoldingIntradayCandle
    {
        return StockHoldingIntradayCandle::query()->create([
            'stock_holding_id' => $holding->id,
            'trading_date' => $tradingDate,
            'interval' => '5m',
            'as_of' => Carbon::parse("{$tradingDate} 09:00:00", 'UTC'),
            'close' => '100.00000000',
            'currency' => $holding->currency,
            'source_key' => 'eodhd_intraday',
            'source_name' => 'EODHD intraday',
        ]);
    }
}
