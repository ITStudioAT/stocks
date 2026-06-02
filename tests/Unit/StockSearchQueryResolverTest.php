<?php

namespace Tests\Unit;

use App\Ai\Agents\StockIdentifierResolver;
use App\Services\StockSearchQueryResolver;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

class StockSearchQueryResolverTest extends TestCase
{
    public function test_it_resolves_portfolio_ready_candidates_with_the_ai_sdk(): void
    {
        Cache::flush();
        StockIdentifierResolver::fake([
            [
                'candidates' => [
                    [
                        'name' => 'Microsoft Corporation',
                        'isin' => 'us5949181045',
                        'wkn' => '870747',
                        'symbol' => 'msft',
                        'exchange' => 'NASDAQ',
                        'mic_code' => 'xnas',
                        'instrument_type' => 'Common Stock',
                        'country' => 'United States',
                        'currency' => 'usd',
                    ],
                ],
            ],
        ])->preventStrayPrompts();

        $candidates = app(StockSearchQueryResolver::class)->resolveCandidates('Microsoft');

        $this->assertSame('Microsoft Corporation', $candidates[0]['name']);
        $this->assertSame('US5949181045', $candidates[0]['isin']);
        $this->assertSame('870747', $candidates[0]['wkn']);
        $this->assertSame('MSFT', $candidates[0]['symbol']);
        $this->assertSame('NASDAQ', $candidates[0]['exchange']);
        $this->assertSame('XNAS', $candidates[0]['mic_code']);
        $this->assertSame('Common Stock', $candidates[0]['instrument_type']);
        $this->assertSame('United States', $candidates[0]['country']);
        $this->assertSame('USD', $candidates[0]['currency']);
        $this->assertSame(['Microsoft', 'US5949181045', 'MSFT', '870747'], $candidates[0]['search_terms']);

        StockIdentifierResolver::assertPrompted(fn ($prompt): bool => $prompt->contains('Microsoft')
            && $prompt->contains('portfolio-ready instrument identifiers'));
    }

    public function test_it_returns_no_candidates_for_blank_queries_without_prompting(): void
    {
        StockIdentifierResolver::fake()->preventStrayPrompts();

        $this->assertSame([], app(StockSearchQueryResolver::class)->resolveCandidates('  '));

        StockIdentifierResolver::assertNeverPrompted();
    }

    public function test_it_returns_no_candidates_when_the_ai_sdk_request_fails(): void
    {
        Cache::flush();
        StockIdentifierResolver::fake(fn (...$arguments): never => throw new RuntimeException('Provider unavailable'))
            ->preventStrayPrompts();

        $this->assertSame([], app(StockSearchQueryResolver::class)->resolveCandidates('Unknown'));
    }
}
