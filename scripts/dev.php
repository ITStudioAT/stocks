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
    ];

    if ($supportsPail) {
        $processes[] = ['logs', '#fb7185', 'php artisan pail --timeout=0'];
    }

    $processes[] = ['vite', '#fdba74', 'npm run dev'];

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

if (! $supportsPail && ! $isDryRun) {
    $reason = PHP_OS_FAMILY === 'Windows'
        ? 'Pail is unavailable on native Windows.'
        : 'Pail requires the PCNTL extension.';

    fwrite(STDOUT, "{$reason} Continuing without live log output.\n");
}

if ($isDryRun) {
    fwrite(STDOUT, displayDevelopmentCommand($command)."\n");

    exit(0);
}

exit(runDevelopmentCommand($command));
