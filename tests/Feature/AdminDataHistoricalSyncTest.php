<?php

namespace Tests\Feature;

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
        $this->travelTo(Carbon::parse('2026-06-26 18:00:00', 'Europe/Vienna'));
        $holding = StockHolding::factory()->create(['symbol' => 'AMES', 'isin' => 'FR0010655746', 'exchange' => 'XETRA', 'mic_code' => 'XETR', 'currency' => 'EUR']);
        $covered = StockHolding::factory()->create(['symbol' => 'COV', 'exchange' => 'XETRA', 'mic_code' => 'XETR', 'currency' => 'EUR']);
        $this->createIntradayCandle($holding, '2026-06-25');
        $this->createIntradayCandle($covered, '2026-06-26');
        $this->fakeEuropeanTrades([['holding' => $holding, 'price' => 101.5, 'at' => '2026-06-26 14:30:00']]);
        $response = $this->actingAs($this->adminUser())->postJson('/admin/data/historical/sync')->assertOk()
            ->assertJsonPath('requested_count', 1)->assertJsonPath('stored_count', 1)->assertJsonPath('failed_count', 0)
            ->assertJsonPath('date_from', '2026-06-26')->assertJsonPath('date_to', '2026-06-26')
            ->assertJsonPath('refresh.status', 'finished')
            ->assertJsonPath('intraday_backfill_settings.last_dispatched_at', '2026-06-26T18:00:00+02:00');
        $this->assertStringContainsString('Free intraday sync', $response->json('message'));
        $this->assertDatabaseHas('stock_holding_intraday_candles', ['stock_holding_id' => $holding->id, 'interval' => 'xtrade',
            'close' => '101.50000000', 'source_key' => 'xetra_posttrade']);
        $this->assertSame(1, StockHoldingIntradayCandle::query()->where('stock_holding_id', $covered->id)->count());
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'eodhd.com'));
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
