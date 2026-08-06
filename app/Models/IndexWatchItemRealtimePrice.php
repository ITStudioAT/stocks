<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['index_watch_item_id', 'trading_date', 'price', 'start_price', 'previous_close', 'change_percent', 'currency', 'as_of', 'source_name', 'raw_payload'])]
class IndexWatchItemRealtimePrice extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trading_date' => 'date',
            'price' => 'decimal:8',
            'start_price' => 'decimal:8',
            'previous_close' => 'decimal:8',
            'change_percent' => 'decimal:6',
            'as_of' => 'datetime',
            'raw_payload' => 'array',
        ];
    }

    /** @return BelongsTo<IndexWatchItem, $this> */
    public function indexWatchItem(): BelongsTo
    {
        return $this->belongsTo(IndexWatchItem::class);
    }
}
