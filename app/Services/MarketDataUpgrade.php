<?php

namespace App\Services;

use App\Models\AppConfig;
use App\Models\MarketPrice;
use App\Models\StockHolding;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class MarketDataUpgrade
{
    public const StateKey = 'market_data.upgrade.v2';

    public function __construct(private MarketDataMode $mode, private MarketPriceLegacyImporter $importer) {}

    public function assertContext(bool $deployment): void
    {
        if (! $deployment) {
            app(LocalMarketDataDatabase::class)->assertLocal();

            return;
        }
        if (PHP_SAPI !== 'cli' || ! app()->environment('production') || ! app()->isDownForMaintenance()) {
            throw new RuntimeException('Production market-data upgrade requires the existing CLI deployment in maintenance mode.');
        }
        $marker = base_path('deployment/source-commit');
        if (! is_file($marker) || ! preg_match('/^[a-f0-9]{40,64}$/D', trim(file_get_contents($marker)))) {
            throw new RuntimeException('A verified release source marker is required.');
        }
    }

    /** @return array<string, mixed> */
    public function state(): array
    {
        return AppConfig::query()->where('key', self::StateKey)->value('value') ?? [];
    }

    /** @param array<string, mixed> $state */
    private function save(array $state): void
    {
        AppConfig::query()->updateOrCreate(['key' => self::StateKey], ['value' => $state]);
    }

    private function root(): string
    {
        return config('services.market_data.upgrade_directory') ?? storage_path('app/private/market-data-upgrades');
    }

    public function prepare(bool $deployment = false): void
    {
        $this->assertContext($deployment);
        if (($this->mode->read()['mode'] ?? null) === 'active') {
            return;
        }
        $this->installBarriers();
        $this->mode->write('upgrading');
        $prior = $this->state();
        $resume = in_array($prior['status'] ?? null, ['preparing', 'prepared', 'importing'], true)
            && ($prior['database_signature'] ?? null) === $this->mode->signature();
        $state = [...$prior, 'status' => 'preparing', 'write_barrier' => true, 'database_signature' => $this->mode->signature()];
        $this->save($state);
        Artisan::call('queue:restart');
        if ($resume && isset($state['backup']) && $this->validBackup($state)) {
            return;
        }
        File::ensureDirectoryExists($this->root(), 0700);
        $file = $this->root().'/'.now()->utc()->format('Ymd-His').'-'.bin2hex(random_bytes(4)).'.sql';
        touch($file);
        chmod($file, 0600);
        $config = DB::connection()->getConfig();
        if ($config['driver'] !== 'mysql') {
            throw new RuntimeException('A native MySQL backup is required before the data upgrade.');
        }
        [$dumpBinary, $version] = $this->nativeClient(config('services.market_data.mysqldump_binary', 'mysqldump'), 'mysqldump', 'mariadb-dump');
        [$restoreBinary] = $this->nativeClient(config('services.market_data.mysql_binary', 'mysql'), 'mysql', 'mariadb');
        $options = [];
        if (! str_contains(strtolower($version), 'mariadb')) {
            $options[] = '--set-gtid-purged=OFF';
            if (preg_match('/\b(?:Ver|Distrib)\s+(\d+)\./i', $version, $match) && (int) $match[1] >= 8) {
                $options[] = '--column-statistics=0';
            }
        }
        if (! empty($config['unix_socket'])) {
            $options[] = '--socket='.$config['unix_socket'];
        }
        $result = Process::path(base_path())->timeout(1800)->env(['MYSQL_PWD' => $config['password'] ?? ''])->run([
            $dumpBinary, '--host='.$config['host'], '--port='.($config['port'] ?? 3306),
            '--user='.$config['username'], '--single-transaction', '--skip-lock-tables', '--routines', '--triggers', '--hex-blob',
            '--no-tablespaces', ...$options, '--result-file='.$file, $config['database'],
        ]);
        clearstatcache(true, $file);
        if (! $result->successful() || ! is_file($file) || filesize($file) < 1000) {
            throw new RuntimeException('Native database backup failed; data upgrade remains inactive.');
        }
        $state = [...$state, 'backup' => $file, 'backup_sha256' => hash_file('sha256', $file), 'backup_bytes' => filesize($file), 'restore_binary' => $restoreBinary, 'status' => 'prepared'];
        $this->save($state);
        $this->manifest($state);
    }

    private function installBarriers(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            throw new RuntimeException('Deployment write barriers require MySQL.');
        }
        AppConfig::query()->firstOrCreate(['key' => self::StateKey], ['value' => ['status' => 'legacy', 'write_barrier' => false]]);
        foreach (MarketPriceLegacyImporter::Tables as $table) {
            foreach (['INSERT' => 'bi', 'UPDATE' => 'bu', 'DELETE' => 'bd'] as $event => $suffix) {
                $name = 'stocks_v2_'.$table.'_'.$suffix;
                if (DB::selectOne('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?', [$name]) !== null) {
                    continue;
                }
                $key = DB::connection()->getQueryGrammar()->wrap('key');
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW BEGIN DECLARE blocked VARCHAR(5) DEFAULT 'false'; SELECT JSON_UNQUOTE(JSON_EXTRACT(value, '$.write_barrier')) INTO blocked FROM app_configs WHERE {$key} = 'market_data.upgrade.v2' LIMIT 1 LOCK IN SHARE MODE; IF blocked = 'true' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Stocks v2 legacy market-data writes are paused'; END IF; END");
            }
        }
    }

    /** @return array<string, mixed> */
    public function finish(bool $deployment = false): array
    {
        $this->assertContext($deployment);
        if (($this->mode->read()['mode'] ?? null) === 'active') {
            return ['status' => 'already_active'];
        }
        $state = $this->state();
        if (($state['write_barrier'] ?? false) !== true || ! $this->validBackup($state) || ! Schema::hasTable('market_prices')) {
            throw new RuntimeException('Prepared backup, write barrier and additive schema are required before activation.');
        }
        $this->save([...$state, 'status' => 'importing']);
        $report = $this->importer->run(500);
        $verification = $this->verify();
        config(['services.market_data.central_enabled' => true]);
        app(MarketDataCoordinator::class)->prepareReads();
        $state = [...$state, 'status' => 'active', 'write_barrier' => true, 'report' => $report, 'verification' => $verification, 'finished_at' => now()->utc()->toIso8601String()];
        $this->save($state);
        $this->mode->write('active');
        $this->manifest($state);

        return ['status' => 'active', 'verification' => $verification];
    }

    /** @return array<string, int> */
    public function verify(): array
    {
        $checked = 0;
        $holdings = StockHolding::query()->get()->keyBy('id');
        $byIsin = $holdings->keyBy(fn ($holding) => $holding->isin.'|'.$holding->currency);
        $byWkn = $holdings->keyBy(fn ($holding) => $holding->wkn.'|'.$holding->currency);
        foreach (MarketPriceLegacyImporter::Tables as $table) {
            DB::table($table)->orderBy('id')->chunkById(500, function ($rows) use ($table, $holdings, $byIsin, $byWkn, &$checked): void {
                $links = DB::table('market_price_legacy_links')->where('legacy_table', $table)->whereIn('legacy_id', $rows->pluck('id'))->get()->keyBy('legacy_id');
                $prices = MarketPrice::query()->whereIn('id', $links->pluck('market_price_id')->filter())->get()->keyBy('id');
                foreach ($rows as $row) {
                    $actual = (array) $row;
                    $link = $links->get($row->id);
                    if ($link === null || $link->content_hash !== hash('sha256', json_encode($actual, JSON_THROW_ON_ERROR)) || ! $prices->has($link->market_price_id)) {
                        throw new RuntimeException('Incomplete or changed legacy row '.$table.':'.$row->id.'; activation refused.');
                    }
                    $price = $prices[$link->market_price_id];
                    if ($link->status === 'unresolved' && $price->validation_status !== 'quarantined') {
                        throw new RuntimeException('Unresolved market identity must remain quarantined.');
                    }
                    $original = json_decode($link->original_record, true, flags: JSON_THROW_ON_ERROR);
                    $ordered = array_replace(array_fill_keys(array_keys($actual), null), $original);
                    if (count($original) !== count($actual) || hash('sha256', json_encode($ordered, JSON_THROW_ON_ERROR)) !== $link->content_hash) {
                        throw new RuntimeException('Archived legacy value-fidelity verification failed.');
                    }
                    $holding = isset($row->stock_holding_id) ? $holdings->get($row->stock_holding_id)
                        : (! empty($row->isin) ? $byIsin->get($row->isin.'|'.($row->currency ?? ''))
                            : (! empty($row->wkn) ? $byWkn->get($row->wkn.'|'.($row->currency ?? '')) : null));
                    if ($holding !== null) {
                        $attributes = $this->importer->attributes($holding, $actual, $table);
                        $store = app(MarketPriceStore::class);
                        $expected = $store->prepare($holding->market_listing_id, $attributes);
                        if ($link->status === 'merged' && ($store->matchesXetraAlias($price, $expected)
                            || $this->importer->derivedSnapshotMatches($attributes, $price))) {
                            if ((int) $price->market_listing_id !== (int) $holding->market_listing_id) {
                                throw new RuntimeException('Merged observation belongs to another listing.');
                            }
                            $checked++;

                            continue;
                        }
                        foreach (['observation_key', 'market_listing_id', 'currency', 'source_time_raw', 'time_precision', 'time_basis', 'trade_count'] as $field) {
                            if ((string) ($price->{$field} ?? '') !== (string) ($expected[$field] ?? '')) {
                                throw new RuntimeException('Central identity/time-fidelity verification failed for '.$table.':'.$row->id);
                            }
                        }
                        foreach (MarketPriceStore::Values as $field) {
                            $scale = $field === 'volume' ? 8 : 10;
                            if (MarketPriceStore::decimal($price->{$field}, $scale) !== MarketPriceStore::decimal($attributes[$field] ?? null, $scale)) {
                                throw new RuntimeException('Central price value-fidelity verification failed for '.$table.':'.$row->id);
                            }
                        }
                    }
                    $checked++;
                }
            });
            if (DB::table('market_price_legacy_links')->where('legacy_table', $table)->count() !== DB::table($table)->count()) {
                throw new RuntimeException('Legacy row-count verification failed for '.$table);
            }
        }
        if (StockHolding::query()->whereNull('market_listing_id')->exists()) {
            throw new RuntimeException('A saved instrument lacks its listing.');
        }

        return ['legacy_rows' => $checked, 'central_rows' => MarketPrice::query()->count(),
            'unresolved_rows' => DB::table('market_price_legacy_links')->where('status', 'unresolved')->count()];
    }

    /** @param array<string, mixed> $state */
    private function validBackup(array $state): bool
    {
        return ($state['database_signature'] ?? null) === $this->mode->signature() && isset($state['backup'], $state['backup_sha256'])
            && is_file($state['backup']) && filesize($state['backup']) > 1000 && hash_equals($state['backup_sha256'], hash_file('sha256', $state['backup']));
    }

    /** @param array<string, mixed> $state */
    private function manifest(array $state): void
    {
        if (isset($state['backup'])) {
            File::replace($state['backup'].'.json', json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), 0600);
        }
    }

    public function rollback(bool $deployment = false): void
    {
        $this->assertContext($deployment);
        $state = $this->state();
        $this->mode->write('legacy');
        $this->save([...$state, 'status' => 'legacy', 'write_barrier' => false, 'rolled_back_at' => now()->utc()->toIso8601String()]);
        config(['services.market_data.central_enabled' => false]);
    }

    public function restore(string $manifest, string $confirmedDatabase, bool $deployment = false): void
    {
        $this->assertContext($deployment);
        $root = realpath($this->root());
        $path = realpath($manifest);
        if ($root === false || $path === false || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Restore manifest must be inside the private upgrade backup directory.');
        }
        $state = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $config = DB::connection()->getConfig();
        if ($confirmedDatabase !== $config['database'] || ! $this->validBackup($state)) {
            throw new RuntimeException('Restore target/checksum does not match the explicit database confirmation.');
        }
        $queue = Queue::connection();
        if (! method_exists($queue, 'reservedSize') || $queue->reservedSize() !== 0) {
            throw new RuntimeException('Restore refused while queue jobs are reserved.');
        }
        $this->mode->write('upgrading');
        $stream = fopen($state['backup'], 'rb');
        try {
            $result = Process::path(base_path())->timeout(1800)->env(['MYSQL_PWD' => $config['password'] ?? ''])->input($stream)->run([
                $state['restore_binary'] ?? config('services.market_data.mysql_binary', 'mysql'), '--host='.$config['host'], '--port='.($config['port'] ?? 3306), '--user='.$config['username'],
                ...(! empty($config['unix_socket']) ? ['--socket='.$config['unix_socket']] : []), $config['database'],
            ]);
            if (! $result->successful()) {
                throw new RuntimeException('Database restore failed; keep maintenance mode enabled.');
            }
        } finally {
            fclose($stream);
        }
        $this->rollback($deployment);
    }

    /** @return array{string, string} */
    private function nativeClient(string $configured, string $default, string $alternative): array
    {
        $candidates = $configured === $default ? [$configured, $alternative] : [$configured];
        foreach ($candidates as $binary) {
            try {
                $result = Process::path(base_path())->timeout(15)->run([$binary, '--version']);
                if ($result->successful()) {
                    return [$binary, $result->output()];
                }
            } catch (\Throwable) {
                continue;
            }
        }
        throw new RuntimeException('Required native database client is unavailable: '.$default);
    }
}
