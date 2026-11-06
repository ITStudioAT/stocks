<?php

namespace App\Services;

use App\Models\IndexWatchItemPrice;
use Illuminate\Database\Eloquent\Model;

class MarketDataProvenance
{
    /** @return array<int, string> */
    public static function markers(?string ...$sources): array
    {
        $markers = [];
        foreach ($sources as $source) {
            $source = strtolower(trim($source ?? ''));
            if ($source === 'eodhd' || str_starts_with($source, 'eodhd_') || str_starts_with($source, 'eodhd ')
                || parse_url($source, PHP_URL_HOST) === 'eodhd.com') {
                $markers[] = 'EODHD';
            } elseif (in_array($source, ['free', 'xetra_instruments', 'xetra_posttrade', 'deutsche_boerse_trade', 'yahoo_intraday', 'yahoo_xetra_intraday', 'yahoo_daily'], true)
                || str_starts_with($source, 'yahoo finance') || str_starts_with($source, 'deutsche börse')) {
                $markers[] = 'free';
            }
        }

        return self::merge($markers);
    }

    /** @return array<int, string> */
    public static function forRecord(Model|array|null $record): array
    {
        if ($record === null) {
            return [];
        }

        foreach (['source_key', 'source_name', 'source_url', 'source_title'] as $field) {
            $markers = self::markers($record[$field] ?? null);
            if ($markers !== []) {
                return $markers;
            }
        }

        return [];
    }

    /** @param array<int, string> ...$groups @return array<int, string> */
    public static function merge(array ...$groups): array
    {
        $markers = array_merge([], ...$groups);

        return array_values(array_filter(['free', 'EODHD'], fn (string $marker): bool => in_array($marker, $markers, true)));
    }

    /**
     * The index importers stored EODHD response bodies before recording an explicit origin.
     * Only their identifiable EOD/realtime payloads qualify; manual or empty rows remain unknown.
     *
     * @return array<int, string>
     */
    public static function forIndexPrice(IndexWatchItemPrice $price): array
    {
        $payload = $price->raw_payload ?? [];
        if (isset($payload['_source'])) {
            return self::markers($payload['_source']);
        }
        if ((isset($payload['date'], $payload['close']) && array_key_exists('adjusted_close', $payload))
            || isset($payload['code'], $payload['timestamp'], $payload['close'])) {
            return ['EODHD'];
        }

        return [];
    }

    /** @param array<array-key, mixed> $payload @return array<array-key, mixed> */
    public static function decorate(array $payload): array
    {
        $aggregate = [];
        foreach ($payload as $key => $value) {
            if (is_array($value) && ! str_ends_with((string) $key, 'source_markers')) {
                $payload[$key] = self::decorate($value);
                $aggregate = self::merge($aggregate, $payload[$key]['data_source_markers'] ?? []);
                if (array_is_list($payload[$key])) {
                    foreach ($payload[$key] as $row) {
                        if (is_array($row)) {
                            $aggregate = self::merge($aggregate, $row['data_source_markers'] ?? []);
                        }
                    }
                }
            }
        }
        if (array_is_list($payload)) {
            return $payload;
        }
        $own = self::merge($payload['source_markers'] ?? [], self::forRecord($payload));
        if ($own !== [] || array_key_exists('source_key', $payload) || array_key_exists('source_name', $payload)) {
            $payload['source_markers'] = $own;
        }
        if (array_key_exists('latest_price_source', $payload)) {
            $payload['latest_price_source_markers'] = array_key_exists('latest_price_source_markers', $payload)
                ? self::merge($payload['latest_price_source_markers']) : self::markers($payload['latest_price_source']);
            $aggregate = self::merge($aggregate, $payload['latest_price_source_markers']);
        }
        foreach ($payload as $key => $value) {
            if (str_ends_with((string) $key, 'source_markers') && is_array($value)) {
                $aggregate = self::merge($aggregate, $value);
            }
        }
        if ($aggregate !== [] || $own !== []) {
            $payload['data_source_markers'] = self::merge($own, $aggregate);
        }

        return $payload;
    }
}
