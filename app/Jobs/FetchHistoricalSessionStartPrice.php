<?php

namespace App\Jobs;

use App\Models\StockHolding;
use App\Services\EodhdMarketData;
use App\Services\HistoricalSessionStartPriceFetchStatus;
use App\Services\HistoricalSessionStartPriceLookup;
use App\Services\StockPriceCatalog;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class FetchHistoricalSessionStartPrice implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function __construct(
        public int $stockHoldingId,
        public string $from,
        public string $until,
    ) {}

    public function handle(
        HistoricalSessionStartPriceLookup $historicalSessionStartPriceLookup,
        StockPriceCatalog $stockPriceCatalog,
        HistoricalSessionStartPriceFetchStatus $historicalSessionStartPriceFetchStatus,
    ): void {
        $from = Carbon::parse($this->from)->utc();
        $until = Carbon::parse($this->until)->utc();
        $holding = StockHolding::query()->find($this->stockHoldingId);

        if (! $holding) {
            $historicalSessionStartPriceFetchStatus->finished($this->stockHoldingId, $from, $until);

            return;
        }

        $historicalSessionStartPriceFetchStatus->running($holding->id, $from, $until);

        if ($this->hasStoredPrice($holding, $stockPriceCatalog, $from, $until)) {
            $historicalSessionStartPriceFetchStatus->finished($holding->id, $from, $until);

            return;
        }

        $historicalSessionStartPriceLookup->startPrice($holding, $from, $until);
        $historicalSessionStartPriceFetchStatus->finished($holding->id, $from, $until);
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function uniqueId(): string
    {
        return "{$this->stockHoldingId}:{$this->from}:{$this->until}";
    }

    public function failed(?Throwable $exception): void
    {
        app(HistoricalSessionStartPriceFetchStatus::class)->failed(
            $this->stockHoldingId,
            Carbon::parse($this->from)->utc(),
            Carbon::parse($this->until)->utc(),
        );

        Log::warning('Historical session start price could not be fetched.', [
            'stock_holding_id' => $this->stockHoldingId,
            'from' => $this->from,
            'until' => $this->until,
            'exception' => $exception ? $exception::class : null,
            'message' => $exception?->getMessage(),
        ]);
    }

    private function hasStoredPrice(
        StockHolding $holding,
        StockPriceCatalog $stockPriceCatalog,
        Carbon $from,
        Carbon $until,
    ): bool {
        return $stockPriceCatalog
            ->pricesForHolding($holding)
            ->whereIn('source_key', EodhdMarketData::sourceKeys())
            ->whereNotNull('price')
            ->whereNotNull('as_of')
            ->where('as_of', '>=', $from)
            ->where('as_of', '<', $until)
            ->exists();
    }
}
