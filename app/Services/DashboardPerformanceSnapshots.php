<?php

namespace App\Services;

use App\Models\Depot;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardPerformanceSnapshots
{
    public const Table = 'depot_performance_snapshots';

    public const SourceTables = [
        'depots', 'depot_transactions', 'stock_holdings', 'stock_prices',
        'stock_realtime_prices', 'stock_holding_daily_prices', 'stock_holding_intraday_candles',
        'market_listings', 'market_prices',
    ];

    public function __construct(private DepotBalanceHistory $history) {}

    /** @return array{balance: ?float, source_markers: list<string>} */
    public function closingBalance(Depot $depot, Carbon $date): array
    {
        return $this->remember($depot, 'day', $date, 'balance', function (Depot $lockedDepot) use ($date): array {
            $balance = $this->history->performanceAccountBalanceAt($lockedDepot, $date->copy()->endOfDay(), $sources);

            return ['balance' => $balance, 'source_markers' => $sources ?? []];
        });
    }

    /** @return array{period: string, source_markers: list<string>, change_amount: ?string, change_percent: ?string} */
    public function completedPeriod(Depot $depot, string $period, Carbon $end): array
    {
        return $this->remember($depot, $period, $end, 'change_amount', function (Depot $lockedDepot) use ($period, $end): array {
            return $this->periodPerformance($lockedDepot, $period, $this->periodOpening($period, $end), $end, $this->closingBalance($lockedDepot, $end));
        });
    }

    /**
     * @param  array{balance: ?float, source_markers: list<string>}  $closing
     * @return array{period: string, source_markers: list<string>, change_amount: ?string, change_percent: ?string}
     */
    public function periodPerformance(Depot $depot, string $period, Carbon $start, Carbon $end, array $closing): array
    {
        return $this->performanceBetween($depot, $period, $start, $end, $this->closingBalance($depot, $start), $closing);
    }

    /**
     * @return array{period: string, source_markers: list<string>, change_amount: ?string, change_percent: ?string, opening_date: string, start_date: string, closing_date: string, opening_balance: ?string, closing_balance: ?string}
     */
    public function completedDepotPeriod(Depot $depot, string $period, Carbon $end): array
    {
        $start = $this->periodOpening($period, $end);
        $opening = $this->history->tradingAccountValuesAt($depot, $start->copy()->endOfDay(), freeOnly: null);
        $closing = $this->history->tradingAccountValuesAt($depot, $end->copy()->endOfDay(), freeOnly: null);
        $performance = $this->performanceBetween(
            $depot,
            $period,
            $start,
            $end,
            ['balance' => $opening['account_balance'], 'source_markers' => $opening['source_markers']],
            ['balance' => $closing['account_balance'], 'source_markers' => $closing['source_markers']],
        );

        return [
            ...$performance,
            'opening_date' => $start->toDateString(),
            'start_date' => $start->copy()->addDay()->toDateString(),
            'closing_date' => $end->toDateString(),
            'opening_balance' => $this->decimal($opening['account_balance']),
            'closing_balance' => $this->decimal($closing['account_balance']),
        ];
    }

    /**
     * @param  array{balance: ?float, source_markers: list<string>}  $opening
     * @param  array{balance: ?float, source_markers: list<string>}  $closing
     * @return array{period: string, source_markers: list<string>, change_amount: ?string, change_percent: ?string}
     */
    private function performanceBetween(Depot $depot, string $period, Carbon $start, Carbon $end, array $opening, array $closing): array
    {
        $cutoff = $start->copy()->endOfDay();
        $end = $end->copy()->endOfDay();
        $capital = $opening['balance'] === null ? null
            : $opening['balance'] + $this->history->externalCashFlowAfter($depot, $cutoff, $end);
        $change = $closing['balance'] === null || $capital === null ? null : $closing['balance'] - $capital;

        if ($period === 'year' && $change !== null) {
            $change -= (float) $depot->transactions()
                ->where('type', 'broker_bonus')
                ->where('booked_at', '>', $cutoff)
                ->where('booked_at', '<=', $end)
                ->sum('cash_delta');
        }

        return [
            'period' => $period,
            'source_markers' => $change === null ? [] : MarketDataProvenance::merge($opening['source_markers'], $closing['source_markers']),
            'change_amount' => $this->decimal($change),
            'change_percent' => $change === null || $capital <= 0 ? null : $this->decimal($change / $capital * 100),
        ];
    }

    private function decimal(?float $value): ?string
    {
        return $value === null ? null : number_format($value, 2, '.', '');
    }

    public function backfill(Depot $depot): void
    {
        $firstBooking = $depot->transactions()->min('booked_at');
        $firstDate = $firstBooking === null ? $depot->created_at : Carbon::parse($firstBooking);

        if ($firstDate === null) {
            return;
        }

        $today = now()->startOfDay();

        foreach (['week', 'month', 'year'] as $period) {
            $end = $this->periodEnd($firstDate, $period);

            while ($end->lt($today)) {
                $this->completedPeriod($depot, $period, $end);
                $end = $this->periodEnd($end->copy()->addDay(), $period);
            }
        }
    }

    public function invalidate(?int $depotId = null, ?Carbon $from = null): void
    {
        DB::transaction(function () use ($depotId, $from): void {
            Depot::query()->when($depotId !== null, fn ($query) => $query->whereKey($depotId))
                ->orderBy('id')->lockForUpdate()->get(['id']);

            DB::table(self::Table)
                ->when($depotId !== null, fn (Builder $query) => $query->where('depot_id', $depotId))
                ->when($from !== null, fn (Builder $query) => $query->where('end_date', '>=', $from->toDateString()))
                ->delete();
        }, attempts: 3);
    }

    public function expireIncompleteFrom(Carbon $from): void
    {
        $now = now();

        DB::transaction(function () use ($from, $now): void {
            Depot::query()->orderBy('id')->lockForUpdate()->get(['id']);

            DB::table(self::Table)
                ->where('end_date', '>=', $from->toDateString())
                ->where('expires_at', '>', $now)
                ->update(['expires_at' => $now]);
        }, attempts: 3);
    }

    public function periodEnd(Carbon $date, string $period): Carbon
    {
        return match ($period) {
            'week' => $date->copy()->endOfWeek(Carbon::SUNDAY),
            'month' => $date->copy()->endOfMonth(),
            'year' => $date->copy()->endOfYear(),
        };
    }

    public function periodOpening(string $period, Carbon $end): Carbon
    {
        return match ($period) {
            'week' => $end->copy()->startOfWeek(Carbon::MONDAY)->subDay(),
            'month' => $end->copy()->startOfMonth()->subDay(),
            'year' => $end->copy()->startOfYear(),
        };
    }

    /** @param non-empty-list<string> $dates */
    public function retryIncomplete(Depot $depot, array $dates, ?string $completedPeriod): void
    {
        $end = max($dates);

        DB::transaction(function () use ($depot, $dates, $end, $completedPeriod): void {
            Depot::query()->lockForUpdate()->findOrFail($depot->id);
            DB::table(self::Table)->where('depot_id', $depot->id)->where('expires_at', '>', now())
                ->where(function (Builder $query) use ($dates, $end, $completedPeriod): void {
                    $query->where(fn (Builder $day) => $day->where('period', 'day')->whereIn('end_date', $dates));
                    if ($completedPeriod !== null) {
                        $query->orWhere(fn (Builder $period) => $period->where('period', $completedPeriod)->where('end_date', $end));
                    }
                })->update(['expires_at' => now()]);
        }, attempts: 3);

        foreach ($dates as $date) {
            $this->closingBalance($depot, Carbon::parse($date));
        }
        if ($completedPeriod !== null) {
            $this->completedPeriod($depot, $completedPeriod, Carbon::parse($end));
        }
    }

    /** @return array{period: string, source_markers: list<string>, change_amount: ?string, change_percent: ?string} */
    public function calculatePeriodFresh(Depot $depot, string $period, Carbon $end): array
    {
        $start = $this->periodOpening($period, $end);
        $opening = $this->history->performanceAccountBalanceAt($depot, $start->copy()->endOfDay(), $openingSources);
        $closing = $this->history->performanceAccountBalanceAt($depot, $end->copy()->endOfDay(), $closingSources);

        return $this->performanceBetween(
            $depot,
            $period,
            $start,
            $end,
            ['balance' => $opening, 'source_markers' => $openingSources ?? []],
            ['balance' => $closing, 'source_markers' => $closingSources ?? []],
        );
    }

    /**
     * @template T of array<string, mixed>
     *
     * @param  callable(Depot): T  $calculate
     * @return T
     */
    private function remember(Depot $depot, string $period, Carbon $end, string $valueKey, callable $calculate): array
    {
        if ($end->gte(now()->startOfDay())) {
            return $calculate($depot);
        }

        $key = ['depot_id' => $depot->id, 'period' => $period, 'end_date' => $end->toDateString()];
        $stored = $this->storedPayload($key);

        if ($stored !== null) {
            return $stored;
        }

        return DB::transaction(function () use ($depot, $key, $valueKey, $calculate): array {
            $lockedDepot = Depot::query()->lockForUpdate()->findOrFail($depot->id);
            $stored = $this->storedPayload($key);

            if ($stored !== null) {
                return $stored;
            }

            $payload = $calculate($lockedDepot);

            DB::table(self::Table)->upsert([
                ...$key,
                'payload' => json_encode([...$payload, '_source_policy' => $this->sourcePolicy()], JSON_THROW_ON_ERROR),
                'expires_at' => $payload[$valueKey] === null ? now()->addHour() : null,
            ], ['depot_id', 'period', 'end_date'], ['payload', 'expires_at']);

            return $payload;
        }, attempts: 3);
    }

    /**
     * @param  array{depot_id: int, period: string, end_date: string}  $key
     * @return array<string, mixed>|null
     */
    private function storedPayload(array $key): ?array
    {
        $payload = DB::table(self::Table)->where($key)
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->value('payload');

        if ($payload === null) {
            return null;
        }
        $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        if (($decoded['_source_policy'] ?? null) !== $this->sourcePolicy()) {
            return null;
        }
        unset($decoded['_source_policy']);

        return $decoded;
    }

    public function sourcePolicy(): string
    {
        return config('services.market_data.free_only', true) ? 'free-v1' : 'all-v1';
    }
}
