<?php

namespace App\Models;

use Database\Factories\HomepageColorSchemeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'homepage_id',
    'scheme_name',
    'source_colors_json',
    'role_colors_json',
    'generated_palette_json',
    'usage_tokens_json',
    'css_variables',
    'warnings_json',
    'harmony_score',
    'accessibility_mode',
    'is_active',
])]
class HomepageColorScheme extends Model
{
    /** @use HasFactory<HomepageColorSchemeFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'source_colors_json' => 'array',
            'role_colors_json' => 'array',
            'generated_palette_json' => 'array',
            'usage_tokens_json' => 'array',
            'warnings_json' => 'array',
            'harmony_score' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function homepage(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'homepage_id');
    }
}
