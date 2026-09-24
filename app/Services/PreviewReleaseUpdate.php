<?php

namespace App\Services;

use Closure;
use RuntimeException;
use Throwable;

class PreviewReleaseUpdate
{
    /** @param array<string, string|null> $target */
    public function __construct(
        private array $target,
        private PreviewReleaseBundle $bundles = new PreviewReleaseBundle,
        private PreviewFileSwap $files = new PreviewFileSwap,
        private ?Closure $backgroundEnabled = null,
    ) {}

    /** @return array{current_commit: string, next_commit: string, state: string} */
    public function inspect(string $archive, string $digest, string $root, int $owner, string $expectedCommit): array
    {
        if ($this->backgroundEnabled && ($this->backgroundEnabled)()) {
            throw new RuntimeException('Turn preview OFF in production Data > Cloudways before updating it.');
        }
        $private = $this->privateDirectory($root, $owner);
        $this->files->assertNoPendingSwap($root);
        if (file_exists($private.'/update.json') || is_link($private.'/update.json')
            || file_exists($root.'/storage/framework/down') || is_link($root.'/storage/framework/down')) {
            throw new RuntimeException('Another preview update or maintenance operation is pending.');
        }
        $marker = $this->marker($root);
        if (($marker['commit'] ?? null) !== $expectedCommit || ($marker['state'] ?? null) !== 'active') {
            throw new RuntimeException('The installed preview release changed.');
        }
        $this->bundles->verifyInstalled($root, $expectedCommit, $marker['manifest_sha256']);
        $current = $this->readJson($root.'/preview-release.json');
        $next = $this->bundles->inspect($archive, $digest);
        if ($next['commit'] === $expectedCommit || $this->runtimeFiles($current) !== $this->runtimeFiles($next)) {
            throw new RuntimeException('The preview update must keep tracked storage files unchanged.');
        }

        return ['current_commit' => $expectedCommit, 'next_commit' => $next['commit'], 'state' => 'ready'];
    }

    /** @param callable(string): void $verifyRuntime
     * @return array{commit: string, previous_commit: string, state: string}
     */
    public function apply(string $archive, string $digest, string $root, int $owner, string $expectedCommit, callable $verifyRuntime): array
    {
        $private = $this->privateDirectory($root, $owner);
        $lock = $this->lock($private);
        try {
            $this->inspect($archive, $digest, $root, $owner, $expectedCommit);
            $marker = $this->marker($root);
            $newManifest = $this->bundles->inspect($archive, $digest);
            $id = bin2hex(random_bytes(16));
            $staging = $private.DIRECTORY_SEPARATOR.'release-'.$id;
            $this->bundles->extract($archive, $digest, $staging);
            $journal = [
                'format' => 'stocks-preview-update-v1', 'root' => $root, 'owner' => $owner,
                'old_marker' => $marker, 'old_commit' => $expectedCommit,
                'old_digest' => $marker['manifest_sha256'], 'new_commit' => $newManifest['commit'],
                'new_digest' => hash_file('sha256', $staging.'/preview-release.json'),
                'staging' => $staging, 'backup' => $private.DIRECTORY_SEPARATOR.'failed-'.$id,
                'rollback' => $private.DIRECTORY_SEPARATOR.'failed-'.bin2hex(random_bytes(16)),
                'nonce' => bin2hex(random_bytes(32)),
            ];
            $this->writeNew($private.'/update.json', json_encode($journal, JSON_THROW_ON_ERROR), 0600);
            try {
                $this->enterMaintenance($root, $private, $journal['nonce']);
                if ($this->backgroundEnabled && ($this->backgroundEnabled)()) {
                    throw new RuntimeException('Preview background processing was enabled during the update.');
                }
                $this->files->exchange($root, $staging, $journal['backup'], $newManifest['commit'], retainRuntime: true);
                $marker['commit'] = $newManifest['commit'];
                $marker['manifest_sha256'] = $journal['new_digest'];
                $this->writeMarker($root, $marker);
                $this->bundles->verifyInstalled($root, $newManifest['commit'], $journal['new_digest']);
                $verifyRuntime($root);
                $this->leaveMaintenance($root, $journal['nonce']);
                if (! unlink($private.'/update.json')) {
                    throw new RuntimeException('Could not finish the preview update journal.');
                }

                return ['commit' => $newManifest['commit'], 'previous_commit' => $expectedCommit, 'state' => 'released'];
            } catch (Throwable $exception) {
                try {
                    $this->recoverLocked($root, $private, $journal);
                } catch (Throwable $recoveryFailure) {
                    throw new RuntimeException('Preview update recovery is required; preserve private state and maintenance.', previous: $recoveryFailure);
                }
                throw $exception;
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function recover(string $root, int $owner): void
    {
        $private = $this->privateDirectory($root, $owner);
        $lock = $this->lock($private);
        try {
            $journal = $this->readJson($private.'/update.json');
            if (($journal['format'] ?? null) !== 'stocks-preview-update-v1'
                || ($journal['root'] ?? null) !== $root || ($journal['owner'] ?? null) !== $owner
                || ! preg_match('/^[a-f0-9]{64}$/D', $journal['nonce'] ?? '')
                || ! preg_match('/^[a-f0-9]{40}$/D', $journal['old_commit'] ?? '')
                || ! preg_match('/^[a-f0-9]{40}$/D', $journal['new_commit'] ?? '')
                || ! preg_match('/^[a-f0-9]{64}$/D', $journal['old_digest'] ?? '')
                || ! preg_match('/^[a-f0-9]{64}$/D', $journal['new_digest'] ?? '')) {
                throw new RuntimeException('Preview update journal identity mismatch.');
            }
            foreach (['staging' => 'release', 'backup' => 'failed', 'rollback' => 'failed'] as $field => $prefix) {
                if (dirname($journal[$field] ?? '') !== $private
                    || ! preg_match('/^'.$prefix.'-[a-f0-9]{32}$/D', basename($journal[$field]))) {
                    throw new RuntimeException('Preview update journal path mismatch.');
                }
            }
            $this->recoverLocked($root, $private, $journal);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<string, mixed> $journal */
    private function recoverLocked(string $root, string $private, array $journal): void
    {
        if (is_file($private.'/swap.json') && ! is_link($private.'/swap.json')) {
            $swap = $this->readJson($private.'/swap.json');
            $commit = $swap['commit'] ?? null;
            if (! in_array($commit, [$journal['old_commit'], $journal['new_commit']], true)) {
                throw new RuntimeException('Unrelated preview file exchange is pending.');
            }
            $this->files->recover($root, $commit);
        }
        $manifestPath = $root.'/preview-release.json';
        $digest = is_file($manifestPath) && ! is_link($manifestPath) ? hash_file('sha256', $manifestPath) : null;
        $marker = $this->marker($root);
        if ($digest === $journal['new_digest']) {
            if (($marker['commit'] ?? null) === $journal['new_commit']
                && ! file_exists($root.'/storage/framework/down')) {
                $this->bundles->verifyInstalled($root, $journal['new_commit'], $journal['new_digest']);
                unlink($private.'/update.json');

                return;
            }
            $this->files->exchange($root, $journal['backup'], $journal['rollback'], $journal['old_commit'], retainRuntime: true);
        } elseif ($digest !== $journal['old_digest']) {
            throw new RuntimeException('Preview update recovery found an unknown release.');
        }
        $this->writeMarker($root, $journal['old_marker']);
        $this->bundles->verifyInstalled($root, $journal['old_commit'], $journal['old_digest']);
        if (is_file($root.'/storage/framework/down')) {
            $this->leaveMaintenance($root, $journal['nonce']);
        }
        if (! unlink($private.'/update.json')) {
            throw new RuntimeException('Could not finish the recovered preview update.');
        }
    }

    private function privateDirectory(string $root, int $owner): string
    {
        $private = $root.DIRECTORY_SEPARATOR.'.stocks-preview-private';
        if ($root !== $this->target['canonicalTargetRoot'] || realpath($root) !== $root
            || fileowner($root) !== $owner || realpath($private) !== $private || is_link($private)
            || fileowner($private) !== $owner
            || (PHP_OS_FAMILY !== 'Windows' && (fileperms($private) & 0077) !== 0)) {
            throw new RuntimeException('Preview update root or owner mismatch.');
        }

        return $private;
    }

    /** @return resource */
    private function lock(string $private): mixed
    {
        if (is_link($private.'/installation.lock')) {
            throw new RuntimeException('Invalid preview installation lock.');
        }
        $lock = fopen($private.'/installation.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Another preview installation operation is running.');
        }

        return $lock;
    }

    /** @return array<string, mixed> */
    private function marker(string $root): array
    {
        $marker = $this->readJson($root.'/storage/framework/stocks-preview-instance');
        if (($marker['format'] ?? null) !== 'stocks-preview-instance-v1'
            || ($marker['root'] ?? null) !== $root
            || ($marker['source_app_id'] ?? null) !== $this->target['sourceAppId']
            || ($marker['target_app_id'] ?? null) !== $this->target['targetAppId']) {
            throw new RuntimeException('Preview installation marker mismatch.');
        }

        return $marker;
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        if (is_link($path) || ! is_file($path) || filesize($path) > 8_388_608) {
            throw new RuntimeException('Invalid preview update state file.');
        }

        return json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $manifest @return array<string, string> */
    private function runtimeFiles(array $manifest): array
    {
        return array_filter($manifest['files'], fn (string $path): bool => str_starts_with($path, 'storage/'), ARRAY_FILTER_USE_KEY);
    }

    private function enterMaintenance(string $root, string $private, string $nonce): void
    {
        $path = $private.'/update-maintenance-'.$nonce.'.json';
        $this->writeNew($path, json_encode(['time' => time(), 'status' => 503, 'retry' => 60, 'stocks_preview_update' => $nonce], JSON_THROW_ON_ERROR), 0600);
        if (! link($path, $root.'/storage/framework/down')) {
            throw new RuntimeException('Could not enter preview update maintenance.');
        }
    }

    private function leaveMaintenance(string $root, string $nonce): void
    {
        $path = $root.'/storage/framework/down';
        $state = $this->readJson($path);
        if (($state['stocks_preview_update'] ?? null) !== $nonce || ! unlink($path)) {
            throw new RuntimeException('Preview update maintenance ownership mismatch.');
        }
    }

    /** @param array<string, mixed> $marker */
    private function writeMarker(string $root, array $marker): void
    {
        $path = $root.'/storage/framework/stocks-preview-instance';
        $temporary = $root.'/storage/framework/.stocks-preview-marker-'.bin2hex(random_bytes(16));
        $this->writeNew($temporary, json_encode($marker, JSON_THROW_ON_ERROR), 0644);
        if (! rename($temporary, $path)) {
            throw new RuntimeException('Could not publish preview release marker.');
        }
    }

    private function writeNew(string $path, string $contents, int $mode): void
    {
        $handle = fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Could not create private preview update state.');
        }
        try {
            if (! chmod($path, $mode) || fwrite($handle, $contents) !== strlen($contents)
                || ! fflush($handle) || ! fsync($handle)) {
                throw new RuntimeException('Could not persist preview update state.');
            }
        } finally {
            fclose($handle);
        }
    }
}
