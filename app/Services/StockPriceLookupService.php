<?php

namespace App\Services;

use Illuminate\Support\Carbon;

class StockPriceLookupService
{
    public function __construct(
        private DeterministicStockPriceLookupService $deterministicStockPriceLookup,
    ) {}

    /**
     * @param  array{symbol: string, name?: ?string, isin?: ?string, wkn?: ?string, exchange?: ?string, mic_code?: ?string, currency?: ?string, source_url?: ?string}  $instrument
     * @return array{price: ?string, currency: ?string, fetched_at: Carbon, source: string, source_url: ?string, as_of: ?string, trading_times: ?string}
     */
    public function latestPrice(array $instrument): array
    {
        $fetchedAt = now();
        $deterministicResult = $this->deterministicStockPriceLookup->latestPrice($instrument);

        if ($deterministicResult === null) {
            return $this->unavailableResult($fetchedAt);
        }

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

    /**
     * @return array{price: null, currency: null, fetched_at: Carbon, source: string, source_url: null, as_of: null, trading_times: null}
     */
    private function unavailableResult(Carbon $fetchedAt): array
    {
        return [
            'price' => null,
            'currency' => null,
            'fetched_at' => $fetchedAt,
            'source' => 'Web market data',
            'source_url' => null,
            'as_of' => null,
            'trading_times' => null,
        ];
    }
}
