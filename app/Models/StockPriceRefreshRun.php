<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['id', 'status', 'total_count', 'processed_count', 'success_count', 'stale_count', 'unavailable_count', 'invalid_count', 'suspicious_count', 'started_at', 'finished_at', 'error_summary'])]
class StockPriceRefreshRun extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $attributes = [
        'status' => 'queued',
        'total_count' => 0,
        'processed_count' => 0,
        'success_count' => 0,
        'stale_count' => 0,
        'unavailable_count' => 0,
        'invalid_count' => 0,
        'suspicious_count' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_count' => 'integer',
            'processed_count' => 'integer',
            'success_count' => 'integer',
            'stale_count' => 'integer',
            'unavailable_count' => 'integer',
            'invalid_count' => 'integer',
            'suspicious_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'error_summary' => 'array',
        ];
    }

    /**
     * @return HasMany<StockPriceRefreshItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(StockPriceRefreshItem::class, 'refresh_run_id');
    }
}
