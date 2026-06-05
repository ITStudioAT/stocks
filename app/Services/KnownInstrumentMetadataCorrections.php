<?php

namespace App\Services;

class KnownInstrumentMetadataCorrections
{
    /**
     * @var array<string, array{name?: string, wkn?: string, valor?: string, country?: string}>
     */
    private const CorrectionsByIsin = [
        'LU1900066462' => [
            'name' => 'Amundi MSCI Eastern Europe Ex Russia UCITS ETF Acc',
            'wkn' => 'LYX02C',
            'valor' => '45209801',
            'country' => 'Luxembourg',
        ],
        'FR0010405431' => [
            'name' => 'Amundi MSCI Greece UCITS ETF Dist',
            'wkn' => 'LYX0BF',
        ],
    ];

    /**
     * @var array<string, array{name: string, isin: string, wkn: ?string, valor: ?string, symbol: string, exchange: string, mic_code: ?string, instrument_type: string, country: ?string, currency: string}>
     */
    private const SearchFallbacksByIsin = [
        'LU1900066462' => [
            'name' => 'Amundi MSCI Eastern Europe Ex Russia UCITS ETF Acc',
            'isin' => 'LU1900066462',
            'wkn' => 'LYX02C',
            'valor' => '45209801',
            'symbol' => 'LEER',
            'exchange' => 'XETRA',
            'mic_code' => 'XETR',
            'instrument_type' => 'ETF',
            'country' => 'Luxembourg',
            'currency' => 'EUR',
        ],
        'FR0010405431' => [
            'name' => 'Amundi MSCI Greece UCITS ETF Dist',
            'isin' => 'FR0010405431',
            'wkn' => 'LYX0BF',
            'valor' => null,
            'symbol' => 'GRE',
            'exchange' => 'PA',
            'mic_code' => 'XPAR',
            'instrument_type' => 'ETF',
            'country' => 'France',
            'currency' => 'EUR',
        ],
    ];

    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, mixed>  $payload
     * @return array<TKey|string, mixed>
     */
    public function apply(array $payload): array
    {
        $isin = $payload['isin'] ?? null;

        if (! is_string($isin)) {
            return $payload;
        }

        $isin = strtoupper(trim($isin));

        if (! array_key_exists($isin, self::CorrectionsByIsin)) {
            return $payload;
        }

        return [
            ...$payload,
            ...self::CorrectionsByIsin[$isin],
        ];
    }

    /**
     * @return array<int, array{name: string, isin: string, wkn: ?string, valor: ?string, symbol: string, exchange: string, mic_code: ?string, instrument_type: string, country: ?string, currency: string}>
     */
    public function searchFallbacksForQuery(string $query): array
    {
        $query = strtoupper(trim($query));

        if ($query === '') {
            return [];
        }

        return collect(self::SearchFallbacksByIsin)
            ->filter(fn (array $payload): bool => in_array($query, array_filter([
                $payload['isin'],
                $payload['wkn'],
                $payload['valor'],
                $payload['symbol'],
            ]), true))
            ->values()
            ->all();
    }
}
