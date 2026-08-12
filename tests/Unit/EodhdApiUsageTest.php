<?php

namespace Tests\Unit;

use App\Services\EodhdApiClient;
use App\Services\EodhdApiUsage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class EodhdApiUsageTest extends TestCase
{
    public function test_it_reports_remaining_hourly_and_daily_calls(): void
    {
        Cache::flush();
        config([
            'services.eodhd.calls_per_hour' => 1000,
            'services.eodhd.calls_per_day' => 100000,
            'services.eodhd.calls_used_today' => 1195,
        ]);
        $this->travelTo(Carbon::parse('2026-06-04 13:25:00', 'Europe/Vienna'));

        $usage = app(EodhdApiUsage::class);
        $payload = $usage->payload();

        $this->assertSame(0, $payload['hour']['used']);
        $this->assertSame(1000, $payload['hour']['remaining']);
        $this->assertSame(1195, $payload['day']['used']);
        $this->assertSame(98805, $payload['day']['remaining']);
        $this->assertSame('2026-06-04T14:00:00+02:00', $payload['hour']['reset_at']);
        $this->assertSame('2026-06-05T00:00:00+02:00', $payload['day']['reset_at']);
    }

    public function test_it_counts_each_recorded_eodhd_call(): void
    {
        Cache::flush();
        config([
            'services.eodhd.calls_per_hour' => 1000,
            'services.eodhd.calls_per_day' => 100000,
            'services.eodhd.calls_used_today' => 1195,
        ]);
        $this->travelTo(Carbon::parse('2026-06-04 13:25:00', 'Europe/Vienna'));

        $usage = app(EodhdApiUsage::class);
        $usage->recordCall();
        $usage->recordCall();
        $payload = $usage->payload();

        $this->assertSame(2, $payload['hour']['used']);
        $this->assertSame(998, $payload['hour']['remaining']);
        $this->assertSame(1197, $payload['day']['used']);
        $this->assertSame(98803, $payload['day']['remaining']);
    }

    public function test_the_api_client_counts_each_outgoing_eodhd_request(): void
    {
        Cache::flush();
        config([
            'services.eodhd.key' => 'test-token',
            'services.eodhd.calls_per_hour' => 1000,
            'services.eodhd.calls_per_day' => 100000,
            'services.eodhd.calls_used_today' => 0,
        ]);
        $this->travelTo(Carbon::parse('2026-06-04 13:25:00', 'Europe/Vienna'));
        Http::fake([
            'eodhd.com/api/*' => Http::response(['ok' => true]),
        ]);

        $apiClient = app(EodhdApiClient::class);
        $apiClient->get('real-time/AAPL.US', ['fmt' => 'json']);
        $apiClient->get('eod/AAPL.US', ['fmt' => 'json']);

        $payload = app(EodhdApiUsage::class)->payload();

        $this->assertSame(2, $payload['hour']['used']);
        $this->assertSame(99998, $payload['day']['remaining']);
        Http::assertSentCount(2);
    }

    public function test_the_api_client_redacts_tokens_from_connection_exceptions(): void
    {
        config(['services.eodhd.key' => 'connection-secret-token']);
        Http::fake(Http::failedConnection(
            'Connection failed for https://eodhd.test/eod?api_token=connection-secret-token&fmt=json',
        ));

        try {
            app(EodhdApiClient::class)->get('eod/AAPL.US');
            $this->fail('Expected the EODHD request to fail.');
        } catch (ConnectionException $exception) {
            $this->assertStringNotContainsString('connection-secret-token', $exception->getMessage());
            $this->assertStringContainsString('api_token=[redacted]', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    public function test_the_api_client_reports_an_actionable_authentication_error(): void
    {
        config(['services.eodhd.key' => 'invalid-test-token']);
        Http::preventStrayRequests();
        Http::fake([
            'eodhd.com/api/*' => Http::response([], 401),
        ]);

        try {
            app(EodhdApiClient::class)->get('eod/AAPL.US', ['fmt' => 'json']);
            $this->fail('Expected the invalid EODHD token to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame(401, $exception->getCode());
            $this->assertSame(EodhdApiClient::AuthenticationErrorMessage, $exception->getMessage());
            $this->assertStringNotContainsString('invalid-test-token', $exception->getMessage());
        }

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request['api_token'] === 'invalid-test-token');
    }
}
