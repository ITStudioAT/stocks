<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class DeterministicStockPriceLookupService
{
    /**
     * @param  array{symbol: string, name?: ?string, isin?: ?string, wkn?: ?string, exchange?: ?string, mic_code?: ?string, currency?: ?string, source_url?: ?string}  $instrument
     * @return array{price: string, currency: 'EUR', source: string, source_url: string, as_of: ?string, trading_times: ?string}|null
     */
    public function latestPrice(array $instrument): ?array
    {
        foreach ($this->sourceCandidates($instrument) as $candidate) {
            if ($this->isSearchUrl($candidate['url'])) {
                continue;
            }

            $content = $this->fetch($candidate['url']);

            if ($content === null) {
                continue;
            }

            $result = $this->parseContent($content, $instrument);

            if ($result === null) {
                continue;
            }

            return [
                'price' => $result['price'],
                'currency' => 'EUR',
                'source' => $candidate['name'],
                'source_url' => $candidate['url'],
                'as_of' => $result['as_of'],
                'trading_times' => $result['trading_times'],
            ];
        }

        return null;
    }

    /**
     * @param  array{symbol: string, name?: ?string, isin?: ?string, wkn?: ?string, exchange?: ?string, mic_code?: ?string, currency?: ?string, source_url?: ?string}  $instrument
     * @return array<int, array{name: string, url: string}>
     */
    private function sourceCandidates(array $instrument): array
    {
        $isin = $this->identifier(Arr::get($instrument, 'isin'));
        $providedSourceUrl = $this->url(Arr::get($instrument, 'source_url'));

        $candidates = collect();

        if ($providedSourceUrl !== null && ! $this->isSearchUrl($providedSourceUrl)) {
            $candidates->push([
                'name' => 'Previous verified source',
                'url' => $providedSourceUrl,
            ]);
        }

        if ($isin !== null) {
            $encodedIsin = rawurlencode($isin);

            $candidates = $candidates->merge([
                [
                    'name' => 'Borsa Italiana',
                    'url' => "https://www.borsaitaliana.it/borsa/etf/listino-ufficiale.html?isin={$encodedIsin}&lang=en",
                ],
                [
                    'name' => 'Tradegate Exchange',
                    'url' => "https://www.tradegate.de/orderbuch.php?isin={$encodedIsin}",
                ],
                [
                    'name' => 'justETF',
                    'url' => "https://www.justetf.com/en/etf-profile.html?isin={$encodedIsin}",
                ],
            ]);
        }

        return $candidates
            ->unique('url')
            ->values()
            ->all();
    }

    private function fetch(string $url): ?string
    {
        try {
            $response = Http::accept('text/html,application/xhtml+xml,application/json')
                ->withUserAgent('Stocks Portfolio Price Lookup/1.0')
                ->connectTimeout(5)
                ->timeout(10)
                ->retry(1, 250)
                ->get($url);
        } catch (Throwable) {
            return null;
        }

        if (! $response->ok() || ! $this->isReadableResponse($response)) {
            return null;
        }

        $body = $response->body();

        return trim($body) === '' ? null : Str::limit($body, 1_000_000, '');
    }

    private function isReadableResponse(Response $response): bool
    {
        $contentType = Str::lower((string) $response->header('content-type'));

        return $contentType === ''
            || Str::contains($contentType, ['html', 'json', 'text', 'javascript']);
    }

    /**
     * @param  array{symbol: string, name?: ?string, isin?: ?string, wkn?: ?string, exchange?: ?string, mic_code?: ?string, currency?: ?string, source_url?: ?string}  $instrument
     * @return array{price: string, as_of: ?string, trading_times: ?string}|null
     */
    private function parseContent(string $content, array $instrument): ?array
    {
        $searchableContent = $this->searchableContent($content);

        if (! $this->containsInstrumentIdentifier($searchableContent, $instrument)) {
            return null;
        }

        if (! $this->containsEurCurrency($searchableContent)) {
            return null;
        }

        $price = $this->extractPrice($searchableContent);

        if ($price === null) {
            return null;
        }

        $asOf = $this->extractAsOf($searchableContent);

        if ($this->isStaleAsOf($asOf)) {
            return null;
        }

        return [
            'price' => $price,
            'as_of' => $asOf,
            'trading_times' => $this->extractTradingTimes($searchableContent),
        ];
    }

    private function searchableContent(string $content): string
    {
        $normalizedContent = str_replace("\xE2\x82\xAC", ' EUR ', $content);
        $withoutScripts = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', ' $1 ', $normalizedContent) ?? $normalizedContent;
        $withoutStyles = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $withoutScripts) ?? $withoutScripts;
        $decoded = html_entity_decode(strip_tags($withoutStyles), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = str_replace("\xE2\x82\xAC", ' EUR ', $decoded);

        return trim(preg_replace('/\s+/u', ' ', "{$decoded} {$normalizedContent}") ?? "{$decoded} {$normalizedContent}");
    }

    /**
     * @param  array{symbol: string, name?: ?string, isin?: ?string, wkn?: ?string, exchange?: ?string, mic_code?: ?string, currency?: ?string, source_url?: ?string}  $instrument
     */
    private function containsInstrumentIdentifier(string $content, array $instrument): bool
    {
        $upperContent = Str::upper($content);

        foreach (['isin', 'wkn'] as $key) {
            $identifier = $this->identifier(Arr::get($instrument, $key));

            if ($identifier !== null && Str::contains($upperContent, $identifier)) {
                return true;
            }
        }

        return false;
    }

    private function containsEurCurrency(string $content): bool
    {
        return Str::contains(Str::upper($content), [' EUR', 'EUR ', '"EUR"', "'EUR'", '&EURO;', 'EURO']);
    }

    private function extractPrice(string $content): ?string
    {
        foreach ($this->pricePatterns() as $pattern) {
            if (! preg_match($pattern, $content, $matches)) {
                continue;
            }

            $price = $this->decimalPrice($matches['price'] ?? null);

            if ($price !== null) {
                return $price;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function pricePatterns(): array
    {
        $price = '(?<price>\d{1,4}(?:[.,]\d{3})*(?:[.,]\d{1,6})|\d{1,6}(?:[.,]\d{1,6})?)';

        return [
            "/(?:last|latest|current|official|close|closing|trade|price|kurs|preis|last-price|lastPrice)[^0-9]{0,80}(?:&euro;|EUR|EURO)?\s*{$price}\s*(?:&euro;|EUR|EURO)\b/iu",
            "/(?:last|latest|current|official|close|closing|trade|price|kurs|preis|last-price|lastPrice)[^0-9]{0,80}(?:&euro;|EUR|EURO)\s*{$price}/iu",
            "/[\"'](?:last|latest|current|official|close|closing|trade|price|lastPrice|last_price)[\"']\s*:\s*[\"']?{$price}[\"']?[^{}]{0,160}[\"'](?:currency|currencyCode)[\"']\s*:\s*[\"']EUR[\"']/iu",
            "/[\"'](?:currency|currencyCode)[\"']\s*:\s*[\"']EUR[\"'][^{}]{0,160}[\"'](?:last|latest|current|official|close|closing|trade|price|lastPrice|last_price)[\"']\s*:\s*[\"']?{$price}[\"']?/iu",
            "/(?:&euro;|EUR|EURO)\s*{$price}\s*(?:last|latest|current|official|close|closing|trade|price|kurs|preis)?/iu",
        ];
    }

    private function extractTradingTimes(string $content): ?string
    {
        foreach ($this->tradingTimesPatterns() as $pattern) {
            if (! preg_match($pattern, $content, $matches)) {
                continue;
            }

            return $this->normalizeTradingTimes($matches['trading_times']);
        }

        return null;
    }

    private function normalizeTradingTimes(string $value): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/(?<trading_times>.*?(?:CET|CEST|UTC|Europe\/Vienna|local time))/iu', $value, $matches)) {
            return Str::limit(trim($matches['trading_times']), 255, '');
        }

        if (preg_match('/(?<trading_times>.*?\d{1,2}:\d{2}\s*(?:-|to|until|bis)\s*\d{1,2}:\d{2})/iu', $value, $matches)) {
            return Str::limit(trim($matches['trading_times']), 255, '');
        }

        return Str::limit($value, 255, '');
    }

    /**
     * @return array<int, string>
     */
    private function tradingTimesPatterns(): array
    {
        $days = '(?:Mon(?:day)?|Tue(?:sday)?|Wed(?:nesday)?|Thu(?:rsday)?|Fri(?:day)?|Sat(?:urday)?|Sun(?:day)?|Mo|Di|Mi|Do|Fr|Sa|So)';
        $timeRange = '\d{1,2}:\d{2}\s*(?:-|to|until|bis)\s*\d{1,2}:\d{2}';
        $timezone = '(?:CET|CEST|UTC|Europe\/Vienna|local time)';

        return [
            "/(?:trading hours|trading times|market hours|trading session|handelszeiten|boersenzeiten)[:\s-]*(?<trading_times>.{0,80}{$timeRange}.{0,80}(?:{$timezone})?)/iu",
            "/(?<trading_times>{$days}(?:\s*(?:-|to|until|bis)\s*{$days})?.{0,40}{$timeRange}.{0,40}(?:{$timezone})?)/iu",
            "/[\"'](?:tradingHours|trading_hours|tradingTimes|trading_times|marketHours|market_hours)[\"']\s*:\s*[\"'](?<trading_times>[^\"']{1,255})[\"']/iu",
        ];
    }

    private function extractAsOf(string $content): ?string
    {
        $dateTimePattern = '/(?<as_of>\b\d{1,2}[.\/-]\d{1,2}[.\/-]\d{2,4}(?:\s+\d{1,2}:\d{2}(?::\d{2})?)?(?:\s*(?:CET|CEST|UTC|Europe\/Vienna))?|\b\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:?\d{2})?)?)/iu';

        if (! preg_match($dateTimePattern, $content, $matches)) {
            return null;
        }

        return Str::limit(trim($matches['as_of']), 255, '');
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

    private function decimalPrice(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');
        $decimalSeparator = $lastComma !== false && ($lastDot === false || $lastComma > $lastDot) ? ',' : '.';
        $thousandsSeparator = $decimalSeparator === ',' ? '.' : ',';
        $normalized = str_replace($thousandsSeparator, '', $value);
        $normalized = str_replace(',', '.', $normalized);

        if (! preg_match('/^\d+(\.\d+)?$/', $normalized)) {
            return null;
        }

        return number_format((float) $normalized, 6, '.', '');
    }

    private function identifier(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = Str::upper(trim($value));

        return $value === '' ? null : $value;
    }

    private function url(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || ! Str::startsWith($value, ['http://', 'https://'])) {
            return null;
        }

        return $value;
    }

    private function isSearchUrl(string $url): bool
    {
        $parts = parse_url($url);
        $path = Str::lower((string) ($parts['path'] ?? ''));
        $query = Str::lower((string) ($parts['query'] ?? ''));

        return Str::contains($path, ['/search', '/suche', 'search_instruments'])
            || Str::contains($query, ['query=', 'q=', 'search=', 'searchvalue=']);
    }
}
