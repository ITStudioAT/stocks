<?php

declare(strict_types=1);

use App\Services\PreviewOriginalPolicy;
use App\Services\PreviewOriginalSchema;
use App\Services\PreviewSnapshotDatabase;
use App\Services\PreviewSnapshotStream;
use Dotenv\Dotenv;
use Symfony\Component\Process\Process;

/** Use only the existing local Cloudways read connection. Never bootstrap the live application. */
try {
    if (PHP_SAPI !== 'cli' || count($argv) !== 4 || PHP_VERSION_ID < 80401 || ! extension_loaded('pdo_mysql') || ! extension_loaded('sodium')) {
        throw new RuntimeException('Usage: preview-export-local.php LOCAL_STOCKS_ROOT EMPTY_PRIVATE_OUTPUT INSTALLED_PREVIEW_COMMIT');
    }
    [, $root, $output, $targetCommit] = $argv;
    $root = realpath($root);
    if ($root === false || str_replace('\\', '/', $root) !== 'C:/laravel/stocks'
        || ! preg_match('/^[a-f0-9]{40}$/D', $targetCommit) || ! is_dir($output) || is_link($output)
        || realpath($output) !== $output || array_values(array_diff(scandir($output), ['.', '..'])) !== []) {
        throw new RuntimeException('Local snapshot root/output identity mismatch.');
    }
    require $root.'/vendor/autoload.php';
    foreach (['PreviewOriginalSchema', 'PreviewOriginalPolicy', 'PreviewSnapshotDatabase', 'PreviewSnapshotStream', 'PreviewSnapshotPolicy'] as $service) {
        require_once dirname(__DIR__).'/app/Services/'.$service.'.php';
    }
    $environment = Dotenv::parse((string) file_get_contents($root.'/.env'));
    $ca = realpath($environment['CLOUDWAYS_SSL_CA'] ?? '') ?: realpath($root.'/'.($environment['CLOUDWAYS_SSL_CA'] ?? ''));
    if (($environment['CLOUDWAYS_DATABASE'] ?? '') !== 'cfbckymfgk' || ($environment['CLOUDWAYS_USERNAME'] ?? '') !== 'cfbckymfgk'
        || ! $ca || ! is_file($ca) || empty($environment['CLOUDWAYS_HOST']) || empty($environment['CLOUDWAYS_PASSWORD'])) {
        throw new RuntimeException('Configured original database identity or TLS certificate is unavailable.');
    }
    $connection = new PDO('mysql:host='.$environment['CLOUDWAYS_HOST'].';port='.($environment['CLOUDWAYS_PORT'] ?? '3306').';dbname=cfbckymfgk;charset=utf8mb4', $environment['CLOUDWAYS_USERNAME'], $environment['CLOUDWAYS_PASSWORD'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10,
        PDO::MYSQL_ATTR_SSL_CA => $ca, PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
    ]);
    $connection->exec('SET SESSION TRANSACTION READ ONLY');
    $cipher = $connection->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetch(PDO::FETCH_NUM);
    if ($connection->query('SELECT DATABASE()')->fetchColumn() !== 'cfbckymfgk' || empty($cipher[1])) {
        throw new RuntimeException('Source database or verified TLS gate failed.');
    }
    $database = new PreviewSnapshotDatabase($connection, new PreviewOriginalSchema);
    $database->assertSchema();
    $git = new Process(['git', '-C', $root, 'rev-parse', 'HEAD']);
    $git->mustRun();
    $sourceReference = trim($git->getOutput());
    if (! preg_match('/^[a-f0-9]{40}$/D', $sourceReference)) {
        throw new RuntimeException('Local approved source reference is invalid.');
    }
    $context = ['source_app_id' => '6468818', 'target_app_id' => '6690486', 'source_commit' => $sourceReference, 'target_commit' => $targetCommit, 'nonce' => bin2hex(random_bytes(32))];
    $keyPair = sodium_crypto_box_keypair();
    $request = ['context' => $context, 'recipient' => bin2hex(sodium_crypto_box_publickey($keyPair))];
    foreach (['incoming-recipient.key' => $keyPair, 'incoming-request.json' => json_encode($request, JSON_THROW_ON_ERROR)] as $name => $contents) {
        $stream = fopen($output.'/'.$name, 'xb');
        if ($stream === false || ! chmod($output.'/'.$name, 0600) || fwrite($stream, $contents) !== strlen($contents) || ! fflush($stream) || ! fsync($stream)) {
            throw new RuntimeException('Could not persist private transfer recipient.');
        }
        fclose($stream);
    }
    $secrets = [];
    foreach ($environment as $key => $value) {
        if (preg_match('/(?:KEY|PASSWORD|TOKEN|SECRET)/', $key) && is_string($value) && $value !== '') {
            $secrets[] = $value;
        }
    }
    $policy = new PreviewOriginalPolicy($secrets);
    $counts = array_fill_keys(array_keys((new PreviewOriginalSchema)->columns()), 0);
    $records = (function () use ($database, $policy, &$counts): iterable {
        foreach ($database->exportRecords() as $record) {
            $record['row'] = $policy->prepare([$record['table'] => [$record['row']]])[$record['table']][0];
            $counts[$record['table']]++;
            yield $record;
        }
    })();
    $result = (new PreviewSnapshotStream)->seal($records, $context, sodium_crypto_box_publickey($keyPair), $output.'/original.snapshot');
    $receipt = ['source_database' => 'cfbckymfgk', 'source_read_only' => true, 'source_tls_verified' => true, 'source_reference_commit' => $sourceReference, 'source_reference_note' => 'Approved local source reference; live database schema was checked directly. This does not attest the live application filesystem commit.', 'context' => $context, 'counts' => $counts, ...$result];
    $contents = json_encode($receipt, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    if (file_put_contents($output.'/export-receipt.json', $contents, LOCK_EX) !== strlen($contents)) {
        throw new RuntimeException('Could not persist export receipt.');
    }
    chmod($output.'/export-receipt.json', 0600);
    fwrite(STDOUT, $contents."\n");
} catch (Throwable $exception) {
    $reason = $exception::class === RuntimeException::class ? $exception->getMessage() : $exception::class;
    fwrite(STDERR, 'Read-only original export stopped: '.$reason."\n");
    exit(1);
}
