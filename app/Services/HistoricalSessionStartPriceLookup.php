<?php

namespace App\Services;

use App\Models\StockHolding;
use Illuminate\Support\Carbon;

class HistoricalSessionStartPriceLookup
{
    public function __construct(
        private EodhdMarketData $eodhdMarketData,
    ) {}

    public function startPrice(StockHolding $holding, Carbon $from, Carbon $until): ?string
    {
        return $this->eodhdMarketData->startPrice($holding, $from, $until);
    }
}
