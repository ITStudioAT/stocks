<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;

class StockSearchQueryResolver
{
    public function __construct(
        private EodhdApiClient $apiClient,
        private KnownInstrumentMetadataCorrections $metadataCorrections,
    ) {}

    /**
     * @return array<int, string>
     */
    public function resolve(string $query): array
    {
        $query = trim($query);

        if ($query === '') {
            return [];
        }

        return $this->resolveFresh($query);
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

        return $this->resolveFreshCandidates($query);
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
        $fallbackCandidates = $this->knownSearchFallbackCandidates($query);

        if (! $this->apiClient->configured()) {
            return $fallbackCandidates;
        }

        try {
            $response = $this->apiClient->get('search/'.rawurlencode($query), [
                'fmt' => 'json',
                'limit' => 15,
                'type' => 'all',
            ]);

            if (! $response->ok()) {
                return $fallbackCandidates;
            }

            $candidates = $response->json();

            if (! is_array($candidates) || Arr::get($candidates, 'status') === 'error') {
                return $fallbackCandidates;
            }

            $searchCandidates = collect($candidates)
                ->map(fn (array $candidate): array => $this->candidatePayload($query, $candidate))
                ->filter(fn (array $candidate): bool => $candidate['search_terms'] !== [])
                ->values()
                ->all();

            $indexCandidates = $this->shouldSearchIndexCandidates($query)
                ? $this->resolveIndexCandidates($query)
                : [];

            return collect($fallbackCandidates)
                ->merge($searchCandidates)
                ->merge($indexCandidates)
                ->unique(fn (array $candidate): string => implode('|', [
                    $candidate['symbol'] ?? '',
                    $candidate['exchange'] ?? '',
                    $candidate['isin'] ?? '',
                ]))
                ->values()
                ->all();
        } catch (Throwable) {
            return $fallbackCandidates;
        }
    }

    /**
     * @return array<int, array{name: ?string, isin: ?string, wkn: ?string, valor: ?string, symbol: ?string, exchange: ?string, mic_code: ?string, instrument_type: ?string, country: ?string, currency: ?string, search_terms: array<int, string>}>
     */
    private function knownSearchFallbackCandidates(string $query): array
    {
        return collect($this->metadataCorrections->searchFallbacksForQuery($query))
            ->map(fn (array $candidate): array => $this->candidatePayload($query, $candidate))
            ->values()
            ->all();
    }

    private function shouldSearchIndexCandidates(string $query): bool
    {
        $query = trim($query);
        $normalizedQuery = Str::upper($query);

        if ($query === '') {
            return false;
        }

        if (preg_match('/^[A-Z0-9]{1,16}\.INDX$/', $normalizedQuery) === 1) {
            return true;
        }

        if (! ctype_alnum($query)) {
            return false;
        }

        if (preg_match('/^[A-Z]{2}[A-Z0-9]{10}$/', $normalizedQuery) === 1) {
            return true;
        }

        if (ctype_digit($query)) {
            return true;
        }

        return $query === $normalizedQuery && ctype_alpha($query) && strlen($query) <= 8;
    }

    /**
     * @return array<int, array{name: ?string, isin: ?string, wkn: ?string, valor: ?string, symbol: ?string, exchange: ?string, mic_code: ?string, instrument_type: ?string, country: ?string, currency: ?string, search_terms: array<int, string>}>
     */
    private function resolveIndexCandidates(string $query): array
    {
        try {
            $response = $this->apiClient->get('exchange-symbol-list/INDX', [
                'fmt' => 'json',
            ]);

            if (! $response->ok()) {
                return [];
            }

            $candidates = $response->json();

            if (! is_array($candidates) || Arr::get($candidates, 'status') === 'error') {
                return [];
            }

            return collect($candidates)
                ->filter(fn (array $candidate): bool => $this->candidateMatchesQuery($candidate, $query))
                ->map(fn (array $candidate): array => $this->candidatePayload($query, $candidate))
                ->filter(fn (array $candidate): bool => $candidate['search_terms'] !== [])
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    private function candidateMatchesQuery(array $candidate, string $query): bool
    {
        $needle = Str::upper(trim($query));

        if ($needle === '') {
            return false;
        }

        return collect([
            Arr::get($candidate, 'Code'),
            $this->eodhdCodeForCandidate($candidate),
            Arr::get($candidate, 'Name'),
            Arr::get($candidate, 'ISIN'),
            Arr::get($candidate, 'Isin'),
            Arr::get($candidate, 'isin'),
        ])
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->contains(fn (string $value): bool => str_contains(Str::upper($value), $needle));
    }

    private function eodhdCodeForCandidate(array $candidate): ?string
    {
        $symbol = $this->nullableUpperString(Arr::get($candidate, 'Code', Arr::get($candidate, 'symbol')));
        $exchange = $this->nullableUpperString(Arr::get($candidate, 'Exchange', Arr::get($candidate, 'exchange')));

        if ($symbol === null || $exchange === null) {
            return null;
        }

        return "{$symbol}.{$exchange}";
    }

    /**
     * @return array{name: ?string, isin: ?string, wkn: ?string, valor: ?string, symbol: ?string, exchange: ?string, mic_code: ?string, instrument_type: ?string, country: ?string, currency: ?string, search_terms: array<int, string>}
     */
    private function candidatePayload(string $query, array $candidate): array
    {
        $payload = [
            'name' => $this->nullableString(Arr::get($candidate, 'Name', Arr::get($candidate, 'name'))),
            'isin' => $this->nullableUpperString(Arr::get($candidate, 'ISIN', Arr::get($candidate, 'Isin', Arr::get($candidate, 'isin')))),
            'wkn' => $this->nullableUpperString(Arr::get($candidate, 'WKN', Arr::get($candidate, 'wkn'))),
            'valor' => $this->nullableUpperString(Arr::get($candidate, 'Valor', Arr::get($candidate, 'valor'))),
            'symbol' => $this->nullableUpperString(Arr::get($candidate, 'Code', Arr::get($candidate, 'symbol'))),
            'exchange' => $this->nullableUpperString(Arr::get($candidate, 'Exchange', Arr::get($candidate, 'exchange'))),
            'mic_code' => $this->micCodeForCandidate($candidate),
            'instrument_type' => $this->nullableString(Arr::get($candidate, 'Type', Arr::get($candidate, 'instrument_type'))),
            'country' => $this->nullableString(Arr::get($candidate, 'Country', Arr::get($candidate, 'country'))),
            'currency' => $this->nullableUpperString(Arr::get($candidate, 'Currency', Arr::get($candidate, 'currency'))),
        ];

        $payload = $this->metadataCorrections->apply($payload);

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

    private function micCodeForCandidate(array $candidate): ?string
    {
        $micCode = $this->nullableUpperString(Arr::get($candidate, 'mic_code'));

        if ($micCode !== null) {
            return $micCode;
        }

        return $this->micCodeForExchange(Arr::get($candidate, 'Exchange', Arr::get($candidate, 'exchange')));
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
}
