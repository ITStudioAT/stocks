<?php

namespace App\Services;

use App\Models\Depot;
use App\Models\StockHolding;
use App\Models\StockHoldingDailyPrice;
use App\Models\StockHoldingIntradayCandle;
use App\Models\StockRealtimePrice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AnalysisTrendSummary
{
    public function __construct(private DepotTransactionBooker $depotTransactionBooker) {}

    /** @return array<int, array{id: int, name: ?string, symbol: ?string, position_pieces: string, direction: ?string, count: ?int, percentage_sum: ?float, trading_date: ?string, intraday: ?array{price: string, currency: string, as_of: string, source_markers: array<int, string>}}> */
    public function all(): array
    {
        $holdings = StockHolding::query()->get(['id', 'name', 'symbol'])->keyBy('id');
        $activeDepot = Depot::query()->where('is_active', true)->first();
        $positions = $activeDepot ? $this->depotTransactionBooker->positionPiecesByHolding($activeDepot) : collect();
        $intradayPrices = $this->latestIntradayPrices();
        $summaries = $holdings->map(fn (StockHolding $holding): array => [
            'id' => $holding->id,
            'name' => $holding->name,
            'symbol' => $holding->symbol,
            'position_pieces' => $positions->get($holding->id, '0.00000000'),
            'direction' => null,
            'count' => null,
            'percentage_sum' => null,
            'trading_date' => null,
            'intraday' => $intradayPrices->get($holding->id),
        ])->all();
        $holdingId = null;
        $previous = null;
        $latestDate = null;
        $direction = 0;
        $count = 0;
        $percentageSum = 0.0;
        $pendingFlatCount = 0;
        $finished = false;

        $prices = StockHoldingDailyPrice::query()
            ->select(['id', 'stock_holding_id', 'trading_date', 'close', 'adjusted_close', 'currency'])
            ->where(function (Builder $query): void {
                $query->whereNotNull('adjusted_close')->orWhereNotNull('close');
            })
            ->orderBy('stock_holding_id')
            ->orderByDesc('trading_date')
            ->orderByDesc('id')
            ->lazy();

        foreach ($prices as $price) {
            if ((int) $price->stock_holding_id !== $holdingId) {
                $this->applySummary($summaries, $holdingId, $latestDate, $direction, $count, $percentageSum);

                $holdingId = (int) $price->stock_holding_id;
                $previous = null;
                $latestDate = $price->trading_date->toDateString();
                $direction = 0;
                $count = 0;
                $percentageSum = 0.0;
                $pendingFlatCount = 0;
                $finished = false;
            }

            if ($finished) {
                continue;
            }

            $current = [
                'trading_date' => $price->trading_date->toDateString(),
                'price' => (string) ($price->adjusted_close ?? $price->close),
                'currency' => $price->currency,
            ];

            if ($previous === null) {
                $previous = $current;

                continue;
            }

            if ($previous['trading_date'] === $current['trading_date']) {
                continue;
            }

            $olderDirection = $this->direction($previous, $current);

            if ($olderDirection === null || ($direction !== 0 && $olderDirection !== 0 && $olderDirection !== $direction)) {
                $finished = true;

                continue;
            }

            $olderPrice = (float) $current['price'];
            if ($olderPrice === 0.0) {
                $percentageSum = null;
            } elseif ($percentageSum !== null) {
                $percentageSum += round(((float) $previous['price'] - $olderPrice) / $olderPrice * 100, 2);
            }
            $previous = $current;

            if ($olderDirection === 0) {
                $pendingFlatCount++;

                continue;
            }

            $direction = $olderDirection;
            $count += $pendingFlatCount + 1;
            $pendingFlatCount = 0;
        }

        $this->applySummary($summaries, $holdingId, $latestDate, $direction, $count, $percentageSum);
        $summaries = array_values($summaries);

        usort($summaries, fn (array $first, array $second): int => (($second['count'] ?? -1) <=> ($first['count'] ?? -1))
            ?: strcasecmp($first['name'] ?: $first['symbol'] ?: '', $second['name'] ?: $second['symbol'] ?: '')
            ?: ($first['id'] <=> $second['id']));

        return $summaries;
    }

    private function latestIntradayPrices(): Collection
    {
        if (MarketPriceStore::enabled()) {
            return $this->latestCentralIntradayPrices();
        }

        $latestIds = StockHolding::query()->select('id')->selectSub(StockHoldingIntradayCandle::query()
            ->select('id')
            ->whereColumn('stock_holding_id', 'stock_holdings.id')
            ->whereColumn('currency', 'stock_holdings.currency')
            ->where('close', '>', 0)
            ->whereNotNull('as_of')
            ->where('as_of', '<=', now()->utc())
            ->orderByDesc('as_of')->orderByDesc('id')->limit(1), 'intraday_id')
            ->get()->pluck('intraday_id', 'id')->filter();

        $candles = StockHoldingIntradayCandle::query()->whereIn('id', $latestIds->unique())
            ->get(['id', 'stock_holding_id', 'close', 'currency', 'as_of', 'source_key', 'source_name', 'source_url'])
            ->filter(fn (StockHoldingIntradayCandle $price): bool => (int) $latestIds->get($price->stock_holding_id) === $price->id)
            ->mapWithKeys(fn ($price): array => [$price->stock_holding_id => [
                'price' => (string) $price->close,
                'currency' => $price->currency,
                'as_of' => Carbon::parse($price->getRawOriginal('as_of'), 'UTC')->toIso8601String(),
                'source_markers' => MarketDataProvenance::forRecord($price),
            ]]);

        $quoteQuery = fn () => StockRealtimePrice::query()
            ->where('price', '>', 0)->whereNotNull('as_of')->where('as_of', '<=', now()->utc())
            ->where(fn (Builder $query) => $query->whereNull('validation_status')->orWhereNotIn('validation_status', ['invalid', 'rejected', 'quarantined']))
            ->where(fn (Builder $query) => $query->whereNull('source_key')->orWhere('source_key', '!=', 'yahoo_daily'))
            ->where(fn (Builder $query) => $query->whereNull('price_type')->orWhereNotIn('price_type', ['historical_eod', 'daily_close', 'ohlc', 'indicative', 'nav']));
        $quoteIds = StockHolding::query()->select('id')->selectSub($quoteQuery()->select('id')
            ->whereColumn('stock_holding_id', 'stock_holdings.id')->whereColumn('currency', 'stock_holdings.currency')
            ->orderByDesc('as_of')->orderByDesc('id')->limit(1), 'quote_id')->get()->pluck('quote_id', 'id')->filter();
        $quotes = $quoteQuery()->whereIn('id', $quoteIds->unique())
            ->get(['id', 'stock_holding_id', 'price', 'currency', 'as_of', 'source_key', 'source_name', 'source_url'])
            ->filter(fn (StockRealtimePrice $price): bool => (int) $quoteIds->get($price->stock_holding_id) === $price->id);

        foreach ($quotes as $quote) {
            $asOf = Carbon::parse($quote->getRawOriginal('as_of'), 'UTC')->toIso8601String();
            if ($candles->has($quote->stock_holding_id) && $candles[$quote->stock_holding_id]['as_of'] >= $asOf) {
                continue;
            }
            $candles->put($quote->stock_holding_id, [
                'price' => (string) $quote->price,
                'currency' => $quote->currency,
                'as_of' => $asOf,
                'source_markers' => MarketDataProvenance::forRecord($quote),
            ]);
        }

        return $candles;
    }

    private function latestCentralIntradayPrices(): Collection
    {
        $latestIds = StockHolding::query()->select(['id', 'market_listing_id'])->selectSub(
            DB::table('market_prices')->select('id')
                ->whereColumn('market_listing_id', 'stock_holdings.market_listing_id')
                ->whereColumn('currency', 'stock_holdings.currency')
                ->where('is_current', true)->where('validation_status', 'valid')
                ->where('interval_code', '!=', '1d')->whereNotIn('price_type', ['indicative', 'nav'])
                ->whereNotNull('observed_at')->where('effective_at', '<=', now()->utc())
                ->whereRaw('COALESCE(price, close) > 0')
                ->orderByDesc('effective_at')->orderByDesc('id')->limit(1), 'intraday_id',
        )->get();
        $prices = DB::table('market_prices')->whereIn('id', $latestIds->pluck('intraday_id')->filter()->unique())
            ->get(['id', 'price', 'close', 'currency', 'observed_at', 'source_key', 'provenance'])->keyBy('id');

        return $latestIds->filter(fn (StockHolding $holding): bool => $prices->has($holding->intraday_id))
            ->mapWithKeys(function (StockHolding $holding) use ($prices): array {
                $price = $prices->get($holding->intraday_id);
                $provenance = json_decode($price->provenance ?? '{}', true) ?: [];

                return [$holding->id => [
                    'price' => (string) ($price->price ?? $price->close),
                    'currency' => $price->currency,
                    'as_of' => Carbon::parse($price->observed_at, 'UTC')->toIso8601String(),
                    'source_markers' => MarketDataProvenance::forRecord(['source_key' => $price->source_key, ...$provenance]),
                ]];
            });
    }

    /** @param array<int, array{id: int, name: ?string, symbol: ?string, position_pieces: string, direction: ?string, count: ?int, percentage_sum: ?float, trading_date: ?string, intraday: ?array{price: string, currency: string, as_of: string, source_markers: array<int, string>}}> $summaries */
    private function applySummary(array &$summaries, ?int $holdingId, ?string $latestDate, int $direction, int $count, ?float $percentageSum): void
    {
        if ($holdingId === null || ! isset($summaries[$holdingId])) {
            return;
        }

        $summaries[$holdingId]['trading_date'] = $latestDate;
        $summaries[$holdingId]['direction'] = $direction === 0 ? null : ($direction > 0 ? 'up' : 'down');
        $summaries[$holdingId]['count'] = $direction === 0 ? null : $count;
        $summaries[$holdingId]['percentage_sum'] = $direction === 0 || $percentageSum === null ? null : round($percentageSum, 2);
    }

    /**
     * @param  array{price: string, currency: ?string}  $newer
     * @param  array{price: string, currency: ?string}  $older
     */
    public function direction(array $newer, array $older): ?int
    {
        if ($newer['currency'] === null || $newer['currency'] === '' || $newer['currency'] !== $older['currency']) {
            return null;
        }

        return (float) $newer['price'] <=> (float) $older['price'];
    }
}
