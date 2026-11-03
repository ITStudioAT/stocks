<?php

declare(strict_types=1);

namespace Stocks\DeploymentStatus;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

function readStatus(string $frameworkDirectory): string
{
    $path = "{$frameworkDirectory}/deployment-status.json";
    $contents = is_file($path) ? @file_get_contents($path) : false;
    $status = $contents === false ? null : json_decode($contents, true);
    $state = $status['state'] ?? null;

    if (is_file("{$frameworkDirectory}/down")) {
        return $state === 'failed' ? 'failed' : 'maintenance';
    }

    if ($state === 'scheduled' && is_int($status['expiresAt'] ?? null) && $status['expiresAt'] > time()) {
        return 'scheduled';
    }

    return $state === 'completed' ? 'completed' : 'idle';
}

function writeStatus(string $frameworkDirectory, string $state, int $noticeSeconds = 0): void
{
    if (! in_array($state, ['idle', 'scheduled', 'maintenance', 'failed', 'completed'], true)) {
        throw new InvalidArgumentException('Unknown deployment status.');
    }

    $path = "{$frameworkDirectory}/deployment-status.json";
    $temporaryPath = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
    $contents = json_encode([
        'state' => $state,
        'expiresAt' => $state === 'scheduled' ? time() + $noticeSeconds + 30 : null,
    ], JSON_THROW_ON_ERROR)."\n";

    try {
        if (file_put_contents($temporaryPath, $contents) !== strlen($contents) || ! rename($temporaryPath, $path)) {
            throw new RuntimeException('Could not publish deployment status.');
        }
    } finally {
        if (is_file($temporaryPath)) {
            unlink($temporaryPath);
        }
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        writeStatus(dirname(__DIR__).'/storage/framework', $argv[1] ?? '', (int) ($argv[2] ?? 0));
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage()."\n");
        exit(1);
    }
}
