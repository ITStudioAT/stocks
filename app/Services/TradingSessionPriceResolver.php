<?php

namespace App\Services;

use App\Models\StockHolding;
use Illuminate\Support\Carbon;

class TradingSessionPriceResolver
{
    private const DefaultMarketTimezone = 'Europe/Vienna';

    public function __construct(
        private StockPriceCatalog $stockPriceCatalog,
    ) {}

    /**
     * Resolve the first price after today's trading session opened (start) and
     * the first price after the previous trading day's session closed (end).
     *
     * When no end price is recorded yet, the actual latest price is used. When
     * no start price is recorded, the end price is used.
     *
     * @return array{start_price: ?string, end_price: ?string}
     */
    public function resolve(StockHolding $holding, ?string $latestPrice): array
    {
        $session = $this->session($holding);

        if ($session === null) {
            return [
                'start_price' => $latestPrice,
                'end_price' => $latestPrice,
            ];
        }

        [$todayOpenUtc, $todayCloseUtc, $previousCloseUtc] = $session;

        $startPrice = $this->stockPriceCatalog
            ->pricesForHolding($holding)
            ->where('source_key', 'calculated_median')
            ->whereNotNull('price')
            ->whereNotNull('as_of')
            ->where('as_of', '>=', $todayOpenUtc)
            ->where('as_of', '<', $todayCloseUtc)
            ->orderBy('as_of')
            ->first(['price'])
            ?->price;

        $endPrice = $this->stockPriceCatalog
            ->pricesForHolding($holding)
            ->where('source_key', 'calculated_median')
            ->whereNotNull('price')
            ->whereNotNull('as_of')
            ->where('as_of', '>=', $previousCloseUtc)
            ->where('as_of', '<', $todayOpenUtc)
            ->orderBy('as_of')
            ->first(['price'])
            ?->price;

        $endPrice ??= $latestPrice;
        $startPrice ??= $endPrice;

        return [
            'start_price' => $startPrice,
            'end_price' => $endPrice,
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
     * @return array{0: Carbon, 1: Carbon, 2: Carbon}|null
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

        return [
            $today->copy()->addMinutes($window[0])->utc(),
            $today->copy()->addMinutes($window[1])->utc(),
            $previousTradingDay->copy()->addMinutes($window[1])->utc(),
        ];
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
