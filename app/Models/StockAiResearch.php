<?php

namespace App\Models;

use Database\Factories\StockAiResearchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['id', 'user_id', 'stock_holding_id', 'previous_research_id', 'status', 'has_material_update', 'summary', 'stronger_case', 'weaker_case', 'trump_connection', 'recommendation', 'justification', 'known_information', 'message', 'error', 'started_at', 'finished_at'])]
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
