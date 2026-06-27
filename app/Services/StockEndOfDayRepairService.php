<?php

namespace App\Services;

use App\Models\StockHolding;
use App\Models\StockPrice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class StockEndOfDayRepairService
{
    public function __construct(
        private EodhdEndOfDayDataService $endOfDayDataService,
        private StockPriceCatalog $stockPriceCatalog,
    ) {}

    /**
     * @return array{
     *     minimum_date: string,
     *     actual_date: ?string,
     *     total_stocks_count: int,
     *     covered_stocks_count: int,
     *     missing_stocks_count: int,
     *     missing_stocks: array<int, array{id: int, label: string}>,
     * }
     */
    public function summary(): array
    {
        $minimumDateEnd = now()->subYear()->endOfDay();
        $instrumentKeys = $this->holdings()
            ->map(fn (StockHolding $holding): string => $this->stockPriceCatalog->instrumentKeyForHolding($holding));
        $uniqueInstrumentKeys = $instrumentKeys->unique()->values();
        $coveredInstrumentKeys = StockPrice::query()
            ->whereIn('instrument_key', $uniqueInstrumentKeys)
            ->whereNotNull('price')
            ->where('as_of', '<=', $minimumDateEnd)
            ->distinct()
            ->pluck('instrument_key')
            ->flip();
        $coveredStocksCount = $instrumentKeys
            ->filter(fn (string $instrumentKey): bool => $coveredInstrumentKeys->has($instrumentKey))
            ->count();
        $totalStocksCount = $instrumentKeys->count();

        return [
            'minimum_date' => now()->subYear()->toDateString(),
            'actual_date' => $this->actualDate($uniqueInstrumentKeys),
            'total_stocks_count' => $totalStocksCount,
            'covered_stocks_count' => $coveredStocksCount,
            'missing_stocks_count' => $totalStocksCount - $coveredStocksCount,
            'missing_stocks' => $this->missingStocksPayload(),
        ];
    }

    /**
     * @return array{
     *     message: string,
     *     repaired_stocks_count: int,
     *     stored_prices_count: int,
     *     repair: array{end_of_day: array<string, mixed>},
     * }
     */
    public function repair(): array
    {
        $holdings = $this->missingHoldings();
        $result = $this->endOfDayDataService->syncHoldings(
            $holdings,
            now('Europe/Vienna')->subYear()->startOfDay(),
            now('Europe/Vienna')->startOfDay(),
        );

        return [
            'message' => trans_choice('{0} No missing stocks found.|{1} 1 stock repaired.|[2,*] :count stocks repaired.', $holdings->count()),
            'repaired_stocks_count' => $holdings->count(),
            'stored_prices_count' => $result['stored_count'],
            'repair' => [
                'end_of_day' => $this->summary(),
            ],
        ];
    }

    /**
     * @return array{stock: array{id: int, label: string}, stored_prices_count: int}
     */
    public function repairHolding(StockHolding $holding): array
    {
        if (! $this->isMissing($holding)) {
            return [
                'stock' => [
                    'id' => $holding->id,
                    'label' => $this->holdingLabel($holding),
                ],
                'stored_prices_count' => 0,
            ];
        }

        $result = $this->endOfDayDataService->syncHolding(
            $holding,
            now('Europe/Vienna')->subYear()->startOfDay(),
            now('Europe/Vienna')->startOfDay(),
        );

        return [
            'stock' => [
                'id' => $holding->id,
                'label' => $this->holdingLabel($holding),
            ],
            'stored_prices_count' => $result['stored_count'],
        ];
    }

    /**
     * @return Collection<int, StockHolding>
     */
    private function missingHoldings(): Collection
    {
        $minimumDateEnd = now()->subYear()->endOfDay();
        $holdings = $this->holdings();
        $instrumentKeys = $holdings
            ->map(fn (StockHolding $holding): string => $this->stockPriceCatalog->instrumentKeyForHolding($holding))
            ->unique()
            ->values();
        $coveredInstrumentKeys = StockPrice::query()
            ->whereIn('instrument_key', $instrumentKeys)
            ->whereNotNull('price')
            ->where('as_of', '<=', $minimumDateEnd)
            ->distinct()
            ->pluck('instrument_key')
            ->flip();

        return $holdings
            ->filter(fn (StockHolding $holding): bool => ! $coveredInstrumentKeys->has($this->stockPriceCatalog->instrumentKeyForHolding($holding)))
            ->unique('id')
            ->values();
    }

    /**
     * @return array<int, array{id: int, label: string}>
     */
    private function missingStocksPayload(): array
    {
        return $this->missingHoldings()
            ->map(fn (StockHolding $holding): array => [
                'id' => $holding->id,
                'label' => $this->holdingLabel($holding),
            ])
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, StockHolding>
     */
    private function holdings(): Collection
    {
        return StockHolding::query()
            ->orderBy('id')
            ->get(['id', 'name', 'isin', 'wkn', 'symbol', 'exchange', 'mic_code', 'currency', 'trading_times']);
    }

    private function isMissing(StockHolding $holding): bool
    {
        return ! $this->stockPriceCatalog->pricesForHolding($holding)
            ->whereNotNull('price')
            ->where('as_of', '<=', now()->subYear()->endOfDay())
            ->exists();
    }

    private function holdingLabel(StockHolding $holding): string
    {
        return collect([$holding->symbol, $holding->name])->filter()->implode(' - ');
    }

    /**
     * @param  Collection<int, string>  $instrumentKeys
     */
    private function actualDate(Collection $instrumentKeys): ?string
    {
        $firstStoredDate = StockPrice::query()
            ->whereIn('instrument_key', $instrumentKeys)
            ->whereNotNull('price')
            ->whereNotNull('as_of')
            ->min('as_of');

        return $firstStoredDate === null
            ? null
            : Carbon::parse($firstStoredDate)->toDateString();
    }
}
