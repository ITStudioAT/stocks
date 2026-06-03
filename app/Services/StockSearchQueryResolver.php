<?php

namespace App\Services;

use App\Ai\Agents\StockIdentifierResolver;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class StockSearchQueryResolver
{
    /**
     * @return array<int, string>
     */
    public function resolve(string $query): array
    {
        $query = trim($query);

        if ($query === '') {
            return [];
        }

        $cacheKey = $this->cacheKey($query);
        $cachedTerms = Cache::get($cacheKey);

        if (is_array($cachedTerms)) {
            return $cachedTerms;
        }

        $terms = $this->resolveFresh($query);

        if ($terms !== []) {
            Cache::put($cacheKey, $terms, now()->addDays(7));
        }

        return $terms;
    }

    /**
     * @return array<int, array{name: ?string, isin: ?string, wkn: ?string, valor: ?string, symbol: ?string, exchange: ?string, mic_code: ?string, instrument_type: ?string, country: ?string, currency: ?string, search_terms: array<int, string>}>
     */
    public function resolveCandidates(string $query): array
    {
        $query = trim($query);

        if ($query === '') {
            return [];
        }

        $cacheKey = $this->candidateCacheKey($query);
        $cachedCandidates = Cache::get($cacheKey);

        if (is_array($cachedCandidates)) {
            return $cachedCandidates;
        }

        $candidates = $this->resolveFreshCandidates($query);

        if ($candidates !== []) {
            Cache::put($cacheKey, $candidates, now()->addDays(7));
        }

        return $candidates;
    }

    /**
     * @return array<int, string>
     */
    private function resolveFresh(string $query): array
    {
        return collect($this->resolveCandidates($query))
            ->flatMap(fn (array $candidate): array => $candidate['search_terms'])
            ->prepend($query)
            ->unique(fn (string $value): string => Str::upper($value))
            ->take(8)
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{name: ?string, isin: ?string, wkn: ?string, valor: ?string, symbol: ?string, exchange: ?string, mic_code: ?string, instrument_type: ?string, country: ?string, currency: ?string, search_terms: array<int, string>}>
     */
    private function resolveFreshCandidates(string $query): array
    {
        try {
            $response = StockIdentifierResolver::make()->prompt($this->prompt($query), timeout: 30);
            $candidates = is_array($response['candidates'] ?? null)
                ? $response['candidates']
                : [];

            return collect($candidates)
                ->map(fn (array $candidate): array => $this->candidatePayload($query, $candidate))
                ->filter(fn (array $candidate): bool => $candidate['search_terms'] !== [])
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array{name: ?string, isin: ?string, wkn: ?string, valor: ?string, symbol: ?string, exchange: ?string, mic_code: ?string, instrument_type: ?string, country: ?string, currency: ?string, search_terms: array<int, string>}
     */
    private function candidatePayload(string $query, array $candidate): array
    {
        $payload = [
            'name' => $this->nullableString(Arr::get($candidate, 'name')),
            'isin' => $this->nullableUpperString(Arr::get($candidate, 'isin')),
            'wkn' => $this->nullableUpperString(Arr::get($candidate, 'wkn')),
            'valor' => $this->nullableUpperString(Arr::get($candidate, 'valor')),
            'symbol' => $this->nullableUpperString(Arr::get($candidate, 'symbol')),
            'exchange' => $this->nullableString(Arr::get($candidate, 'exchange')),
            'mic_code' => $this->nullableUpperString(Arr::get($candidate, 'mic_code')),
            'instrument_type' => $this->nullableString(Arr::get($candidate, 'instrument_type')),
            'country' => $this->nullableString(Arr::get($candidate, 'country')),
            'currency' => $this->nullableUpperString(Arr::get($candidate, 'currency')),
        ];

        $identifierTerms = collect([
            $payload['isin'],
            $payload['symbol'],
            $payload['wkn'],
            $payload['valor'],
        ])->filter();

        $nameTerms = collect([$payload['name']])->filter();

        $payload['search_terms'] = $identifierTerms
            ->when($identifierTerms->isEmpty(), fn ($terms) => $terms->merge($nameTerms))
            ->prepend($query)
            ->unique(fn (string $value): string => Str::upper($value))
            ->values()
            ->all();

        return $payload;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function nullableUpperString(mixed $value): ?string
    {
        $value = $this->nullableString($value);

        return $value === null ? null : Str::upper($value);
    }

    private function prompt(string $query): string
    {
        $queryContext = implode("\n", $this->queryContext($query));

        return <<<PROMPT
Resolve this stock, ETF, or fund search query to portfolio-ready instrument identifiers using public web sources.

Query: {$query}
Detected query context:
{$queryContext}

Search exact identifier queries with their identifier label:
- WKN: six-character German security code.
- ISIN: twelve-character international security identifier.
- Valor: Swiss numeric security identifier.
- Symbol or name: local ticker or instrument name.

Return likely identifiers for the exact same instrument only. Find the matching ISIN, WKN, Valor when available, exchange ticker symbols, exchanges, MIC codes, currency, country, instrument type, and official instrument name.
PROMPT;
    }

    /**
     * @return array<int, string>
     */
    private function queryContext(string $query): array
    {
        $normalizedQuery = Str::upper(trim($query));
        $context = [];

        if (preg_match('/^[A-Z]{2}[A-Z0-9]{9}[0-9]$/', $normalizedQuery) === 1) {
            $context[] = "- {$normalizedQuery} looks like an ISIN.";
        }

        if (preg_match('/^[A-Z0-9]{6}$/', $normalizedQuery) === 1) {
            $context[] = "- {$normalizedQuery} looks like a WKN or local ticker.";
        }

        if (preg_match('/^[0-9]{1,9}$/', $normalizedQuery) === 1) {
            $context[] = "- {$normalizedQuery} looks like a Valor number; if it is six digits, also check WKN.";
        }

        if ($context === []) {
            $context[] = '- Treat this as a ticker symbol or instrument name and resolve exact matching instruments.';
        }

        return $context;
    }

    private function cacheKey(string $query): string
    {
        return 'stock-search-query-resolver:'.sha1(Str::upper(trim($query)));
    }

    private function candidateCacheKey(string $query): string
    {
        return 'stock-search-query-resolver:candidates:'.sha1(Str::upper(trim($query)));
    }
}
