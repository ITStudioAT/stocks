<?php

namespace App\Models;

use Database\Factories\StockAiResearchSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['stock_ai_research_id', 'user_id', 'stock_holding_id', 'url', 'url_hash', 'title', 'source_type', 'confidence', 'is_primary', 'retrieved_at'])]
class StockAiResearchSource extends Model
{
    /** @use HasFactory<StockAiResearchSourceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'retrieved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<StockAiResearch, $this>
     */
    public function research(): BelongsTo
    {
        return $this->belongsTo(StockAiResearch::class, 'stock_ai_research_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<StockHolding, $this>
     */
    public function stockHolding(): BelongsTo
    {
        return $this->belongsTo(StockHolding::class);
    }
}
