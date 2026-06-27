<?php

namespace App\Services;

use Illuminate\Support\Carbon;

class CompletedTradingDay
{
    private const CloseHour = 17;

    private const CloseMinute = 30;

    private const Timezone = 'Europe/Vienna';

    public function date(?Carbon $now = null): Carbon
    {
        $now = ($now ?? now(self::Timezone))->copy()->setTimezone(self::Timezone);
        $date = $now->copy()->startOfDay();

        if ($date->isWeekend()) {
            return $this->previousWeekday($date);
        }

        if ($now->lessThan($date->copy()->setTime(self::CloseHour, self::CloseMinute))) {
            return $this->previousWeekday($date);
        }

        return $date;
    }

    private function previousWeekday(Carbon $date): Carbon
    {
        $date = $date->copy()->startOfDay()->subDay();

        while ($date->isWeekend()) {
            $date->subDay();
        }

        return $date;
    }
}
