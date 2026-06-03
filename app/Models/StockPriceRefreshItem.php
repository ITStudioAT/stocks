<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['refresh_run_id', 'stock_holding_id', 'status', 'attempted_sources', 'selected_quote_id', 'selected_stock_price_id', 'error_message'])]
class StockPriceRefreshItem extends Model
{
    protected $attributes = [
        'status' => 'queued',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempted_sources' => 'array',
        ];
    }

    /**
     * @return BelongsTo<StockPriceRefreshRun, $this>
     */
    public function refreshRun(): BelongsTo
    {
        return $this->belongsTo(StockPriceRefreshRun::class, 'refresh_run_id');
    }

    /**
     * @return BelongsTo<StockHolding, $this>
     */
    public function stockHolding(): BelongsTo
    {
        return $this->belongsTo(StockHolding::class);
    }

    /**
     * @return BelongsTo<StockPriceQuote, $this>
     */
    public function selectedQuote(): BelongsTo
    {
        return $this->belongsTo(StockPriceQuote::class, 'selected_quote_id');
    }

    /**
     * @return BelongsTo<StockPrice, $this>
     */
    public function selectedStockPrice(): BelongsTo
    {
        return $this->belongsTo(StockPrice::class, 'selected_stock_price_id');
    }
}
