<?php

namespace App\Services;

use App\Ai\Agents\StockPriceResolver;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

class StockPriceLookupService
{
    public function __construct(
        private DeterministicStockPriceLookupService $deterministicStockPriceLookup,
    ) {}

    /**
     * @var array<int, string>
     */
    private const SOURCE_ORDER = [
        'Official page for the exact provided MIC or exchange when available; accept only an EUR quote for that exact venue',
        'Deutsche Boerse / Xetra / Boerse Frankfurt official pages or market-data pages for XETR, XFRA, German shares, ETFs, ETCs, and ETPs',
        'Vienna Stock Exchange official pages for XWBO or Austrian-listed shares, ETFs, funds, certificates, and warrants',
        'Euronext Live official pages for Amsterdam, Paris, Brussels, Dublin, Lisbon, Oslo, and Euronext Milan / Borsa Italiana EUR listings',
        'Borsa Italiana official pages, including ETFplus and Italian-listed shares or ETFs quoted in EUR',
        'Boerse Stuttgart official pages for Stuttgart-listed shares, ETFs, funds, bonds, and securitized derivatives quoted in EUR',
        'gettex / Boerse Muenchen official pages for shares, ETFs, funds, ETPs, and bonds; gettex generally quotes in EUR',
        'Tradegate Exchange official pages for shares, ETFs, ETPs, funds, and bonds quoted in EUR',
        'Quotrix / Boerse Duesseldorf official pages for shares, ETFs, ETCs, funds, and bonds quoted in EUR',
        'LS Exchange / Boerse Hamburg official pages for shares, ETFs, ETPs, and funds quoted in EUR',
        'European Investor Exchange / Boerse Hannover official pages for shares, ETFs, ETPs, and funds quoted in EUR',
        'Issuer or fund provider official pages for the exact ISIN, such as iShares, Vanguard, Xtrackers / DWS, Amundi, Invesco, VanEck, SPDR, UBS, or Lyxor; use only if the page clearly shows an EUR market price or EUR listing',
        'justETF pages for ETFs only, using the listing table or quote only when it clearly matches the ISIN, venue, ticker, and EUR currency',
        'finanzen.net ETF Kurs pages for ETFs when the result clearly matches the ISIN/WKN and shows a current EUR quote',
        'Major finance portals as a last resort, such as onvista, ARIVA, boersennews.de, stockanalysis.com, wallstreet-online, or MarketScreener, only when the page clearly matches the ISIN/WKN, venue, ticker, and EUR currency',
    ];

    /**
     * @param  array{symbol: string, name?: ?string, isin?: ?string, wkn?: ?string, exchange?: ?string, mic_code?: ?string, currency?: ?string, source_url?: ?string}  $instrument
     * @return array{price: ?string, currency: ?string, fetched_at: Carbon, source: string, source_url: ?string, as_of: ?string, trading_times: ?string}
     */
    public function latestPrice(array $instrument): array
    {
        $fetchedAt = now();
        $deterministicResult = $this->deterministicStockPriceLookup->latestPrice($instrument);

        if ($deterministicResult !== null) {
            return [
                'price' => $deterministicResult['price'],
                'currency' => $deterministicResult['currency'],
                'fetched_at' => $fetchedAt,
                'source' => $deterministicResult['source'],
                'source_url' => $deterministicResult['source_url'],
                'as_of' => $deterministicResult['as_of'],
                'trading_times' => $deterministicResult['trading_times'],
            ];
        }

        try {
            $response = StockPriceResolver::make()->prompt($this->prompt($instrument), timeout: 45);
        } catch (Throwable) {
            return $this->unavailableResult($fetchedAt);
        }

        $result = $this->resultFromResponse($response, $fetchedAt);

        if ($result['price'] !== null) {
            return $result;
        }

        try {
            $response = StockPriceResolver::make()->prompt($this->prompt($instrument, exhaustive: true), timeout: 60);
        } catch (Throwable) {
            return $result;
        }

        return $this->resultFromResponse($response, $fetchedAt);
    }

    /**
     * @return array{price: ?string, currency: ?string, fetched_at: Carbon, source: string, source_url: ?string, as_of: ?string, trading_times: ?string}
     */
    private function resultFromResponse(mixed $response, Carbon $fetchedAt): array
    {
        $price = $this->decimalPrice(Arr::get($response, 'decimal_price'));
        $currency = $this->nullableCurrency(Arr::get($response, 'currency'));
        $asOf = $this->nullableString(Arr::get($response, 'as_of'));

        if ($price === null || $currency !== 'EUR' || $this->isStaleAsOf($asOf)) {
            return $this->unavailableResult(
                fetchedAt: $fetchedAt,
                source: $this->nullableString(Arr::get($response, 'source_name')) ?? 'AI SDK web search',
                sourceUrl: $this->nullableUrl(Arr::get($response, 'source_url')),
                asOf: $asOf,
                tradingTimes: $this->nullableString(Arr::get($response, 'trading_times')),
            );
        }

        return [
            'price' => $price,
            'currency' => $currency,
            'fetched_at' => $fetchedAt,
            'source' => $this->nullableString(Arr::get($response, 'source_name')) ?? 'AI SDK web search',
            'source_url' => $this->nullableUrl(Arr::get($response, 'source_url')),
            'as_of' => $asOf,
            'trading_times' => $this->nullableString(Arr::get($response, 'trading_times')),
        ];
    }

    /**
     * @return array{price: null, currency: null, fetched_at: Carbon, source: string, source_url: ?string, as_of: ?string, trading_times: ?string}
     */
    private function unavailableResult(
        Carbon $fetchedAt,
        string $source = 'AI SDK web search',
        ?string $sourceUrl = null,
        ?string $asOf = null,
        ?string $tradingTimes = null,
    ): array {
        return [
            'price' => null,
            'currency' => null,
            'fetched_at' => $fetchedAt,
            'source' => $source,
            'source_url' => $sourceUrl,
            'as_of' => $asOf,
            'trading_times' => $tradingTimes,
        ];
    }

    /**
     * @param  array{symbol: string, name?: ?string, isin?: ?string, wkn?: ?string, exchange?: ?string, mic_code?: ?string, currency?: ?string, source_url?: ?string}  $instrument
     */
    private function prompt(array $instrument, bool $exhaustive = false): string
    {
        $details = collect([
            'Symbol' => $instrument['symbol'],
            'Name' => $instrument['name'] ?? null,
            'ISIN' => $instrument['isin'] ?? null,
            'WKN' => $instrument['wkn'] ?? null,
            'Exchange' => $instrument['exchange'] ?? null,
            'MIC' => $instrument['mic_code'] ?? null,
            'Currency' => $instrument['currency'] ?? null,
        ])
            ->filter(fn (?string $value): bool => filled($value))
            ->map(fn (string $value, string $label): string => "{$label}: {$value}")
            ->implode("\n");

        $sourceOrder = collect(self::SOURCE_ORDER)
            ->map(fn (string $source, int $index): string => ($index + 1).". {$source}")
            ->implode("\n");

        $exhaustiveInstructions = $exhaustive
            ? "This is an exhaustive retry because the first attempt did not return a fresh EUR price. Search deeper, but keep the same source order. Do not return null until every ordered source has been checked for a matching fresh EUR quote.\n\n"
            : '';

        return <<<PROMPT
Find the latest available market price for this exact holding.

{$details}

{$exhaustiveInstructions}Latest means the latest visible trade, last price, or official close available from the source. If markets are closed, use the most recent official close or latest available price and include its date/time in as_of.
Fresh means the visible price date is within the last 7 calendar days, or the source clearly shows a live/current quote without an old date. Reject stale quote pages, factsheets, PDFs, NAV-only pages, and ETF Capital pages unless the visible price date is fresh.

Check sources in this exact order every time:
{$sourceOrder}

Use the first source in that order that clearly identifies the exact same instrument and quotes a latest/current price in EUR. If a source has no matching EUR quote, continue to the next source.
Also retrieve the regular exchange trading times for the exact listing when visible or verifiable. Return trading_times as a short string such as "Monday-Friday 09:00-17:30 Europe/Vienna". Use the exchange's local timezone.
Return null for decimal_price if a fresh current/latest EUR price cannot be verified for this exact instrument.
Return null for decimal_price only after every ordered source has been checked and only stale, non-EUR, or unverifiable prices are available. Do not convert currencies.
Set currency to EUR when returning a price.
PROMPT;
    }

    private function decimalPrice(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $normalized = str_replace(',', '.', $value);

        if (! preg_match('/^\d+(\.\d+)?$/', $normalized)) {
            return null;
        }

        return number_format((float) $normalized, 6, '.', '');
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : Str::limit($value, 255, '');
    }

    private function nullableCurrency(mixed $value): ?string
    {
        $value = $this->nullableString($value);

        return $value === null ? null : Str::upper($value);
    }

    private function nullableUrl(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || ! Str::startsWith($value, ['http://', 'https://'])) {
            return null;
        }

        return Str::limit($value, 2048, '');
    }

    private function isStaleAsOf(?string $asOf): bool
    {
        if ($asOf === null) {
            return false;
        }

        $date = $this->dateFromAsOf($asOf);

        return $date !== null && $date->lt(now()->subDays(7));
    }

    private function dateFromAsOf(string $asOf): ?Carbon
    {
        if (preg_match('/\b(?<year>\d{4})-(?<month>\d{1,2})-(?<day>\d{1,2})\b/', $asOf, $matches)) {
            return Carbon::create((int) $matches['year'], (int) $matches['month'], (int) $matches['day'])->startOfDay();
        }

        if (! preg_match('/\b(?<day>\d{1,2})[.\/-](?<month>\d{1,2})[.\/-](?<year>\d{2,4})\b/', $asOf, $matches)) {
            return null;
        }

        $year = (int) $matches['year'];

        if ($year < 100) {
            $year += 2000;
        }

        return Carbon::create($year, (int) $matches['month'], (int) $matches['day'])->startOfDay();
    }
}
