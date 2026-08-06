<?php

namespace App\Models;

use Database\Factories\IndexWatchItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['symbol', 'name', 'isin', 'wkn', 'exchange', 'mic_code', 'instrument_type', 'country', 'currency', 'start_price', 'latest_price', 'last_price', 'latest_price_change_pct', 'latest_price_as_of', 'latest_price_source', 'trading_times', 'raw_payload'])]
class IndexWatchItem extends Model
{
    /** @use HasFactory<IndexWatchItemFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_price' => 'decimal:8',
            'latest_price' => 'decimal:8',
            'last_price' => 'decimal:8',
            'latest_price_change_pct' => 'decimal:6',
            'latest_price_as_of' => 'datetime',
            'raw_payload' => 'array',
        ];
    }

    /**
     * @return HasMany<IndexWatchItemPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(IndexWatchItemPrice::class);
    }

    /**
     * @return HasMany<IndexWatchItemIntradayCandle, $this>
     */
    public function intradayCandles(): HasMany
    {
        return $this->hasMany(IndexWatchItemIntradayCandle::class);
    }
}
