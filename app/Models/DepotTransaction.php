<?php

namespace App\Models;

use Database\Factories\DepotTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['depot_id', 'stock_holding_id', 'type', 'pieces', 'total_amount', 'unit_price', 'cash_delta', 'balance_after', 'booked_at', 'note'])]
class DepotTransaction extends Model
{
    /** @use HasFactory<DepotTransactionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pieces' => 'decimal:8',
            'total_amount' => 'decimal:2',
            'unit_price' => 'decimal:8',
            'cash_delta' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'booked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Depot, $this>
     */
    public function depot(): BelongsTo
    {
        return $this->belongsTo(Depot::class);
    }

    /**
     * @return BelongsTo<StockHolding, $this>
     */
    public function stockHolding(): BelongsTo
    {
        return $this->belongsTo(StockHolding::class);
    }
}
