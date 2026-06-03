<?php

namespace App\Models;

use Database\Factories\StockHoldingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['symbol', 'name', 'isin', 'wkn', 'exchange', 'mic_code', 'instrument_type', 'country', 'currency', 'latest_price', 'latest_price_fetched_at', 'latest_price_source', 'latest_price_source_url', 'latest_price_as_of', 'trading_times', 'preferred_venue', 'preferred_mic', 'preferred_source_key', 'latest_quote_id', 'latest_stock_price_id', 'price_status', 'latest_price_type', 'price_spread_pct', 'source_verified_at'])]
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
            'latest_price_fetched_at' => 'datetime',
            'price_spread_pct' => 'decimal:6',
            'source_verified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<StockPriceQuote, $this>
     */
    public function latestQuote(): BelongsTo
    {
        return $this->belongsTo(StockPriceQuote::class, 'latest_quote_id');
    }

    /**
     * @return BelongsTo<StockPrice, $this>
     */
    public function latestStockPrice(): BelongsTo
    {
        return $this->belongsTo(StockPrice::class, 'latest_stock_price_id');
    }

    /**
     * @return HasMany<StockPriceQuote, $this>
     */
    public function priceQuotes(): HasMany
    {
        return $this->hasMany(StockPriceQuote::class);
    }

    /**
     * @return HasMany<StockHoldingSourceCandidate, $this>
     */
    public function sourceCandidates(): HasMany
    {
        return $this->hasMany(StockHoldingSourceCandidate::class);
    }
}
