<?php

namespace App\Models;

use Database\Factories\DepotTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['depot_id', 'stock_holding_id', 'type', 'pieces', 'total_amount', 'currency', 'unit_price', 'cash_delta', 'balance_after', 'booked_at', 'note', 'is_external_cashflow', 'affects_performance'])]
class DepotTransaction extends Model
{
    /** @use HasFactory<DepotTransactionFactory> */
    use HasFactory;

    public const StockTypes = ['buy', 'sell'];

    public const CashTypes = [
        'opening_balance',
        'deposit',
        'withdrawal',
        'dividend',
        'interest',
        'fee',
        'tax',
        'broker_bonus',
    ];

    public const ExternalCashflowTypes = [
        'opening_balance',
        'deposit',
        'withdrawal',
    ];

    public const PerformanceCashTypes = [
        'dividend',
        'interest',
        'fee',
        'tax',
    ];

    public const PositiveCashDeltaTypes = [
        'opening_balance',
        'deposit',
        'dividend',
        'interest',
        'broker_bonus',
        'sell',
    ];

    public const NegativeCashDeltaTypes = [
        'withdrawal',
        'fee',
        'tax',
        'buy',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'currency' => 'EUR',
        'is_external_cashflow' => false,
        'affects_performance' => false,
    ];

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
            'is_external_cashflow' => 'boolean',
            'affects_performance' => 'boolean',
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
