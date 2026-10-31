<?php

namespace Tests\Feature;

use App\Models\StockHolding;
use App\Models\StockHoldingDailyPrice;
use App\Models\StockPrice;
use App\Models\StockRealtimePrice;
use App\Models\User;
use App\Services\DeutscheBoerseDelayedDataTest as DelayedDataTest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DeutscheBoerseDelayedDataTest extends TestCase
{
    use RefreshDatabase;

    private const Endpoint = '/admin/dashboard/tests/deutsche-boerse';

    private const File = 'DETR-posttrade-2026-10-30T16_30.json.gz';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-30 22:00:00', 'UTC'));
        Http::preventStrayRequests();
        Queue::fake();
    }

    public function test_manual_probe_reads_real_shaped_trades_and_keeps_price_tables_unchanged(): void
    {
        $this->signIn();
        $this->holding('FR0010655746', 'AMES');
        $this->holding('IE00B3RBWM25', 'VGWL');
        $this->holding('US37950E2596', 'ARGT', 'US', null, 'USD');
        StockPrice::factory()->create(['price' => 17]);
        StockRealtimePrice::factory()->create(['price' => 18]);
        StockHoldingDailyPrice::factory()->create(['close' => 19]);
        $names = ['DETR-posttrade-2026-10-30T21_44.json.gz', 'DETR-posttrade-2026-10-30T21_43.json.gz', self::File,
            'DETR-posttrade-2026-10-30T16_29.json.gz', 'DETR-posttrade-2026-10-30T16_28.json.gz'];
        Http::fake(function (Request $request) use ($names) {
            if ($request->url() === DelayedDataTest::CatalogUrl) {
                return Http::response($this->catalog($names));
            }
            if (str_ends_with($request->url(), self::File)) {
                return Http::response($this->gzip([
                    $this->trade(['price' => 100, 'tradingDateAndTime' => '2026-10-30T16:29:59.000000000Z']),
                    $this->trade(['price' => 123.45, 'tradingDateAndTime' => '2026-10-30T16:30:00.123456789Z']),
                    $this->trade(['price' => 999, 'instrumentIdentificationCode' => 'DE0007500001', 'mmtModificationInd' => 'CANC']),
                    $this->trade(['price' => 0]),
                ]));
            }

            return Http::response($this->gzip([]));
        });
        $response = $this->postJson(self::Endpoint)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('test.source', 'Deutsche Börse · Xetra Post-Trade')
            ->assertJsonPath('test.delay_minutes', 15)->assertJsonCount(4, 'test.files')
            ->assertJsonPath('test.records_checked', 2)->assertJsonPath('test.records_ignored', 2)
            ->assertJsonPath('test.matched_instruments', 1)
            ->assertJsonPath('test.latest_observed_trade_at', '2026-10-30T16:30:00.123456Z');
        $rows = collect($response->json('test.instruments'))->keyBy('isin');
        $this->assertSame('123.45', $rows['FR0010655746']['price']);
        $this->assertSame('XETA', $rows['FR0010655746']['trade_venue']);
        $this->assertSame('not_found', $rows['IE00B3RBWM25']['state']);
        $this->assertNull($rows['IE00B3RBWM25']['price']);
        $this->assertSame('not_xetra', $rows['US37950E2596']['state']);
        $this->assertGreaterThan(300, $response->json('test.observed_age_minutes'));
        $this->assertDatabaseCount('stock_prices', 1);
        $this->assertDatabaseCount('stock_realtime_prices', 1);
        $this->assertDatabaseCount('stock_holding_daily_prices', 1);
        $this->assertDatabaseCount('stock_holding_intraday_candles', 0);
        Http::assertSentCount(5);
        Queue::assertNothingPushed();
    }

    public function test_empty_catalog_and_empty_segments_do_not_invent_prices(): void
    {
        $this->signIn();
        $this->holding('FR0010655746', 'AMES');
        Http::fake([
            DelayedDataTest::CatalogUrl => Http::sequence()->push($this->catalog([]))->push($this->catalog([self::File])),
            'mfs.deutsche-boerse.com/api/download/*' => Http::response($this->gzip([])),
        ]);
        $this->postJson(self::Endpoint)->assertOk()->assertJsonPath('test.status', 'empty')
            ->assertJsonPath('test.latest_observed_trade_at', null)->assertJsonPath('test.instruments.0.price', null);
        $this->postJson(self::Endpoint)->assertOk()->assertJsonCount(1, 'test.files')->assertJsonPath('test.status', 'empty')
            ->assertJsonPath('test.instruments.0.state', 'not_found')->assertJsonPath('test.latest_observed_trade_at', null);
    }

    public function test_old_data_is_returned_with_its_actual_timestamp_and_age(): void
    {
        $this->signIn();
        $this->holding('FR0010655746', 'AMES');
        $this->fakeFile($this->gzip([$this->trade(['tradingDateAndTime' => '2026-10-29T12:00:00Z'])]));
        $response = $this->postJson(self::Endpoint)->assertOk()->assertJsonPath('test.instruments.0.trade_at', '2026-10-29T12:00:00.000000Z');
        $this->assertGreaterThan(1440, $response->json('test.observed_age_minutes'));
    }

    public function test_after_hours_probe_includes_late_closing_messages_for_the_atx_etf(): void
    {
        $this->signIn();
        $this->holding('DE000A0D8Q23', 'EXXX');
        $names = ['DETR-posttrade-2026-10-30T21_44.json.gz', 'DETR-posttrade-2026-10-30T21_43.json.gz',
            'DETR-posttrade-2026-10-30T16_36.json.gz', 'DETR-posttrade-2026-10-30T16_35.json.gz', self::File];
        Http::fake(function (Request $request) use ($names) {
            if ($request->url() === DelayedDataTest::CatalogUrl) {
                return Http::response($this->catalog($names));
            }

            return Http::response($this->gzip(str_contains($request->url(), 'T16_35.json.gz')
                ? [$this->trade(['instrumentIdentificationCode' => 'DE000A0D8Q23', 'price' => 68.69, 'tradingDateAndTime' => '2026-10-30T16:35:53.703011339Z'])]
                : []));
        });
        $response = $this->postJson(self::Endpoint)->assertOk()->assertJsonPath('test.instruments.0.price', '68.69')
            ->assertJsonPath('test.instruments.0.state', 'found')->assertJsonCount(4, 'test.files');
        $this->assertSame(array_slice($names, 0, 4), array_column($response->json('test.files'), 'name'));
        Http::assertSentCount(5);
    }

    public function test_currency_other_venues_corrections_and_invalid_timestamps_do_not_become_prices(): void
    {
        $this->signIn();
        $this->holding('FR0010655746', 'AMES');
        $this->fakeFile($this->gzip([
            $this->trade(['priceCurrency' => 'USD']), $this->trade(['venueOfExecution' => 'XFRA']),
            $this->trade(['mmtModificationInd' => 'AMND']), $this->trade(['priceNotation' => 2]),
            $this->trade(['tradingDateAndTime' => '']), $this->trade(['tradingDateAndTime' => 'tomorrow']),
            $this->trade(['tradingDateAndTime' => '2026-10-31T10:00:00Z']),
        ]));
        $this->postJson(self::Endpoint)->assertOk()->assertJsonPath('test.matched_instruments', 0)
            ->assertJsonPath('test.instruments.0.price', null)->assertJsonPath('test.instruments.0.trade_at', null);
    }

    public function test_only_the_providers_exact_storage_redirect_is_followed(): void
    {
        $this->signIn();
        $this->holding('FR0010655746', 'AMES');
        $target = 'https://storage.googleapis.com/mv-cef-prod-europe-west3-private-min-by-min-files/DETR/'.self::File.'?X-Goog-Signature=public-test-signature';
        Http::fake([
            DelayedDataTest::CatalogUrl => Http::response($this->catalog([self::File])),
            'mfs.deutsche-boerse.com/api/download/*' => Http::response('', 301, ['Location' => $target]),
            $target => Http::response($this->gzip([$this->trade()])),
        ]);
        $this->postJson(self::Endpoint)->assertOk()->assertJsonPath('test.matched_instruments', 1);
        Http::assertSentCount(3);
    }

    public function test_a_correction_suppresses_a_price_in_the_bounded_sample(): void
    {
        $this->signIn();
        $this->holding('FR0010655746', 'AMES');
        $this->fakeFile($this->gzip([$this->trade(['mmtModificationInd' => 'CANC']), $this->trade()]));
        $this->postJson(self::Endpoint)->assertOk()->assertJsonPath('test.instruments.0.state', 'correction')
            ->assertJsonPath('test.instruments.0.price', null)->assertJsonPath('test.matched_instruments', 0);
    }

    public function test_nanosecond_event_order_is_kept_when_selecting_the_last_trade(): void
    {
        $this->signIn();
        $this->holding('FR0010655746', 'AMES');
        $this->fakeFile($this->gzip([
            $this->trade(['price' => 100, 'tradingDateAndTime' => '2026-10-30T16:30:00.123456001Z']),
            $this->trade(['price' => 101, 'tradingDateAndTime' => '2026-10-30T16:30:00.123456999Z']),
        ]));
        $this->postJson(self::Endpoint)->assertOk()->assertJsonPath('test.instruments.0.price', '101');
    }

    public function test_instrument_and_decompression_limits_are_enforced(): void
    {
        $this->signIn();
        StockHolding::factory()->count(51)->create();
        $this->fakeFile(gzencode(str_repeat('x', 4194305)));
        $this->postJson(self::Endpoint)->assertOk()->assertJsonPath('test.instrument_limit_reached', true)
            ->assertJsonCount(50, 'test.instruments')->assertJsonPath('test.status', 'partial')
            ->assertJsonCount(0, 'test.files')->assertJsonPath('test.latest_observed_trade_at', null);
    }

    public function test_untrusted_redirects_and_filename_paths_are_not_requested(): void
    {
        $this->signIn();
        Http::fake([
            DelayedDataTest::CatalogUrl => Http::response($this->catalog([self::File, '../secrets.json.gz', 'https://evil.example/data.json.gz'])),
            'mfs.deutsche-boerse.com/api/download/*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/private']),
        ]);
        $this->postJson(self::Endpoint, ['url' => 'http://169.254.169.254/'])->assertOk()
            ->assertJsonPath('test.status', 'partial')->assertJsonCount(0, 'test.files')->assertJsonCount(1, 'test.warnings');
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request): bool => ! str_starts_with($request->url(), 'https://mfs.deutsche-boerse.com/'));
    }

    public function test_malformed_gzip_json_and_oversized_downloads_are_reported_without_writes(): void
    {
        $this->signIn();
        Http::fake([
            DelayedDataTest::CatalogUrl => Http::response($this->catalog([self::File])),
            'mfs.deutsche-boerse.com/api/download/*' => Http::sequence()->push('not gzip')->push(gzencode('not-json'))->push(str_repeat('x', 1048577)),
        ]);
        foreach (range(1, 3) as $attempt) {
            $this->postJson(self::Endpoint)->assertOk()->assertJsonPath('test.status', 'partial')
                ->assertJsonCount(0, 'test.files')->assertJsonPath('test.latest_observed_trade_at', null);
        }
        $this->assertDatabaseCount('stock_prices', 0);
        $this->assertDatabaseCount('stock_holding_intraday_candles', 0);
    }

    public function test_failed_or_changed_catalog_returns_a_safe_partial_result_for_continuation(): void
    {
        $this->signIn();
        Http::fake([DelayedDataTest::CatalogUrl => Http::sequence()->push('Invalid JSON', 200)->push(['SourcePrefix' => 'unexpected', 'CurrentFiles' => []])]);
        $this->postJson(self::Endpoint)->assertOk()->assertJsonPath('test.coverage.0.status', 'failed')->assertJsonPath('test.next_batch', 1);
        $this->postJson(self::Endpoint)->assertOk()->assertJsonPath('test.coverage.0.status', 'failed')->assertJsonPath('test.next_batch', 1);
    }

    public function test_test_requires_an_authorized_admin_and_does_not_run_from_a_get(): void
    {
        Http::fake();
        $this->postJson(self::Endpoint)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->postJson(self::Endpoint)->assertForbidden();
        $this->signIn();
        $this->getJson(self::Endpoint)->assertStatus(405);
        $this->get('/admin/menu/dashboard/tests')->assertOk();
        Http::assertNothingSent();
    }

    public function test_repeated_manual_downloads_are_rate_limited(): void
    {
        $this->signIn();
        Http::fake([DelayedDataTest::CatalogUrl => Http::response($this->catalog([]))]);
        foreach (range(1, 10) as $attempt) {
            $this->postJson(self::Endpoint)->assertOk();
        }
        $this->postJson(self::Endpoint)->assertStatus(429);
        Http::assertSentCount(10);
    }

    public function test_rbi_closing_trade_on_xetb_is_found_and_compared_without_changing_stored_prices(): void
    {
        $this->signIn();
        $this->holding('AT0000606306', 'RAW');
        $holding = StockHolding::query()->where('isin', 'AT0000606306')->firstOrFail();
        $quote = StockRealtimePrice::factory()->create(['stock_holding_id' => $holding->id, 'isin' => $holding->isin,
            'price' => 56.1, 'as_of' => '2026-10-30 16:35:00', 'mic' => 'XETR']);
        $this->fakeFile($this->gzip([$this->trade(['instrumentIdentificationCode' => $holding->isin,
            'price' => 56.1, 'venueOfExecution' => 'XETB', 'tradingDateAndTime' => '2026-10-30T16:35:40.336012321Z'])]));
        $this->postJson(self::Endpoint)->assertOk()->assertJsonPath('test.instruments.0.price', '56.1')
            ->assertJsonPath('test.instruments.0.trade_venue', 'XETB')->assertJsonPath('test.instruments.0.trade_source', 'Xetra')
            ->assertJsonPath('test.instruments.0.stored_quote.price', '56.10000000')
            ->assertJsonPath('test.instruments.0.stored_quote.as_of', '2026-10-30T16:35:00.000000Z')
            ->assertJsonPath('test.instruments.0.comparison.value_status', 'equal')
            ->assertJsonPath('test.instruments.0.comparison.comparable', false)
            ->assertJsonCount(2, 'test.instruments.0.comparison.reasons');
        $this->assertSame('56.10000000', $quote->fresh()->price);
        $this->assertDatabaseCount('stock_realtime_prices', 1);
        Queue::assertNothingPushed();
    }

    public function test_older_segments_are_paginated_with_a_fixed_anchor_and_bounded_downloads(): void
    {
        $this->signIn();
        $this->holding('FR0010655746', 'AMES');
        $names = ['DETR-posttrade-2026-10-30T21_59.json.gz', 'DETR-posttrade-2026-10-30T21_58.json.gz',
            'DETR-posttrade-2026-10-30T16_36.json.gz', 'DETR-posttrade-2026-10-30T16_35.json.gz',
            'DETR-posttrade-2026-10-30T15_10.json.gz', 'DETR-posttrade-2026-10-29T10_00.json.gz'];
        Http::fake(function (Request $request) use ($names) {
            if ($request->url() === DelayedDataTest::CatalogUrl) {
                return Http::response($this->catalog([...$names, 'DETR-posttrade-2026-10-30T22_00.json.gz']));
            }

            return Http::response($this->gzip(str_contains($request->url(), 'T15_10')
                ? [$this->trade(['tradingDateAndTime' => '2026-10-30T15:10:00Z'])] : []));
        });
        $this->postJson(self::Endpoint, ['batch' => 3, 'before' => '2026-10-30T21:59:00+00:00'])
            ->assertOk()->assertJsonCount(1, 'test.files')->assertJsonPath('test.next_round', null)
            ->assertJsonPath('test.files.0.name', $names[4])
            ->assertJsonPath('test.instruments.0.price', '123.45')->assertJsonPath('test.limits.seconds', 6);
        Http::assertSentCount(2);
    }

    public function test_frankfurt_and_tradegate_are_explicit_alternative_sources_with_exact_redirect_paths(): void
    {
        $this->signIn();
        $this->holding('FR0010655746', 'AMES');
        Http::fake(function (Request $request) {
            $source = str_contains($request->url(), 'DFRA') ? 'DFRA' : 'DGAT';
            $file = $source.'-posttrade-2026-10-30T20_59.json.gz';
            if (str_ends_with($request->url(), $source.'-posttrade')) {
                return Http::response(['SourcePrefix' => $source.'-posttrade', 'CurrentFiles' => [$file]]);
            }
            if (str_contains($request->url(), '/api/download/')) {
                return Http::response('', 301, ['Location' => 'https://storage.googleapis.com/mv-cef-prod-europe-west3-private-min-by-min-files/'.$source.'/'.$file]);
            }

            return Http::response($this->gzip([$this->trade(['venueOfExecution' => $source === 'DFRA' ? 'FRAB' : 'XGRM',
                'tradingDateAndTime' => '2026-10-30T20:59:00Z'])]));
        });
        $this->postJson(self::Endpoint, ['batch' => 2])->assertOk()
            ->assertJsonPath('test.instruments.0.trade_source', 'Frankfurt')->assertJsonPath('test.instruments.0.trade_venue', 'FRAB');
        $this->postJson(self::Endpoint, ['batch' => 1])->assertOk()
            ->assertJsonPath('test.instruments.0.trade_source', 'Tradegate')->assertJsonPath('test.instruments.0.trade_venue', 'XGRM');
        Http::assertSentCount(6);
    }

    public function test_comparison_selects_latest_valid_quote_and_distinguishes_different_and_missing_values(): void
    {
        $this->signIn();
        $this->holding('FR0010655746', 'AMES');
        $this->holding('IE00B3RBWM25', 'VGWL');
        $holding = StockHolding::query()->where('isin', 'FR0010655746')->firstOrFail();
        StockRealtimePrice::factory()->create(['stock_holding_id' => $holding->id, 'price' => 120, 'as_of' => '2026-10-30 16:29:00']);
        StockRealtimePrice::factory()->create(['stock_holding_id' => $holding->id, 'price' => 124, 'as_of' => '2026-10-30 16:30:00', 'mic' => 'XETA']);
        StockRealtimePrice::factory()->create(['stock_holding_id' => $holding->id, 'price' => 999, 'as_of' => '2026-10-30 16:31:00', 'validation_status' => 'invalid']);
        $this->fakeFile($this->gzip([$this->trade()]));
        $response = $this->postJson(self::Endpoint)->assertOk();
        $rows = collect($response->json('test.instruments'))->keyBy('isin');
        $this->assertSame('124.00000000', $rows['FR0010655746']['stored_quote']['price']);
        $this->assertSame('different', $rows['FR0010655746']['comparison']['value_status']);
        $this->assertTrue($rows['FR0010655746']['comparison']['comparable']);
        $this->assertNull($rows['IE00B3RBWM25']['stored_quote']);
        $this->assertNull($rows['IE00B3RBWM25']['comparison']['value_status']);
    }

    public function test_daily_fallback_and_currency_mismatch_are_not_directly_comparable(): void
    {
        $this->signIn();
        $this->holding('FR0010655746', 'AMES');
        $holding = StockHolding::query()->where('isin', 'FR0010655746')->firstOrFail();
        StockHoldingDailyPrice::factory()->create(['stock_holding_id' => $holding->id, 'trading_date' => '2026-10-29', 'close' => 123.45, 'currency' => 'USD']);
        StockRealtimePrice::factory()->create(['stock_holding_id' => $holding->id, 'price' => 99, 'as_of' => '2026-10-28 16:30:00']);
        $this->fakeFile($this->gzip([$this->trade()]));
        $this->postJson(self::Endpoint)->assertOk()->assertJsonPath('test.instruments.0.stored_quote.trading_date', '2026-10-29')
            ->assertJsonPath('test.instruments.0.stored_quote.as_of', null)
            ->assertJsonPath('test.instruments.0.comparison.value_status', null)
            ->assertJsonPath('test.instruments.0.comparison.reasons.0', 'Unterschiedliche Währungen');
    }

    public function test_invalid_search_ranges_cannot_trigger_downloads(): void
    {
        $this->signIn();
        $this->postJson(self::Endpoint, ['source' => 'http://localhost', 'round' => 121, 'batch' => 10, 'before' => '2027-01-01T00:00:00+00:00'])
            ->assertUnprocessable()->assertJsonValidationErrors(['source', 'round', 'batch', 'before']);
        Http::assertNothingSent();
    }

    public function test_equal_direct_comparison_and_missing_trade_have_distinct_labels(): void
    {
        $this->signIn();
        $this->holding('FR0010655746', 'AMES');
        $holding = StockHolding::query()->where('isin', 'FR0010655746')->firstOrFail();
        StockRealtimePrice::factory()->create(['stock_holding_id' => $holding->id, 'price' => 123.45,
            'as_of' => '2026-10-30 16:30:00', 'mic' => 'XETA']);
        Http::fake([
            DelayedDataTest::CatalogUrl => Http::response($this->catalog([self::File])),
            'mfs.deutsche-boerse.com/api/download/*' => Http::sequence()->push($this->gzip([$this->trade()]))->push($this->gzip([])),
        ]);
        $this->postJson(self::Endpoint)->assertOk()->assertJsonPath('test.instruments.0.comparison.value_status', 'equal')
            ->assertJsonPath('test.instruments.0.comparison.comparable', true);
        $this->postJson(self::Endpoint)->assertOk()->assertJsonPath('test.instruments.0.comparison.label', 'Börsentrade fehlt')
            ->assertJsonPath('test.instruments.0.comparison.comparable', false)->assertJsonPath('test.instruments.0.price', null)
            ->assertJsonPath('test.instruments.0.stored_quote.price', '123.45000000');
    }

    public function test_source_failure_allows_the_next_automatic_batch_and_preserves_tradegate_identity(): void
    {
        $this->signIn();
        $this->holding('AT0000606306', 'RAW');
        $holding = StockHolding::query()->where('isin', 'AT0000606306')->firstOrFail();
        StockRealtimePrice::factory()->create(['stock_holding_id' => $holding->id, 'price' => 56.1,
            'as_of' => '2026-10-30 16:35:00', 'mic' => 'XETR']);
        Http::fake([
            DelayedDataTest::CatalogUrl => Http::response('unavailable', 503),
            'https://mfs.deutsche-boerse.com/api/DGAT-posttrade' => Http::response([
                'SourcePrefix' => 'DGAT-posttrade', 'CurrentFiles' => ['DGAT-posttrade-2026-10-30T20_59.json.gz'],
            ]),
            'mfs.deutsche-boerse.com/api/download/*' => Http::response($this->gzip([$this->trade([
                'instrumentIdentificationCode' => $holding->isin, 'venueOfExecution' => 'XGAT', 'price' => 56.2,
                'tradingDateAndTime' => '2026-10-30T20:59:00Z',
            ])])),
        ]);
        $this->postJson(self::Endpoint)->assertOk()->assertJsonPath('test.coverage.0.source', 'Xetra')
            ->assertJsonPath('test.coverage.0.status', 'failed')->assertJsonPath('test.instruments.0.price', null)
            ->assertJsonPath('test.next_batch', 1);
        $this->postJson(self::Endpoint, ['batch' => 1, 'remaining_isins' => [$holding->isin]])->assertOk()
            ->assertJsonPath('test.instruments.0.trade_source', 'Tradegate')->assertJsonPath('test.instruments.0.trade_venue', 'XGAT')
            ->assertJsonPath('test.instruments.0.price', '56.2')->assertJsonPath('test.instruments.0.stored_quote.mic', 'XETR')
            ->assertJsonPath('test.instruments.0.comparison.comparable', false);
        Http::assertSentCount(3);
        $this->assertDatabaseCount('stock_realtime_prices', 1);
    }

    public function test_older_automatic_round_samples_trading_hours_beyond_the_initial_minutes(): void
    {
        $this->signIn();
        $this->holding('FR0010655746', 'AMES');
        $files = [];
        foreach (range(0, 370) as $minutes) {
            $files[] = 'DETR-posttrade-'.Carbon::parse('2026-10-30 16:36', 'UTC')->subMinutes($minutes)->format('Y-m-d\TH_i').'.json.gz';
        }
        Http::fake(function (Request $request) use ($files) {
            if ($request->url() === DelayedDataTest::CatalogUrl) {
                return Http::response($this->catalog($files));
            }

            return Http::response($this->gzip([$this->trade(['tradingDateAndTime' => '2026-10-30T10:00:00Z'])]));
        });
        $response = $this->postJson(self::Endpoint, ['batch' => 6])->assertOk()->assertJsonCount(2, 'test.files')
            ->assertJsonPath('test.coverage.0.source', 'Xetra')->assertJsonPath('test.coverage.0.round', 2)
            ->assertJsonPath('test.limits.seconds', 6)->assertJsonPath('test.instruments.0.state', 'found');
        $this->assertGreaterThanOrEqual(180, Carbon::parse($response->json('test.coverage.0.from'))->diffInMinutes(Carbon::parse($response->json('test.coverage.0.to'))));
        Http::assertSentCount(3);
    }

    public function test_continuation_filters_requested_isins_to_the_authorized_bounded_holding_set(): void
    {
        $this->signIn();
        $this->holding('FR0010655746', 'AMES');
        $this->holding('IE00B3RBWM25', 'VGWL');
        $this->fakeFile($this->gzip([$this->trade()]));
        $this->postJson(self::Endpoint, ['remaining_isins' => ['IE00B3RBWM25', 'AT0000606306']])->assertOk()
            ->assertJsonCount(1, 'test.instruments')->assertJsonPath('test.instruments.0.isin', 'IE00B3RBWM25')
            ->assertJsonPath('test.instruments.0.price', null);
    }

    public function test_provider_rate_limit_stops_downloads_for_that_source_without_disabling_fallback(): void
    {
        $this->signIn();
        $this->holding('FR0010655746', 'AMES');
        Http::fake([
            DelayedDataTest::CatalogUrl => Http::response($this->catalog([self::File, 'DETR-posttrade-2026-10-30T16_29.json.gz'])),
            'mfs.deutsche-boerse.com/api/download/*' => Http::response('', 429),
        ]);
        $this->postJson(self::Endpoint)->assertOk()->assertJsonPath('test.rate_limited', true)
            ->assertJsonPath('test.next_round', null)->assertJsonPath('test.next_batch', 1)
            ->assertJsonCount(0, 'test.files')->assertJsonCount(1, 'test.warnings');
        Http::assertSentCount(2);
    }

    public function test_catalog_429_is_reported_as_a_provider_rate_limit_without_any_file_requests(): void
    {
        $this->signIn();
        Http::fake([DelayedDataTest::CatalogUrl => Http::response('', 429)]);
        $this->postJson(self::Endpoint)->assertOk()->assertJsonPath('test.rate_limited', true)
            ->assertJsonCount(1, 'test.warnings')->assertJsonCount(0, 'test.files');
        Http::assertSentCount(1);
    }

    /** @param array<int, string> $files @return array<string, mixed> */
    private function catalog(array $files): array
    {
        return ['SourcePrefix' => 'DETR-posttrade', 'CurrentFiles' => $files, 'GenerationDatetime' => '2026-10-30 21:59:00.000 UTC'];
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function trade(array $overrides = []): array
    {
        return [...['messageId' => 'posttrade', 'instrumentIdentificationCode' => 'FR0010655746', 'priceCurrency' => 'EUR',
            'price' => 123.45, 'priceNotation' => 1, 'mmtModificationInd' => '-', 'venueOfExecution' => 'XETA',
            'tradingDateAndTime' => '2026-10-30T16:30:00.000000000Z'], ...$overrides];
    }

    /** @param array<int, array<string, mixed>> $trades */
    private function gzip(array $trades): string
    {
        return gzencode(implode("\n", array_map(fn (array $trade): string => json_encode($trade, JSON_THROW_ON_ERROR), $trades)));
    }

    private function fakeFile(string $body): void
    {
        Http::fake([
            DelayedDataTest::CatalogUrl => Http::response($this->catalog([self::File])),
            'mfs.deutsche-boerse.com/api/download/*' => Http::response($body),
        ]);
    }

    private function holding(string $isin, string $symbol, string $exchange = 'XETRA', ?string $mic = 'XETR', string $currency = 'EUR'): void
    {
        StockHolding::factory()->create(['isin' => $isin, 'symbol' => $symbol, 'exchange' => $exchange, 'mic_code' => $mic, 'currency' => $currency]);
    }

    private function signIn(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin', 'web'));
        $this->actingAs($user);
    }
}
