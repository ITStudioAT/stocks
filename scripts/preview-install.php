<?php

declare(strict_types=1);
use App\Services\PreviewDatabaseGuard;
use App\Services\PreviewInstallation;
use App\Services\PreviewReleaseBundle;

/** Upload this launcher and its four services only to public_html/.stocks-preview-private/uploads. */
$serviceDirectory = is_file(__DIR__.'/PreviewInstallation.php') ? __DIR__ : dirname(__DIR__).'/app/Services';
foreach (['PreviewFileSwap', 'PreviewInstallation', 'PreviewReleaseBundle', 'PreviewDatabaseGuard'] as $service) {
    require $serviceDirectory.'/'.$service.'.php';
}

try {
    if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux' || PHP_VERSION_ID < 80401
        || ! extension_loaded('zip') || ! extension_loaded('sodium') || ! extension_loaded('pdo_mysql')
        || ! function_exists('posix_geteuid')) {
        throw new RuntimeException('Preview installer requires Linux CLI PHP >= 8.4.1 with zip, sodium, PDO MySQL and POSIX.');
    }
    if (count($argv) !== 7 || ! in_array($argv[1], ['inspect', 'activate', 'restore-template', 'recover-files'], true) || ! ctype_digit($argv[3])) {
        throw new RuntimeException('Usage: php preview-install.php inspect|activate|restore-template|recover-files CANONICAL_ROOT OWNER_UID BUNDLE_ZIP TRUSTED_SHA256 TARGET_JSON');
    }
    [, $mode, $root, $owner, $archive, $digest, $targetPath] = $argv;
    $target = json_decode((string) file_get_contents($targetPath), true, 16, JSON_THROW_ON_ERROR);
    if (($target['serverId'] ?? null) !== '1486907' || ($target['targetAppId'] ?? null) !== '6690486'
        || ($target['sourceAppId'] ?? null) !== '6468818'
        || ($target['targetDatabase'] ?? null) !== 'hbnucgvzmy' || ($target['targetDatabaseUser'] ?? null) !== 'hbnucgvzmy'
        || ($target['sourceDatabase'] ?? null) !== 'cfbckymfgk' || ($target['sourceDatabaseUser'] ?? null) !== 'cfbckymfgk'
        || $root !== '/home/1486907.cloudwaysapps.com/hbnucgvzmy/public_html'
        || ($target['targetFolder'] ?? null) !== 'hbnucgvzmy' || (int) $owner !== posix_geteuid()) {
        throw new RuntimeException('The installer only accepts the verified Stocks-Feature application and its own Unix owner.');
    }
    $bundles = new PreviewReleaseBundle;
    $installation = new PreviewInstallation($target, $bundles, new PreviewDatabaseGuard);
    $manifest = $bundles->inspect($archive, $digest);
    if ($mode === 'recover-files') {
        $installation->recoverFiles($root, (int) $owner, $manifest['commit']);
        fwrite(STDOUT, "Preview file recovery completed; database unchanged.\n");
        exit(0);
    }
    if ($mode === 'restore-template') {
        $preserved = $installation->restoreTemplate($root, (int) $owner, $manifest['commit']);
        fwrite(STDOUT, json_encode(['restored' => 'original template', 'preserved_preview' => $preserved, 'database' => 'unchanged'], JSON_THROW_ON_ERROR)."\n");
        exit(0);
    }
    $installation->assertTarget($root, (int) $owner);
    if ($mode === 'inspect') {
        fwrite(STDOUT, json_encode(['root' => $root, 'owner_uid' => posix_geteuid(), 'php' => PHP_VERSION, 'commit' => $manifest['commit'], 'app_id' => $target['targetAppId']], JSON_THROW_ON_ERROR)."\n");
        exit(0);
    }
    $result = $installation->activate($archive, $digest, $root, (int) $owner, function (string $staging, string $existingRoot) use ($installation): array {
        $environmentPath = $existingRoot.'/.env';
        if (! is_file($environmentPath) || is_link($environmentPath) || filesize($environmentPath) > 65_536) {
            throw new RuntimeException('The owned preview template environment is missing or invalid.');
        }
        require $staging.'/vendor/autoload.php';
        $environment = Dotenv\Dotenv::parse((string) file_get_contents($environmentPath));
        if (! empty($environment['DB_URL'])) {
            throw new RuntimeException('The preview template uses an unsupported database URL override.');
        }
        $database = [
            'host' => $environment['DB_HOST'] ?? '', 'port' => $environment['DB_PORT'] ?? '3306',
            'database' => $environment['DB_DATABASE'] ?? '', 'username' => $environment['DB_USERNAME'] ?? '',
            'password' => $environment['DB_PASSWORD'] ?? '',
        ];
        $installation->validateDatabaseConfiguration($database);
        $connection = new PDO('mysql:host='.$database['host'].';port='.$database['port'].';dbname='.$database['database'].';charset=utf8mb4', $database['username'], $database['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        return [$connection, $database];
    });
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR)."\n");
} catch (Throwable $exception) {
    $reason = $exception::class === RuntimeException::class ? $exception->getMessage() : $exception::class;
    fwrite(STDERR, 'Preview installation stopped: '.$reason.' Existing database data was not replaced. Inspect the private installation state before retrying.'."\n");
    exit(1);
}
