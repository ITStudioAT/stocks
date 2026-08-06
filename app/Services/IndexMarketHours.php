<?php

namespace App\Services;

use App\Models\IndexWatchItem;
use Illuminate\Support\Str;

class IndexMarketHours
{
    /**
     * @var array<string, array{timezone: string, open: string, close: string}>
     */
    private const Profiles = [
        '000001' => ['timezone' => 'Asia/Shanghai', 'open' => '09:30:00', 'close' => '15:00:00'],
        'ATG' => ['timezone' => 'Europe/Athens', 'open' => '10:15:00', 'close' => '17:20:00'],
        'ATX' => ['timezone' => 'Europe/Vienna', 'open' => '09:00:00', 'close' => '17:30:00'],
        'BBVAI' => ['timezone' => 'Europe/Madrid', 'open' => '09:00:00', 'close' => '17:30:00'],
        'DJI' => ['timezone' => 'America/New_York', 'open' => '09:30:00', 'close' => '16:00:00'],
        'GDAXI' => ['timezone' => 'Europe/Berlin', 'open' => '09:00:00', 'close' => '17:30:00'],
        'IBEX' => ['timezone' => 'Europe/Madrid', 'open' => '09:00:00', 'close' => '17:30:00'],
        'KS11' => ['timezone' => 'Asia/Seoul', 'open' => '09:00:00', 'close' => '15:30:00'],
        'N225' => ['timezone' => 'Asia/Tokyo', 'open' => '09:00:00', 'close' => '15:30:00'],
        'NDX' => ['timezone' => 'America/New_York', 'open' => '09:30:00', 'close' => '16:00:00'],
        'OEX' => ['timezone' => 'America/New_York', 'open' => '09:30:00', 'close' => '16:00:00'],
        'SSMI' => ['timezone' => 'Europe/Zurich', 'open' => '09:00:00', 'close' => '17:30:00'],
    ];

    /**
     * @return array{timezone: string, open: string, close: string}|null
     */
    public function profile(IndexWatchItem $item): ?array
    {
        return $this->profileForSymbol($item->symbol);
    }

    /**
     * @return array{timezone: string, open: string, close: string}|null
     */
    public function profileForSymbol(?string $symbol): ?array
    {
        return self::Profiles[Str::upper(trim((string) $symbol))] ?? null;
    }

    public function canonicalTradingTimesForSymbol(?string $symbol): ?string
    {
        $profile = $this->profileForSymbol($symbol);

        if ($profile === null) {
            return null;
        }

        return "Monday-Friday {$profile['open']}-{$profile['close']} {$profile['timezone']}";
    }

    public function effectiveTradingTimes(IndexWatchItem $item): ?string
    {
        return $this->canonicalTradingTimesForSymbol($item->symbol) ?? $item->trading_times;
    }
}
