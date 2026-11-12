<?php

namespace Tests\Feature;

use App\Models\Depot;
use App\Models\DepotTransaction;
use App\Models\StockHolding;
use App\Models\StockHoldingDailyPrice;
use App\Models\StockHoldingIntradayCandle;
use App\Models\StockRealtimePrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminAnalysisTrendsTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_ranks_both_directions_and_counts_flat_days_without_querying_each_stock(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('admin', 'web'));
        $up = $this->holdingWithPrices('Upward', [100, 110, 110, 121]);
        $down = $this->holdingWithPrices('Downward', [100, 90, 90, 90, 80]);
        $reverse = $this->holdingWithPrices('Reversal', [100, 90, 90, 110]);
        $this->holdingWithPrices('Only flat', [100, 100, 100]);
        $this->holdingWithPrices('Single', [100]);
        $long = $this->holdingWithPrices('Long run', range(100, 202));
        $incompatible = $this->holdingWithPrices('Currency gap', [100, 110]);
        $empty = StockHolding::factory()->create(['name' => 'No prices']);
        StockHoldingDailyPrice::query()->where('stock_holding_id', $incompatible->id)->whereDate('trading_date', '2026-01-01')->update(['currency' => 'USD']);

        DB::enableQueryLog();
        $response = $this->actingAs($admin)->getJson('/admin/analysis/trends')->assertOk();
        $queries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'stock_holding_daily_prices'));
        DB::disableQueryLog();

        $response->assertJsonCount(8, 'trends')
            ->assertJsonPath('trends.0.id', $long->id)->assertJsonPath('trends.0.count', 102)->assertJsonPath('trends.0.direction', 'up')
            ->assertJsonPath('trends.1.id', $down->id)->assertJsonPath('trends.1.count', 4)->assertJsonPath('trends.1.direction', 'down')
            ->assertJsonPath('trends.2.id', $up->id)->assertJsonPath('trends.2.count', 3)->assertJsonPath('trends.2.direction', 'up')
            ->assertJsonPath('trends.3.id', $reverse->id)->assertJsonPath('trends.3.count', 1)
            ->assertJsonPath('trends.4.id', $incompatible->id)->assertJsonPath('trends.4.direction', null)
            ->assertJsonPath('trends.5.id', $empty->id)->assertJsonPath('trends.5.count', null);
        $this->assertCount(1, $queries);
    }

    public function test_summary_returns_all_stocks_and_an_empty_list_without_stocks(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('admin', 'web'));
        $this->actingAs($admin)->getJson('/admin/analysis/trends')->assertOk()->assertJsonCount(0, 'trends');

        foreach (range(1, 15) as $number) {
            $this->holdingWithPrices(sprintf('Stock %02d', $number), [100, 101]);
        }

        $this->getJson('/admin/analysis/trends')->assertOk()->assertJsonCount(15, 'trends')
            ->assertJsonPath('trends.0.name', 'Stock 01')
            ->assertJsonPath('trends.14.name', 'Stock 15');
    }

    public function test_summary_adds_rounded_daily_percentages_for_the_current_trend(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('admin', 'web'));
        $up = $this->holdingWithPrices('Up', [100, 110, 110, 121]);
        $down = $this->holdingWithPrices('Down', [100, 90, 81]);
        $reversal = $this->holdingWithPrices('Reversal', [100, 120, 108]);
        $rounding = $this->holdingWithPrices('Rounding', [100, 103, 106]);
        $zero = $this->holdingWithPrices('Zero base', [0, 100]);
        $flat = $this->holdingWithPrices('Flat', [100, 100]);
        $trends = collect($this->actingAs($admin)->getJson('/admin/analysis/trends')->assertOk()->json('trends'))->keyBy('id');

        $this->assertEquals(20, $trends[$up->id]['percentage_sum']);
        $this->assertSame(3, $trends[$up->id]['count']);
        $this->assertEquals(-20, $trends[$down->id]['percentage_sum']);
        $this->assertEquals(-10, $trends[$reversal->id]['percentage_sum']);
        $this->assertEquals(5.91, $trends[$rounding->id]['percentage_sum']);
        $this->assertNull($trends[$zero->id]['percentage_sum']);
        $this->assertNull($trends[$flat->id]['percentage_sum']);
    }

    public function test_summary_uses_the_active_depot_positions_like_the_stocks_list(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('admin', 'web'));
        $depot = Depot::factory()->create(['is_active' => true]);
        $otherDepot = Depot::factory()->create(['is_active' => false]);
        $held = $this->holdingWithPrices('Held stock', [10, 11]);
        $empty = StockHolding::factory()->create(['name' => 'No position']);
        DepotTransaction::factory()->create(['depot_id' => $depot->id, 'stock_holding_id' => $held->id, 'type' => 'buy', 'pieces' => '5.25000000']);
        DepotTransaction::factory()->create(['depot_id' => $depot->id, 'stock_holding_id' => $held->id, 'type' => 'sell', 'pieces' => '1.12500000']);
        DepotTransaction::factory()->create(['depot_id' => $otherDepot->id, 'stock_holding_id' => $held->id, 'type' => 'buy', 'pieces' => '20.00000000']);

        $this->actingAs($admin);
        $trends = collect($this->getJson('/admin/analysis/trends')->assertOk()->json('trends'))->keyBy('id');
        $stocks = collect($this->getJson('/admin/watchlist/holdings?page=1&all=1')->assertOk()->json('holdings'))->keyBy('id');
        $this->assertSame('4.12500000', $trends[$held->id]['position_pieces']);
        $this->assertSame('0.00000000', $trends[$empty->id]['position_pieces']);
        $this->assertSame($stocks[$held->id]['position_pieces'], $trends[$held->id]['position_pieces']);
        $this->assertSame($stocks[$empty->id]['position_pieces'], $trends[$empty->id]['position_pieces']);
    }

    public function test_summary_requires_authentication(): void
    {
        $this->getJson('/admin/analysis/trends')->assertUnauthorized();
    }

    public function test_current_intraday_prices_recalculate_and_rank_trends_without_duplicate_days(): void
    {
        $this->travelTo(Carbon::parse('2026-01-04 12:00:00', 'Europe/Vienna'));
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('admin', 'web'));
        $up = $this->holdingWithPrices('Up', [100, 110, 120, 150]);
        $down = $this->holdingWithPrices('Down', [150, 140, 130]);
        $reverse = $this->holdingWithPrices('Reversal', [100, 110, 120]);
        $stale = $this->holdingWithPrices('Stale', [100, 110, 120]);

        foreach ([[$up, 125, '2026-01-04 10:00:00'], [$down, 120, '2026-01-04 10:00:00'], [$reverse, 100, '2026-01-04 10:00:00'], [$stale, 90, '2026-01-03 10:00:00']] as [$holding, $value, $time]) {
            StockRealtimePrice::factory()->create([
                'stock_holding_id' => $holding->id, 'currency' => 'EUR', 'price' => $value,
                'as_of' => Carbon::parse($time, 'Europe/Vienna')->utc(),
            ]);
        }

        $response = $this->actingAs($admin)->getJson('/admin/analysis/trends')->assertOk();
        $response->assertJsonPath('trends.0.id', $down->id)->assertJsonPath('trends.0.direction', 'down')->assertJsonPath('trends.0.count', 3)
            ->assertJsonPath('trends.1.id', $up->id)->assertJsonPath('trends.1.direction', 'up')->assertJsonPath('trends.1.count', 3)
            ->assertJsonPath('trends.1.trading_date', '2026-01-04')->assertJsonPath('trends.1.intraday.trend_date', '2026-01-04')
            ->assertJsonPath('trends.2.id', $stale->id)->assertJsonPath('trends.2.count', 2)->assertJsonPath('trends.2.intraday.trend_date', null)
            ->assertJsonPath('trends.3.id', $reverse->id)->assertJsonPath('trends.3.direction', 'down')->assertJsonPath('trends.3.count', 1);
        $this->assertEquals(23.26, $response->json('trends.1.percentage_sum'));
    }

    public function test_intraday_date_uses_application_timezone_and_does_not_override_a_newer_daily_price(): void
    {
        $this->travelTo(Carbon::parse('2026-01-04 00:30:00', 'Europe/Vienna'));
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('admin', 'web'));
        $current = $this->holdingWithPrices('Current', [100, 110, 120]);
        $newer = $this->holdingWithPrices('Newer daily', [100, 110, 120, 130, 140]);
        foreach ([$current, $newer] as $holding) {
            StockRealtimePrice::factory()->create(['stock_holding_id' => $holding->id, 'currency' => 'EUR', 'price' => 130,
                'as_of' => Carbon::parse('2026-01-03 23:15:00', 'UTC')]);
        }
        $trends = collect($this->actingAs($admin)->getJson('/admin/analysis/trends')->assertOk()->json('trends'))->keyBy('id');
        $this->assertSame('2026-01-04', $trends[$current->id]['intraday']['trend_date']);
        $this->assertSame(3, $trends[$current->id]['count']);
        $this->assertSame('2026-01-05', $trends[$newer->id]['trading_date']);
        $this->assertSame(4, $trends[$newer->id]['count']);
    }

    public function test_summary_includes_the_latest_valid_intraday_price_for_each_stock(): void
    {
        $this->travelTo(Carbon::parse('2026-06-05 12:00:00', 'UTC'));
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('admin', 'web'));
        $holding = $this->holdingWithPrices('Stock', [100, 110]);
        $empty = StockHolding::factory()->create(['name' => 'Empty']);

        foreach ([['09:00', '120', 'EUR'], ['10:00', '125', 'EUR'], ['11:00', '200', 'USD'], ['13:00', '300', 'EUR']] as [$time, $close, $currency]) {
            $asOf = Carbon::parse("2026-06-05 {$time}", 'UTC');
            StockHoldingIntradayCandle::query()->create([
                'stock_holding_id' => $holding->id, 'trading_date' => '2026-06-05', 'interval' => '5m',
                'as_of' => $asOf, 'timestamp' => $asOf->timestamp, 'datetime' => $asOf->toDateTimeString(),
                'close' => $close, 'currency' => $currency, 'source_key' => 'eodhd_intraday',
            ]);
        }

        $trends = collect($this->actingAs($admin)->getJson('/admin/analysis/trends')->assertOk()->json('trends'))->keyBy('id');
        $this->assertEquals(125, $trends[$holding->id]['intraday']['price']);
        $this->assertSame('2026-06-05T10:00:00+00:00', $trends[$holding->id]['intraday']['as_of']);
        $this->assertSame(['EODHD'], $trends[$holding->id]['intraday']['source_markers']);
        $this->assertSame('up', $trends[$holding->id]['direction']);
        $this->assertNull($trends[$empty->id]['intraday']);

        StockRealtimePrice::factory()->create(['stock_holding_id' => $holding->id, 'currency' => 'EUR', 'price' => '130',
            'as_of' => Carbon::parse('2026-06-05 10:05:00', 'UTC')]);
        StockRealtimePrice::factory()->create(['stock_holding_id' => $holding->id, 'currency' => 'EUR', 'price' => '500',
            'as_of' => Carbon::parse('2026-06-05 10:10:00', 'UTC'), 'validation_status' => 'invalid']);
        $trends = collect($this->getJson('/admin/analysis/trends')->assertOk()->json('trends'))->keyBy('id');
        $this->assertEquals(130, $trends[$holding->id]['intraday']['price']);
        $this->assertSame('2026-06-05T10:05:00+00:00', $trends[$holding->id]['intraday']['as_of']);
    }

    /** @param array<int, int> $values */
    private function holdingWithPrices(string $name, array $values): StockHolding
    {
        $holding = StockHolding::factory()->create(['name' => $name, 'currency' => 'EUR']);

        foreach ($values as $index => $value) {
            StockHoldingDailyPrice::factory()->create([
                'stock_holding_id' => $holding->id,
                'trading_date' => Carbon::parse('2026-01-01')->addDays($index),
                'close' => $value,
                'adjusted_close' => $value,
                'currency' => 'EUR',
            ]);
        }

        return $holding;
    }
}
