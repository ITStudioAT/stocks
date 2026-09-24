<?php

namespace App\Services;

use PDO;
use RuntimeException;
use Throwable;

class PreviewSnapshotDatabase
{
    private const MaximumBytes = 60_000_000;

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
                $actual = $this->connection->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
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
            $rows = array_map(fn (array $row): string => $this->canonicalRow($row), $rows);
            sort($rows, SORT_STRING);
            hash_update($hash, $table);
            foreach ($rows as $row) {
                hash_update($hash, $row);
            }
        }

        return hash_final($hash);
    }

    /** @param array<string, scalar|null> $row */
    private function canonicalRow(array $row): string
    {
        ksort($row);

        return json_encode(array_map(fn ($value): ?string => $value === null ? null : (string) $value, $row), JSON_THROW_ON_ERROR);
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
        foreach (array_keys($this->schema->columns()) as $table) {
            $query = $this->connection->query("SELECT * FROM `{$table}` FOR UPDATE");
            while ($query->fetch(PDO::FETCH_NUM) !== false) {
            }
            $query->closeCursor();
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
