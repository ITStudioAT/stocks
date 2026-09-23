<?php

namespace Tests\Feature;

use App\Models\StockHolding;
use App\Models\User;
use App\Services\CloudwaysApiClient;
use App\Services\CloudwaysDatabaseSync;
use App\Services\EodhdApiClient;
use App\Services\PreviewIsolation;
use App\Services\PreviewRuntime;
use App\Services\StockAiResearchService;
use Illuminate\Cache\FileStore;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
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
        $this->get('/up')->assertOk()->assertHeader('X-Stocks-Preview', 'true');
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
        $this->expectExceptionMessage('External integrations are disabled');
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
            'filesystems.default' => 'local', 'filesystems.disks.s3.key' => null, 'filesystems.disks.s3.secret' => null,
            'services.eodhd.key' => null, 'services.cloudways.deployment.access_token' => null,
            'ai.providers' => [],
        ]);
    }
}
