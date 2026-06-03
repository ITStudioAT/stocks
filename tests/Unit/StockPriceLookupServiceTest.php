<?php

namespace Tests\Unit;

use App\Ai\Agents\StockPriceResolver;
use App\Services\StockPriceLookupService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StockPriceLookupServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_it_uses_deterministic_source_content_without_ai_fallback(): void
    {
        $this->travelTo(Carbon::parse('2026-06-02 11:30:00'));
        Http::fake([
            'www.tradegate.de/*' => Http::response(
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
            'source_url' => 'https://www.tradegate.de/orderbuch.php?isin=DE000A0D8Q23',
        ]);

        $this->assertSame('41.420000', $result['price']);
        $this->assertSame('EUR', $result['currency']);
        $this->assertSame('2026-06-02 11:30:00', $result['fetched_at']->toDateTimeString());
        $this->assertSame('Previous verified source', $result['source']);
        $this->assertSame('https://www.tradegate.de/orderbuch.php?isin=DE000A0D8Q23', $result['source_url']);
        $this->assertSame('02.06.2026 13:25 CEST', $result['as_of']);
        $this->assertSame('Monday-Friday 09:00-17:30 Europe/Vienna', $result['trading_times']);

        StockPriceResolver::assertNeverPrompted();
    }

    public function test_it_returns_unavailable_instead_of_using_ai_when_deterministic_sources_fail(): void
    {
        $this->travelTo(Carbon::parse('2026-06-02 12:00:00'));
        Http::fake([
            'www.borsaitaliana.it/*' => Http::response('<html><body>No matching quote.</body></html>', 200, ['content-type' => 'text/html']),
            'www.tradegate.de/*' => Http::response('<html><body>No matching quote.</body></html>', 200, ['content-type' => 'text/html']),
            'www.justetf.com/*' => Http::response('<html><body>No matching quote.</body></html>', 200, ['content-type' => 'text/html']),
            'extraetf.com/*' => Http::response('<html><body>No matching quote.</body></html>', 200, ['content-type' => 'text/html']),
            'www.onvista.de/*' => Http::response('<html><body>No matching quote.</body></html>', 200, ['content-type' => 'text/html']),
        ]);
        StockPriceResolver::fake()->preventStrayPrompts();

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
        $this->assertSame('2026-06-02 12:00:00', $result['fetched_at']->toDateTimeString());
        $this->assertSame('Web market data', $result['source']);
        $this->assertNull($result['source_url']);
        $this->assertNull($result['as_of']);
        $this->assertNull($result['trading_times']);

        StockPriceResolver::assertNeverPrompted();
    }

    public function test_it_rejects_date_only_deterministic_prices_without_ai_fallback(): void
    {
        $this->travelTo(Carbon::parse('2026-06-03 12:00:00'));
        Http::fake([
            'www.tradegate.de/*' => Http::response(
                '<html><body>iShares ATX UCITS ETF (DE) ISIN DE000A0D8Q23 WKN A0D8Q2 Last price 26,99 EUR 03.06.2026 Trading hours Monday-Friday 09:00-17:30 Europe/Berlin</body></html>',
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
            'source_url' => 'https://www.tradegate.de/orderbuch.php?isin=DE000A0D8Q23',
        ]);

        $this->assertNull($result['price']);
        $this->assertSame('Web market data', $result['source']);

        StockPriceResolver::assertNeverPrompted();
    }
}
