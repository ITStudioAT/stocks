<?php

namespace Tests\Unit;

use App\Ai\Agents\StockPriceResolver;
use App\Services\StockPriceLookupService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class StockPriceLookupServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_it_uses_deterministic_source_content_before_the_ai_sdk_fallback(): void
    {
        $this->travelTo(Carbon::parse('2026-06-02 11:30:00'));
        Http::fake([
            'quotes.example.test/*' => Http::response(
                '<html><body>iShares ATX UCITS ETF (DE) ISIN DE000A0D8Q23 WKN A0D8Q2 Last price 41,42 EUR 02.06.2026 13:25 CEST Trading hours Monday-Friday 09:00-17:30 Europe/Vienna</body></html>',
                200,
                ['content-type' => 'text/html'],
            ),
        ]);
        StockPriceResolver::fake()->preventStrayPrompts();

        $result = app(StockPriceLookupService::class)->latestPrice([
            'symbol' => 'EXXX',
            'name' => 'iShares ATX UCITS ETF (DE)',
            'isin' => 'DE000A0D8Q23',
            'wkn' => 'A0D8Q2',
            'exchange' => 'XETRA',
            'mic_code' => 'XETR',
            'currency' => 'EUR',
            'source_url' => 'https://quotes.example.test/exxx',
        ]);

        $this->assertSame('41.420000', $result['price']);
        $this->assertSame('EUR', $result['currency']);
        $this->assertSame('2026-06-02 11:30:00', $result['fetched_at']->toDateTimeString());
        $this->assertSame('Previous verified source', $result['source']);
        $this->assertSame('https://quotes.example.test/exxx', $result['source_url']);
        $this->assertSame('02.06.2026 13:25 CEST', $result['as_of']);
        $this->assertSame('Monday-Friday 09:00-17:30 Europe/Vienna', $result['trading_times']);

        StockPriceResolver::assertNeverPrompted();
    }

    public function test_it_checks_the_euronext_live_gateway_for_saved_euronext_etf_sources(): void
    {
        $this->travelTo(Carbon::parse('2026-06-02 17:40:00'));
        Http::fake([
            'live.euronext.com/en/product/etfs/FR0010405431-XPAR' => Http::response(
                '<html><head><title>AMUNDI MSCI GREECE | FR0010405431 | Euronext exchange Live quotes</title></head><body>Trackers Amundi MSCI Greece UCITS ETF Dist ETF FR0010405431 XPAR Euronext Paris Live Euronext quotes</body></html>',
                200,
                ['content-type' => 'text/html'],
            ),
            'gateway.euronext.com/api/instrumentDetail*' => Http::response(
                '{"status":"0","instr":{"exchCode":"XPAR","cdStand":"FR0010405431","codifStand":"ISIN","shrtNm":"AMUNDI MSCI GREECE","currency":"EUR","mic":"XPAR","currInstrSess":{"dateTime":"20260602-17:35:04","lastPx":"2.5895","lastQty":"4.0"}}}',
                200,
                ['content-type' => 'application/json'],
            ),
        ]);
        StockPriceResolver::fake()->preventStrayPrompts();

        $result = app(StockPriceLookupService::class)->latestPrice([
            'symbol' => 'GRE',
            'name' => 'Amundi MSCI Greece UCITS ETF Dist',
            'isin' => 'FR0010405431',
            'wkn' => 'LYX0BF',
            'exchange' => 'Euronext Paris',
            'currency' => 'EUR',
            'source_url' => 'https://live.euronext.com/en/product/etfs/FR0010405431-XPAR',
        ]);

        $this->assertSame('2.589500', $result['price']);
        $this->assertSame('EUR', $result['currency']);
        $this->assertSame('Euronext Live', $result['source']);
        $this->assertSame('https://live.euronext.com/en/product/etfs/FR0010405431-XPAR', $result['source_url']);
        $this->assertSame('2026-06-02 17:35:04 Europe/Paris', $result['as_of']);
        $this->assertSame('Monday-Friday 09:00-17:30 Europe/Paris', $result['trading_times']);

        StockPriceResolver::assertNeverPrompted();
    }

    public function test_it_checks_the_justetf_austria_profile_for_etfs_by_isin(): void
    {
        $this->travelTo(Carbon::parse('2026-06-02 11:30:00'));
        Http::fake([
            'www.borsaitaliana.it/*' => Http::response('<html><body>No matching quote.</body></html>', 200, ['content-type' => 'text/html']),
            'www.tradegate.de/*' => Http::response('<html><body>No matching quote.</body></html>', 200, ['content-type' => 'text/html']),
            'www.justetf.com/at/*' => Http::response(
                '<html><body>Amundi MSCI Eastern Europe Ex Russia UCITS ETF Acc ISIN LU1900066462 WKN LYX02C Börsennotierung gettex EUR LEER Kurs 43,23 EUR Stand: 02.06.2026 13:11 Trading hours Monday-Friday 08:00-22:00 Europe/Berlin</body></html>',
                200,
                ['content-type' => 'text/html'],
            ),
        ]);
        StockPriceResolver::fake()->preventStrayPrompts();

        $result = app(StockPriceLookupService::class)->latestPrice([
            'symbol' => 'LEER',
            'name' => 'Amundi MSCI Eastern Europe Ex Russia UCITS ETF Acc',
            'isin' => 'LU1900066462',
            'wkn' => 'LYX02C',
            'exchange' => 'gettex',
            'currency' => 'EUR',
        ]);

        $this->assertSame('43.230000', $result['price']);
        $this->assertSame('EUR', $result['currency']);
        $this->assertSame('justETF Austria', $result['source']);
        $this->assertSame('https://www.justetf.com/at/etf-profile.html?isin=LU1900066462', $result['source_url']);
        $this->assertSame('02.06.2026 13:11', $result['as_of']);
        $this->assertSame('Monday-Friday 08:00-22:00 Europe/Berlin', $result['trading_times']);

        StockPriceResolver::assertNeverPrompted();
    }

    public function test_it_checks_the_finanzen_at_boersenplaetze_page_for_saved_finanzen_at_etf_sources(): void
    {
        $this->travelTo(Carbon::parse('2026-06-02 11:30:00'));
        Http::fake([
            'www.finanzen.at/etf/amundi-msci-greece-etf-fr0010405431' => Http::response(
                '<html><body>Amundi MSCI Greece UCITS ETF Dist WKN DE: LYX0BF ISIN: FR0010405431 No fresh quote here.</body></html>',
                200,
                ['content-type' => 'text/html'],
            ),
            'www.finanzen.at/etf/boersenplaetze/amundi-msci-greece-etf-fr0010405431' => Http::response(
                '<html><body>Börsenplätze Amundi MSCI Greece UCITS ETF Dist WKN LYX0BF ISIN FR0010405431 Börse Währung Letzter Zeit Datum gettex EUR Letzter 2,53 EUR Zeit 19:28:43 Datum 02.06.2026 Trading hours Monday-Friday 08:00-22:00 Europe/Berlin</body></html>',
                200,
                ['content-type' => 'text/html'],
            ),
        ]);
        StockPriceResolver::fake()->preventStrayPrompts();

        $result = app(StockPriceLookupService::class)->latestPrice([
            'symbol' => 'LYX0BF',
            'name' => 'Amundi MSCI Greece UCITS ETF Dist',
            'isin' => 'FR0010405431',
            'wkn' => 'LYX0BF',
            'exchange' => 'gettex',
            'currency' => 'EUR',
            'source_url' => 'https://www.finanzen.at/etf/amundi-msci-greece-etf-fr0010405431',
        ]);

        $this->assertSame('2.530000', $result['price']);
        $this->assertSame('EUR', $result['currency']);
        $this->assertSame('finanzen.at Boersenplaetze', $result['source']);
        $this->assertSame('https://www.finanzen.at/etf/boersenplaetze/amundi-msci-greece-etf-fr0010405431', $result['source_url']);
        $this->assertSame('02.06.2026', $result['as_of']);
        $this->assertSame('Monday-Friday 08:00-22:00 Europe/Berlin', $result['trading_times']);

        StockPriceResolver::assertNeverPrompted();
    }

    public function test_it_checks_the_extraetf_austria_profile_for_etfs_by_isin(): void
    {
        $this->travelTo(Carbon::parse('2026-06-02 11:30:00'));
        Http::fake([
            'www.borsaitaliana.it/*' => Http::response('<html><body>No matching quote.</body></html>', 200, ['content-type' => 'text/html']),
            'www.tradegate.de/*' => Http::response('<html><body>No matching quote.</body></html>', 200, ['content-type' => 'text/html']),
            'www.justetf.com/*' => Http::response('<html><body>No matching quote.</body></html>', 200, ['content-type' => 'text/html']),
            'extraetf.com/at/*' => Http::response(
                '<html><body>Amundi MSCI Greece UCITS ETF Dist ISIN FR0010405431 WKN LYX0BF Ticker GRE EUR Aktueller Kurs EUR 2,54 Stand: 02.06.2026 13:15 Trading hours Monday-Friday 08:00-22:00 Europe/Berlin</body></html>',
                200,
                ['content-type' => 'text/html'],
            ),
        ]);
        StockPriceResolver::fake()->preventStrayPrompts();

        $result = app(StockPriceLookupService::class)->latestPrice([
            'symbol' => 'LYX0BF',
            'name' => 'Amundi MSCI Greece UCITS ETF Dist',
            'isin' => 'FR0010405431',
            'wkn' => 'LYX0BF',
            'exchange' => 'gettex',
            'currency' => 'EUR',
        ]);

        $this->assertSame('2.540000', $result['price']);
        $this->assertSame('EUR', $result['currency']);
        $this->assertSame('extraETF Austria', $result['source']);
        $this->assertSame('https://extraetf.com/at/etf-profile/FR0010405431', $result['source_url']);
        $this->assertSame('02.06.2026 13:15', $result['as_of']);
        $this->assertSame('Monday-Friday 08:00-22:00 Europe/Berlin', $result['trading_times']);

        StockPriceResolver::assertNeverPrompted();
    }

    public function test_it_checks_the_structured_onvista_etf_quote_for_the_requested_exchange(): void
    {
        $this->travelTo(Carbon::parse('2026-06-03 05:47:00'));
        Http::fake([
            'www.borsaitaliana.it/*' => Http::response('<html><body>No matching quote.</body></html>', 200, ['content-type' => 'text/html']),
            'www.tradegate.de/*' => Http::response('<html><body>No matching quote.</body></html>', 200, ['content-type' => 'text/html']),
            'www.justetf.com/*' => Http::response('<html><body>No matching quote.</body></html>', 200, ['content-type' => 'text/html']),
            'extraetf.com/*' => Http::response('<html><body>No matching quote.</body></html>', 200, ['content-type' => 'text/html']),
            'www.onvista.de/etf/*' => Http::response(
                '<html><body><script id="__NEXT_DATA__" type="application/json">{"props":{"pageProps":{"data":{"snapshot":{"instrument":{"isin":"FR0010405431","wkn":"LYX0BF"},"quote":{"isoCurrency":"EUR","last":0.032,"datetimeLast":"2026-06-03T05:32:08.000+00:00","market":{"nameExchange":"Tradegate BSX","codeExchange":"GAT"}},"quoteList":{"list":[{"isoCurrency":"EUR","last":2.5895,"datetimeLast":"2026-06-02T15:35:52.910+00:00","market":{"nameExchange":"Xetra","codeExchange":"GER"}},{"isoCurrency":"EUR","last":2.6055,"datetimeLast":"2026-06-02T20:02:48.418+00:00","market":{"nameExchange":"Tradegate BSX","codeExchange":"GAT"}}]}}}}}}</script></body></html>',
                200,
                ['content-type' => 'text/html'],
            ),
        ]);
        StockPriceResolver::fake()->preventStrayPrompts();

        $result = app(StockPriceLookupService::class)->latestPrice([
            'symbol' => 'LYX0BF',
            'name' => 'Amundi MSCI Greece UCITS ETF Dist',
            'isin' => 'FR0010405431',
            'wkn' => 'LYX0BF',
            'exchange' => 'XETRA',
            'mic_code' => 'XETR',
            'currency' => 'EUR',
        ]);

        $this->assertSame('2.589500', $result['price']);
        $this->assertSame('EUR', $result['currency']);
        $this->assertSame('onvista ETF', $result['source']);
        $this->assertSame('https://www.onvista.de/etf/FR0010405431', $result['source_url']);
        $this->assertSame('2026-06-02 17:35:52 Europe/Berlin', $result['as_of']);
        $this->assertSame('Monday-Friday 09:00-17:30 Europe/Berlin', $result['trading_times']);

        StockPriceResolver::assertNeverPrompted();
    }

    public function test_it_does_not_accept_search_result_pages_as_deterministic_price_sources(): void
    {
        $this->travelTo(Carbon::parse('2026-06-02 20:10:00'));
        Http::fake([
            'www.boerse-frankfurt.de/*' => Http::response(
                '<html><body>ISIN DE000A0D8Q23 WKN A0D8Q2 Price 7.50 EUR 20.06.19</body></html>',
                200,
                ['content-type' => 'text/html'],
            ),
        ]);
        StockPriceResolver::fake([
            [
                'decimal_price' => '41.42',
                'currency' => 'EUR',
                'source_name' => 'AI SDK web search',
                'source_url' => 'https://www.wienerborse.at/en/market-data/',
                'as_of' => '2026-06-02 17:25 Europe/Vienna',
                'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Vienna',
            ],
        ])->preventStrayPrompts();

        $result = app(StockPriceLookupService::class)->latestPrice([
            'symbol' => 'EXXX',
            'name' => 'iShares ATX UCITS ETF (DE)',
            'isin' => 'DE000A0D8Q23',
            'wkn' => 'A0D8Q2',
            'exchange' => 'XETRA',
            'mic_code' => 'XETR',
            'currency' => 'EUR',
            'source_url' => 'https://www.boerse-frankfurt.de/en/search?query=DE000A0D8Q23',
        ]);

        $this->assertSame('41.420000', $result['price']);
        $this->assertSame('EUR', $result['currency']);
        $this->assertSame('AI SDK web search', $result['source']);
        $this->assertSame('https://www.wienerborse.at/en/market-data/', $result['source_url']);

        StockPriceResolver::assertPrompted(fn ($prompt): bool => $prompt->contains('DE000A0D8Q23'));
    }

    public function test_it_rejects_stale_dates_from_deterministic_source_content(): void
    {
        $this->travelTo(Carbon::parse('2026-06-02 20:15:00'));
        Http::fake([
            'quotes.example.test/*' => Http::response(
                '<html><body>iShares ATX UCITS ETF (DE) ISIN DE000A0D8Q23 WKN A0D8Q2 Last price 7.50 EUR 20.06.19</body></html>',
                200,
                ['content-type' => 'text/html'],
            ),
        ]);
        StockPriceResolver::fake([
            [
                'decimal_price' => '41.43',
                'currency' => 'EUR',
                'source_name' => 'AI SDK web search',
                'source_url' => 'https://www.wienerborse.at/en/market-data/',
                'as_of' => '2026-06-02 17:20 Europe/Vienna',
                'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Vienna',
            ],
        ])->preventStrayPrompts();

        $result = app(StockPriceLookupService::class)->latestPrice([
            'symbol' => 'EXXX',
            'name' => 'iShares ATX UCITS ETF (DE)',
            'isin' => 'DE000A0D8Q23',
            'wkn' => 'A0D8Q2',
            'exchange' => 'XETRA',
            'mic_code' => 'XETR',
            'currency' => 'EUR',
            'source_url' => 'https://quotes.example.test/exxx',
        ]);

        $this->assertSame('41.430000', $result['price']);
        $this->assertSame('EUR', $result['currency']);
        $this->assertSame('AI SDK web search', $result['source']);
        $this->assertSame('https://www.wienerborse.at/en/market-data/', $result['source_url']);

        StockPriceResolver::assertPrompted(fn ($prompt): bool => $prompt->contains('DE000A0D8Q23'));
    }

    public function test_it_uses_the_ai_sdk_web_resolver_for_latest_prices(): void
    {
        $this->travelTo(Carbon::parse('2026-06-02 12:00:00'));
        StockPriceResolver::fake([
            [
                'decimal_price' => '260.12001',
                'currency' => 'EUR',
                'source_name' => 'Boerse Frankfurt',
                'source_url' => 'https://www.boerse-frankfurt.de/equity/apple-inc',
                'as_of' => '2026-06-02 11:59 Europe/Vienna',
                'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
            ],
        ])->preventStrayPrompts();

        $result = app(StockPriceLookupService::class)->latestPrice([
            'symbol' => 'AAPL',
            'name' => 'Apple Inc.',
            'isin' => 'US0378331005',
            'wkn' => '865985',
            'exchange' => 'NASDAQ',
            'mic_code' => 'XNAS',
            'currency' => 'USD',
        ]);

        $this->assertSame('260.120010', $result['price']);
        $this->assertSame('EUR', $result['currency']);
        $this->assertSame('2026-06-02 12:00:00', $result['fetched_at']->toDateTimeString());
        $this->assertSame('Boerse Frankfurt', $result['source']);
        $this->assertSame('https://www.boerse-frankfurt.de/equity/apple-inc', $result['source_url']);
        $this->assertSame('2026-06-02 11:59 Europe/Vienna', $result['as_of']);
        $this->assertSame('Monday-Friday 09:00-17:30 Europe/Berlin', $result['trading_times']);

        StockPriceResolver::assertPrompted(fn ($prompt): bool => $prompt->contains('AAPL')
            && $prompt->contains('US0378331005')
            && $prompt->contains('Check sources in this exact order every time')
            && $prompt->contains('1. Official page for the exact provided MIC or exchange')
            && $prompt->contains('2. Deutsche Boerse / Xetra / Boerse Frankfurt')
            && $prompt->contains('7. gettex / Boerse Muenchen')
            && $prompt->contains('13. justETF Austria')
            && $prompt->contains('extraETF Austria ETF profile pages')
            && $prompt->contains('14. finanzen.at ETF pages')
            && $prompt->contains('Reject stale quote pages')
            && $prompt->contains('Set currency to EUR when returning a price'));
    }

    public function test_it_retries_exhaustively_when_the_first_attempt_does_not_find_an_eur_price(): void
    {
        $this->travelTo(Carbon::parse('2026-06-02 12:15:00'));
        StockPriceResolver::fake([
            [
                'decimal_price' => null,
                'currency' => null,
                'source_name' => 'AI SDK web search',
                'source_url' => null,
                'as_of' => null,
                'trading_times' => null,
            ],
            [
                'decimal_price' => '41.42',
                'currency' => 'EUR',
                'source_name' => 'Vienna Stock Exchange',
                'source_url' => 'https://www.wienerborse.at/en/market-data/',
                'as_of' => '2026-06-02 14:10 Europe/Vienna',
                'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Vienna',
            ],
        ])->preventStrayPrompts();

        $result = app(StockPriceLookupService::class)->latestPrice([
            'symbol' => 'EXXX',
            'name' => 'iShares ATX UCITS ETF (DE)',
            'isin' => 'DE000A0D8Q23',
            'wkn' => 'A0D8Q2',
            'exchange' => 'XETRA',
            'mic_code' => 'XETR',
            'currency' => 'EUR',
        ]);

        $this->assertSame('41.420000', $result['price']);
        $this->assertSame('EUR', $result['currency']);
        $this->assertSame('Vienna Stock Exchange', $result['source']);
        $this->assertSame('https://www.wienerborse.at/en/market-data/', $result['source_url']);
        $this->assertSame('2026-06-02 14:10 Europe/Vienna', $result['as_of']);
        $this->assertSame('Monday-Friday 09:00-17:30 Europe/Vienna', $result['trading_times']);

        StockPriceResolver::assertPrompted(fn ($prompt): bool => $prompt->contains('exhaustive retry')
            && $prompt->contains('Do not return null until every ordered source has been checked for a matching fresh EUR quote'));
    }

    public function test_it_retries_exhaustively_when_the_ai_sdk_returns_a_stale_eur_price(): void
    {
        $this->travelTo(Carbon::parse('2026-06-02 12:20:00'));
        StockPriceResolver::fake([
            [
                'decimal_price' => '114.045',
                'currency' => 'EUR',
                'source_name' => 'Old market-data PDF',
                'source_url' => 'https://example.com/old-market-data.pdf',
                'as_of' => '2024-07-31',
                'trading_times' => null,
            ],
            [
                'decimal_price' => '115.12',
                'currency' => 'EUR',
                'source_name' => 'Madrid Stock Exchange',
                'source_url' => 'https://www.bolsamadrid.es/market-data/',
                'as_of' => '2026-06-02 12:18 Europe/Madrid',
                'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Madrid',
            ],
        ])->preventStrayPrompts();

        $result = app(StockPriceLookupService::class)->latestPrice([
            'symbol' => 'LYXIB',
            'name' => 'Amundi IBEX 35 UCITS ETF Dist',
            'isin' => 'FR0010251744',
            'wkn' => 'LYX0A6',
            'exchange' => 'Madrid',
            'currency' => 'EUR',
        ]);

        $this->assertSame('115.120000', $result['price']);
        $this->assertSame('EUR', $result['currency']);
        $this->assertSame('Madrid Stock Exchange', $result['source']);
        $this->assertSame('https://www.bolsamadrid.es/market-data/', $result['source_url']);
        $this->assertSame('2026-06-02 12:18 Europe/Madrid', $result['as_of']);

        StockPriceResolver::assertPrompted(fn ($prompt): bool => $prompt->contains('exhaustive retry'));
    }

    public function test_it_accepts_previous_business_day_prices_before_the_market_opens(): void
    {
        $this->travelTo(Carbon::parse('2026-06-03 04:30:00'));
        StockPriceResolver::fake([
            [
                'decimal_price' => '66.72',
                'currency' => 'EUR',
                'source_name' => 'Boerse Frankfurt',
                'source_url' => 'https://www.boerse-frankfurt.de/etf/example',
                'as_of' => '2026-06-02 17:25 Europe/Berlin',
                'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
            ],
        ])->preventStrayPrompts();

        $result = app(StockPriceLookupService::class)->latestPrice([
            'symbol' => 'EXXX',
            'name' => 'iShares ATX UCITS ETF (DE)',
            'isin' => 'DE000A0D8Q23',
            'wkn' => 'A0D8Q2',
            'exchange' => 'XETRA',
            'mic_code' => 'XETR',
            'currency' => 'EUR',
        ]);

        $this->assertSame('66.720000', $result['price']);
        $this->assertSame('2026-06-02 17:25 Europe/Berlin', $result['as_of']);

        StockPriceResolver::assertPrompted(fn ($prompt): bool => $prompt->contains('Current date/time:')
            && $prompt->contains('current exchange trading day'));
    }

    public function test_it_retries_previous_business_day_prices_after_the_market_opens(): void
    {
        $this->travelTo(Carbon::parse('2026-06-03 08:30:00'));
        StockPriceResolver::fake([
            [
                'decimal_price' => '66.72',
                'currency' => 'EUR',
                'source_name' => 'Boerse Frankfurt',
                'source_url' => 'https://www.boerse-frankfurt.de/etf/example',
                'as_of' => '2026-06-02 17:25 Europe/Berlin',
                'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
            ],
            [
                'decimal_price' => '66.91',
                'currency' => 'EUR',
                'source_name' => 'Boerse Frankfurt',
                'source_url' => 'https://www.boerse-frankfurt.de/etf/example',
                'as_of' => '2026-06-03 10:25 Europe/Berlin',
                'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
            ],
        ])->preventStrayPrompts();

        $result = app(StockPriceLookupService::class)->latestPrice([
            'symbol' => 'EXXX',
            'name' => 'iShares ATX UCITS ETF (DE)',
            'isin' => 'DE000A0D8Q23',
            'wkn' => 'A0D8Q2',
            'exchange' => 'XETRA',
            'mic_code' => 'XETR',
            'currency' => 'EUR',
        ]);

        $this->assertSame('66.910000', $result['price']);
        $this->assertSame('2026-06-03 10:25 Europe/Berlin', $result['as_of']);

        StockPriceResolver::assertPrompted(fn ($prompt): bool => $prompt->contains('exhaustive retry'));
    }

    public function test_it_retries_when_a_price_has_no_source_time(): void
    {
        $this->travelTo(Carbon::parse('2026-06-02 12:40:00'));
        StockPriceResolver::fake([
            [
                'decimal_price' => '43.37',
                'currency' => 'EUR',
                'source_name' => 'Borsa Italiana',
                'source_url' => 'https://www.borsaitaliana.it/example',
                'as_of' => null,
                'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Rome',
            ],
            [
                'decimal_price' => '43.41',
                'currency' => 'EUR',
                'source_name' => 'Borsa Italiana',
                'source_url' => 'https://www.borsaitaliana.it/example',
                'as_of' => '2026-06-02 14:39 Europe/Rome',
                'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Rome',
            ],
        ])->preventStrayPrompts();

        $result = app(StockPriceLookupService::class)->latestPrice([
            'symbol' => 'LEER',
            'name' => 'Amundi MSCI Eastern Europe Ex Russia UCITS ETF Acc',
            'isin' => 'LU1681043912',
            'exchange' => 'Borsa Italiana',
            'currency' => 'EUR',
        ]);

        $this->assertSame('43.410000', $result['price']);
        $this->assertSame('2026-06-02 14:39 Europe/Rome', $result['as_of']);

        StockPriceResolver::assertPrompted(fn ($prompt): bool => $prompt->contains('exhaustive retry'));
    }

    public function test_it_retries_source_times_outside_regular_trading_hours(): void
    {
        $this->travelTo(Carbon::parse('2026-06-03 04:30:00'));
        StockPriceResolver::fake([
            [
                'decimal_price' => '66.72',
                'currency' => 'EUR',
                'source_name' => 'Manager Magazin',
                'source_url' => 'https://www.manager-magazin.de/markets/example',
                'as_of' => '2026-06-02 22:59:14',
                'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
            ],
            [
                'decimal_price' => '66.75',
                'currency' => 'EUR',
                'source_name' => 'Boerse Frankfurt',
                'source_url' => 'https://www.boerse-frankfurt.de/etf/example',
                'as_of' => '2026-06-02 17:20 Europe/Berlin',
                'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Berlin',
            ],
        ])->preventStrayPrompts();

        $result = app(StockPriceLookupService::class)->latestPrice([
            'symbol' => 'EXXX',
            'name' => 'iShares ATX UCITS ETF (DE)',
            'isin' => 'DE000A0D8Q23',
            'wkn' => 'A0D8Q2',
            'exchange' => 'XETRA',
            'mic_code' => 'XETR',
            'currency' => 'EUR',
        ]);

        $this->assertSame('66.750000', $result['price']);
        $this->assertSame('Boerse Frankfurt', $result['source']);
        $this->assertSame('2026-06-02 17:20 Europe/Berlin', $result['as_of']);

        StockPriceResolver::assertPrompted(fn ($prompt): bool => $prompt->contains('exhaustive retry'));
    }

    public function test_it_records_unavailable_when_the_ai_sdk_returns_a_non_eur_price(): void
    {
        $this->travelTo(Carbon::parse('2026-06-02 12:30:00'));
        StockPriceResolver::fake([
            [
                'decimal_price' => '306.32001',
                'currency' => 'USD',
                'source_name' => 'Nasdaq',
                'source_url' => 'https://www.nasdaq.com/market-activity/stocks/aapl',
                'as_of' => '2026-06-02 11:59 UTC',
                'trading_times' => 'Monday-Friday 09:30-16:00 America/New_York',
            ],
            [
                'decimal_price' => '306.40001',
                'currency' => 'USD',
                'source_name' => 'Nasdaq retry',
                'source_url' => 'https://www.nasdaq.com/market-activity/stocks/aapl',
                'as_of' => '2026-06-02 12:01 UTC',
                'trading_times' => 'Monday-Friday 09:30-16:00 America/New_York',
            ],
        ])->preventStrayPrompts();

        $result = app(StockPriceLookupService::class)->latestPrice([
            'symbol' => 'AAPL',
            'name' => 'Apple Inc.',
            'isin' => 'US0378331005',
            'wkn' => '865985',
            'exchange' => 'NASDAQ',
            'mic_code' => 'XNAS',
            'currency' => 'USD',
        ]);

        $this->assertNull($result['price']);
        $this->assertNull($result['currency']);
        $this->assertSame('2026-06-02 12:30:00', $result['fetched_at']->toDateTimeString());
        $this->assertSame('Nasdaq retry', $result['source']);
        $this->assertSame('https://www.nasdaq.com/market-activity/stocks/aapl', $result['source_url']);
        $this->assertSame('2026-06-02 12:01 UTC', $result['as_of']);
        $this->assertSame('Monday-Friday 09:30-16:00 America/New_York', $result['trading_times']);
    }

    public function test_it_records_unavailable_when_the_ai_sdk_does_not_return_a_price(): void
    {
        $this->travelTo(Carbon::parse('2026-06-02 13:00:00'));
        StockPriceResolver::fake([
            [
                'decimal_price' => null,
                'currency' => null,
                'source_name' => 'AI SDK web search',
                'source_url' => null,
                'as_of' => null,
                'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Vienna',
            ],
            [
                'decimal_price' => null,
                'currency' => null,
                'source_name' => 'AI SDK exhaustive web search',
                'source_url' => null,
                'as_of' => null,
                'trading_times' => 'Monday-Friday 09:00-17:30 Europe/Vienna',
            ],
        ])->preventStrayPrompts();

        $result = app(StockPriceLookupService::class)->latestPrice([
            'symbol' => 'EXXX',
            'name' => 'iShares ATX UCITS ETF (DE)',
            'isin' => 'DE000A0D8Q23',
            'wkn' => 'A0D8Q2',
            'exchange' => 'XETRA',
            'mic_code' => 'XETR',
            'currency' => 'EUR',
        ]);

        $this->assertNull($result['price']);
        $this->assertNull($result['currency']);
        $this->assertSame('2026-06-02 13:00:00', $result['fetched_at']->toDateTimeString());
        $this->assertSame('AI SDK exhaustive web search', $result['source']);
        $this->assertNull($result['source_url']);
        $this->assertNull($result['as_of']);
        $this->assertSame('Monday-Friday 09:00-17:30 Europe/Vienna', $result['trading_times']);
    }

    public function test_it_records_unavailable_when_the_ai_sdk_request_fails(): void
    {
        $this->travelTo(Carbon::parse('2026-06-02 14:00:00'));
        StockPriceResolver::fake(fn (...$arguments): never => throw new RuntimeException('Provider unavailable'))
            ->preventStrayPrompts();

        $result = app(StockPriceLookupService::class)->latestPrice([
            'symbol' => 'AAPL',
        ]);

        $this->assertNull($result['price']);
        $this->assertNull($result['currency']);
        $this->assertSame('2026-06-02 14:00:00', $result['fetched_at']->toDateTimeString());
        $this->assertSame('AI SDK web search', $result['source']);
        $this->assertNull($result['trading_times']);
    }
}
