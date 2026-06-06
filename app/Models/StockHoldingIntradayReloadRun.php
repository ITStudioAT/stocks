<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['id', 'stock_holding_id', 'status', 'total_count', 'processed_count', 'success_count', 'failed_count', 'current', 'date_from', 'date_to', 'stored_count', 'message', 'error_summary', 'started_at', 'finished_at'])]
class StockHoldingIntradayReloadRun extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $attributes = [
        'status' => 'queued',
        'total_count' => 0,
        'processed_count' => 0,
        'success_count' => 0,
        'failed_count' => 0,
        'stored_count' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_from' => 'date',
            'date_to' => 'date',
            'total_count' => 'integer',
            'processed_count' => 'integer',
            'success_count' => 'integer',
            'failed_count' => 'integer',
            'stored_count' => 'integer',
            'error_summary' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
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
