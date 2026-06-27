<?php

namespace Tests\Feature;

use App\Jobs\ReloadEodhdExchanges;
use App\Models\EodhdExchange;
use App\Models\EodhdExchangeImportRun;
use App\Models\IndexWatchItem;
use App\Models\IndexWatchItemPrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminDataExchangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_stored_exchanges(): void
    {
        $admin = $this->adminUser();
        EodhdExchange::query()->create([
            'code' => 'XETRA',
            'detail_code' => 'XETR',
            'name' => 'XETRA',
            'country' => 'Germany',
            'currency' => 'EUR',
            'timezone' => 'Europe/Berlin',
            'trading_hours' => [
                'Open' => '09:00:00',
                'Close' => '15:00:00',
                'WorkingDays' => 'Mon, Tue, Wed, Thu, Fri',
            ],
            'holidays' => [
                '2026-01-01' => 'New Year',
            ],
            'synced_at' => now(),
        ]);
        EodhdExchange::query()->create([
            'code' => 'BA',
            'detail_code' => 'XBUE',
            'name' => 'Buenos Aires Exchange',
            'country' => 'Argentina',
            'currency' => 'ARS',
            'timezone' => 'America/Argentina/Buenos_Aires',
            'synced_at' => now(),
        ]);
        $index = IndexWatchItem::factory()->create();

        $this->travelTo(Carbon::parse('2026-06-27 01:48:00', 'Europe/Vienna'));
        IndexWatchItemPrice::query()->create([
            'index_watch_item_id' => $index->id,
            'trading_date' => '2026-06-26',
            'actual_price' => '6116.52980000',
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/data/exchanges')
            ->assertOk()
            ->assertJsonPath('exchanges.0.code', 'BA')
            ->assertJsonPath('exchanges.0.country', 'Argentina')
            ->assertJsonPath('exchanges.0.name', 'Buenos Aires Exchange')
            ->assertJsonPath('exchanges.1.code', 'XETRA')
            ->assertJsonPath('exchanges.1.country', 'Germany')
            ->assertJsonPath('exchanges.1.detail_code', 'XETR')
            ->assertJsonPath('exchanges.1.trading_hours.Open', '09:00:00')
            ->assertJsonPath('exchanges.1.holidays.2026-01-01', 'New Year')
            ->assertJsonPath('exchanges.1.updated_at', EodhdExchange::query()->where('code', 'XETRA')->firstOrFail()->updated_at?->toIso8601String())
            ->assertJsonPath('index_data_update_settings.latest_table_update_at', '2026-06-27T01:48:00+02:00');
    }

    public function test_admin_can_queue_exchange_reload(): void
    {
        Queue::fake();
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)
            ->postJson('/admin/data/exchanges/reload')
            ->assertAccepted()
            ->assertJsonPath('refresh.status', 'queued');

        $refreshId = $response->json('refresh.refresh_id');

        Queue::assertPushed(ReloadEodhdExchanges::class, fn (ReloadEodhdExchanges $job): bool => $job->refreshId === $refreshId);
        $this->assertDatabaseHas('eodhd_exchange_import_runs', [
            'id' => $refreshId,
            'status' => 'queued',
        ]);
    }

    public function test_admin_can_view_exchange_reload_status(): void
    {
        $admin = $this->adminUser();
        $run = EodhdExchangeImportRun::query()->create([
            'id' => 'exchanges-test',
            'status' => 'running',
            'total_count' => 2,
            'processed_count' => 1,
            'success_count' => 1,
            'current' => 'XETRA',
            'started_at' => now(),
        ]);

        $this->actingAs($admin)
            ->getJson("/admin/data/exchanges/reload/{$run->id}")
            ->assertOk()
            ->assertJsonPath('refresh.refresh_id', 'exchanges-test')
            ->assertJsonPath('refresh.status', 'running')
            ->assertJsonPath('refresh.step', '1/2')
            ->assertJsonPath('refresh.current', 'XETRA');
    }

    public function test_guest_cannot_reload_exchanges(): void
    {
        $this->postJson('/admin/data/exchanges/reload')->assertUnauthorized();
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
