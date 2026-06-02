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
                'as_of' => '2026-06-02 20:09 Europe/Vienna',
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
                'as_of' => '2026-06-02 20:14 Europe/Vienna',
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
            && $prompt->contains('13. justETF pages')
            && $prompt->contains('14. finanzen.net ETF Kurs pages')
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
