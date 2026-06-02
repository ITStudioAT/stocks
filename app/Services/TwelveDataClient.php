<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TwelveDataClient
{
    private string $baseUrl;

    private string $token;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.twelve_data.base_url'), '/');
        $this->token = (string) config('services.twelve_data.token');
    }

    /**
     * @param  array<int, string>  $symbols
     * @return array<string, string>
     */
    public function latestPrices(array $symbols): array
    {
        $symbols = $this->normalizeSymbols($symbols);
        $data = $this->get('price', [
            'symbol' => implode(',', $symbols),
        ]);

        if (count($symbols) === 1 && array_key_exists('price', $data)) {
            return [$symbols[0] => (string) $data['price']];
        }

        $prices = [];

        foreach ($symbols as $symbol) {
            if (! isset($data[$symbol]['price'])) {
                throw new RuntimeException("Twelve Data did not return a price for {$symbol}.");
            }

            $prices[$symbol] = (string) $data[$symbol]['price'];
        }

        return $prices;
    }

    public function latestPrice(string $symbol, ?string $exchange = null, ?string $micCode = null): string
    {
        $query = [
            'symbol' => $this->normalizeSymbol($symbol),
        ];

        if ($exchange !== null && trim($exchange) !== '') {
            $query['exchange'] = trim($exchange);
        }

        if ($micCode !== null && trim($micCode) !== '') {
            $query['mic_code'] = $this->normalizeSymbol($micCode);
        }

        $data = $this->get('price', $query);

        if (! isset($data['price'])) {
            throw new RuntimeException("Twelve Data did not return a price for {$query['symbol']}.");
        }

        return (string) $data['price'];
    }

    /**
     * @param  array<int, string>  $symbols
     * @return array<string, mixed>
     */
    public function quotes(array $symbols): array
    {
        $symbols = $this->normalizeSymbols($symbols);

        return $this->get('quote', [
            'symbol' => implode(',', $symbols),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function timeSeries(string $symbol, string $interval = '1day', int $outputSize = 30): array
    {
        return $this->get('time_series', [
            'symbol' => $this->normalizeSymbol($symbol),
            'interval' => $interval,
            'outputsize' => $outputSize,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function symbolSearch(string $query, int $limit = 10): array
    {
        $query = trim($query);

        if ($query === '') {
            throw new RuntimeException('A search query is required.');
        }

        $data = $this->get('symbol_search', [
            'symbol' => $query,
        ]);

        $results = $data['data'] ?? [];

        if (! is_array($results)) {
            throw new RuntimeException('Twelve Data returned an invalid symbol search response.');
        }

        return array_slice($results, 0, $limit);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $endpoint, array $query): array
    {
        $this->ensureConfigured();

        $data = $this->request()
            ->get($endpoint, [
                ...$query,
                'apikey' => $this->token,
            ])
            ->throw()
            ->json();

        if (! is_array($data)) {
            throw new RuntimeException('Twelve Data returned an invalid response.');
        }

        if (($data['status'] ?? null) === 'error') {
            throw new RuntimeException((string) ($data['message'] ?? 'Twelve Data request failed.'));
        }

        return $data;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->timeout(15)
            ->retry(2, 200);
    }

    private function ensureConfigured(): void
    {
        if ($this->token !== '') {
            return;
        }

        throw new RuntimeException('Twelve Data token is not configured.');
    }

    /**
     * @param  array<int, string>  $symbols
     * @return array<int, string>
     */
    private function normalizeSymbols(array $symbols): array
    {
        $normalizedSymbols = array_values(array_filter(
            array_map(fn (string $symbol): string => $this->normalizeSymbol($symbol), $symbols),
        ));

        if ($normalizedSymbols !== []) {
            return $normalizedSymbols;
        }

        throw new RuntimeException('At least one Twelve Data symbol is required.');
    }

    private function normalizeSymbol(string $symbol): string
    {
        return strtoupper(trim($symbol));
    }
}
