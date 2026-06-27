<?php

namespace Tests\Feature;

use App\Models\StockHolding;
use App\Models\StockRealtimePrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminDataRealtimeSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_sync_eodhd_realtime_quotes_in_one_batch_request(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        $this->travelTo(Carbon::parse('2026-06-05 12:05:00', 'Europe/Vienna'));
        $admin = $this->adminUser();
        $ames = StockHolding::factory()->create([
            'symbol' => 'AMES',
            'name' => 'Amundi IBEX 35 UCITS ETF',
            'exchange' => 'XETRA',
            'mic_code' => 'XETR',
            'currency' => 'EUR',
            'latest_price' => '10.000000',
        ]);
        $leer = StockHolding::factory()->create([
            'symbol' => 'LEER',
            'name' => 'Leerink',
            'exchange' => 'XETRA',
            'mic_code' => 'XETR',
            'currency' => 'EUR',
            'latest_price' => '20.000000',
        ]);

        Http::fake([
            'eodhd.com/api/real-time/AMES.XETRA*' => Http::response([
                [
                    'code' => 'AMES.XETRA',
                    'timestamp' => Carbon::parse('2026-06-05 10:00:00', 'UTC')->timestamp,
                    'close' => 10.25,
                ],
                [
                    'code' => 'LEER.XETRA',
                    'timestamp' => Carbon::parse('2026-06-05 10:01:00', 'UTC')->timestamp,
                    'close' => 20.75,
                ],
            ]),
        ]);

        $this->actingAs($admin)
            ->postJson('/admin/data/realtime/sync')
            ->assertOk()
            ->assertJsonPath('requested_count', 2)
            ->assertJsonPath('stored_count', 2)
            ->assertJsonPath('unchanged_count', 0)
            ->assertJsonPath('updated_count', 2)
            ->assertJsonPath('failed_count', 0)
            ->assertJsonPath('price_refresh_settings.last_refreshed_at', '2026-06-05T12:05:00+02:00')
            ->assertJsonPath('price_refresh_settings.next_refresh_at', '2026-06-05T12:25:00+02:00');

        $this->assertDatabaseHas('stock_realtime_prices', [
            'stock_holding_id' => $ames->id,
            'source_key' => 'eodhd_realtime',
            'symbol' => 'AMES',
            'price' => '10.25000000',
        ]);
        $this->assertDatabaseHas('stock_realtime_prices', [
            'stock_holding_id' => $leer->id,
            'source_key' => 'eodhd_realtime',
            'symbol' => 'LEER',
            'price' => '20.75000000',
        ]);

        $this->assertNotNull($ames->refresh()->latest_realtime_price_id);
        $this->assertNotNull($leer->refresh()->latest_realtime_price_id);
        $this->assertSame(2, StockRealtimePrice::query()->count());

        $this->actingAs($admin)
            ->postJson('/admin/data/realtime/sync')
            ->assertOk()
            ->assertJsonPath('message', 'EODHD sync: 0 record(s) created, 0 record(s) updated.')
            ->assertJsonPath('requested_count', 2)
            ->assertJsonPath('stored_count', 0)
            ->assertJsonPath('unchanged_count', 2)
            ->assertJsonPath('updated_count', 0)
            ->assertJsonPath('failed_count', 0);

        $this->assertSame(2, StockRealtimePrice::query()->count());
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/real-time/AMES.XETRA')
            && $request['s'] === 'LEER.XETRA');
    }

    public function test_guest_cannot_sync_eodhd_realtime_quotes(): void
    {
        $this->postJson('/admin/data/realtime/sync')->assertUnauthorized();
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
