<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['stock_holding_id', 'source_key', 'source_name', 'source_url', 'source_quality', 'venue', 'mic', 'isin', 'wkn', 'symbol', 'currency', 'bid', 'ask', 'last', 'close', 'nav', 'price', 'price_type', 'spread_abs', 'spread_pct', 'as_of', 'fetched_at', 'freshness_status', 'validation_status', 'validation_errors', 'raw_text_hash', 'raw_payload'])]
class StockPriceQuote extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bid' => 'decimal:8',
            'ask' => 'decimal:8',
            'last' => 'decimal:8',
            'close' => 'decimal:8',
            'nav' => 'decimal:8',
            'price' => 'decimal:8',
            'spread_abs' => 'decimal:8',
            'spread_pct' => 'decimal:6',
            'as_of' => 'datetime',
            'fetched_at' => 'datetime',
            'validation_errors' => 'array',
            'raw_payload' => 'array',
        ];
    }

    /**
     * @return BelongsTo<StockHolding, $this>
     */
    public function stockHolding(): BelongsTo
    {
        return $this->belongsTo(StockHolding::class);
    }
}
