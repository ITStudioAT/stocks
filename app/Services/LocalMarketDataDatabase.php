<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class LocalMarketDataDatabase
{
    public function assertLocal(): void
    {
        $connection = DB::connection();
        $config = $connection->getConfig();
        if ($config['driver'] === 'sqlite' && $config['database'] === ':memory:' && app()->environment('testing')) {
            return;
        }
        if ($config['driver'] !== 'mysql' || ! in_array($config['host'], ['127.0.0.1', '::1', 'localhost'], true)
            || ! empty($config['url']) || ! empty($config['read']) || ! empty($config['write'])) {
            throw new RuntimeException('Market-data migration requires a verified local database, without URL or read/write overrides.');
        }
        $server = $connection->selectOne('SELECT @@hostname AS hostname, @@datadir AS directory');
        if (strcasecmp($server->hostname, gethostname()) !== 0 || ! is_dir($server->directory)
            || realpath($server->directory) === false) {
            throw new RuntimeException('Database server identity/data directory does not match this computer; refusing migration.');
        }
    }
}
