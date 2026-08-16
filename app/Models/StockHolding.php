<?php

namespace App\Models;

use Database\Factories\StockHoldingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['symbol', 'name', 'subtitle', 'isin', 'wkn', 'exchange', 'mic_code', 'instrument_type', 'country', 'currency', 'latest_price', 'flatex_price', 'start_price', 'end_price', 'end_price_24', 'end_price_48', 'latest_price_fetched_at', 'latest_price_source', 'latest_price_source_url', 'latest_price_as_of', 'trading_times', 'preferred_venue', 'preferred_mic', 'preferred_source_key', 'latest_stock_price_id', 'latest_realtime_price_id', 'price_status', 'latest_price_type', 'price_spread_pct', 'source_verified_at'])]
class StockHolding extends Model
{
    /** @use HasFactory<StockHoldingFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latest_price' => 'decimal:6',
            'flatex_price' => 'decimal:6',
            'start_price' => 'decimal:8',
            'end_price' => 'decimal:8',
            'end_price_24' => 'decimal:8',
            'end_price_48' => 'decimal:8',
            'latest_price_fetched_at' => 'datetime',
            'price_spread_pct' => 'decimal:6',
            'source_verified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<StockPrice, $this>
     */
    public function latestStockPrice(): BelongsTo
    {
        return $this->belongsTo(StockPrice::class, 'latest_stock_price_id');
    }

    /**
     * @return BelongsTo<StockRealtimePrice, $this>
     */
    public function latestRealtimePrice(): BelongsTo
    {
        return $this->belongsTo(StockRealtimePrice::class, 'latest_realtime_price_id');
    }

    /**
     * @return HasMany<StockRealtimePrice, $this>
     */
    public function realtimePrices(): HasMany
    {
        return $this->hasMany(StockRealtimePrice::class);
    }

    /**
     * @return HasMany<StockHoldingDailyPrice, $this>
     */
    public function dailyPrices(): HasMany
    {
        return $this->hasMany(StockHoldingDailyPrice::class);
    }

    /**
     * @return HasMany<StockAiResearch, $this>
     */
    public function aiResearches(): HasMany
    {
        return $this->hasMany(StockAiResearch::class);
    }

    /**
     * @return HasMany<DepotTransaction, $this>
     */
    public function depotTransactions(): HasMany
    {
        return $this->hasMany(DepotTransaction::class);
    }

    /**
     * Legacy v1 relationship retained temporarily for compatibility reads and migration.
     *
     * @deprecated Use intradayCandles(). Remove this relation with the legacy table after fallback reads are retired.
     *
     * @return HasMany<StockHoldingIntradayPrice, $this>
     */
    public function intradayPrices(): HasMany
    {
        return $this->hasMany(StockHoldingIntradayPrice::class);
    }

    /**
     * @return HasMany<StockHoldingIntradayCandle, $this>
     */
    public function intradayCandles(): HasMany
    {
        return $this->hasMany(StockHoldingIntradayCandle::class);
    }
}
