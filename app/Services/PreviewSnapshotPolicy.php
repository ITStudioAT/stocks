<?php

namespace App\Services;

use InvalidArgumentException;

class PreviewSnapshotPolicy
{
    public const Tables = [
        'depots', 'depot_transactions', 'stock_holdings', 'stock_prices',
        'stock_price_quotes', 'stock_realtime_prices', 'stock_holding_daily_prices',
        'stock_holding_intraday_candles', 'stock_holding_intraday_prices',
        'stock_holding_source_candidates', 'eodhd_exchanges', 'index_watch_items',
        'index_watch_item_prices', 'index_watch_item_intraday_candles',
        'index_watch_item_realtime_prices',
    ];

    /**
     * Only selected business tables enter this boundary; never pass a raw SQL dump.
     * Users, permissions, tokens, sessions, settings, jobs and research are deliberately absent.
     *
     * @param  array<string, list<array<string, scalar|null>>>  $tables
     * @return array<string, list<array<string, scalar|null>>>
     */
    public function sanitize(array $tables): array
    {
        if (array_diff(array_keys($tables), self::Tables) !== []) {
            throw new InvalidArgumentException('Snapshot contains an unapproved table.');
        }
        foreach ($tables as $table => &$rows) {
            if (! is_array($rows) || ! array_is_list($rows)) {
                throw new InvalidArgumentException('Snapshot rows must be a list.');
            }
            foreach ($rows as &$row) {
                if (! is_array($row) || ! isset($row['id']) || ! is_scalar($row['id'])) {
                    throw new InvalidArgumentException('Snapshot row identity is missing.');
                }
                foreach ($row as $column => &$value) {
                    if (! is_string($column) || preg_match('/^[a-z][a-z0-9_]*$/D', $column) !== 1
                        || (! is_scalar($value) && $value !== null)
                        || preg_match('/(?:password|secret|token|credential|api_key)/i', $column)) {
                        throw new InvalidArgumentException('Snapshot contains an unapproved column or value.');
                    }
                    if (str_ends_with($column, '_url')) {
                        $value = '';
                    } elseif (in_array($column, ['raw_payload', 'raw_text_hash', 'validation_errors', 'account_number', 'description', 'note'], true)) {
                        $value = null;
                    }
                }
                unset($value);
                if ($table === 'depots') {
                    $row['name'] = 'Preview Depot '.$row['id'];
                }
            }
            unset($row);
        }
        unset($rows);

        return $tables;
    }
}
