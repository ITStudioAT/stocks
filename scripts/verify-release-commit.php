<?php

declare(strict_types=1);

const DEPLOYMENT_RELEASE_FILES = [
    'deployment/frontend-build.sha256',
    'deployment/frontend-build.tar.gz',
    'deployment/source-commit',
    'deployment/source-manifest.sha256',
];

function releaseCommitProjectPath(): string
{
    return dirname(__DIR__);
}

/**
 * @param  array<int, string>  $arguments
 * @return array{output: string, error: string, exit_code: int}
 */
function runReleaseCommitGit(array $arguments): array
{
    $process = proc_open(
        ['git', ...$arguments],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        releaseCommitProjectPath(),
    );

    if (! is_resource($process)) {
        throw new RuntimeException('Could not start Git to verify the deployment release commit.');
    }

    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [
        'output' => is_string($output) ? $output : '',
        'error' => is_string($error) ? $error : '',
        'exit_code' => $exitCode,
    ];
}

/** @param array<int, string> $arguments */
function releaseCommitGitOutput(array $arguments, string $failureMessage): string
{
    $result = runReleaseCommitGit($arguments);

    if ($result['exit_code'] !== 0) {
        $details = trim($result['error']);

        throw new RuntimeException($details === '' ? $failureMessage : "{$failureMessage} {$details}");
    }

    return $result['output'];
}

function deploymentReleaseParent(): string
{
    $revision = trim(releaseCommitGitOutput(
        ['rev-list', '--parents', '-n', '1', 'HEAD'],
        'Could not inspect the deployment release commit.',
    ));
    $revisions = preg_split('/\s+/', $revision) ?: [];

    if (count($revisions) !== 2) {
        throw new RuntimeException('The deployment release must be a non-merge commit with exactly one parent.');
    }

    $parent = $revisions[1];

    if (preg_match('/^[0-9a-f]{40,64}$/', $parent) !== 1) {
        throw new RuntimeException('The deployment release parent commit is invalid.');
    }

    return $parent;
}

/** @return array<int, string> */
function deploymentReleaseChangedFiles(string $parent): array
{
    $output = releaseCommitGitOutput(
        ['diff', '--name-only', '--no-renames', '-z', $parent, 'HEAD', '--'],
        'Could not inspect the files changed by the deployment release commit.',
    );
    $files = array_values(array_filter(
        explode("\0", rtrim($output, "\0")),
        fn (string $path): bool => $path !== '',
    ));
    sort($files, SORT_STRING);

    return $files;
}

/** @param array<int, string> $changedFiles */
function assertDeploymentReleaseFiles(array $changedFiles): void
{
    $expectedFiles = DEPLOYMENT_RELEASE_FILES;
    sort($expectedFiles, SORT_STRING);

    if ($changedFiles === $expectedFiles) {
        return;
    }

    $differences = [];

    foreach (array_diff($expectedFiles, $changedFiles) as $path) {
        $differences[] = "missing: {$path}";
    }

    foreach (array_diff($changedFiles, $expectedFiles) as $path) {
        $differences[] = "unexpected: {$path}";
    }

    throw new RuntimeException(
        "The deployment release commit must change exactly the four release files:\n - ".implode("\n - ", $differences),
    );
}

function assertDeploymentReleaseFilesAreRegular(): void
{
    $output = releaseCommitGitOutput(
        ['ls-tree', '-z', '--full-tree', 'HEAD', '--', ...DEPLOYMENT_RELEASE_FILES],
        'Could not inspect the deployment release files.',
    );
    $entries = array_values(array_filter(explode("\0", rtrim($output, "\0"))));
    $verifiedPaths = [];

    foreach ($entries as $entry) {
        [$metadata, $path] = array_pad(explode("\t", $entry, 2), 2, null);
        $metadataParts = is_string($metadata) ? preg_split('/\s+/', $metadata) : false;

        if (! is_string($path)
            || ! is_array($metadataParts)
            || count($metadataParts) !== 3
            || $metadataParts[0] !== '100644'
            || $metadataParts[1] !== 'blob') {
            throw new RuntimeException('Every deployment release file must be a regular non-executable Git blob.');
        }

        $verifiedPaths[] = $path;
    }

    sort($verifiedPaths, SORT_STRING);
    $expectedFiles = DEPLOYMENT_RELEASE_FILES;
    sort($expectedFiles, SORT_STRING);

    if ($verifiedPaths !== $expectedFiles) {
        throw new RuntimeException('Every deployment release file must exist in the deployment release commit.');
    }
}

function assertDeploymentReleaseSourceMatchesParent(string $parent): void
{
    $sourceCommit = trim(releaseCommitGitOutput(
        ['show', 'HEAD:deployment/source-commit'],
        'Could not read the deployment release source commit.',
    ));

    if (! hash_equals($parent, $sourceCommit)) {
        throw new RuntimeException("The deployment release source marker must equal its parent commit {$parent}.");
    }
}

function verifyDeploymentReleaseCommit(): int
{
    $parent = deploymentReleaseParent();

    assertDeploymentReleaseFiles(deploymentReleaseChangedFiles($parent));
    assertDeploymentReleaseFilesAreRegular();
    assertDeploymentReleaseSourceMatchesParent($parent);

    fwrite(STDOUT, "Deployment release commit verified for source {$parent}.\n");

    return 0;
}

try {
    exit(verifyDeploymentReleaseCommit());
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage()."\n");

    exit(1);
}
