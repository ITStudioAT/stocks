<?php

namespace Tests\Feature;

use App\Jobs\RefreshDepotHoldingPrices;
use App\Models\AppConfig;
use App\Models\IndexWatchItem;
use App\Models\StockHolding;
use App\Models\StockPriceRefreshRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PriceRefreshSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_and_update_the_global_price_refresh_schedule(): void
    {
        Queue::fake();
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-03 10:00:00', 'Europe/Vienna'));
        StockHolding::factory()->create([
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Vienna',
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/price-refresh-settings')
            ->assertOk()
            ->assertJsonPath('price_refresh_settings.trading_interval_minutes', 20)
            ->assertJsonPath('price_refresh_settings.trading_starts_before_minutes', 0)
            ->assertJsonPath('price_refresh_settings.trading_ends_after_minutes', 0)
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
                'closed_refresh_enabled' => false,
                'closed_interval_minutes' => 45,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Price refresh schedule updated.')
            ->assertJsonPath('price_refresh_settings.trading_interval_minutes', 15)
            ->assertJsonPath('price_refresh_settings.trading_starts_before_minutes', 5)
            ->assertJsonPath('price_refresh_settings.trading_ends_after_minutes', 10)
            ->assertJsonPath('price_refresh_settings.closed_refresh_enabled', false)
            ->assertJsonPath('price_refresh_settings.closed_interval_minutes', 45)
            ->assertJsonPath('price_refresh_settings.current_interval_minutes', 15)
            ->assertJsonPath('price_refresh_settings.status', 'updating')
            ->assertJsonPath('refresh.status', 'queued')
            ->assertJsonPath('refresh.total', 1);

        Queue::assertPushed(RefreshDepotHoldingPrices::class, fn (RefreshDepotHoldingPrices $job): bool => $job->recipientUserId === $admin->id);

        $config = AppConfig::query()->where('key', 'price_refresh.schedule')->firstOrFail();

        $this->assertSame(15, $config->value['trading_interval_minutes']);
        $this->assertSame(5, $config->value['trading_starts_before_minutes']);
        $this->assertSame(10, $config->value['trading_ends_after_minutes']);
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
            ->assertJsonPath('index_price_refresh_settings.current_interval_minutes', 120);
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

    public function test_due_price_refresh_command_dispatches_the_watchlist_refresh_job(): void
    {
        Queue::fake();
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-03 18:00:00', 'Europe/Vienna'));
        StockHolding::factory()->create([
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Vienna',
        ]);
        StockHolding::factory()->create([
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Vienna',
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
        ]);
        $config = AppConfig::query()->where('key', 'price_refresh.schedule')->firstOrFail();

        $this->assertSame('2026-06-03T18:00:00+02:00', $config->value['last_refreshed_at']);
        $this->assertSame('2026-06-03T19:00:00+02:00', $config->value['next_refresh_at']);

        $this->actingAs($admin)
            ->getJson('/admin/price-refresh-settings')
            ->assertOk()
            ->assertJsonPath('price_refresh_settings.status', 'updating')
            ->assertJsonPath('refresh.status', 'queued')
            ->assertJsonPath('refresh.total', 2)
            ->assertJsonPath('refresh.step', '0/2');
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

    public function test_due_price_refresh_command_respects_separate_index_schedule(): void
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
        $this->assertSame('2026-06-03T10:00:00+02:00', $indexConfig->value['last_refreshed_at']);
        $this->assertSame('2026-06-03T10:30:00+02:00', $indexConfig->value['next_refresh_at']);
    }

    public function test_due_price_refresh_command_recalculates_the_next_refresh_from_current_settings(): void
    {
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-06-03 10:20:00', 'Europe/Vienna'));
        StockHolding::factory()->create([
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Vienna',
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

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
