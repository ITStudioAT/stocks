<?php

namespace App\Models;

use Database\Factories\DepotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'account_balance', 'is_active', 'provider', 'account_number', 'description'])]
class Depot extends Model
{
    /** @use HasFactory<DepotFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'account_balance' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<StockHolding, $this>
     */
    public function stockHoldings(): HasMany
    {
        return $this->hasMany(StockHolding::class);
    }
}
