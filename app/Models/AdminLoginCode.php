<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['email', 'code_hash', 'attempts', 'expires_at', 'consumed_at'])]
#[Hidden(['code_hash'])]
class AdminLoginCode extends Model
{
    use MassPrunable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function prunable(): Builder
    {
        return static::query()->where(function (Builder $query): void {
            $query
                ->where('expires_at', '<=', now()->subDay())
                ->orWhere('consumed_at', '<=', now()->subDay());
        });
    }
}
