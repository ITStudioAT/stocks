<?php

namespace Tests\Feature;

use App\Jobs\BackfillMissingStockHoldingIntradayCandles;
use App\Jobs\RefreshDepotHoldingPrices;
use App\Models\AppConfig;
use App\Models\IndexWatchItem;
use App\Models\IndexWatchItemPrice;
use App\Models\StockHolding;
use App\Models\StockHoldingIntradayReloadRun;
use App\Models\StockPriceRefreshRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PriceRefreshSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.eodhd.key' => null]);
    }

    public function test_admin_can_view_and_update_the_global_price_refresh_schedule(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        Queue::fake();
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-03 10:00:00', 'Europe/Vienna'));
        StockHolding::factory()->create([
            'symbol' => 'AAPL',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Vienna',
        ]);
        Http::fake([
            'eodhd.com/api/real-time/AAPL.US*' => Http::response([
                [
                    'code' => 'AAPL.US',
                    'timestamp' => Carbon::parse('2026-06-03 08:00:00', 'UTC')->timestamp,
                    'close' => 301.12,
                ],
            ]),
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/price-refresh-settings')
            ->assertOk()
            ->assertJsonPath('price_refresh_settings.trading_interval_minutes', 20)
            ->assertJsonPath('price_refresh_settings.trading_starts_before_minutes', 0)
            ->assertJsonPath('price_refresh_settings.trading_ends_after_minutes', 0)
            ->assertJsonPath('price_refresh_settings.trading_start_time', null)
            ->assertJsonPath('price_refresh_settings.trading_end_time', null)
            ->assertJsonPath('price_refresh_settings.closed_refresh_enabled', true)
            ->assertJsonPath('price_refresh_settings.closed_interval_minutes', 60)
            ->assertJsonPath('price_refresh_settings.status', 'waiting')
            ->assertJsonPath('price_refresh_settings.is_trading_time', true)
            ->assertJsonPath('price_refresh_settings.current_interval_minutes', 20)
            ->assertJsonPath('refresh', null);

        $this->actingAs($admin)
            ->patchJson('/admin/price-refresh-settings', [
                'trading_interval_minutes' => 15,
                'trading_starts_before_minutes' => 5,
                'trading_ends_after_minutes' => 10,
                'trading_start_time' => '09:15',
                'trading_end_time' => '17:30',
                'closed_refresh_enabled' => false,
                'closed_interval_minutes' => 45,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Price refresh schedule updated.')
            ->assertJsonPath('price_refresh_settings.trading_interval_minutes', 15)
            ->assertJsonPath('price_refresh_settings.trading_starts_before_minutes', 5)
            ->assertJsonPath('price_refresh_settings.trading_ends_after_minutes', 10)
            ->assertJsonPath('price_refresh_settings.trading_start_time', '09:15')
            ->assertJsonPath('price_refresh_settings.trading_end_time', '17:30')
            ->assertJsonPath('price_refresh_settings.closed_refresh_enabled', false)
            ->assertJsonPath('price_refresh_settings.closed_interval_minutes', 45)
            ->assertJsonPath('price_refresh_settings.current_interval_minutes', 15)
            ->assertJsonPath('price_refresh_settings.status', 'updating')
            ->assertJsonPath('refresh.status', 'queued')
            ->assertJsonPath('refresh.total', 1);

        Queue::assertPushedTimes(RefreshDepotHoldingPrices::class, 1);

        $config = AppConfig::query()->where('key', 'price_refresh.schedule')->firstOrFail();

        $this->assertSame(15, $config->value['trading_interval_minutes']);
        $this->assertSame(5, $config->value['trading_starts_before_minutes']);
        $this->assertSame(10, $config->value['trading_ends_after_minutes']);
        $this->assertSame('09:15', $config->value['trading_start_time']);
        $this->assertSame('17:30', $config->value['trading_end_time']);
        $this->assertFalse($config->value['closed_refresh_enabled']);
        $this->assertSame(45, $config->value['closed_interval_minutes']);
    }

    public function test_updating_non_current_interval_does_not_dispatch_an_immediate_refresh(): void
    {
        Queue::fake();
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-03 10:00:00', 'Europe/Vienna'));
        StockHolding::factory()->create([
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Vienna',
        ]);

        $this->actingAs($admin)
            ->patchJson('/admin/price-refresh-settings', [
                'trading_interval_minutes' => 20,
                'trading_starts_before_minutes' => 0,
                'trading_ends_after_minutes' => 0,
                'closed_refresh_enabled' => true,
                'closed_interval_minutes' => 45,
            ])
            ->assertOk()
            ->assertJsonPath('refresh', null);

        Queue::assertNothingPushed();
    }

    public function test_admin_can_update_index_price_refresh_schedule_separately(): void
    {
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-03 10:00:00', 'Europe/Vienna'));
        IndexWatchItem::factory()->create([
            'trading_times' => 'Monday-Friday 08:55-17:35 Europe/Vienna',
        ]);
        AppConfig::query()->create([
            'key' => 'price_refresh.schedule',
            'value' => [
                'trading_interval_minutes' => 10,
                'trading_starts_before_minutes' => 60,
                'trading_ends_after_minutes' => 60,
                'closed_refresh_enabled' => false,
                'closed_interval_minutes' => 120,
                'last_refreshed_at' => null,
                'next_refresh_at' => null,
            ],
        ]);

        $this->actingAs($admin)
            ->patchJson('/admin/index-price-refresh-settings', [
                'trading_interval_minutes' => 30,
                'trading_starts_before_minutes' => 15,
                'trading_ends_after_minutes' => 20,
                'closed_refresh_enabled' => true,
                'closed_interval_minutes' => 90,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Index price refresh schedule updated.')
            ->assertJsonPath('index_price_refresh_settings.trading_interval_minutes', 30)
            ->assertJsonPath('index_price_refresh_settings.trading_starts_before_minutes', 15)
            ->assertJsonPath('index_price_refresh_settings.trading_ends_after_minutes', 20)
            ->assertJsonPath('index_price_refresh_settings.closed_refresh_enabled', true)
            ->assertJsonPath('index_price_refresh_settings.closed_interval_minutes', 90)
            ->assertJsonPath('index_price_refresh_settings.current_interval_minutes', 30);

        $stockConfig = AppConfig::query()->where('key', 'price_refresh.schedule')->firstOrFail();
        $indexConfig = AppConfig::query()->where('key', 'index_price_refresh.schedule')->firstOrFail();

        $this->assertSame(10, $stockConfig->value['trading_interval_minutes']);
        $this->assertSame(30, $indexConfig->value['trading_interval_minutes']);
        $this->assertSame(15, $indexConfig->value['trading_starts_before_minutes']);
        $this->assertSame(20, $indexConfig->value['trading_ends_after_minutes']);
        $this->assertTrue($indexConfig->value['closed_refresh_enabled']);
        $this->assertSame(90, $indexConfig->value['closed_interval_minutes']);
    }

    public function test_index_outside_interval_ignores_full_day_trading_windows_when_regular_exchanges_are_closed(): void
    {
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-04 22:47:00', 'Europe/Vienna'));
        IndexWatchItem::factory()->create([
            'symbol' => 'ATX',
            'trading_times' => 'Monday-Friday 08:55:00-17:35:00 Europe/Vienna',
        ]);
        IndexWatchItem::factory()->create([
            'symbol' => 'GDAXI',
            'trading_times' => 'Monday-Friday 00:00:00-23:59:00 Europe/London',
        ]);
        AppConfig::query()->create([
            'key' => 'index_price_refresh.schedule',
            'value' => [
                'trading_interval_minutes' => 20,
                'trading_starts_before_minutes' => 0,
                'trading_ends_after_minutes' => 0,
                'closed_refresh_enabled' => true,
                'closed_interval_minutes' => 120,
                'last_refreshed_at' => '2026-06-04T22:42:12+02:00',
                'next_refresh_at' => '2026-06-04T23:02:12+02:00',
            ],
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/price-refresh-settings')
            ->assertOk()
            ->assertJsonPath('index_price_refresh_settings.is_trading_time', false)
            ->assertJsonPath('index_price_refresh_settings.current_interval_minutes', 20)
            ->assertJsonPath('index_price_refresh_settings.next_refresh_at', '2026-06-05T08:55:00+02:00');
    }

    public function test_index_next_refresh_waits_for_the_next_trading_day_when_exchange_is_closed(): void
    {
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-06 02:32:12', 'Europe/Vienna'));
        IndexWatchItem::factory()->create([
            'symbol' => 'ATX',
            'trading_times' => 'Monday-Friday 08:55:00-17:35:00 Europe/Vienna',
        ]);
        AppConfig::query()->create([
            'key' => 'index_price_refresh.schedule',
            'value' => [
                'trading_interval_minutes' => 20,
                'trading_starts_before_minutes' => 20,
                'trading_ends_after_minutes' => 20,
                'closed_refresh_enabled' => true,
                'closed_interval_minutes' => 120,
                'last_refreshed_at' => '2026-06-06T00:32:12+02:00',
                'next_refresh_at' => '2026-06-06T02:32:12+02:00',
            ],
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/price-refresh-settings')
            ->assertOk()
            ->assertJsonPath('index_price_refresh_settings.is_trading_time', false)
            ->assertJsonPath('index_price_refresh_settings.current_interval_minutes', 20)
            ->assertJsonPath('index_price_refresh_settings.next_refresh_at', '2026-06-08T08:35:00+02:00');
    }

    public function test_trading_refresh_window_can_start_before_market_open(): void
    {
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-03 08:55:00', 'Europe/Vienna'));
        StockHolding::factory()->create([
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Vienna',
        ]);
        AppConfig::query()->create([
            'key' => 'price_refresh.schedule',
            'value' => [
                'trading_interval_minutes' => 20,
                'trading_starts_before_minutes' => 5,
                'trading_ends_after_minutes' => 0,
                'closed_refresh_enabled' => true,
                'closed_interval_minutes' => 60,
                'last_refreshed_at' => null,
                'next_refresh_at' => null,
            ],
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/price-refresh-settings')
            ->assertOk()
            ->assertJsonPath('price_refresh_settings.is_trading_time', true)
            ->assertJsonPath('price_refresh_settings.current_interval_minutes', 20);
    }

    public function test_due_price_refresh_command_skips_outside_trading_when_disabled(): void
    {
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-06-03 18:00:00', 'Europe/Vienna'));
        StockHolding::factory()->create([
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Vienna',
        ]);
        AppConfig::query()->create([
            'key' => 'price_refresh.schedule',
            'value' => [
                'trading_interval_minutes' => 20,
                'trading_starts_before_minutes' => 0,
                'trading_ends_after_minutes' => 0,
                'closed_refresh_enabled' => false,
                'closed_interval_minutes' => 60,
                'last_refreshed_at' => null,
                'next_refresh_at' => now()->subMinute()->toIso8601String(),
            ],
        ]);

        $this->artisan('price-refresh:dispatch-due')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function test_next_refresh_uses_next_trading_window_when_outside_trading_is_disabled(): void
    {
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-04 19:13:00', 'Europe/Vienna'));
        StockHolding::factory()->create([
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Vienna',
        ]);
        AppConfig::query()->create([
            'key' => 'price_refresh.schedule',
            'value' => [
                'trading_interval_minutes' => 10,
                'trading_starts_before_minutes' => 60,
                'trading_ends_after_minutes' => 60,
                'closed_refresh_enabled' => false,
                'closed_interval_minutes' => 120,
                'last_refreshed_at' => '2026-06-04T19:13:00+02:00',
                'next_refresh_at' => '2026-06-04T21:13:00+02:00',
            ],
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/price-refresh-settings')
            ->assertOk()
            ->assertJsonPath('price_refresh_settings.is_trading_time', false)
            ->assertJsonPath('price_refresh_settings.closed_refresh_enabled', false)
            ->assertJsonPath('price_refresh_settings.next_refresh_at', '2026-06-05T08:00:00+02:00');
    }

    public function test_stock_next_refresh_ignores_index_trading_windows(): void
    {
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-04 22:47:00', 'Europe/Vienna'));
        StockHolding::factory()->create([
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
        ]);
        IndexWatchItem::factory()->create([
            'symbol' => 'GDAXI',
            'trading_times' => 'Monday-Friday 00:00:00-23:59:00 Europe/London',
        ]);
        AppConfig::query()->create([
            'key' => 'price_refresh.schedule',
            'value' => [
                'trading_interval_minutes' => 15,
                'trading_starts_before_minutes' => 60,
                'trading_ends_after_minutes' => 60,
                'closed_refresh_enabled' => false,
                'closed_interval_minutes' => 120,
                'last_refreshed_at' => '2026-06-04T22:47:00+02:00',
                'next_refresh_at' => '2026-06-04T23:02:00+02:00',
            ],
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/price-refresh-settings')
            ->assertOk()
            ->assertJsonPath('price_refresh_settings.is_trading_time', false)
            ->assertJsonPath('price_refresh_settings.next_refresh_at', '2026-06-05T08:00:00+02:00');
    }

    public function test_due_price_refresh_command_runs_the_watchlist_realtime_sync(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        Queue::fake();
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-03 18:00:00', 'Europe/Vienna'));
        StockHolding::factory()->create([
            'symbol' => 'AAPL',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Vienna',
        ]);
        StockHolding::factory()->create([
            'symbol' => 'MSFT',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Vienna',
        ]);
        Http::fake([
            'eodhd.com/api/real-time/*' => Http::response([
                [
                    'code' => 'AAPL.US',
                    'timestamp' => Carbon::parse('2026-06-03 16:00:00', 'UTC')->timestamp,
                    'close' => 301.12,
                ],
                [
                    'code' => 'MSFT.US',
                    'timestamp' => Carbon::parse('2026-06-03 16:00:00', 'UTC')->timestamp,
                    'close' => 302.34,
                ],
            ]),
        ]);
        AppConfig::query()->create([
            'key' => 'price_refresh.schedule',
            'value' => [
                'trading_interval_minutes' => 20,
                'closed_interval_minutes' => 60,
                'last_refreshed_at' => null,
                'next_refresh_at' => now()->subMinute()->toIso8601String(),
            ],
        ]);

        $this->artisan('price-refresh:dispatch-due')
            ->assertExitCode(0);

        Queue::assertPushedTimes(RefreshDepotHoldingPrices::class, 1);
        $this->assertDatabaseHas('stock_price_refresh_runs', [
            'status' => 'queued',
            'total_count' => 2,
            'processed_count' => 0,
            'success_count' => 0,
        ]);
        $config = AppConfig::query()->where('key', 'price_refresh.schedule')->firstOrFail();

        $this->assertSame('2026-06-03T18:00:00+02:00', $config->value['last_refreshed_at']);
        $this->assertSame('2026-06-03T19:00:00+02:00', $config->value['next_refresh_at']);

        $this->actingAs($admin)
            ->getJson('/admin/price-refresh-settings')
            ->assertOk()
            ->assertJsonPath('price_refresh_settings.status', 'updating')
            ->assertJsonPath('refresh.status', 'queued');
    }

    public function test_due_price_refresh_command_only_dispatches_realtime_price_refreshes(): void
    {
        Queue::fake();
        Cache::flush();
        config(['services.eodhd.key' => 'test-token']);
        $this->travelTo(Carbon::parse('2026-06-04 21:49:00', 'Europe/Berlin'));
        Http::fake([
            'eodhd.com/api/real-time/AMES.XETRA*' => Http::response([
                [
                    'code' => 'AMES.XETRA',
                    'timestamp' => Carbon::parse('2026-06-04 19:49:00', 'UTC')->timestamp,
                    'close' => 301.12,
                ],
            ]),
            'eodhd.com/api/exchange-details/XETRA*' => Http::response([
                'Name' => 'XETRA Stock Exchange',
                'Code' => 'XETRA',
                'OperatingMIC' => 'XETR',
                'Timezone' => 'Europe/Berlin',
                'TradingHours' => [
                    'Open' => '09:00:00',
                    'Close' => '17:30:00',
                    'WorkingDays' => 'Mon,Tue,Wed,Thu,Fri',
                ],
            ]),
        ]);
        StockHolding::factory()->create([
            'symbol' => 'AMES',
            'exchange' => 'Xetra',
            'mic_code' => 'XETR',
            'country' => 'Germany',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
        ]);
        AppConfig::query()->create([
            'key' => 'price_refresh.schedule',
            'value' => [
                'trading_interval_minutes' => 20,
                'closed_refresh_enabled' => true,
                'closed_interval_minutes' => 60,
                'last_refreshed_at' => null,
                'next_refresh_at' => now()->subMinute()->toIso8601String(),
            ],
        ]);

        $this->artisan('price-refresh:dispatch-due')
            ->expectsOutput('Queued 1 watch-list realtime sync(s).')
            ->assertExitCode(0);

        Queue::assertPushedTimes(RefreshDepotHoldingPrices::class, 1);
    }

    public function test_index_schedule_reports_updating_for_combined_price_refreshes(): void
    {
        $admin = $this->adminUser();
        StockHolding::factory()->create();
        IndexWatchItem::factory()->create();

        StockPriceRefreshRun::query()->create([
            'id' => 'stock-only-refresh',
            'status' => 'queued',
            'total_count' => 1,
            'started_at' => now(),
            'finished_at' => null,
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/price-refresh-settings')
            ->assertOk()
            ->assertJsonPath('price_refresh_settings.status', 'updating')
            ->assertJsonPath('index_price_refresh_settings.status', 'waiting');

        StockPriceRefreshRun::query()->whereKey('stock-only-refresh')->update([
            'status' => 'finished',
            'finished_at' => now(),
        ]);

        StockPriceRefreshRun::query()->create([
            'id' => 'combined-refresh',
            'status' => 'queued',
            'total_count' => 2,
            'started_at' => now(),
            'finished_at' => null,
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/price-refresh-settings')
            ->assertOk()
            ->assertJsonPath('price_refresh_settings.status', 'updating')
            ->assertJsonPath('index_price_refresh_settings.status', 'updating')
            ->assertJsonPath('index_price_refresh_settings.status_label', 'Updating prices');
    }

    public function test_due_price_refresh_command_ignores_the_legacy_index_schedule(): void
    {
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-06-03 10:00:00', 'Europe/Vienna'));
        StockHolding::factory()->create([
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Vienna',
        ]);
        IndexWatchItem::factory()->create([
            'symbol' => 'ATX',
            'trading_times' => 'Monday-Friday 08:55-17:35 Europe/Vienna',
        ]);
        AppConfig::query()->create([
            'key' => 'price_refresh.schedule',
            'value' => [
                'trading_interval_minutes' => 20,
                'closed_interval_minutes' => 60,
                'last_refreshed_at' => '2026-06-03T09:50:00+02:00',
                'next_refresh_at' => '2026-06-03T10:10:00+02:00',
            ],
        ]);
        AppConfig::query()->create([
            'key' => 'index_price_refresh.schedule',
            'value' => [
                'trading_interval_minutes' => 30,
                'trading_starts_before_minutes' => 0,
                'trading_ends_after_minutes' => 0,
                'closed_refresh_enabled' => true,
                'closed_interval_minutes' => 90,
                'last_refreshed_at' => null,
                'next_refresh_at' => now()->subMinute()->toIso8601String(),
            ],
        ]);

        $this->artisan('price-refresh:dispatch-due')
            ->assertExitCode(0);

        Queue::assertNothingPushed();

        $stockConfig = AppConfig::query()->where('key', 'price_refresh.schedule')->firstOrFail();
        $indexConfig = AppConfig::query()->where('key', 'index_price_refresh.schedule')->firstOrFail();

        $this->assertSame('2026-06-03T09:50:00+02:00', $stockConfig->value['last_refreshed_at']);
        $this->assertSame('2026-06-03T10:10:00+02:00', $stockConfig->value['next_refresh_at']);
        $this->assertNull($indexConfig->value['last_refreshed_at']);
        $this->assertSame(now()->subMinute()->toIso8601String(), $indexConfig->value['next_refresh_at']);
    }

    public function test_dashboard_poll_does_not_dispatch_an_overdue_index_refresh(): void
    {
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-08 12:58:00', 'Europe/Vienna'));
        IndexWatchItem::factory()->create([
            'symbol' => 'ATX',
            'trading_times' => 'Monday-Friday 08:55:00-17:35:00 Europe/Vienna',
        ]);
        AppConfig::query()->create([
            'key' => 'index_price_refresh.schedule',
            'value' => [
                'trading_interval_minutes' => 20,
                'trading_starts_before_minutes' => 0,
                'trading_ends_after_minutes' => 0,
                'closed_refresh_enabled' => true,
                'closed_interval_minutes' => 60,
                'last_refreshed_at' => '2026-06-08T11:29:00+02:00',
                'next_refresh_at' => '2026-06-08T11:49:00+02:00',
            ],
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/depots/active')
            ->assertOk()
            ->assertJsonPath('index_price_refresh_settings.last_refreshed_at', '2026-06-08T11:29:00+02:00')
            ->assertJsonPath('index_price_refresh_settings.next_refresh_at', '2026-06-08T11:49:00+02:00');

        $config = AppConfig::query()->where('key', 'index_price_refresh.schedule')->firstOrFail();

        $this->assertSame('2026-06-08T11:29:00+02:00', $config->value['last_refreshed_at']);
        $this->assertSame('2026-06-08T11:49:00+02:00', $config->value['next_refresh_at']);
    }

    public function test_due_price_refresh_command_recalculates_the_next_refresh_from_current_settings(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-06-03 10:20:00', 'Europe/Vienna'));
        StockHolding::factory()->create([
            'symbol' => 'AAPL',
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Vienna',
        ]);
        Http::fake([
            'eodhd.com/api/real-time/AAPL.US*' => Http::response([
                [
                    'code' => 'AAPL.US',
                    'timestamp' => Carbon::parse('2026-06-03 08:20:00', 'UTC')->timestamp,
                    'close' => 301.12,
                ],
            ]),
        ]);
        AppConfig::query()->create([
            'key' => 'price_refresh.schedule',
            'value' => [
                'trading_interval_minutes' => 15,
                'trading_starts_before_minutes' => 0,
                'trading_ends_after_minutes' => 0,
                'closed_refresh_enabled' => true,
                'closed_interval_minutes' => 120,
                'last_refreshed_at' => '2026-06-03T10:00:00+02:00',
                'next_refresh_at' => '2026-06-03T12:00:00+02:00',
            ],
        ]);

        $this->artisan('price-refresh:dispatch-due')
            ->assertExitCode(0);

        Queue::assertPushedTimes(RefreshDepotHoldingPrices::class, 1);
        $config = AppConfig::query()->where('key', 'price_refresh.schedule')->firstOrFail();

        $this->assertSame('2026-06-03T10:20:00+02:00', $config->value['last_refreshed_at']);
        $this->assertSame('2026-06-03T10:35:00+02:00', $config->value['next_refresh_at']);
    }

    public function test_admin_can_update_intraday_backfill_schedule_and_queue_missing_backfill(): void
    {
        Queue::fake();
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-12 12:00:00', 'Europe/Vienna'));
        StockHolding::factory()->count(2)->create();

        $this->actingAs($admin)
            ->getJson('/admin/price-refresh-settings')
            ->assertOk()
            ->assertJsonPath('intraday_backfill_settings.daily_time', '18:30')
            ->assertJsonPath('intraday_backfill_settings.interval_minutes', 15)
            ->assertJsonPath('intraday_backfill_settings.timezone', 'Europe/Vienna')
            ->assertJsonPath('intraday_backfill_settings.status', 'waiting');

        $this->actingAs($admin)
            ->patchJson('/admin/intraday-backfill-settings', [
                'daily_time' => '21:15',
                'interval_minutes' => 30,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Intraday backfill schedule updated.')
            ->assertJsonPath('intraday_backfill_settings.daily_time', '21:15')
            ->assertJsonPath('intraday_backfill_settings.interval_minutes', 30);

        $response = $this->actingAs($admin)
            ->postJson('/admin/intraday-backfill/run')
            ->assertAccepted()
            ->assertJsonPath('message', 'Missing intraday backfill queued.')
            ->assertJsonPath('intraday_backfill_refresh.status', 'queued')
            ->assertJsonPath('intraday_backfill_refresh.total', 2)
            ->assertJsonPath('intraday_backfill_refresh.date_to', '2026-06-12');

        $refreshId = $response->json('intraday_backfill_refresh.refresh_id');

        Queue::assertPushed(BackfillMissingStockHoldingIntradayCandles::class, fn (BackfillMissingStockHoldingIntradayCandles $job): bool => $job->refreshId === $refreshId);

        $config = AppConfig::query()->where('key', 'intraday_candle_backfill.schedule')->firstOrFail();

        $this->assertSame('21:15', $config->value['daily_time']);
        $this->assertSame(30, $config->value['interval_minutes']);
        $this->assertSame('2026-06-12', $config->value['last_dispatched_on']);
    }

    public function test_admin_can_update_end_of_day_data_update_schedule_separately(): void
    {
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-12 12:00:00', 'Europe/Vienna'));
        AppConfig::query()->create([
            'key' => 'intraday_candle_backfill.schedule',
            'value' => [
                'daily_time' => '18:30',
                'interval_minutes' => 15,
                'timezone' => 'Europe/Vienna',
                'last_dispatched_at' => null,
                'last_dispatched_on' => null,
                'next_refresh_at' => null,
            ],
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/price-refresh-settings')
            ->assertOk()
            ->assertJsonPath('end_of_day_data_update_settings.daily_time', '18:30')
            ->assertJsonPath('end_of_day_data_update_settings.interval_minutes', 15)
            ->assertJsonPath('end_of_day_data_update_settings.timezone', 'Europe/Vienna')
            ->assertJsonPath('end_of_day_data_update_settings.status', 'waiting');

        $this->actingAs($admin)
            ->patchJson('/admin/end-of-day-data-update-settings', [
                'daily_time' => '19:20',
                'interval_minutes' => 45,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'End-of-day data update schedule updated.')
            ->assertJsonPath('end_of_day_data_update_settings.daily_time', '19:20')
            ->assertJsonPath('end_of_day_data_update_settings.interval_minutes', 45)
            ->assertJsonPath('end_of_day_data_update_settings.next_refresh_at', '2026-06-12T19:20:00+02:00');

        $endOfDayConfig = AppConfig::query()->where('key', 'end_of_day_data_update.schedule')->firstOrFail();
        $historicalDataConfig = AppConfig::query()->where('key', 'intraday_candle_backfill.schedule')->firstOrFail();

        $this->assertSame('19:20', $endOfDayConfig->value['daily_time']);
        $this->assertSame(45, $endOfDayConfig->value['interval_minutes']);
        $this->assertSame('18:30', $historicalDataConfig->value['daily_time']);
        $this->assertSame(15, $historicalDataConfig->value['interval_minutes']);
    }

    public function test_admin_can_update_index_data_update_schedule_separately(): void
    {
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-12 12:00:00', 'Europe/Vienna'));
        $index = IndexWatchItem::factory()->create();
        IndexWatchItemPrice::query()->create([
            'index_watch_item_id' => $index->id,
            'trading_date' => '2026-06-11',
            'actual_price' => '6116.52980000',
            'actual_price_as_of' => '2026-06-11 16:00:00',
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/price-refresh-settings')
            ->assertOk()
            ->assertJsonPath('index_data_update_settings.weekday', 1)
            ->assertJsonPath('index_data_update_settings.weekday_label', 'Monday')
            ->assertJsonPath('index_data_update_settings.daily_time', '02:00')
            ->assertJsonPath('index_data_update_settings.timezone', 'Europe/Vienna')
            ->assertJsonPath('index_data_update_settings.table_name', 'index_watch_item_prices')
            ->assertJsonPath('index_data_update_settings.table_row_count', 1)
            ->assertJsonPath('index_data_update_settings.latest_table_update_at', '2026-06-12T12:00:00+02:00')
            ->assertJsonPath('index_data_update_settings.status', 'waiting');

        $this->actingAs($admin)
            ->patchJson('/admin/index-data-update-settings', [
                'weekday' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Indices data update schedule updated.')
            ->assertJsonPath('index_data_update_settings.weekday', 3)
            ->assertJsonPath('index_data_update_settings.weekday_label', 'Wednesday')
            ->assertJsonPath('index_data_update_settings.daily_time', '02:00')
            ->assertJsonPath('index_data_update_settings.next_refresh_at', '2026-06-17T02:00:00+02:00');

        $indexDataConfig = AppConfig::query()->where('key', 'index_data_update.schedule')->firstOrFail();

        $this->assertSame(3, $indexDataConfig->value['weekday']);
        $this->assertSame('02:00', $indexDataConfig->value['daily_time']);
    }

    public function test_admin_can_run_index_data_sync(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-17 02:00:00', 'Europe/Vienna'));
        $index = IndexWatchItem::factory()->create([
            'symbol' => 'ATX',
            'exchange' => 'XETRA',
            'mic_code' => 'XETR',
            'currency' => 'EUR',
        ]);
        Http::fake([
            'eodhd.com/api/real-time/ATX.XETRA*' => Http::response([
                'code' => 'ATX.XETRA',
                'timestamp' => Carbon::parse('2026-06-17 00:01:00', 'UTC')->timestamp,
                'open' => 6100.12,
                'close' => 6116.53,
                'previousClose' => 6095.00,
                'change_p' => 0.35,
                'currency' => 'EUR',
            ]),
            'eodhd.com/api/exchange-details/XETRA*' => Http::response([
                'Name' => 'XETRA Stock Exchange',
                'Code' => 'XETRA',
                'OperatingMIC' => 'XETR',
                'Country' => 'Germany',
                'Currency' => 'EUR',
                'Timezone' => 'Europe/Berlin',
                'TradingHours' => [
                    'Open' => '09:00:00',
                    'Close' => '17:30:00',
                    'WorkingDays' => 'Mon,Tue,Wed,Thu,Fri',
                ],
            ]),
        ]);

        $this->actingAs($admin)
            ->postJson('/admin/data/indices/sync')
            ->assertOk()
            ->assertJsonPath('message', 'EODHD indices sync: 1 index(es) refreshed.')
            ->assertJsonPath('requested_count', 1)
            ->assertJsonPath('refreshed_count', 1)
            ->assertJsonPath('failed_count', 0)
            ->assertJsonPath('index_data_update_settings.last_dispatched_at', '2026-06-17T02:00:00+02:00')
            ->assertJsonPath('index_data_update_settings.table_name', 'index_watch_item_prices')
            ->assertJsonPath('index_data_update_settings.table_row_count', 1)
            ->assertJsonPath('index_data_update_settings.latest_table_update_at', '2026-06-17T02:00:00+02:00');

        $storedPrice = IndexWatchItemPrice::query()
            ->where('index_watch_item_id', $index->id)
            ->whereDate('trading_date', '2026-06-17')
            ->firstOrFail();

        $this->assertSame('6116.53000000', (string) $storedPrice->actual_price);
        $this->assertSame('6095.00000000', (string) $storedPrice->last_price);
    }

    public function test_due_intraday_backfill_command_retries_after_interval_when_historical_data_is_incomplete(): void
    {
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-06-12 18:31:00', 'Europe/Vienna'));
        StockHolding::factory()->create();
        AppConfig::query()->create([
            'key' => 'intraday_candle_backfill.schedule',
            'value' => [
                'daily_time' => '18:30',
                'timezone' => 'Europe/Vienna',
                'last_dispatched_at' => null,
                'last_dispatched_on' => null,
                'next_refresh_at' => '2026-06-12T18:30:00+02:00',
            ],
        ]);

        $this->artisan('intraday-candles:dispatch-due')
            ->assertExitCode(0);

        Queue::assertPushedTimes(BackfillMissingStockHoldingIntradayCandles::class, 1);

        $this->artisan('intraday-candles:dispatch-due')
            ->assertExitCode(0);

        Queue::assertPushedTimes(BackfillMissingStockHoldingIntradayCandles::class, 1);

        $config = AppConfig::query()->where('key', 'intraday_candle_backfill.schedule')->firstOrFail();

        $this->assertSame('2026-06-12', $config->value['last_dispatched_on']);
        $this->assertSame('2026-06-12T18:46:00+02:00', $config->value['next_refresh_at']);

        StockHoldingIntradayReloadRun::query()
            ->where('status', 'queued')
            ->update([
                'status' => 'finished',
                'finished_at' => now(),
            ]);

        $this->travelTo(Carbon::parse('2026-06-12 18:46:00', 'Europe/Vienna'));

        $this->artisan('intraday-candles:dispatch-due')
            ->assertExitCode(0);

        Queue::assertPushedTimes(BackfillMissingStockHoldingIntradayCandles::class, 2);

        $config->refresh();

        $this->assertSame('2026-06-12T19:01:00+02:00', $config->value['next_refresh_at']);
    }

    public function test_due_intraday_backfill_command_does_not_dispatch_on_weekends(): void
    {
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-06-13 18:31:00', 'Europe/Vienna'));
        StockHolding::factory()->create();
        AppConfig::query()->create([
            'key' => 'intraday_candle_backfill.schedule',
            'value' => [
                'daily_time' => '18:30',
                'timezone' => 'Europe/Vienna',
                'last_dispatched_at' => null,
                'last_dispatched_on' => null,
                'next_refresh_at' => '2026-06-13T18:30:00+02:00',
            ],
        ]);

        $this->artisan('intraday-candles:dispatch-due')
            ->assertExitCode(0);

        Queue::assertNotPushed(BackfillMissingStockHoldingIntradayCandles::class);

        $config = AppConfig::query()->where('key', 'intraday_candle_backfill.schedule')->firstOrFail();

        $this->assertSame('2026-06-15T18:30:00+02:00', $config->value['next_refresh_at']);
    }

    public function test_intraday_backfill_status_returns_progress_payload(): void
    {
        $admin = $this->adminUser();
        $run = StockHoldingIntradayReloadRun::query()->create([
            'id' => 'intraday-missing-test',
            'stock_holding_id' => null,
            'status' => 'running',
            'total_count' => 2,
            'processed_count' => 1,
            'stored_count' => 25,
            'current' => 'Amundi IBEX 35',
            'date_from' => '2025-06-13',
            'date_to' => '2026-06-12',
            'started_at' => now(),
            'message' => 'Fetching data for Amundi IBEX 35: 2025-06-13 to 2026-06-12...',
        ]);

        $this->actingAs($admin)
            ->getJson("/admin/intraday-backfill/{$run->id}")
            ->assertOk()
            ->assertJsonPath('intraday_backfill_refresh.refresh_id', $run->id)
            ->assertJsonPath('intraday_backfill_refresh.status', 'running')
            ->assertJsonPath('intraday_backfill_refresh.step', '1/2')
            ->assertJsonPath('intraday_backfill_refresh.message', 'Fetching data for Amundi IBEX 35: 2025-06-13 to 2026-06-12...')
            ->assertJsonPath('intraday_backfill_refresh.stored_count', 25)
            ->assertJsonPath('intraday_backfill_settings.status', 'updating');
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
