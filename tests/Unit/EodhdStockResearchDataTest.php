<?php

namespace Tests\Unit;

use App\Models\StockHolding;
use App\Services\EodhdApiUsage;
use App\Services\EodhdStockResearchData;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EodhdStockResearchDataTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->travelTo(Carbon::parse('2026-08-16 10:00:00', 'Europe/Vienna'));
    }

    public function test_it_builds_a_current_etf_evidence_bundle_from_paid_eodhd_datasets(): void
    {
        config(['services.eodhd.key' => 'research-secret-token']);
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $this->responseFor($request));
        $holding = new StockHolding([
            'name' => 'Amundi Global Hydrogen UCITS ETF Acc',
            'symbol' => 'AMEE',
            'isin' => 'FR0010930644',
            'exchange' => 'XETRA',
            'instrument_type' => 'ETF',
            'currency' => 'EUR',
        ]);
        $holding->id = 21;

        $bundle = app(EodhdStockResearchData::class)->for($holding);

        $this->assertSame('complete', $bundle['status'], json_encode($bundle['coverage'], JSON_THROW_ON_ERROR));
        $this->assertTrue($bundle['coverage_complete']);
        $this->assertSame('AMEE.XETRA', $bundle['instrument']['eodhd_symbol']);
        $this->assertSame('verified_by_isin', $bundle['instrument']['mapping_status']);
        $this->assertNull($bundle['etf_snapshot']['source_as_of']);
        $this->assertStringContainsString('not an official holdings snapshot date', $bundle['etf_snapshot']['date_note']);
        $this->assertSame('BE.US', $bundle['etf_snapshot']['positions'][0]['symbol']);
        $this->assertSame(8.4, $bundle['etf_snapshot']['positions'][0]['weight_pct']);
        $this->assertSame('AI.PA', $bundle['etf_snapshot']['positions'][1]['symbol']);
        $reportedEarnings = collect($bundle['earnings_calendar'])->firstWhere('status', 'reported');
        $scheduledEarnings = collect($bundle['earnings_calendar'])->firstWhere('status', 'scheduled');
        $this->assertSame(12.5, $reportedEarnings['eps_surprise_pct']);
        $this->assertSame(1200, $reportedEarnings['revenue_actual']);
        $this->assertSame(1000, $reportedEarnings['revenue_estimate']);
        $this->assertSame('AI.PA', $scheduledEarnings['symbol']);
        $this->assertSame('https://news.example/be-quarter', $bundle['current_news'][0]['link']);
        $this->assertSame(7.25, $bundle['current_market'][1]['change_pct']);
        $this->assertSame('2026-08-15', $bundle['recent_market_context']['BE.US'][0]['date']);
        $this->assertSame(0.65, $bundle['recent_news_sentiment']['BE.US'][0]['normalized']);
        $this->assertSame('purchase', $bundle['insider_activity'][0]['action']);
        $this->assertSame(
            'https://www.sec.gov/Archives/edgar/data/1664703/000123456726000001/0001234567-26-000001-index.html',
            $bundle['insider_activity'][0]['source_url'],
        );
        $this->assertStringNotContainsString('research-secret-token', json_encode($bundle, JSON_THROW_ON_ERROR));
        $this->assertSame(68, app(EodhdApiUsage::class)->payload()['hour']['used']);
        Http::assertSentCount(15);

        $retrievedAt = $bundle['current_market'][0]['received_at'];
        $etfRetrievedAt = $bundle['etf_snapshot']['retrieved_at'];
        $this->travel(30)->seconds();
        $cachedBundle = app(EodhdStockResearchData::class)->for($holding);

        $this->assertContains('cached_fresh', collect($cachedBundle['coverage'])->pluck('status')->all());
        $this->assertSame($retrievedAt, $cachedBundle['current_market'][0]['received_at']);
        $this->assertSame($etfRetrievedAt, $cachedBundle['etf_snapshot']['retrieved_at']);
        $this->assertSame(68, app(EodhdApiUsage::class)->payload()['hour']['used']);
        Http::assertSentCount(15);
    }

    public function test_it_reports_partial_subscription_coverage_instead_of_treating_it_as_no_news(): void
    {
        config(['services.eodhd.key' => 'limited-token']);
        Http::preventStrayRequests();
        Http::fake([
            'eodhd.com/api/*' => Http::response(['message' => 'Forbidden'], 403),
        ]);
        $holding = new StockHolding([
            'symbol' => 'AMEE',
            'isin' => 'FR0010930644',
            'exchange' => 'XETRA',
            'instrument_type' => 'ETF',
        ]);

        $bundle = app(EodhdStockResearchData::class)->for($holding);

        $this->assertSame('unavailable', $bundle['status']);
        $this->assertFalse($bundle['coverage_complete']);
        $this->assertSame('fallback_not_mapped', $bundle['instrument']['mapping_status']);
        $this->assertSame([], $bundle['etf_snapshot']);
        $this->assertContains('unsupported_subscription', collect($bundle['coverage'])->pluck('status')->all());
        Http::assertSentCount(6);

        app(EodhdStockResearchData::class)->for($holding);

        Http::assertSentCount(6);
    }

    public function test_it_skips_http_requests_when_eodhd_is_not_configured(): void
    {
        config(['services.eodhd.key' => null]);
        Http::preventStrayRequests();

        $bundle = app(EodhdStockResearchData::class)->for(new StockHolding([
            'symbol' => 'AAPL',
            'exchange' => 'US',
            'instrument_type' => 'stock',
        ]));

        $this->assertSame('not_configured', $bundle['status']);
        $this->assertFalse($bundle['coverage_complete']);
        Http::assertNothingSent();
    }

    public function test_it_stops_after_an_authentication_failure_without_exposing_the_token(): void
    {
        config(['services.eodhd.key' => 'rejected-secret-token']);
        Http::preventStrayRequests();
        Http::fake([
            'eodhd.com/api/*' => Http::response([], 401),
        ]);
        $holding = new StockHolding([
            'symbol' => 'AMEE',
            'isin' => 'FR0010930644',
            'exchange' => 'XETRA',
            'instrument_type' => 'ETF',
        ]);

        $bundle = app(EodhdStockResearchData::class)->for($holding);

        $this->assertSame('unavailable', $bundle['status']);
        $this->assertSame('authentication_failed', $bundle['coverage'][0]['status']);
        $this->assertStringNotContainsString('rejected-secret-token', json_encode($bundle, JSON_THROW_ON_ERROR));
        Http::assertSentCount(1);
    }

    private function responseFor(Request $request): mixed
    {
        $url = $request->url();

        if (str_contains($url, '/id-mapping')) {
            return Http::response(['data' => [
                ['symbol' => 'AMEE.F', 'isin' => 'FR0010930644'],
                ['symbol' => 'AMEE.XETRA', 'isin' => 'FR0010930644'],
            ]]);
        }

        if (str_contains($url, '/v1.1/fundamentals/AMEE.XETRA')) {
            return Http::response([
                'General' => [
                    'Name' => 'Amundi Global Hydrogen UCITS ETF Acc',
                    'UpdatedAt' => '2026-08-15',
                ],
                'ETF_Data' => [
                    'ISIN' => 'FR0010930644',
                    'Company_Name' => 'Amundi',
                    'Company_URL' => 'https://www.amundi.com',
                    'ETF_URL' => 'https://www.amundietf.example/amee',
                    'Index_Name' => 'Hydrogen Index',
                    'Holdings_Count' => 40,
                    'Top_10_Holdings' => [
                        'AI.PA' => ['Name' => 'Air Liquide', 'Assets_%' => 6.7, 'Sector' => 'Materials'],
                        'BE.US' => ['Name' => 'Bloom Energy', 'Assets_%' => 8.4, 'Sector' => 'Industrials'],
                    ],
                ],
            ]);
        }

        if (str_contains($url, '/v1.1/fundamentals/BE.US')) {
            return Http::response($this->companyFundamentals(
                symbol: 'BE.US',
                name: 'Bloom Energy Corporation',
                country: 'US',
                cik: '0001664703',
            ));
        }

        if (str_contains($url, '/v1.1/fundamentals/AI.PA')) {
            return Http::response($this->companyFundamentals(
                symbol: 'AI.PA',
                name: 'Air Liquide SA',
                country: 'FR',
            ));
        }

        if (str_contains($url, '/calendar/earnings')) {
            return Http::response(['earnings' => [
                [
                    'code' => 'BE.US',
                    'report_date' => '2026-08-08',
                    'date' => '2026-06-30',
                    'actual' => 0.18,
                    'estimate' => 0.16,
                    'difference' => 0.02,
                    'percent' => 12.5,
                    'revenueActual' => 1200,
                    'revenueEstimate' => 1000,
                    'currency' => 'USD',
                ],
                [
                    'code' => 'AI.PA',
                    'report_date' => '2026-08-28',
                    'date' => '2026-06-30',
                    'actual' => null,
                    'estimate' => 3.2,
                    'currency' => 'EUR',
                ],
                ['code' => 'BE.US', 'report_date' => '2026-01-01', 'actual' => 0.1],
            ]]);
        }

        if (str_contains($url, '/calendar/trends')) {
            return Http::response(['trends' => [[
                [
                    'code' => 'BE.US',
                    'date' => '2026-09-30',
                    'period' => '0q',
                    'epsTrendCurrent' => '0.20',
                    'epsTrend30daysAgo' => '0.17',
                    'epsRevisionsUpLast30days' => '4',
                    'epsRevisionsDownLast30days' => '1',
                ],
            ]]]);
        }

        if (str_contains($url, '/news')) {
            $symbol = $request['s'];

            return $symbol === 'BE.US'
                ? Http::response([[
                    'date' => '2026-08-16T08:00:00+00:00',
                    'title' => 'Bloom reports quarterly results',
                    'content' => '<p>Bloom reported current quarterly results.</p>',
                    'link' => 'https://news.example/be-quarter',
                    'symbols' => ['BE.US'],
                    'tags' => ['EARNINGS'],
                    'sentiment' => ['polarity' => 0.4, 'pos' => 0.5, 'neu' => 0.4, 'neg' => 0.1],
                ]])
                : Http::response([]);
        }

        if (str_contains($url, '/sentiments')) {
            return Http::response([
                'BE.US' => [
                    ['date' => '2026-08-15', 'count' => 8, 'normalized' => 0.65],
                ],
                'AI.PA' => [],
            ]);
        }

        if (str_contains($url, '/eod/')) {
            return Http::response([
                [
                    'date' => '2026-08-15',
                    'open' => 29.1,
                    'high' => 31.4,
                    'low' => 28.9,
                    'close' => 31.05,
                    'adjusted_close' => 31.05,
                    'volume' => 1800000,
                ],
            ]);
        }

        if (str_contains($url, '/real-time/')) {
            return Http::response([
                [
                    'code' => 'AMEE.XETRA',
                    'timestamp' => 1786867200,
                    'close' => 121.4,
                    'previousClose' => 120.8,
                    'change_p' => 0.5,
                    'volume' => 2200,
                ],
                [
                    'code' => 'BE.US',
                    'timestamp' => 1786867200,
                    'close' => 31.05,
                    'previousClose' => 28.95,
                    'change_p' => 7.25,
                    'volume' => 1800000,
                ],
            ]);
        }

        if (str_contains($url, '/sec-filings/BE/form4')) {
            return Http::response(['data' => [[
                'accession_number' => '0001234567-26-000001',
                'filed_at' => '2026-08-15',
                'period_of_report' => '2026-08-14',
                'non_derivative' => [
                    [
                        'reporting_owner_name' => 'Example Director',
                        'is_director' => true,
                        'is_officer' => false,
                        'transaction_date' => '2026-08-14T00:00:00+00:00',
                        'transaction_code' => 'P',
                        'shares_amount' => 10000,
                        'price_per_share' => 29.5,
                        'total_value' => 295000,
                        'shares_owned_after' => 50000,
                    ],
                    ['transaction_code' => 'A'],
                ],
            ]]]);
        }

        return Http::response(['message' => 'Unexpected test request'], 500);
    }

    /**
     * @return array<string, mixed>
     */
    private function companyFundamentals(
        string $symbol,
        string $name,
        string $country,
        ?string $cik = null,
    ): array {
        return [
            'General' => [
                'Name' => $name,
                'CountryISO' => $country,
                'CurrencyCode' => $country === 'US' ? 'USD' : 'EUR',
                'CIK' => $cik,
                'WebURL' => "https://issuer.example/{$symbol}",
                'UpdatedAt' => '2026-08-15',
            ],
            'Highlights' => [
                'MostRecentQuarter' => '2026-06-30',
                'QuarterlyRevenueGrowthYOY' => 0.15,
                'QuarterlyEarningsGrowthYOY' => 0.21,
            ],
            'SharesStats' => [
                'SharesShort' => 1200000,
                'SharesShortPriorMonth' => 950000,
                'ShortPercentFloat' => 0.08,
            ],
            'Earnings' => [
                'History' => [
                    '2026-06-30' => [
                        'reportDate' => '2026-08-08',
                        'date' => '2026-06-30',
                        'epsActual' => 0.18,
                        'epsEstimate' => 0.16,
                        'surprisePercent' => 12.5,
                    ],
                ],
                'Trend' => ['Quarterly' => [
                    '2026-09-30' => [
                        'date' => '2026-09-30',
                        'period' => '0q',
                        'epsTrendCurrent' => '0.20',
                        'epsTrend30daysAgo' => '0.17',
                    ],
                ]],
            ],
            'Financials::Income_Statement::quarterly' => [
                '2026-06-30' => [
                    'date' => '2026-06-30',
                    'filing_date' => '2026-08-08',
                    'currency_symbol' => $country === 'US' ? 'USD' : 'EUR',
                    'totalRevenue' => '401000000',
                    'netIncome' => '22000000',
                ],
            ],
        ];
    }
}
