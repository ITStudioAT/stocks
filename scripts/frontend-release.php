<?php

declare(strict_types=1);

const FRONTEND_RELEASE_ARCHIVE = 'deployment/frontend-build.tar.gz';
const FRONTEND_RELEASE_SOURCE = 'deployment/source-commit';
const FRONTEND_RELEASE_MANIFEST = 'deployment/source-manifest.sha256';

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

/** @param array<int, string> $command */
function releaseCommandOutput(array $command): string
{
    $process = proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        releaseProjectPath(),
    );

    if (! is_resource($process)) {
        throw new RuntimeException('Could not inspect the release commit.');
    }

    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0 || ! is_string($output)) {
        throw new RuntimeException('Could not inspect the release commit: '.trim((string) $error));
    }

    return trim($output);
}

function removeReleaseDirectory(string $directory): void
{
    $projectPublicDirectory = realpath(releaseProjectPath('public'));
    $resolvedParent = realpath(dirname($directory));

    if ($projectPublicDirectory === false || $resolvedParent !== $projectPublicDirectory) {
        throw new RuntimeException("Refusing to remove unexpected release directory: {$directory}");
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

function validateFrontendManifest(string $buildDirectory): void
{
    $manifestPath = $buildDirectory.DIRECTORY_SEPARATOR.'manifest.json';

    if (! is_file($manifestPath)) {
        throw new RuntimeException('The frontend release does not contain manifest.json.');
    }

    $manifest = file_get_contents($manifestPath);

    if ($manifest === false) {
        throw new RuntimeException('The frontend manifest could not be read.');
    }

    json_decode($manifest, true, flags: JSON_THROW_ON_ERROR);
}

function createFrontendRelease(string $sourceCommit): int
{
    validateReleaseSource($sourceCommit);

    $buildDirectory = releaseProjectPath('public/build');
    validateFrontendManifest($buildDirectory);

    $deploymentDirectory = releaseProjectPath('deployment');

    if (! is_dir($deploymentDirectory) && ! mkdir($deploymentDirectory, 0775, true) && ! is_dir($deploymentDirectory)) {
        throw new RuntimeException('The deployment directory could not be created.');
    }

    file_put_contents($buildDirectory.DIRECTORY_SEPARATOR.'deployment-source.txt', "{$sourceCommit}\n");
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
        '-czf',
        $archivePath,
        '-C',
        $buildDirectory,
        '.',
    ]) !== 0) {
        throw new RuntimeException('The frontend release archive could not be created.');
    }

    fwrite(STDOUT, "Frontend release created for {$sourceCommit}.\n");

    return 0;
}

function releaseSourceCommit(?string $expectedSourceCommit = null): string
{
    $sourcePath = releaseProjectPath(FRONTEND_RELEASE_SOURCE);

    if (! is_file($sourcePath)) {
        throw new RuntimeException('The frontend release source marker is missing.');
    }

    $sourceCommit = trim((string) file_get_contents($sourcePath));
    validateReleaseSource($sourceCommit);

    if ($expectedSourceCommit === null) {
        $expectedSourceCommit = releaseCommandOutput(['git', 'rev-parse', 'HEAD^']);
        validateReleaseSource($expectedSourceCommit);
    }

    if (! hash_equals($expectedSourceCommit, $sourceCommit)) {
        throw new RuntimeException("The frontend release belongs to {$sourceCommit}, not {$expectedSourceCommit}.");
    }

    return $sourceCommit;
}

function extractFrontendRelease(string $sourceCommit): string
{
    $archivePath = releaseProjectPath(FRONTEND_RELEASE_ARCHIVE);

    if (! is_file($archivePath)) {
        throw new RuntimeException('The frontend release archive is missing.');
    }

    $temporaryDirectory = releaseProjectPath('public/.stocks-release.'.bin2hex(random_bytes(6)));

    if (! mkdir($temporaryDirectory, 0775, true) && ! is_dir($temporaryDirectory)) {
        throw new RuntimeException('The temporary frontend release directory could not be created.');
    }

    if (runReleaseCommand(['tar', '-xzf', $archivePath, '-C', $temporaryDirectory]) !== 0) {
        removeReleaseDirectory($temporaryDirectory);

        throw new RuntimeException('The frontend release archive could not be extracted.');
    }

    try {
        validateFrontendManifest($temporaryDirectory);

        $innerSourcePath = $temporaryDirectory.DIRECTORY_SEPARATOR.'deployment-source.txt';
        $innerSourceCommit = is_file($innerSourcePath)
            ? trim((string) file_get_contents($innerSourcePath))
            : '';

        if (! hash_equals($sourceCommit, $innerSourceCommit)) {
            throw new RuntimeException('The frontend archive source marker does not match its release.');
        }
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
    $temporaryDirectory = extractFrontendRelease($sourceCommit);
    removeReleaseDirectory($temporaryDirectory);

    fwrite(STDOUT, "Frontend release verified for {$sourceCommit}.\n");

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
    $temporaryDirectory = extractFrontendRelease($sourceCommit);
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

    if (is_dir($backupDirectory)) {
        removeReleaseDirectory($backupDirectory);
    }

    fwrite(STDOUT, "Frontend release installed for {$sourceCommit}.\n");

    return 0;
}

function frontendReleaseUsage(): int
{
    fwrite(STDERR, "Usage: php scripts/frontend-release.php <create SOURCE|verify [SOURCE]|install>\n");

    return 2;
}

try {
    $command = $argv[1] ?? null;

    exit(match ($command) {
        'create' => isset($argv[2]) ? createFrontendRelease($argv[2]) : frontendReleaseUsage(),
        'verify' => verifyFrontendRelease($argv[2] ?? null),
        'install' => installFrontendRelease(),
        default => frontendReleaseUsage(),
    });
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage()."\n");

    exit(1);
}
