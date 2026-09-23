<?php

namespace App\Services;

use PDO;
use RuntimeException;

class PreviewInstallation
{
    /** @param array<string, string|null> $target */
    public function __construct(private array $target, private PreviewReleaseBundle $bundles, private PreviewDatabaseGuard $databases) {}

    public function assertTarget(string $root, int $expectedOwner, bool $firstInstall = true): void
    {
        if ($this->target['targetAppId'] === $this->target['sourceAppId']
            || $this->target['targetDatabase'] === $this->target['sourceDatabase']
            || $this->target['targetDatabaseUser'] === $this->target['sourceDatabaseUser']
            || realpath($root) !== $root || ! is_dir($root) || is_link($root)
            || basename($root) !== 'public_html' || basename(dirname($root)) !== $this->target['targetFolder']
            || fileowner($root) !== $expectedOwner
            || ($firstInstall && file_exists($root.'/storage/framework/stocks-preview-instance'))) {
            throw new RuntimeException('Preview target identity or first-install state is invalid.');
        }
    }

    public function assertEmptyDatabase(PDO $connection): void
    {
        $this->databases->assertScopedGrants($connection, $this->target['targetDatabase']);
        if ($connection->query('SHOW FULL TABLES')->fetchAll() !== []) {
            throw new RuntimeException('First installation requires an empty preview database; existing tables will not be replaced.');
        }
    }

    /**
     * The caller authenticates SSH and supplies the independently verified owner and archive digest.
     * Database checks run before moving any existing application files.
     *
     * @param  callable(string, string): array{PDO, array{host: string, port: string, database: string, username: string, password: string}}  $connectDatabase
     * @return array{commit: string, backup: string, state: string}
     */
    public function activate(string $archive, string $trustedDigest, string $root, int $expectedOwner, callable $connectDatabase): array
    {
        $this->assertTarget($root, $expectedOwner);
        $manifest = $this->bundles->inspect($archive, $trustedDigest);
        foreach (['artisan', 'vendor/autoload.php', 'public/index.php', 'public/build/manifest.json', 'bootstrap/app.php', 'app/Services/PreviewIsolation.php'] as $path) {
            if (! isset($manifest['files'][$path])) {
                throw new RuntimeException('Preview release is incomplete.');
            }
        }
        $private = dirname($root).DIRECTORY_SEPARATOR.'.stocks-preview-private';
        if (is_link($private) || (file_exists($private) && (! is_dir($private) || realpath($private) !== $private))) {
            throw new RuntimeException('Invalid private preview directory.');
        }
        if (file_exists($private) && fileowner($private) !== $expectedOwner) {
            throw new RuntimeException('Private preview directory belongs to another owner.');
        }
        if (! is_dir($private) && ! mkdir($private, 0700)) {
            throw new RuntimeException('Cannot create private preview directory.');
        }
        chmod($private, 0700);
        if (is_link($private.'/installation.lock')) {
            throw new RuntimeException('Invalid preview installation lock.');
        }
        $lock = fopen($private.'/installation.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Another preview installation is running.');
        }
        try {
            $this->assertTarget($root, $expectedOwner);
            $identifier = bin2hex(random_bytes(16));
            $staging = $private.DIRECTORY_SEPARATOR.'release-'.$identifier;
            $backup = $private.DIRECTORY_SEPARATOR.'template-'.$identifier;
            $this->bundles->extract($archive, $trustedDigest, $staging);
            [$connection, $database] = $connectDatabase($staging, $root);
            $this->validateDatabaseConfiguration($database);
            $this->assertEmptyDatabase($connection);
            $key = random_bytes(32);
            $accessPassword = bin2hex(random_bytes(24));
            $marker = [
                'format' => 'stocks-preview-instance-v1',
                'source_app_id' => $this->target['sourceAppId'],
                'target_app_id' => $this->target['targetAppId'],
                'root' => $root,
                'key_sha256' => hash('sha256', $key),
                'commit' => $manifest['commit'],
                'manifest_sha256' => hash_file('sha256', $staging.'/preview-release.json'),
                'state' => 'pending',
                'prior_database_empty' => true,
                'backup' => $backup,
            ];
            foreach (['storage/app/private', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache'] as $directory) {
                if (! is_dir($staging.'/'.$directory)) {
                    if (! mkdir($staging.'/'.$directory, 0775, true)) {
                        throw new RuntimeException('Cannot create preview runtime directory.');
                    }
                }
            }
            $this->write($staging.'/.env', $this->environment($database, $root, $key, password_hash($accessPassword, PASSWORD_BCRYPT, ['cost' => 10])), 0600);
            $this->write($staging.'/storage/app/private/preview-access.json', json_encode([
                'access_username' => 'preview', 'access_password' => $accessPassword,
            ], JSON_THROW_ON_ERROR), 0600);
            $this->write($staging.'/storage/framework/stocks-preview-instance', json_encode($marker, JSON_THROW_ON_ERROR), 0644);
            $this->write($staging.'/storage/framework/down', json_encode(['time' => time(), 'status' => 503, 'retry' => 60, 'stocks_preview_commit' => $manifest['commit']], JSON_THROW_ON_ERROR), 0644);
            chmod($staging, 0755);
            if (! rename($root, $backup)) {
                throw new RuntimeException('Cannot preserve the existing preview template.');
            }
            if (! rename($staging, $root)) {
                if (! rename($backup, $root)) {
                    throw new RuntimeException('Activation failed; the preserved template requires manual restoration from the private directory.');
                }
                throw new RuntimeException('Activation failed; the previous preview template was restored.');
            }

            return ['commit' => $manifest['commit'], 'backup' => $backup, 'state' => 'pending'];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Restore only the preserved first-install template; never discard the new database. */
    public function restoreTemplate(string $root, int $expectedOwner, string $commit): string
    {
        $this->assertTarget($root, $expectedOwner, firstInstall: false);
        $private = dirname($root).DIRECTORY_SEPARATOR.'.stocks-preview-private';
        if (realpath($private) !== $private || ! is_dir($private) || fileowner($private) !== $expectedOwner || is_link($private.'/installation.lock')) {
            throw new RuntimeException('Invalid private preview directory.');
        }
        $lock = fopen($private.'/installation.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Another preview installation is running.');
        }
        try {
            $this->assertTarget($root, $expectedOwner, firstInstall: false);
            $markerPath = $root.'/storage/framework/stocks-preview-instance';
            if (! is_file($markerPath) || is_link($markerPath)) {
                throw new RuntimeException('Preview installation marker is missing.');
            }
            $marker = json_decode((string) file_get_contents($markerPath), true, 16, JSON_THROW_ON_ERROR);
            $backup = $marker['backup'] ?? '';
            if (($marker['format'] ?? null) !== 'stocks-preview-instance-v1' || ($marker['root'] ?? null) !== $root
                || ($marker['target_app_id'] ?? null) !== $this->target['targetAppId'] || ($marker['commit'] ?? null) !== $commit
                || ! in_array($marker['state'] ?? null, ['pending', 'initializing', 'initialized'], true)
                || ! is_string($backup) || dirname($backup) !== $private || realpath($private) !== $private
                || realpath($backup) !== $backup || ! is_dir($backup) || fileowner($backup) !== $expectedOwner
                || ! preg_match('/^template-[a-f0-9]{32}$/D', basename($backup)) || is_link($private.'/installation.lock')) {
                throw new RuntimeException('Preview template restoration identity is invalid.');
            }
            $preserved = $private.'/failed-'.bin2hex(random_bytes(16));
            if (! rename($root, $preserved)) {
                throw new RuntimeException('Cannot preserve the pending preview installation.');
            }
            if (! rename($backup, $root)) {
                rename($preserved, $root);
                throw new RuntimeException('Template restoration failed; inspect the private backups.');
            }

            return $preserved;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array{host: string, port: string, database: string, username: string, password: string} $database */
    public function environment(array $database, string $root, string $key, string $accessPasswordHash): string
    {
        $this->validateDatabaseConfiguration($database);
        if (strlen($key) !== 32 || (password_get_info($accessPasswordHash)['algo'] ?? null) === null) {
            throw new RuntimeException('Invalid preview key or access password hash.');
        }
        $values = [
            'APP_NAME' => 'Stocks Vorschau', 'APP_ENV' => 'preview', 'APP_DEBUG' => 'false',
            'APP_URL' => $this->target['targetUrl'], 'APP_KEY' => 'base64:'.base64_encode($key),
            'STOCKS_PREVIEW' => 'true', 'PREVIEW_SERVER_ID' => $this->target['serverId'],
            'PREVIEW_SOURCE_APP_ID' => $this->target['sourceAppId'], 'PREVIEW_TARGET_APP_ID' => $this->target['targetAppId'],
            'PREVIEW_SOURCE_URL' => $this->target['sourceUrl'], 'PREVIEW_TARGET_ROOT' => $root,
            'PREVIEW_ACCESS_PASSWORD_HASH' => $accessPasswordHash,
            'PREVIEW_SOURCE_DATABASE' => $this->target['sourceDatabase'], 'PREVIEW_SOURCE_DATABASE_USER' => $this->target['sourceDatabaseUser'],
            'DB_CONNECTION' => 'mysql', 'DB_HOST' => $database['host'], 'DB_PORT' => $database['port'],
            'DB_DATABASE' => $database['database'], 'DB_USERNAME' => $database['username'], 'DB_PASSWORD' => $database['password'],
            'CACHE_STORE' => 'file', 'SESSION_DRIVER' => 'file', 'SESSION_COOKIE' => '__Host-stocks-preview-'.$this->target['targetAppId'],
            'SESSION_SECURE_COOKIE' => 'true', 'SESSION_HTTP_ONLY' => 'true', 'SESSION_ENCRYPT' => 'true', 'SESSION_SAME_SITE' => 'strict',
            'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'FILESYSTEM_DISK' => 'local',
            'APP_MAINTENANCE_DRIVER' => 'file', 'AWS_USE_DEFAULT_CREDENTIALS' => 'false',
            'SUPER_ADMIN_EMAIL' => 'preview-admin@stocks.invalid', 'SUPER_ADMIN_FIRST_NAME' => 'Preview', 'SUPER_ADMIN_LAST_NAME' => 'Administrator',
        ];
        $lines = [];
        foreach ($values as $name => $value) {
            if (preg_match('/[\r\n\x00]/', $value)) {
                throw new RuntimeException('Invalid preview environment value.');
            }
            $lines[] = $name.'="'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
        }

        return implode("\n", $lines)."\n";
    }

    /** @param array{host: string, port: string, database: string, username: string, password: string} $database */
    public function validateDatabaseConfiguration(array $database): void
    {
        if (! in_array($database['host'], ['localhost', '127.0.0.1'], true)
            || $database['database'] !== $this->target['targetDatabase']
            || $database['username'] !== $this->target['targetDatabaseUser']
            || $database['password'] === '' || ! ctype_digit($database['port']) || (int) $database['port'] < 1 || (int) $database['port'] > 65535) {
            throw new RuntimeException('Preview database configuration is invalid.');
        }
    }

    private function write(string $path, string $contents, int $mode): void
    {
        if (file_put_contents($path, $contents, LOCK_EX) !== strlen($contents) || ! chmod($path, $mode)) {
            throw new RuntimeException('Cannot write protected preview installation state.');
        }
    }
}
