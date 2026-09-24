<?php

declare(strict_types=1);

const SOURCE_MANIFEST_PATHS = [
    'app',
    'artisan',
    'bootstrap',
    'composer.json',
    'composer.lock',
    'config',
    'database',
    'package-lock.json',
    'package.json',
    'public',
    'resources',
    'routes',
    'scripts',
    'vite.config.js',
];

const SOURCE_MANIFEST_EXCLUDED_PATHS = [
    'bootstrap/cache',
    'database/database.sqlite',
    'public/build',
    'public/hot',
    'public/storage',
];

const SOURCE_MANIFEST_PRUNE_REQUIRED_PATHS = [
    'artisan',
    'composer.json',
    'composer.lock',
    'scripts/deploy_cloudways.sh',
    'scripts/frontend-release.php',
    'scripts/source-manifest.php',
];

function projectPath(string $relativePath = ''): string
{
    $projectDirectory = dirname(__DIR__);

    return $relativePath === ''
        ? $projectDirectory
        : $projectDirectory.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
}

function isExcludedSourcePath(string $relativePath): bool
{
    foreach (SOURCE_MANIFEST_EXCLUDED_PATHS as $excludedPath) {
        if ($relativePath === $excludedPath || str_starts_with($relativePath, "{$excludedPath}/")) {
            return true;
        }
    }

    return str_starts_with($relativePath, 'database/database.sqlite');
}

function isIncludedSourcePath(string $relativePath): bool
{
    if ($relativePath === ''
        || str_starts_with($relativePath, '/')
        || str_contains($relativePath, '\\')
        || preg_match('#(^|/)\.\.?(/|$)#', $relativePath)) {
        return false;
    }

    if (isExcludedSourcePath($relativePath)) {
        return false;
    }

    foreach (SOURCE_MANIFEST_PATHS as $includedPath) {
        if ($relativePath === $includedPath || str_starts_with($relativePath, "{$includedPath}/")) {
            return true;
        }
    }

    return false;
}

/** @return array<int, string> */
function trackedSourceFiles(): array
{
    $process = proc_open(
        ['git', 'ls-files', '-z', '--', ...SOURCE_MANIFEST_PATHS],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        projectPath(),
    );

    if (! is_resource($process)) {
        throw new RuntimeException('Could not list the Git-tracked deployment source.');
    }

    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0 || $output === false) {
        throw new RuntimeException('Could not list the Git-tracked deployment source: '.trim((string) $error));
    }

    $files = array_values(array_filter(
        explode("\0", rtrim($output, "\0")),
        fn (string $relativePath): bool => $relativePath !== '' && isIncludedSourcePath($relativePath),
    ));

    sort($files, SORT_STRING);

    return array_values(array_unique($files));
}

/**
 * @param  array<int, string>  $files
 */
function collectDeployedSourceFiles(string $relativeDirectory, array &$files): void
{
    $absoluteDirectory = projectPath($relativeDirectory);

    if (! is_dir($absoluteDirectory) || is_link($absoluteDirectory)) {
        if (is_link($absoluteDirectory)) {
            $files[] = $relativeDirectory;
        }

        return;
    }

    foreach (new FilesystemIterator($absoluteDirectory, FilesystemIterator::SKIP_DOTS) as $item) {
        $relativePath = $relativeDirectory.'/'.$item->getFilename();

        if (isExcludedSourcePath($relativePath)) {
            continue;
        }

        if ($item->isLink() || ! $item->isDir()) {
            $files[] = $relativePath;

            continue;
        }

        collectDeployedSourceFiles($relativePath, $files);
    }
}

/** @return array<int, string> */
function deployedSourceFiles(): array
{
    $files = [];

    foreach (SOURCE_MANIFEST_PATHS as $includedPath) {
        if (isExcludedSourcePath($includedPath)) {
            continue;
        }

        $absolutePath = projectPath($includedPath);

        if (is_link($absolutePath) || is_file($absolutePath)) {
            $files[] = $includedPath;

            continue;
        }

        collectDeployedSourceFiles($includedPath, $files);
    }

    sort($files, SORT_STRING);

    return array_values(array_unique($files));
}

function sourceFileHash(string $relativePath): string
{
    $absolutePath = projectPath($relativePath);

    if (! is_file($absolutePath) || is_link($absolutePath)) {
        throw new RuntimeException("Source file is missing: {$relativePath}");
    }

    $contents = file_get_contents($absolutePath);

    if ($contents === false) {
        throw new RuntimeException("Could not hash source file: {$relativePath}");
    }

    if (! str_contains($contents, "\0")) {
        $contents = str_replace(["\r\n", "\r"], "\n", $contents);
    }

    return hash('sha256', $contents);
}

/** @param array<int, string> $relativePaths */
function buildSourceManifest(array $relativePaths): string
{
    $lines = [];

    foreach ($relativePaths as $relativePath) {
        if (str_contains($relativePath, "\n") || str_contains($relativePath, "\r")) {
            throw new RuntimeException("Source path contains a line break: {$relativePath}");
        }

        $lines[] = sourceFileHash($relativePath)."  {$relativePath}";
    }

    return implode("\n", $lines)."\n";
}

/** @return array<string, string> */
function parseSourceManifest(string $manifest): array
{
    $entries = [];

    foreach (preg_split('/\R/', trim($manifest)) ?: [] as $line) {
        if ($line === '') {
            continue;
        }

        if (! preg_match('/^([0-9a-f]{64})  (.+)$/', $line, $matches)) {
            throw new RuntimeException('The source manifest contains an invalid line.');
        }

        if (! isIncludedSourcePath($matches[2])) {
            throw new RuntimeException("The source manifest contains an unsafe path: {$matches[2]}");
        }

        if (array_key_exists($matches[2], $entries)) {
            throw new RuntimeException("The source manifest contains a duplicate path: {$matches[2]}");
        }

        $entries[$matches[2]] = $matches[1];
    }

    return $entries;
}

/**
 * @param  array<string, string>  $manifestEntries
 * @param  array<int, string>  $deployedFiles
 * @return array{unlisted: array<int, string>, blocking: array<int, string>}
 */
function sourceManifestDifferences(array $manifestEntries, array $deployedFiles): array
{
    $unlisted = [];
    $blocking = [];

    foreach ($deployedFiles as $relativePath) {
        if (! array_key_exists($relativePath, $manifestEntries)) {
            $unlisted[] = $relativePath;
        }
    }

    foreach ($manifestEntries as $relativePath => $expectedHash) {
        if (! in_array($relativePath, $deployedFiles, true)) {
            $blocking[] = "missing: {$relativePath}";

            continue;
        }

        if (! is_file(projectPath($relativePath)) || is_link(projectPath($relativePath))) {
            $blocking[] = "missing: {$relativePath}";

            continue;
        }

        if (! hash_equals($expectedHash, sourceFileHash($relativePath))) {
            $blocking[] = "changed: {$relativePath}";
        }
    }

    return [
        'unlisted' => $unlisted,
        'blocking' => $blocking,
    ];
}

/** @param array<string, string> $manifestEntries */
function validatePrunableManifest(array $manifestEntries): void
{
    foreach (SOURCE_MANIFEST_PRUNE_REQUIRED_PATHS as $requiredPath) {
        if (! array_key_exists($requiredPath, $manifestEntries)) {
            throw new RuntimeException("The source manifest cannot safely prune files because it omits: {$requiredPath}");
        }
    }
}

/** @param array<string, string> $manifestEntries */
function isPrunableSourcePath(string $relativePath, array $manifestEntries): bool
{
    if (! isIncludedSourcePath($relativePath) || array_key_exists($relativePath, $manifestEntries)) {
        return false;
    }

    $absolutePath = projectPath($relativePath);

    return is_link($absolutePath) || is_file($absolutePath);
}

/** @param array<int, string> $differences */
function describeManifestMismatch(array $differences): void
{
    foreach (array_slice($differences, 0, 10) as $difference) {
        fwrite(STDERR, "  - {$difference}\n");
    }

    if (count($differences) > 10) {
        fwrite(STDERR, '  - and '.(count($differences) - 10)." more difference(s)\n");
    }
}

function writeSourceManifest(string $manifestPath): int
{
    $absoluteManifestPath = projectPath($manifestPath);
    $directory = dirname($absoluteManifestPath);

    if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
        fwrite(STDERR, "Could not create source manifest directory: {$directory}\n");

        return 1;
    }

    if (file_put_contents($absoluteManifestPath, buildSourceManifest(trackedSourceFiles())) === false) {
        fwrite(STDERR, "Could not write source manifest: {$absoluteManifestPath}\n");

        return 1;
    }

    fwrite(STDOUT, "Source manifest written to {$manifestPath}.\n");

    return 0;
}

function verifySourceManifest(string $manifestPath): int
{
    $absoluteManifestPath = projectPath($manifestPath);

    if (! is_file($absoluteManifestPath)) {
        fwrite(STDERR, "Source manifest is missing: {$manifestPath}\n");

        return 1;
    }

    $expected = file_get_contents($absoluteManifestPath);

    if ($expected === false) {
        fwrite(STDERR, "Could not read source manifest: {$manifestPath}\n");

        return 1;
    }

    $manifestEntries = parseSourceManifest($expected);
    $manifestDifferences = sourceManifestDifferences($manifestEntries, deployedSourceFiles());
    $differences = [
        ...array_map(
            fn (string $relativePath): string => "unlisted: {$relativePath}",
            $manifestDifferences['unlisted'],
        ),
        ...$manifestDifferences['blocking'],
    ];

    if ($differences !== []) {
        fwrite(STDERR, "The pulled source does not match its deployment release:\n");
        describeManifestMismatch($differences);
        fwrite(STDERR, "Pull main again after gitsave on main has completed.\n");
        fwrite(STDERR, "If only unlisted files remain, Cloudways preserved removed source files; delete exactly those listed files before retrying.\n");

        return 1;
    }

    fwrite(STDOUT, "Deployment source manifest verified.\n");

    return 0;
}

function pruneUnlistedSourceFiles(string $manifestPath): int
{
    $absoluteManifestPath = projectPath($manifestPath);

    if (! is_file($absoluteManifestPath)) {
        fwrite(STDERR, "Source manifest is missing: {$manifestPath}\n");

        return 1;
    }

    $manifest = file_get_contents($absoluteManifestPath);

    if ($manifest === false) {
        fwrite(STDERR, "Could not read source manifest: {$manifestPath}\n");

        return 1;
    }

    $manifestEntries = parseSourceManifest($manifest);
    validatePrunableManifest($manifestEntries);
    $manifestDifferences = sourceManifestDifferences($manifestEntries, deployedSourceFiles());

    if ($manifestDifferences['blocking'] !== []) {
        fwrite(STDERR, "Refusing to prune stale source files because expected release files differ:\n");
        describeManifestMismatch($manifestDifferences['blocking']);

        return 1;
    }

    foreach ($manifestDifferences['unlisted'] as $relativePath) {
        if (! isPrunableSourcePath($relativePath, $manifestEntries)) {
            fwrite(STDERR, "Refusing to prune an unsafe or changed source path: {$relativePath}\n");

            return 1;
        }
    }

    foreach ($manifestDifferences['unlisted'] as $relativePath) {
        if (! isPrunableSourcePath($relativePath, $manifestEntries)
            || ! unlink(projectPath($relativePath))) {
            fwrite(STDERR, "Could not safely prune stale source file: {$relativePath}\n");

            return 1;
        }

        fwrite(STDOUT, "Pruned stale deployment source file: {$relativePath}\n");
    }

    if ($manifestDifferences['unlisted'] === []) {
        fwrite(STDOUT, "No stale deployment source files found.\n");
    }

    return verifySourceManifest($manifestPath);
}

function sourceManifestUsage(): int
{
    fwrite(STDERR, "Usage: php scripts/source-manifest.php <write|verify|prune-unlisted> <manifest-path>\n");

    return 2;
}

$command = $argv[1] ?? null;
$manifestPath = $argv[2] ?? null;

if (! is_string($manifestPath) || $manifestPath === '' || str_starts_with($manifestPath, '..')) {
    exit(sourceManifestUsage());
}

try {
    exit(match ($command) {
        'write' => writeSourceManifest($manifestPath),
        'verify' => verifySourceManifest($manifestPath),
        'prune-unlisted' => pruneUnlistedSourceFiles($manifestPath),
        default => sourceManifestUsage(),
    });
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage()."\n");

    exit(1);
}
