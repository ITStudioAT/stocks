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
                'decimal_price' => '306.32001',
                'currency' => 'USD',
                'source_name' => 'Nasdaq',
                'source_url' => 'https://www.nasdaq.com/market-activity/stocks/aapl',
                'as_of' => '2026-06-02 11:59 UTC',
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

        $this->assertSame('306.320010', $result['price']);
        $this->assertSame('USD', $result['currency']);
        $this->assertSame('2026-06-02 12:00:00', $result['fetched_at']->toDateTimeString());
        $this->assertSame('Nasdaq', $result['source']);
        $this->assertSame('https://www.nasdaq.com/market-activity/stocks/aapl', $result['source_url']);
        $this->assertSame('2026-06-02 11:59 UTC', $result['as_of']);

        StockPriceResolver::assertPrompted(fn ($prompt): bool => $prompt->contains('AAPL')
            && $prompt->contains('US0378331005'));
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
        $this->assertSame('AI SDK web search', $result['source']);
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
