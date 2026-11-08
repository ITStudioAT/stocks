<?php

namespace Tests\Feature;

use App\Models\Depot;
use App\Models\DepotTransaction;
use App\Models\MarketPrice;
use App\Models\StockHolding;
use App\Models\StockHoldingDailyPrice;
use App\Models\StockHoldingIntradayCandle;
use App\Models\StockRealtimePrice;
use App\Models\User;
use App\Services\MarketDataUpgrade;
use App\Services\MarketIdentityRegistry;
use App\Services\MarketPriceLegacyImporter;
use App\Services\MarketPriceStore;
use App\Services\StoredHistoricalPriceResolver;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CentralMarketDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-06 17:00:00', 'UTC'));
    }

    private function holding(array $attributes = []): StockHolding
    {
        return StockHolding::factory()->create([...['isin' => 'US8740541094', 'symbol' => 'TKE', 'currency' => 'EUR', 'exchange' => 'XETRA', 'mic_code' => 'XETR'], ...$attributes]);
    }

    private function quote(array $attributes = []): array
    {
        return [...['source_key' => 'xetra_posttrade', 'reported_mic' => 'XETR', 'currency' => 'EUR', 'record_kind' => 'quote', 'observed_at' => '2026-11-06 15:00:00',
            'effective_at' => '2026-11-06 15:00:00', 'source_time_raw' => '2026-11-06 15:00:00', 'session_type' => 'regular', 'trading_date' => '2026-11-06',
            'price' => '193.1234567890', 'fetched_at' => '2026-11-06 15:20:00'], ...$attributes];
    }

    public function test_shared_listings_separate_markets_and_exact_decimal_normalization(): void
    {
        $registry = app(MarketIdentityRegistry::class);
        $holding = $this->holding();
        $one = $registry->listing($holding);
        $two = $registry->listing($holding->fresh());
        $holding->forceFill(['symbol' => 'TTWO', 'currency' => 'USD', 'exchange' => 'NASDAQ', 'mic_code' => 'XNAS'])->save();
        $us = $registry->listing($holding);
        $this->assertSame($one->id, $two->id);
        $this->assertNotSame($one->id, $us->id);
        $this->assertSame('consolidated:US', $us->venue_key);
        $this->assertNull($us->mic);
        $this->assertSame('123456789012345678.1234567890', MarketPriceStore::decimal('123456789012345678.123456789'));
    }

    public function test_identical_reports_do_not_write_and_new_source_time_is_new_even_at_the_same_price(): void
    {
        $listing = app(MarketIdentityRegistry::class)->listing($this->holding());
        $store = app(MarketPriceStore::class);
        $first = $store->store($listing->id, $this->quote());
        DB::enableQueryLog();
        $same = $store->store($listing->id, $this->quote(['fetched_at' => '2026-11-06 16:00:00']));
        $mutations = collect(DB::getQueryLog())->filter(fn ($query) => preg_match('/^(insert|update|delete)/i', $query['query']));
        DB::disableQueryLog();
        $this->assertSame($first->id, $same->id);
        $this->assertCount(0, $mutations);
        $store->store($listing->id, $this->quote(['observed_at' => '2026-11-06 15:01:00', 'source_time_raw' => '2026-11-06 15:01:00', 'effective_at' => '2026-11-06 15:01:00']));
        $this->assertSame(2, MarketPrice::count());
    }

    public function test_a_repeat_and_an_exact_xetra_alias_reuse_the_same_normalized_observation(): void
    {
        $listing = app(MarketIdentityRegistry::class)->listing($this->holding());
        $store = app(MarketPriceStore::class);
        $trade = $this->xetraSecond();
        $first = $store->store($listing->id, $trade);
        $equivalent = [
            ...$trade,
            'source_record_id' => 'second:2026-11-06T16:00:00+01:00',
            'observed_at' => '2026-11-06T16:00:00+01:00',
            'effective_at' => '2026-11-06T16:00:00+01:00',
            'fetched_at' => '2026-11-06T16:30:00+01:00',
        ];

        $this->assertSame($first->id, $store->store($listing->id, $equivalent)->id);
        $alias = $store->store($listing->id, [...$equivalent, 'source_key' => 'deutsche_boerse_trade', 'trade_count' => null]);
        $this->assertSame($first->id, $alias->id);
        $this->assertFalse($alias->wasRecentlyCreated);
        $this->assertSame(1, MarketPrice::count());

        $revised = $store->store($listing->id, [...$trade, 'price' => '195'], confirmedCorrection: true);
        $this->assertSame($first->id, $revised->supersedes_id);
        $this->assertSame($revised->id, MarketPrice::query()->where('is_current', true)->sole()->id);
    }

    public function test_a_batch_preserves_separate_xetra_event_times_and_independent_sources(): void
    {
        $listing = app(MarketIdentityRegistry::class)->listing($this->holding());
        $store = app(MarketPriceStore::class);
        $trade = $this->xetraSecond();
        $otherTime = [
            ...$trade,
            'source_record_id' => 'second:2026-11-06 15:00:01',
            'observed_at' => '2026-11-06 15:00:01',
            'effective_at' => '2026-11-06 15:00:01',
            'source_time_raw' => '2026-11-06T15:00:01.123456789Z',
        ];
        $alias = [...$trade, 'source_key' => 'deutsche_boerse_trade', 'trade_count' => null];
        $rows = array_map(fn (array $row): array => $store->prepare($listing->id, $row), [
            $trade,
            $alias,
            $otherTime,
            [...$trade, 'source_key' => 'yahoo_xetra_intraday'],
        ]);

        $first = $store->importBatch($rows);
        $retry = $store->importBatch($rows);

        $this->assertSame($first[0]->id, $first[1]->id);
        $this->assertTrue($first[0]->wasRecentlyCreated);
        $this->assertFalse($first[1]->wasRecentlyCreated);
        $this->assertSame(array_column($first, 'id'), array_column($retry, 'id'));
        $this->assertSame(3, MarketPrice::query()->where('is_current', true)->count());

        $otherEvent = $store->store($listing->id, [...$alias, 'source_time_raw' => '2026-11-06T15:00:00.987654321Z']);
        $this->assertNotSame($first[0]->id, $otherEvent->id);
        $this->assertSame(4, MarketPrice::query()->where('is_current', true)->count());
    }

    private function xetraSecond(): array
    {
        return $this->quote([
            'record_kind' => 'observation_1s', 'interval_code' => '1s', 'price_type' => 'observed_trade',
            'source_record_id' => 'second:2026-11-06 15:00:00', 'time_precision' => 'nanosecond',
            'source_time_raw' => '2026-11-06T15:00:00.123456789Z', 'trade_count' => 1,
        ]);
    }

    public function test_a_rounded_xetra_snapshot_requires_the_matching_precise_trade_from_its_report(): void
    {
        $listing = app(MarketIdentityRegistry::class)->listing($this->holding());
        $store = app(MarketPriceStore::class);
        $trade = $this->xetraSecond();
        $snapshot = $this->quote([
            'price_type' => 'observed_trade',
            'raw_payload' => ['trade_source' => 'Xetra', 'trade_at' => '2026-11-06T15:00:00+00:00',
                'days' => ['2026-11-06' => ['last_trade_at' => $trade['source_time_raw']]]],
        ]);
        $records = $store->importBatch([$store->prepare($listing->id, $snapshot), $store->prepare($listing->id, $trade)]);

        $this->assertSame($records[1]->id, $records[0]->id);
        $this->assertSame('observation_1s', $records[0]->record_kind);
        $this->assertSame($records[1]->id, $store->store($listing->id, $snapshot)->id);
        $this->assertSame(1, MarketPrice::count());

        $snapshot['raw_payload']['days']['2026-11-06']['last_trade_at'] = '2026-11-06T15:00:00.987654321Z';
        $unmatched = $store->store($listing->id, $snapshot);
        $this->assertSame('quote', $unmatched->record_kind);
        $this->assertNotSame($records[1]->id, $unmatched->id);
        $this->assertSame(2, MarketPrice::query()->where('is_current', true)->count());
    }

    public function test_corrections_preserve_revisions_and_ambiguous_or_older_inputs_cannot_replace_current(): void
    {
        $listing = app(MarketIdentityRegistry::class)->listing($this->holding());
        $store = app(MarketPriceStore::class);
        $first = $store->store($listing->id, $this->quote(['source_published_at' => '2026-11-06 15:10:00']));
        $ambiguous = $store->store($listing->id, $this->quote(['price' => '195']));
        $this->assertFalse($ambiguous->is_current);
        $correction = $store->store($listing->id, $this->quote(['price' => '194', 'source_published_at' => '2026-11-06 15:30:00']));
        $this->assertTrue($correction->is_current);
        $this->assertFalse($first->fresh()->is_current);
        $this->assertSame($first->id, $correction->supersedes_id);
        $store->store($listing->id, $this->quote(['source_published_at' => '2026-11-06 15:10:00']), confirmedCorrection: true);
        $this->assertSame($correction->id, MarketPrice::where('is_current', true)->sole()->id);
    }

    public function test_all_legacy_tables_have_repeatable_links_and_changes_are_revisited(): void
    {
        $holding = $this->holding();
        StockRealtimePrice::factory()->create(['stock_holding_id' => $holding->id, 'isin' => $holding->isin, 'currency' => 'EUR', 'mic' => 'XETR', 'price' => '193.12345678', 'as_of' => '2026-11-06 15:00:00']);
        $daily = StockHoldingDailyPrice::factory()->create(['stock_holding_id' => $holding->id, 'trading_date' => '2026-11-05', 'currency' => 'EUR', 'close' => '190.12345678']);
        $importer = app(MarketPriceLegacyImporter::class);
        $first = $importer->run(1);
        $this->assertCount(5, $first);
        $count = MarketPrice::count();
        $again = $importer->run(1);
        $this->assertSame($count, MarketPrice::count());
        $this->assertSame(1, $again['stock_holding_daily_prices']['unchanged']);
        $this->assertSame(2, DB::table('market_price_legacy_links')->count());
        $daily->update(['close' => '191.12345678']);
        $importer->run(1);
        $this->assertSame($count + 1, MarketPrice::count());
        $this->assertDatabaseHas('market_prices', ['interval_code' => '1d', 'observed_at' => null, 'time_basis' => 'session_close_derived']);
        $this->assertSame(1, DB::table('market_price_legacy_links')->where('legacy_table', 'stock_holding_daily_prices')->count());
        Http::assertNothingSent();
    }

    public function test_analysis_trend_summary_reads_the_central_store(): void
    {
        $holding = $this->holding();
        foreach (['2026-11-04' => 188, '2026-11-05' => 190, '2026-11-06' => 190] as $date => $value) {
            StockHoldingDailyPrice::factory()->create([
                'stock_holding_id' => $holding->id,
                'trading_date' => $date,
                'currency' => 'EUR',
                'close' => $value,
                'adjusted_close' => $value,
            ]);
        }
        app(MarketPriceLegacyImporter::class)->run(1);
        config(['services.market_data.central_enabled' => true]);
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('admin'));

        $this->actingAs($admin)->getJson('/admin/analysis/trends')->assertOk()
            ->assertJsonCount(1, 'trends')
            ->assertJsonPath('trends.0.id', $holding->id)
            ->assertJsonPath('trends.0.direction', 'up')
            ->assertJsonPath('trends.0.count', 2);
    }

    public function test_analysis_reads_stored_daily_prices_from_the_central_store(): void
    {
        $holding = $this->holding();
        StockHoldingDailyPrice::factory()->create([
            'stock_holding_id' => $holding->id,
            'trading_date' => '2026-11-05',
            'currency' => 'EUR',
            'close' => '190.00000000',
            'adjusted_close' => '189.00000000',
        ]);
        app(MarketPriceLegacyImporter::class)->run(1);
        config(['services.market_data.central_enabled' => true]);
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('admin'));
        Http::preventStrayRequests();

        $this->actingAs($admin)->getJson("/admin/analysis/holdings/{$holding->id}/historical-prices")
            ->assertOk()
            ->assertJsonCount(1, 'prices')
            ->assertJsonPath('prices.0.trading_date', '2026-11-05')
            ->assertJsonPath('prices.0.price', '189.00000000');
        Http::assertNothingSent();
    }

    public function test_central_readers_use_shared_prices_without_provider_requests_and_history_cache_avoids_scans(): void
    {
        $holding = $this->holding();
        $second = $holding->fresh();
        $registry = app(MarketIdentityRegistry::class);
        $listing = $registry->listing($holding);
        $registry->listing($second);
        $store = app(MarketPriceStore::class);
        $store->store($listing->id, $this->quote(['price' => '193.12345678', 'adjustment_basis' => 'unadjusted']));
        $store->selectLatest($listing->id);
        config(['services.market_data.central_enabled' => true]);
        $this->assertSame('193.12345678', $holding->refresh()->latestRealtimePrice->price);
        $this->assertSame('193.12345678', $second->refresh()->latestRealtimePrice->price);
        $this->assertSame(0, DB::table('stock_realtime_prices')->count());
        $resolver = app(StoredHistoricalPriceResolver::class);
        $holdings = collect([$holding, $second]);
        $resolver->prime($holdings, now()->subDays(10), now());
        DB::enableQueryLog();
        $resolver->prime($holdings, now()->subDays(10), now());
        $scans = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'market_prices'));
        DB::disableQueryLog();
        $this->assertCount(0, $scans);
        $this->assertNotNull($resolver->at($holding, now()->endOfDay()));
        Http::assertNothingSent();
    }

    public function test_source_detail_and_nanosecond_events_are_preserved_without_fabricating_candles(): void
    {
        $holding = $this->holding();
        $attributes = app(MarketPriceLegacyImporter::class)->attributes($holding, ['source_key' => 'xetra_posttrade', 'currency' => 'EUR',
            'interval' => 'xtrade', 'timestamp' => Carbon::parse('2026-11-06 15:00:00', 'UTC')->timestamp, 'close' => '193.00000001',
            'event_at' => '2026-11-06T15:00:00.123456789Z', 'events' => [['id' => 'a']], 'mic' => 'XETB']);
        $listing = app(MarketIdentityRegistry::class)->listing($holding);
        $price = app(MarketPriceStore::class)->store($listing->id, $attributes);
        $this->assertSame('observation_1s', $price->record_kind);
        $this->assertSame('2026-11-06T15:00:00.123456789Z', $price->source_time_raw);
        $this->assertNull($price->open);
        $this->assertNull($price->close);
        $this->assertSame([['id' => 'a']], $price->raw_payload['events']);
        $this->assertSame('XETB', $price->reported_mic);
    }

    public function test_global_watchlist_create_update_and_read_keep_instrument_identity_and_separate_listings(): void
    {
        config(['services.market_data.central_enabled' => true]);
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin'));
        $this->actingAs($user);
        $data = ['symbol' => 'TKE', 'name' => 'Take-Two', 'isin' => 'US8740541094', 'wkn' => '914508',
            'currency' => 'EUR', 'exchange' => 'XETRA', 'mic_code' => 'XETR'];
        $created = $this->postJson('/admin/watchlist/holdings', $data)->assertCreated();
        $holding = StockHolding::findOrFail($created->json('holding.id'));
        $original = $holding->market_listing_id;
        $price = app(MarketPriceStore::class)->store($original, $this->quote());
        app(MarketPriceStore::class)->selectLatest($original);
        $this->getJson('/admin/watchlist/holdings?all=1')->assertOk()->assertJsonPath('holdings.0.latest_price', '193.123457');
        $this->postJson('/admin/watchlist/holdings/charts?include_charts=1&all=1&chart_stock_id='.$holding->id.'&chart_range=year')->assertOk();
        $this->postJson('/admin/watchlist/holdings', $data)->assertUnprocessable()->assertJsonValidationErrors('isin');
        $this->patchJson('/admin/watchlist/holdings/'.$holding->id,
            [...$data, 'symbol' => 'TTWO', 'exchange' => 'NASDAQ', 'mic_code' => 'XNAS', 'currency' => 'USD'])->assertOk();
        $holding->refresh();
        $this->assertNotSame($original, $holding->market_listing_id);
        $this->assertSame($original, $price->fresh()->market_listing_id);
        $this->assertSame(1, DB::table('market_instruments')->count());
        $this->assertSame(2, DB::table('market_listings')->count());
        $this->assertSame(1, StockHolding::count());
        $this->assertNull($holding->latest_market_price_id);
        app(MarketPriceStore::class)->store($holding->market_listing_id, $this->quote(['currency' => 'USD', 'reported_mic' => null,
            'source_key' => 'yahoo_intraday', 'price' => '250']));
        app(MarketPriceStore::class)->selectLatest($holding->market_listing_id);
        $this->assertSame('250.00000000', $holding->refresh()->latestRealtimePrice->price);
        $this->assertSame(1, StockRealtimePrice::query()->where('stock_holding_id', $holding->id)->count());
        $this->getJson('/admin/watchlist/holdings?all=1')->assertOk();
        Http::assertNothingSent();
    }

    public function test_two_depots_reference_one_global_instrument_and_share_one_price_history(): void
    {
        $holding = $this->holding();
        foreach (range(1, 2) as $index) {
            $depot = Depot::factory()->create();
            DepotTransaction::factory()->create(['depot_id' => $depot->id, 'stock_holding_id' => $holding->id,
                'type' => 'buy', 'pieces' => 1, 'total_amount' => 193, 'cash_delta' => -193, 'currency' => 'EUR']);
        }
        $listing = app(MarketIdentityRegistry::class)->listing($holding);
        app(MarketPriceStore::class)->store($listing->id, $this->quote());
        $this->assertSame(2, $holding->depotTransactions()->distinct()->count('depot_id'));
        $this->assertSame(1, StockHolding::count());
        $this->assertSame(1, MarketPrice::count());
    }

    public function test_database_rejects_two_current_revisions_of_the_same_observation(): void
    {
        $first = MarketPrice::factory()->create();
        $copy = $first->toArray();
        unset($copy['id'], $copy['current_key']);
        $copy['revision'] = 2;
        $this->expectException(QueryException::class);
        MarketPrice::create($copy);
    }

    public function test_precise_quote_inside_a_bar_is_not_displaced_by_its_derived_interval_end(): void
    {
        $holding = $this->holding();
        $listing = app(MarketIdentityRegistry::class)->listing($holding);
        $store = app(MarketPriceStore::class);
        $quote = $store->store($listing->id, $this->quote(['observed_at' => '2026-11-06 15:04:47', 'effective_at' => '2026-11-06 15:04:47']));
        $store->store($listing->id, $this->quote(['record_kind' => 'bar', 'interval_code' => '5m', 'price' => null, 'close' => '194',
            'observed_at' => '2026-11-06 15:00:00', 'period_start_at' => '2026-11-06 15:00:00', 'period_end_at' => '2026-11-06 15:05:00', 'effective_at' => '2026-11-06 15:05:00']));
        $this->assertSame($quote->id, $store->selectLatest($listing->id)->id);
    }

    public function test_a_proven_xetra_snapshot_links_to_its_existing_observation_without_a_second_price_fact(): void
    {
        $holding = $this->holding();
        $event = '2026-11-06T15:00:00.123456789Z';
        StockRealtimePrice::factory()->create(['stock_holding_id' => $holding->id, 'isin' => $holding->isin,
            'currency' => 'EUR', 'mic' => 'XETB', 'source_key' => 'xetra_posttrade', 'price_type' => 'observed_trade',
            'price' => '193.12345678', 'last' => '193.12345678', 'as_of' => '2026-11-06 15:00:00',
            'raw_payload' => ['trade_source' => 'Xetra', 'trade_at' => '2026-11-06T15:00:00+00:00',
                'days' => ['2026-11-06' => ['last_trade_at' => $event]]]]);
        StockHoldingIntradayCandle::create(['stock_holding_id' => $holding->id, 'trading_date' => '2026-11-06',
            'interval' => 'xtrade', 'as_of' => '2026-11-06 15:00:00', 'close' => '193.12345678', 'currency' => 'EUR',
            'source_key' => 'xetra_posttrade', 'raw_payload' => ['mic' => 'XETB', 'event_at' => $event, 'events' => [['id' => 'trade-1']]]]);
        StockHoldingIntradayCandle::create(['stock_holding_id' => $holding->id, 'trading_date' => '2026-11-06',
            'interval' => 'trade', 'as_of' => '2026-11-06 15:00:00', 'close' => '193.12345678', 'currency' => 'EUR',
            'source_key' => 'deutsche_boerse_trade', 'raw_payload' => ['mic' => 'XETB', 'event_at' => $event]]);
        $importer = app(MarketPriceLegacyImporter::class);
        $importer->run();
        $this->assertSame(1, MarketPrice::count());
        $this->assertSame(3, DB::table('market_price_legacy_links')->count());
        $this->assertSame(2, DB::table('market_price_legacy_links')->where('status', 'merged')->count());
        $this->assertSame(1, DB::table('market_price_legacy_links')->distinct()->count('market_price_id'));
        $this->assertSame(3, app(MarketDataUpgrade::class)->verify()['legacy_rows']);
        $importer->run();
        $this->assertSame(1, MarketPrice::count());
    }
}
