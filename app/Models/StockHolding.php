<?php

namespace App\Models;

use Database\Factories\StockHoldingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['depot_id', 'symbol', 'name', 'isin', 'wkn', 'exchange', 'mic_code', 'instrument_type', 'country', 'currency', 'latest_price', 'latest_price_fetched_at', 'latest_price_source', 'latest_price_source_url', 'latest_price_as_of', 'trading_times'])]
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
        ];
    }

    /**
     * @return BelongsTo<Depot, $this>
     */
    public function depot(): BelongsTo
    {
        return $this->belongsTo(Depot::class);
    }
}
