<?php

namespace App\Models;

use App\Services\MarketPriceCompatibility;
use Database\Factories\StockHoldingDailyPriceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['stock_holding_id', 'trading_date', 'open', 'high', 'low', 'close', 'adjusted_close', 'volume', 'currency', 'source_key', 'source_name', 'source_url', 'raw_payload'])]
class StockHoldingDailyPrice extends Model
{
    /** @use HasFactory<StockHoldingDailyPriceFactory> */
    use HasFactory;

    public function newModelQuery(): Builder
    {
        return app(MarketPriceCompatibility::class)->apply(parent::newModelQuery(), $this);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trading_date' => 'date',
            'open' => 'decimal:8',
            'high' => 'decimal:8',
            'low' => 'decimal:8',
            'close' => 'decimal:8',
            'adjusted_close' => 'decimal:8',
            'volume' => 'integer',
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
