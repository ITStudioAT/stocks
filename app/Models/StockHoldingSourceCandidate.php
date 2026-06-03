<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['stock_holding_id', 'source_key', 'source_url', 'venue', 'mic', 'parser_key', 'confidence_score', 'last_success_at', 'last_failed_at', 'consecutive_failures', 'active', 'verified'])]
class StockHoldingSourceCandidate extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confidence_score' => 'integer',
            'last_success_at' => 'datetime',
            'last_failed_at' => 'datetime',
            'consecutive_failures' => 'integer',
            'active' => 'boolean',
            'verified' => 'boolean',
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
