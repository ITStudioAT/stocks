<?php

namespace App\Console\Commands;

use App\Services\EodhdErrorSanitizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JsonException;
use stdClass;

#[Signature('security:redact-eodhd-errors {--chunk=500 : Number of records to inspect per query}')]
#[Description('Redact EODHD API tokens from historical database payloads')]
class RedactEodhdErrors extends Command
{
    /**
     * @var array<int, array{
     *     table: string,
     *     columns: array<int, string>,
     *     where?: array<string, string>,
     * }>
     */
    private const Targets = [
        ['table' => 'failed_jobs', 'columns' => ['exception']],
        ['table' => 'eodhd_exchanges', 'columns' => ['raw_exchange', 'raw_details']],
        ['table' => 'stock_price_quotes', 'columns' => ['raw_payload']],
        ['table' => 'stock_prices', 'columns' => ['raw_payload']],
        ['table' => 'stock_realtime_prices', 'columns' => ['raw_payload']],
        ['table' => 'stock_holding_daily_prices', 'columns' => ['raw_payload']],
        ['table' => 'stock_holding_intraday_candles', 'columns' => ['raw_payload']],
        ['table' => 'index_watch_items', 'columns' => ['raw_payload']],
        ['table' => 'index_watch_item_prices', 'columns' => ['raw_payload', 'intraday_sync_message']],
        ['table' => 'index_watch_item_realtime_prices', 'columns' => ['raw_payload']],
        ['table' => 'index_watch_item_intraday_candles', 'columns' => ['raw_payload']],
        ['table' => 'eodhd_exchange_import_runs', 'columns' => ['error_summary']],
        ['table' => 'stock_holding_intraday_reload_runs', 'columns' => ['error_summary']],
        ['table' => 'stock_eodhd_sync_runs', 'columns' => ['steps', 'error']],
        ['table' => 'index_eodhd_sync_runs', 'columns' => ['steps', 'index_progress', 'summary', 'error']],
        ['table' => 'stock_price_refresh_runs', 'columns' => ['error_summary']],
        ['table' => 'stock_price_refresh_items', 'columns' => ['attempted_sources', 'error_message']],
        ['table' => 'stock_historical_price_fetch_runs', 'columns' => ['error_summary']],
        ['table' => 'stock_historical_price_fetch_items', 'columns' => ['error_message']],
        [
            'table' => 'app_configs',
            'columns' => ['value'],
            'where' => ['key' => 'v2_index_realtime.schedule'],
        ],
    ];

    public function handle(EodhdErrorSanitizer $errorSanitizer): int
    {
        $chunkSize = (int) $this->option('chunk');

        if ($chunkSize < 1 || $chunkSize > 5000) {
            $this->components->error('The chunk size must be between 1 and 5000.');

            return self::INVALID;
        }

        $updatedRecords = 0;

        foreach (self::Targets as $target) {
            $updatedRecords += $this->sanitizeTarget($target, $errorSanitizer, $chunkSize);
        }

        $this->components->info("Redacted EODHD secrets from {$updatedRecords} database record(s).");

        return self::SUCCESS;
    }

    /**
     * @param  array{
     *     table: string,
     *     columns: array<int, string>,
     *     where?: array<string, string>,
     * }  $target
     */
    private function sanitizeTarget(
        array $target,
        EodhdErrorSanitizer $errorSanitizer,
        int $chunkSize,
    ): int {
        if (! Schema::hasTable($target['table'])) {
            return 0;
        }

        $columns = array_values(array_filter(
            $target['columns'],
            fn (string $column): bool => Schema::hasColumn($target['table'], $column),
        ));

        if ($columns === [] || ! Schema::hasColumn($target['table'], 'id')) {
            return 0;
        }

        $updatedRecords = 0;
        $query = DB::table($target['table'])->select(['id', ...$columns]);

        foreach ($target['where'] ?? [] as $column => $value) {
            $query->where($column, $value);
        }

        $query
            ->orderBy('id')
            ->chunkById(
                $chunkSize,
                function ($records) use ($columns, $errorSanitizer, $target, &$updatedRecords): void {
                    foreach ($records as $record) {
                        if ($this->sanitizeRecord($target['table'], $record, $columns, $errorSanitizer)) {
                            $updatedRecords++;
                        }
                    }
                },
            );

        return $updatedRecords;
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function sanitizeRecord(
        string $table,
        stdClass $record,
        array $columns,
        EodhdErrorSanitizer $errorSanitizer,
    ): bool {
        $updates = [];
        $originalValues = [];

        foreach ($columns as $column) {
            $value = $record->{$column};

            if (! is_string($value)) {
                continue;
            }

            $sanitized = $this->sanitizeValue($value, $errorSanitizer);

            if ($sanitized !== $value) {
                $updates[$column] = $sanitized;
                $originalValues[$column] = $value;
            }
        }

        if ($updates === []) {
            return false;
        }

        $query = DB::table($table)->where('id', $record->id);

        foreach ($originalValues as $column => $value) {
            $query->where($column, $value);
        }

        return $query->update($updates) === 1;
    }

    private function sanitizeValue(string $value, EodhdErrorSanitizer $errorSanitizer): string
    {
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $errorSanitizer->message($value);
        }

        $sanitized = $errorSanitizer->payload($decoded);

        if ($sanitized === $decoded) {
            return $value;
        }

        return json_encode(
            $sanitized,
            JSON_THROW_ON_ERROR,
        );
    }
}
