<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;

class IntradayMarketIdentity
{
    public const XetraMics = ['XETR', 'XETA', 'XETB', 'XETS'];

    /** @param array<string, mixed> $identity */
    public static function isXetra(array $identity): bool
    {
        return in_array(strtoupper((string) ($identity['mic_code'] ?? '')), self::XetraMics, true)
            || strtoupper((string) ($identity['exchange'] ?? '')) === 'XETRA';
    }

    public static function constrain(Builder $query, bool $quote = false): void
    {
        $table = $query->getModel()->getTable();
        $mic = $quote ? "{$table}.mic" : "{$table}.raw_payload->mic";
        $query->where(function (Builder $query) use ($table, $mic): void {
            $query->whereNull("{$table}.source_key")
                ->orWhereNotIn("{$table}.source_key", ['deutsche_boerse_trade', 'xetra_posttrade', 'yahoo_xetra_intraday'])
                ->orWhere(function (Builder $query) use ($table, $mic): void {
                    $query->whereIn($mic, self::XetraMics)
                        ->whereExists(function ($holdings) use ($table): void {
                            $holdings->selectRaw('1')->from('stock_holdings as market_identity')
                                ->whereColumn('market_identity.id', "{$table}.stock_holding_id")
                                ->whereColumn('market_identity.currency', "{$table}.currency")
                                ->where(function ($holdings): void {
                                    $holdings->whereIn('market_identity.mic_code', self::XetraMics)
                                        ->orWhere('market_identity.exchange', 'XETRA');
                                });
                        });
                });
        });
        $query->where(function (Builder $query) use ($table): void {
            $query->whereNull("{$table}.raw_payload->quarantined")
                ->orWhere("{$table}.raw_payload->quarantined", false);
        });
    }
}
