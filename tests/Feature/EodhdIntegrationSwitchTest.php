<?php

namespace Tests\Feature;

use App\Services\EodhdApiClient;
use App\Services\EodhdApiUsage;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class EodhdIntegrationSwitchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.market_data.free_only' => false,
            'services.eodhd.enabled' => true,
            'services.eodhd.key' => 'isolated-test-token',
            'services.eodhd.base_url' => 'https://eodhd.com/api',
            'services.eodhd.calls_used_today' => 0,
            'cache.default' => 'array',
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);

        DB::shouldReceive('connection')->never();
        Http::preventStrayRequests();
        Http::fake(['eodhd.com/api/*' => Http::response(['ok' => true])]);
    }

    #[DataProvider('eodhdEndpoints')]
    public function test_disabled_integration_blocks_each_endpoint_without_http_or_counter_changes(string $path): void
    {
        config(['services.eodhd.enabled' => false]);
        $apiClient = app(EodhdApiClient::class);
        app(EodhdApiUsage::class)->recordCall(7);
        $before = Cache::getStore()->all();

        $this->assertFalse($apiClient->configured());

        try {
            $apiClient->get($path, ['fmt' => 'json', 'api_token' => 'query-secret-token'], 10);
            $this->fail('Disabled EODHD integration accepted an API request.');
        } catch (RuntimeException $exception) {
            $this->assertSame('EODHD integration is disabled by configuration.', $exception->getMessage());
            $this->assertStringNotContainsString('isolated-test-token', $exception->getMessage());
            $this->assertStringNotContainsString('query-secret-token', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }

        $this->assertSame($before, Cache::getStore()->all());
        Http::assertNothingSent();
    }

    /** @return array<string, array{string}> */
    public static function eodhdEndpoints(): array
    {
        return [
            'realtime' => ['real-time/AAPL.US'],
            'history' => ['eod/AAPL.US'],
            'intraday' => ['intraday/AAPL.US'],
            'search' => ['search/AAPL'],
            'exchanges' => ['exchanges-list'],
            'symbols' => ['exchange-symbol-list/US'],
            'fundamentals' => ['fundamentals/AAPL.US'],
            'dividends' => ['div/AAPL.US'],
            'splits' => ['splits/AAPL.US'],
            'forex' => ['eod/USDEUR.FOREX'],
        ];
    }

    public function test_the_shipped_application_configuration_disables_new_provider_requests(): void
    {
        $configuration = require base_path('config/services.php');
        $this->assertFalse($configuration['eodhd']['enabled']);
        $this->assertTrue($configuration['market_data']['free_only']);
        config(['services.eodhd.enabled' => $configuration['eodhd']['enabled']]);

        $this->assertFalse(app(EodhdApiClient::class)->configured());
        Http::assertNothingSent();
    }

    #[DataProvider('eodhdEndpoints')]
    public function test_free_only_policy_blocks_requests_even_with_legacy_cached_configuration(string $path): void
    {
        config(['services.market_data' => null, 'services.eodhd.enabled' => true]);
        $this->assertFalse(app(EodhdApiClient::class)->configured());

        try {
            app(EodhdApiClient::class)->get($path);
            $this->fail('A cached legacy provider setting bypassed the free-only policy.');
        } catch (RuntimeException $exception) {
            $this->assertSame('EODHD integration is disabled by configuration.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_disabled_integration_does_not_initialize_usage_counters(): void
    {
        config(['services.eodhd.enabled' => false]);
        $before = Cache::getStore()->all();

        try {
            app(EodhdApiClient::class)->get('eod/AAPL.US');
            $this->fail('Disabled EODHD integration accepted an API request.');
        } catch (RuntimeException $exception) {
            $this->assertSame('EODHD integration is disabled by configuration.', $exception->getMessage());
        }

        $this->assertSame($before, Cache::getStore()->all());
        Http::assertNothingSent();
    }

    public function test_enabled_integration_preserves_requests_and_usage_accounting(): void
    {
        $apiClient = app(EodhdApiClient::class);

        $this->assertTrue($apiClient->configured());
        $response = $apiClient->get('fundamentals/AAPL.US', ['fmt' => 'json'], 10);

        $this->assertTrue($response->successful());
        $this->assertSame(['ok' => true], $response->json());
        $payload = app(EodhdApiUsage::class)->payload();
        $this->assertSame(10, $payload['hour']['used']);
        $this->assertSame(10, $payload['day']['used']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/fundamentals/AAPL.US')
            && $request['api_token'] === 'isolated-test-token'
            && $request['fmt'] === 'json');
    }

    public function test_enabled_integration_still_requires_a_token(): void
    {
        config(['services.eodhd.key' => '  ']);

        $this->assertFalse(app(EodhdApiClient::class)->configured());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('EODHD API token is not configured.');

        app(EodhdApiClient::class)->get('eod/AAPL.US');
    }
}
