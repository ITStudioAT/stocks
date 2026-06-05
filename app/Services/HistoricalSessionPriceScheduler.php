<?php

namespace App\Services;

use App\Jobs\FetchHistoricalSessionPrices;
use App\Models\StockHolding;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class HistoricalSessionPriceScheduler
{
    public function __construct(
        private EodhdMarketData $eodhdMarketData,
        private HistoricalSessionStartPriceFetchStatus $fetchStatus,
        private StockPriceCatalog $stockPriceCatalog,
    ) {}

    public function dispatchDue(): int
    {
        if (! $this->hasEodhdApiToken()) {
            return 0;
        }

        return StockHolding::query()
            ->orderBy('id')
            ->get(['id', 'symbol', 'isin', 'wkn', 'exchange', 'mic_code', 'country', 'currency', 'trading_times'])
            ->groupBy(fn (StockHolding $holding): string => $this->eodhdMarketData->exchangeCodeForHolding($holding))
            ->reduce(
                fn (int $dispatchedCount, Collection $holdings, string $exchangeCode): int => $dispatchedCount
                    + $this->dispatchExchangeIfDue($exchangeCode, $holdings),
                0,
            );
    }

    /**
     * @param  Collection<int, StockHolding>  $holdings
     */
    private function dispatchExchangeIfDue(string $exchangeCode, Collection $holdings): int
    {
        $details = $this->eodhdMarketData->exchangeDetailsForCode($exchangeCode);
        $session = $this->session($details);

        if ($session === null) {
            return 0;
        }

        if (! $this->isDispatchWindow($session)) {
            return 0;
        }

        $pendingHoldings = $holdings
            ->filter(fn (StockHolding $holding): bool => ! $this->hasCompleteMorningBundle($holding, $session))
            ->values();

        if ($pendingHoldings->isEmpty()) {
            return 0;
        }

        if (! Cache::add($this->throttleKey($exchangeCode, $session), true, now()->addMinutes(10))) {
            return 0;
        }

        $pendingHoldings->each(fn (StockHolding $holding): null => $this->markQueued($holding, $session));

        FetchHistoricalSessionPrices::dispatch(
            exchangeCode: $exchangeCode,
            stockHoldingIds: $pendingHoldings->pluck('id')->map(fn (int $id): int => $id)->all(),
            session: $this->sessionPayload($session),
        );

        return 1;
    }

    /**
     * @param  array{timezone: string, working_days: array<int, string>, today_date: Carbon, today_open: Carbon, today_close: Carbon, previous_date: Carbon, previous_open: Carbon, previous_close: Carbon, two_ago_date: Carbon, two_ago_open: Carbon, two_ago_close: Carbon}  $session
     */
    private function isDispatchWindow(array $session): bool
    {
        $localNow = now()->setTimezone($session['timezone']);

        return $localNow->greaterThanOrEqualTo($session['today_open']);
    }

    /**
     * @param  array{code: string, name: ?string, operating_mic: ?string, country: ?string, currency: ?string, timezone: ?string, is_open: bool, open: ?string, close: ?string, open_utc: ?string, close_utc: ?string, working_days: ?string, error: ?string}  $details
     * @return array{timezone: string, working_days: array<int, string>, today_date: Carbon, today_open: Carbon, today_close: Carbon, previous_date: Carbon, previous_open: Carbon, previous_close: Carbon, two_ago_date: Carbon, two_ago_open: Carbon, two_ago_close: Carbon}|null
     */
    private function session(array $details): ?array
    {
        if ($details['error'] !== null || $details['timezone'] === null || $details['open'] === null || $details['close'] === null) {
            return null;
        }

        $workingDays = $this->workingDays($details['working_days']);
        $today = now()->setTimezone($details['timezone'])->startOfDay();

        if (! $this->isWorkingDay($today, $workingDays)) {
            return null;
        }

        $previousDate = $this->previousTradingDay($today, $workingDays);
        $twoAgoDate = $this->previousTradingDay($previousDate, $workingDays);

        return [
            'timezone' => $details['timezone'],
            'working_days' => $workingDays,
            'today_date' => $today,
            'today_open' => $this->atTime($today, $details['open']),
            'today_close' => $this->atTime($today, $details['close']),
            'previous_date' => $previousDate,
            'previous_open' => $this->atTime($previousDate, $details['open']),
            'previous_close' => $this->atTime($previousDate, $details['close']),
            'two_ago_date' => $twoAgoDate,
            'two_ago_open' => $this->atTime($twoAgoDate, $details['open']),
            'two_ago_close' => $this->atTime($twoAgoDate, $details['close']),
        ];
    }

    /**
     * @param  array{timezone: string, working_days: array<int, string>, today_date: Carbon, today_open: Carbon, today_close: Carbon, previous_date: Carbon, previous_open: Carbon, previous_close: Carbon, two_ago_date: Carbon, two_ago_open: Carbon, two_ago_close: Carbon}  $session
     */
    private function hasCompleteMorningBundle(StockHolding $holding, array $session): bool
    {
        $hasTodayEnd = now()->setTimezone($session['timezone'])->greaterThanOrEqualTo($session['today_close'])
            ? $this->hasHistoricalPrice($holding, $session['today_close'], $session['today_close']->copy()->addDay(), 'historical_session_end')
            : true;

        return $this->hasHistoricalPrice($holding, $session['today_open'], $session['today_close'], 'historical_session_start')
            && $hasTodayEnd
            && $this->hasHistoricalPrice($holding, $session['previous_close'], $session['today_open'], 'historical_session_end')
            && $this->hasHistoricalPrice($holding, $session['two_ago_close'], $session['previous_open'], 'historical_session_end');
    }

    private function hasHistoricalPrice(StockHolding $holding, Carbon $from, Carbon $until, string $priceType): bool
    {
        return $this->stockPriceCatalog
            ->pricesForHolding($holding)
            ->whereIn('source_key', EodhdMarketData::sourceKeys())
            ->where('price_type', $priceType)
            ->whereNotNull('price')
            ->whereNotNull('as_of')
            ->where('as_of', '>=', $from->copy()->utc())
            ->where('as_of', '<', $until->copy()->utc())
            ->exists();
    }

    /**
     * @param  array{timezone: string, working_days: array<int, string>, today_date: Carbon, today_open: Carbon, today_close: Carbon, previous_date: Carbon, previous_open: Carbon, previous_close: Carbon, two_ago_date: Carbon, two_ago_open: Carbon, two_ago_close: Carbon}  $session
     */
    private function markQueued(StockHolding $holding, array $session): void
    {
        $this->fetchStatus->queued($holding->id, $session['today_open'], $session['today_close'], 'start');

        if (now()->setTimezone($session['timezone'])->greaterThanOrEqualTo($session['today_close'])) {
            $this->fetchStatus->queued($holding->id, $session['today_close'], $session['today_close']->copy()->addDay(), 'end');
        }

        $this->fetchStatus->queued($holding->id, $session['previous_close'], $session['today_open'], 'end');
        $this->fetchStatus->queued($holding->id, $session['two_ago_close'], $session['previous_open'], 'end');
    }

    /**
     * @param  array{timezone: string, working_days: array<int, string>, today_date: Carbon, today_open: Carbon, today_close: Carbon, previous_date: Carbon, previous_open: Carbon, previous_close: Carbon, two_ago_date: Carbon, two_ago_open: Carbon, two_ago_close: Carbon}  $session
     * @return array{timezone: string, today_date: string, today_open: string, today_close: string, previous_date: string, previous_open: string, previous_close: string, two_ago_date: string, two_ago_open: string, two_ago_close: string}
     */
    private function sessionPayload(array $session): array
    {
        return [
            'timezone' => $session['timezone'],
            'today_date' => $session['today_date']->toDateString(),
            'today_open' => $session['today_open']->copy()->utc()->toIso8601String(),
            'today_close' => $session['today_close']->copy()->utc()->toIso8601String(),
            'previous_date' => $session['previous_date']->toDateString(),
            'previous_open' => $session['previous_open']->copy()->utc()->toIso8601String(),
            'previous_close' => $session['previous_close']->copy()->utc()->toIso8601String(),
            'two_ago_date' => $session['two_ago_date']->toDateString(),
            'two_ago_open' => $session['two_ago_open']->copy()->utc()->toIso8601String(),
            'two_ago_close' => $session['two_ago_close']->copy()->utc()->toIso8601String(),
        ];
    }

    /**
     * @param  array{timezone: string, working_days: array<int, string>, today_date: Carbon, today_open: Carbon, today_close: Carbon, previous_date: Carbon, previous_open: Carbon, previous_close: Carbon, two_ago_date: Carbon, two_ago_open: Carbon, two_ago_close: Carbon}  $session
     */
    private function throttleKey(string $exchangeCode, array $session): string
    {
        return "historical-session-prices:{$exchangeCode}:{$session['today_date']->toDateString()}";
    }

    /**
     * @return array<int, string>
     */
    private function workingDays(?string $workingDays): array
    {
        if ($workingDays === null || trim($workingDays) === '') {
            return ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];
        }

        return collect(explode(',', $workingDays))
            ->map(fn (string $day): string => trim($day))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $workingDays
     */
    private function previousTradingDay(Carbon $date, array $workingDays): Carbon
    {
        $previousTradingDay = $date->copy()->subDay();

        while (! $this->isWorkingDay($previousTradingDay, $workingDays)) {
            $previousTradingDay->subDay();
        }

        return $previousTradingDay;
    }

    /**
     * @param  array<int, string>  $workingDays
     */
    private function isWorkingDay(Carbon $date, array $workingDays): bool
    {
        return in_array($date->format('D'), $workingDays, true);
    }

    private function atTime(Carbon $date, string $time): Carbon
    {
        [$hour, $minute, $second] = array_pad(explode(':', $time), 3, '0');

        return $date->copy()->setTime((int) $hour, (int) $minute, (int) $second);
    }

    private function hasEodhdApiToken(): bool
    {
        return is_string(config('services.eodhd.key'))
            && trim((string) config('services.eodhd.key')) !== '';
    }
}
