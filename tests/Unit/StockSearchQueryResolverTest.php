<?php

namespace Tests\Unit;

use App\Ai\Agents\StockIdentifierResolver;
use App\Services\StockSearchQueryResolver;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
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
                        'valor' => '951692',
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
        $this->assertSame('951692', $candidates[0]['valor']);
        $this->assertSame('MSFT', $candidates[0]['symbol']);
        $this->assertSame('NASDAQ', $candidates[0]['exchange']);
        $this->assertSame('XNAS', $candidates[0]['mic_code']);
        $this->assertSame('Common Stock', $candidates[0]['instrument_type']);
        $this->assertSame('United States', $candidates[0]['country']);
        $this->assertSame('USD', $candidates[0]['currency']);
        $this->assertSame(['Microsoft', 'US5949181045', 'MSFT', '870747', '951692'], $candidates[0]['search_terms']);

        StockIdentifierResolver::assertPrompted(fn ($prompt): bool => $prompt->contains('Microsoft')
            && $prompt->contains('portfolio-ready instrument identifiers'));
    }

    public function test_it_returns_no_candidates_for_blank_queries_without_prompting(): void
    {
        StockIdentifierResolver::fake()->preventStrayPrompts();

        $this->assertSame([], app(StockSearchQueryResolver::class)->resolveCandidates('  '));

        StockIdentifierResolver::assertNeverPrompted();
    }

    #[DataProvider('identifierQueryProvider')]
    public function test_it_enriches_prompts_for_identifier_queries(string $query, array $expectedPromptFragments): void
    {
        Cache::flush();
        StockIdentifierResolver::fake([
            [
                'candidates' => [
                    [
                        'name' => 'Resolved Instrument',
                        'isin' => 'LU1900066462',
                        'wkn' => 'LYX02C',
                        'valor' => '123456789',
                        'symbol' => 'LEER',
                        'exchange' => 'Xetra',
                        'mic_code' => 'XETR',
                        'instrument_type' => 'ETF',
                        'country' => 'Luxembourg',
                        'currency' => 'EUR',
                    ],
                ],
            ],
        ])->preventStrayPrompts();

        $candidates = app(StockSearchQueryResolver::class)->resolveCandidates($query);

        $this->assertSame('Resolved Instrument', $candidates[0]['name']);
        $this->assertSame('LU1900066462', $candidates[0]['isin']);
        $this->assertSame('LYX02C', $candidates[0]['wkn']);
        $this->assertSame('123456789', $candidates[0]['valor']);
        $this->assertSame('LEER', $candidates[0]['symbol']);

        StockIdentifierResolver::assertPrompted(function ($prompt) use ($expectedPromptFragments): bool {
            foreach ($expectedPromptFragments as $expectedPromptFragment) {
                if (! $prompt->contains($expectedPromptFragment)) {
                    return false;
                }
            }

            return true;
        });
    }

    public function test_it_returns_no_candidates_when_the_ai_sdk_request_fails(): void
    {
        Cache::flush();
        StockIdentifierResolver::fake(fn (...$arguments): never => throw new RuntimeException('Provider unavailable'))
            ->preventStrayPrompts();

        $this->assertSame([], app(StockSearchQueryResolver::class)->resolveCandidates('Unknown'));
    }

    /**
     * @return array<string, array{query: string, expectedPromptFragments: array<int, string>}>
     */
    public static function identifierQueryProvider(): array
    {
        return [
            'wkn' => [
                'query' => 'lyx0a6',
                'expectedPromptFragments' => ['LYX0A6 looks like a WKN or local ticker.', 'WKN: six-character German security code.'],
            ],
            'isin' => [
                'query' => 'lu1900066462',
                'expectedPromptFragments' => ['LU1900066462 looks like an ISIN.', 'ISIN: twelve-character international security identifier.'],
            ],
            'valor' => [
                'query' => '123456789',
                'expectedPromptFragments' => ['123456789 looks like a Valor number', 'Valor: Swiss numeric security identifier.'],
            ],
            'name' => [
                'query' => 'Amundi Eastern Europe',
                'expectedPromptFragments' => ['Treat this as a ticker symbol or instrument name', 'Symbol or name: local ticker or instrument name.'],
            ],
        ];
    }
}
