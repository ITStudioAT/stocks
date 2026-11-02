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
        $this->travelTo(Carbon::parse('2026-06-05 12:05:00', 'Europe/Vienna'));
        $ames = StockHolding::factory()->create(['symbol' => 'AMES', 'exchange' => 'XETRA', 'mic_code' => 'XETR', 'currency' => 'EUR', 'latest_price' => 10]);
        $leer = StockHolding::factory()->create(['symbol' => 'LEER', 'exchange' => 'XETRA', 'mic_code' => 'XETR', 'currency' => 'EUR', 'latest_price' => 20]);
        $this->fakeEuropeanTrades([['holding' => $ames, 'price' => 10.25, 'at' => '2026-06-05 09:45:00'], ['holding' => $leer, 'price' => 20.75, 'at' => '2026-06-05 09:45:00']]);
        $this->actingAs($this->adminUser())->postJson('/admin/data/realtime/sync')->assertOk()
            ->assertJsonPath('requested_count', 2)->assertJsonPath('stored_count', 2)->assertJsonPath('updated_count', 2)
            ->assertJsonPath('failed_count', 0)->assertJsonPath('price_refresh_settings.last_refreshed_at', '2026-06-05T12:05:00+02:00')
            ->assertJsonPath('price_refresh_settings.next_refresh_at', '2026-06-05T12:25:00+02:00');
        $this->assertDatabaseHas('stock_realtime_prices', ['stock_holding_id' => $ames->id, 'source_key' => 'xetra_posttrade', 'price' => '10.25000000']);
        $this->assertDatabaseHas('stock_realtime_prices', ['stock_holding_id' => $leer->id, 'source_key' => 'xetra_posttrade', 'price' => '20.75000000']);
        $this->assertDatabaseHas('stock_holdings', ['id' => $ames->id, 'latest_price' => '10.000000']);
        Http::assertSentCount(3);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'eodhd.com'));
    }

    public function test_admin_can_list_selected_stock_realtime_prices_from_its_latest_vienna_date(): void
    {
        $admin = $this->adminUser();
        $ames = StockHolding::factory()->create([
            'symbol' => 'AMES',
            'name' => 'Amundi IBEX 35 UCITS ETF',
            'exchange' => 'XETRA',
            'currency' => 'EUR',
        ]);
        $leer = StockHolding::factory()->create([
            'symbol' => 'LEER',
            'name' => 'Leerink',
            'exchange' => 'XETRA',
            'currency' => 'EUR',
        ]);

        StockRealtimePrice::factory()->for($ames)->create([
            'symbol' => 'AMES',
            'price' => '9.95000000',
            'as_of' => Carbon::parse('2026-06-04 20:30:00', 'UTC'),
            'fetched_at' => Carbon::parse('2026-06-04 20:30:01', 'UTC'),
        ]);
        StockRealtimePrice::factory()->for($ames)->create([
            'symbol' => 'AMES',
            'venue' => 'Tradegate',
            'bid' => '10.10000000',
            'ask' => '10.20000000',
            'last' => '10.15000000',
            'price' => '10.15000000',
            'as_of' => Carbon::parse('2026-06-04 22:30:00', 'UTC'),
            'fetched_at' => Carbon::parse('2026-06-04 22:30:01', 'UTC'),
        ]);
        StockRealtimePrice::factory()->for($ames)->create([
            'symbol' => 'AMES',
            'venue' => 'XETRA',
            'price' => '10.25000000',
            'as_of' => Carbon::parse('2026-06-05 15:00:00', 'UTC'),
            'fetched_at' => Carbon::parse('2026-06-05 15:00:02', 'UTC'),
        ]);
        StockRealtimePrice::factory()->for($leer)->create([
            'symbol' => 'LEER',
            'price' => '20.75000000',
            'as_of' => Carbon::parse('2026-06-05 15:30:00', 'UTC'),
            'fetched_at' => Carbon::parse('2026-06-05 15:30:02', 'UTC'),
        ]);

        $this->actingAs($admin)
            ->getJson("/admin/data/realtime/latest?stock={$ames->id}")
            ->assertOk()
            ->assertJsonPath('date', '2026-06-05')
            ->assertJsonPath('row_count', 2)
            ->assertJsonPath('entries.0.symbol', 'AMES')
            ->assertJsonPath('entries.0.holding_name', 'Amundi IBEX 35 UCITS ETF')
            ->assertJsonPath('entries.0.venue', 'Tradegate')
            ->assertJsonPath('entries.0.bid', '10.10000000')
            ->assertJsonPath('entries.0.ask', '10.20000000')
            ->assertJsonPath('entries.0.last', '10.15000000')
            ->assertJsonPath('entries.0.as_of', '2026-06-04T22:30:00+00:00')
            ->assertJsonPath('entries.1.symbol', 'AMES')
            ->assertJsonPath('entries.1.price', '10.25000000')
            ->assertJsonMissingPath('entries.2');
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
