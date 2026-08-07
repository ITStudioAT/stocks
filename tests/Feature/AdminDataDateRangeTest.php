<?php

namespace Tests\Feature;

use App\Models\IndexWatchItem;
use App\Models\IndexWatchItemIntradayCandle;
use App\Models\IndexWatchItemPrice;
use App\Models\IndexWatchItemRealtimePrice;
use App\Models\StockHolding;
use App\Models\StockHoldingDailyPrice;
use App\Models\StockHoldingIntradayCandle;
use App\Models\StockRealtimePrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminDataDateRangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_read_all_index_and_stock_data_date_ranges(): void
    {
        $admin = $this->adminUser();
        $index = IndexWatchItem::factory()->create(['symbol' => 'GDAXI']);
        $secondIndex = IndexWatchItem::factory()->create(['symbol' => 'NDX']);
        $holding = StockHolding::factory()->create(['symbol' => 'AAPL']);
        $secondHolding = StockHolding::factory()->create(['symbol' => 'MSFT']);

        IndexWatchItemRealtimePrice::query()->create([
            'index_watch_item_id' => $index->id,
            'trading_date' => '2026-06-02',
            'price' => '6100.00000000',
            'as_of' => '2026-06-02 12:00:00',
        ]);
        IndexWatchItemRealtimePrice::query()->create([
            'index_watch_item_id' => $secondIndex->id,
            'trading_date' => '2026-05-01',
            'price' => '20000.00000000',
            'as_of' => '2026-05-01 12:00:00',
        ]);
        IndexWatchItemRealtimePrice::query()->create([
            'index_watch_item_id' => $index->id,
            'trading_date' => '2026-06-05',
            'price' => '6200.00000000',
            'as_of' => '2026-06-05 12:00:00',
        ]);
        IndexWatchItemIntradayCandle::query()->create([
            'index_watch_item_id' => $index->id,
            'trading_date' => '2026-05-28',
            'interval' => '5m',
            'as_of' => '2026-05-28 12:00:00',
            'timestamp' => 1,
            'close' => '6000.00000000',
            'source_key' => 'eodhd_intraday',
        ]);
        IndexWatchItemIntradayCandle::query()->create([
            'index_watch_item_id' => $index->id,
            'trading_date' => '2024-01-01',
            'interval' => '1h',
            'as_of' => '2024-01-01 12:00:00',
            'timestamp' => 2,
            'close' => '5000.00000000',
            'source_key' => 'other_source',
        ]);
        IndexWatchItemIntradayCandle::query()->create([
            'index_watch_item_id' => $secondIndex->id,
            'trading_date' => '2026-06-01',
            'interval' => '5m',
            'as_of' => '2026-06-01 12:00:00',
            'timestamp' => 3,
            'close' => '21000.00000000',
            'source_key' => 'eodhd_intraday',
        ]);
        IndexWatchItemPrice::query()->create([
            'index_watch_item_id' => $index->id,
            'trading_date' => '2025-06-05',
            'actual_price' => '5900.00000000',
        ]);
        IndexWatchItemPrice::query()->create([
            'index_watch_item_id' => $index->id,
            'trading_date' => '2024-01-01',
            'actual_price' => null,
        ]);
        IndexWatchItemPrice::query()->create([
            'index_watch_item_id' => $secondIndex->id,
            'trading_date' => '2026-07-01',
            'actual_price' => '22000.00000000',
        ]);

        StockRealtimePrice::factory()->for($holding)->create([
            'as_of' => Carbon::parse('2026-06-01 10:00:00', 'UTC'),
        ]);
        StockRealtimePrice::factory()->for($secondHolding)->create([
            'as_of' => Carbon::parse('2026-05-01 10:00:00', 'UTC'),
        ]);
        StockRealtimePrice::factory()->create([
            'stock_holding_id' => null,
            'as_of' => Carbon::parse('2024-01-01 10:00:00', 'UTC'),
        ]);
        StockRealtimePrice::factory()->for($holding)->create([
            'as_of' => Carbon::parse('2026-06-04 10:00:00', 'UTC'),
        ]);
        StockHoldingIntradayCandle::query()->create([
            'stock_holding_id' => $holding->id,
            'trading_date' => '2026-05-30',
            'interval' => '5m',
            'as_of' => '2026-05-30 12:00:00',
            'timestamp' => 2,
            'close' => '10.00000000',
            'source_key' => 'eodhd_intraday',
        ]);
        StockHoldingIntradayCandle::query()->create([
            'stock_holding_id' => $holding->id,
            'trading_date' => '2024-01-01',
            'interval' => '5m',
            'as_of' => '2024-01-01 12:00:00',
            'timestamp' => 3,
            'close' => '9.00000000',
            'source_key' => 'legacy_migration',
        ]);
        StockHoldingIntradayCandle::query()->create([
            'stock_holding_id' => $secondHolding->id,
            'trading_date' => '2026-06-02',
            'interval' => '5m',
            'as_of' => '2026-06-02 12:00:00',
            'timestamp' => 4,
            'close' => '20.00000000',
            'source_key' => 'eodhd_intraday',
        ]);
        StockHoldingDailyPrice::factory()->for($holding)->create([
            'trading_date' => '2025-06-01',
        ]);
        StockHoldingDailyPrice::factory()->for($holding)->create([
            'trading_date' => '2024-01-01',
            'close' => null,
        ]);
        StockHoldingDailyPrice::factory()->for($secondHolding)->create([
            'trading_date' => '2026-07-01',
        ]);

        $this->actingAs($admin)
            ->getJson("/admin/data/indices/{$index->id}/live-data/date-range")
            ->assertOk()
            ->assertJsonPath('data_type', 'live-data')
            ->assertJsonPath('range.date_from', '2026-06-02')
            ->assertJsonPath('range.date_to', '2026-06-05')
            ->assertJsonPath('range.row_count', 2);

        $this->actingAs($admin)
            ->getJson("/admin/data/indices/{$index->id}/intraday-data/date-range")
            ->assertOk()
            ->assertJsonPath('range.date_from', '2026-05-28')
            ->assertJsonPath('range.date_to', '2026-05-28')
            ->assertJsonPath('range.row_count', 1);

        $this->actingAs($admin)
            ->getJson("/admin/data/indices/{$index->id}/eod-data/date-range")
            ->assertOk()
            ->assertJsonPath('range.date_from', '2025-06-05')
            ->assertJsonPath('range.date_to', '2025-06-05')
            ->assertJsonPath('range.row_count', 1);

        $this->actingAs($admin)
            ->getJson("/admin/data/stocks/{$holding->id}/live-data/date-range")
            ->assertOk()
            ->assertJsonPath('range.date_from', '2026-06-01')
            ->assertJsonPath('range.date_to', '2026-06-04')
            ->assertJsonPath('range.row_count', 2);

        $this->actingAs($admin)
            ->getJson("/admin/data/stocks/{$holding->id}/intraday-data/date-range")
            ->assertOk()
            ->assertJsonPath('range.date_from', '2026-05-30')
            ->assertJsonPath('range.date_to', '2026-05-30')
            ->assertJsonPath('range.row_count', 1);

        $this->actingAs($admin)
            ->getJson("/admin/data/stocks/{$holding->id}/eod-data/date-range")
            ->assertOk()
            ->assertJsonPath('range.date_from', '2025-06-01')
            ->assertJsonPath('range.date_to', '2025-06-01')
            ->assertJsonPath('range.row_count', 1);

        $this->actingAs($admin)
            ->getJson('/admin/data/indices/live-data/date-range')
            ->assertOk()
            ->assertJsonPath('range.date_from', '2026-05-01')
            ->assertJsonPath('range.date_to', '2026-06-05')
            ->assertJsonPath('range.row_count', 3);

        $this->actingAs($admin)
            ->getJson('/admin/data/indices/intraday-data/date-range')
            ->assertOk()
            ->assertJsonPath('range.date_from', '2026-05-28')
            ->assertJsonPath('range.date_to', '2026-06-01')
            ->assertJsonPath('range.row_count', 2);

        $this->actingAs($admin)
            ->getJson('/admin/data/indices/eod-data/date-range')
            ->assertOk()
            ->assertJsonPath('range.date_from', '2025-06-05')
            ->assertJsonPath('range.date_to', '2026-07-01')
            ->assertJsonPath('range.row_count', 2);

        $this->actingAs($admin)
            ->getJson('/admin/data/stocks/live-data/date-range')
            ->assertOk()
            ->assertJsonPath('range.date_from', '2026-05-01')
            ->assertJsonPath('range.date_to', '2026-06-04')
            ->assertJsonPath('range.row_count', 3);

        $this->actingAs($admin)
            ->getJson('/admin/data/stocks/intraday-data/date-range')
            ->assertOk()
            ->assertJsonPath('range.date_from', '2026-05-30')
            ->assertJsonPath('range.date_to', '2026-06-02')
            ->assertJsonPath('range.row_count', 2);

        $this->actingAs($admin)
            ->getJson('/admin/data/stocks/eod-data/date-range')
            ->assertOk()
            ->assertJsonPath('range.date_from', '2025-06-01')
            ->assertJsonPath('range.date_to', '2026-07-01')
            ->assertJsonPath('range.row_count', 2);
    }

    public function test_empty_range_and_invalid_data_type_are_handled(): void
    {
        $admin = $this->adminUser();
        $index = IndexWatchItem::factory()->create();

        $this->actingAs($admin)
            ->getJson("/admin/data/indices/{$index->id}/live-data/date-range")
            ->assertOk()
            ->assertJsonPath('range.date_from', null)
            ->assertJsonPath('range.date_to', null)
            ->assertJsonPath('range.row_count', 0);

        $this->actingAs($admin)
            ->getJson("/admin/data/indices/{$index->id}/unknown/date-range")
            ->assertNotFound();

        $this->actingAs($admin)
            ->getJson('/admin/data/indices/live-data/date-range')
            ->assertOk()
            ->assertJsonPath('range.date_from', null)
            ->assertJsonPath('range.date_to', null)
            ->assertJsonPath('range.row_count', 0);

        $this->actingAs($admin)
            ->getJson('/admin/data/indices/unknown/date-range')
            ->assertNotFound();
    }

    public function test_guest_cannot_read_data_date_ranges(): void
    {
        $index = IndexWatchItem::factory()->create();

        $this->getJson("/admin/data/indices/{$index->id}/live-data/date-range")
            ->assertUnauthorized();
        $this->getJson('/admin/data/indices/live-data/date-range')
            ->assertUnauthorized();
    }

    public function test_three_level_data_menu_url_can_be_loaded_directly(): void
    {
        $this->actingAs($this->adminUser())
            ->get('/admin/menu/data/stocks/eod-data')
            ->assertOk();
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
