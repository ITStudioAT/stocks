<?php

namespace Tests\Unit;

use App\Services\TwelveDataClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class TwelveDataClientTest extends TestCase
{
    public function test_it_fetches_latest_prices_for_multiple_symbols(): void
    {
        $this->configureTwelveData();
        Http::preventStrayRequests();
        Http::fake([
            'api.twelvedata.test/price*' => Http::response([
                'AAPL' => ['price' => '306.32001'],
                'VOO' => ['price' => '697.28998'],
            ]),
        ]);

        $prices = app(TwelveDataClient::class)->latestPrices(['aapl', 'VOO']);

        $this->assertSame([
            'AAPL' => '306.32001',
            'VOO' => '697.28998',
        ], $prices);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.twelvedata.test/price?symbol=AAPL%2CVOO&apikey=test-token');
    }

    public function test_it_fetches_latest_price_for_a_single_symbol(): void
    {
        $this->configureTwelveData();
        Http::preventStrayRequests();
        Http::fake([
            'api.twelvedata.test/price*' => Http::response([
                'price' => '306.32001',
            ]),
        ]);

        $prices = app(TwelveDataClient::class)->latestPrices(['AAPL']);

        $this->assertSame([
            'AAPL' => '306.32001',
        ], $prices);
    }

    public function test_it_fetches_latest_price_for_a_symbol_exchange_and_mic_code(): void
    {
        $this->configureTwelveData();
        Http::preventStrayRequests();
        Http::fake([
            'api.twelvedata.test/price*' => Http::response([
                'price' => '82.150000',
            ]),
        ]);

        $price = app(TwelveDataClient::class)->latestPrice('exxx', 'XETRA', 'xetr');

        $this->assertSame('82.150000', $price);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.twelvedata.test/price?symbol=EXXX&exchange=XETRA&mic_code=XETR&apikey=test-token');
    }

    public function test_it_fetches_quotes(): void
    {
        $this->configureTwelveData();
        Http::preventStrayRequests();
        Http::fake([
            'api.twelvedata.test/quote*' => Http::response([
                'symbol' => 'AAPL',
                'close' => '306.32001',
            ]),
        ]);

        $quote = app(TwelveDataClient::class)->quotes(['AAPL']);

        $this->assertSame('AAPL', $quote['symbol']);
        $this->assertSame('306.32001', $quote['close']);
    }

    public function test_it_fetches_time_series(): void
    {
        $this->configureTwelveData();
        Http::preventStrayRequests();
        Http::fake([
            'api.twelvedata.test/time_series*' => Http::response([
                'meta' => [
                    'symbol' => 'VOO',
                    'interval' => '1day',
                ],
                'values' => [
                    [
                        'datetime' => '2026-06-01',
                        'close' => '697.28998',
                    ],
                ],
            ]),
        ]);

        $timeSeries = app(TwelveDataClient::class)->timeSeries('VOO', outputSize: 5);

        $this->assertSame('VOO', $timeSeries['meta']['symbol']);
        $this->assertSame('697.28998', $timeSeries['values'][0]['close']);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.twelvedata.test/time_series?symbol=VOO&interval=1day&outputsize=5&apikey=test-token');
    }

    public function test_it_searches_symbols_and_limits_results(): void
    {
        $this->configureTwelveData();
        Http::preventStrayRequests();
        Http::fake([
            'api.twelvedata.test/symbol_search*' => Http::response([
                'data' => collect(range(1, 12))
                    ->map(fn (int $index): array => [
                        'symbol' => "TEST{$index}",
                        'instrument_name' => "Test {$index}",
                    ])
                    ->all(),
                'status' => 'ok',
            ]),
        ]);

        $results = app(TwelveDataClient::class)->symbolSearch('test', 10);

        $this->assertCount(10, $results);
        $this->assertSame('TEST1', $results[0]['symbol']);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.twelvedata.test/symbol_search?symbol=test&apikey=test-token');
    }

    public function test_it_requires_a_symbol_search_query(): void
    {
        $this->configureTwelveData();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A search query is required.');

        app(TwelveDataClient::class)->symbolSearch('');
    }

    public function test_it_throws_when_token_is_missing(): void
    {
        config([
            'services.twelve_data.token' => '',
            'services.twelve_data.base_url' => 'https://api.twelvedata.test',
        ]);
        $this->app->forgetInstance(TwelveDataClient::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Twelve Data token is not configured.');

        app(TwelveDataClient::class)->latestPrices(['AAPL']);
    }

    public function test_it_throws_when_twelve_data_returns_an_api_error(): void
    {
        $this->configureTwelveData();
        Http::preventStrayRequests();
        Http::fake([
            'api.twelvedata.test/price*' => Http::response([
                'status' => 'error',
                'message' => 'Invalid symbol',
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid symbol');

        app(TwelveDataClient::class)->latestPrices(['UNKNOWN']);
    }

    private function configureTwelveData(): void
    {
        config([
            'services.twelve_data.token' => 'test-token',
            'services.twelve_data.base_url' => 'https://api.twelvedata.test',
        ]);
        $this->app->forgetInstance(TwelveDataClient::class);
    }
}
