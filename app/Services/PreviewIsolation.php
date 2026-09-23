<?php

namespace App\Services;

use RuntimeException;

class PreviewIsolation
{
    public function active(): bool
    {
        return config('security.preview.enabled', false)
            || config('app.env') === 'preview'
            || file_exists(base_path('storage/framework/stocks-preview-instance'));
    }

    public function assertIntegrationAllowed(): void
    {
        if ($this->active()) {
            throw new RuntimeException('External integrations are disabled in the Stocks preview.');
        }
    }

    /** @return list<string> */
    public function problems(): array
    {
        $problems = [];
        $require = function (bool $valid, string $field) use (&$problems): void {
            if (! $valid) {
                $problems[] = $field;
            }
        };
        $preview = config('security.preview', []);
        foreach (['source_app_id', 'target_app_id', 'server_id', 'source_database', 'source_database_user', 'source_key_sha256', 'source_url', 'target_root'] as $field) {
            $require(is_string($preview[$field] ?? null) && trim($preview[$field]) !== '', 'security.preview.'.$field);
        }
        $require($this->active() && config('security.preview.enabled') === true && config('app.env') === 'preview', 'preview.role');
        $require(! config('app.debug'), 'app.debug');
        $require(($preview['source_app_id'] ?? null) !== ($preview['target_app_id'] ?? null), 'preview.distinct_application');
        $require(realpath((string) ($preview['target_root'] ?? '')) === realpath(base_path()), 'preview.target_root');
        $require($this->ownsStoragePath(storage_path()), 'preview.storage');
        $sourceHost = parse_url((string) ($preview['source_url'] ?? ''), PHP_URL_HOST);
        $targetHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        $require(is_string($targetHost) && $targetHost !== '' && is_string($sourceHost)
            && strtolower($sourceHost) !== strtolower($targetHost)
            && parse_url((string) config('app.url'), PHP_URL_SCHEME) === 'https', 'preview.https_host');
        $key = (string) config('app.key');
        $decodedKey = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
        $fingerprint = (string) ($preview['source_key_sha256'] ?? '');
        $require(is_string($decodedKey) && strlen($decodedKey) === 32
            && preg_match('/^[a-f0-9]{64}$/D', $fingerprint) === 1
            && ! hash_equals($fingerprint, hash('sha256', $decodedKey)), 'preview.independent_key');
        $require(config('app.previous_keys', []) === [], 'app.previous_keys');

        $database = config('database.connections.'.config('database.default'), []);
        $require(in_array($database['driver'] ?? '', ['mysql', 'mariadb'], true), 'preview.mysql');
        $require(! empty($database['database']) && $database['database'] !== ($preview['source_database'] ?? null), 'preview.distinct_database');
        $require(! empty($database['username']) && $database['username'] !== ($preview['source_database_user'] ?? null), 'preview.distinct_database_user');
        foreach (['url', 'read', 'write', 'unix_socket', 'prefix'] as $field) {
            $require(empty($database[$field]), 'preview.database.'.$field);
        }
        foreach (['cache.default' => 'file', 'session.driver' => 'file', 'queue.default' => 'sync', 'mail.default' => 'array', 'filesystems.default' => 'local', 'app.maintenance.driver' => 'file'] as $field => $value) {
            $require(config($field) === $value, $field);
        }
        foreach (['session.files', 'view.compiled', 'cache.stores.file.path', 'cache.stores.file.lock_path'] as $field) {
            $require($this->ownsStoragePath(config($field)), $field);
        }
        $require(config('session.domain') === null && config('session.path') === '/', 'session.host_only');
        $require(config('session.secure') === true && config('session.http_only') === true && config('session.encrypt') === true, 'session.security');
        $require(in_array(config('session.same_site'), ['lax', 'strict'], true), 'session.same_site');
        $require(str_starts_with((string) config('session.cookie'), '__Host-stocks-preview-'), 'session.cookie');
        foreach (['services.eodhd.key', 'services.cloudways.deployment.access_token', 'database.connections.cloudways.password', 'filesystems.disks.s3.key', 'filesystems.disks.s3.secret'] as $field) {
            $require(empty(config($field)), $field);
        }
        foreach (config('ai.providers', []) as $provider => $configuration) {
            foreach (['key', 'secret', 'token', 'access_key_id', 'secret_access_key', 'session_token', 'use_default_credential_provider'] as $field) {
                $require(empty($configuration[$field]), 'ai.providers.'.$provider.'.'.$field);
            }
        }

        return array_values(array_unique($problems));
    }

    public function ownsStoragePath(mixed $path): bool
    {
        if (! is_string($path) || $path === '' || preg_match('~(?:^|[\\\\/])\.\.?([\\\\/]|$)~', $path)) {
            return false;
        }
        $application = realpath(base_path());
        $storage = realpath(storage_path());
        if ($application === false || $storage === false || is_file($path)) {
            return false;
        }
        $candidate = $path;
        while (! file_exists($candidate) && ! is_link($candidate)) {
            $parent = dirname($candidate);
            if ($parent === $candidate || $parent === '.') {
                return false;
            }
            $candidate = $parent;
        }
        $resolved = realpath($candidate);
        if ($resolved === false) {
            return false;
        }
        $normalize = fn (string $value): string => (PHP_OS_FAMILY === 'Windows' ? strtolower(str_replace('\\', '/', $value)) : $value).'/';

        return str_starts_with($normalize($storage), $normalize($application))
            && str_starts_with($normalize($resolved), $normalize($storage));
    }
}
