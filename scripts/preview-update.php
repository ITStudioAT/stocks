<?php

declare(strict_types=1);

use App\Services\PreviewBackgroundState;
use App\Services\PreviewFileSwap;
use App\Services\PreviewReleaseBundle;
use App\Services\PreviewReleaseUpdate;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Process\Process;

try {
    if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux' || PHP_VERSION_ID < 80401
        || ! extension_loaded('zip') || ! function_exists('posix_geteuid')) {
        throw new RuntimeException('Preview update requires Linux CLI PHP >= 8.4.1 with ZIP and POSIX.');
    }
    $mode = $argv[1] ?? '';
    if (! in_array($mode, ['inspect', 'apply', 'recover'], true)
        || ! in_array(count($argv), $mode === 'recover' ? [5] : [8], true)
        || ! ctype_digit($argv[3] ?? '')) {
        throw new RuntimeException('Usage: preview-update.php inspect|apply ROOT OWNER BUNDLE SHA256 TARGET_JSON OLD_COMMIT, or recover ROOT OWNER TARGET_JSON');
    }
    [, , $root, $owner] = $argv;
    $owner = (int) $owner;
    $targetPath = $mode === 'recover' ? $argv[4] : $argv[6];
    if ($root !== '/home/1486907.cloudwaysapps.com/hbnucgvzmy/public_html'
        || $owner !== 1013 || $owner !== posix_geteuid()
        || ! is_file($targetPath) || is_link($targetPath) || filesize($targetPath) > 16_384) {
        throw new RuntimeException('Preview target identity or owner mismatch.');
    }
    $target = json_decode((string) file_get_contents($targetPath), true, 16, JSON_THROW_ON_ERROR);
    if (($target['canonicalTargetRoot'] ?? null) !== $root || ($target['targetAppId'] ?? null) !== '6690486'
        || ($target['sourceAppId'] ?? null) !== '6468818'
        || ($target['targetDatabase'] ?? null) !== 'hbnucgvzmy'
        || ($target['sourceDatabase'] ?? null) !== 'cfbckymfgk') {
        throw new RuntimeException('Preview target metadata mismatch.');
    }
    require $root.'/vendor/autoload.php';
    if ($mode !== 'recover') {
        $application = require $root.'/bootstrap/app.php';
        $application->make(Kernel::class)->bootstrap();
    }
    $services = is_file(__DIR__.'/PreviewReleaseUpdate.php') ? __DIR__ : dirname(__DIR__).'/app/Services';
    foreach (['PreviewFileSwap', 'PreviewReleaseBundle', 'PreviewReleaseUpdate'] as $service) {
        require_once $services.'/'.$service.'.php';
    }
    $update = new PreviewReleaseUpdate(
        $target,
        new PreviewReleaseBundle,
        new PreviewFileSwap,
        $mode === 'recover' ? null : fn (): bool => $application->make(PreviewBackgroundState::class)->enabled(),
    );
    if ($mode === 'recover') {
        $update->recover($root, $owner);
        fwrite(STDOUT, "Preview update recovery completed; database unchanged.\n");
        exit(0);
    }
    [, , , , $archive, $digest, , $oldCommit] = $argv;
    if (! preg_match('/^[a-f0-9]{64}$/D', $digest) || ! preg_match('/^[a-f0-9]{40}$/D', $oldCommit)
        || dirname($archive) !== dirname($targetPath) || is_link($archive)) {
        throw new RuntimeException('Preview update package identity mismatch.');
    }
    $result = $mode === 'inspect'
        ? $update->inspect($archive, $digest, $root, $owner, $oldCommit)
        : $update->apply($archive, $digest, $root, $owner, $oldCommit, function (string $path): void {
            $process = new Process([PHP_BINARY, $path.'/artisan', 'preview:check', '--no-interaction'], $path);
            $process->setTimeout(60);
            $process->run();
            if (! $process->isSuccessful()) {
                throw new RuntimeException('Updated preview isolation check failed.');
            }
        });
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR)."\n");
} catch (Throwable $exception) {
    $reason = $exception::class === RuntimeException::class ? $exception->getMessage() : $exception::class;
    fwrite(STDERR, 'Preview update stopped: '.$reason.'. Preserve the private update package and state for recovery. Database unchanged.'."\n");
    exit(1);
}
