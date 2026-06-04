<?php

namespace App\Services;

use App\Models\StockHolding;
use Illuminate\Support\Carbon;

class TradingSessionPriceResolver
{
    private const DefaultMarketTimezone = 'Europe/Vienna';

    public function __construct(
        private StockPriceCatalog $stockPriceCatalog,
        private HistoricalSessionStartPriceFetchStatus $historicalSessionStartPriceFetchStatus,
    ) {}

    /**
     * Resolve the first price after today's trading session opened (start) and
     * the first price after the previous trading day's session closed (end).
     *
     * When no end price is recorded yet, the actual latest price is used. When
     * no start price is recorded, the end price is used.
     *
     * @return array{start_price: ?string, end_price: ?string, start_price_24: ?string, start_price_48: ?string, historical_prices_fetching: bool}
     */
    public function resolve(StockHolding $holding, ?string $latestPrice): array
    {
        $session = $this->session($holding);

        if ($session === null) {
            return [
                'start_price' => $latestPrice,
                'end_price' => $latestPrice,
                'start_price_24' => null,
                'start_price_48' => null,
                'historical_prices_fetching' => false,
            ];
        }

        [$todayOpenUtc, $todayCloseUtc, $previousOpenUtc, $previousCloseUtc, $twoTradingDaysAgoOpenUtc, $twoTradingDaysAgoCloseUtc] = $session;

        $startPrice = $this->storedFirstPriceBetween($holding, $todayOpenUtc, $todayCloseUtc, 'historical_session_start');
        $startPrice24 = $this->storedHistoricalPriceBetween($holding, $previousOpenUtc, $previousCloseUtc, 'historical_session_start');
        $startPrice48 = $this->storedHistoricalPriceBetween($holding, $twoTradingDaysAgoOpenUtc, $twoTradingDaysAgoCloseUtc, 'historical_session_start');
        $endPrice = $this->storedHistoricalPriceBetween($holding, $previousCloseUtc, $todayOpenUtc, 'historical_session_end');

        $endPrice ??= $latestPrice;
        $startPrice ??= $endPrice;

        return [
            'start_price' => $startPrice,
            'end_price' => $endPrice,
            'start_price_24' => $startPrice24,
            'start_price_48' => $startPrice48,
            'historical_prices_fetching' => $this->isHistoricalPriceFetching(
                $holding,
                [
                    [$todayOpenUtc, $todayCloseUtc, 'start'],
                    [$previousOpenUtc, $previousCloseUtc, 'start'],
                    [$twoTradingDaysAgoOpenUtc, $twoTradingDaysAgoCloseUtc, 'start'],
                    [$previousCloseUtc, $todayOpenUtc, 'end'],
                ],
            ),
        ];
    }

    public function isTradingTime(?string $tradingTimes, ?Carbon $at = null): bool
    {
        if ($tradingTimes === null) {
            return false;
        }

        $window = $this->tradingWindow($tradingTimes);

        if ($window === null) {
            return false;
        }

        $localTime = ($at ?? now())->copy()->setTimezone($this->marketTimezone($tradingTimes));

        if ($localTime->isWeekend()) {
            return false;
        }

        $currentMinute = ($localTime->hour * 60) + $localTime->minute;

        return $currentMinute >= $window[0] && $currentMinute < $window[1];
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: Carbon, 3: Carbon, 4: Carbon, 5: Carbon}|null
     */
    private function session(StockHolding $holding): ?array
    {
        $tradingTimes = $holding->trading_times;

        if ($tradingTimes === null) {
            return null;
        }

        $window = $this->tradingWindow($tradingTimes);

        if ($window === null) {
            return null;
        }

        $marketTimezone = $this->marketTimezone($tradingTimes);
        $today = now()->setTimezone($marketTimezone)->startOfDay();
        $previousTradingDay = $this->previousTradingDay($today);
        $twoTradingDaysAgo = $this->previousTradingDay($previousTradingDay);

        return [
            $today->copy()->addMinutes($window[0])->utc(),
            $today->copy()->addMinutes($window[1])->utc(),
            $previousTradingDay->copy()->addMinutes($window[0])->utc(),
            $previousTradingDay->copy()->addMinutes($window[1])->utc(),
            $twoTradingDaysAgo->copy()->addMinutes($window[0])->utc(),
            $twoTradingDaysAgo->copy()->addMinutes($window[1])->utc(),
        ];
    }

    private function storedFirstPriceBetween(StockHolding $holding, Carbon $from, Carbon $until, string $priceType): ?string
    {
        return $this->stockPriceCatalog
            ->pricesForHolding($holding)
            ->whereIn('source_key', EodhdMarketData::sourceKeys())
            ->where('price_type', $priceType)
            ->whereNotNull('price')
            ->whereNotNull('as_of')
            ->where('as_of', '>=', $from)
            ->where('as_of', '<', $until)
            ->orderBy('as_of')
            ->first(['price'])
            ?->price;
    }

    private function storedHistoricalPriceBetween(StockHolding $holding, Carbon $from, Carbon $until, string $priceType): ?string
    {
        return $this->storedFirstPriceBetween($holding, $from, $until, $priceType);
    }

    /**
     * @param  array<int, array{0: Carbon, 1: Carbon, 2: string}>  $windows
     */
    private function isHistoricalPriceFetching(StockHolding $holding, array $windows): bool
    {
        return collect($windows)
            ->contains(fn (array $window): bool => $this->historicalSessionStartPriceFetchStatus->isFetching(
                $holding->id,
                $window[0],
                $window[1],
                $window[2],
            ));
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private function tradingWindow(string $tradingTimes): ?array
    {
        if (! preg_match('/(?<open_hour>\d{1,2}):(?<open_minute>\d{2})\s*(?:-|to|until|bis)\s*(?<close_hour>\d{1,2}):(?<close_minute>\d{2})/i', $tradingTimes, $matches)) {
            return null;
        }

        return [
            ((int) $matches['open_hour'] * 60) + (int) $matches['open_minute'],
            ((int) $matches['close_hour'] * 60) + (int) $matches['close_minute'],
        ];
    }

    private function marketTimezone(string $tradingTimes): string
    {
        if (preg_match('/\bEurope\/[A-Za-z_]+\b/', $tradingTimes, $matches)) {
            return $matches[0];
        }

        return self::DefaultMarketTimezone;
    }

    private function previousTradingDay(Carbon $today): Carbon
    {
        $previousTradingDay = $today->copy()->subDay();

        while ($previousTradingDay->isWeekend()) {
            $previousTradingDay->subDay();
        }

        return $previousTradingDay;
    }
}
