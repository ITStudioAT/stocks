<?php

namespace App\Services;

use App\Models\Depot;
use App\Models\DepotTransaction;
use App\Models\StockHolding;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DepotTransactionBooker
{
    public function bookCash(Depot $depot, string $type, string $totalAmount, ?string $note = null): DepotTransaction
    {
        if (! in_array($type, ['deposit', 'withdrawal'], true)) {
            throw ValidationException::withMessages([
                'type' => 'Unknown cash transaction type.',
            ]);
        }

        return $this->book(
            depot: $depot,
            type: $type,
            totalAmount: $totalAmount,
            stockHolding: null,
            pieces: null,
            note: $note,
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
    ): DepotTransaction {
        if (! in_array($type, ['buy', 'sell'], true)) {
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
        );
    }

    public function positionPieces(Depot $depot, StockHolding $holding): string
    {
        $pieces = DepotTransaction::query()
            ->where('depot_id', $depot->id)
            ->where('stock_holding_id', $holding->id)
            ->whereIn('type', ['buy', 'sell'])
            ->get(['type', 'pieces'])
            ->reduce(function (float $sum, DepotTransaction $transaction): float {
                $pieces = (float) $transaction->pieces;

                return $transaction->type === 'buy'
                    ? $sum + $pieces
                    : $sum - $pieces;
            }, 0.0);

        return $this->decimal($pieces, 8);
    }

    private function book(
        Depot $depot,
        string $type,
        string $totalAmount,
        ?StockHolding $stockHolding,
        ?string $pieces,
        ?string $note,
        ?Carbon $bookedAt = null,
    ): DepotTransaction {
        return DB::transaction(function () use ($depot, $type, $totalAmount, $stockHolding, $pieces, $note, $bookedAt): DepotTransaction {
            $lockedDepot = Depot::query()
                ->whereKey($depot->id)
                ->lockForUpdate()
                ->firstOrFail();
            $amount = (float) $totalAmount;
            $piecesAmount = $pieces === null ? null : (float) $pieces;
            $cashDelta = $this->cashDelta($type, $amount);
            $balanceAfter = round(((float) $lockedDepot->account_balance) + $cashDelta, 2);

            if ($balanceAfter < 0) {
                throw ValidationException::withMessages([
                    'total_amount' => 'The depot cash balance is too low for this booking.',
                ]);
            }

            if ($type === 'sell' && $stockHolding !== null && $piecesAmount !== null) {
                $ownedPieces = (float) $this->positionPieces($lockedDepot, $stockHolding);

                if ($piecesAmount > $ownedPieces) {
                    throw ValidationException::withMessages([
                        'pieces' => 'Cannot sell more pieces than the depot owns.',
                    ]);
                }
            }

            $lockedDepot->update([
                'account_balance' => $this->decimal($balanceAfter, 2),
            ]);

            return DepotTransaction::query()->create([
                'depot_id' => $lockedDepot->id,
                'stock_holding_id' => $stockHolding?->id,
                'type' => $type,
                'pieces' => $piecesAmount === null ? null : $this->decimal($piecesAmount, 8),
                'total_amount' => $this->decimal($amount, 2),
                'unit_price' => $piecesAmount === null ? null : $this->decimal($amount / $piecesAmount, 8),
                'cash_delta' => $this->decimal($cashDelta, 2),
                'balance_after' => $this->decimal($balanceAfter, 2),
                'booked_at' => $bookedAt ?? now(),
                'note' => $note,
            ]);
        }, attempts: 3);
    }

    private function cashDelta(string $type, float $amount): float
    {
        return match ($type) {
            'deposit', 'sell' => $amount,
            'withdrawal', 'buy' => -$amount,
        };
    }

    private function decimal(float $value, int $places): string
    {
        return number_format($value, $places, '.', '');
    }
}
