<?php

declare(strict_types=1);

/**
 * @return array{
 *     commands: array<int, string>,
 *     names: array<int, string>,
 *     colors: array<int, string>,
 * }
 */
function developmentProcesses(bool $supportsPail): array
{
    $processes = [
        ['server', '#93c5fd', 'php artisan serve'],
        ['queue', '#c4b5fd', 'php artisan queue:listen --tries=1 --timeout=0'],
        ['scheduler', '#86efac', 'php artisan schedule:work'],
        ['logs', '#fb7185', $supportsPail ? 'php artisan pail --timeout=0' : 'php scripts/dev-logs.php'],
        ['vite', '#fdba74', 'npx vite'],
    ];

    return [
        'commands' => array_column($processes, 2),
        'names' => array_column($processes, 0),
        'colors' => array_column($processes, 1),
    ];
}

/** @param array<int, string> $arguments */
function simulatedPailSupport(array $arguments): ?bool
{
    foreach ($arguments as $argument) {
        if ($argument === '--dry-run=windows') {
            return false;
        }

        if ($argument === '--dry-run=unix') {
            return true;
        }
    }

    return null;
}

/** @param array<int, string> $command */
function displayDevelopmentCommand(array $command): string
{
    return implode(' ', array_map(
        fn (string $argument): string => str_contains($argument, ' ') ? '"'.$argument.'"' : $argument,
        $command,
    ));
}

/** @param array<int, string> $command */
function windowsDevelopmentCommand(array $command): string
{
    $executable = $command[0];
    $pathDirectories = explode(PATH_SEPARATOR, getenv('PATH') ?: '');
    $extensions = explode(';', getenv('PATHEXT') ?: '.COM;.EXE;.BAT;.CMD');

    foreach ($pathDirectories as $pathDirectory) {
        $pathDirectory = trim($pathDirectory, " \t\n\r\0\x0B\"");

        foreach ($extensions as $extension) {
            $candidate = $pathDirectory.DIRECTORY_SEPARATOR.$executable.strtolower($extension);

            if (is_file($candidate)) {
                $executable = $candidate;
                break 2;
            }
        }
    }

    $command[0] = $executable;

    return 'call '.implode(' ', array_map(
        fn (string $argument): string => '"'.str_replace(['%', '"'], ['%%', '""'], $argument).'"',
        $command,
    ));
}

/** @param array<int, string> $command */
function runDevelopmentCommand(array $command): int
{
    $executableCommand = PHP_OS_FAMILY === 'Windows'
        ? windowsDevelopmentCommand($command)
        : $command;

    $process = proc_open($executableCommand, [STDIN, STDOUT, STDERR], $pipes, dirname(__DIR__));

    if (! is_resource($process)) {
        fwrite(STDERR, "Could not start the development processes.\n");

        return 1;
    }

    return proc_close($process);
}

$simulatedPailSupport = simulatedPailSupport($argv);
$isDryRun = $simulatedPailSupport !== null || in_array('--dry-run', $argv, true);
$supportsPail = $simulatedPailSupport
    ?? (PHP_OS_FAMILY !== 'Windows' && function_exists('pcntl_fork'));
$processes = developmentProcesses($supportsPail);
$command = [
    'npx',
    'concurrently',
    '-c',
    implode(',', $processes['colors']),
    ...$processes['commands'],
    '--names='.implode(',', $processes['names']),
    '--kill-others',
];

if ($isDryRun) {
    fwrite(STDOUT, "Preparation: PHP and Node dependencies, then safe local database migrations.\n");
    fwrite(STDOUT, displayDevelopmentCommand($command)."\n");

    exit(0);
}

$preparation = [PHP_BINARY, __DIR__.'/update.php', '--target=local', '--prepare', '--development'];
$preparationExitCode = runDevelopmentCommand($preparation);
if ($preparationExitCode !== 0) {
    fwrite(STDERR, "Dependency preparation failed; development processes were not started.\n");

    exit($preparationExitCode);
}

$migrationExitCode = runDevelopmentCommand([PHP_BINARY, dirname(__DIR__).'/artisan', 'app:prepare-local-development', '--no-interaction']);
if ($migrationExitCode !== 0) {
    fwrite(STDERR, "Local database preparation failed; development processes were not started.\n");

    exit($migrationExitCode);
}

if (in_array('--prepare-only', $argv, true)) {
    fwrite(STDOUT, "Development preparation completed.\n");

    exit(0);
}

$vitePort = (int) (getenv('VITE_DEV_SERVER_PORT') ?: 5173);
foreach (['127.0.0.1', '[::1]'] as $viteHost) {
    $viteSocket = @fsockopen($viteHost, $vitePort, $errorCode, $errorMessage, 1);
    if (is_resource($viteSocket)) {
        fclose($viteSocket);
        fwrite(STDERR, "A development server is already listening on port {$vitePort}; it was left running.\n");

        exit(1);
    }
}

exit(runDevelopmentCommand($command));
