<?php

declare(strict_types=1);

use App\Services\PreviewDatabaseGuard;
use App\Services\PreviewIsolation;
use App\Services\PreviewOriginalPolicy;
use App\Services\PreviewOriginalSchema;
use App\Services\PreviewReleaseBundle;
use App\Services\PreviewSnapshotArchive;
use App\Services\PreviewSnapshotDatabase;
use App\Services\PreviewSnapshotStream;
use App\Services\PreviewSnapshotTransfer;
use Dotenv\Dotenv;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Process\Process;

function stocksSnapshotRead(string $path, int $limit = 67_108_864): string
{
    if (is_link($path) || ! is_file($path) || filesize($path) > $limit) {
        throw new RuntimeException('Invalid snapshot input file.');
    }

    return (string) file_get_contents($path);
}

function stocksSnapshotWrite(string $path, string $contents): void
{
    $stream = fopen($path, 'xb');
    if ($stream === false) {
        throw new RuntimeException('Refusing to overwrite snapshot output.');
    }
    try {
        if (! chmod($path, 0600) || fwrite($stream, $contents) !== strlen($contents) || ! fflush($stream) || ! fsync($stream)) {
            throw new RuntimeException('Could not persist snapshot output.');
        }
    } finally {
        fclose($stream);
    }
}

try {
    if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux' || PHP_VERSION_ID < 80401
        || ! extension_loaded('sodium') || ! extension_loaded('pdo_mysql') || ! function_exists('posix_geteuid')) {
        throw new RuntimeException('Snapshot requires Linux CLI PHP >= 8.4.1 with sodium, PDO MySQL and POSIX.');
    }
    umask(0077);
    if (count($argv) < 4 || ! in_array($argv[1], ['inventory', 'export', 'prepare', 'adopt', 'inspect', 'import', 'restore', 'finish'], true)) {
        throw new RuntimeException('Usage: preview-snapshot.php MODE CANONICAL_APP_ROOT PRIVATE_WORK_DIRECTORY [SOURCE_COMMIT | INPUT_FILE TRUSTED_SHA256]');
    }
    [, $mode, $root, $directory] = $argv;
    $source = in_array($mode, ['inventory', 'export'], true);
    $folder = $source ? 'cfbckymfgk' : 'hbnucgvzmy';
    if ($root !== '/home/1486907.cloudwaysapps.com/'.$folder.'/public_html' || realpath($root) !== $root
        || fileowner($root) !== posix_geteuid() || is_link($root.'/.env') || is_link($root.'/vendor')) {
        throw new RuntimeException('Snapshot application root or Unix owner mismatch.');
    }
    if (! is_dir($directory) || realpath($directory) !== $directory || is_link($directory)
        || fileowner($directory) !== posix_geteuid() || (fileperms($directory) & 0077) !== 0
        || ($source && (! str_starts_with($directory, '/tmp/stocks-snapshot-') || dirname($directory) !== '/tmp'))
        || (! $source && dirname($directory) !== $root.'/.stocks-preview-private')) {
        throw new RuntimeException('Snapshot work directory must be private, owned and outside the public web directory.');
    }
    $services = is_file(__DIR__.'/PreviewOriginalSchema.php') ? __DIR__ : dirname(__DIR__).'/app/Services';
    foreach (['PreviewOriginalSchema', 'PreviewOriginalPolicy', 'PreviewSnapshotPolicy', 'PreviewSnapshotArchive', 'PreviewSnapshotStream', 'PreviewSnapshotDatabase', 'PreviewSnapshotTransfer'] as $service) {
        require_once $services.'/'.$service.'.php';
    }
    if (! $source) {
        require_once $services.'/PreviewReleaseBundle.php';
        $unbootedMarker = json_decode(stocksSnapshotRead($root.'/storage/framework/stocks-preview-instance', 65_536), true, 16, JSON_THROW_ON_ERROR);
        if (($unbootedMarker['source_app_id'] ?? '') !== '6468818' || ($unbootedMarker['target_app_id'] ?? '') !== '6690486'
            || ($unbootedMarker['root'] ?? '') !== $root || ($unbootedMarker['state'] ?? '') !== 'active') {
            throw new RuntimeException('Unbooted preview installation identity mismatch.');
        }
        (new PreviewReleaseBundle)->verifyInstalled($root, $unbootedMarker['commit'], $unbootedMarker['manifest_sha256']);
    }
    require $root.'/vendor/autoload.php';
    if ($source) {
        $environment = Dotenv::parse(stocksSnapshotRead($root.'/.env', 65_536));
        if (($environment['DB_DATABASE'] ?? '') !== 'cfbckymfgk' || ($environment['DB_USERNAME'] ?? '') !== 'cfbckymfgk'
            || ! in_array($environment['DB_HOST'] ?? '', ['127.0.0.1', 'localhost'], true)
            || ($environment['DB_PORT'] ?? '3306') !== '3306' || ! empty($environment['DB_URL']) || ! empty($environment['DB_SOCKET'])) {
            throw new RuntimeException('Source database identity/configuration mismatch.');
        }
        $connection = new PDO('mysql:host='.$environment['DB_HOST'].';port=3306;dbname=cfbckymfgk;charset=utf8mb4', 'cfbckymfgk', $environment['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $connection->exec('SET SESSION TRANSACTION READ ONLY');
        if ($connection->query('SELECT DATABASE()')->fetchColumn() !== 'cfbckymfgk') {
            throw new RuntimeException('Source database mismatch.');
        }
        $process = new Process(['git', '-C', $root, 'rev-parse', 'HEAD']);
        $process->mustRun();
        $sourceCommit = trim($process->getOutput());
        if (! preg_match('/^[a-f0-9]{40}$/D', $sourceCommit)) {
            throw new RuntimeException('Source commit cannot be verified.');
        }
        $database = new PreviewSnapshotDatabase($connection, new PreviewOriginalSchema);
        $database->assertSchema();
        if ($mode === 'inventory') {
            $counts = [];
            foreach (array_keys((new PreviewOriginalSchema)->columns()) as $table) {
                $counts[$table] = (int) $connection->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
            }
            fwrite(STDOUT, json_encode(['source_commit' => $sourceCommit, 'counts' => $counts], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");
            exit(0);
        }
        if (count($argv) !== 6 || ! preg_match('/^[a-f0-9]{64}$/D', $argv[5])) {
            throw new RuntimeException('Export requires the preview request file and its separately verified SHA256.');
        }
        $requestContents = stocksSnapshotRead($argv[4], 65_536);
        if (! hash_equals($argv[5], hash('sha256', $requestContents))) {
            throw new RuntimeException('Preview request authentication failed.');
        }
        $request = json_decode($requestContents, true, 16, JSON_THROW_ON_ERROR);
        if (($request['context']['source_app_id'] ?? '') !== '6468818' || ($request['context']['target_app_id'] ?? '') !== '6690486'
            || ($request['context']['source_commit'] ?? '') !== $sourceCommit || ! preg_match('/^[a-f0-9]{64}$/D', $request['recipient'] ?? '')) {
            throw new RuntimeException('Export request source/target identity mismatch.');
        }
        $secrets = [];
        foreach ($environment as $key => $value) {
            if (preg_match('/(?:KEY|PASSWORD|TOKEN|SECRET)/', $key) && is_string($value) && $value !== '') {
                $secrets[] = $value;
            }
        }
        $policy = new PreviewOriginalPolicy($secrets);
        $records = (function () use ($database, $policy): iterable {
            foreach ($database->exportRecords() as $record) {
                $record['row'] = $policy->prepare([$record['table'] => [$record['row']]])[$record['table']][0];
                yield $record;
            }
        })();
        $result = (new PreviewSnapshotStream)->seal($records, $request['context'], hex2bin($request['recipient']), $directory.'/original.snapshot');
        fwrite(STDOUT, json_encode(['file' => $directory.'/original.snapshot', ...$result], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");
        exit(0);
    }
    if (posix_geteuid() !== 1013) {
        throw new RuntimeException('Preview snapshot requires the verified application owner.');
    }
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $isolation = $app->make(PreviewIsolation::class);
    if (! $isolation->active() || $isolation->problems() !== [] || ($isolation->installationMarker()['state'] ?? null) !== 'active') {
        throw new RuntimeException('Preview isolation/installation gates failed.');
    }
    $lock = $isolation->lockInstallation();
    $marker = $isolation->installationMarker();
    (new PreviewReleaseBundle)->verifyInstalled($root, $marker['commit'], $marker['manifest_sha256']);
    $connection = $app['db']->connection()->getPdo();
    (new PreviewDatabaseGuard)->assertScopedGrants($connection, 'hbnucgvzmy');
    $database = new PreviewSnapshotDatabase($connection, new PreviewOriginalSchema);
    $transfer = new PreviewSnapshotTransfer($database, new PreviewSnapshotArchive(new PreviewOriginalPolicy), $directory, $root.'/storage/framework/down', $root.'/storage/framework/sessions', $root.'/storage/framework/cache/data');
    if ($mode === 'prepare') {
        if (count($argv) !== 5 || ! preg_match('/^[a-f0-9]{40}$/D', $argv[4])) {
            throw new RuntimeException('Prepare requires the verified source commit.');
        }
        $request = $transfer->prepare(['source_app_id' => '6468818', 'target_app_id' => '6690486', 'source_commit' => $argv[4], 'target_commit' => $marker['commit'], 'nonce' => bin2hex(random_bytes(32))]);
        $result = ['request' => $directory.'/request.json', 'request_sha256' => hash_file('sha256', $directory.'/request.json'), 'context' => $request['context']];
    } elseif ($mode === 'adopt') {
        $request = json_decode(stocksSnapshotRead($directory.'/incoming-request.json', 65_536), true, 16, JSON_THROW_ON_ERROR);
        if (($request['context']['source_app_id'] ?? '') !== '6468818' || ($request['context']['target_app_id'] ?? '') !== '6690486'
            || ($request['context']['target_commit'] ?? '') !== $marker['commit']) {
            throw new RuntimeException('Incoming recipient context does not match this preview release.');
        }
        $keyPair = stocksSnapshotRead($directory.'/incoming-recipient.key', 1024);
        if (strlen($keyPair) !== SODIUM_CRYPTO_BOX_KEYPAIRBYTES || bin2hex(sodium_crypto_box_publickey($keyPair)) !== ($request['recipient'] ?? '')) {
            throw new RuntimeException('Incoming recipient key mismatch.');
        }
        $result = $transfer->prepare($request['context'], $keyPair);
    } elseif (in_array($mode, ['inspect', 'import'], true)) {
        if (count($argv) !== 6) {
            throw new RuntimeException('Inspect/import requires the encrypted snapshot and its separately verified SHA256.');
        }
        $method = $mode.'Stream';
        $result = $transfer->{$method}($argv[4], $argv[5]);
    } else {
        $transfer->{$mode}();
        $result = ['state' => $mode === 'restore' ? 'restored-maintenance' : 'released'];
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");
} catch (Throwable $exception) {
    $reason = $exception::class === RuntimeException::class ? $exception->getMessage() : $exception::class;
    if ($exception instanceof PDOException) {
        $reason .= ' ('.PreviewSnapshotDatabase::describePdoFailure($exception).')';
    }
    fwrite(STDERR, 'Snapshot stopped: '.$reason.'. Preview maintenance is retained after an interrupted import; use restore before finish. No source writes are performed.'."\n");
    exit(1);
}
