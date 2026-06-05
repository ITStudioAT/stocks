<?php

namespace Tests\Unit;

use App\Services\EodhdApiUsage;
use App\Services\StockSearchQueryResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StockSearchQueryResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_it_resolves_portfolio_ready_candidates_with_eodhd(): void
    {
        Cache::flush();
        config([
            'services.eodhd.key' => 'test-token',
            'services.eodhd.calls_per_hour' => 1000,
            'services.eodhd.calls_per_day' => 100000,
            'services.eodhd.calls_used_today' => 0,
        ]);
        Http::fake([
            'eodhd.com/api/search/Microsoft*' => Http::response([
                [
                    'Code' => 'MSFT',
                    'Exchange' => 'NASDAQ',
                    'Name' => 'Microsoft Corporation',
                    'Type' => 'Common Stock',
                    'Country' => 'USA',
                    'Currency' => 'USD',
                    'ISIN' => 'US5949181045',
                ],
            ]),
        ]);

        $candidates = app(StockSearchQueryResolver::class)->resolveCandidates('Microsoft');

        $this->assertSame('Microsoft Corporation', $candidates[0]['name']);
        $this->assertSame('US5949181045', $candidates[0]['isin']);
        $this->assertNull($candidates[0]['wkn']);
        $this->assertNull($candidates[0]['valor']);
        $this->assertSame('MSFT', $candidates[0]['symbol']);
        $this->assertSame('NASDAQ', $candidates[0]['exchange']);
        $this->assertSame('XNAS', $candidates[0]['mic_code']);
        $this->assertSame('Common Stock', $candidates[0]['instrument_type']);
        $this->assertSame('USA', $candidates[0]['country']);
        $this->assertSame('USD', $candidates[0]['currency']);
        $this->assertSame(['Microsoft', 'US5949181045', 'MSFT'], $candidates[0]['search_terms']);
        $this->assertSame(1, app(EodhdApiUsage::class)->payload()['hour']['used']);

        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_starts_with($request->url(), 'https://eodhd.com/api/search/Microsoft?')
                && $query['api_token'] === 'test-token'
                && $query['fmt'] === 'json'
                && $query['limit'] === '15'
                && $query['type'] === 'all';
        });
    }

    public function test_it_returns_no_candidates_for_blank_queries_without_requesting_eodhd(): void
    {
        $this->assertSame([], app(StockSearchQueryResolver::class)->resolveCandidates('  '));

        Http::assertNothingSent();
    }

    public function test_it_encodes_the_search_term_for_eodhd(): void
    {
        Cache::flush();
        config(['services.eodhd.key' => 'test-token']);
        Http::fake([
            'eodhd.com/api/search/Apple%20Inc*' => Http::response([]),
        ]);

        app(StockSearchQueryResolver::class)->resolveCandidates('Apple Inc');

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://eodhd.com/api/search/Apple%20Inc?'));
    }

    public function test_it_always_fetches_fresh_candidates_from_eodhd(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        Http::fake([
            'eodhd.com/api/search/Apple*' => Http::sequence()
                ->push([
                    [
                        'Code' => 'AAPL',
                        'Exchange' => 'NASDAQ',
                        'Name' => 'Apple Old',
                        'Type' => 'Common Stock',
                        'Country' => 'USA',
                        'Currency' => 'USD',
                        'ISIN' => 'US0378331005',
                    ],
                ])
                ->push([
                    [
                        'Code' => 'AAPL',
                        'Exchange' => 'NASDAQ',
                        'Name' => 'Apple Fresh',
                        'Type' => 'Common Stock',
                        'Country' => 'USA',
                        'Currency' => 'USD',
                        'ISIN' => 'US0378331005',
                    ],
                ]),
        ]);

        $firstCandidates = app(StockSearchQueryResolver::class)->resolveCandidates('Apple');
        $secondCandidates = app(StockSearchQueryResolver::class)->resolveCandidates('Apple');

        $this->assertSame('Apple Old', $firstCandidates[0]['name']);
        $this->assertSame('Apple Fresh', $secondCandidates[0]['name']);
        Http::assertSentCount(2);
    }

    public function test_it_resolves_index_candidates_from_the_eodhd_index_symbol_list(): void
    {
        Cache::flush();
        config(['services.eodhd.key' => 'test-token']);
        Http::fake([
            'eodhd.com/api/search/AT0000999982*' => Http::response([]),
            'eodhd.com/api/search/099998*' => Http::response([]),
            'eodhd.com/api/exchange-symbol-list/INDX*' => Http::response([
                [
                    'Code' => 'ATX',
                    'Name' => 'Austrian Traded Index in EUR',
                    'Country' => 'Austria',
                    'Exchange' => 'INDX',
                    'Currency' => 'EUR',
                    'Type' => 'INDEX',
                    'Isin' => 'AT0000999982',
                ],
            ]),
        ]);

        $isinCandidates = app(StockSearchQueryResolver::class)->resolveCandidates('AT0000999982');
        $numericCandidates = app(StockSearchQueryResolver::class)->resolveCandidates('099998');

        $this->assertSame('Austrian Traded Index in EUR', $isinCandidates[0]['name']);
        $this->assertSame('AT0000999982', $isinCandidates[0]['isin']);
        $this->assertSame('ATX', $isinCandidates[0]['symbol']);
        $this->assertSame('INDX', $isinCandidates[0]['exchange']);
        $this->assertSame('INDEX', $isinCandidates[0]['instrument_type']);
        $this->assertSame('EUR', $isinCandidates[0]['currency']);
        $this->assertSame('ATX', $numericCandidates[0]['symbol']);
        $this->assertSame('AT0000999982', $numericCandidates[0]['isin']);
    }

    public function test_it_returns_no_candidates_when_eodhd_fails(): void
    {
        Cache::flush();
        config(['services.eodhd.key' => 'test-token']);
        Http::fake([
            'eodhd.com/api/search/Unknown*' => Http::response(['status' => 'error', 'message' => 'Not found'], 200),
        ]);

        $this->assertSame([], app(StockSearchQueryResolver::class)->resolveCandidates('Unknown'));
    }
}
