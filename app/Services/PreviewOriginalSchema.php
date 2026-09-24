<?php

namespace App\Services;

use InvalidArgumentException;

class PreviewOriginalSchema
{
    /** @var array<string, array{required: list<string>, nullable: list<string>}> */
    private const Definitions = [
        'agent_conversation_messages' => [
            'required' => ['id', 'conversation_id', 'agent', 'role', 'content', 'attachments', 'tool_calls', 'tool_results', 'usage', 'meta'],
            'nullable' => ['user_id', 'created_at', 'updated_at'],
        ],
        'agent_conversations' => [
            'required' => ['id', 'title'],
            'nullable' => ['user_id', 'created_at', 'updated_at'],
        ],
        'analyze_research_settings' => [
            'required' => ['id', 'user_id', 'settings'],
            'nullable' => ['created_at', 'updated_at'],
        ],
        'app_configs' => [
            'required' => ['id', 'key'],
            'nullable' => ['value', 'created_at', 'updated_at'],
        ],
        'depot_transactions' => [
            'required' => ['id', 'depot_id', 'type', 'total_amount', 'cash_delta', 'balance_after', 'booked_at', 'currency', 'is_external_cashflow', 'affects_performance'],
            'nullable' => ['stock_holding_id', 'pieces', 'unit_price', 'note', 'created_at', 'updated_at'],
        ],
        'depots' => [
            'required' => ['id', 'name', 'account_balance', 'is_active'],
            'nullable' => ['provider', 'account_number', 'description', 'created_at', 'updated_at'],
        ],
        'eodhd_exchange_import_runs' => [
            'required' => ['id', 'status', 'total_count', 'processed_count', 'success_count', 'failed_count'],
            'nullable' => ['current', 'started_at', 'finished_at', 'error_summary', 'created_at', 'updated_at'],
        ],
        'eodhd_exchanges' => [
            'required' => ['id', 'code', 'detail_code'],
            'nullable' => ['name', 'country', 'currency', 'timezone', 'operating_mic', 'trading_hours', 'holidays', 'raw_exchange', 'raw_details', 'synced_at', 'created_at', 'updated_at'],
        ],
        'index_eodhd_sync_runs' => [
            'required' => ['id', 'status', 'stage', 'total_indices', 'processed_indices', 'date_from', 'date_to', 'eod_missing_count', 'eod_synced_count', 'intraday_missing_count', 'intraday_synced_count', 'unsupported_intraday_count', 'failed_count', 'steps'],
            'nullable' => ['current', 'summary', 'message', 'error', 'started_at', 'finished_at', 'created_at', 'updated_at', 'index_progress'],
        ],
        'index_watch_item_intraday_candles' => [
            'required' => ['id', 'index_watch_item_id', 'trading_date', 'interval', 'as_of', 'source_key', 'source_name'],
            'nullable' => ['timestamp', 'gmtoffset', 'datetime', 'open', 'high', 'low', 'close', 'volume', 'currency', 'source_url', 'raw_payload', 'created_at', 'updated_at'],
        ],
        'index_watch_item_prices' => [
            'required' => ['id', 'index_watch_item_id', 'trading_date'],
            'nullable' => ['start_price', 'actual_price', 'last_price', 'actual_price_as_of', 'last_price_as_of', 'raw_payload', 'created_at', 'updated_at', 'intraday_sync_status', 'intraday_candle_count', 'intraday_sync_attempts', 'intraday_http_status', 'intraday_sync_message', 'intraday_checked_at'],
        ],
        'index_watch_item_realtime_prices' => [
            'required' => ['id', 'index_watch_item_id', 'trading_date', 'price', 'as_of', 'source_name'],
            'nullable' => ['start_price', 'previous_close', 'change_percent', 'currency', 'raw_payload', 'created_at', 'updated_at'],
        ],
        'index_watch_items' => [
            'required' => ['id', 'symbol'],
            'nullable' => ['name', 'isin', 'wkn', 'exchange', 'mic_code', 'instrument_type', 'country', 'currency', 'raw_payload', 'created_at', 'updated_at', 'start_price', 'latest_price', 'last_price', 'latest_price_change_pct', 'latest_price_as_of', 'latest_price_source', 'trading_times'],
        ],
        'model_has_permissions' => [
            'required' => ['permission_id', 'model_type', 'model_id'],
            'nullable' => [],
        ],
        'model_has_roles' => [
            'required' => ['role_id', 'model_type', 'model_id'],
            'nullable' => [],
        ],
        'permissions' => [
            'required' => ['id', 'name', 'guard_name'],
            'nullable' => ['created_at', 'updated_at'],
        ],
        'role_has_permissions' => [
            'required' => ['permission_id', 'role_id'],
            'nullable' => [],
        ],
        'roles' => [
            'required' => ['id', 'name', 'guard_name'],
            'nullable' => ['created_at', 'updated_at'],
        ],
        'stock_ai_research_sources' => [
            'required' => ['id', 'stock_ai_research_id', 'user_id', 'stock_holding_id', 'url', 'url_hash', 'is_primary'],
            'nullable' => ['title', 'created_at', 'updated_at', 'source_type', 'confidence', 'retrieved_at'],
        ],
        'stock_ai_researches' => [
            'required' => ['id', 'user_id', 'stock_holding_id', 'status'],
            'nullable' => ['previous_research_id', 'has_material_update', 'summary', 'stronger_case', 'weaker_case', 'trump_connection', 'recommendation', 'justification', 'known_information', 'message', 'error', 'started_at', 'finished_at', 'created_at', 'updated_at', 'developments', 'calculation_snapshot', 'calculated_events', 'assessment', 'analyst_consensus', 'recommendation_buy_pct', 'recommendation_hold_pct', 'recommendation_sell_pct'],
        ],
        'stock_eodhd_sync_runs' => [
            'required' => ['id', 'status', 'stage', 'date_from', 'date_to', 'eod_requested_count', 'eod_stored_count', 'eod_skipped_count', 'eod_failed_count', 'intraday_total_count', 'intraday_processed_count', 'intraday_stored_count', 'intraday_success_count', 'intraday_failed_count', 'steps'],
            'nullable' => ['intraday_reload_run_id', 'current', 'message', 'error', 'started_at', 'finished_at', 'created_at', 'updated_at'],
        ],
        'stock_historical_price_fetch_items' => [
            'required' => ['id', 'fetch_run_id', 'stock_holding_id', 'status', 'date_from', 'date_to', 'stored_count'],
            'nullable' => ['error_message', 'created_at', 'updated_at'],
        ],
        'stock_historical_price_fetch_runs' => [
            'required' => ['id', 'status', 'date_from', 'date_to', 'total_count', 'processed_count', 'success_count', 'unavailable_count', 'failed_count'],
            'nullable' => ['current', 'started_at', 'finished_at', 'error_summary', 'created_at', 'updated_at'],
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
        'stock_holding_intraday_reload_runs' => [
            'required' => ['id', 'status', 'date_from', 'date_to', 'stored_count', 'total_count', 'processed_count', 'success_count', 'failed_count'],
            'nullable' => ['stock_holding_id', 'message', 'error_summary', 'started_at', 'finished_at', 'created_at', 'updated_at', 'current'],
        ],
        'stock_holding_source_candidates' => [
            'required' => ['id', 'stock_holding_id', 'source_key', 'source_url', 'parser_key', 'confidence_score', 'consecutive_failures', 'active', 'verified'],
            'nullable' => ['venue', 'mic', 'last_success_at', 'last_failed_at', 'created_at', 'updated_at'],
        ],
        'stock_holdings' => [
            'required' => ['id'],
            'nullable' => ['name', 'isin', 'wkn', 'created_at', 'updated_at', 'symbol', 'exchange', 'mic_code', 'instrument_type', 'country', 'currency', 'latest_price', 'latest_price_fetched_at', 'latest_price_source', 'latest_price_source_url', 'latest_price_as_of', 'trading_times', 'preferred_venue', 'preferred_mic', 'preferred_source_key', 'latest_quote_id', 'price_status', 'latest_price_type', 'price_spread_pct', 'source_verified_at', 'latest_stock_price_id', 'flatex_price', 'start_price', 'end_price', 'end_price_24', 'end_price_48', 'latest_realtime_price_id', 'subtitle'],
        ],
        'stock_price_quotes' => [
            'required' => ['id', 'stock_holding_id', 'source_key', 'source_name', 'source_url', 'source_quality', 'price_type', 'fetched_at', 'freshness_status', 'validation_status'],
            'nullable' => ['venue', 'mic', 'isin', 'wkn', 'symbol', 'currency', 'bid', 'ask', 'last', 'close', 'nav', 'price', 'spread_abs', 'spread_pct', 'as_of', 'validation_errors', 'raw_text_hash', 'raw_payload', 'created_at', 'updated_at'],
        ],
        'stock_price_refresh_items' => [
            'required' => ['id', 'refresh_run_id', 'stock_holding_id', 'status'],
            'nullable' => ['attempted_sources', 'selected_quote_id', 'error_message', 'created_at', 'updated_at', 'selected_stock_price_id'],
        ],
        'stock_price_refresh_runs' => [
            'required' => ['id', 'status', 'total_count', 'processed_count', 'success_count', 'stale_count', 'unavailable_count', 'invalid_count', 'suspicious_count'],
            'nullable' => ['started_at', 'finished_at', 'error_summary', 'created_at', 'updated_at'],
        ],
        'stock_prices' => [
            'required' => ['id', 'instrument_key', 'quote_hash', 'source_key', 'source_name', 'source_url', 'source_quality', 'price_type', 'fetched_at', 'freshness_status', 'validation_status'],
            'nullable' => ['venue', 'mic', 'isin', 'wkn', 'symbol', 'currency', 'bid', 'ask', 'last', 'close', 'nav', 'price', 'spread_abs', 'spread_pct', 'as_of', 'validation_errors', 'raw_text_hash', 'raw_payload', 'trading_times', 'created_at', 'updated_at'],
        ],
        'stock_realtime_prices' => [
            'required' => ['id', 'instrument_key', 'quote_hash', 'source_key', 'source_name', 'source_url', 'source_quality', 'price_type', 'fetched_at', 'freshness_status', 'validation_status'],
            'nullable' => ['stock_holding_id', 'legacy_stock_price_id', 'venue', 'mic', 'isin', 'wkn', 'symbol', 'currency', 'bid', 'ask', 'last', 'close', 'nav', 'price', 'spread_abs', 'spread_pct', 'as_of', 'validation_errors', 'raw_text_hash', 'raw_payload', 'trading_times', 'created_at', 'updated_at'],
        ],
        'users' => [
            'required' => ['id', 'last_name', 'first_name', 'email', 'password', 'is_protected', 'auth_revision'],
            'nullable' => ['email_verified_at', 'remember_token', 'created_at', 'updated_at', 'password_initialized_at'],
        ],
    ];

    /** @var array<string, list<string>> */
    private const PrimaryKeys = [
        'agent_conversation_messages' => ['id'],
        'agent_conversations' => ['id'],
        'analyze_research_settings' => ['id'],
        'app_configs' => ['id'],
        'depot_transactions' => ['id'],
        'depots' => ['id'],
        'eodhd_exchange_import_runs' => ['id'],
        'eodhd_exchanges' => ['id'],
        'index_eodhd_sync_runs' => ['id'],
        'index_watch_item_intraday_candles' => ['id'],
        'index_watch_item_prices' => ['id'],
        'index_watch_item_realtime_prices' => ['id'],
        'index_watch_items' => ['id'],
        'model_has_permissions' => ['permission_id', 'model_id', 'model_type'],
        'model_has_roles' => ['role_id', 'model_id', 'model_type'],
        'permissions' => ['id'],
        'role_has_permissions' => ['permission_id', 'role_id'],
        'roles' => ['id'],
        'stock_ai_research_sources' => ['id'],
        'stock_ai_researches' => ['id'],
        'stock_eodhd_sync_runs' => ['id'],
        'stock_historical_price_fetch_items' => ['id'],
        'stock_historical_price_fetch_runs' => ['id'],
        'stock_holding_daily_prices' => ['id'],
        'stock_holding_intraday_candles' => ['id'],
        'stock_holding_intraday_prices' => ['id'],
        'stock_holding_intraday_reload_runs' => ['id'],
        'stock_holding_source_candidates' => ['id'],
        'stock_holdings' => ['id'],
        'stock_price_quotes' => ['id'],
        'stock_price_refresh_items' => ['id'],
        'stock_price_refresh_runs' => ['id'],
        'stock_prices' => ['id'],
        'stock_realtime_prices' => ['id'],
        'users' => ['id'],
    ];

    /** @var array<string, list<array{columns: list<string>, foreign_table: string, foreign_columns: list<string>, nullable: bool}>> */
    private const ForeignKeys = [
        'agent_conversation_messages' => [
            ['columns' => ['conversation_id'], 'foreign_table' => 'agent_conversations', 'foreign_columns' => ['id'], 'nullable' => false],
            ['columns' => ['user_id'], 'foreign_table' => 'users', 'foreign_columns' => ['id'], 'nullable' => true],
        ],
        'agent_conversations' => [
            ['columns' => ['user_id'], 'foreign_table' => 'users', 'foreign_columns' => ['id'], 'nullable' => true],
        ],
        'analyze_research_settings' => [
            ['columns' => ['user_id'], 'foreign_table' => 'users', 'foreign_columns' => ['id'], 'nullable' => false],
        ],
        'app_configs' => [
        ],
        'depot_transactions' => [
            ['columns' => ['stock_holding_id'], 'foreign_table' => 'stock_holdings', 'foreign_columns' => ['id'], 'nullable' => true],
            ['columns' => ['depot_id'], 'foreign_table' => 'depots', 'foreign_columns' => ['id'], 'nullable' => false],
        ],
        'depots' => [
        ],
        'eodhd_exchange_import_runs' => [
        ],
        'eodhd_exchanges' => [
        ],
        'index_eodhd_sync_runs' => [
        ],
        'index_watch_item_intraday_candles' => [
            ['columns' => ['index_watch_item_id'], 'foreign_table' => 'index_watch_items', 'foreign_columns' => ['id'], 'nullable' => false],
        ],
        'index_watch_item_prices' => [
            ['columns' => ['index_watch_item_id'], 'foreign_table' => 'index_watch_items', 'foreign_columns' => ['id'], 'nullable' => false],
        ],
        'index_watch_item_realtime_prices' => [
            ['columns' => ['index_watch_item_id'], 'foreign_table' => 'index_watch_items', 'foreign_columns' => ['id'], 'nullable' => false],
        ],
        'index_watch_items' => [
        ],
        'model_has_permissions' => [
            ['columns' => ['permission_id'], 'foreign_table' => 'permissions', 'foreign_columns' => ['id'], 'nullable' => false],
            ['columns' => ['model_id'], 'foreign_table' => 'users', 'foreign_columns' => ['id'], 'nullable' => false],
        ],
        'model_has_roles' => [
            ['columns' => ['role_id'], 'foreign_table' => 'roles', 'foreign_columns' => ['id'], 'nullable' => false],
            ['columns' => ['model_id'], 'foreign_table' => 'users', 'foreign_columns' => ['id'], 'nullable' => false],
        ],
        'permissions' => [
        ],
        'role_has_permissions' => [
            ['columns' => ['role_id'], 'foreign_table' => 'roles', 'foreign_columns' => ['id'], 'nullable' => false],
            ['columns' => ['permission_id'], 'foreign_table' => 'permissions', 'foreign_columns' => ['id'], 'nullable' => false],
        ],
        'roles' => [
        ],
        'stock_ai_research_sources' => [
            ['columns' => ['stock_ai_research_id'], 'foreign_table' => 'stock_ai_researches', 'foreign_columns' => ['id'], 'nullable' => false],
            ['columns' => ['stock_holding_id'], 'foreign_table' => 'stock_holdings', 'foreign_columns' => ['id'], 'nullable' => false],
            ['columns' => ['user_id'], 'foreign_table' => 'users', 'foreign_columns' => ['id'], 'nullable' => false],
        ],
        'stock_ai_researches' => [
            ['columns' => ['previous_research_id'], 'foreign_table' => 'stock_ai_researches', 'foreign_columns' => ['id'], 'nullable' => true],
            ['columns' => ['stock_holding_id'], 'foreign_table' => 'stock_holdings', 'foreign_columns' => ['id'], 'nullable' => false],
            ['columns' => ['user_id'], 'foreign_table' => 'users', 'foreign_columns' => ['id'], 'nullable' => false],
        ],
        'stock_eodhd_sync_runs' => [
            ['columns' => ['intraday_reload_run_id'], 'foreign_table' => 'stock_holding_intraday_reload_runs', 'foreign_columns' => ['id'], 'nullable' => true],
        ],
        'stock_historical_price_fetch_items' => [
            ['columns' => ['fetch_run_id'], 'foreign_table' => 'stock_historical_price_fetch_runs', 'foreign_columns' => ['id'], 'nullable' => false],
            ['columns' => ['stock_holding_id'], 'foreign_table' => 'stock_holdings', 'foreign_columns' => ['id'], 'nullable' => false],
        ],
        'stock_historical_price_fetch_runs' => [
        ],
        'stock_holding_daily_prices' => [
            ['columns' => ['stock_holding_id'], 'foreign_table' => 'stock_holdings', 'foreign_columns' => ['id'], 'nullable' => false],
        ],
        'stock_holding_intraday_candles' => [
            ['columns' => ['stock_holding_id'], 'foreign_table' => 'stock_holdings', 'foreign_columns' => ['id'], 'nullable' => false],
        ],
        'stock_holding_intraday_prices' => [
            ['columns' => ['stock_holding_id'], 'foreign_table' => 'stock_holdings', 'foreign_columns' => ['id'], 'nullable' => false],
            ['columns' => ['source_stock_price_id'], 'foreign_table' => 'stock_prices', 'foreign_columns' => ['id'], 'nullable' => true],
        ],
        'stock_holding_intraday_reload_runs' => [
            ['columns' => ['stock_holding_id'], 'foreign_table' => 'stock_holdings', 'foreign_columns' => ['id'], 'nullable' => true],
        ],
        'stock_holding_source_candidates' => [
            ['columns' => ['stock_holding_id'], 'foreign_table' => 'stock_holdings', 'foreign_columns' => ['id'], 'nullable' => false],
        ],
        'stock_holdings' => [
            ['columns' => ['latest_realtime_price_id'], 'foreign_table' => 'stock_realtime_prices', 'foreign_columns' => ['id'], 'nullable' => true],
            ['columns' => ['latest_stock_price_id'], 'foreign_table' => 'stock_prices', 'foreign_columns' => ['id'], 'nullable' => true],
            ['columns' => ['latest_quote_id'], 'foreign_table' => 'stock_price_quotes', 'foreign_columns' => ['id'], 'nullable' => true],
        ],
        'stock_price_quotes' => [
            ['columns' => ['stock_holding_id'], 'foreign_table' => 'stock_holdings', 'foreign_columns' => ['id'], 'nullable' => false],
        ],
        'stock_price_refresh_items' => [
            ['columns' => ['selected_stock_price_id'], 'foreign_table' => 'stock_prices', 'foreign_columns' => ['id'], 'nullable' => true],
            ['columns' => ['stock_holding_id'], 'foreign_table' => 'stock_holdings', 'foreign_columns' => ['id'], 'nullable' => false],
            ['columns' => ['selected_quote_id'], 'foreign_table' => 'stock_price_quotes', 'foreign_columns' => ['id'], 'nullable' => true],
            ['columns' => ['refresh_run_id'], 'foreign_table' => 'stock_price_refresh_runs', 'foreign_columns' => ['id'], 'nullable' => false],
        ],
        'stock_price_refresh_runs' => [
        ],
        'stock_prices' => [
        ],
        'stock_realtime_prices' => [
            ['columns' => ['legacy_stock_price_id'], 'foreign_table' => 'stock_prices', 'foreign_columns' => ['id'], 'nullable' => true],
            ['columns' => ['stock_holding_id'], 'foreign_table' => 'stock_holdings', 'foreign_columns' => ['id'], 'nullable' => true],
        ],
        'users' => [
        ],
    ];

    /** @var array<string, int> */
    private const StringIdentities = [
        'agent_conversation_messages' => 36,
        'agent_conversations' => 36,
        'eodhd_exchange_import_runs' => 255,
        'index_eodhd_sync_runs' => 255,
        'stock_ai_researches' => 255,
        'stock_eodhd_sync_runs' => 255,
        'stock_historical_price_fetch_runs' => 255,
        'stock_holding_intraday_reload_runs' => 255,
        'stock_price_refresh_runs' => 255,
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

    /** @return array<string, list<string>> */
    public function primaryKeys(): array
    {
        return self::PrimaryKeys;
    }

    /** @return array<string, list<array{columns: list<string>, foreign_table: string, foreign_columns: list<string>, nullable: bool}>> */
    public function foreignKeys(): array
    {
        return self::ForeignKeys;
    }

    /** @param array<string, list<array<string, scalar|null>>> $tables */
    public function validate(array $tables): void
    {
        $columns = $this->columns();

        if (count($tables) !== count($columns) || array_diff(array_keys($tables), array_keys($columns)) !== []) {
            throw new InvalidArgumentException('Original snapshot must contain exactly the approved application tables.');
        }

        $indexed = $this->indexRows($tables, $columns);
        $this->validateReferences($tables, $indexed);
        $this->validateOwnership($tables, $indexed);
    }

    /**
     * @param  array<string, list<array<string, scalar|null>>>  $tables
     * @param  array<string, list<string>>  $columns
     * @return array<string, array<string, array<string, scalar|null>>>
     */
    private function indexRows(array $tables, array $columns): array
    {
        $indexed = [];

        foreach ($tables as $table => $rows) {
            if (! is_array($rows) || ! array_is_list($rows)) {
                throw new InvalidArgumentException('Original snapshot rows must be a list.');
            }

            $indexed[$table] = [];

            foreach ($rows as $row) {
                if (! is_array($row) || count($row) !== count($columns[$table])
                    || array_diff(array_keys($row), $columns[$table]) !== []) {
                    throw new InvalidArgumentException('Original snapshot row columns do not match the approved schema.');
                }

                foreach ($row as $column => $value) {
                    if ((! is_scalar($value) && $value !== null) || (is_float($value) && ! is_finite($value))
                        || ($value === null && in_array($column, self::Definitions[$table]['required'], true))) {
                        throw new InvalidArgumentException('Original snapshot contains an invalid or unexpectedly null value.');
                    }
                }

                $key = $this->rowKey($table, $row, self::PrimaryKeys[$table]);

                if (isset($indexed[$table][$key])) {
                    throw new InvalidArgumentException('Original snapshot contains a duplicate primary key.');
                }

                $indexed[$table][$key] = $row;
            }
        }

        return $indexed;
    }

    /**
     * @param  array<string, list<array<string, scalar|null>>>  $tables
     * @param  array<string, array<string, array<string, scalar|null>>>  $indexed
     */
    private function validateReferences(array $tables, array $indexed): void
    {
        foreach (self::ForeignKeys as $table => $references) {
            foreach ($tables[$table] as $row) {
                foreach ($references as $reference) {
                    $values = array_map(fn (string $column) => $row[$column], $reference['columns']);

                    if ($reference['nullable'] && count(array_filter($values, fn ($value) => $value !== null)) === 0) {
                        continue;
                    }

                    $foreignTable = $reference['foreign_table'];
                    $foreignRow = array_combine($reference['foreign_columns'], $values);
                    $key = $this->rowKey($foreignTable, $foreignRow, $reference['foreign_columns']);

                    if (! isset($indexed[$foreignTable][$key])) {
                        throw new InvalidArgumentException('Original snapshot contains a missing referenced row.');
                    }
                }
            }
        }
    }

    /**
     * @param  array<string, list<array<string, scalar|null>>>  $tables
     * @param  array<string, array<string, array<string, scalar|null>>>  $indexed
     */
    private function validateOwnership(array $tables, array $indexed): void
    {
        foreach ($tables['stock_holdings'] as $holding) {
            foreach (['latest_quote_id' => 'stock_price_quotes', 'latest_realtime_price_id' => 'stock_realtime_prices'] as $column => $table) {
                if ($holding[$column] !== null) {
                    $price = $this->referencedRow($indexed, $table, $holding[$column]);
                    $this->assertSameOwnership(['stock_holding_id' => $holding['id']], $price, ['stock_holding_id']);
                }
            }
        }

        foreach ($tables['stock_price_refresh_items'] as $item) {
            if ($item['selected_quote_id'] !== null) {
                $quote = $this->referencedRow($indexed, 'stock_price_quotes', $item['selected_quote_id']);
                $this->assertSameOwnership($item, $quote, ['stock_holding_id']);
            }
        }

        foreach ($tables['stock_ai_researches'] as $research) {
            if ($research['previous_research_id'] !== null) {
                $previous = $this->referencedRow($indexed, 'stock_ai_researches', $research['previous_research_id']);
                $this->assertSameOwnership($research, $previous, ['user_id', 'stock_holding_id']);
            }
        }

        foreach ($tables['stock_ai_research_sources'] as $source) {
            $research = $this->referencedRow($indexed, 'stock_ai_researches', $source['stock_ai_research_id']);
            $this->assertSameOwnership($source, $research, ['user_id', 'stock_holding_id']);
        }

        foreach ($tables['agent_conversation_messages'] as $message) {
            $conversation = $this->referencedRow($indexed, 'agent_conversations', $message['conversation_id']);
            $this->assertSameOwnership($message, $conversation, ['user_id']);
        }
    }

    /**
     * @param  array<string, array<string, array<string, scalar|null>>>  $indexed
     * @return array<string, scalar|null>
     */
    private function referencedRow(array $indexed, string $table, mixed $id): array
    {
        return $indexed[$table][$this->rowKey($table, ['id' => $id], ['id'])];
    }

    /**
     * @param  array<string, scalar|null>  $left
     * @param  array<string, scalar|null>  $right
     * @param  list<string>  $columns
     */
    private function assertSameOwnership(array $left, array $right, array $columns): void
    {
        foreach ($columns as $column) {
            if (($left[$column] === null) !== ($right[$column] === null) || (string) $left[$column] !== (string) $right[$column]) {
                throw new InvalidArgumentException('Original snapshot contains mismatched row ownership.');
            }
        }
    }

    /**
     * @param  array<string, scalar|null>  $row
     * @param  list<string>  $columns
     */
    private function rowKey(string $table, array $row, array $columns): string
    {
        return json_encode(array_map(fn (string $column): string => $this->identity($table, $column, $row[$column]), $columns), JSON_THROW_ON_ERROR);
    }

    private function identity(string $table, string $column, mixed $value): string
    {
        if ($column === 'model_type') {
            if ($value !== 'App\\Models\\User') {
                throw new InvalidArgumentException('Original snapshot has an unsupported permission model type.');
            }

            return $value;
        }

        if ($column === 'id' && isset(self::StringIdentities[$table])) {
            if (! is_string($value) || $value === '' || ! mb_check_encoding($value, 'UTF-8')
                || mb_strlen($value, 'UTF-8') > self::StringIdentities[$table]
                || preg_match('/[\\x00-\\x1F\\x7F]/', $value)) {
                throw new InvalidArgumentException('Original snapshot has an invalid string identity.');
            }

            return $value;
        }

        if ((! is_int($value) && ! is_string($value)) || preg_match('/^[1-9][0-9]*$/D', (string) $value) !== 1) {
            throw new InvalidArgumentException('Original snapshot numeric identities must be positive integers.');
        }

        $id = (string) $value;

        if (strlen($id) > 20 || (strlen($id) === 20 && strcmp($id, '18446744073709551615') > 0)) {
            throw new InvalidArgumentException('Original snapshot identity exceeds the database integer range.');
        }

        return $id;
    }
}
