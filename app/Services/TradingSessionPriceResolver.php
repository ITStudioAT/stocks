<?php

namespace App\Services;

use App\Models\StockHolding;
use Illuminate\Support\Carbon;
use Throwable;

class TradingSessionPriceResolver
{
    private const DefaultMarketTimezone = 'Europe/Vienna';

    public function __construct(
        private StockPriceCatalog $stockPriceCatalog,
    ) {}

    /**
     * Resolve the first price after the trading session opened (start) and the
     * first price after it closed (end) for the holding's most recent session.
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

        [$dayStartUtc, $nextDayStartUtc, $openMinute, $closeMinute, $marketTimezone] = $session;

        $quotes = $this->stockPriceCatalog
            ->pricesForHolding($holding)
            ->where('source_key', 'calculated_median')
            ->whereNotNull('price')
            ->whereNotNull('as_of')
            ->where('as_of', '>=', $dayStartUtc)
            ->where('as_of', '<', $nextDayStartUtc)
            ->orderBy('as_of')
            ->get(['price', 'as_of']);

        $startPrice = null;
        $endPrice = null;

        foreach ($quotes as $quote) {
            $minuteOfDay = $this->minuteOfDay($quote->as_of, $marketTimezone);

            if ($startPrice === null && $minuteOfDay >= $openMinute) {
                $startPrice = (string) $quote->price;
            }

            if ($minuteOfDay >= $closeMinute) {
                $endPrice = (string) $quote->price;

                break;
            }
        }

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
     * @return array{0: Carbon, 1: Carbon, 2: int, 3: int, 4: string}|null
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
        $referenceDate = $this->referenceDate($holding, $marketTimezone);

        if ($referenceDate === null) {
            return null;
        }

        $dayStart = $referenceDate->copy()->startOfDay();

        return [
            $dayStart->copy()->utc(),
            $dayStart->copy()->addDay()->utc(),
            $window[0],
            $window[1],
            $marketTimezone,
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

    private function referenceDate(StockHolding $holding, string $marketTimezone): ?Carbon
    {
        $latestStockPrice = $holding->latestStockPrice;

        if ($latestStockPrice?->as_of !== null) {
            return $this->storedUtcDate($latestStockPrice->as_of)->setTimezone($marketTimezone);
        }

        if (! $holding->latest_price_as_of) {
            return null;
        }

        try {
            return Carbon::parse($holding->latest_price_as_of)->setTimezone($marketTimezone);
        } catch (Throwable) {
            return null;
        }
    }

    private function minuteOfDay(Carbon $asOf, string $marketTimezone): int
    {
        $local = $this->storedUtcDate($asOf)->setTimezone($marketTimezone);

        return ($local->hour * 60) + $local->minute;
    }

    private function storedUtcDate(Carbon $date): Carbon
    {
        return Carbon::createFromFormat('Y-m-d H:i:s', $date->format('Y-m-d H:i:s'), 'UTC');
    }
}
