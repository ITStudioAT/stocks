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
        $admin = $this->adminUser();
        $this->travelTo(Carbon::parse('2026-06-03 10:00:00', 'Europe/Vienna'));
        StockHolding::factory()->create([
            'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Vienna',
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/price-refresh-settings')
            ->assertOk()
            ->assertJsonPath('price_refresh_settings.trading_interval_minutes', 20)
            ->assertJsonPath('price_refresh_settings.closed_interval_minutes', 60)
            ->assertJsonPath('price_refresh_settings.status', 'waiting')
            ->assertJsonPath('price_refresh_settings.is_trading_time', true)
            ->assertJsonPath('price_refresh_settings.current_interval_minutes', 20);

        $this->actingAs($admin)
            ->patchJson('/admin/price-refresh-settings', [
                'trading_interval_minutes' => 15,
                'closed_interval_minutes' => 45,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Price refresh schedule updated.')
            ->assertJsonPath('price_refresh_settings.trading_interval_minutes', 15)
            ->assertJsonPath('price_refresh_settings.closed_interval_minutes', 45)
            ->assertJsonPath('price_refresh_settings.current_interval_minutes', 15);

        $config = AppConfig::query()->where('key', 'price_refresh.schedule')->firstOrFail();

        $this->assertSame(15, $config->value['trading_interval_minutes']);
        $this->assertSame(45, $config->value['closed_interval_minutes']);
    }

    public function test_due_price_refresh_command_dispatches_the_watchlist_refresh_job(): void
    {
        Queue::fake();
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
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
