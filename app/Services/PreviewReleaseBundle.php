<?php

namespace App\Services;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

class PreviewReleaseBundle
{
    private const MaximumBytes = 268_435_456;

    public function create(string $directory, string $destination, string $commit): string
    {
        $root = realpath($directory);
        if ($root === false || ! is_dir($root) || file_exists($destination) || ! preg_match('/^[a-f0-9]{40}$/D', $commit)) {
            throw new RuntimeException('Invalid preview bundle source, destination or commit.');
        }
        $files = [];
        $bytes = 0;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $this->assertPath($relative);
            if ($file->isLink() || ! $file->isFile()) {
                throw new RuntimeException('Preview bundles permit only regular files.');
            }
            $bytes += $file->getSize();
            if ($bytes > self::MaximumBytes || count($files) >= 50_000) {
                throw new RuntimeException('Preview bundle exceeds size limits.');
            }
            $files[$relative] = hash_file('sha256', $file->getPathname());
        }
        ksort($files);
        $zip = new ZipArchive;
        if ($zip->open($destination, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('Cannot create preview bundle.');
        }
        try {
            $zip->addFromString('preview-release.json', json_encode(['format' => 'stocks-preview-release-v1', 'commit' => $commit, 'files' => $files], JSON_THROW_ON_ERROR));
            foreach ($files as $path => $hash) {
                if (! $zip->addFile($root.'/'.$path, $path)) {
                    throw new RuntimeException('Cannot add preview bundle file.');
                }
            }
        } finally {
            $zip->close();
        }

        return hash_file('sha256', $destination);
    }

    /** @return array{format: string, commit: string, files: array<string, string>} */
    public function inspect(string $archive, string $trustedDigest): array
    {
        if (! is_file($archive) || is_link($archive) || ! preg_match('/^[a-f0-9]{64}$/D', $trustedDigest)
            || ! hash_equals($trustedDigest, hash_file('sha256', $archive))) {
            throw new RuntimeException('Preview bundle digest mismatch.');
        }
        $zip = new ZipArchive;
        if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Invalid preview bundle.');
        }
        try {
            $manifest = $zip->getFromName('preview-release.json', 8_388_609);
            if (! is_string($manifest) || strlen($manifest) > 8_388_608) {
                throw new RuntimeException('Preview release manifest is missing or oversized.');
            }
            $decoded = json_decode($manifest, true, 32, JSON_THROW_ON_ERROR);
            if (($decoded['format'] ?? null) !== 'stocks-preview-release-v1'
                || ! is_string($decoded['commit'] ?? null) || ! preg_match('/^[a-f0-9]{40}$/D', $decoded['commit'])
                || ! is_array($decoded['files'] ?? null) || $zip->numFiles > 50_001) {
                throw new RuntimeException('Invalid preview release manifest.');
            }
            $seen = [];
            $bytes = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                if ($entry === false) {
                    throw new RuntimeException('Invalid preview archive entry.');
                }
                $name = $entry['name'];
                if (isset($seen[strtolower($name)])) {
                    throw new RuntimeException('Duplicate preview archive path.');
                }
                $seen[strtolower($name)] = true;
                $zip->getExternalAttributesIndex($index, $operatingSystem, $attributes);
                if (($attributes >> 16 & 0170000) === 0120000 || str_ends_with($name, '/')) {
                    throw new RuntimeException('Preview archive links and directory entries are not allowed.');
                }
                $bytes += $entry['size'];
                if ($bytes > self::MaximumBytes + 8_388_608) {
                    throw new RuntimeException('Preview bundle exceeds size limits.');
                }
                if ($name === 'preview-release.json') {
                    continue;
                }
                $this->assertPath($name);
                $hash = $decoded['files'][$name] ?? null;
                if (! is_string($hash) || ! preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                    throw new RuntimeException('Unlisted preview archive file.');
                }
                $stream = $zip->getStream($name);
                if ($stream === false) {
                    throw new RuntimeException('Cannot read preview archive file.');
                }
                try {
                    $context = hash_init('sha256');
                    hash_update_stream($context, $stream);
                    if (! hash_equals($hash, hash_final($context))) {
                        throw new RuntimeException('Preview archive file checksum mismatch.');
                    }
                } finally {
                    fclose($stream);
                }
            }
            if (count($decoded['files']) + 1 !== count($seen)) {
                throw new RuntimeException('Preview archive is missing files.');
            }

            return $decoded;
        } finally {
            $zip->close();
        }
    }

    public function extract(string $archive, string $trustedDigest, string $destination): array
    {
        $manifest = $this->inspect($archive, $trustedDigest);
        if (file_exists($destination) || is_link($destination) || ! mkdir($destination, 0700)) {
            throw new RuntimeException('Preview staging directory must be new.');
        }
        $zip = new ZipArchive;
        $zip->open($archive, ZipArchive::RDONLY);
        try {
            foreach ($manifest['files'] as $name => $hash) {
                $path = $destination.'/'.$name;
                if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0755, true)) {
                    throw new RuntimeException('Cannot create preview release directory.');
                }
                $input = $zip->getStream($name);
                $output = fopen($path, 'xb');
                if ($input === false || $output === false) {
                    throw new RuntimeException('Cannot extract preview release file.');
                }
                try {
                    if (stream_copy_to_stream($input, $output) === false) {
                        throw new RuntimeException('Cannot write preview release file.');
                    }
                } finally {
                    fclose($input);
                    fclose($output);
                }
                chmod($path, 0644);
                if (! hash_equals($hash, hash_file('sha256', $path))) {
                    throw new RuntimeException('Extracted preview release checksum mismatch.');
                }
            }
            file_put_contents($destination.'/preview-release.json', json_encode($manifest, JSON_THROW_ON_ERROR), LOCK_EX);
        } finally {
            $zip->close();
        }

        return $manifest;
    }

    public function verifyInstalled(string $directory, string $commit, string $manifestDigest): void
    {
        $directory = realpath($directory) ?: throw new RuntimeException('Installed preview directory is missing.');
        $manifestPath = $directory.'/preview-release.json';
        if (! is_file($manifestPath) || is_link($manifestPath) || ! preg_match('/^[a-f0-9]{64}$/D', $manifestDigest)
            || ! hash_equals($manifestDigest, hash_file('sha256', $manifestPath))) {
            throw new RuntimeException('Installed preview manifest changed.');
        }
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 32, JSON_THROW_ON_ERROR);
        if (($manifest['format'] ?? null) !== 'stocks-preview-release-v1' || ($manifest['commit'] ?? null) !== $commit
            || ! is_array($manifest['files'] ?? null) || ! isset($manifest['files']['artisan'], $manifest['files']['public/index.php'], $manifest['files']['vendor/autoload.php'])) {
            throw new RuntimeException('Installed preview release identity mismatch.');
        }
        foreach ($manifest['files'] as $path => $hash) {
            $this->assertPath($path);
            if (is_link($directory.'/'.$path) || ! is_file($directory.'/'.$path)
                || ! hash_equals($hash, hash_file('sha256', $directory.'/'.$path))) {
                throw new RuntimeException('Installed preview source files changed.');
            }
        }
        $private = $directory.DIRECTORY_SEPARATOR.'.stocks-preview-private';
        if (file_exists($private) || is_link($private)) {
            if (! is_dir($private) || is_link($private) || realpath($private) !== $private || fileowner($private) !== fileowner($directory)
                || (PHP_OS_FAMILY !== 'Windows' && (fileperms($private) & 0077) !== 0)) {
                throw new RuntimeException('Invalid installed private preview directory.');
            }
        }
        $iterator = new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            fn ($file): bool => $file->getPathname() !== $private);
        foreach (new RecursiveIteratorIterator($iterator) as $file) {
            $path = str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1));
            if ($file->isLink() || (! isset($manifest['files'][$path]) && ! in_array($path, ['.env', 'preview-release.json'], true)
                && ! str_starts_with($path, 'storage/') && ! str_starts_with($path, 'bootstrap/cache/'))) {
                throw new RuntimeException('Unlisted installed preview source file.');
            }
        }
    }

    private function assertPath(string $path): void
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\') || str_contains($path, ':')
            || preg_match('/[\x00-\x1f\x7f]/', $path) || $path === 'preview-release.json') {
            throw new RuntimeException('Invalid preview archive path.');
        }
        if ($path === '.stocks-preview-private' || str_starts_with($path, '.stocks-preview-private/') || $path === 'public/hot' || $path === 'auth.json'
            || ((str_starts_with($path, 'storage/') || str_starts_with($path, 'bootstrap/cache/')) && basename($path) !== '.gitignore')) {
            throw new RuntimeException('Preview archives cannot contain runtime state or credentials.');
        }
        foreach (explode('/', $path) as $segment) {
            if (in_array($segment, ['', '.', '..', '.git', 'node_modules'], true)
                || ($segment !== '.env.example' && ($segment === '.env' || str_starts_with($segment, '.env.')))) {
                throw new RuntimeException('Preview archives cannot contain secrets, dependencies for Node or unsafe paths.');
            }
        }
    }
}
