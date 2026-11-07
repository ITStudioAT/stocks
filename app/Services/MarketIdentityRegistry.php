<?php

namespace App\Services;

use App\Models\StockHolding;
use Illuminate\Support\Facades\DB;

class MarketIdentityRegistry
{
    /** @param array<string, mixed> $row */
    public function unresolvedListing(array $row, string $origin): object
    {
        $key = 'unresolved:'.$origin.':'.$row['id'];
        DB::table('market_instruments')->insertOrIgnore(['identity_key' => $key, 'isin' => $row['isin'] ?? null,
            'wkn' => $row['wkn'] ?? null, 'name' => 'Unresolved legacy instrument', 'created_at' => now(), 'updated_at' => now()]);
        $instrument = DB::table('market_instruments')->where('identity_key', $key)->value('id');
        $hash = hash('sha256', $key);
        DB::table('market_listings')->insertOrIgnore(['identity_key' => $hash, 'market_instrument_id' => $instrument,
            'venue_key' => $key, 'currency' => $row['currency'] ?? null, 'quote_unit' => 'unknown', 'status' => 'unresolved', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('market_listings')->where('identity_key', $hash)->first();
    }

    public function listing(StockHolding $holding): object
    {
        $identity = $holding->isin ? 'isin:'.strtoupper($holding->isin) : ($holding->wkn ? 'wkn:'.strtoupper($holding->wkn) : 'unresolved:holding:'.$holding->id);
        $xetra = IntradayMarketIdentity::isXetra($holding->toArray());
        $us = YahooFinanceMarketData::supports($holding->toArray());
        $mic = strtoupper($holding->mic_code ?? '');
        $venue = $xetra ? 'mic:'.($mic ?: 'XETR') : ($us ? 'consolidated:US' : ($mic ? 'mic:'.$mic : 'unresolved:holding:'.$holding->id));
        $currency = strtoupper($holding->currency ?? '');
        $known = ($xetra || $us) && preg_match('/^[A-Z]{3}$/', $currency) && ! str_starts_with($identity, 'unresolved:');

        return DB::transaction(function () use ($holding, $identity, $venue, $currency, $known, $xetra, $us, $mic): object {
            DB::table('market_instruments')->insertOrIgnore(['identity_key' => $identity, 'isin' => $holding->isin, 'wkn' => $holding->wkn,
                'name' => $holding->name ?? $holding->symbol, 'instrument_type' => $holding->instrument_type, 'created_at' => now(), 'updated_at' => now()]);
            $instrument = DB::table('market_instruments')->where('identity_key', $identity)->value('id');
            $key = hash('sha256', json_encode([$instrument, $venue, $currency, 'unit'], JSON_THROW_ON_ERROR));
            DB::table('market_listings')->insertOrIgnore(['identity_key' => $key, 'market_instrument_id' => $instrument, 'venue_key' => $venue,
                'mic' => $us ? null : ($mic ?: ($xetra ? 'XETR' : null)), 'currency' => $currency ?: null, 'quote_unit' => 'unit',
                'timezone' => $us ? 'America/New_York' : ($xetra ? 'Europe/Berlin' : null), 'calendar_key' => $us ? 'US' : ($xetra ? 'XETRA' : null),
                'status' => $known ? 'verified' : 'unresolved', 'created_at' => now(), 'updated_at' => now()]);
            $listing = DB::table('market_listings')->where('identity_key', $key)->first();
            if ((int) $holding->market_listing_id !== $listing->id) {
                $holding->forceFill(['market_listing_id' => $listing->id, 'latest_market_price_id' => null])->saveQuietly();
            }
            $symbol = $us ? $holding->symbol : config('services.free_intraday.yahoo_xetra_symbols.'.$holding->isin);
            if ($symbol && $known) {
                DB::table('market_source_instruments')->insertOrIgnore(['market_listing_id' => $listing->id, 'source_key' => 'yahoo',
                    'symbol' => $symbol, 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            }

            return $listing;
        }, 3);
    }
}
