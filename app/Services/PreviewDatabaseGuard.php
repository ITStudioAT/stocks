<?php

namespace App\Services;

use PDO;
use RuntimeException;

class PreviewDatabaseGuard
{
    public function assertScopedGrants(PDO $connection, string $database): void
    {
        if ($connection->query('SELECT DATABASE()')->fetchColumn() !== $database) {
            throw new RuntimeException('Preview database identity mismatch.');
        }
        $this->validateGrants($connection->query('SHOW GRANTS FOR CURRENT_USER')->fetchAll(PDO::FETCH_COLUMN), $database);
    }

    /** @param list<string> $grants */
    public function validateGrants(array $grants, string $database): void
    {
        if ($grants === [] || preg_match('/^[a-zA-Z0-9]+$/D', $database) !== 1) {
            throw new RuntimeException('Preview requires an exact database grant without wildcard characters.');
        }
        $schemaGrant = false;
        foreach ($grants as $grant) {
            if (str_contains($grant, 'WITH GRANT OPTION')) {
                throw new RuntimeException('Preview database privileges exceed the application schema.');
            }
            if (preg_match('/^GRANT USAGE ON \*\.\* TO /D', $grant) === 1) {
                continue;
            }
            if (preg_match('/^GRANT (?:ALL PRIVILEGES|[A-Z ,]+) ON `'.preg_quote($database, '/').'`\.\* TO /D', $grant) !== 1) {
                throw new RuntimeException('Preview database privileges exceed the application schema.');
            }
            $schemaGrant = true;
        }
        if (! $schemaGrant) {
            throw new RuntimeException('Preview application schema grant is missing.');
        }
    }
}
