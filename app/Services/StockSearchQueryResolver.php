<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
        $apiToken = config('services.eodhd.key');

        if (! is_string($apiToken) || trim($apiToken) === '') {
            return [];
        }

        try {
            $response = Http::baseUrl((string) config('services.eodhd.base_url', 'https://eodhd.com/api'))
                ->acceptJson()
                ->connectTimeout((int) config('services.eodhd.connect_timeout', 5))
                ->timeout((int) config('services.eodhd.timeout', 20))
                ->get('search/'.rawurlencode($query), [
                    'api_token' => $apiToken,
                    'fmt' => 'json',
                    'limit' => 15,
                    'type' => 'all',
                ]);

            if (! $response->ok()) {
                return [];
            }

            $candidates = $response->json();

            if (! is_array($candidates) || Arr::get($candidates, 'status') === 'error') {
                return [];
            }

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
            'name' => $this->nullableString(Arr::get($candidate, 'Name', Arr::get($candidate, 'name'))),
            'isin' => $this->nullableUpperString(Arr::get($candidate, 'ISIN', Arr::get($candidate, 'isin'))),
            'wkn' => $this->nullableUpperString(Arr::get($candidate, 'WKN', Arr::get($candidate, 'wkn'))),
            'valor' => $this->nullableUpperString(Arr::get($candidate, 'Valor', Arr::get($candidate, 'valor'))),
            'symbol' => $this->nullableUpperString(Arr::get($candidate, 'Code', Arr::get($candidate, 'symbol'))),
            'exchange' => $this->nullableUpperString(Arr::get($candidate, 'Exchange', Arr::get($candidate, 'exchange'))),
            'mic_code' => $this->micCodeForExchange(Arr::get($candidate, 'Exchange', Arr::get($candidate, 'mic_code'))),
            'instrument_type' => $this->nullableString(Arr::get($candidate, 'Type', Arr::get($candidate, 'instrument_type'))),
            'country' => $this->nullableString(Arr::get($candidate, 'Country', Arr::get($candidate, 'country'))),
            'currency' => $this->nullableUpperString(Arr::get($candidate, 'Currency', Arr::get($candidate, 'currency'))),
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

    private function micCodeForExchange(mixed $value): ?string
    {
        $exchange = $this->nullableUpperString($value);

        if ($exchange === null) {
            return null;
        }

        return match ($exchange) {
            'XETRA' => 'XETR',
            'NYSE' => 'XNYS',
            'NASDAQ' => 'XNAS',
            'SW' => 'XSWX',
            'VX' => 'XVTX',
            'VI' => 'XVIE',
            'PA' => 'XPAR',
            'MI' => 'XMIL',
            'MC' => 'XMAD',
            default => null,
        };
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
