<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $canonicalTradingTimes = [
            '000001' => 'Monday-Friday 09:30:00-15:00:00 Asia/Shanghai',
            'ATG' => 'Monday-Friday 10:15:00-17:20:00 Europe/Athens',
            'ATX' => 'Monday-Friday 09:00:00-17:30:00 Europe/Vienna',
            'BBVAI' => 'Monday-Friday 09:00:00-17:30:00 Europe/Madrid',
            'DJI' => 'Monday-Friday 09:30:00-16:00:00 America/New_York',
            'GDAXI' => 'Monday-Friday 09:00:00-17:30:00 Europe/Berlin',
            'IBEX' => 'Monday-Friday 09:00:00-17:30:00 Europe/Madrid',
            'KS11' => 'Monday-Friday 09:00:00-15:30:00 Asia/Seoul',
            'N225' => 'Monday-Friday 09:00:00-15:30:00 Asia/Tokyo',
            'NDX' => 'Monday-Friday 09:30:00-16:00:00 America/New_York',
            'OEX' => 'Monday-Friday 09:30:00-16:00:00 America/New_York',
            'SSMI' => 'Monday-Friday 09:00:00-17:30:00 Europe/Zurich',
        ];

        foreach ($canonicalTradingTimes as $symbol => $tradingTimes) {
            DB::table('index_watch_items')
                ->where('symbol', $symbol)
                ->update(['trading_times' => $tradingTimes]);
        }
    }
};
