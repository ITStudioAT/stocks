<?php

namespace Tests\Feature;

use App\Models\StockHolding;
use App\Models\User;
use App\Services\CloudwaysApiClient;
use App\Services\CloudwaysDatabaseSync;
use App\Services\EodhdApiClient;
use App\Services\PreviewBackgroundState;
use App\Services\PreviewControlSignature;
use App\Services\PreviewIsolation;
use App\Services\PreviewRuntime;
use App\Services\StockAiResearchService;
use Illuminate\Cache\FileStore;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Queue\Events\Looping;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class PreviewIsolationTest extends TestCase
{
    public function test_non_preview_requests_keep_their_existing_behavior(): void
    {
        $this->assertFalse(app(PreviewIsolation::class)->active());
        app(PreviewIsolation::class)->assertIntegrationAllowed();
        $this->get('/up')->assertOk()->assertHeaderMissing('X-Stocks-Preview');
    }

    public function test_invalid_preview_is_closed_before_authentication_or_database_work(): void
    {
        config(['security.preview.enabled' => true]);
        DB::shouldReceive('connection')->never();
        $this->get('/admin')->assertStatus(503)->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_configuration_check_never_reports_secret_values_or_connects(): void
    {
        config(['security.preview.enabled' => true, 'services.eodhd.key' => 'secret-do-not-print']);
        DB::shouldReceive('connection')->never();
        $this->artisan('preview:check')->expectsOutput('Check required: services.eodhd.key')->assertFailed();
    }

    public function test_complete_isolated_configuration_passes_without_database_access(): void
    {
        $this->configurePreview();
        DB::shouldReceive('connection')->never();
        $this->assertSame([], app(PreviewIsolation::class)->problems());
        $this->artisan('preview:check')->assertSuccessful();
        $this->get('/up')->assertOk()->assertHeader('X-Stocks-Preview', 'true')->assertHeaderMissing('WWW-Authenticate');
        $this->get('/admin/login')->assertOk()->assertHeader('X-Stocks-Preview', 'true')->assertHeaderMissing('WWW-Authenticate');
        $this->getJson('/admin/depots')->assertUnauthorized()->assertHeader('X-Stocks-Preview', 'true');
    }

    public function test_preview_market_routes_require_the_application_login(): void
    {
        $this->configurePreview();
        $originalRoutes = Route::getRoutes();
        Route::setRoutes(new RouteCollection);

        try {
            require base_path('routes/web.php');
            Route::getRoutes()->refreshNameLookups();
            foreach (['homepage', 'indices', 'depot-sum-sign'] as $name) {
                $route = Route::getRoutes()->getByName($name);
                $this->assertContains('auth', $route->gatherMiddleware());
                $this->assertContains('auth.session', $route->gatherMiddleware());
                $this->assertContains('role:admin|super_admin', $route->gatherMiddleware());
                $this->assertSame([], $route->excludedMiddleware());
            }
            $this->getJson('/indices')->assertUnauthorized();
        } finally {
            Route::setRoutes($originalRoutes);
        }
    }

    public function test_background_control_requires_a_scoped_redis_queue_and_eodhd_key(): void
    {
        $this->configureControlledPreview();

        $this->assertSame([], app(PreviewIsolation::class)->problems());
        config(['queue.connections.redis.queue' => 'stocks-preview-200']);
        $this->assertContains('preview.redis_queue', app(PreviewIsolation::class)->problems());
        config(['queue.connections.redis.queue' => 'stockspreview200']);
        config(['database.redis.options.prefix' => 'live-database-']);
        $this->assertContains('preview.redis_prefix', app(PreviewIsolation::class)->problems());
        config(['database.redis.options.prefix' => 'previewuser:stocks-preview-200-database-']);
        $this->assertSame([], app(PreviewIsolation::class)->problems());
        config(['database.redis.default.username' => 'otheruser']);
        $this->assertContains('preview.redis_prefix', app(PreviewIsolation::class)->problems());
    }

    public function test_stopped_preview_blocks_web_requests_but_accepts_signed_control_status(): void
    {
        $this->configureControlledPreview();
        $state = Mockery::mock(PreviewBackgroundState::class);
        $state->shouldReceive('enabled')->twice()->andReturn(false);
        app()->instance(PreviewBackgroundState::class, $state);

        $this->get('/up')->assertStatus(503)->assertHeader('X-Stocks-Preview', 'true');
        $headers = app(PreviewControlSignature::class)->headers('GET', 'preview/control', '');
        $this->withHeaders($headers)->get('/preview/control')->assertOk()->assertJsonPath('enabled', false);
    }

    public function test_signed_control_update_rejects_replay_and_unsigned_requests(): void
    {
        $this->configureControlledPreview();
        $state = Mockery::mock(PreviewBackgroundState::class);
        $state->shouldReceive('setEnabled')->once()->with(true);
        $state->shouldReceive('enabled')->once()->andReturn(true);
        app()->instance(PreviewBackgroundState::class, $state);
        $body = json_encode(['enabled' => true], JSON_THROW_ON_ERROR);
        $headers = app(PreviewControlSignature::class)->headers('POST', 'preview/control', $body);

        $this->postJson('/preview/control', ['enabled' => true])->assertUnauthorized();
        $this->withHeaders($headers)->postJson('/preview/control', ['enabled' => true])->assertOk()->assertJsonPath('enabled', true);
        $this->withHeaders($headers)->postJson('/preview/control', ['enabled' => true])->assertUnauthorized();
    }

    public function test_controlled_runtime_pauses_queue_and_limits_outbound_http_to_eodhd(): void
    {
        $this->configureControlledPreview();
        $running = false;
        $state = Mockery::mock(PreviewBackgroundState::class);
        $state->shouldReceive('enabled')->andReturnUsing(function () use (&$running): bool {
            return $running;
        });
        app()->instance(PreviewBackgroundState::class, $state);
        app(PreviewRuntime::class)->install();
        Http::fake(['*' => Http::response(['ok' => true])]);

        $this->assertFalse(Event::until(new Looping('redis', 'stockspreview200')));
        try {
            Http::get('https://eodhd.com/api/test');
            $this->fail('Stopped preview made an outbound HTTP request.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('disabled in the Stocks preview', $exception->getMessage());
        }
        try {
            Event::dispatch(new CommandStarting('price-refresh:dispatch-due', new ArrayInput([]), new NullOutput));
            $this->fail('Stopped preview started a market data command.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('disabled in the Stocks preview', $exception->getMessage());
        }

        $running = true;
        $this->assertNotSame(false, Event::until(new Looping('redis', 'stockspreview200')));
        Event::dispatch(new CommandStarting('price-refresh:dispatch-due', new ArrayInput([]), new NullOutput));
        $this->assertTrue(Http::get('https://eodhd.com/api/test')->successful());
        $this->expectException(RuntimeException::class);
        Http::get('https://example.test/api/test');
    }

    #[DataProvider('unsafeConfiguration')]
    public function test_unsafe_configuration_is_rejected(string $key, mixed $value, string $problem): void
    {
        $this->configurePreview();
        config([$key => $value]);
        $this->assertContains($problem, app(PreviewIsolation::class)->problems());
    }

    public static function unsafeConfiguration(): array
    {
        return [
            ['security.preview.target_app_id', '100', 'preview.distinct_application'],
            ['database.connections.mysql.database', 'live123', 'preview.distinct_database'],
            ['database.connections.mysql.username', 'liveuser', 'preview.distinct_database_user'],
            ['database.connections.mysql.url', 'mysql://other', 'preview.database.url'],
            ['database.connections.mysql.read', ['host' => 'live'], 'preview.database.read'],
            ['session.domain', '.example.test', 'session.host_only'],
            ['session.files', sys_get_temp_dir(), 'session.files'],
            ['view.compiled', sys_get_temp_dir(), 'view.compiled'],
            ['app.url', 'https://live.example.test', 'preview.https_host'],
            ['app.previous_keys', ['old-live-key'], 'app.previous_keys'],
            ['filesystems.disks.local.serve', true, 'preview.private_storage_not_served'],
            ['app.key', 'base64:'.base64_encode(str_repeat('l', 32)), 'preview.independent_key'],
            ['queue.default', 'redis', 'queue.default'],
            ['ai.providers.bedrock.use_default_credential_provider', true, 'ai.providers.bedrock.use_default_credential_provider'],
        ];
    }

    #[DataProvider('integrations')]
    public function test_integrations_are_blocked_before_side_effects(string $service, string $method, array $arguments): void
    {
        config(['security.preview.enabled' => true]);
        Http::preventStrayRequests();
        DB::shouldReceive('connection')->never();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($service === EodhdApiClient::class
            ? 'EODHD synchronization is stopped'
            : 'External integrations are disabled');
        app($service)->{$method}(...$arguments);
    }

    public static function integrations(): array
    {
        return [
            [EodhdApiClient::class, 'get', ['/test']],
            [CloudwaysApiClient::class, 'startGitPull', []],
            [CloudwaysApiClient::class, 'gitDeploymentHistory', []],
            [CloudwaysDatabaseSync::class, 'compareAllTables', []],
            [CloudwaysDatabaseSync::class, 'syncAllTables', [[]]],
            [StockAiResearchService::class, 'run', ['research-1']],
        ];
    }

    public function test_ai_dispatch_does_not_create_a_job_or_lock(): void
    {
        config(['security.preview.enabled' => true]);
        Queue::fake();
        Cache::shouldReceive('lock')->never();
        $this->expectException(RuntimeException::class);
        app(StockAiResearchService::class)->dispatch(new User, new StockHolding);
    }

    #[DataProvider('runtimeOperations')]
    public function test_runtime_blocks_explicit_alternative_connections_and_outbound_services(string $operation): void
    {
        $this->configurePreview();
        app(PreviewRuntime::class)->install();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('disabled in the Stocks preview');
        match ($operation) {
            'http' => Http::get('https://invalid.example.test'),
            'mail' => Event::dispatch(new MessageSending(new Email)),
            'ai' => Event::dispatch('Laravel\\Ai\\Events\\PromptingAgent', []),
            'redis' => Redis::connection(),
            'queue' => Queue::connection('redis'),
            'cache' => Cache::store('redis')->get('test'),
            'storage' => Storage::disk('s3')->exists('test'),
            'database' => DB::connection('sqlite')->getPdo(),
            'dynamic_database' => DB::build(['driver' => 'sqlite', 'database' => ':memory:'])->getPdo(),
            'changed_database' => $this->changedDatabase(),
            'migrate' => Event::dispatch(new CommandStarting('migrate', new ArrayInput([]), new NullOutput)),
            'scheduler' => Event::dispatch(new CommandStarting('schedule:run', new ArrayInput([]), new NullOutput)),
            'clear_views' => Event::dispatch(new CommandStarting('view:clear', new ArrayInput([]), new NullOutput)),
        };
    }

    public static function runtimeOperations(): array
    {
        return array_map(fn (string $operation): array => [$operation], ['http', 'mail', 'ai', 'redis', 'queue', 'cache', 'storage', 'database', 'dynamic_database', 'changed_database', 'migrate', 'scheduler', 'clear_views']);
    }

    private function changedDatabase(): void
    {
        config(['database.connections.mysql.database' => 'another']);
        DB::connection()->getPdo();
    }

    public function test_runtime_allows_only_owned_local_storage_and_cache(): void
    {
        $this->configurePreview();
        app(PreviewRuntime::class)->install();
        $this->assertInstanceOf(FileStore::class, Cache::store('file')->getStore());
        $this->assertStringStartsWith(storage_path(), Storage::disk('local')->path('test'));
        config(['filesystems.disks.outside' => ['driver' => 'local', 'root' => sys_get_temp_dir()]]);
        $this->expectException(RuntimeException::class);
        Storage::disk('outside');
    }

    private function configurePreview(): void
    {
        config([
            'security.preview' => [
                'enabled' => true, 'source_app_id' => '100', 'target_app_id' => '200', 'server_id' => '300',
                'source_database' => 'live123', 'source_database_user' => 'liveuser',
                'source_key_sha256' => hash('sha256', str_repeat('l', 32)),
                'source_url' => 'https://live.example.test', 'target_root' => base_path(),
                'access_password_hash' => password_hash('preview-test-access', PASSWORD_BCRYPT, ['cost' => 4]),
            ],
            'app.env' => 'preview', 'app.debug' => false, 'app.key' => 'base64:'.base64_encode(str_repeat('p', 32)),
            'app.previous_keys' => [], 'app.url' => 'https://preview.example.test', 'app.maintenance.driver' => 'file',
            'database.default' => 'mysql',
            'database.connections.mysql' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'preview123', 'username' => 'previewuser'],
            'database.connections.cloudways.password' => null,
            'cache.default' => 'file', 'queue.default' => 'sync', 'mail.default' => 'array',
            'session.driver' => 'file', 'session.domain' => null, 'session.path' => '/',
            'session.secure' => true, 'session.http_only' => true, 'session.encrypt' => true,
            'session.same_site' => 'strict', 'session.cookie' => '__Host-stocks-preview-200',
            'filesystems.default' => 'local', 'filesystems.disks.local.serve' => false,
            'filesystems.disks.s3.key' => null, 'filesystems.disks.s3.secret' => null,
            'services.eodhd.key' => null, 'services.cloudways.deployment.access_token' => null,
            'ai.providers' => [],
        ]);
    }

    private function configureControlledPreview(): void
    {
        $this->configurePreview();
        config([
            'security.preview.control_enabled' => true,
            'security.preview.control_key' => str_repeat('a', 64),
            'queue.default' => 'redis',
            'queue.connections.redis.queue' => 'stockspreview200',
            'database.redis.options.prefix' => 'previewuser:stocks-preview-200-database-',
            'database.redis.default.username' => 'previewuser',
            'database.redis.default.url' => null,
            'database.redis.default.host' => '127.0.0.1',
            'services.eodhd.key' => 'preview-test-token',
            'services.eodhd.base_url' => 'https://eodhd.com/api',
        ]);
    }
}
