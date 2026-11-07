<?php

namespace App\Services;

use App\Models\StockHolding;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class CloudwaysFreeMarketDataSync
{
    private const Fields = [
        'source_key', 'source_record_id', 'reported_mic', 'currency', 'record_kind', 'price_type',
        'interval_code', 'session_type', 'time_precision', 'time_basis', 'adjustment_basis', 'validation_status',
        'trading_date', 'source_time_raw', 'observed_at', 'period_start_at', 'period_end_at', 'effective_at',
        'source_published_at', 'fetched_at', 'trade_count', 'delay_seconds', 'validation_errors', 'provenance', 'raw_payload',
    ];

    public function __construct(
        private MarketIdentityRegistry $identities,
        private MarketPriceStore $prices,
        private DashboardPerformanceSnapshots $snapshots,
    ) {}

    public function sync(string $source, ?callable $onProgress = null): array
    {
        foreach (['market_prices', 'market_listings', 'market_instruments'] as $table) {
            if (! Schema::connection($source)->hasTable($table) || ! Schema::hasTable($table)) {
                throw new RuntimeException('Die zentrale Kursablage fehlt auf einer der beiden Datenbanken.');
            }
        }
        $processed = 0;
        $imported = 0;
        $unmatched = 0;
        $conflicts = 0;
        $touched = [];

        try {
            if ($onProgress) {
                $onProgress(['phase' => 'reconciling', 'table' => 'Wertpapier-Zuordnungen', 'processed' => 0]);
            }
            foreach (StockHolding::query()->orderBy('id')->cursor() as $holding) {
                $this->identities->listing($holding);
            }
            $listings = DB::table('market_listings as l')->join('market_instruments as i', 'i.id', '=', 'l.market_instrument_id')
                ->where('l.status', 'verified')->get(['l.id', 'l.venue_key', 'l.currency', 'l.quote_unit', 'i.identity_key']);
            $localByIdentity = [];
            foreach ($listings as $listing) {
                $key = $this->identity($listing->identity_key, $listing->venue_key, $listing->currency, $listing->quote_unit);
                $localByIdentity[$key][] = $listing->id;
            }
            $query = DB::connection($source)->table('market_prices as p')
                ->join('market_listings as l', 'l.id', '=', 'p.market_listing_id')
                ->join('market_instruments as i', 'i.id', '=', 'l.market_instrument_id')
                ->where('l.status', 'verified')->whereIn('p.source_key', StockPreviousCloseResolver::FreeSources)
                ->where('p.is_current', true)->where('p.validation_status', 'valid')
                ->select('p.*', 'i.identity_key as sync_instrument_key', 'l.venue_key as sync_venue_key',
                    'l.currency as sync_currency', 'l.quote_unit as sync_quote_unit');
            $query->chunkById(200, function ($rows) use ($localByIdentity, &$processed, &$imported, &$unmatched, &$conflicts, &$touched, $onProgress): void {
                $prepared = [];
                foreach ($rows as $row) {
                    $processed++;
                    $key = $this->identity($row->sync_instrument_key, $row->sync_venue_key, $row->sync_currency, $row->sync_quote_unit);
                    $matches = $localByIdentity[$key] ?? [];
                    if (count($matches) !== 1 || $row->currency !== $row->sync_currency) {
                        $unmatched++;

                        continue;
                    }
                    $data = Arr::only((array) $row, [...self::Fields, ...MarketPriceStore::Values]);
                    foreach (['validation_errors', 'provenance', 'raw_payload'] as $field) {
                        $value = $data[$field] ?? null;
                        $data[$field] = is_string($value) ? json_decode($value, true, flags: JSON_THROW_ON_ERROR) : $value;
                    }
                    $data['validation_errors'] ??= [];
                    $data['provenance'] ??= [];
                    $prepared[] = $this->prices->prepare((int) $matches[0], $data);
                    $touched[(int) $matches[0]] = true;
                }
                foreach ($this->prices->importBatch($prepared) as $price) {
                    $imported += (int) $price->wasRecentlyCreated;
                    $conflicts += (int) (! $price->is_current || $price->validation_status !== 'valid');
                }
                if ($onProgress) {
                    $onProgress(['phase' => 'reconciling', 'table' => 'Kostenlose Kurse', 'processed' => $processed]);
                }
            }, 'p.id', 'id');
            foreach (array_keys($touched) as $listingId) {
                $this->prices->selectLatest($listingId);
            }
        } finally {
            $this->snapshots->invalidate();
        }

        return ['processed' => $processed, 'imported' => $imported, 'unmatched' => $unmatched, 'conflicts' => $conflicts];
    }

    private function identity(string $instrument, string $venue, ?string $currency, string $unit): string
    {
        return json_encode([$instrument, $venue, $currency, $unit], JSON_THROW_ON_ERROR);
    }
}
