<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['last_name', 'first_name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'auth_revision', 'password_initialized_at'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    protected $attributes = [
        'auth_revision' => 1,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'auth_revision' => 'integer',
            'email_verified_at' => 'datetime',
            'is_protected' => 'boolean',
            'password' => 'hashed',
            'password_initialized_at' => 'datetime',
        ];
    }

    /**
     * @return Attribute<string, never>
     */
    protected function name(): Attribute
    {
        return Attribute::get(fn (): string => trim("{$this->last_name} {$this->first_name}"));
    }

    /**
     * @return HasMany<StockAiResearch, $this>
     */
    public function stockAiResearches(): HasMany
    {
        return $this->hasMany(StockAiResearch::class);
    }
}
