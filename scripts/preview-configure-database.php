<?php

declare(strict_types=1);

use App\Services\PreviewDatabaseGuard;
use App\Services\PreviewReleaseBundle;
use Dotenv\Dotenv;

/** @param array{commit: string, files: array<string, string>} $manifest */
function stocksPreviewVerifyStaging(string $staging, string $private, array $manifest): void
{
    if (dirname($staging) !== $private || realpath($staging) !== $staging || is_link($staging)
        || ! preg_match('/^release-[a-f0-9]{32}$/D', basename($staging))
        || fileowner($staging) !== fileowner($private) || is_link($staging.'/vendor')) {
        throw new RuntimeException('Unexpected staging directory identity.');
    }
    (new PreviewReleaseBundle)->verifyInstalled($staging, $manifest['commit'], hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR)));
}

function stocksPreviewDatabaseEnvironment(string $contents, string $password): string
{
    if ($password === '' || strlen($password) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $password)) {
        throw new RuntimeException('Invalid password input.');
    }
    $current = Dotenv::parse($contents);
    if (($current['DB_PASSWORD'] ?? '') !== '' || ! empty($current['DB_URL'])) {
        throw new RuntimeException('Only an unconfigured template database may be configured.');
    }
    $updates = ['DB_DATABASE' => 'hbnucgvzmy', 'DB_USERNAME' => 'hbnucgvzmy', 'DB_PASSWORD' => $password];
    $counts = array_fill_keys(array_keys($updates), 0);
    $updated = preg_replace_callback('/^[\t ]*(?:export[\t ]+)?(DB_DATABASE|DB_USERNAME|DB_PASSWORD)[\t ]*=[^\r\n]*(\r?\n|$)/m', function (array $match) use ($updates, $current, &$counts): string {
        $key = $match[1];
        $line = Dotenv::parse($match[0]);
        if (! array_key_exists($key, $line) || ($line[$key] ?? null) !== ($current[$key] ?? null)) {
            throw new RuntimeException('Unsupported template database assignment.');
        }
        $counts[$key]++;
        $value = str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $updates[$key]);

        return $key.'="'.$value.'"'.$match[2];
    }, $contents);
    if (! is_string($updated) || array_values(array_unique($counts)) !== [1]
        || Dotenv::parse($updated) !== array_replace($current, $updates)) {
        throw new RuntimeException('Template database assignments are missing, duplicated or affect other configuration.');
    }

    return $updated;
}

function stocksPreviewSaveDatabaseEnvironment(string $root, string $private, string $original, string $updated): void
{
    $path = $root.'/.env';
    if (realpath($root) !== $root || realpath($private) !== $root.DIRECTORY_SEPARATOR.'.stocks-preview-private'
        || is_link($path) || ! is_file($path) || fileowner($path) !== fileowner($root)
        || fileowner($private) !== fileowner($root) || is_link($private)
        || (PHP_OS_FAMILY !== 'Windows' && (fileperms($private) & 0077) !== 0)
        || file_get_contents($path) !== $original) {
        throw new RuntimeException('Template environment identity changed.');
    }
    $identifier = bin2hex(random_bytes(16));
    $backup = $private.'/template-environment-'.$identifier;
    $temporary = $root.'/.env.preview-'.$identifier;
    foreach ([$backup => $original, $temporary => $updated] as $destination => $contents) {
        $stream = fopen($destination, 'xb');
        if ($stream === false) {
            throw new RuntimeException('Cannot create protected template environment file.');
        }
        try {
            if (! chmod($destination, 0600) || fwrite($stream, $contents) !== strlen($contents) || ! fflush($stream) || ! fsync($stream)) {
                throw new RuntimeException('Cannot persist protected template environment file.');
            }
        } finally {
            fclose($stream);
        }
    }
    clearstatcache(true, $path);
    if (is_link($path) || ! is_file($path) || fileowner($path) !== fileowner($root)
        || file_get_contents($path) !== $original || ! rename($temporary, $path)) {
        throw new RuntimeException('Template environment changed; protected backup retained.');
    }
}

function stocksConfigurePreviewDatabase(): int
{
    $phase = 'target';
    $lock = null;
    umask(0077);
    ini_set('display_errors', '0');
    ini_set('log_errors', '0');
    try {
        $root = '/home/1486907.cloudwaysapps.com/hbnucgvzmy/public_html';
        $private = $root.'/.stocks-preview-private';
        if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux' || PHP_VERSION_ID < 80401
            || ! function_exists('posix_geteuid') || posix_geteuid() !== 1013 || ($_SERVER['argc'] ?? 0) !== 1
            || stream_isatty(STDIN) || realpath($root) !== $root || fileowner($root) !== 1013
            || realpath($private) !== $private || fileowner($private) !== 1013 || (fileperms($private) & 0077) !== 0
            || is_link($private.'/installation.lock') || is_link($root.'/.env') || ! is_file($root.'/.env') || filesize($root.'/.env') > 65_536) {
            throw new RuntimeException('Target identity or protected input is invalid.');
        }
        $lock = fopen($private.'/installation.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Another preview installation operation is running.');
        }
        foreach ([$root.'/storage/framework/stocks-preview-instance', $private.'/swap.json'] as $state) {
            if (file_exists($state) || is_link($state)) {
                throw new RuntimeException('Preview is no longer an unconfigured template.');
            }
        }
        $phase = 'staging integrity';
        $archive = __DIR__.'/stocks-preview-75e531e6b022a09c424d6c73db4bff6923493b53.zip';
        $digest = 'aa154c794bd285629b64d57e6d59c544e916ad396dff60fee8d2819c7b136bfd';
        if (! is_file($archive) || is_link($archive) || ! hash_equals($digest, hash_file('sha256', $archive))) {
            throw new RuntimeException('Unexpected preview package.');
        }
        $zip = new ZipArchive;
        if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Cannot verify preview package.');
        }
        try {
            $manifest = json_decode((string) $zip->getFromName('preview-release.json'), true, 32, JSON_THROW_ON_ERROR);
        } finally {
            $zip->close();
        }
        $verifier = __DIR__.'/PreviewReleaseBundle.php';
        if (! is_file($verifier) || is_link($verifier)
            || ! hash_equals($manifest['files']['app/Services/PreviewReleaseBundle.php'] ?? '', hash_file('sha256', $verifier))) {
            throw new RuntimeException('Unexpected preview package verifier.');
        }
        require $verifier;
        $manifest = (new PreviewReleaseBundle)->inspect($archive, $digest);
        $autoloaders = glob($private.'/release-*/vendor/autoload.php');
        if (! is_array($autoloaders) || count($autoloaders) !== 1 || is_link($autoloaders[0])) {
            throw new RuntimeException('Expected the single verified staging release.');
        }
        $staging = dirname($autoloaders[0], 2);
        if (($manifest['commit'] ?? null) !== '75e531e6b022a09c424d6c73db4bff6923493b53') {
            throw new RuntimeException('Unexpected staging source commit.');
        }
        stocksPreviewVerifyStaging($staging, $private, $manifest);
        require $autoloaders[0];
        $phase = 'configuration';
        $original = (string) file_get_contents($root.'/.env');
        $password = stream_get_contents(STDIN, 4097);
        if (! is_string($password)) {
            throw new RuntimeException('Cannot read protected password input.');
        }
        $updated = stocksPreviewDatabaseEnvironment($original, $password);
        $environment = Dotenv::parse($updated);
        $host = $environment['DB_HOST'] ?? '';
        $port = $environment['DB_PORT'] ?? '3306';
        if (! in_array($host, ['localhost', '127.0.0.1'], true) || ! ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            throw new RuntimeException('Unexpected preview database endpoint.');
        }
        $phase = 'database authentication';
        $connection = new PDO('mysql:host='.$host.';port='.$port.';dbname=hbnucgvzmy;charset=utf8mb4', 'hbnucgvzmy', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        $phase = 'database scope';
        (new PreviewDatabaseGuard)->assertScopedGrants($connection, 'hbnucgvzmy');
        $phase = 'empty database';
        if ($connection->query('SHOW FULL TABLES')->fetchAll() !== []) {
            throw new RuntimeException('Preview database is not empty.');
        }
        $phase = 'protected environment update';
        stocksPreviewSaveDatabaseEnvironment($root, $private, $original, $updated);
        fwrite(STDOUT, "Preview database configuration saved: own credentials verified, scoped grants verified, empty database verified. No database data changed.\n");

        return 0;
    } catch (Throwable) {
        fwrite(STDERR, 'Preview database configuration stopped at '.$phase.'. No database data changed; do not retry the installer until this is resolved.'."\n");

        return 1;
    } finally {
        if (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(stocksConfigurePreviewDatabase());
}
