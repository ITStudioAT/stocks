<?php

namespace App\Services;

use App\Models\MarketPrice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MarketPriceStore
{
    public const Values = ['price', 'bid', 'ask', 'last', 'nav', 'open', 'high', 'low', 'close', 'adjusted_close', 'volume'];

    public static function enabled(): bool
    {
        $mode = app(MarketDataMode::class)->read();
        if ($mode !== null) {
            return $mode['mode'] === 'active';
        }

        return config('services.market_data.central_enabled', false) === true;
    }

    public static function decimal(mixed $value, int $scale = 10): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('Non-finite provider value.');
            }
            $value = number_format($value, $scale, '.', '');
        }
        $value = (string) $value;
        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/D', $value, $match)) {
            throw new InvalidArgumentException('Non-decimal market value.');
        }
        $fraction = $match[3] ?? '';
        if (strlen(rtrim($fraction, '0')) > $scale || strlen(ltrim($match[2], '0')) > 28 - $scale) {
            throw new InvalidArgumentException('Market value exceeds decimal precision; original payload retained by importer.');
        }

        return $match[1].(ltrim($match[2], '0') ?: '0').'.'.str_pad(substr($fraction, 0, $scale), $scale, '0');
    }

    /** @param array<string, mixed> $data */
    public function store(int $listingId, array $data, bool $confirmedCorrection = false): MarketPrice
    {
        $data = $this->prepare($listingId, $data);
        $key = $data['observation_key'];
        $hash = $data['payload_hash'];

        return $this->storePrepared($listingId, $data, $key, $hash, $confirmedCorrection);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function prepare(int $listingId, array $data): array
    {
        $data += ['source_record_id' => null, 'reported_mic' => null, 'currency' => null, 'record_kind' => 'quote', 'price_type' => 'last',
            'interval_code' => 'point', 'session_type' => 'unknown', 'trading_date' => null, 'observed_at' => null, 'source_time_raw' => null,
            'time_precision' => 'unknown', 'time_basis' => 'unknown', 'period_start_at' => null, 'period_end_at' => null, 'effective_at' => null,
            'source_published_at' => null, 'fetched_at' => null, 'trade_count' => null, 'adjustment_basis' => 'unknown', 'delay_seconds' => null,
            'validation_status' => 'valid', 'validation_errors' => [], 'provenance' => [], 'raw_payload' => null];
        foreach (self::Values as $field) {
            $data[$field] = self::decimal($data[$field] ?? null, $field === 'volume' ? 8 : 10);
        }
        $value = $data['price'] ?? $data['close'] ?? $data['last'] ?? $data['nav'];
        if ($data['validation_status'] !== 'cancelled' && (! is_numeric($value) || (float) $value <= 0)) {
            $data['validation_status'] = 'quarantined';
            $data['validation_errors'][] = 'No positive usable market value.';
        }
        foreach (['observed_at', 'effective_at', 'period_start_at', 'period_end_at', 'fetched_at', 'source_published_at'] as $field) {
            $data[$field] = $data[$field] === null ? null : Carbon::parse($data[$field], 'UTC')->utc()->format('Y-m-d H:i:s.u');
        }
        $keyParts = [$listingId, $data['source_key'], $data['reported_mic'], $data['record_kind'], $data['price_type'], $data['session_type'], $data['interval_code'], $data['adjustment_basis']];
        $identityTime = $data['observed_at'] === null ? null : rtrim(rtrim($data['observed_at'], '0'), '.');
        if ($data['time_precision'] === 'nanosecond') {
            $identityTime = $data['source_time_raw'] ?? $identityTime;
        }
        $keyParts[] = $data['source_record_id'] ?? [$identityTime, $data['trading_date'], $data['period_start_at'], $data['period_end_at']];
        $key = hash('sha256', json_encode($keyParts, JSON_THROW_ON_ERROR));
        $content = array_intersect_key($data, array_flip([...self::Values, 'trade_count', 'validation_status']));
        $content['events'] = $data['raw_payload']['events'] ?? null;
        ksort($content);
        $hash = hash('sha256', json_encode($content, JSON_THROW_ON_ERROR));

        return [...$data, 'market_listing_id' => $listingId, 'observation_key' => $key, 'payload_hash' => $hash];
    }

    /** @param array<string, mixed> $data */
    private function storePrepared(int $listingId, array $data, string $key, string $hash, bool $confirmedCorrection): MarketPrice
    {

        return DB::transaction(function () use ($listingId, $data, $key, $hash, $confirmedCorrection): MarketPrice {
            $listing = DB::table('market_listings')->where('id', $listingId)->lockForUpdate()->first();
            if ($listing === null) {
                throw new InvalidArgumentException('Unknown market listing.');
            }
            $current = MarketPrice::query()->where('current_key', $key)->first();
            if ($current !== null && ($current->payload_hash === $hash || $this->sameContent($current, $data))) {
                return $current;
            }
            $accepted = $listing->status === 'verified' && $listing->currency === $data['currency'] && in_array($data['validation_status'], ['valid', 'cancelled'], true);
            if ($current !== null) {
                $newer = $data['source_published_at'] !== null && ($current->source_published_at === null || $data['source_published_at'] > $current->source_published_at);
                $accepted = $accepted && ($confirmedCorrection || $newer);
                if ($data['source_published_at'] !== null && $current->source_published_at !== null && $data['source_published_at'] < $current->source_published_at) {
                    $accepted = false;
                }
            }
            $existing = MarketPrice::query()->where('observation_key', $key)->where('payload_hash', $hash)->where('is_current', false)->latest('revision')->first();
            if (! $accepted && $existing !== null) {
                return $existing;
            }
            if ($accepted && $current !== null) {
                $current->update(['is_current' => false]);
            }
            $price = MarketPrice::query()->create([...$data, 'market_listing_id' => $listingId, 'observation_key' => $key, 'payload_hash' => $hash,
                'revision' => (int) MarketPrice::query()->where('observation_key', $key)->max('revision') + 1,
                'is_current' => $accepted, 'supersedes_id' => $current?->id,
                'validation_status' => $accepted ? $data['validation_status'] : 'quarantined']);
            DB::table('market_listings')->where('id', $listingId)->increment('data_version');

            return $price;
        }, 5);
    }

    /** @param array<int, array<string, mixed>> $rows @return array<int, MarketPrice> */
    public function importBatch(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        return DB::transaction(function () use ($rows): array {
            $listings = DB::table('market_listings')->whereIn('id', array_column($rows, 'market_listing_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $existing = MarketPrice::query()->whereIn('observation_key', array_column($rows, 'observation_key'))->get()->groupBy('observation_key');
            $inserts = [];
            foreach ($rows as $row) {
                if ($existing->has($row['observation_key']) || isset($inserts[$row['observation_key']])) {
                    continue;
                }
                $accepted = $listings[$row['market_listing_id']]->status === 'verified' && $listings[$row['market_listing_id']]->currency === $row['currency'] && $row['validation_status'] === 'valid';
                $insert = [...$row, 'revision' => 1, 'is_current' => $accepted, 'validation_status' => $accepted ? 'valid' : 'quarantined', 'created_at' => now()->utc(), 'updated_at' => now()->utc()];
                foreach (['provenance', 'raw_payload', 'validation_errors'] as $field) {
                    $insert[$field] = $insert[$field] === null ? null : json_encode($insert[$field], JSON_THROW_ON_ERROR);
                }
                $inserts[$row['observation_key']] = $insert;
            }
            foreach (array_chunk(array_values($inserts), 100) as $chunk) {
                DB::table('market_prices')->insertOrIgnore($chunk);
            }
            $records = MarketPrice::query()->whereIn('observation_key', array_column($rows, 'observation_key'))->get()->groupBy('observation_key');
            $result = [];
            foreach ($rows as $index => $row) {
                $match = $records->get($row['observation_key'], collect())->first(fn (MarketPrice $record): bool => $record->payload_hash === $row['payload_hash'] || $this->sameContent($record, $row));
                $result[$index] = $match ?? $this->storePrepared($row['market_listing_id'], $row, $row['observation_key'], $row['payload_hash'], false);
                if ($match !== null && isset($inserts[$row['observation_key']]) && $match->revision === 1) {
                    $result[$index]->wasRecentlyCreated = true;
                }
            }
            if ($inserts !== []) {
                DB::table('market_listings')->whereIn('id', array_unique(array_column($inserts, 'market_listing_id')))->increment('data_version');
            }

            return $result;
        }, 5);
    }

    /** @param array<string, mixed> $data */
    private function sameContent(MarketPrice $price, array $data): bool
    {
        foreach (self::Values as $field) {
            if (self::decimal($price->{$field}, $field === 'volume' ? 8 : 10) !== $data[$field]) {
                return false;
            }
        }

        return $price->validation_status === $data['validation_status']
            && (int) $price->trade_count === (int) $data['trade_count']
            && ($price->raw_payload['events'] ?? null) == ($data['raw_payload']['events'] ?? null);
    }

    public function selectLatest(int $listingId): ?MarketPrice
    {
        $price = MarketPrice::query()->where('market_listing_id', $listingId)->where('is_current', true)->where('validation_status', 'valid')
            ->whereNotNull('effective_at')->where('effective_at', '<=', now()->utc())->whereNull('nav')
            ->whereNotIn('price_type', ['indicative', 'nav'])->where('interval_code', '!=', '1d')->where('session_type', '!=', 'unknown')
            ->orderByRaw("CASE WHEN interval_code = '5m' THEN period_start_at ELSE effective_at END DESC")
            ->orderByRaw("CASE WHEN record_kind = 'bar' THEN 1 ELSE 0 END")
            ->orderByRaw("CASE WHEN source_key = 'xetra_posttrade' THEN 0 ELSE 1 END")
            ->orderByDesc('id')->first();
        DB::table('market_price_selections')->updateOrInsert(['market_listing_id' => $listingId, 'purpose' => 'latest'], ['market_price_id' => $price?->id, 'checked_at' => now()->utc()]);
        DB::table('stock_holdings')->where('market_listing_id', $listingId)->update(['latest_market_price_id' => $price?->id]);

        return $price;
    }
}
