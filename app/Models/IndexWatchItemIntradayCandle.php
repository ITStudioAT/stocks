<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['index_watch_item_id', 'trading_date', 'interval', 'as_of', 'timestamp', 'gmtoffset', 'datetime', 'open', 'high', 'low', 'close', 'volume', 'currency', 'source_key', 'source_name', 'source_url', 'raw_payload'])]
class IndexWatchItemIntradayCandle extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trading_date' => 'date',
            'as_of' => 'datetime',
            'timestamp' => 'integer',
            'gmtoffset' => 'integer',
            'open' => 'decimal:8',
            'high' => 'decimal:8',
            'low' => 'decimal:8',
            'close' => 'decimal:8',
            'volume' => 'integer',
            'raw_payload' => 'array',
        ];
    }

    /** @return BelongsTo<IndexWatchItem, $this> */
    public function indexWatchItem(): BelongsTo
    {
        return $this->belongsTo(IndexWatchItem::class);
    }
}
