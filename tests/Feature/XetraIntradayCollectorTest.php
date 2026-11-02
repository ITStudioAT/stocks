<?php

namespace Tests\Feature;

use App\Jobs\CollectFreeIntradayData;
use App\Models\StockHolding;
use App\Models\StockHoldingIntradayCandle;
use App\Services\FreeIntradayMarketData;
use App\Services\XetraIntradayCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class XetraIntradayCollectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-02 11:00', 'Europe/Berlin'));
        Storage::fake('local');
        Http::preventStrayRequests();
        config(['services.free_intraday.session_start' => '09:00', 'services.free_intraday.session_end' => '09:03',
            'services.free_intraday.batch_files' => 20]);
    }

    public function test_all_eight_previously_missed_isins_are_found_only_after_all_files_are_processed_and_resume_without_redownloads(): void
    {
        $identities = ['CZX' => 'BGCZPX003174', 'SXRY' => 'IE00B53L4X51', 'DJAM' => 'FR0007056841', 'WDEY' => 'IE0008RX29L5',
            'AMEE' => 'FR0010930644', 'SPYU' => 'IE00BKWQ0P07', 'V9N' => 'IE00BMH5Y327', 'UN9' => 'AT0000821103'];
        $trades = [];
        foreach ($identities as $symbol => $isin) {
            StockHolding::factory()->create(['symbol' => $symbol, 'isin' => $isin, 'exchange' => 'XETRA', 'mic_code' => 'XETR', 'currency' => 'EUR']);
            $trades[] = $this->trade($isin);
        }
        $this->files(['08_01' => $trades]);
        config(['services.free_intraday.batch_files' => 2]);
        $collector = app(XetraIntradayCollector::class);
        $first = $collector->collect();
        $this->assertFalse($first['complete']);
        $this->assertSame(2, $first['pending_files']);
        $this->assertSame([], $first['series']);
        $second = $collector->collect(advance: true);
        $this->assertTrue($second['complete']);
        $this->assertSame(0, $second['pending_files']);
        foreach ($identities as $isin) {
            $this->assertCount(1, $second['series'][$isin]);
            $this->assertSame($isin, $second['series'][$isin][0]['isin']);
            $this->assertSame('xtrade', $second['series'][$isin][0]['interval']);
            $this->assertNull($second['series'][$isin][0]['open']);
        }
        Http::assertSentCount(5);
        $collector->collect(advance: true);
        Http::assertSentCount(5);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'DGAT') || str_contains($request->url(), 'DFRA') || str_contains($request->url(), 'eodhd'));
    }

    public function test_daily_files_stream_beyond_the_old_expanded_limit_and_duplicate_events_are_not_counted_twice(): void
    {
        $holding = $this->holding();
        $duplicate = $this->trade($holding->isin);
        $largeDaily = str_repeat(json_encode($this->trade('DE0000000001'))."\n", 22000)
            .json_encode($this->trade($holding->isin, '2026-10-30T08:01:00.000000001Z'))."\n";
        $this->files(['08_00' => [$duplicate], '08_01' => [$duplicate]], $largeDaily);
        $result = app(XetraIntradayCollector::class)->collect();
        $this->assertTrue($result['complete']);
        $this->assertTrue($result['days']['2026-10-30']['daily_file']);
        $this->assertCount(2, $result['series'][$holding->isin]);
        $this->assertSame(2, $result['instruments'][0]['trade_count']);
    }

    public function test_http_failure_remains_pending_and_only_the_failed_file_is_retried(): void
    {
        $this->holding();
        $fail = true;
        $this->files([], failure: function (string $file) use (&$fail): ?int {
            return $fail && str_contains($file, '08_01') ? 503 : null;
        });
        $collector = app(XetraIntradayCollector::class);
        $first = $collector->collect();
        $this->assertSame(1, $first['pending_files']);
        $this->assertSame('incomplete', $first['instruments'][0]['status']);
        $this->assertStringContainsString('503', implode(' ', $first['days']['2026-11-02']['file_errors']));
        $fail = false;
        $this->travel(31)->seconds();
        $second = $collector->collect(advance: true);
        $this->assertTrue($second['complete']);
        $this->assertSame('no_trades', $second['instruments'][0]['status']);
        Http::assertSentCount(7);
    }

    public function test_missing_catalogue_minutes_are_not_reported_as_no_trading(): void
    {
        $this->holding();
        $this->files([], omit: '08_01');
        $result = app(XetraIntradayCollector::class)->collect();
        $this->assertFalse($result['complete']);
        $this->assertSame('incomplete', $result['instruments'][0]['status']);
        $this->assertSame(1, $result['pending_files']);
        $this->assertSame([], $result['series']);
    }

    public function test_wrong_currency_or_venue_cannot_complete_a_file(): void
    {
        $holding = $this->holding();
        $this->files(['08_01' => [[...$this->trade($holding->isin), 'venueOfExecution' => 'XGAT']]]);
        $result = app(XetraIntradayCollector::class)->collect();
        $this->assertFalse($result['complete']);
        $this->assertSame([], $result['series']);
        $this->assertStringContainsString('mismatch', implode(' ', $result['days']['2026-11-02']['file_errors']));
    }

    public function test_early_and_late_xetra_trades_are_included_with_an_explicit_published_window(): void
    {
        $holding = $this->holding();
        $this->travelTo(Carbon::parse('2026-11-02 19:00', 'Europe/Berlin'));
        config(['services.free_intraday.session_start' => '08:00', 'services.free_intraday.session_end' => '22:01']);
        $files = ['DETR-posttrade-daily-2026-10-30.json.gz' => '',
            'DETR-posttrade-2026-11-02T07_01.json.gz' => json_encode($this->trade($holding->isin, '2026-11-02T07:01:00Z')),
            'DETR-posttrade-2026-11-02T17_01.json.gz' => json_encode($this->trade($holding->isin, '2026-11-02T17:01:00Z'))];
        Http::fake(function ($request) use ($files) {
            if ($request->url() === XetraIntradayCollector::CatalogUrl) {
                return Http::response(['SourcePrefix' => 'DETR-posttrade', 'CurrentFiles' => array_keys($files)]);
            }

            return Http::response(gzencode($files[basename(parse_url($request->url(), PHP_URL_PATH))]));
        });
        $result = app(XetraIntradayCollector::class)->collect();
        $this->assertCount(2, $result['series'][$holding->isin]);
        $this->assertSame('2026-11-02T07:00:00+00:00', $result['days']['2026-11-02']['from']);
        $this->assertSame('2026-11-02T17:44:00+00:00', $result['days']['2026-11-02']['until']);
        $this->assertFalse($result['days']['2026-11-02']['complete']);
    }

    public function test_same_second_events_keep_nanosecond_order_and_all_real_events(): void
    {
        $holding = $this->holding();
        $first = $this->trade($holding->isin);
        $last = [...$first, 'tradingDateAndTime' => '2026-11-02T08:01:00.999999999Z', 'price' => 11, 'transactionIdentificationCode' => 'second'];
        $this->files(['08_01' => [$last, $first]]);
        $result = app(XetraIntradayCollector::class)->collect();
        $sample = $result['series'][$holding->isin][0];
        $this->assertSame('11.00000000', $sample['close']);
        $this->assertSame(2, $sample['trade_count']);
        $this->assertCount(2, $sample['events']);
    }

    public function test_rate_limit_stops_downloads_and_keeps_resumable_progress(): void
    {
        $this->holding();
        $this->files([], failure: fn (string $filename): ?int => 429);
        $collector = app(XetraIntradayCollector::class);
        $result = $collector->collect();
        $this->assertFalse($result['complete']);
        Http::assertSentCount(2);
        $collector->collect(advance: true);
        Http::assertSentCount(2);
    }

    public function test_corrections_quarantine_affected_saved_observations_without_deleting_them_or_using_yahoo(): void
    {
        $holding = $this->holding();
        $trade = $this->trade($holding->isin);
        $this->files(['08_02' => [$trade], '08_01' => [[...$trade, 'mmtModificationInd' => 'CANC']]]);
        config(['services.free_intraday.batch_files' => 2]);
        $market = app(FreeIntradayMarketData::class);
        $market->syncAll();
        $this->assertSame(1, StockHoldingIntradayCandle::query()->count());
        $market->xetraCoverage(advance: true);
        $result = $market->syncAll();
        $this->assertSame(0, StockHoldingIntradayCandle::query()->count());
        $this->assertSame(1, StockHoldingIntradayCandle::withoutGlobalScopes()->count());
        $this->assertTrue(StockHoldingIntradayCandle::withoutGlobalScopes()->firstOrFail()->raw_payload['quarantined']);
        $this->assertSame('unresolved_corrections', $result['coverage'][0]['status']);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'yahoo'));
    }

    public function test_unresolved_old_gaps_remain_visible_after_the_catalogue_retention_expires(): void
    {
        $this->holding();
        $this->files([], omit: '08_01');
        $collector = app(XetraIntradayCollector::class);
        $collector->collect();
        $this->travelTo(Carbon::parse('2026-11-05 11:00', 'Europe/Berlin'));
        $result = $collector->collect(advance: true);
        $this->assertArrayHasKey('2026-11-02', $result['days']);
        $this->assertFalse($result['days']['2026-11-02']['complete']);
        $this->assertContains('DETR-posttrade-2026-11-02T08_01.json.gz', $result['days']['2026-11-02']['missing_files']);
    }

    public function test_concurrent_collectors_release_without_changing_quote_availability_or_requesting_the_provider(): void
    {
        $holding = $this->holding();
        $holding->update(['price_status' => 'delayed']);
        $lock = Cache::lock('free-intraday.xetra.collector.v1', 150);
        $this->assertTrue($lock->get());
        try {
            $job = (new CollectFreeIntradayData)->withFakeQueueInteractions();
            app()->call([$job, 'handle']);
            $job->assertReleased(5);
            app(FreeIntradayMarketData::class)->syncAll();
            $this->assertSame('delayed', $holding->fresh()->price_status);
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    public function test_command_drains_bounded_batches_and_the_queue_job_releases_until_the_files_are_complete(): void
    {
        $holding = $this->holding();
        $this->files(['08_01' => [$this->trade($holding->isin)]]);
        config(['services.free_intraday.batch_files' => 2]);
        $job = (new CollectFreeIntradayData)->withFakeQueueInteractions();
        app()->call([$job, 'handle']);
        $job->assertReleased(5);
        $this->artisan('intraday:collect-free', ['--batches' => 2, '--json' => true])->assertExitCode(0);
        $this->assertSame(1, StockHoldingIntradayCandle::query()->count());
        $this->assertSame('xetra_posttrade', StockHoldingIntradayCandle::query()->firstOrFail()->source_key);
        Queue::fake();
        $this->artisan('intraday:collect-free', ['--queue' => true])->assertExitCode(0);
        Queue::assertPushed(CollectFreeIntradayData::class);
    }

    public function test_new_instruments_replay_archived_files_without_network_redownloads(): void
    {
        $first = $this->holding();
        $this->files(['08_01' => [$this->trade($first->isin), $this->trade('IE00B53L4X51')]]);
        $collector = app(XetraIntradayCollector::class);
        $collector->collect();
        StockHolding::factory()->create(['isin' => 'IE00B53L4X51', 'symbol' => 'SXRY', 'exchange' => 'XETRA', 'mic_code' => 'XETR', 'currency' => 'EUR']);
        $result = $collector->collect(advance: true);
        $this->assertTrue($result['complete']);
        $this->assertCount(1, $result['series']['IE00B53L4X51']);
        Http::assertSentCount(5);
    }

    public function test_corrupt_gzip_footer_stays_pending_and_is_not_cached_as_complete(): void
    {
        $this->holding();
        $files = ['DETR-posttrade-daily-2026-10-30.json.gz', 'DETR-posttrade-2026-11-02T08_00.json.gz',
            'DETR-posttrade-2026-11-02T08_01.json.gz', 'DETR-posttrade-2026-11-02T08_02.json.gz'];
        Http::fake(function ($request) use ($files) {
            if ($request->url() === XetraIntradayCollector::CatalogUrl) {
                return Http::response(['SourcePrefix' => 'DETR-posttrade', 'CurrentFiles' => $files]);
            }
            $body = gzencode('');
            if (str_contains($request->url(), '08_01')) {
                $body = substr($body, 0, -8).pack('VV', 123, 0);
            }

            return Http::response($body);
        });
        $result = app(XetraIntradayCollector::class)->collect();
        $this->assertFalse($result['complete']);
        $this->assertSame(1, $result['pending_files']);
        $this->assertStringContainsString('Corrupt', implode(' ', $result['days']['2026-11-02']['file_errors']));
    }

    public function test_official_daily_and_minute_storage_redirects_are_processed(): void
    {
        $holding = $this->holding();
        $files = ['DETR-posttrade-daily-2026-10-30.json.gz', 'DETR-posttrade-2026-11-02T08_00.json.gz',
            'DETR-posttrade-2026-11-02T08_01.json.gz', 'DETR-posttrade-2026-11-02T08_02.json.gz'];
        Http::fake(function ($request) use ($files, $holding) {
            if ($request->url() === XetraIntradayCollector::CatalogUrl) {
                return Http::response(['SourcePrefix' => 'DETR-posttrade', 'CurrentFiles' => $files]);
            }
            $filename = basename(parse_url($request->url(), PHP_URL_PATH));
            if (str_starts_with($request->url(), 'https://mfs.deutsche-boerse.com/')) {
                $folder = str_contains($filename, '-daily-') ? 'daily/' : '';

                return Http::response('', 301, ['Location' => 'https://storage.googleapis.com/mv-cef-prod-europe-west3-private-min-by-min-files/DETR/'.$folder.$filename.'?signature=fixture']);
            }

            return Http::response(gzencode(str_contains($filename, '08_01') ? json_encode($this->trade($holding->isin)) : ''));
        });
        $result = app(XetraIntradayCollector::class)->collect();
        $this->assertTrue($result['complete']);
        $this->assertSame(1, $result['instruments'][0]['trade_count']);
        Http::assertSentCount(9);
    }

    public function test_concatenated_gzip_members_in_daily_archives_are_all_validated_and_processed(): void
    {
        $holding = $this->holding();
        $daily = 'DETR-posttrade-daily-2026-10-30.json.gz';
        $files = [$daily, 'DETR-posttrade-2026-11-02T08_00.json.gz',
            'DETR-posttrade-2026-11-02T08_01.json.gz', 'DETR-posttrade-2026-11-02T08_02.json.gz'];
        $corrupt = true;
        Http::fake(function ($request) use ($files, $holding, $daily, &$corrupt) {
            if ($request->url() === XetraIntradayCollector::CatalogUrl) {
                return Http::response(['SourcePrefix' => 'DETR-posttrade', 'CurrentFiles' => $files]);
            }
            $body = gzencode('');
            if (str_ends_with($request->url(), $daily)) {
                $member = gzencode(json_encode($this->trade($holding->isin, '2026-10-30T08:01:00Z'))."\n");
                if ($corrupt) {
                    $member = substr($member, 0, -8).pack('VV', 123, 0);
                }
                $body .= $member.gzencode('');
            }

            return Http::response($body);
        });
        $collector = app(XetraIntradayCollector::class);
        $this->assertFalse($collector->collect()['complete']);
        $corrupt = false;
        $this->travel(31)->seconds();
        $result = $collector->collect(advance: true);
        $this->assertTrue($result['complete']);
        $this->assertSame(1, $result['instruments'][0]['trade_count']);
        $this->assertSame('2026-10-30T08:01:00.000000000Z', $result['series'][$holding->isin][0]['event_at']);
    }

    public function test_untrusted_redirect_targets_are_blocked(): void
    {
        $this->holding();
        Http::fake(function ($request) {
            if ($request->url() === XetraIntradayCollector::CatalogUrl) {
                return Http::response(['SourcePrefix' => 'DETR-posttrade', 'CurrentFiles' => ['DETR-posttrade-daily-2026-10-30.json.gz']]);
            }

            return Http::response('', 302, ['Location' => 'https://untrusted.invalid/collect']);
        });
        $result = app(XetraIntradayCollector::class)->collect();
        $this->assertFalse($result['complete']);
        $this->assertStringContainsString('redirect blocked', implode(' ', $result['days']['2026-10-30']['file_errors']));
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'untrusted.invalid'));
    }

    private function holding(): StockHolding
    {
        return StockHolding::factory()->create(['isin' => 'BGCZPX003174', 'symbol' => 'CZX', 'exchange' => 'XETRA', 'mic_code' => 'XETR', 'currency' => 'EUR']);
    }

    /** @return array<string, mixed> */
    private function trade(string $isin, string $at = '2026-11-02T08:01:00.000000001Z'): array
    {
        return ['messageId' => 'posttrade', 'instrumentIdentificationCode' => $isin, 'priceCurrency' => 'EUR', 'priceNotation' => 1,
            'venueOfExecution' => 'XETA', 'price' => 10, 'quantity' => 2, 'mmtModificationInd' => '-',
            'tradingDateAndTime' => $at, 'transactionIdentificationCode' => $isin.'|'.$at];
    }

    /** @param array<string, array<int, array<string, mixed>>> $minutes */
    private function files(array $minutes, string $daily = '', ?callable $failure = null, ?string $omit = null): void
    {
        $files = ['DETR-posttrade-daily-2026-10-30.json.gz' => $daily];
        foreach (['08_00', '08_01', '08_02'] as $minute) {
            if ($minute !== $omit) {
                $files['DETR-posttrade-2026-11-02T'.$minute.'.json.gz'] = implode("\n", array_map(fn (array $trade): string => json_encode($trade), $minutes[$minute] ?? []));
            }
        }
        Http::fake(function ($request) use ($files, $failure) {
            $filename = basename(parse_url($request->url(), PHP_URL_PATH));
            if ($filename === 'DETR-posttrade') {
                return Http::response(['SourcePrefix' => 'DETR-posttrade', 'CurrentFiles' => array_keys($files)]);
            }
            $status = $failure === null ? null : $failure($filename);

            return Http::response($status === null ? gzencode($files[$filename] ?? '') : '', $status ?? 200);
        });
    }
}
