<?php

namespace App\Services\WebMarketData;

use App\Services\WebMarketData\DTO\ParsedQuote;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class MarketHours
{
    public function isOpen(ParsedQuote $quote, ?Carbon $at = null): bool
    {
        $now = ($at ?? now())->copy()->setTimezone($this->timezone($quote));

        if ($now->isWeekend()) {
            return false;
        }

        [$startTime, $endTime] = $this->timeWindow($quote);
        [$startHour, $startMinute] = array_map('intval', explode(':', $startTime));
        [$endHour, $endMinute] = array_map('intval', explode(':', $endTime));

        $start = $now->copy()->setTime($startHour, $startMinute);
        $end = $now->copy()->setTime($endHour, $endMinute);

        return $now->greaterThanOrEqualTo($start) && $now->lessThan($end);
    }

    public function tradingTimes(ParsedQuote $quote): string
    {
        [$startTime, $endTime] = $this->timeWindow($quote);

        return "Monday-Friday {$startTime}-{$endTime} {$this->timezone($quote)}";
    }

    public function timezone(ParsedQuote $quote): string
    {
        $mic = Str::upper((string) $quote->mic);
        $venue = Str::lower((string) $quote->venue);

        if (in_array($mic, ['XBRN', 'XSWX'], true) || Str::contains($venue, ['swiss', 'zurich', 'zuerich'])) {
            return 'Europe/Zurich';
        }

        if ($mic === 'XMAD' || Str::contains($venue, ['madrid', 'sibe'])) {
            return 'Europe/Madrid';
        }

        if ($mic === 'XPAR' || Str::contains($venue, ['paris', 'euronext'])) {
            return 'Europe/Paris';
        }

        if ($mic === 'XVIE' || Str::contains($venue, ['wien', 'vienna'])) {
            return 'Europe/Vienna';
        }

        if (
            in_array($mic, ['XETR', 'XFRA', 'XSTU', 'XDUS', 'XMUN', 'XHAM', 'XBER', 'XGAT'], true)
            || Str::contains($venue, ['tradegate', 'gettex', 'xetra', 'frankfurt', 'stuttgart', 'quotrix', 'düsseldorf', 'duesseldorf', 'hamburg', 'münchen', 'munich'])
        ) {
            return 'Europe/Berlin';
        }

        $timezoneName = $quote->asOf?->timezoneName;

        if ($timezoneName !== null && ! preg_match('/^[+-]\d{2}:\d{2}$/', $timezoneName)) {
            return $timezoneName;
        }

        return 'Europe/Berlin';
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function timeWindow(ParsedQuote $quote): array
    {
        $venue = Str::lower((string) $quote->venue);

        if (Str::contains($venue, ['tradegate', 'gettex', 'quotrix', 'lang & schwarz', 'l&s exchange'])) {
            return ['08:00', '22:00'];
        }

        return ['09:00', '17:30'];
    }
}
