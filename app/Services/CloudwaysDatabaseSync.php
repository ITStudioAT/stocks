<?php

namespace App\Services;

use App\Models\AppConfig;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use Pdo\Mysql;
use RuntimeException;
use stdClass;
use Throwable;

class CloudwaysDatabaseSync
{
    private const SourceConnectionName = 'cloudways';

    private const InsertChunkSize = 500;

    private const CheckConfigKey = 'cloudways.database_check';

    private const SyncConfigKey = 'cloudways.database_sync';

    private const Timezone = 'Europe/Vienna';

    /**
     * @return array{
     *     source_connection: string,
     *     target_connection: string,
     *     tables: array<int, array{
     *         name: string,
     *         status: string,
     *         cloudways_rows: ?int,
     *         local_rows: ?int,
     *         cloudways_hash: ?string,
     *         local_hash: ?string,
     *         cloudways_only_columns: array<int, string>,
     *         local_only_columns: array<int, string>,
     *         message: string,
     *     }>,
     *     total_tables: int,
     *     identical_tables: int,
     *     different_tables: int,
     *     checked_at: string,
     * }
     */
    public function compareAllTables(
        ?string $sourceConnectionName = null,
        ?string $targetConnectionName = null,
        ?callable $onTableCompared = null,
        ?callable $onProgress = null,
    ): array {
        $sourceConnectionName ??= $this->sourceConnectionName();
        $targetConnectionName ??= (string) config('database.default');

        $synchronizableTables = $this->synchronizableTableNames();
        $sourceTables = array_values(array_intersect(
            $synchronizableTables,
            $this->tableNames($sourceConnectionName),
        ));
        $targetTables = array_values(array_intersect(
            $synchronizableTables,
            $this->tableNames($targetConnectionName),
        ));
        $tablesToCompare = collect($synchronizableTables)
            ->filter(fn (string $table): bool => in_array($table, $sourceTables, true)
                || in_array($table, $targetTables, true))
            ->values();
        $tables = [];
        $totalTables = $tablesToCompare->count();

        foreach ($tablesToCompare as $index => $table) {
            if ($onProgress !== null) {
                $onProgress([
                    'phase' => 'checking',
                    'table' => $table,
                    'position' => $index + 1,
                    'completed' => $index,
                    'total' => $totalTables,
                ]);
            }

            $comparison = $this->compareTable(
                $sourceConnectionName,
                $targetConnectionName,
                $table,
                in_array($table, $sourceTables, true),
                in_array($table, $targetTables, true),
            );
            $tables[] = $comparison;

            if ($onTableCompared !== null) {
                $onTableCompared($comparison, $index + 1, $totalTables);
            }
        }

        $comparison = [
            'source_connection' => $sourceConnectionName,
            'target_connection' => $targetConnectionName,
            'tables' => $tables,
            'total_tables' => count($tables),
            'identical_tables' => collect($tables)->where('status', 'identical')->count(),
            'different_tables' => collect($tables)->where('status', '!=', 'identical')->count(),
            'checked_at' => now(self::Timezone)->toIso8601String(),
        ];

        $this->persistExecution(self::CheckConfigKey, $comparison['checked_at']);

        return $comparison;
    }

    /**
     * @param  array<int, string>  $checkedDifferentTables
     * @return array{
     *     source_connection: string,
     *     target_connection: string,
     *     tables: array<int, array{name: string, rows: int, columns: int, status: string, message: string}>,
     *     skipped_tables: array<int, string>,
     *     skipped_table_details: array<int, array{name: string, status: string, reason: string, message: string, missing_required_columns: array<int, string>}>,
     *     synced_tables: int,
     *     total_tables: int,
     *     rows: int,
     *     synced_at: string,
     * }
     */
    public function syncAllTables(
        array $checkedDifferentTables,
        ?string $sourceConnectionName = null,
        ?string $targetConnectionName = null,
        ?callable $onTableSynced = null,
        ?callable $onProgress = null,
    ): array {
        $sourceConnectionName ??= $this->sourceConnectionName();
        $targetConnectionName ??= (string) config('database.default');

        $this->assertDistinctDatabaseConnections($sourceConnectionName, $targetConnectionName);

        $synchronizableTables = array_values(array_intersect(
            $this->synchronizableTableNames(),
            $checkedDifferentTables,
        ));

        $sourceTables = array_values(array_intersect(
            $synchronizableTables,
            $this->tableNames($sourceConnectionName),
        ));
        $targetTables = array_values(array_intersect(
            $synchronizableTables,
            $this->tableNames($targetConnectionName),
        ));
        $syncPlan = $this->syncPlan(
            $sourceConnectionName,
            $targetConnectionName,
            $sourceTables,
            $targetTables,
        );
        $syncTables = $syncPlan['tables'];
        $skippedTableDetails = $syncPlan['skipped'];
        $skippedTables = array_column($skippedTableDetails, 'name');
        $syncedTables = [];
        $totalSyncTables = count($syncTables);

        if ($onProgress !== null) {
            $onProgress([
                'phase' => 'planned',
                'completed' => 0,
                'total' => $totalSyncTables,
                'skipped_table_details' => $skippedTableDetails,
            ]);
        }

        if ($syncTables !== []) {
            $this->refreshSourceConnection($sourceConnectionName);
            Schema::connection($targetConnectionName)->disableForeignKeyConstraints();

            try {
                DB::connection($targetConnectionName)->transaction(function () use (
                    $sourceConnectionName,
                    $targetConnectionName,
                    $syncTables,
                    $onTableSynced,
                    $onProgress,
                    $totalSyncTables,
                    &$syncedTables,
                ): void {
                    foreach ($syncTables as $index => $table) {
                        if ($onProgress !== null) {
                            $onProgress([
                                'phase' => 'clearing',
                                'table' => $table,
                                'position' => $index + 1,
                                'completed' => $index,
                                'total' => $totalSyncTables,
                            ]);
                        }

                        $this->deleteTargetTableRows($targetConnectionName, $table);
                    }

                    foreach ($syncTables as $index => $table) {
                        if ($onProgress !== null) {
                            $onProgress([
                                'phase' => 'importing',
                                'table' => $table,
                                'position' => $index + 1,
                                'completed' => $index,
                                'total' => $totalSyncTables,
                            ]);
                        }

                        try {
                            $syncedTable = $this->syncTable($sourceConnectionName, $targetConnectionName, $table);
                        } finally {
                            $this->refreshSourceConnection($sourceConnectionName);
                        }

                        $syncedTables[] = $syncedTable;

                        if ($onTableSynced !== null) {
                            $onTableSynced($syncedTable, $index + 1, $totalSyncTables);
                        }
                    }

                    if (array_intersect(['stock_holdings', 'stock_realtime_prices'], $syncTables) !== []) {
                        $this->repairLatestRealtimePriceLinks($targetConnectionName);
                    }
                });
            } finally {
                Schema::connection($targetConnectionName)->enableForeignKeyConstraints();
            }
        }

        $sync = [
            'source_connection' => $sourceConnectionName,
            'target_connection' => $targetConnectionName,
            'tables' => $syncedTables,
            'skipped_tables' => $skippedTables,
            'skipped_table_details' => $skippedTableDetails,
            'synced_tables' => count($syncedTables),
            'total_tables' => count($sourceTables),
            'rows' => array_sum(array_column($syncedTables, 'rows')),
            'synced_at' => now(self::Timezone)->toIso8601String(),
        ];

        $this->persistExecution(self::SyncConfigKey, $sync['synced_at']);

        return $sync;
    }

    /**
     * @return array{last_checked_at: ?string, last_synced_at: ?string, timezone: string}
     */
    public function executionStatus(): array
    {
        $configurations = AppConfig::query()
            ->whereIn('key', [self::CheckConfigKey, self::SyncConfigKey])
            ->get(['key', 'value'])
            ->keyBy('key');

        return [
            'last_checked_at' => $this->lastExecutedAt($configurations->get(self::CheckConfigKey)?->value),
            'last_synced_at' => $this->lastExecutedAt($configurations->get(self::SyncConfigKey)?->value),
            'timezone' => self::Timezone,
        ];
    }

    private function sourceConnectionName(): string
    {
        $configuredConnection = config('services.cloudways.connection');

        if (is_string($configuredConnection) && trim($configuredConnection) !== '') {
            $configuredConnection = trim($configuredConnection);

            if (! is_array(config("database.connections.{$configuredConnection}"))) {
                throw new RuntimeException('The configured Cloudways database connection does not exist.');
            }

            return $configuredConnection;
        }

        $host = config('services.cloudways.host');
        $database = config('services.cloudways.database');
        $username = config('services.cloudways.username');
        $password = config('services.cloudways.password');

        if (! $host || ! $database || ! $username || ! $password) {
            throw new RuntimeException('Cloudways database credentials are not configured.');
        }

        $sslCa = config('services.cloudways.ssl_ca');
        $verifyServerCertificate = config('services.cloudways.ssl_verify_server_cert');

        if (! is_string($sslCa) || trim($sslCa) === '') {
            throw new RuntimeException(
                'The dynamic Cloudways database connection requires a TLS CA certificate. Configure CLOUDWAYS_SSL_CA or CLOUDWAYS_CONNECTION.',
            );
        }

        $sslCaPath = trim($sslCa);

        if (! $this->pathIsAbsolute($sslCaPath)) {
            $sslCaPath = base_path($sslCaPath);
        }

        $resolvedSslCa = realpath($sslCaPath);

        if ($resolvedSslCa === false || ! is_file($resolvedSslCa) || ! is_readable($resolvedSslCa)) {
            throw new RuntimeException('The configured Cloudways TLS CA certificate is not a readable file.');
        }

        if ($verifyServerCertificate !== true) {
            throw new RuntimeException(
                'The dynamic Cloudways database connection requires server certificate verification.',
            );
        }

        if (! extension_loaded('pdo_mysql')) {
            throw new RuntimeException('The dynamic Cloudways database connection requires the PDO MySQL extension.');
        }

        config([
            'database.connections.'.self::SourceConnectionName => array_merge(
                config('database.connections.mysql'),
                [
                    'url' => null,
                    'host' => $host,
                    'port' => config('services.cloudways.port'),
                    'database' => $database,
                    'username' => $username,
                    'password' => $password,
                    'options' => array_replace(
                        config('database.connections.mysql.options', []),
                        [
                            PDO::ATTR_TIMEOUT => (int) config('services.cloudways.connect_timeout', 5),
                            Mysql::ATTR_SSL_CA => $resolvedSslCa,
                            Mysql::ATTR_SSL_VERIFY_SERVER_CERT => true,
                        ],
                    ),
                ],
            ),
        ]);

        DB::purge(self::SourceConnectionName);

        return self::SourceConnectionName;
    }

    private function assertDistinctDatabaseConnections(
        string $sourceConnectionName,
        string $targetConnectionName,
    ): void {
        $sameConnectionName = $sourceConnectionName === $targetConnectionName;
        $sourceIdentity = $this->normalizedDatabaseIdentity($sourceConnectionName, 'read');
        $targetIdentity = $this->normalizedDatabaseIdentity($targetConnectionName, 'write');
        $sameDatabaseIdentity = $sourceIdentity !== null
            && $targetIdentity !== null
            && $sourceIdentity === $targetIdentity;

        if ($sameConnectionName || $sameDatabaseIdentity) {
            $this->refuseSameDatabaseSynchronization();
        }

        if (! $this->supportsLiveDatabaseIdentity($sourceIdentity)
            || ! $this->supportsLiveDatabaseIdentity($targetIdentity)) {
            return;
        }

        $sourceLiveIdentity = $this->liveDatabaseIdentity($sourceConnectionName, 'read');
        $targetLiveIdentity = $this->liveDatabaseIdentity($targetConnectionName, 'write');

        if ($sourceLiveIdentity === $targetLiveIdentity) {
            $this->refuseSameDatabaseSynchronization();
        }
    }

    /**
     * @param  array{driver: string, database: string, endpoint: string}|null  $identity
     */
    private function supportsLiveDatabaseIdentity(?array $identity): bool
    {
        return $identity !== null
            && in_array($identity['driver'], ['mariadb', 'mysql'], true);
    }

    /**
     * @return array{database: string, server: string}
     */
    private function liveDatabaseIdentity(string $connectionName, string $connectionRole): array
    {
        $connection = DB::connection($connectionName);
        $useReadPdo = $connectionRole === 'read';

        try {
            $server = $connection->selectOne(
                'select database() as database_name, @@hostname as server_hostname, @@port as server_port, @@server_id as server_id, @@datadir as server_data_directory',
                [],
                $useReadPdo,
            );
            $database = $this->databaseIdentityValue($server, 'database_name');
            $serverHostname = strtolower($this->databaseIdentityValue($server, 'server_hostname'));
            $serverPort = $this->databaseIdentityValue($server, 'server_port');
            $serverId = $this->databaseIdentityValue($server, 'server_id');
            $serverDataDirectory = strtolower(str_replace(
                '\\',
                '/',
                rtrim($this->databaseIdentityValue($server, 'server_data_directory'), '/\\'),
            ));

            if ($database === ''
                || $serverHostname === ''
                || $serverPort === ''
                || $serverId === ''
                || $serverDataDirectory === '') {
                throw new RuntimeException('The database server returned an incomplete live identity.');
            }

            $serverIdentity = $this->uniqueLiveServerIdentity(
                $connection,
                $useReadPdo,
            ) ?? implode('|', [
                'fallback',
                $serverHostname,
                $serverPort,
                $serverId,
                $serverDataDirectory,
            ]);

            return [
                'database' => $database,
                'server' => $serverIdentity,
            ];
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Cloudways synchronization could not verify that the source and target are distinct live databases; synchronization was refused before deleting data.',
                previous: $exception,
            );
        }
    }

    private function uniqueLiveServerIdentity(Connection $connection, bool $useReadPdo): ?string
    {
        $variables = $connection->select(
            "show variables where variable_name in ('server_uuid', 'server_uid')",
            [],
            $useReadPdo,
        );
        $identifiers = [];

        foreach ($variables as $variable) {
            $name = strtolower($this->databaseIdentityValue($variable, 'variable_name'));
            $value = strtolower($this->databaseIdentityValue($variable, 'value'));

            if (in_array($name, ['server_uuid', 'server_uid'], true) && $value !== '') {
                $identifiers[$name] = $value;
            }
        }

        foreach (['server_uuid', 'server_uid'] as $name) {
            if (isset($identifiers[$name])) {
                return $name.':'.$identifiers[$name];
            }
        }

        return null;
    }

    private function databaseIdentityValue(array|object|null $row, string $key): string
    {
        if ($row === null) {
            return '';
        }

        $values = array_change_key_case((array) $row, CASE_LOWER);
        $value = $values[strtolower($key)] ?? null;

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function refuseSameDatabaseSynchronization(): never
    {
        throw new RuntimeException(
            'Cloudways source and target resolve to the same database; synchronization was refused before deleting data.',
        );
    }

    /**
     * @return array{driver: string, database: string, endpoint: string}|null
     */
    private function normalizedDatabaseIdentity(string $connectionName, string $connectionRole): ?array
    {
        $configuration = config("database.connections.{$connectionName}");

        if (! is_array($configuration)) {
            return null;
        }

        $configuration = (new ConfigurationUrlParser)->parseConfiguration($configuration);
        $roleConfiguration = $configuration[$connectionRole] ?? null;

        if (is_array($roleConfiguration)) {
            $configuration = array_replace($configuration, $roleConfiguration);
        }

        $driver = strtolower(trim((string) ($configuration['driver'] ?? '')));
        $database = trim((string) ($configuration['database'] ?? ''));

        if ($driver === '' || $database === '') {
            return null;
        }

        if ($driver === 'sqlite') {
            return [
                'driver' => $driver,
                'database' => $this->normalizedDatabasePath($database),
                'endpoint' => 'sqlite',
            ];
        }

        $socket = trim((string) ($configuration['unix_socket'] ?? ''));

        if ($socket !== '') {
            return [
                'driver' => $driver,
                'database' => $database,
                'endpoint' => 'socket:'.$this->normalizedDatabasePath($socket),
            ];
        }

        $hosts = collect((array) ($configuration['host'] ?? []))
            ->filter(fn (mixed $host): bool => is_string($host) && trim($host) !== '')
            ->map(fn (string $host): string => $this->normalizedDatabaseHost($host))
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($hosts === []) {
            return null;
        }

        $port = (int) ($configuration['port'] ?? $this->defaultDatabasePort($driver));

        return [
            'driver' => $driver,
            'database' => $database,
            'endpoint' => 'tcp:'.implode(',', $hosts).":{$port}",
        ];
    }

    private function normalizedDatabasePath(string $path): string
    {
        if ($path === ':memory:') {
            return $path;
        }

        $resolvedPath = realpath($path);

        if ($resolvedPath === false && ! $this->pathIsAbsolute($path)) {
            $path = base_path($path);
            $resolvedPath = realpath($path);
        }

        $normalizedPath = str_replace('\\', '/', $resolvedPath ?: $path);

        return PHP_OS_FAMILY === 'Windows' ? strtolower($normalizedPath) : $normalizedPath;
    }

    private function pathIsAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function normalizedDatabaseHost(string $host): string
    {
        $host = strtolower(rtrim(trim($host, " \t\n\r\0\x0B[]"), '.'));

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            ? 'loopback'
            : $host;
    }

    private function defaultDatabasePort(string $driver): int
    {
        return match ($driver) {
            'mariadb', 'mysql' => 3306,
            'pgsql' => 5432,
            'sqlsrv' => 1433,
            default => 0,
        };
    }

    private function persistExecution(string $configKey, string $executedAt): void
    {
        AppConfig::query()->updateOrCreate(
            ['key' => $configKey],
            ['value' => ['last_executed_at' => $executedAt]],
        );
    }

    private function lastExecutedAt(mixed $value): ?string
    {
        $lastExecutedAt = is_array($value) ? ($value['last_executed_at'] ?? null) : null;

        return is_string($lastExecutedAt) ? $lastExecutedAt : null;
    }

    /**
     * @return array<int, string>
     */
    private function synchronizableTableNames(): array
    {
        return collect(config('services.cloudways.sync_tables', []))
            ->filter(fn (mixed $table): bool => is_string($table) && $table !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     name: string,
     *     status: string,
     *     cloudways_rows: ?int,
     *     local_rows: ?int,
     *     cloudways_hash: ?string,
     *     local_hash: ?string,
     *     cloudways_only_columns: array<int, string>,
     *     local_only_columns: array<int, string>,
     *     message: string,
     * }
     */
    private function compareTable(
        string $sourceConnectionName,
        string $targetConnectionName,
        string $table,
        bool $existsOnCloudways,
        bool $existsLocally,
    ): array {
        if (! $existsOnCloudways) {
            return $this->missingComparisonTable($table, 'missing_cloudways', 'Missing on Cloudways.');
        }

        if (! $existsLocally) {
            return $this->missingComparisonTable($table, 'missing_local', 'Missing locally.');
        }

        $sourceColumnDefinitions = Schema::connection($sourceConnectionName)->getColumns($table);
        $targetColumnDefinitions = Schema::connection($targetConnectionName)->getColumns($table);
        $sourceColumns = array_column($sourceColumnDefinitions, 'name');
        $targetColumns = array_column($targetColumnDefinitions, 'name');
        $matchingColumns = collect(array_intersect($sourceColumns, $targetColumns))
            ->diff($this->excludedColumns($table))
            ->values()
            ->all();
        $cloudwaysOnlyColumns = array_values(array_diff($sourceColumns, $targetColumns));
        $localOnlyColumns = array_values(array_diff($targetColumns, $sourceColumns));

        if ($matchingColumns === []) {
            return [
                'name' => $table,
                'status' => 'different',
                'cloudways_rows' => null,
                'local_rows' => null,
                'cloudways_hash' => null,
                'local_hash' => null,
                'cloudways_only_columns' => $cloudwaysOnlyColumns,
                'local_only_columns' => $localOnlyColumns,
                'message' => 'No matching columns can be compared.',
            ];
        }

        $jsonColumns = $this->comparisonJsonColumns(
            $sourceColumnDefinitions,
            $targetColumnDefinitions,
            $matchingColumns,
        );
        $cloudways = $this->tableFingerprint($sourceConnectionName, $table, $matchingColumns, $jsonColumns);
        $local = $this->tableFingerprint($targetConnectionName, $table, $matchingColumns, $jsonColumns);
        $hasSchemaDifferences = $cloudwaysOnlyColumns !== [] || $localOnlyColumns !== [];
        $hasContentDifferences = $cloudways['rows'] !== $local['rows'] || $cloudways['hash'] !== $local['hash'];
        $isIdentical = ! $hasSchemaDifferences && ! $hasContentDifferences;

        return [
            'name' => $table,
            'status' => $isIdentical ? 'identical' : 'different',
            'cloudways_rows' => $cloudways['rows'],
            'local_rows' => $local['rows'],
            'cloudways_hash' => $cloudways['hash'],
            'local_hash' => $local['hash'],
            'cloudways_only_columns' => $cloudwaysOnlyColumns,
            'local_only_columns' => $localOnlyColumns,
            'message' => $this->comparisonMessage(
                $isIdentical,
                $hasContentDifferences,
                $cloudways['rows'],
                $local['rows'],
                $cloudwaysOnlyColumns,
                $localOnlyColumns,
            ),
        ];
    }

    /**
     * @return array{
     *     name: string,
     *     status: string,
     *     cloudways_rows: null,
     *     local_rows: null,
     *     cloudways_hash: null,
     *     local_hash: null,
     *     cloudways_only_columns: array<int, string>,
     *     local_only_columns: array<int, string>,
     *     message: string,
     * }
     */
    private function missingComparisonTable(string $table, string $status, string $message): array
    {
        return [
            'name' => $table,
            'status' => $status,
            'cloudways_rows' => null,
            'local_rows' => null,
            'cloudways_hash' => null,
            'local_hash' => null,
            'cloudways_only_columns' => [],
            'local_only_columns' => [],
            'message' => $message,
        ];
    }

    /**
     * @param  array<int, string>  $columns
     * @param  array<int, string>  $jsonColumns
     * @return array{rows: int, hash: string}
     */
    private function tableFingerprint(
        string $connectionName,
        string $table,
        array $columns,
        array $jsonColumns,
    ): array {
        $hash = hash_init('sha256');
        $rows = 0;
        $query = $this->scopedTableQuery($connectionName, $table)->select($columns);
        $jsonColumnLookup = array_fill_keys($jsonColumns, true);

        foreach ($this->comparisonOrderColumns($connectionName, $table, $columns) as $column) {
            $query->orderBy($column);
        }

        foreach ($query->cursor() as $row) {
            $encodedRow = [];

            foreach ($columns as $column) {
                $value = $row->{$column};
                $encodedRow[$column] = $this->encodedComparisonValue(
                    $value,
                    isset($jsonColumnLookup[$column]),
                );
            }

            hash_update($hash, json_encode($encodedRow, JSON_THROW_ON_ERROR)."\n");
            $rows++;
        }

        return [
            'rows' => $rows,
            'hash' => hash_final($hash),
        ];
    }

    /**
     * @param  array<int, array{name: string, type_name: string}>  $sourceColumns
     * @param  array<int, array{name: string, type_name: string}>  $targetColumns
     * @param  array<int, string>  $matchingColumns
     * @return array<int, string>
     */
    private function comparisonJsonColumns(
        array $sourceColumns,
        array $targetColumns,
        array $matchingColumns,
    ): array {
        return collect([
            ...$sourceColumns,
            ...$targetColumns,
        ])
            ->filter(fn (array $column): bool => in_array(
                strtolower((string) ($column['type_name'] ?? '')),
                ['json', 'jsonb'],
                true,
            ))
            ->pluck('name')
            ->intersect($matchingColumns)
            ->unique()
            ->values()
            ->all();
    }

    private function encodedComparisonValue(mixed $value, bool $isJson): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalizedValue = $isJson
            ? $this->canonicalJson((string) $value)
            : (string) $value;

        return base64_encode($normalizedValue);
    }

    private function canonicalJson(string $value): string
    {
        $decoded = json_decode(
            $value,
            flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING,
        );

        return json_encode(
            $this->canonicalJsonValue($decoded),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    private function canonicalJsonValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(
                fn (mixed $item): mixed => $this->canonicalJsonValue($item),
                $value,
            );
        }

        if (! $value instanceof stdClass) {
            return $value;
        }

        $properties = get_object_vars($value);
        ksort($properties, SORT_STRING);
        $canonicalObject = new stdClass;

        foreach ($properties as $key => $property) {
            $canonicalObject->{$key} = $this->canonicalJsonValue($property);
        }

        return $canonicalObject;
    }

    /**
     * @param  array<int, string>  $columns
     * @return array<int, string>
     */
    private function comparisonOrderColumns(string $connectionName, string $table, array $columns): array
    {
        $primaryIndex = collect(Schema::connection($connectionName)->getIndexes($table))
            ->first(fn (array $index): bool => ($index['primary'] ?? false) === true);
        $primaryColumns = array_values(array_intersect($primaryIndex['columns'] ?? [], $columns));

        return $primaryColumns !== [] ? $primaryColumns : $columns;
    }

    /**
     * @param  array<int, string>  $cloudwaysOnlyColumns
     * @param  array<int, string>  $localOnlyColumns
     */
    private function comparisonMessage(
        bool $isIdentical,
        bool $hasContentDifferences,
        int $cloudwaysRows,
        int $localRows,
        array $cloudwaysOnlyColumns,
        array $localOnlyColumns,
    ): string {
        if ($isIdentical) {
            return 'Contents match.';
        }

        $messages = [];

        if ($hasContentDifferences) {
            $messages[] = "Content differs: Cloudways {$cloudwaysRows} row(s), local {$localRows} row(s).";
        }

        if ($cloudwaysOnlyColumns !== []) {
            $messages[] = 'Cloudways-only columns: '.implode(', ', $cloudwaysOnlyColumns).'.';
        }

        if ($localOnlyColumns !== []) {
            $messages[] = 'Local-only columns: '.implode(', ', $localOnlyColumns).'.';
        }

        return implode(' ', $messages);
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
     * @return array{name: string, rows: int, columns: int, status: string, message: string}
     */
    private function syncTable(string $sourceConnectionName, string $targetConnectionName, string $table): array
    {
        $columns = $this->matchingColumns($sourceConnectionName, $targetConnectionName, $table);
        $rows = 0;
        $batch = [];

        if ($columns === []) {
            return [
                'name' => $table,
                'rows' => 0,
                'columns' => 0,
                'status' => 'skipped',
                'message' => "Skipped {$table}: no matching columns.",
            ];
        }

        foreach ($this->scopedTableQuery($sourceConnectionName, $table)->select($columns)->cursor() as $row) {
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
            'status' => 'imported',
            'message' => "Imported {$table}: {$rows} row(s), ".count($columns).' column(s).',
        ];
    }

    protected function refreshSourceConnection(string $sourceConnectionName): void
    {
        $driver = config("database.connections.{$sourceConnectionName}.driver");

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        @DB::purge($sourceConnectionName);
    }

    /**
     * @param  array<int, string>  $sourceTables
     * @param  array<int, string>  $targetTables
     * @return array{
     *     tables: array<int, string>,
     *     skipped: array<int, array{name: string, status: string, reason: string, message: string, missing_required_columns: array<int, string>}>,
     * }
     */
    private function syncPlan(
        string $sourceConnectionName,
        string $targetConnectionName,
        array $sourceTables,
        array $targetTables,
    ): array {
        $syncTables = [];
        $skippedTables = [];

        foreach ($sourceTables as $table) {
            if (! in_array($table, $targetTables, true)) {
                $skippedTables[] = $this->skippedTable(
                    table: $table,
                    reason: 'missing_local_table',
                    message: "Skipped {$table}: no matching local table.",
                );

                continue;
            }

            $missingRequiredColumns = $this->missingRequiredTargetColumns(
                $sourceConnectionName,
                $targetConnectionName,
                $table,
            );

            if ($missingRequiredColumns !== []) {
                $skippedTables[] = $this->skippedTable(
                    table: $table,
                    reason: 'missing_required_columns',
                    message: "Skipped {$table}: Cloudways is missing required local column(s): ".implode(', ', $missingRequiredColumns).'.',
                    missingRequiredColumns: $missingRequiredColumns,
                );

                continue;
            }

            if ($this->matchingColumns($sourceConnectionName, $targetConnectionName, $table) === []) {
                $skippedTables[] = $this->skippedTable(
                    table: $table,
                    reason: 'no_matching_columns',
                    message: "Skipped {$table}: no matching columns.",
                );

                continue;
            }

            $missingLocalUserIds = $this->missingLocalAnalysisSettingUserIds(
                $sourceConnectionName,
                $targetConnectionName,
                $table,
            );

            if ($missingLocalUserIds !== []) {
                $skippedTables[] = $this->skippedTable(
                    table: $table,
                    reason: 'missing_local_users',
                    message: "Skipped {$table}: local user(s) ".implode(', ', $missingLocalUserIds).' do not exist.',
                );

                continue;
            }

            $comparison = $this->compareTable(
                $sourceConnectionName,
                $targetConnectionName,
                $table,
                existsOnCloudways: true,
                existsLocally: true,
            );

            if ($comparison['status'] === 'identical') {
                $skippedTables[] = $this->skippedTable(
                    table: $table,
                    reason: 'already_identical',
                    message: "Skipped {$table}: contents already match.",
                );

                continue;
            }

            $syncTables[] = $table;
        }

        return [
            'tables' => $syncTables,
            'skipped' => $skippedTables,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function matchingColumns(string $sourceConnectionName, string $targetConnectionName, string $table): array
    {
        $sourceColumns = Schema::connection($sourceConnectionName)->getColumnListing($table);
        $targetColumns = Schema::connection($targetConnectionName)->getColumnListing($table);
        $excludedColumns = $this->excludedColumns($table);

        return collect(array_intersect($sourceColumns, $targetColumns))
            ->diff($excludedColumns)
            ->values()
            ->all();
    }

    private function deleteTargetTableRows(string $targetConnectionName, string $table): void
    {
        $this->scopedTableQuery($targetConnectionName, $table)->delete();
    }

    private function scopedTableQuery(string $connectionName, string $table): Builder
    {
        $query = DB::connection($connectionName)->table($table);
        $keyPrefixes = $this->keyPrefixes($table);

        if ($keyPrefixes === [] || ! Schema::connection($connectionName)->hasColumn($table, 'key')) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($keyPrefixes): void {
            foreach ($keyPrefixes as $keyPrefix) {
                $query->orWhere('key', 'like', $keyPrefix.'%');
            }
        });
    }

    /**
     * @return array<int, string>
     */
    private function keyPrefixes(string $table): array
    {
        return collect(config("services.cloudways.sync_table_scopes.{$table}.key_prefixes", []))
            ->filter(fn (mixed $prefix): bool => is_string($prefix) && $prefix !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function excludedColumns(string $table): array
    {
        return collect(config("services.cloudways.sync_table_scopes.{$table}.excluded_columns", []))
            ->filter(fn (mixed $column): bool => is_string($column) && $column !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function missingLocalAnalysisSettingUserIds(
        string $sourceConnectionName,
        string $targetConnectionName,
        string $table,
    ): array {
        $sourceUserIds = match ($table) {
            'analyze_research_settings' => $this->researchSettingUserIds($sourceConnectionName),
            'app_configs' => $this->analysisPreferenceUserIds($sourceConnectionName),
            default => [],
        };

        if ($sourceUserIds === []) {
            return [];
        }

        if (! Schema::connection($targetConnectionName)->hasTable('users')) {
            return $sourceUserIds;
        }

        $localUserIds = DB::connection($targetConnectionName)
            ->table('users')
            ->whereIn('id', $sourceUserIds)
            ->pluck('id')
            ->map(fn (mixed $userId): int => (int) $userId)
            ->all();

        return collect($sourceUserIds)
            ->diff($localUserIds)
            ->values()
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function researchSettingUserIds(string $connectionName): array
    {
        if (! Schema::connection($connectionName)->hasColumn('analyze_research_settings', 'user_id')) {
            return [];
        }

        return DB::connection($connectionName)
            ->table('analyze_research_settings')
            ->distinct()
            ->pluck('user_id')
            ->map(fn (mixed $userId): int => (int) $userId)
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function analysisPreferenceUserIds(string $connectionName): array
    {
        return $this->scopedTableQuery($connectionName, 'app_configs')
            ->pluck('key')
            ->map(function (mixed $key): ?int {
                foreach ($this->keyPrefixes('app_configs') as $keyPrefix) {
                    if (! is_string($key) || ! str_starts_with($key, $keyPrefix)) {
                        continue;
                    }

                    $userId = substr($key, strlen($keyPrefix));

                    return ctype_digit($userId) && (int) $userId > 0 ? (int) $userId : null;
                }

                return null;
            })
            ->filter(fn (?int $userId): bool => $userId !== null)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $missingRequiredColumns
     * @return array{name: string, status: string, reason: string, message: string, missing_required_columns: array<int, string>}
     */
    private function skippedTable(
        string $table,
        string $reason,
        string $message,
        array $missingRequiredColumns = [],
    ): array {
        return [
            'name' => $table,
            'status' => 'skipped',
            'reason' => $reason,
            'message' => $message,
            'missing_required_columns' => $missingRequiredColumns,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function missingRequiredTargetColumns(string $sourceConnectionName, string $targetConnectionName, string $table): array
    {
        $sourceColumns = Schema::connection($sourceConnectionName)->getColumnListing($table);

        return collect(Schema::connection($targetConnectionName)->getColumns($table))
            ->filter(fn (array $column): bool => $this->isRequiredTargetColumn($column))
            ->pluck('name')
            ->diff($sourceColumns)
            ->values()
            ->all();
    }

    /**
     * @param  array{name?: string, nullable?: bool, default?: mixed, auto_increment?: bool, generation?: mixed}  $column
     */
    private function isRequiredTargetColumn(array $column): bool
    {
        if (($column['nullable'] ?? false) === true) {
            return false;
        }

        if (($column['default'] ?? null) !== null) {
            return false;
        }

        if (($column['auto_increment'] ?? false) === true) {
            return false;
        }

        return ($column['generation'] ?? null) === null;
    }

    private function repairLatestRealtimePriceLinks(string $targetConnectionName): void
    {
        if (! Schema::connection($targetConnectionName)->hasTable('stock_holdings')) {
            return;
        }

        if (! Schema::connection($targetConnectionName)->hasTable('stock_realtime_prices')) {
            return;
        }

        if (! Schema::connection($targetConnectionName)->hasColumn('stock_holdings', 'latest_realtime_price_id')) {
            return;
        }

        DB::connection($targetConnectionName)
            ->table('stock_holdings')
            ->orderBy('id')
            ->chunkById(500, function (Collection $holdings) use ($targetConnectionName): void {
                foreach ($holdings as $holding) {
                    $latestRealtimePriceId = DB::connection($targetConnectionName)
                        ->table('stock_realtime_prices')
                        ->where('stock_holding_id', $holding->id)
                        ->whereNotNull('price')
                        ->whereIn('validation_status', ['valid', 'suspicious'])
                        ->whereNotIn('freshness_status', ['stale', 'unavailable', 'invalid'])
                        ->orderByDesc('as_of')
                        ->orderByDesc('id')
                        ->value('id');

                    DB::connection($targetConnectionName)
                        ->table('stock_holdings')
                        ->where('id', $holding->id)
                        ->update(['latest_realtime_price_id' => $latestRealtimePriceId]);
                }
            });
    }
}
