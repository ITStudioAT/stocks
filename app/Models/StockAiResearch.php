<?php

namespace App\Models;

use Database\Factories\StockAiResearchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['id', 'user_id', 'stock_holding_id', 'previous_research_id', 'status', 'has_material_update', 'summary', 'developments', 'calculation_snapshot', 'calculated_events', 'assessment', 'analyst_consensus', 'stronger_case', 'weaker_case', 'trump_connection', 'recommendation', 'recommendation_buy_pct', 'recommendation_hold_pct', 'recommendation_sell_pct', 'justification', 'known_information', 'message', 'error', 'started_at', 'finished_at'])]
class StockAiResearch extends Model
{
    /** @use HasFactory<StockAiResearchFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'stock_ai_researches';

    protected $attributes = [
        'status' => 'queued',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'has_material_update' => 'boolean',
            'developments' => 'array',
            'calculation_snapshot' => 'array',
            'calculated_events' => 'array',
            'assessment' => 'array',
            'analyst_consensus' => 'array',
            'recommendation_buy_pct' => 'integer',
            'recommendation_hold_pct' => 'integer',
            'recommendation_sell_pct' => 'integer',
            'known_information' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
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

    /**
     * @return BelongsTo<StockAiResearch, $this>
     */
    public function previousResearch(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_research_id');
    }

    /**
     * @return HasMany<StockAiResearchSource, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(StockAiResearchSource::class);
    }
}
