<?php

namespace App\Models;

use Database\Factories\StockHistoricalPriceFetchItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['fetch_run_id', 'stock_holding_id', 'status', 'date_from', 'date_to', 'stored_count', 'error_message'])]
class StockHistoricalPriceFetchItem extends Model
{
    /** @use HasFactory<StockHistoricalPriceFetchItemFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'queued',
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
            'stored_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<StockHistoricalPriceFetchRun, $this>
     */
    public function fetchRun(): BelongsTo
    {
        return $this->belongsTo(StockHistoricalPriceFetchRun::class, 'fetch_run_id');
    }

    /**
     * @return BelongsTo<StockHolding, $this>
     */
    public function stockHolding(): BelongsTo
    {
        return $this->belongsTo(StockHolding::class);
    }
}
