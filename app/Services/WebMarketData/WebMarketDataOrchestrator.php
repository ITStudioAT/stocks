<?php

namespace App\Services\WebMarketData;

use App\Models\StockHolding;
use App\Services\EodhdMarketData;
use App\Services\WebMarketData\DTO\QuoteSelectionResult;

class WebMarketDataOrchestrator
{
    public function __construct(
        private EodhdMarketData $eodhdMarketData,
    ) {}

    public function resolve(StockHolding $holding): QuoteSelectionResult
    {
        return $this->eodhdMarketData->resolve($holding);
    }
}
