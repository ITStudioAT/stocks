<?php

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class CloudwaysDatabaseSync
{
    private const SourceConnectionName = 'cloudways';

    private const InsertChunkSize = 500;

    /**
     * @return array{
     *     source_connection: string,
     *     target_connection: string,
     *     tables: array<int, array{name: string, rows: int, columns: int}>,
     *     skipped_tables: array<int, string>,
     *     synced_tables: int,
     *     total_tables: int,
     *     rows: int,
     *     synced_at: string,
     * }
     */
    public function syncAllTables(?string $sourceConnectionName = null, ?string $targetConnectionName = null): array
    {
        $sourceConnectionName ??= $this->sourceConnectionName();
        $targetConnectionName ??= (string) config('database.default');

        $sourceTables = $this->tableNames($sourceConnectionName);
        $targetTables = $this->tableNames($targetConnectionName);
        $syncTables = array_values(array_intersect($sourceTables, $targetTables));
        $skippedTables = array_values(array_diff($sourceTables, $syncTables));
        $syncedTables = [];

        Schema::connection($targetConnectionName)->disableForeignKeyConstraints();

        try {
            DB::connection($targetConnectionName)->transaction(function () use (
                $sourceConnectionName,
                $targetConnectionName,
                $syncTables,
                &$syncedTables,
            ): void {
                foreach ($syncTables as $table) {
                    DB::connection($targetConnectionName)->table($table)->delete();
                }

                foreach ($syncTables as $table) {
                    $syncedTables[] = $this->syncTable($sourceConnectionName, $targetConnectionName, $table);
                }
            });
        } finally {
            Schema::connection($targetConnectionName)->enableForeignKeyConstraints();
        }

        return [
            'source_connection' => $sourceConnectionName,
            'target_connection' => $targetConnectionName,
            'tables' => $syncedTables,
            'skipped_tables' => $skippedTables,
            'synced_tables' => count($syncedTables),
            'total_tables' => count($sourceTables),
            'rows' => array_sum(array_column($syncedTables, 'rows')),
            'synced_at' => now()->toIso8601String(),
        ];
    }

    private function sourceConnectionName(): string
    {
        $configuredConnection = config('services.cloudways.connection');

        if (is_string($configuredConnection) && $configuredConnection !== '') {
            return $configuredConnection;
        }

        $host = config('services.cloudways.host');
        $database = config('services.cloudways.database');
        $username = config('services.cloudways.username');
        $password = config('services.cloudways.password');

        if (! $host || ! $database || ! $username || ! $password) {
            throw new RuntimeException('Cloudways database credentials are not configured.');
        }

        config([
            'database.connections.'.self::SourceConnectionName => array_merge(
                config('database.connections.mysql'),
                [
                    'host' => $host,
                    'port' => config('services.cloudways.port'),
                    'database' => $database,
                    'username' => $username,
                    'password' => $password,
                ],
            ),
        ]);

        DB::purge(self::SourceConnectionName);

        return self::SourceConnectionName;
    }

    /**
     * @return array<int, string>
     */
    private function tableNames(string $connectionName): array
    {
        $connection = DB::connection($connectionName);

        $tables = match ($connection->getDriverName()) {
            'mysql', 'mariadb' => $this->mysqlTableNames($connection),
            'sqlite' => $this->sqliteTableNames($connection),
            default => collect(Schema::connection($connectionName)->getTables())
                ->map(fn (array $table): string => (string) ($table['name'] ?? $table['schema_qualified_name'] ?? ''))
                ->filter(),
        };

        return $tables
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, string>
     */
    private function mysqlTableNames(Connection $connection): Collection
    {
        return collect($connection->select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"))
            ->map(fn (object $table): string => (string) array_values((array) $table)[0]);
    }

    /**
     * @return Collection<int, string>
     */
    private function sqliteTableNames(Connection $connection): Collection
    {
        return collect($connection->select(
            "select name from sqlite_master where type = 'table' and name not like 'sqlite_%'",
        ))->map(fn (object $table): string => $table->name);
    }

    /**
     * @return array{name: string, rows: int, columns: int}
     */
    private function syncTable(string $sourceConnectionName, string $targetConnectionName, string $table): array
    {
        $sourceColumns = Schema::connection($sourceConnectionName)->getColumnListing($table);
        $targetColumns = Schema::connection($targetConnectionName)->getColumnListing($table);
        $columns = array_values(array_intersect($sourceColumns, $targetColumns));
        $rows = 0;
        $batch = [];

        if ($columns === []) {
            return [
                'name' => $table,
                'rows' => 0,
                'columns' => 0,
            ];
        }

        foreach (DB::connection($sourceConnectionName)->table($table)->select($columns)->cursor() as $row) {
            $batch[] = (array) $row;

            if (count($batch) < self::InsertChunkSize) {
                continue;
            }

            DB::connection($targetConnectionName)->table($table)->insert($batch);
            $rows += count($batch);
            $batch = [];
        }

        if ($batch !== []) {
            DB::connection($targetConnectionName)->table($table)->insert($batch);
            $rows += count($batch);
        }

        return [
            'name' => $table,
            'rows' => $rows,
            'columns' => count($columns),
        ];
    }
}
