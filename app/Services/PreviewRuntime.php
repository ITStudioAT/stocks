<?php

namespace App\Services;

use Closure;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectorInterface;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PDO;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

class PreviewRuntime
{
    public function install(): void
    {
        $isolation = app(PreviewIsolation::class);
        if (! $isolation->active()) {
            return;
        }
        $deny = static function (): never {
            throw new RuntimeException('This operation is disabled in the Stocks preview.');
        };
        $controlEnabled = config('security.preview.control_enabled') === true;
        if ($controlEnabled) {
            Http::globalRequestMiddleware(function (RequestInterface $request) use ($deny): RequestInterface {
                $url = $request->getUri();
                if (! app(PreviewBackgroundState::class)->enabled()
                    || $url->getScheme() !== 'https'
                    || $url->getHost() !== 'eodhd.com'
                    || $url->getPort() !== null || ! str_starts_with($url->getPath(), '/api/')) {
                    $deny();
                }

                return $request;
            });
            Event::listen(Looping::class, fn (): bool => app(PreviewBackgroundState::class)->enabled());
        } else {
            Http::globalMiddleware(fn (): Closure => $deny);
        }
        Event::listen(MessageSending::class, $deny);
        Event::listen('Laravel\\Ai\\Events\\*', $deny);
        Event::listen(CommandStarting::class, function (CommandStarting $event) use ($controlEnabled, $deny): void {
            $allowed = ['preview:check', 'preview:initialize', 'preview:activate', 'list', 'help', 'about'];
            $background = [
                'price-refresh:dispatch-due', 'intraday-candles:dispatch-due',
                'end-of-day-data:dispatch-due', 'indices-data:dispatch-due',
                'indices:eodhd-sync:dispatch-due', 'indices:v2-realtime:dispatch-due', 'model:prune',
            ];
            if ($controlEnabled) {
                $allowed = [...$allowed, 'schedule:run', 'queue:work', 'queue:restart', 'queue:pause', 'queue:continue', ...$background];
            }
            if (! in_array($event->command, $allowed, true)
                || ($controlEnabled && in_array($event->command, $background, true)
                    && ! app(PreviewBackgroundState::class)->enabled())) {
                $deny();
            }
        });

        $this->restrictDatabaseConnections($isolation, $deny);
        foreach (array_keys(Redis::connections() ?? []) as $name) {
            Redis::purge($name);
        }
        if (! $controlEnabled) {
            foreach (array_unique(['predis', 'phpredis', config('database.redis.client', 'phpredis')]) as $driver) {
                Redis::extend($driver, $deny);
            }
        }
        Cache::forgetDriver(array_keys(config('cache.stores', [])));
        foreach (array_unique(['database', 'redis', 'memcached', 'dynamodb', 'failover', ...array_column(config('cache.stores', []), 'driver')]) as $driver) {
            if (! in_array($driver, ['file', 'array', 'null'], true)) {
                Cache::extend($driver, $deny);
            }
        }
        Cache::extend('file', function ($app, array $configuration) use ($isolation, $deny): Repository {
            if (! $isolation->ownsStoragePath($configuration['path'] ?? null)
                || ! $isolation->ownsStoragePath($configuration['lock_path'] ?? null)) {
                $deny();
            }

            return $app['cache']->repository((new FileStore($app['files'], $configuration['path']))
                ->setLockDirectory($configuration['lock_path']), $configuration);
        });
        foreach (array_unique(['database', 'redis', 'sqs', 'beanstalkd', 'failover', 'background', 'deferred', ...array_column(config('queue.connections', []), 'driver')]) as $driver) {
            if (! in_array($driver, $controlEnabled ? ['sync', 'null', 'redis'] : ['sync', 'null'], true)) {
                Queue::extend($driver, $deny);
            }
        }
        Storage::forgetDisk(array_keys(config('filesystems.disks', [])));
        Storage::extend('local', function ($app, array $configuration) use ($isolation, $deny): Filesystem {
            if (! $isolation->ownsStoragePath($configuration['root'] ?? null)) {
                $deny();
            }

            return $app['filesystem']->createLocalDriver($configuration);
        });
        foreach (array_unique(['s3', 'ftp', 'sftp', 'scoped', ...array_column(config('filesystems.disks', []), 'driver')]) as $driver) {
            if ($driver !== 'local') {
                Storage::extend($driver, $deny);
            }
        }
        Broadcast::forgetDrivers();
        foreach (array_unique(['reverb', 'pusher', 'ably', 'redis', ...array_column(config('broadcasting.connections', []), 'driver')]) as $driver) {
            if ($driver !== 'null') {
                Broadcast::extend($driver, $deny);
            }
        }
        foreach (array_keys(Log::getChannels()) as $channel) {
            Log::forgetChannel($channel);
        }
        foreach (array_unique(['single', 'daily', 'stack', 'slack', 'monolog', 'custom', 'syslog', 'errorlog', ...array_column(config('logging.channels', []), 'driver')]) as $driver) {
            Log::extend($driver, fn (): Logger => new Logger('preview', [new NullHandler]));
        }
    }

    private function restrictDatabaseConnections(PreviewIsolation $isolation, Closure $deny): void
    {
        $name = (string) config('database.default');
        $allowed = $this->normalize(config('database.connections.'.$name, []), $name);
        $configurationValid = $isolation->problems() === [];
        $assertAllowed = function (array $configuration, string $connectionName) use ($name, $allowed, $configurationValid, $deny): void {
            if (! $configurationValid || $connectionName !== $name || $this->normalize($configuration, $connectionName) !== $allowed) {
                $deny();
            }
        };
        foreach (array_keys(DB::getConnections()) as $connectionName) {
            DB::purge($connectionName);
        }
        $factory = app('db.factory');
        foreach (['mysql', 'mariadb', 'pgsql', 'sqlite', 'sqlsrv'] as $driver) {
            $connector = $factory->createConnector(['driver' => $driver]);
            app()->instance('db.connector.'.$driver, new class($connector, $assertAllowed) implements ConnectorInterface
            {
                public function __construct(private ConnectorInterface $connector, private Closure $assertAllowed) {}

                public function connect(array $config): PDO
                {
                    ($this->assertAllowed)($config, (string) ($config['name'] ?? ''));
                    $connection = $this->connector->connect($config);
                    app(PreviewDatabaseGuard::class)->assertScopedGrants($connection, $config['database']);

                    return $connection;
                }
            });
            DB::extend($driver, function (array $configuration, string $connectionName) use ($assertAllowed, $factory): Connection {
                $assertAllowed($configuration, $connectionName);

                return $factory->make($configuration, $connectionName);
            });
        }
    }

    /** @param array<string, mixed> $configuration
     * @return array<string, mixed>
     */
    private function normalize(array $configuration, string $name): array
    {
        $configuration = (new ConfigurationUrlParser)->parseConfiguration($configuration);
        $configuration['prefix'] ??= '';
        $configuration['name'] ??= $name;
        ksort($configuration);

        return $configuration;
    }
}
