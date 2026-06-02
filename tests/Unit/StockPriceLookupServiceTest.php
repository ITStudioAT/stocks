<?php

namespace Tests\Unit;

use App\Ai\Agents\StockPriceResolver;
use App\Services\StockPriceLookupService;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

class StockPriceLookupServiceTest extends TestCase
{
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

        StockPriceResolver::assertPrompted(fn ($prompt): bool => $prompt->contains('AAPL')
            && $prompt->contains('US0378331005')
            && $prompt->contains('Check sources in this exact order every time')
            && $prompt->contains('1. Official page for the exact provided MIC or exchange')
            && $prompt->contains('2. Deutsche Boerse / Xetra / Boerse Frankfurt')
            && $prompt->contains('7. gettex / Boerse Muenchen')
            && $prompt->contains('13. justETF pages')
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
            ],
            [
                'decimal_price' => '41.42',
                'currency' => 'EUR',
                'source_name' => 'Vienna Stock Exchange',
                'source_url' => 'https://www.wienerborse.at/en/market-data/',
                'as_of' => '2026-06-02 14:10 Europe/Vienna',
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

        StockPriceResolver::assertPrompted(fn ($prompt): bool => $prompt->contains('exhaustive retry')
            && $prompt->contains('Do not return null until every ordered source has been checked'));
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
            ],
            [
                'decimal_price' => '306.40001',
                'currency' => 'USD',
                'source_name' => 'Nasdaq retry',
                'source_url' => 'https://www.nasdaq.com/market-activity/stocks/aapl',
                'as_of' => '2026-06-02 12:01 UTC',
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
            ],
            [
                'decimal_price' => null,
                'currency' => null,
                'source_name' => 'AI SDK exhaustive web search',
                'source_url' => null,
                'as_of' => null,
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
    }
}
