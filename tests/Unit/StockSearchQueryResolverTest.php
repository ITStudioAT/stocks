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

    public function test_it_corrects_known_stale_eodhd_metadata(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        Http::fake([
            'eodhd.com/api/search/LU1900066462*' => Http::response([
                [
                    'Code' => 'LEER',
                    'Exchange' => 'XETRA',
                    'Name' => 'Lyxor MSCI Eastern Europe ex Russia UCITS ETF Acc',
                    'Type' => 'ETF',
                    'Country' => 'Germany',
                    'Currency' => 'EUR',
                    'ISIN' => 'LU1900066462',
                ],
            ]),
        ]);

        $candidates = app(StockSearchQueryResolver::class)->resolveCandidates('LU1900066462');

        $this->assertSame('Amundi MSCI Eastern Europe Ex Russia UCITS ETF Acc', $candidates[0]['name']);
        $this->assertSame('LU1900066462', $candidates[0]['isin']);
        $this->assertSame('LYX02C', $candidates[0]['wkn']);
        $this->assertSame('45209801', $candidates[0]['valor']);
        $this->assertSame('LEER', $candidates[0]['symbol']);
        $this->assertSame('XETRA', $candidates[0]['exchange']);
        $this->assertSame('XETR', $candidates[0]['mic_code']);
        $this->assertSame('ETF', $candidates[0]['instrument_type']);
        $this->assertSame('Luxembourg', $candidates[0]['country']);
        $this->assertSame('EUR', $candidates[0]['currency']);
        $this->assertSame(['LU1900066462', 'LEER', 'LYX02C', '45209801'], $candidates[0]['search_terms']);
    }

    public function test_it_corrects_the_stale_lyxor_greece_etf_metadata(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        Http::fake([
            'eodhd.com/api/search/FR0010405431*' => Http::response([
                [
                    'Code' => 'LYMH',
                    'Exchange' => 'PA',
                    'Name' => 'Multi Units France - Lyxor MSCI Greece UCITS ETF',
                    'Type' => 'ETF',
                    'Country' => 'France',
                    'Currency' => 'EUR',
                    'ISIN' => 'FR0010405431',
                ],
            ]),
        ]);

        $candidates = app(StockSearchQueryResolver::class)->resolveCandidates('FR0010405431');
        $lymhCandidate = collect($candidates)->firstWhere('symbol', 'LYMH');

        $this->assertSame('Amundi MSCI Greece UCITS ETF Dist', $candidates[0]['name']);
        $this->assertSame('FR0010405431', $candidates[0]['isin']);
        $this->assertSame('LYX0BF', $candidates[0]['wkn']);
        $this->assertNull($candidates[0]['valor']);
        $this->assertSame('GRE', $candidates[0]['symbol']);
        $this->assertSame('PA', $candidates[0]['exchange']);
        $this->assertSame('XPAR', $candidates[0]['mic_code']);
        $this->assertSame('ETF', $candidates[0]['instrument_type']);
        $this->assertSame('France', $candidates[0]['country']);
        $this->assertSame('EUR', $candidates[0]['currency']);
        $this->assertSame(['FR0010405431', 'GRE', 'LYX0BF'], $candidates[0]['search_terms']);

        $this->assertIsArray($lymhCandidate);
        $this->assertSame('Amundi MSCI Greece UCITS ETF Dist', $lymhCandidate['name']);
        $this->assertSame('LYX0BF', $lymhCandidate['wkn']);
        $this->assertSame('LYMH', $lymhCandidate['symbol']);
    }

    public function test_it_finds_known_corrected_instruments_by_wkn_when_eodhd_returns_no_results(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        Http::fake([
            'eodhd.com/api/search/LYX0BF*' => Http::response([]),
        ]);

        $candidates = app(StockSearchQueryResolver::class)->resolveCandidates('LYX0BF');

        $this->assertCount(1, $candidates);
        $this->assertSame('Amundi MSCI Greece UCITS ETF Dist', $candidates[0]['name']);
        $this->assertSame('FR0010405431', $candidates[0]['isin']);
        $this->assertSame('LYX0BF', $candidates[0]['wkn']);
        $this->assertSame('GRE', $candidates[0]['symbol']);
        $this->assertSame('PA', $candidates[0]['exchange']);
        $this->assertSame('XPAR', $candidates[0]['mic_code']);
        $this->assertSame(['LYX0BF', 'FR0010405431', 'GRE'], $candidates[0]['search_terms']);
    }

    public function test_it_finds_known_corrected_instruments_by_valor_when_eodhd_returns_no_results(): void
    {
        config(['services.eodhd.key' => 'test-token']);
        Http::fake([
            'eodhd.com/api/search/45209801*' => Http::response([]),
        ]);

        $candidates = app(StockSearchQueryResolver::class)->resolveCandidates('45209801');

        $this->assertCount(1, $candidates);
        $this->assertSame('Amundi MSCI Eastern Europe Ex Russia UCITS ETF Acc', $candidates[0]['name']);
        $this->assertSame('LU1900066462', $candidates[0]['isin']);
        $this->assertSame('LYX02C', $candidates[0]['wkn']);
        $this->assertSame('45209801', $candidates[0]['valor']);
        $this->assertSame('LEER', $candidates[0]['symbol']);
        $this->assertSame('XETRA', $candidates[0]['exchange']);
        $this->assertSame('XETR', $candidates[0]['mic_code']);
        $this->assertSame(['45209801', 'LU1900066462', 'LEER', 'LYX02C'], $candidates[0]['search_terms']);
    }

    public function test_it_resolves_index_candidates_from_the_eodhd_index_symbol_list(): void
    {
        Cache::flush();
        config(['services.eodhd.key' => 'test-token']);
        Http::fake([
            'eodhd.com/api/search/AT0000999982*' => Http::response([]),
            'eodhd.com/api/search/099998*' => Http::response([]),
            'eodhd.com/api/search/ATX.INDX*' => Http::response([]),
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
        $codeCandidates = app(StockSearchQueryResolver::class)->resolveCandidates('ATX.INDX');

        $this->assertSame('Austrian Traded Index in EUR', $isinCandidates[0]['name']);
        $this->assertSame('AT0000999982', $isinCandidates[0]['isin']);
        $this->assertSame('ATX', $isinCandidates[0]['symbol']);
        $this->assertSame('INDX', $isinCandidates[0]['exchange']);
        $this->assertSame('INDEX', $isinCandidates[0]['instrument_type']);
        $this->assertSame('EUR', $isinCandidates[0]['currency']);
        $this->assertSame('ATX', $numericCandidates[0]['symbol']);
        $this->assertSame('AT0000999982', $numericCandidates[0]['isin']);
        $this->assertSame('ATX', $codeCandidates[0]['symbol']);
        $this->assertSame('INDX', $codeCandidates[0]['exchange']);
        $this->assertSame('AT0000999982', $codeCandidates[0]['isin']);
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
