<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('stock_realtime_prices')
            ->whereNull('stock_holding_id')
            ->orderBy('id')
            ->chunkById(500, function ($realtimePrices): void {
                foreach ($realtimePrices as $realtimePrice) {
                    $holdingId = $this->matchingHoldingId($realtimePrice);

                    if ($holdingId === null) {
                        continue;
                    }

                    DB::table('stock_realtime_prices')
                        ->where('id', $realtimePrice->id)
                        ->update(['stock_holding_id' => $holdingId]);
                }
            });
    }

    private function matchingHoldingId(object $realtimePrice): ?int
    {
        if ($realtimePrice->isin !== null) {
            $holdingId = DB::table('stock_holdings')
                ->where('isin', $realtimePrice->isin)
                ->value('id');

            if ($holdingId !== null) {
                return (int) $holdingId;
            }
        }

        if ($realtimePrice->wkn !== null) {
            $holdingId = DB::table('stock_holdings')
                ->where('wkn', $realtimePrice->wkn)
                ->value('id');

            if ($holdingId !== null) {
                return (int) $holdingId;
            }
        }

        if ($realtimePrice->symbol === null) {
            return null;
        }

        $query = DB::table('stock_holdings')
            ->where('symbol', $realtimePrice->symbol);

        if ($realtimePrice->mic !== null) {
            $query->where('mic_code', $realtimePrice->mic);
        }

        return ($holdingId = $query->value('id')) === null
            ? null
            : (int) $holdingId;
    }
};
