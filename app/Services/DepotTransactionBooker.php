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
                'currency' => strtoupper($currency),
                'unit_price' => $piecesAmount === null ? null : $this->decimal($amount / $piecesAmount, 8),
                'cash_delta' => $this->decimal($cashDelta, 2),
                'balance_after' => $this->decimal($balanceAfter, 2),
                'booked_at' => $bookedAt ?? now(),
                'note' => $note,
                'is_external_cashflow' => in_array($type, DepotTransaction::ExternalCashflowTypes, true),
                'affects_performance' => in_array($type, DepotTransaction::PerformanceCashTypes, true),
            ]);
        }, attempts: 3);
    }

    private function cashDelta(string $type, float $amount): float
    {
        if (in_array($type, DepotTransaction::PositiveCashDeltaTypes, true)) {
            return $amount;
        }

        if (in_array($type, DepotTransaction::NegativeCashDeltaTypes, true)) {
            return -$amount;
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
