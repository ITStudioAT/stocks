<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['index_watch_item_id', 'trading_date', 'start_price', 'actual_price', 'last_price', 'actual_price_as_of', 'last_price_as_of', 'raw_payload', 'intraday_sync_status', 'intraday_candle_count', 'intraday_sync_attempts', 'intraday_http_status', 'intraday_sync_message', 'intraday_checked_at'])]
class IndexWatchItemPrice extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trading_date' => 'date',
            'start_price' => 'decimal:8',
            'actual_price' => 'decimal:8',
            'last_price' => 'decimal:8',
            'actual_price_as_of' => 'datetime',
            'last_price_as_of' => 'datetime',
            'raw_payload' => 'array',
            'intraday_candle_count' => 'integer',
            'intraday_sync_attempts' => 'integer',
            'intraday_http_status' => 'integer',
            'intraday_checked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<IndexWatchItem, $this>
     */
    public function indexWatchItem(): BelongsTo
    {
        return $this->belongsTo(IndexWatchItem::class);
    }
}
