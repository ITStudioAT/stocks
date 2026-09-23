<?php

declare(strict_types=1);
use App\Services\PreviewReleaseBundle;

require dirname(__DIR__).'/app/Services/PreviewReleaseBundle.php';

try {
    if (count($argv) !== 4) {
        throw new RuntimeException('Usage: php scripts/preview-bundle.php SOURCE_DIRECTORY DESTINATION_ZIP SOURCE_COMMIT');
    }
    $digest = (new PreviewReleaseBundle)->create($argv[1], $argv[2], $argv[3]);
    (new PreviewReleaseBundle)->inspect($argv[2], $digest);
    fwrite(STDOUT, $digest."\n");
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}
