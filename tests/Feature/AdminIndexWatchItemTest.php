<?php

namespace Tests\Feature;

use App\Models\IndexWatchItem;
use App\Models\IndexWatchItemPrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminIndexWatchItemTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_index_watch_items(): void
    {
        $admin = $this->adminUser();
        $now = now();
        $atx = IndexWatchItem::factory()->create([
            'symbol' => 'ATX',
            'name' => 'Austrian Traded Index in EUR',
            'isin' => 'AT0000999982',
            'exchange' => 'INDX',
            'instrument_type' => 'INDEX',
            'country' => 'Austria',
            'currency' => 'EUR',
            'start_price' => '6096.16990000',
            'latest_price' => '6116.52980000',
            'last_price' => '6096.16990000',
            'latest_price_change_pct' => '0.333979',
            'latest_price_as_of' => '2026-06-04 15:13:00',
            'created_at' => $now->copy()->subMinute(),
        ]);

        collect(range(0, 31))->each(fn (int $daysAgo): IndexWatchItemPrice => IndexWatchItemPrice::query()->create([
            'index_watch_item_id' => $atx->id,
            'trading_date' => $now->copy()->subDays($daysAgo)->toDateString(),
            'start_price' => 6100 + $daysAgo,
            'actual_price' => 6110 + $daysAgo,
            'last_price' => 6090 + $daysAgo,
            'actual_price_as_of' => $now->copy()->subDays($daysAgo)->setTime(15, 13),
            'last_price_as_of' => $now->copy()->subDays($daysAgo)->setTime(17, 30),
        ]));

        IndexWatchItem::factory()->create([
            'symbol' => 'DAX',
            'name' => 'DAX Index',
            'isin' => 'DE0008469008',
            'exchange' => 'INDX',
            'instrument_type' => 'INDEX',
            'country' => 'Germany',
            'currency' => 'EUR',
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/index-watch-items')
            ->assertOk()
            ->assertJsonCount(2, 'indexes')
            ->assertJsonPath('indexes.0.symbol', 'ATX')
            ->assertJsonPath('indexes.0.name', 'Austrian Traded Index in EUR')
            ->assertJsonPath('indexes.0.eodhd_code', 'ATX.INDX')
            ->assertJsonPath('indexes.0.latest_price', '6116.529800')
            ->assertJsonPath('indexes.0.last_price', '6096.169900')
            ->assertJsonPath('indexes.0.latest_price_change_pct', '0.09')
            ->assertJsonCount(30, 'indexes.0.recent_prices')
            ->assertJsonPath('indexes.0.recent_prices.0.trading_date', $now->toDateString())
            ->assertJsonPath('indexes.0.recent_prices.0.actual_price', '6110.000000')
            ->assertJsonPath('indexes.0.recent_prices.29.trading_date', $now->copy()->subDays(29)->toDateString())
            ->assertJsonPath('indexes.1.symbol', 'DAX');
    }

    public function test_admin_can_load_missing_index_prices_from_eodhd(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-07 12:00:00', 'Europe/Vienna'));

        try {
            config(['services.eodhd.key' => 'test-token']);
            Http::fake([
                'eodhd.com/api/real-time/DAX.XETRA*' => Http::response([
                    'code' => 'DAX.XETRA',
                    'timestamp' => Carbon::parse('2026-06-07 10:15:00', 'UTC')->timestamp,
                    'open' => 6200.00,
                    'close' => 6116.5298,
                    'previousClose' => 6096.1699,
                    'currency' => 'EUR',
                ]),
                'eodhd.com/api/eod/DAX.XETRA*' => Http::response($this->eodhdDailyIndexRecords()),
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
            $admin = $this->adminUser();
            $index = IndexWatchItem::factory()->create([
                'symbol' => 'DAX',
                'name' => 'DAX Index',
                'isin' => 'DE0008469008',
                'exchange' => 'XETRA',
                'mic_code' => 'XETR',
                'country' => 'Germany',
                'currency' => 'EUR',
            ]);

            $this->actingAs($admin)
                ->postJson("/admin/index-watch-items/{$index->id}/prices/ensure")
                ->assertOk()
                ->assertJsonPath('message', 'Index prices loaded.')
                ->assertJsonPath('index.latest_price', '6116.529800')
                ->assertJsonPath('index.latest_price_change_pct', '0.02')
                ->assertJsonPath('index.latest_price_source', 'EODHD real-time')
                ->assertJsonCount(30, 'index.recent_prices')
                ->assertJsonPath('index.recent_prices.0.trading_date', '2026-06-07')
                ->assertJsonPath('index.recent_prices.0.actual_price', '6116.529800')
                ->assertJsonPath('index.recent_prices.29.trading_date', '2026-05-09');

            $this->assertDatabaseCount('index_watch_item_prices', 30);
            $storedPrice = IndexWatchItemPrice::query()
                ->where('index_watch_item_id', $index->id)
                ->whereDate('trading_date', '2026-05-09')
                ->first();

            $this->assertNotNull($storedPrice);
            $this->assertSame('6042.00000000', (string) $storedPrice->start_price);
            $this->assertSame('6087.52980000', (string) $storedPrice->actual_price);
            $this->assertSame('6087.52980000', (string) $storedPrice->last_price);
            Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/eod/DAX.XETRA'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_admin_index_recent_prices_hide_only_current_trading_day_last_price_from_open_quote_timestamp(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-05 18:00:00', 'Europe/Berlin'));

        try {
            $admin = $this->adminUser();
            $index = IndexWatchItem::factory()->create([
                'symbol' => 'GDAXI',
                'name' => 'DAX Index',
                'country' => 'Germany',
                'currency' => 'EUR',
                'latest_price' => '24956.33010000',
                'last_price' => '24944.94920000',
                'latest_price_change_pct' => '-2.090000',
                'latest_price_as_of' => Carbon::parse('2026-06-05 14:24:00', 'UTC'),
                'trading_times' => 'Monday-Friday 08:55:00-17:35:00 Europe/Berlin',
            ]);

            IndexWatchItemPrice::query()->create([
                'index_watch_item_id' => $index->id,
                'trading_date' => '2026-06-05',
                'start_price' => '24881.86910000',
                'actual_price' => '24956.33010000',
                'last_price' => '24944.94920000',
                'actual_price_as_of' => '2026-06-05 12:00:00',
                'last_price_as_of' => '2026-06-05 12:00:00',
            ]);

            IndexWatchItemPrice::query()->create([
                'index_watch_item_id' => $index->id,
                'trading_date' => '2026-06-04',
                'start_price' => '24700.00000000',
                'actual_price' => '24900.00000000',
                'last_price' => '24881.86910000',
                'actual_price_as_of' => '2026-06-04 17:30:00',
                'last_price_as_of' => '2026-06-04 17:30:00',
            ]);

            $response = $this->actingAs($admin)
                ->getJson('/admin/index-watch-items')
                ->assertOk()
                ->assertJsonPath('indexes.0.latest_price', '24956.330100')
                ->assertJsonPath('indexes.0.latest_price_change_pct', '0.23');

            $this->assertSame(
                ['2026-06-05', '2026-06-04'],
                array_column($response->json('indexes.0.recent_prices'), 'trading_date'),
            );
            $response
                ->assertJsonPath('indexes.0.recent_prices.0.actual_price', '24956.330100')
                ->assertJsonPath('indexes.0.recent_prices.0.last_price', '24956.330100')
                ->assertJsonPath('indexes.0.recent_prices.1.last_price', '24900.000000');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_admin_can_add_a_selected_index_watch_item(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->postJson('/admin/index-watch-items', [
                'symbol' => ' spx ',
                'name' => 'S&P 500 Index',
                'isin' => ' us78378x1072 ',
                'wkn' => ' a0aet0 ',
                'exchange' => 'INDX',
                'mic_code' => ' xnas ',
                'instrument_type' => 'INDEX',
                'country' => 'United States',
                'currency' => 'usd',
                'valor' => '998434',
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Index added.')
            ->assertJsonPath('index.symbol', 'SPX')
            ->assertJsonPath('index.name', 'S&P 500 Index')
            ->assertJsonPath('index.isin', 'US78378X1072')
            ->assertJsonPath('index.wkn', 'A0AET0')
            ->assertJsonPath('index.eodhd_code', 'SPX.INDX')
            ->assertJsonPath('index.mic_code', 'XNAS')
            ->assertJsonPath('index.currency', 'USD');

        $this->assertDatabaseHas('index_watch_items', [
            'symbol' => 'SPX',
            'name' => 'S&P 500 Index',
            'isin' => 'US78378X1072',
            'wkn' => 'A0AET0',
            'exchange' => 'INDX',
            'mic_code' => 'XNAS',
            'instrument_type' => 'INDEX',
            'country' => 'United States',
            'currency' => 'USD',
        ]);
    }

    public function test_guest_cannot_add_index_watch_items(): void
    {
        $this->postJson('/admin/index-watch-items', [
            'symbol' => 'SPX',
        ])->assertUnauthorized();
    }

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    /**
     * @return array<int, array{date: string, open: float, close: float, adjusted_close: float}>
     */
    private function eodhdDailyIndexRecords(): array
    {
        return collect(range(0, 29))
            ->reverse()
            ->values()
            ->map(function (int $daysAgo): array {
                $close = 6116.5298 - $daysAgo;

                return [
                    'date' => Carbon::parse('2026-06-07')->subDays($daysAgo)->toDateString(),
                    'open' => $close - 45.5298,
                    'close' => $close,
                    'adjusted_close' => $close,
                ];
            })
            ->all();
    }
}
