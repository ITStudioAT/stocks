<?php

namespace Tests\Feature;

use App\Jobs\RefreshDepotHoldingPrices;
use App\Models\AppConfig;
use App\Models\StockHolding;
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
