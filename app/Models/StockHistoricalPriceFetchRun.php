<?php

namespace App\Models;

use Database\Factories\StockHistoricalPriceFetchRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['id', 'status', 'date_from', 'date_to', 'total_count', 'processed_count', 'success_count', 'unavailable_count', 'failed_count', 'current', 'started_at', 'finished_at', 'error_summary'])]
class StockHistoricalPriceFetchRun extends Model
{
    /** @use HasFactory<StockHistoricalPriceFetchRunFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $attributes = [
        'status' => 'queued',
        'total_count' => 0,
        'processed_count' => 0,
        'success_count' => 0,
        'unavailable_count' => 0,
        'failed_count' => 0,
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
            'unavailable_count' => 'integer',
            'failed_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'error_summary' => 'array',
        ];
    }

    /**
     * @return HasMany<StockHistoricalPriceFetchItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(StockHistoricalPriceFetchItem::class, 'fetch_run_id');
    }
}
