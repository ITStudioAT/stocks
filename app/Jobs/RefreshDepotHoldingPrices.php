<?php

namespace App\Jobs;

use App\Models\StockHolding;
use App\Services\DepotHoldingPriceRefreshProgress;
use App\Services\StockPriceLookupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RefreshDepotHoldingPrices implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $depotId,
        public string $refreshId,
    ) {}

    public function handle(
        StockPriceLookupService $stockPriceLookup,
        DepotHoldingPriceRefreshProgress $progress,
    ): void {
        $progress->markRunning($this->refreshId);

        StockHolding::query()
            ->where('depot_id', $this->depotId)
            ->orderBy('id')
            ->eachById(function (StockHolding $holding) use ($stockPriceLookup, $progress): void {
                $latestPriceData = $stockPriceLookup->latestPrice($this->instrumentPayload($holding));
                $updates = [
                    'trading_times' => $latestPriceData['trading_times'] ?? $holding->trading_times,
                ];

                if ($latestPriceData['price'] !== null) {
                    $updates = [
                        ...$updates,
                        'currency' => $latestPriceData['currency'] ?? $holding->currency,
                        'latest_price' => $latestPriceData['price'],
                        'latest_price_fetched_at' => $latestPriceData['fetched_at'],
                        'latest_price_source' => $latestPriceData['source'],
                        'latest_price_source_url' => $latestPriceData['source_url'],
                        'latest_price_as_of' => $latestPriceData['as_of'],
                    ];
                }

                $holding->update($updates);

                $progress->advance($this->refreshId, $holding->symbol ?? $holding->name);
            });

        $progress->finish($this->refreshId);
    }

    public function failed(?Throwable $exception): void
    {
        app(DepotHoldingPriceRefreshProgress::class)->fail(
            $this->refreshId,
            $exception?->getMessage() ?? 'Unknown queue failure.',
        );
    }

    /**
     * @return array{symbol: string, name: ?string, isin: ?string, wkn: ?string, exchange: ?string, mic_code: ?string, instrument_type: ?string, country: ?string, currency: ?string, source_url: ?string}
     */
    private function instrumentPayload(StockHolding $holding): array
    {
        return [
            'symbol' => $holding->symbol ?? '',
            'name' => $holding->name,
            'isin' => $holding->isin,
            'wkn' => $holding->wkn,
            'exchange' => $holding->exchange,
            'mic_code' => $holding->mic_code,
            'instrument_type' => $holding->instrument_type,
            'country' => $holding->country,
            'currency' => $holding->currency,
            'source_url' => $holding->latest_price_source_url,
        ];
    }
}
