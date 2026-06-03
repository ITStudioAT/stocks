<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

class StockPriceFreshness
{
    private const DefaultMarketTimezone = 'Europe/Vienna';

    private const CloseGraceMinutes = 90;

    public function isFresh(?string $asOf, ?string $tradingTimes = null, ?Carbon $now = null): bool
    {
        $sourceDateTime = $this->sourceDateTime($asOf, $tradingTimes);

        if ($sourceDateTime === null) {
            return false;
        }

        $sourceDate = $sourceDateTime['date'];
        $currentDate = ($now ?? now())->copy()->setTimezone($sourceDate->timezone);

        if ($sourceDate->gt($currentDate->copy()->addMinutes(5))) {
            return false;
        }

        if ($this->isOutsideTradingSession($sourceDateTime, $tradingTimes)) {
            return false;
        }

        return $sourceDate
            ->copy()
            ->startOfDay()
            ->greaterThanOrEqualTo($this->minimumFreshDate($currentDate, $tradingTimes));
    }

    /**
     * @return array{date: Carbon, has_time: bool}|null
     */
    private function sourceDateTime(?string $asOf, ?string $tradingTimes): ?array
    {
        if ($asOf === null || trim($asOf) === '') {
            return null;
        }

        $marketTimezone = $this->marketTimezone($asOf, $tradingTimes);
        $patterns = [
            '/\b(?<year>\d{4})-(?<month>\d{1,2})-(?<day>\d{1,2})(?:[ T](?<hour>\d{1,2}):(?<minute>\d{2})(?::(?<second>\d{2}))?)?(?:\s*(?<timezone>Z|UTC|CET|CEST|Europe\/[A-Za-z_]+|[+-]\d{2}:?\d{2}))?\b/i',
            '/\b(?<day>\d{1,2})[.\/-](?<month>\d{1,2})[.\/-](?<year>\d{2,4})(?:\s+(?<hour>\d{1,2}):(?<minute>\d{2})(?::(?<second>\d{2}))?)?(?:\s*(?<timezone>CET|CEST|UTC|Europe\/[A-Za-z_]+|[+-]\d{2}:?\d{2}))?\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (! preg_match($pattern, $asOf, $matches)) {
                continue;
            }

            return $this->dateTimeFromMatches($matches, $marketTimezone);
        }

        return null;
    }

    /**
     * @param  array<string, string>  $matches
     * @return array{date: Carbon, has_time: bool}|null
     */
    private function dateTimeFromMatches(array $matches, string $marketTimezone): ?array
    {
        $year = (int) $matches['year'];

        if ($year < 100) {
            $year += 2000;
        }

        $hasTime = isset($matches['hour'], $matches['minute'])
            && $matches['hour'] !== ''
            && $matches['minute'] !== '';
        $timezone = $this->sourceTimezone($matches['timezone'] ?? null, $marketTimezone);

        try {
            $date = Carbon::create(
                $year,
                (int) $matches['month'],
                (int) $matches['day'],
                $hasTime ? (int) $matches['hour'] : 0,
                $hasTime ? (int) $matches['minute'] : 0,
                isset($matches['second']) && $matches['second'] !== '' ? (int) $matches['second'] : 0,
                $timezone,
            );
        } catch (Throwable) {
            return null;
        }

        return [
            'date' => $date->setTimezone($marketTimezone),
            'has_time' => $hasTime,
        ];
    }

    private function marketTimezone(string $asOf, ?string $tradingTimes): string
    {
        foreach ([$tradingTimes, $asOf] as $value) {
            if (! is_string($value)) {
                continue;
            }

            if (preg_match('/\bEurope\/[A-Za-z_]+\b/', $value, $matches)) {
                return $matches[0];
            }
        }

        return self::DefaultMarketTimezone;
    }

    private function sourceTimezone(?string $timezone, string $fallback): string
    {
        if ($timezone === null || trim($timezone) === '') {
            return $fallback;
        }

        $timezone = trim($timezone);
        $upperTimezone = Str::upper($timezone);

        if ($upperTimezone === 'Z' || $upperTimezone === 'UTC') {
            return 'UTC';
        }

        if ($upperTimezone === 'CET' || $upperTimezone === 'CEST') {
            return $fallback;
        }

        if (preg_match('/^[+-]\d{2}:?\d{2}$/', $timezone)) {
            return $timezone;
        }

        if (Str::startsWith($timezone, 'Europe/')) {
            return $timezone;
        }

        return $fallback;
    }

    private function minimumFreshDate(Carbon $now, ?string $tradingTimes): Carbon
    {
        [$openingHour, $openingMinute] = $this->openingTime($tradingTimes);
        $marketOpen = $now
            ->copy()
            ->setTime($openingHour, $openingMinute);

        if ($now->isWeekend() || $now->lessThan($marketOpen)) {
            return $this->previousBusinessDay($now)->startOfDay();
        }

        return $now->copy()->startOfDay();
    }

    private function previousBusinessDay(Carbon $date): Carbon
    {
        $previousBusinessDay = $date
            ->copy()
            ->subDay()
            ->startOfDay();

        while ($previousBusinessDay->isWeekend()) {
            $previousBusinessDay->subDay();
        }

        return $previousBusinessDay;
    }

    /**
     * @param  array{date: Carbon, has_time: bool}  $sourceDateTime
     */
    private function isOutsideTradingSession(array $sourceDateTime, ?string $tradingTimes): bool
    {
        if (! $sourceDateTime['has_time'] || $tradingTimes === null) {
            return false;
        }

        if (! preg_match('/(?<open_hour>\d{1,2}):(?<open_minute>\d{2})\s*(?:-|to|until|bis)\s*(?<close_hour>\d{1,2}):(?<close_minute>\d{2})/i', $tradingTimes, $matches)) {
            return false;
        }

        $sourceDate = $sourceDateTime['date'];

        if ($sourceDate->isWeekend()) {
            return true;
        }

        $sourceMinute = ($sourceDate->hour * 60) + $sourceDate->minute;
        $openMinute = ((int) $matches['open_hour'] * 60) + (int) $matches['open_minute'];
        $closeMinute = ((int) $matches['close_hour'] * 60) + (int) $matches['close_minute'];

        return $sourceMinute < $openMinute
            || $sourceMinute > $closeMinute + self::CloseGraceMinutes;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function openingTime(?string $tradingTimes): array
    {
        if (
            $tradingTimes !== null
            && preg_match('/(?<hour>\d{1,2}):(?<minute>\d{2})\s*(?:-|to|until|bis)/i', $tradingTimes, $matches)
        ) {
            return [(int) $matches['hour'], (int) $matches['minute']];
        }

        return [9, 0];
    }
}
