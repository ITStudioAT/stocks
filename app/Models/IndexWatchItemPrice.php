<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['index_watch_item_id', 'trading_date', 'start_price', 'actual_price', 'last_price', 'actual_price_as_of', 'last_price_as_of', 'raw_payload'])]
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
