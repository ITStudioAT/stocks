<?php

declare(strict_types=1);

use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Monolog\Handler\RotatingFileHandler;

/**
 * @param  array{inode: int, offset: int}|null  $position
 * @return array{inode: int, offset: int}|null
 */
function streamDevelopmentLog(string $path, ?array $position, bool $starting): ?array
{
    $log = @fopen($path, 'rb');

    if ($log === false) {
        return null;
    }

    try {
        $stat = fstat($log);

        if ($stat === false) {
            return $position;
        }

        $offset = 0;

        if ($starting) {
            $offset = $stat['size'];
        } elseif ($position !== null && $position['inode'] === $stat['ino'] && $position['offset'] <= $stat['size']) {
            $offset = $position['offset'];
        }

        if (fseek($log, $offset) !== 0) {
            return $position;
        }

        $bytesRead = stream_copy_to_stream($log, STDOUT);

        return ['inode' => $stat['ino'], 'offset' => $offset + $bytesRead];
    } finally {
        fclose($log);
    }
}

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->bootstrapWith([LoadEnvironmentVariables::class, LoadConfiguration::class]);

$singleLog = config('logging.channels.single.path');
$dailyLog = config('logging.channels.daily.path');
$positions = [];
$starting = true;

while (true) {
    $paths = [$singleLog, (new RotatingFileHandler($dailyLog))->getUrl()];
    $positions = array_intersect_key($positions, array_flip($paths));

    foreach ($paths as $path) {
        $positions[$path] = streamDevelopmentLog($path, $positions[$path] ?? null, $starting);
    }

    if ($starting) {
        fwrite(STDOUT, "Following Laravel log files.\n");
        $starting = false;
    }

    usleep(250_000);
}
