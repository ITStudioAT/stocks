<?php

namespace App\Services;

use InvalidArgumentException;

class PreviewSnapshotSchema
{
    /** @var array<string, array{required: list<string>, nullable: list<string>}> */
    private const Definitions = [
        'depots' => [
            'required' => ['id', 'name', 'account_balance', 'is_active'],
            'nullable' => ['provider', 'account_number', 'description', 'created_at', 'updated_at'],
        ],
        'depot_transactions' => [
            'required' => ['id', 'depot_id', 'type', 'total_amount', 'cash_delta', 'balance_after', 'booked_at', 'currency', 'is_external_cashflow', 'affects_performance'],
            'nullable' => ['stock_holding_id', 'pieces', 'unit_price', 'note', 'created_at', 'updated_at'],
        ],
        'stock_holdings' => [
            'required' => ['id'],
            'nullable' => ['name', 'isin', 'wkn', 'created_at', 'updated_at', 'symbol', 'exchange', 'mic_code', 'instrument_type', 'country', 'currency', 'latest_price', 'latest_price_fetched_at', 'latest_price_source', 'latest_price_source_url', 'latest_price_as_of', 'trading_times', 'preferred_venue', 'preferred_mic', 'preferred_source_key', 'latest_quote_id', 'price_status', 'latest_price_type', 'price_spread_pct', 'source_verified_at', 'latest_stock_price_id', 'flatex_price', 'start_price', 'end_price', 'end_price_24', 'end_price_48', 'latest_realtime_price_id', 'subtitle'],
        ],
        'stock_prices' => [
            'required' => ['id', 'instrument_key', 'quote_hash', 'source_key', 'source_name', 'source_url', 'source_quality', 'price_type', 'fetched_at', 'freshness_status', 'validation_status'],
            'nullable' => ['venue', 'mic', 'isin', 'wkn', 'symbol', 'currency', 'bid', 'ask', 'last', 'close', 'nav', 'price', 'spread_abs', 'spread_pct', 'as_of', 'validation_errors', 'raw_text_hash', 'raw_payload', 'trading_times', 'created_at', 'updated_at'],
        ],
        'stock_price_quotes' => [
            'required' => ['id', 'stock_holding_id', 'source_key', 'source_name', 'source_url', 'source_quality', 'price_type', 'fetched_at', 'freshness_status', 'validation_status'],
            'nullable' => ['venue', 'mic', 'isin', 'wkn', 'symbol', 'currency', 'bid', 'ask', 'last', 'close', 'nav', 'price', 'spread_abs', 'spread_pct', 'as_of', 'validation_errors', 'raw_text_hash', 'raw_payload', 'created_at', 'updated_at'],
        ],
        'stock_realtime_prices' => [
            'required' => ['id', 'instrument_key', 'quote_hash', 'source_key', 'source_name', 'source_url', 'source_quality', 'price_type', 'fetched_at', 'freshness_status', 'validation_status'],
            'nullable' => ['stock_holding_id', 'legacy_stock_price_id', 'venue', 'mic', 'isin', 'wkn', 'symbol', 'currency', 'bid', 'ask', 'last', 'close', 'nav', 'price', 'spread_abs', 'spread_pct', 'as_of', 'validation_errors', 'raw_text_hash', 'raw_payload', 'trading_times', 'created_at', 'updated_at'],
        ],
        'stock_holding_daily_prices' => [
            'required' => ['id', 'stock_holding_id', 'trading_date', 'source_key', 'source_name', 'source_url'],
            'nullable' => ['open', 'high', 'low', 'close', 'adjusted_close', 'volume', 'currency', 'raw_payload', 'created_at', 'updated_at'],
        ],
        'stock_holding_intraday_candles' => [
            'required' => ['id', 'stock_holding_id', 'trading_date', 'interval', 'as_of', 'source_key', 'source_name'],
            'nullable' => ['timestamp', 'gmtoffset', 'datetime', 'open', 'high', 'low', 'close', 'volume', 'currency', 'source_url', 'raw_payload', 'created_at', 'updated_at'],
        ],
        'stock_holding_intraday_prices' => [
            'required' => ['id', 'stock_holding_id', 'trading_date', 'sample_index', 'price', 'as_of'],
            'nullable' => ['source_stock_price_id', 'currency', 'source_name', 'price_type', 'created_at', 'updated_at'],
        ],
        'stock_holding_source_candidates' => [
            'required' => ['id', 'stock_holding_id', 'source_key', 'source_url', 'parser_key', 'confidence_score', 'consecutive_failures', 'active', 'verified'],
            'nullable' => ['venue', 'mic', 'last_success_at', 'last_failed_at', 'created_at', 'updated_at'],
        ],
        'eodhd_exchanges' => [
            'required' => ['id', 'code', 'detail_code'],
            'nullable' => ['name', 'country', 'currency', 'timezone', 'operating_mic', 'trading_hours', 'holidays', 'raw_exchange', 'raw_details', 'synced_at', 'created_at', 'updated_at'],
        ],
        'index_watch_items' => [
            'required' => ['id', 'symbol'],
            'nullable' => ['name', 'isin', 'wkn', 'exchange', 'mic_code', 'instrument_type', 'country', 'currency', 'raw_payload', 'created_at', 'updated_at', 'start_price', 'latest_price', 'last_price', 'latest_price_change_pct', 'latest_price_as_of', 'latest_price_source', 'trading_times'],
        ],
        'index_watch_item_prices' => [
            'required' => ['id', 'index_watch_item_id', 'trading_date'],
            'nullable' => ['start_price', 'actual_price', 'last_price', 'actual_price_as_of', 'last_price_as_of', 'raw_payload', 'created_at', 'updated_at', 'intraday_sync_status', 'intraday_candle_count', 'intraday_sync_attempts', 'intraday_http_status', 'intraday_sync_message', 'intraday_checked_at'],
        ],
        'index_watch_item_intraday_candles' => [
            'required' => ['id', 'index_watch_item_id', 'trading_date', 'interval', 'as_of', 'source_key', 'source_name'],
            'nullable' => ['timestamp', 'gmtoffset', 'datetime', 'open', 'high', 'low', 'close', 'volume', 'currency', 'source_url', 'raw_payload', 'created_at', 'updated_at'],
        ],
        'index_watch_item_realtime_prices' => [
            'required' => ['id', 'index_watch_item_id', 'trading_date', 'price', 'as_of', 'source_name'],
            'nullable' => ['start_price', 'previous_close', 'change_percent', 'currency', 'raw_payload', 'created_at', 'updated_at'],
        ],
    ];

    /** @var array<string, array<string, string>> */
    private const References = [
        'depot_transactions' => ['depot_id' => 'depots', 'stock_holding_id' => 'stock_holdings'],
        'stock_holdings' => [
            'latest_quote_id' => 'stock_price_quotes',
            'latest_stock_price_id' => 'stock_prices',
            'latest_realtime_price_id' => 'stock_realtime_prices',
        ],
        'stock_price_quotes' => ['stock_holding_id' => 'stock_holdings'],
        'stock_realtime_prices' => [
            'stock_holding_id' => 'stock_holdings',
            'legacy_stock_price_id' => 'stock_prices',
        ],
        'stock_holding_daily_prices' => ['stock_holding_id' => 'stock_holdings'],
        'stock_holding_intraday_candles' => ['stock_holding_id' => 'stock_holdings'],
        'stock_holding_intraday_prices' => [
            'stock_holding_id' => 'stock_holdings',
            'source_stock_price_id' => 'stock_prices',
        ],
        'stock_holding_source_candidates' => ['stock_holding_id' => 'stock_holdings'],
        'index_watch_item_prices' => ['index_watch_item_id' => 'index_watch_items'],
        'index_watch_item_intraday_candles' => ['index_watch_item_id' => 'index_watch_items'],
        'index_watch_item_realtime_prices' => ['index_watch_item_id' => 'index_watch_items'],
    ];

    /** @return array<string, list<string>> */
    public function columns(): array
    {
        $columns = [];

        foreach (self::Definitions as $table => $definition) {
            $columns[$table] = [...$definition['required'], ...$definition['nullable']];
        }

        return $columns;
    }

    /** @param array<string, list<array<string, scalar|null>>> $tables */
    public function validate(array $tables): void
    {
        $columns = $this->columns();

        if (count($tables) !== count($columns) || array_diff(array_keys($tables), array_keys($columns)) !== []) {
            throw new InvalidArgumentException('Snapshot must contain exactly the approved business tables.');
        }

        $indexed = [];

        foreach ($tables as $table => $rows) {
            if (! is_array($rows) || ! array_is_list($rows)) {
                throw new InvalidArgumentException('Snapshot rows must be a list.');
            }

            $indexed[$table] = [];

            foreach ($rows as $row) {
                if (! is_array($row) || count($row) !== count($columns[$table])
                    || array_diff(array_keys($row), $columns[$table]) !== []) {
                    throw new InvalidArgumentException('Snapshot row columns do not match the approved schema.');
                }

                foreach ($row as $column => $value) {
                    if ((! is_scalar($value) && $value !== null) || (is_float($value) && ! is_finite($value))
                        || ($value === null && in_array($column, self::Definitions[$table]['required'], true))) {
                        throw new InvalidArgumentException('Snapshot contains an invalid or unexpectedly null value.');
                    }
                }

                $id = $this->id($row['id']);

                if (isset($indexed[$table][$id])) {
                    throw new InvalidArgumentException('Snapshot contains a duplicate row identity.');
                }

                $indexed[$table][$id] = $row;
            }
        }

        foreach (self::References as $table => $references) {
            foreach ($tables[$table] as $row) {
                foreach ($references as $column => $foreignTable) {
                    if ($row[$column] !== null && ! isset($indexed[$foreignTable][$this->id($row[$column])])) {
                        throw new InvalidArgumentException('Snapshot contains a missing referenced row.');
                    }
                }
            }
        }

        foreach ($tables['stock_holdings'] as $holding) {
            foreach (['latest_quote_id' => 'stock_price_quotes', 'latest_realtime_price_id' => 'stock_realtime_prices'] as $column => $table) {
                if ($holding[$column] === null) {
                    continue;
                }

                $price = $indexed[$table][$this->id($holding[$column])];

                if ($price['stock_holding_id'] === null || $this->id($price['stock_holding_id']) !== $this->id($holding['id'])) {
                    throw new InvalidArgumentException('Snapshot latest price belongs to a different holding.');
                }
            }
        }
    }

    private function id(mixed $value): string
    {
        if ((! is_int($value) && ! is_string($value)) || preg_match('/^[1-9][0-9]*$/D', (string) $value) !== 1) {
            throw new InvalidArgumentException('Snapshot identities must be positive integers.');
        }

        $id = (string) $value;

        if (strlen($id) > 20 || (strlen($id) === 20 && strcmp($id, '18446744073709551615') > 0)) {
            throw new InvalidArgumentException('Snapshot identity exceeds the database integer range.');
        }

        return $id;
    }
}
