<?php

namespace App\Services;

use App\Models\StockHolding;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class MarketDataCoordinator
{
    public function __construct(private MarketIdentityRegistry $identities, private MarketPriceLegacyImporter $normalizer,
        private MarketPriceStore $store, private FreeTradingCalendar $calendar, private XetraIntradayCollector $xetra, private YahooFinanceMarketData $yahoo) {}

    /** @return array<string, mixed> */
    public function collect(?StockHolding $only = null): array
    {
        if (app(MarketDataMode::class)->paused()) {
            return $this->result(0, 0, ['Market-data deployment upgrade is in progress.']);
        }
        app(PreviewIsolation::class)->assertIntegrationAllowed();
        $result = Cache::lock('market-data.coordinator', 240)->get(fn (): array => $this->collectLocked($only));

        return $result ?? $this->result(0, 0, ['Another coordinated collection is running.']);
    }

    /** @return array<string, mixed> */
    private function collectLocked(?StockHolding $only): array
    {
        $holdings = $only ? collect([$only]) : StockHolding::query()->orderBy('id')->get();
        $unique = $holdings->map(function (StockHolding $holding): StockHolding {
            $this->identities->listing($holding);

            return $holding;
        })->unique('market_listing_id')->filter(fn (StockHolding $holding): bool => $this->calendar->shouldCollect($holding));
        $stored = 0;
        $invalidated = false;
        $errors = [];
        $eu = $unique->filter(fn ($holding) => IntradayMarketIdentity::isXetra($holding->toArray()));
        if ($eu->isNotEmpty() && $this->reserve('xetra')) {
            try {
                $report = $this->xetra->collect(advance: true, refreshCatalog: true, coordinated: true);
                foreach ($eu as $holding) {
                    if (in_array($holding->isin, $report['unresolved_corrections'] ?? [], true)) {
                        $quarantined = DB::table('market_prices')->where('market_listing_id', $holding->market_listing_id)->where('source_key', 'xetra_posttrade')
                            ->where('validation_status', '!=', 'quarantined')->whereIn('trading_date', array_keys($report['days']))->update(['validation_status' => 'quarantined']);
                        if ($quarantined > 0) {
                            DB::table('market_listings')->where('id', $holding->market_listing_id)->increment('data_version');
                            $invalidated = true;
                        }
                    }
                    $stored += $this->persist($holding, array_values($report['series'][$holding->isin] ?? []));
                    $this->store->selectLatest($holding->market_listing_id);
                }
                $errors = [...$errors, ...$report['warnings']];
                $this->record('xetra', null, $report['complete'] ? 'complete' : 'partial', array_diff_key($report, ['series' => true, 'instruments' => true]));
            } catch (Throwable $exception) {
                $errors[] = $exception->getMessage();
                $this->record('xetra', null, 'failed', ['error' => $exception->getMessage()]);
            }
        }
        foreach ($unique as $holding) {
            $us = YahooFinanceMarketData::supports($holding->toArray());
            $supplement = YahooFinanceMarketData::supportsXetra($holding->toArray());
            $symbol = $us ? $holding->symbol : config('services.free_intraday.yahoo_xetra_symbols.'.$holding->isin);
            if ((! $us && ! $supplement) || ! $this->reserve('yahoo-symbol:'.$symbol) || Cache::has('free-intraday.cooldown.yahoo')) {
                continue;
            }
            try {
                $report = $us ? $this->yahoo->run(collect([$holding->toArray()]), includeSeries: true, coordinated: true) : $this->yahoo->runXetra($holding->toArray(), coordinated: true);
                $row = $report['instruments'][0] ?? [];
                $stored += $this->persist($holding, $row['series'] ?? []);
                if (($row['price'] ?? null) !== null) {
                    $quote = ['source_key' => $us ? 'yahoo_intraday' : 'yahoo_xetra_intraday', 'source_name' => $report['source'],
                        'source_url' => 'https://finance.yahoo.com/quote/'.rawurlencode($symbol).'/',
                        'price' => $row['price'], 'last' => $row['price'], 'as_of' => $row['trade_at'], 'fetched_at' => $report['checked_at'],
                        'currency' => $holding->currency, 'price_type' => 'regular_market_last', 'raw_payload' => array_diff_key($row, ['series' => true])];
                    $attributes = $this->normalizer->attributes($holding, $quote);
                    $attributes['reported_mic'] = $us ? null : 'XETR';
                    $stored += (int) $this->store->store($holding->market_listing_id, $attributes)->wasRecentlyCreated;
                }
                $this->store->selectLatest($holding->market_listing_id);
                $errors = [...$errors, ...$report['warnings']];
                $this->record('yahoo', $holding->market_listing_id, $report['status'], ['warnings' => $report['warnings']]);
            } catch (Throwable $exception) {
                $errors[] = $exception->getMessage();
                $this->record('yahoo', $holding->market_listing_id, 'failed', ['error' => $exception->getMessage()]);
            }
        }

        if ($stored > 0 || $invalidated) {
            $this->prepareReads();
        }

        return $this->result($unique->count(), $stored, $errors);
    }

    public function prepareReads(): void
    {
        $holdings = StockHolding::query()->whereNotNull('market_listing_id')->get();
        $from = now()->startOfYear()->subDays(14);
        app(MarketPriceCompatibility::class)->history($holdings, $from, now());
        app(MarketPriceCompatibility::class)->history($holdings, $from, now(), freeOnly: true);
    }

    public function reserve(string $key): bool
    {
        return Cache::add('market-data.poll.'.$key, true, now()->addSeconds(300));
    }

    /** @param array<int, array<string, mixed>> $rows */
    public function persist(StockHolding $holding, array $rows): int
    {
        $listing = $this->identities->listing($holding);
        $prepared = [];
        foreach ($rows as $row) {
            $row['fetched_at'] ??= str_starts_with($row['source_key'], 'yahoo') ? now()->utc()->toDateTimeString() : null;
            $attributes = $this->normalizer->attributes($holding, $row);
            if (str_starts_with($row['source_key'], 'yahoo') && $holding->currency === 'EUR') {
                $attributes['reported_mic'] = 'XETR';
                $attributes['raw_payload']['mic'] = 'XETR';
            }
            $data = $this->store->prepare($listing->id, $attributes);
            $prepared[$data['observation_key'].$data['payload_hash']] = $data;
        }
        $stored = 0;
        foreach (array_chunk(array_values($prepared), 300) as $batch) {
            $stored += collect($this->store->importBatch($batch))->where('wasRecentlyCreated', true)->count();
        }

        return $stored;
    }

    /** @param array<string, mixed> $details */
    public function record(string $source, ?int $listingId, string $status, array $details): void
    {
        DB::table('market_data_runs')->insert(['source_key' => $source, 'market_listing_id' => $listingId,
            'status' => $status, 'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now()->utc(), 'updated_at' => now()->utc()]);
    }

    /** @param array<int, string> $errors @return array<string, mixed> */
    private function result(int $requested, int $stored, array $errors): array
    {
        return ['message' => 'Central market data: '.$stored.' new observations/revisions. Five-minute polling; provider delay retained.',
            'requested_count' => $requested, 'stored_count' => $stored, 'unchanged_count' => 0, 'updated_count' => $stored,
            'failed_count' => count($errors), 'errors' => $errors, 'coverage' => [], 'coverage_complete_count' => 0, 'no_trade_count' => 0];
    }
}
