<?php

namespace App\Models;

use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['company_id', 'name', 'signature', 'headline', 'subheadline', 'body', 'is_published', 'is_active'])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'signature';
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasMany<WebsiteAnalysis, $this>
     */
    public function websiteAnalyses(): HasMany
    {
        return $this->hasMany(WebsiteAnalysis::class);
    }

    /**
     * @return HasMany<HomepageColorScheme, $this>
     */
    public function homepageColorSchemes(): HasMany
    {
        return $this->hasMany(HomepageColorScheme::class, 'homepage_id');
    }

    /**
     * @return HasOne<HomepageColorScheme, $this>
     */
    public function activeHomepageColorScheme(): HasOne
    {
        return $this->hasOne(HomepageColorScheme::class, 'homepage_id')->where('is_active', true)->latestOfMany();
    }
}
