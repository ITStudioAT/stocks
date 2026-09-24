<?php

namespace App\Services;

use PDO;
use RuntimeException;
use stdClass;
use Throwable;

class PreviewSnapshotDatabase
{
    private const MaximumBytes = 60_000_000;

    /** @var array<string, list<string>> */
    private array $jsonColumns = [];

    public function __construct(private PDO $connection, private PreviewOriginalSchema $schema) {}

    public function assertSchema(): void
    {
        foreach ($this->schema->columns() as $table => $expected) {
            if ($this->driver() === 'mysql') {
                $query = $this->connection->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND TABLE_TYPE = ?');
                $query->execute([$table, 'BASE TABLE']);
                if ($query->fetchColumn() !== 'InnoDB') {
                    throw new RuntimeException('Snapshot requires transactional InnoDB business tables.');
                }
                $metadata = $this->connection->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
                $actual = array_column($metadata, 'Field');
                $this->jsonColumns[$table] = array_column(array_filter($metadata, fn (array $column): bool => strtolower($column['Type']) === 'json'), 'Field');
                $query = $this->connection->prepare('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ?');
                $query->execute([$table]);
                if ((int) $query->fetchColumn() !== 0) {
                    throw new RuntimeException('Snapshot target/source business triggers are unsupported.');
                }
            } else {
                $actual = array_column($this->connection->query("PRAGMA table_info(`{$table}`)")->fetchAll(PDO::FETCH_ASSOC), 'name');
            }
            sort($actual);
            sort($expected);
            if ($actual !== $expected) {
                throw new RuntimeException('Snapshot schema mismatch: '.$table);
            }
        }
    }

    /** @return array<string, list<array<string, scalar|null>>> */
    public function export(): array
    {
        $this->assertSchema();
        if ($this->connection->inTransaction()) {
            throw new RuntimeException('Snapshot requires its own read-only transaction.');
        }
        if ($this->driver() === 'mysql') {
            $this->connection->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->connection->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        } else {
            $this->connection->beginTransaction();
        }
        try {
            $tables = $this->read();
            $this->schema->validate($tables);

            return $tables;
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return array<string, list<array<string, scalar|null>>> */
    public function read(): array
    {
        $tables = [];
        $bytes = 0;
        foreach ($this->schema->columns() as $table => $columns) {
            $tables[$table] = [];
            $names = '`'.implode('`, `', $columns).'`';
            $order = '`'.implode('`, `', $this->schema->primaryKeys()[$table]).'`';
            $statement = $this->connection->query("SELECT {$names} FROM `{$table}` ORDER BY {$order}");
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $bytes += strlen(json_encode($row, JSON_THROW_ON_ERROR));
                if ($bytes > self::MaximumBytes) {
                    throw new RuntimeException('Snapshot exceeds 60 MB. No data was truncated; choose an explicit history scope before retrying.');
                }
                $tables[$table][] = $row;
            }
            $statement->closeCursor();
        }

        return $tables;
    }

    public function assertEmpty(): void
    {
        foreach ([...PreviewSnapshotPolicy::Tables, 'sessions', 'personal_access_tokens', 'password_reset_tokens', 'admin_login_codes'] as $table) {
            if ($this->connection->query("SELECT 1 FROM `{$table}` LIMIT 1")->fetchColumn() !== false) {
                throw new RuntimeException('First-fill refuses existing business data: '.$table);
            }
        }
    }

    /**
     * Import into an empty business schema with FK enforcement enabled. A rehearsal rolls back
     * the same inserts and read-back checks before the caller authorizes the durable transaction.
     *
     * @param  array<string, list<array<string, scalar|null>>>  $tables
     */
    public function import(array $tables, string $expectedBeforeDigest, bool $rehearsal = false): string
    {
        $this->assertSchema();
        $this->schema->validate($tables);
        $this->assertConstraints();
        $before = $this->protectedDigest();
        if ($this->driver() === 'mysql') {
            $this->connection->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        $this->connection->beginTransaction();
        try {
            $this->lockTables();
            if ($this->digest($this->read()) !== $expectedBeforeDigest) {
                throw new RuntimeException('Preview changed since its verified backup.');
            }
            $this->clear();
            $this->insert($tables);
            $digest = $this->digest($this->read());
            if ($digest !== $this->digest($tables) || $before !== $this->protectedDigest()) {
                throw new RuntimeException('Snapshot read-back or protected-table verification failed.');
            }
            if ($rehearsal) {
                $this->connection->rollBack();
                if ($this->digest($this->read()) !== $expectedBeforeDigest) {
                    throw new RuntimeException('Preview rehearsal did not restore its original data.');
                }
            } else {
                $this->connection->commit();
            }

            return $digest;
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    /** Restore only this transfer's exact dataset, never overwrite later edits.
     * @param  array<string, list<array<string, scalar|null>>>  $backup
     */
    public function restore(array $backup, string $expectedDigest, string $protectedDigest): void
    {
        if ($this->protectedDigest() !== $protectedDigest) {
            throw new RuntimeException('Restore refuses changed protected data.');
        }
        $this->import($backup, $expectedDigest);
    }

    public function protectedDigest(): string
    {
        $names = $this->driver() === 'mysql'
            ? $this->connection->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN)
            : $this->connection->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
        sort($names);
        $hash = hash_init('sha256');
        foreach (array_diff($names, array_keys($this->schema->columns())) as $table) {
            if (! preg_match('/^[a-z][a-z0-9_]*$/D', $table)) {
                throw new RuntimeException('Unsupported protected table name.');
            }
            $rows = $this->connection->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
            $encoded = array_map(fn (array $row): string => $this->canonicalRow($row), $rows);
            sort($encoded);
            hash_update($hash, $table.json_encode($encoded, JSON_THROW_ON_ERROR));
        }

        return hash_final($hash);
    }

    /** @param array<string, list<array<string, scalar|null>>> $tables */
    public function digest(array $tables): string
    {
        ksort($tables);
        $hash = hash_init('sha256');
        foreach ($tables as $table => $rows) {
            $rows = array_map(fn (array $row): string => $this->canonicalRow($row, $table), $rows);
            sort($rows, SORT_STRING);
            hash_update($hash, $table);
            foreach ($rows as $row) {
                hash_update($hash, $row);
            }
        }

        return hash_final($hash);
    }

    /** @param array<string, scalar|null> $row */
    private function canonicalRow(array $row, ?string $table = null): string
    {
        foreach ($this->jsonColumns[$table] ?? [] as $column) {
            if (isset($row[$column])) {
                $row[$column] = json_encode($this->canonicalJson(json_decode($row[$column], flags: JSON_THROW_ON_ERROR)), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            }
        }
        ksort($row);

        return json_encode(array_map(fn ($value): ?string => $value === null ? null : (string) $value, $row), JSON_THROW_ON_ERROR);
    }

    private function canonicalJson(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            ksort($properties);

            return (object) array_map($this->canonicalJson(...), $properties);
        }
        if (is_array($value)) {
            return array_map($this->canonicalJson(...), $value);
        }

        return $value;
    }

    /** @return iterable<array{table: string, row: array<string, scalar|null>}> */
    public function exportRecords(): iterable
    {
        $this->assertSchema();
        if ($this->connection->inTransaction()) {
            throw new RuntimeException('Source snapshot requires an independent read-only transaction.');
        }
        if ($this->driver() === 'mysql') {
            $this->connection->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->connection->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        } else {
            $this->connection->beginTransaction();
        }
        try {
            yield from $this->records();
        } finally {
            $this->connection->rollBack();
        }
    }

    /** @return iterable<array{table: string, row: array<string, scalar|null>}> */
    public function records(): iterable
    {
        foreach ($this->insertionOrder() as $table) {
            $columns = '`'.implode('`, `', $this->schema->columns()[$table]).'`';
            $order = '`'.implode('`, `', $this->schema->primaryKeys()[$table]).'`';
            if ($this->driver() === 'mysql') {
                $this->connection->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            }
            $query = $this->connection->query("SELECT {$columns} FROM `{$table}` ORDER BY {$order}");
            try {
                while (($row = $query->fetch(PDO::FETCH_ASSOC)) !== false) {
                    yield ['table' => $table, 'row' => $row];
                }
            } finally {
                $query->closeCursor();
                if ($this->driver() === 'mysql') {
                    $this->connection->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
                }
            }
        }
    }

    /** @param array<string, list<array<string, scalar|null>>> $tables
     * @return iterable<array{table: string, row: array<string, scalar|null>}>
     */
    public function recordsFromTables(array $tables): iterable
    {
        foreach ($this->insertionOrder() as $table) {
            foreach ($tables[$table] as $row) {
                yield ['table' => $table, 'row' => $row];
            }
        }
    }

    /** @param iterable<array{table: string, row: array<string, scalar|null>}> $records
     * @return array{sha256: string, counts: array<string, int>}
     */
    public function summarize(iterable $records): array
    {
        $states = [];
        $counts = [];
        $order = array_flip($this->insertionOrder());
        foreach (array_keys($order) as $table) {
            $states[$table] = hash_init('sha256');
            $counts[$table] = 0;
        }
        $lastTable = -1;
        foreach ($records as $record) {
            if (array_keys($record) !== ['table', 'row'] || ! is_string($record['table']) || ! is_array($record['row'])) {
                throw new RuntimeException('Invalid snapshot record.');
            }
            ['table' => $table, 'row' => $row] = $record;
            $this->schema->validateRow($table, $row);
            if ($order[$table] < $lastTable) {
                throw new RuntimeException('Snapshot table ordering is invalid.');
            }
            $lastTable = $order[$table];
            $encoded = $this->canonicalRow($row, $table);
            hash_update($states[$table], strlen($encoded).':'.$encoded);
            $counts[$table]++;
        }
        $hashes = array_map(hash_final(...), $states);

        return ['sha256' => hash('sha256', json_encode([$counts, $hashes], JSON_THROW_ON_ERROR)), 'counts' => $counts];
    }

    /** @param callable(): iterable<array{table: string, row: array<string, scalar|null>}> $records
     * @return array{sha256: string, counts: array<string, int>}
     */
    public function importRecords(callable $records, string $expectedBefore, bool $rehearsal = false): array
    {
        $this->assertSchema();
        $this->assertConstraints();
        $expected = $this->summarize($records());
        $protected = $this->protectedDigest();
        if ($this->driver() === 'mysql') {
            $this->connection->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        $this->connection->beginTransaction();
        try {
            $this->lockTables();
            if ($this->summarize($this->records())['sha256'] !== $expectedBefore) {
                throw new RuntimeException('Preview changed since its backup.');
            }
            $this->clear();
            $statements = [];
            foreach ($records() as ['table' => $table, 'row' => $row]) {
                $this->schema->validateRow($table, $row);
                $columns = $this->schema->columns()[$table];
                if (! isset($statements[$table])) {
                    $names = '`'.implode('`, `', $columns).'`';
                    $parameters = implode(', ', array_fill(0, count($columns), '?'));
                    $statements[$table] = $this->connection->prepare("INSERT INTO `{$table}` ({$names}) VALUES ({$parameters})");
                }
                foreach ($this->nullableColumns($table) as $column) {
                    $row[$column] = null;
                }
                $statements[$table]->execute(array_map(fn (string $column) => $row[$column], $columns));
            }
            $statements = [];
            foreach ($records() as ['table' => $table, 'row' => $row]) {
                $columns = $this->nullableColumns($table);
                if ($columns === []) {
                    continue;
                }
                $keys = $this->schema->primaryKeys()[$table];
                if (! isset($statements[$table])) {
                    $set = implode(', ', array_map(fn (string $column): string => "`{$column}` = ?", $columns));
                    $where = implode(' AND ', array_map(fn (string $column): string => "`{$column}` = ?", $keys));
                    $statements[$table] = $this->connection->prepare("UPDATE `{$table}` SET {$set} WHERE {$where}");
                }
                $statements[$table]->execute(array_map(fn (string $column) => $row[$column], [...$columns, ...$keys]));
            }
            $this->assertDatabaseReferences();
            $actual = $this->summarize($this->records());
            if ($actual !== $expected || $this->protectedDigest() !== $protected) {
                throw new RuntimeException('Original snapshot read-back verification failed.');
            }
            if ($rehearsal) {
                $this->connection->rollBack();
            } else {
                $this->connection->commit();
            }

            return $actual;
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    private function assertDatabaseReferences(): void
    {
        foreach ($this->schema->foreignKeys() as $table => $references) {
            foreach ($references as $reference) {
                $parent = $reference['foreign_table'];
                $column = $reference['columns'][0];
                $foreignColumn = $reference['foreign_columns'][0];
                $query = "SELECT 1 FROM `{$table}` child LEFT JOIN `{$parent}` parent ON child.`{$column}` = parent.`{$foreignColumn}` WHERE child.`{$column}` IS NOT NULL AND parent.`{$foreignColumn}` IS NULL LIMIT 1";
                if ($this->connection->query($query)->fetchColumn() !== false) {
                    throw new RuntimeException('Original snapshot contains a dangling reference.');
                }
            }
        }
        $checks = [
            ['stock_holdings', 'latest_quote_id', 'stock_price_quotes', ['id' => 'stock_holding_id']],
            ['stock_holdings', 'latest_realtime_price_id', 'stock_realtime_prices', ['id' => 'stock_holding_id']],
            ['stock_price_refresh_items', 'selected_quote_id', 'stock_price_quotes', ['stock_holding_id' => 'stock_holding_id']],
            ['stock_ai_researches', 'previous_research_id', 'stock_ai_researches', ['user_id' => 'user_id', 'stock_holding_id' => 'stock_holding_id']],
            ['stock_ai_research_sources', 'stock_ai_research_id', 'stock_ai_researches', ['user_id' => 'user_id', 'stock_holding_id' => 'stock_holding_id']],
            ['agent_conversation_messages', 'conversation_id', 'agent_conversations', ['user_id' => 'user_id']],
        ];
        foreach ($checks as [$table, $reference, $parent, $ownership]) {
            $conditions = [];
            foreach ($ownership as $left => $right) {
                $conditions[] = "(child.`{$left}` <> parent.`{$right}` OR (child.`{$left}` IS NULL AND parent.`{$right}` IS NOT NULL) OR (child.`{$left}` IS NOT NULL AND parent.`{$right}` IS NULL))";
            }
            if ($this->connection->query("SELECT 1 FROM `{$table}` child JOIN `{$parent}` parent ON child.`{$reference}` = parent.id WHERE ".implode(' OR ', $conditions).' LIMIT 1')->fetchColumn() !== false) {
                throw new RuntimeException('Original snapshot changes user or holding ownership.');
            }
        }
    }

    /** @param array<string, list<array<string, scalar|null>>> $tables */
    private function insert(array $tables): void
    {
        foreach ($this->insertionOrder() as $table) {
            $columns = $this->schema->columns()[$table];
            $names = '`'.implode('`, `', $columns).'`';
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $query = $this->connection->prepare("INSERT INTO `{$table}` ({$names}) VALUES ({$placeholders})");
            foreach ($tables[$table] as $row) {
                foreach ($this->nullableColumns($table) as $column) {
                    $row[$column] = null;
                }
                $query->execute(array_map(fn (string $column) => $row[$column], $columns));
            }
        }
        foreach ($tables as $table => $rows) {
            $columns = $this->nullableColumns($table);
            if ($columns === []) {
                continue;
            }
            $keys = $this->schema->primaryKeys()[$table];
            $set = implode(', ', array_map(fn (string $column): string => "`{$column}` = ?", $columns));
            $where = implode(' AND ', array_map(fn (string $column): string => "`{$column}` = ?", $keys));
            $query = $this->connection->prepare("UPDATE `{$table}` SET {$set} WHERE {$where}");
            foreach ($rows as $row) {
                $query->execute(array_map(fn (string $column) => $row[$column], [...$columns, ...$keys]));
            }
        }
    }

    /** @return list<string> */
    private function insertionOrder(): array
    {
        $pending = array_keys($this->schema->columns());
        $ordered = [];
        while ($pending !== []) {
            $progress = false;
            foreach ($pending as $index => $table) {
                $parents = array_column(array_filter($this->schema->foreignKeys()[$table] ?? [], fn (array $key): bool => ! $key['nullable']), 'foreign_table');
                if (array_diff($parents, $ordered) === []) {
                    $ordered[] = $table;
                    unset($pending[$index]);
                    $progress = true;
                }
            }
            if (! $progress) {
                throw new RuntimeException('Unsupported non-nullable snapshot reference cycle.');
            }
        }

        return $ordered;
    }

    /** @return list<string> */
    private function nullableColumns(string $table): array
    {
        $columns = [];
        foreach ($this->schema->foreignKeys()[$table] ?? [] as $key) {
            if ($key['nullable']) {
                $columns = [...$columns, ...$key['columns']];
            }
        }

        return array_values(array_unique($columns));
    }

    private function clear(): void
    {
        foreach (array_keys($this->schema->columns()) as $table) {
            $columns = $this->nullableColumns($table);
            if ($columns !== []) {
                $set = implode(', ', array_map(fn (string $column): string => "`{$column}` = NULL", $columns));
                $this->connection->exec("UPDATE `{$table}` SET {$set}");
            }
        }
        foreach (array_reverse($this->insertionOrder()) as $table) {
            $this->connection->exec("DELETE FROM `{$table}`");
        }
    }

    private function lockTables(): void
    {
        if ($this->driver() !== 'mysql') {
            return;
        }
        $buffered = $this->connection->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        $this->connection->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        try {
            foreach ($this->schema->primaryKeys() as $table => $columns) {
                $keys = '`'.implode('`, `', $columns).'`';
                $query = $this->connection->query("SELECT {$keys} FROM `{$table}` FOR UPDATE");
                try {
                    while ($query->fetch(PDO::FETCH_NUM) !== false) {
                    }
                } finally {
                    $query->closeCursor();
                }
            }
        } finally {
            $this->connection->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
        }
    }

    private function assertConstraints(): void
    {
        $query = $this->driver() === 'mysql' ? 'SELECT @@SESSION.foreign_key_checks' : 'PRAGMA foreign_keys';
        if ((int) $this->connection->query($query)->fetchColumn() !== 1) {
            throw new RuntimeException('Snapshot requires enabled foreign-key enforcement.');
        }
    }

    private function driver(): string
    {
        $driver = $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (! in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('Unsupported snapshot database driver.');
        }

        return $driver;
    }
}
