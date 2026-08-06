<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Legacy v1 intraday-price storage retained temporarily for compatibility reads and migration.
 *
 * @deprecated Use StockHoldingIntradayCandle for all v2 intraday data. Remove this model and the
 * stock_holding_intraday_prices table after the remaining legacy rows and fallback reads are retired.
 */
#[Fillable(['stock_holding_id', 'trading_date', 'sample_index', 'source_stock_price_id', 'price', 'currency', 'as_of', 'source_name', 'price_type'])]
class StockHoldingIntradayPrice extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trading_date' => 'date',
            'sample_index' => 'integer',
            'source_stock_price_id' => 'integer',
            'price' => 'decimal:8',
            'as_of' => 'datetime',
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
