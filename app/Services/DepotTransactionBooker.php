<?php

namespace App\Services;

use App\Models\Depot;
use App\Models\DepotTransaction;
use App\Models\StockHolding;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DepotTransactionBooker
{
    private const MaxCashBalance = '9999999999999.99';

    public function __construct(private DashboardPerformanceSnapshots $performanceSnapshots) {}

    public function bookCash(
        Depot $depot,
        string $type,
        ?StockHolding $holding,
        string $totalAmount,
        ?string $note = null,
        ?Carbon $bookedAt = null,
        string $currency = 'EUR',
    ): DepotTransaction {
        if (! in_array($type, DepotTransaction::CashTypes, true)) {
            throw ValidationException::withMessages([
                'type' => 'Unknown cash transaction type.',
            ]);
        }

        return $this->book(
            depot: $depot,
            type: $type,
            totalAmount: $totalAmount,
            stockHolding: $holding,
            pieces: null,
            note: $note,
            bookedAt: $bookedAt,
            currency: $currency,
        );
    }

    public function bookStock(
        Depot $depot,
        StockHolding $holding,
        string $type,
        string $pieces,
        string $totalAmount,
        ?string $note = null,
        ?Carbon $bookedAt = null,
        string $currency = 'EUR',
    ): DepotTransaction {
        if (! in_array($type, DepotTransaction::StockTypes, true)) {
            throw ValidationException::withMessages([
                'type' => 'Unknown stock transaction type.',
            ]);
        }

        return $this->book(
            depot: $depot,
            type: $type,
            totalAmount: $totalAmount,
            stockHolding: $holding,
            pieces: $pieces,
            note: $note,
            bookedAt: $bookedAt,
            currency: $currency,
        );
    }

    public function positionPieces(Depot $depot, StockHolding $holding): string
    {
        $pieces = DepotTransaction::query()
            ->where('depot_id', $depot->id)
            ->where('stock_holding_id', $holding->id)
            ->whereIn('type', DepotTransaction::StockTypes)
            ->get(['type', 'pieces'])
            ->reduce(function (float $sum, DepotTransaction $transaction): float {
                $pieces = (float) $transaction->pieces;

                return $transaction->type === 'buy'
                    ? $sum + $pieces
                    : $sum - $pieces;
            }, 0.0);

        return $this->decimal($pieces, 8);
    }

    /**
     * @param  array{type?: string, stock_holding_id?: int|null, total_amount?: numeric-string|int|float, pieces?: numeric-string|int|float, booked_at?: string, note?: string|null}  $changes
     */
    public function update(Depot $depot, DepotTransaction $transaction, array $changes): DepotTransaction
    {
        return DB::transaction(function () use ($depot, $transaction, $changes): DepotTransaction {
            $lockedDepot = Depot::query()->whereKey($depot->id)->where('is_active', true)->lockForUpdate()->firstOrFail();
            $transaction = $lockedDepot->transactions()->findOrFail($transaction->id);
            $originalDate = $transaction->booked_at;
            $originalCashDelta = $transaction->cash_delta;
            $type = $changes['type'] ?? $transaction->type;
            $isStockTrade = in_array($transaction->type, DepotTransaction::StockTypes, true);
            $canChangeType = ! $isStockTrade && $transaction->type !== 'opening_balance';

            if ($type !== $transaction->type && (! $canChangeType || $type === 'opening_balance' || ! in_array($type, DepotTransaction::CashTypes, true))) {
                throw ValidationException::withMessages(['type' => 'This transaction cannot be changed to the selected type.']);
            }
            if ($isStockTrade && array_key_exists('stock_holding_id', $changes)) {
                throw ValidationException::withMessages(['stock_holding_id' => 'The stock on a trade cannot be changed.']);
            }
            if (isset($changes['pieces']) && (! $isStockTrade || $transaction->stock_holding_id === null)) {
                throw ValidationException::withMessages(['pieces' => 'Pieces can only be changed on a trade linked to a stock.']);
            }

            if (isset($changes['booked_at'])) {
                $date = Carbon::createFromFormat('!Y-m-d', $changes['booked_at']);
                if ($date->toDateString() !== $originalDate->toDateString()) {
                    $transaction->booked_at = $date;
                }
            }
            $transaction->fill(collect($changes)->only(['stock_holding_id', 'note'])->all());

            if (isset($changes['total_amount'])) {
                $transaction->total_amount = (string) BigDecimal::of((string) $changes['total_amount'])->toScale(2);
            }
            if (isset($changes['pieces'])) {
                $transaction->pieces = (string) BigDecimal::of((string) $changes['pieces'])->toScale(8);
            }
            if ($type !== $transaction->type) {
                $transaction->type = $type;
                $transaction->is_external_cashflow = in_array($type, DepotTransaction::ExternalCashflowTypes, true);
                $transaction->affects_performance = in_array($type, DepotTransaction::PerformanceCashTypes, true);
            }
            if ($transaction->isDirty(['type', 'total_amount'])) {
                $transaction->cash_delta = (string) $this->cashDelta($type, BigDecimal::of($transaction->total_amount));
            }
            if ($isStockTrade && $transaction->isDirty(['pieces', 'total_amount']) && BigDecimal::of($transaction->pieces ?? '0')->isPositive()) {
                $unitPrice = BigDecimal::of($transaction->total_amount)->dividedBy($transaction->pieces, 8, RoundingMode::HalfUp);
                if ($unitPrice->isGreaterThan('999999999999.99999999')) {
                    throw ValidationException::withMessages(['total_amount' => 'The calculated price per piece is too large.']);
                }
                $transaction->unit_price = (string) $unitPrice;
            }

            $transaction->save();

            if ($isStockTrade && $transaction->stock_holding_id !== null && $transaction->wasChanged(['booked_at', 'pieces'])) {
                $this->validateStockHistory($lockedDepot, $transaction);
            }

            if ($transaction->wasChanged(['booked_at', 'cash_delta'])) {
                $balance = BigDecimal::of($lockedDepot->account_balance)->minus($originalCashDelta)->plus($transaction->cash_delta);
                $this->validateClosingBalance($balance);
                if ($transaction->wasChanged('cash_delta')) {
                    $lockedDepot->update(['account_balance' => (string) $balance]);
                }
                $this->rebuildRunningBalances($lockedDepot, $balance);
            }

            if ($transaction->wasChanged(['booked_at', 'type', 'total_amount', 'stock_holding_id', 'pieces'])) {
                $futureBoundary = now()->endOfDay();
                $affectedDate = $type === 'opening_balance' || $originalDate->gt($futureBoundary) || $transaction->booked_at->gt($futureBoundary)
                    ? null
                    : $originalDate->min($transaction->booked_at);
                $this->performanceSnapshots->invalidate($lockedDepot->id, $affectedDate);
            }

            return $transaction->refresh();
        }, attempts: 3);
    }

    private function validateStockHistory(Depot $depot, DepotTransaction $transaction): void
    {
        $pieces = BigDecimal::zero();
        $trades = $depot->transactions()
            ->where('stock_holding_id', $transaction->stock_holding_id)
            ->whereIn('type', DepotTransaction::StockTypes)
            ->orderBy('booked_at')->orderBy('id')
            ->get(['type', 'pieces']);

        foreach ($trades as $trade) {
            $tradePieces = $trade->pieces ?? '0';
            $pieces = $trade->type === 'buy' ? $pieces->plus($tradePieces) : $pieces->minus($tradePieces);

            if ($pieces->isNegative()) {
                throw ValidationException::withMessages([
                    $transaction->wasChanged('pieces') ? 'pieces' : 'booked_at' => 'A sale would exceed the pieces owned on its booking date.',
                ]);
            }
        }
    }

    private function rebuildRunningBalances(Depot $depot, BigDecimal $closingBalance): void
    {
        $transactions = $depot->transactions()->orderByDesc('booked_at')->orderByDesc('id')
            ->get(['id', 'cash_delta', 'balance_after']);
        $balance = $closingBalance;

        foreach ($transactions as $transaction) {
            if ($balance->abs()->isGreaterThan(self::MaxCashBalance)) {
                throw ValidationException::withMessages(['total_amount' => 'A running cash balance would be too large.']);
            }
            if (! $balance->isEqualTo($transaction->balance_after)) {
                $depot->transactions()->whereKey($transaction->id)->toBase()->update(['balance_after' => (string) $balance]);
            }

            $balance = $balance->minus($transaction->cash_delta);
        }
    }

    private function validateClosingBalance(BigDecimal $balance): void
    {
        if ($balance->isNegative()) {
            throw ValidationException::withMessages([
                'total_amount' => 'The depot cash balance is too low for this booking.',
            ]);
        }
        if ($balance->isGreaterThan(self::MaxCashBalance)) {
            throw ValidationException::withMessages(['total_amount' => 'The depot cash balance would be too large.']);
        }
    }

    private function book(
        Depot $depot,
        string $type,
        string $totalAmount,
        ?StockHolding $stockHolding,
        ?string $pieces,
        ?string $note,
        ?Carbon $bookedAt = null,
        string $currency = 'EUR',
    ): DepotTransaction {
        return DB::transaction(function () use ($depot, $type, $totalAmount, $stockHolding, $pieces, $note, $bookedAt, $currency): DepotTransaction {
            $lockedDepot = Depot::query()
                ->whereKey($depot->id)
                ->lockForUpdate()
                ->firstOrFail();
            $amount = BigDecimal::of($totalAmount)->toScale(2, RoundingMode::HalfUp);
            $piecesAmount = $pieces === null ? null : (float) $pieces;
            $cashDelta = $this->cashDelta($type, $amount);
            $balanceAfter = BigDecimal::of($lockedDepot->account_balance)->plus($cashDelta);
            $this->validateClosingBalance($balanceAfter);

            if ($type === 'sell' && $stockHolding !== null && $piecesAmount !== null) {
                $ownedPieces = (float) $this->positionPieces($lockedDepot, $stockHolding);

                if ($piecesAmount > $ownedPieces) {
                    throw ValidationException::withMessages([
                        'pieces' => 'Cannot sell more pieces than the depot owns.',
                    ]);
                }
            }

            $lockedDepot->update([
                'account_balance' => (string) $balanceAfter,
            ]);

            $bookingDate = $bookedAt ?? now();
            $transaction = DepotTransaction::query()->create([
                'depot_id' => $lockedDepot->id,
                'stock_holding_id' => $stockHolding?->id,
                'type' => $type,
                'pieces' => $piecesAmount === null ? null : $this->decimal($piecesAmount, 8),
                'total_amount' => (string) $amount,
                'currency' => strtoupper($currency),
                'unit_price' => $piecesAmount === null ? null : $this->decimal((float) $totalAmount / $piecesAmount, 8),
                'cash_delta' => (string) $cashDelta,
                'balance_after' => (string) $balanceAfter,
                'booked_at' => $bookingDate,
                'note' => $note,
                'is_external_cashflow' => in_array($type, DepotTransaction::ExternalCashflowTypes, true),
                'affects_performance' => in_array($type, DepotTransaction::PerformanceCashTypes, true),
            ]);

            if ($lockedDepot->transactions()->where('booked_at', '>', $bookingDate)->exists()) {
                $this->rebuildRunningBalances($lockedDepot, $balanceAfter);
                $transaction->refresh();
            }

            if ($type === 'opening_balance' || $bookingDate->gt(now()->endOfDay())) {
                $this->performanceSnapshots->invalidate($lockedDepot->id);
            } elseif ($bookingDate->lt(now()->startOfDay())) {
                $this->performanceSnapshots->invalidate($lockedDepot->id, $bookingDate);
            }

            return $transaction;
        }, attempts: 3);
    }

    private function cashDelta(string $type, BigDecimal $amount): BigDecimal
    {
        if (in_array($type, DepotTransaction::PositiveCashDeltaTypes, true)) {
            return $amount;
        }

        if (in_array($type, DepotTransaction::NegativeCashDeltaTypes, true)) {
            return $amount->negated();
        }

        throw ValidationException::withMessages([
            'type' => 'Unknown transaction type.',
        ]);
    }

    private function decimal(float $value, int $places): string
    {
        return number_format($value, $places, '.', '');
    }
}
