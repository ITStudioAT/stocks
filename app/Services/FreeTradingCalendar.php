<?php

namespace App\Services;

use App\Models\StockHolding;
use Illuminate\Support\Carbon;

class FreeTradingCalendar
{
    /**
     * Published NYSE calendars: https://www.nyse.com/trade/hours-calendars
     * Xetra/Frankfurt: https://www.cashmarket.deutsche-boerse.com/cash-en/trading/trading-calendar-and-trading-hours
     * Years outside the verified calendars deliberately have no inferred reference day.
     *
     * @var array<int, array<int, string>>
     */
    private const UsHolidays = [
        2025 => ['01-01', '01-09', '01-20', '02-17', '04-18', '05-26', '06-19', '07-04', '09-01', '11-27', '12-25'],
        2026 => ['01-01', '01-19', '02-16', '04-03', '05-25', '06-19', '07-03', '09-07', '11-26', '12-25'],
        2027 => ['01-01', '01-18', '02-15', '03-26', '05-31', '06-18', '07-05', '09-06', '11-25', '12-24'],
        2028 => ['01-17', '02-21', '04-14', '05-29', '06-19', '07-04', '09-04', '11-23', '12-25'],
    ];

    /** @var array<int, array<int, string>> */
    private const GermanHolidays = [
        2025 => ['01-01', '04-18', '04-21', '05-01', '12-24', '12-25', '12-26', '12-31'],
        2026 => ['01-01', '04-03', '04-06', '05-01', '12-24', '12-25', '12-26', '12-31'],
        2027 => ['01-01', '03-26', '03-29', '05-01', '12-24', '12-25', '12-26', '12-31'],
        2028 => ['01-01', '04-14', '04-17', '05-01', '12-24', '12-25', '12-26', '12-31'],
    ];

    /** @return array{open: Carbon, close: Carbon}|null */
    public function session(StockHolding $holding, Carbon $date): ?array
    {
        $timezone = $this->timezone($holding);
        if ($timezone === null) {
            return null;
        }
        $day = $date->copy()->setTimezone($timezone)->startOfDay();
        $holidays = $timezone === 'America/New_York' ? self::UsHolidays : self::GermanHolidays;
        if (! isset($holidays[$day->year]) || $day->isWeekend() || in_array($day->format('m-d'), $holidays[$day->year], true)) {
            return null;
        }
        $us = $timezone === 'America/New_York';
        $early = ['2025-07-03', '2025-11-28', '2025-12-24', '2026-11-27', '2026-12-24', '2027-11-26', '2028-07-03', '2028-11-24'];
        $overrides = config('services.market_data.session_overrides.'.($us ? 'US' : 'XETRA'), []);
        if (array_key_exists($day->toDateString(), $overrides) && $overrides[$day->toDateString()] === null) {
            return null;
        }
        $close = $overrides[$day->toDateString()] ?? ($us ? (in_array($day->toDateString(), $early, true) ? '13:00' : '16:00') : '17:30');

        return ['open' => $day->copy()->setTimeFromTimeString($us ? '09:30' : '09:00')->utc(),
            'close' => $day->copy()->setTimeFromTimeString($close)->utc()];
    }

    public function shouldCollect(StockHolding $holding, ?Carbon $at = null): bool
    {
        $at = ($at ?? now())->copy();
        $timezone = $this->timezone($holding);
        if ($timezone === null) {
            return false;
        }
        $session = $this->session($holding, $at->copy()->setTimezone($timezone));

        return $session !== null && $at->betweenIncluded($session['open'], $session['close']->copy()->addMinutes(30));
    }

    public function timezone(StockHolding $holding): ?string
    {
        $identity = $holding->only(['isin', 'symbol', 'exchange', 'mic_code', 'currency']);

        if (YahooFinanceMarketData::supports($identity)) {
            return 'America/New_York';
        }

        if (IntradayMarketIdentity::isXetra($identity) || in_array(strtoupper($holding->exchange ?? ''), ['F', 'FRANKFURT'], true)
            || $holding->mic_code === 'XFRA') {
            return 'Europe/Berlin';
        }

        return null;
    }

    public function previousDay(StockHolding $holding, Carbon $reference): ?Carbon
    {
        $timezone = $this->timezone($holding);

        if ($timezone === null) {
            return null;
        }

        return $this->previousDayInTimezone($timezone, $reference);
    }

    public function previousDayInTimezone(string $timezone, Carbon $reference): ?Carbon
    {
        if (! in_array($timezone, ['America/New_York', 'Europe/Berlin'], true)) {
            return null;
        }

        $date = $reference->copy()->setTimezone($timezone)->startOfDay()->subDay();
        $calendar = $timezone === 'America/New_York' ? self::UsHolidays : self::GermanHolidays;

        while (isset($calendar[$date->year])) {
            if (! $date->isWeekend() && ! in_array($date->format('m-d'), $calendar[$date->year], true)) {
                return $date;
            }

            $date->subDay();
        }

        return null;
    }
}
