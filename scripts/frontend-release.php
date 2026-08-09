<?php

declare(strict_types=1);

const FRONTEND_RELEASE_ARCHIVE = 'deployment/frontend-build.tar.gz';
const FRONTEND_RELEASE_ARCHIVE_HASH = 'deployment/frontend-build.sha256';
const FRONTEND_RELEASE_SOURCE = 'deployment/source-commit';
const FRONTEND_RELEASE_MANIFEST = 'deployment/source-manifest.sha256';
const FRONTEND_RELEASE_MAX_ARCHIVE_BYTES = 25_000_000;
const FRONTEND_RELEASE_MAX_FILE_BYTES = 16_000_000;
const FRONTEND_RELEASE_MAX_TOTAL_BYTES = 24_000_000;
const FRONTEND_RELEASE_MAX_ENTRIES = 1_000;
const FRONTEND_RELEASE_MAX_TRAILING_BYTES = 1_048_576;

function releaseProjectPath(string $relativePath = ''): string
{
    $projectDirectory = dirname(__DIR__);

    return $relativePath === ''
        ? $projectDirectory
        : $projectDirectory.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
}

/** @param array<int, string> $command */
function runReleaseCommand(array $command): int
{
    $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, releaseProjectPath());

    if (! is_resource($process)) {
        return 1;
    }

    return proc_close($process);
}

function removeReleaseDirectory(string $directory): void
{
    $projectPublicDirectory = realpath(releaseProjectPath('public'));
    $resolvedParent = realpath(dirname($directory));

    if ($projectPublicDirectory === false || $resolvedParent !== $projectPublicDirectory) {
        throw new RuntimeException("Refusing to remove unexpected release directory: {$directory}");
    }

    if (is_link($directory)) {
        unlink($directory);

        return;
    }

    if (! is_dir($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        $item->isDir() && ! $item->isLink()
            ? rmdir($item->getPathname())
            : unlink($item->getPathname());
    }

    rmdir($directory);
}

function validateReleaseSource(string $sourceCommit): void
{
    if (! preg_match('/^[0-9a-f]{40,64}$/', $sourceCommit)) {
        throw new RuntimeException('The frontend release source commit is invalid.');
    }
}

function normalizeFrontendPath(string $path, bool $directory = false): string
{
    if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\')) {
        throw new RuntimeException('The frontend release contains an unsafe path.');
    }

    if ($directory) {
        $path = rtrim($path, '/');
    }

    if ($path === '.' || $path === './') {
        return '.';
    }

    if (str_starts_with($path, './')) {
        $path = substr($path, 2);
    }

    if ($path === '' || str_starts_with($path, '/') || str_contains($path, '//')) {
        throw new RuntimeException('The frontend release contains an unsafe path.');
    }

    $segments = explode('/', $path);

    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            throw new RuntimeException('The frontend release contains path traversal.');
        }

        if (preg_match('/^[A-Za-z0-9_-][A-Za-z0-9._-]*$/', $segment) !== 1) {
            throw new RuntimeException('The frontend release contains an unsafe path.');
        }
    }

    return implode('/', $segments);
}

function validateFrontendAssetPath(string $path): string
{
    $normalizedPath = normalizeFrontendPath($path);

    if ($normalizedPath !== $path || ! str_starts_with($normalizedPath, 'assets/')) {
        throw new RuntimeException('The frontend manifest references a path outside assets/.');
    }

    $fileName = basename($normalizedPath);
    $extension = strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));
    $allowedExtensions = [
        'avif',
        'css',
        'eot',
        'gif',
        'ico',
        'jpeg',
        'jpg',
        'js',
        'json',
        'map',
        'mjs',
        'mp3',
        'mp4',
        'ogg',
        'otf',
        'png',
        'svg',
        'ttf',
        'wasm',
        'webm',
        'webp',
        'woff',
        'woff2',
    ];

    if (! in_array($extension, $allowedExtensions, true)) {
        throw new RuntimeException("The frontend release contains a disallowed asset extension: {$normalizedPath}");
    }

    if (preg_match('/\.(?:asp|aspx|cgi|fcgi|jsp|jspx|phar|php\d*|pht|phtml|pl|py|rb|sh)(?:\.|$)/i', $fileName) === 1) {
        throw new RuntimeException("The frontend release contains a server-executable asset name: {$normalizedPath}");
    }

    return $normalizedPath;
}

/** @return array<int, string> */
function frontendManifestFiles(string $manifest): array
{
    $decoded = json_decode($manifest, true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($decoded)) {
        throw new RuntimeException('The frontend manifest must contain a JSON object.');
    }

    $paths = ['manifest.json'];

    foreach ($decoded as $entry) {
        if (! is_array($entry)) {
            throw new RuntimeException('The frontend manifest contains an invalid entry.');
        }

        if (array_key_exists('file', $entry)) {
            if (! is_string($entry['file'])) {
                throw new RuntimeException('The frontend manifest contains an invalid file path.');
            }

            $paths[] = validateFrontendAssetPath($entry['file']);
        }

        foreach (['assets', 'css'] as $field) {
            if (! array_key_exists($field, $entry)) {
                continue;
            }

            if (! is_array($entry[$field])) {
                throw new RuntimeException("The frontend manifest contains an invalid {$field} list.");
            }

            foreach ($entry[$field] as $assetPath) {
                if (! is_string($assetPath)) {
                    throw new RuntimeException("The frontend manifest contains an invalid {$field} path.");
                }

                $paths[] = validateFrontendAssetPath($assetPath);
            }
        }
    }

    $paths = array_values(array_unique($paths));
    sort($paths, SORT_STRING);

    return $paths;
}

/** @param array<int, string> $filePaths
 * @return array<int, string>
 */
function frontendExpectedDirectories(array $filePaths): array
{
    $directories = ['.'];

    foreach ($filePaths as $filePath) {
        $directory = dirname($filePath);

        while ($directory !== '.') {
            $directories[] = str_replace('\\', '/', $directory);
            $directory = dirname($directory);
        }
    }

    $directories = array_values(array_unique($directories));
    sort($directories, SORT_STRING);

    return $directories;
}

/**
 * @return array{
 *     files: array<string, string>,
 *     directories: array<int, string>,
 * }
 */
function validateFrontendBuildTree(string $buildDirectory): array
{
    if (is_link($buildDirectory) || ! is_dir($buildDirectory)) {
        throw new RuntimeException('The frontend release build must be a real directory.');
    }

    $rootMetadata = lstat($buildDirectory);

    if (! is_array($rootMetadata) || (($rootMetadata['mode'] ?? 0) & 07000) !== 0) {
        throw new RuntimeException('The frontend release build directory has unsafe permission bits.');
    }

    $resolvedBuildDirectory = realpath($buildDirectory);

    if ($resolvedBuildDirectory === false) {
        throw new RuntimeException('The frontend release build directory could not be resolved.');
    }

    $files = [];
    $directories = ['.'];
    $manifest = null;
    $totalBytes = 0;
    $entryCount = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolvedBuildDirectory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $item) {
        $entryCount++;

        if ($entryCount > FRONTEND_RELEASE_MAX_ENTRIES) {
            throw new RuntimeException('The frontend release contains too many entries.');
        }

        $path = $item->getPathname();
        $relativePath = str_replace('\\', '/', substr($path, strlen($resolvedBuildDirectory) + 1));

        if ($item->isLink()) {
            throw new RuntimeException("The frontend release contains a symbolic link: {$relativePath}");
        }

        $metadata = lstat($path);

        if (! is_array($metadata)) {
            throw new RuntimeException("The frontend release entry could not be inspected: {$relativePath}");
        }

        $fileType = $metadata['mode'] & 0170000;

        if ($fileType === 0040000) {
            if (($metadata['mode'] & 07000) !== 0) {
                throw new RuntimeException("The frontend release directory has unsafe permission bits: {$relativePath}");
            }

            $directories[] = normalizeFrontendPath($relativePath, directory: true);

            continue;
        }

        if ($fileType !== 0100000) {
            throw new RuntimeException("The frontend release contains a non-regular file: {$relativePath}");
        }

        $normalizedPath = normalizeFrontendPath($relativePath);

        if (($metadata['nlink'] ?? 1) !== 1) {
            throw new RuntimeException("The frontend release contains a hard-linked file: {$normalizedPath}");
        }

        $permissionBits = $metadata['mode'] & 07777;

        if (($permissionBits & ~0666) !== 0) {
            throw new RuntimeException("The frontend release file has executable or special permission bits: {$normalizedPath}");
        }

        $size = (int) ($metadata['size'] ?? -1);

        if ($size < 0 || $size > FRONTEND_RELEASE_MAX_FILE_BYTES) {
            throw new RuntimeException("The frontend release file exceeds the size limit: {$normalizedPath}");
        }

        $totalBytes += $size;

        if ($totalBytes > FRONTEND_RELEASE_MAX_TOTAL_BYTES) {
            throw new RuntimeException('The frontend release exceeds the total uncompressed size limit.');
        }

        $hash = hash_file('sha256', $path);

        if (! is_string($hash)) {
            throw new RuntimeException("The frontend release file could not be hashed: {$normalizedPath}");
        }

        $files[$normalizedPath] = $hash;

        if ($normalizedPath === 'manifest.json') {
            $manifest = file_get_contents($path);
        }
    }

    if (! is_string($manifest)) {
        throw new RuntimeException('The frontend release does not contain manifest.json.');
    }

    assertFrontendEntryAllowlist($files, $directories, $manifest);
    ksort($files, SORT_STRING);
    $directories = array_values(array_unique($directories));
    sort($directories, SORT_STRING);

    return [
        'files' => $files,
        'directories' => $directories,
    ];
}

/**
 * @param  array<string, string>  $files
 * @param  array<int, string>  $directories
 */
function assertFrontendEntryAllowlist(array $files, array $directories, string $manifest): void
{
    $expectedFiles = frontendManifestFiles($manifest);
    $actualFiles = array_keys($files);
    sort($actualFiles, SORT_STRING);

    if (in_array('deployment-source.txt', $actualFiles, true)) {
        throw new RuntimeException('The frontend release archive contains a public source commit marker.');
    }

    if ($actualFiles !== $expectedFiles) {
        $unexpected = array_values(array_diff($actualFiles, $expectedFiles));
        $missing = array_values(array_diff($expectedFiles, $actualFiles));
        $details = [
            ...array_map(fn (string $path): string => "unexpected: {$path}", $unexpected),
            ...array_map(fn (string $path): string => "missing: {$path}", $missing),
        ];

        throw new RuntimeException("The frontend release does not match its manifest allowlist:\n - ".implode("\n - ", $details));
    }

    $expectedDirectories = frontendExpectedDirectories($expectedFiles);

    foreach ($directories as $directory) {
        if (! in_array($directory, $expectedDirectories, true)) {
            throw new RuntimeException("The frontend release contains an unexpected directory: {$directory}");
        }
    }
}

function tarStringField(string $header, int $offset, int $length): string
{
    $value = substr($header, $offset, $length);
    $nullPosition = strpos($value, "\0");

    return $nullPosition === false ? $value : substr($value, 0, $nullPosition);
}

function tarOctalField(string $header, int $offset, int $length, string $field): int
{
    $value = trim(substr($header, $offset, $length), " \0");

    if ($value === '' || preg_match('/^[0-7]+$/', $value) !== 1) {
        throw new RuntimeException("The frontend release archive has an invalid {$field} field.");
    }

    return intval($value, 8);
}

/** @param resource $stream */
function readGzipBytes($stream, int $length): string
{
    $contents = '';

    while (strlen($contents) < $length && ! gzeof($stream)) {
        $chunk = gzread($stream, min(1_048_576, $length - strlen($contents)));

        if ($chunk === false) {
            throw new RuntimeException('The frontend release archive could not be decompressed.');
        }

        if ($chunk === '') {
            break;
        }

        $contents .= $chunk;
    }

    return $contents;
}

function validateTarHeaderChecksum(string $header): void
{
    $storedChecksum = tarOctalField($header, 148, 8, 'checksum');
    $checksumHeader = substr_replace($header, str_repeat(' ', 8), 148, 8);
    $actualChecksum = 0;

    for ($index = 0; $index < 512; $index++) {
        $actualChecksum += ord($checksumHeader[$index]);
    }

    if ($actualChecksum !== $storedChecksum) {
        throw new RuntimeException('The frontend release archive has an invalid header checksum.');
    }
}

/**
 * @return array{
 *     files: array<string, string>,
 *     directories: array<int, string>,
 * }
 */
function inspectFrontendArchive(string $archivePath): array
{
    if (is_link($archivePath) || ! is_file($archivePath)) {
        throw new RuntimeException('The frontend release archive is missing or is not a regular file.');
    }

    $archiveSize = filesize($archivePath);

    if (! is_int($archiveSize) || $archiveSize < 1 || $archiveSize > FRONTEND_RELEASE_MAX_ARCHIVE_BYTES) {
        throw new RuntimeException('The frontend release archive exceeds the compressed size limit.');
    }

    $stream = gzopen($archivePath, 'rb');

    if ($stream === false) {
        throw new RuntimeException('The frontend release archive could not be opened.');
    }

    $files = [];
    $directories = [];
    $manifest = null;
    $totalBytes = 0;
    $entryCount = 0;
    $terminated = false;

    try {
        while (! gzeof($stream)) {
            $header = readGzipBytes($stream, 512);

            if ($header === '') {
                break;
            }

            if (strlen($header) !== 512) {
                throw new RuntimeException('The frontend release archive has a truncated header.');
            }

            if ($header === str_repeat("\0", 512)) {
                $terminated = true;
                $secondEndBlock = readGzipBytes($stream, 512);

                if ($secondEndBlock !== str_repeat("\0", 512)) {
                    throw new RuntimeException('The frontend release archive has an invalid end marker.');
                }

                $trailingBytes = 512;

                while (! gzeof($stream)) {
                    $remainingTrailingBytes = FRONTEND_RELEASE_MAX_TRAILING_BYTES - $trailingBytes;

                    if ($remainingTrailingBytes <= 0) {
                        throw new RuntimeException('The frontend release archive has excessive trailing padding.');
                    }

                    $trailing = readGzipBytes($stream, min(65_536, $remainingTrailingBytes + 1));
                    $trailingBytes += strlen($trailing);

                    if ($trailingBytes > FRONTEND_RELEASE_MAX_TRAILING_BYTES) {
                        throw new RuntimeException('The frontend release archive has excessive trailing padding.');
                    }

                    if ($trailing !== '' && trim($trailing, "\0") !== '') {
                        throw new RuntimeException('The frontend release archive contains data after its end marker.');
                    }
                }

                break;
            }

            $entryCount++;

            if ($entryCount > FRONTEND_RELEASE_MAX_ENTRIES) {
                throw new RuntimeException('The frontend release archive contains too many entries.');
            }

            validateTarHeaderChecksum($header);

            if (! in_array(substr($header, 257, 6), ["ustar\0", 'ustar '], true)) {
                throw new RuntimeException('The frontend release archive must use the ustar format.');
            }

            $name = tarStringField($header, 0, 100);
            $prefix = tarStringField($header, 345, 155);
            $archivePathName = $prefix === '' ? $name : "{$prefix}/{$name}";
            $type = $header[156];
            $directory = $type === '5';

            if (! in_array($type, ["\0", '0', '5'], true)) {
                throw new RuntimeException('The frontend release archive contains a link or special entry.');
            }

            if (tarStringField($header, 157, 100) !== '') {
                throw new RuntimeException('The frontend release archive contains unexpected link metadata.');
            }

            $normalizedPath = normalizeFrontendPath($archivePathName, $directory);

            if (isset($files[$normalizedPath]) || in_array($normalizedPath, $directories, true)) {
                throw new RuntimeException("The frontend release archive contains a duplicate entry: {$normalizedPath}");
            }

            $mode = tarOctalField($header, 100, 8, 'mode');
            $size = tarOctalField($header, 124, 12, 'size');

            if ($directory) {
                if ($size !== 0) {
                    throw new RuntimeException("The frontend release archive directory contains data: {$normalizedPath}");
                }

                if (($mode & ~0777) !== 0) {
                    throw new RuntimeException("The frontend release archive directory has special permission bits: {$normalizedPath}");
                }

                $directories[] = $normalizedPath;

                continue;
            }

            if (($mode & ~0666) !== 0) {
                throw new RuntimeException("The frontend release archive file has executable or special permission bits: {$normalizedPath}");
            }

            if ($size > FRONTEND_RELEASE_MAX_FILE_BYTES) {
                throw new RuntimeException("The frontend release archive file exceeds the size limit: {$normalizedPath}");
            }

            $totalBytes += $size;

            if ($totalBytes > FRONTEND_RELEASE_MAX_TOTAL_BYTES) {
                throw new RuntimeException('The frontend release archive exceeds the total uncompressed size limit.');
            }

            $hashContext = hash_init('sha256');
            $remaining = $size;
            $contents = '';

            while ($remaining > 0) {
                $chunk = readGzipBytes($stream, min(1_048_576, $remaining));

                if ($chunk === '') {
                    throw new RuntimeException("The frontend release archive file is truncated: {$normalizedPath}");
                }

                hash_update($hashContext, $chunk);

                if ($normalizedPath === 'manifest.json') {
                    $contents .= $chunk;
                }

                $remaining -= strlen($chunk);
            }

            $padding = (512 - ($size % 512)) % 512;

            if ($padding > 0) {
                $paddingContents = readGzipBytes($stream, $padding);

                if (strlen($paddingContents) !== $padding) {
                    throw new RuntimeException("The frontend release archive file padding is truncated: {$normalizedPath}");
                }

                if (trim($paddingContents, "\0") !== '') {
                    throw new RuntimeException("The frontend release archive file padding is invalid: {$normalizedPath}");
                }
            }

            $files[$normalizedPath] = hash_final($hashContext);

            if ($normalizedPath === 'manifest.json') {
                $manifest = $contents;
            }
        }
    } finally {
        gzclose($stream);
    }

    if (! $terminated) {
        throw new RuntimeException('The frontend release archive has no valid end marker.');
    }

    if (! is_string($manifest)) {
        throw new RuntimeException('The frontend release archive does not contain manifest.json.');
    }

    assertFrontendEntryAllowlist($files, $directories, $manifest);
    ksort($files, SORT_STRING);
    sort($directories, SORT_STRING);

    return [
        'files' => $files,
        'directories' => $directories,
    ];
}

/**
 * @param  array{files: array<string, string>, directories: array<int, string>}  $expected
 * @param  array{files: array<string, string>, directories: array<int, string>}  $actual
 */
function assertFrontendTreesMatch(array $expected, array $actual, string $message): void
{
    if (array_keys($expected['files']) !== array_keys($actual['files'])) {
        throw new RuntimeException($message);
    }

    foreach ($expected['files'] as $path => $hash) {
        if (! hash_equals($hash, $actual['files'][$path])) {
            throw new RuntimeException("{$message} Changed file: {$path}");
        }
    }
}

function validateFrontendManifest(string $buildDirectory): void
{
    validateFrontendBuildTree($buildDirectory);
}

function writeFrontendArchiveHash(string $archivePath): void
{
    $archiveHash = hash_file('sha256', $archivePath);

    if (! is_string($archiveHash)) {
        throw new RuntimeException('The frontend release archive could not be hashed.');
    }

    if (file_put_contents(
        releaseProjectPath(FRONTEND_RELEASE_ARCHIVE_HASH),
        "{$archiveHash}  frontend-build.tar.gz\n",
    ) === false) {
        throw new RuntimeException('The frontend release archive hash could not be saved.');
    }
}

function verifyFrontendArchiveHash(string $archivePath): void
{
    $hashPath = releaseProjectPath(FRONTEND_RELEASE_ARCHIVE_HASH);

    if (is_link($hashPath) || ! is_file($hashPath)) {
        throw new RuntimeException('The frontend release archive hash is missing.');
    }

    $storedHash = trim((string) file_get_contents($hashPath));

    if (! preg_match('/^(?<hash>[0-9a-f]{64})  frontend-build\.tar\.gz$/', $storedHash, $matches)) {
        throw new RuntimeException('The frontend release archive hash is invalid.');
    }

    $actualHash = hash_file('sha256', $archivePath);

    if (! is_string($actualHash) || ! hash_equals($matches['hash'], $actualHash)) {
        throw new RuntimeException('The frontend release archive checksum does not match.');
    }
}

function createFrontendRelease(string $sourceCommit): int
{
    validateReleaseSource($sourceCommit);

    $buildDirectory = releaseProjectPath('public/build');
    $deploymentDirectory = releaseProjectPath('deployment');

    if (! is_dir($deploymentDirectory) && ! mkdir($deploymentDirectory, 0775, true) && ! is_dir($deploymentDirectory)) {
        throw new RuntimeException('The deployment directory could not be created.');
    }

    $publicSourcePath = $buildDirectory.DIRECTORY_SEPARATOR.'deployment-source.txt';

    if ((file_exists($publicSourcePath) || is_link($publicSourcePath)) && ! is_file($publicSourcePath) && ! is_link($publicSourcePath)) {
        throw new RuntimeException('The public frontend release source marker is not a removable file.');
    }

    if ((is_file($publicSourcePath) || is_link($publicSourcePath)) && ! unlink($publicSourcePath)) {
        throw new RuntimeException('The public frontend release source marker could not be removed.');
    }

    $currentBuild = validateFrontendBuildTree($buildDirectory);

    file_put_contents(releaseProjectPath(FRONTEND_RELEASE_SOURCE), "{$sourceCommit}\n");

    if (runReleaseCommand([
        PHP_BINARY,
        releaseProjectPath('scripts/source-manifest.php'),
        'write',
        FRONTEND_RELEASE_MANIFEST,
    ]) !== 0) {
        throw new RuntimeException('The deployment source manifest could not be created.');
    }

    $archivePath = releaseProjectPath(FRONTEND_RELEASE_ARCHIVE);

    if (is_file($archivePath) && ! unlink($archivePath)) {
        throw new RuntimeException('The previous frontend release archive could not be removed.');
    }

    if (runReleaseCommand([
        'tar',
        '--format=ustar',
        '-czf',
        $archivePath,
        '-C',
        $buildDirectory,
        '.',
    ]) !== 0) {
        throw new RuntimeException('The frontend release archive could not be created.');
    }

    $archiveTree = inspectFrontendArchive($archivePath);
    $finalBuild = validateFrontendBuildTree($buildDirectory);
    assertFrontendTreesMatch($currentBuild, $finalBuild, 'The frontend build changed while its release was created.');
    assertFrontendTreesMatch($finalBuild, $archiveTree, 'The frontend archive does not match the validated build.');
    writeFrontendArchiveHash($archivePath);

    fwrite(STDOUT, "Frontend release created for {$sourceCommit}.\n");

    return 0;
}

function releaseSourceCommit(?string $expectedSourceCommit = null): string
{
    $sourcePath = releaseProjectPath(FRONTEND_RELEASE_SOURCE);

    if (is_link($sourcePath) || ! is_file($sourcePath)) {
        throw new RuntimeException('The frontend release source marker is missing.');
    }

    $sourceCommit = trim((string) file_get_contents($sourcePath));
    validateReleaseSource($sourceCommit);

    if ($expectedSourceCommit !== null && ! hash_equals($expectedSourceCommit, $sourceCommit)) {
        throw new RuntimeException("The frontend release belongs to {$sourceCommit}, not {$expectedSourceCommit}.");
    }

    return $sourceCommit;
}

function validateExtractionTarget(string $temporaryDirectory): void
{
    $publicDirectory = realpath(releaseProjectPath('public'));
    $resolvedDirectory = realpath($temporaryDirectory);

    if ($publicDirectory === false || $resolvedDirectory === false || is_link($temporaryDirectory)) {
        throw new RuntimeException('The frontend extraction target is invalid.');
    }

    if (realpath(dirname($resolvedDirectory)) !== $publicDirectory) {
        throw new RuntimeException('The frontend extraction target must be directly inside public/.');
    }

    if (! str_starts_with(basename($resolvedDirectory), '.stocks-build.')) {
        throw new RuntimeException('The frontend extraction target name is invalid.');
    }

    $entries = scandir($resolvedDirectory);

    if (! is_array($entries) || array_values(array_diff($entries, ['.', '..'])) !== []) {
        throw new RuntimeException('The frontend extraction target must be empty.');
    }
}

function extractFrontendRelease(?string $targetDirectory = null): string
{
    $archivePath = releaseProjectPath(FRONTEND_RELEASE_ARCHIVE);

    if (! is_file($archivePath)) {
        throw new RuntimeException('The frontend release archive is missing.');
    }

    verifyFrontendArchiveHash($archivePath);
    $archiveTree = inspectFrontendArchive($archivePath);
    $temporaryDirectory = $targetDirectory ?? releaseProjectPath('public/.stocks-release.'.bin2hex(random_bytes(6)));

    if ($targetDirectory === null) {
        if (! mkdir($temporaryDirectory, 0775, true) && ! is_dir($temporaryDirectory)) {
            throw new RuntimeException('The temporary frontend release directory could not be created.');
        }
    } else {
        validateExtractionTarget($temporaryDirectory);
    }

    if (runReleaseCommand(['tar', '-xzf', $archivePath, '-C', $temporaryDirectory]) !== 0) {
        removeReleaseDirectory($temporaryDirectory);

        throw new RuntimeException('The frontend release archive could not be extracted.');
    }

    try {
        $extractedTree = validateFrontendBuildTree($temporaryDirectory);
        assertFrontendTreesMatch(
            $archiveTree,
            $extractedTree,
            'The extracted frontend release does not match the validated archive.',
        );
    } catch (Throwable $throwable) {
        removeReleaseDirectory($temporaryDirectory);

        throw $throwable;
    }

    return $temporaryDirectory;
}

function verifyFrontendRelease(?string $expectedSourceCommit = null): int
{
    if (runReleaseCommand([
        PHP_BINARY,
        releaseProjectPath('scripts/source-manifest.php'),
        'verify',
        FRONTEND_RELEASE_MANIFEST,
    ]) !== 0) {
        throw new RuntimeException('The pulled source and frontend release do not belong together.');
    }

    $sourceCommit = releaseSourceCommit($expectedSourceCommit);
    $temporaryDirectory = extractFrontendRelease();
    removeReleaseDirectory($temporaryDirectory);

    fwrite(STDOUT, "Frontend release verified for {$sourceCommit}.\n");

    return 0;
}

function verifyFrontendRebuild(?string $expectedSourceCommit = null): int
{
    $sourceCommit = releaseSourceCommit($expectedSourceCommit);
    $archivePath = releaseProjectPath(FRONTEND_RELEASE_ARCHIVE);
    verifyFrontendArchiveHash($archivePath);
    $archiveTree = inspectFrontendArchive($archivePath);
    $buildTree = validateFrontendBuildTree(releaseProjectPath('public/build'));
    assertFrontendTreesMatch(
        $archiveTree,
        $buildTree,
        'The committed frontend release does not match the clean frontend rebuild.',
    );

    fwrite(STDOUT, "Frontend release matches the clean rebuild for {$sourceCommit}.\n");

    return 0;
}

function extractFrontendReleaseTo(string $targetDirectory): int
{
    extractFrontendRelease($targetDirectory);
    fwrite(STDOUT, "Frontend release extracted to {$targetDirectory}.\n");

    return 0;
}

function validateInstalledFrontendBuild(): int
{
    $archivePath = releaseProjectPath(FRONTEND_RELEASE_ARCHIVE);
    verifyFrontendArchiveHash($archivePath);
    $archiveTree = inspectFrontendArchive($archivePath);
    $installedTree = validateFrontendBuildTree(releaseProjectPath('public/build'));
    assertFrontendTreesMatch(
        $archiveTree,
        $installedTree,
        'The installed frontend build does not match the validated archive.',
    );
    fwrite(STDOUT, "Installed frontend build validated.\n");

    return 0;
}

function installFrontendRelease(): int
{
    if (runReleaseCommand([
        PHP_BINARY,
        releaseProjectPath('scripts/source-manifest.php'),
        'verify',
        FRONTEND_RELEASE_MANIFEST,
    ]) !== 0) {
        throw new RuntimeException('The pulled source and frontend release do not belong together.');
    }

    $sourceCommit = releaseSourceCommit();
    $temporaryDirectory = extractFrontendRelease();
    $archivePath = releaseProjectPath(FRONTEND_RELEASE_ARCHIVE);
    verifyFrontendArchiveHash($archivePath);
    $archiveTree = inspectFrontendArchive($archivePath);
    $buildDirectory = releaseProjectPath('public/build');
    $backupDirectory = releaseProjectPath('public/.stocks-build-backup.'.bin2hex(random_bytes(6)));

    if (is_dir($buildDirectory) && ! rename($buildDirectory, $backupDirectory)) {
        removeReleaseDirectory($temporaryDirectory);

        throw new RuntimeException('The existing frontend build could not be moved aside.');
    }

    if (! rename($temporaryDirectory, $buildDirectory)) {
        if (is_dir($backupDirectory)) {
            rename($backupDirectory, $buildDirectory);
        }

        removeReleaseDirectory($temporaryDirectory);

        throw new RuntimeException('The verified frontend release could not be installed.');
    }

    try {
        $installedTree = validateFrontendBuildTree($buildDirectory);
        assertFrontendTreesMatch(
            $archiveTree,
            $installedTree,
            'The installed frontend build does not match the validated archive.',
        );
    } catch (Throwable $throwable) {
        removeReleaseDirectory($buildDirectory);

        if (is_dir($backupDirectory)) {
            rename($backupDirectory, $buildDirectory);
        }

        throw $throwable;
    }

    if (is_dir($backupDirectory)) {
        removeReleaseDirectory($backupDirectory);
    }

    fwrite(STDOUT, "Frontend release installed for {$sourceCommit}.\n");

    return 0;
}

function frontendReleaseUsage(): int
{
    fwrite(
        STDERR,
        "Usage: php scripts/frontend-release.php <create SOURCE|verify [SOURCE]|verify-build [SOURCE]|extract-to DIRECTORY|validate-build|install>\n",
    );

    return 2;
}

try {
    $command = $argv[1] ?? null;

    exit(match ($command) {
        'create' => isset($argv[2]) ? createFrontendRelease($argv[2]) : frontendReleaseUsage(),
        'verify' => verifyFrontendRelease($argv[2] ?? null),
        'verify-build' => verifyFrontendRebuild($argv[2] ?? null),
        'extract-to' => isset($argv[2]) ? extractFrontendReleaseTo($argv[2]) : frontendReleaseUsage(),
        'validate-build' => validateInstalledFrontendBuild(),
        'install' => installFrontendRelease(),
        default => frontendReleaseUsage(),
    });
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage()."\n");

    exit(1);
}
