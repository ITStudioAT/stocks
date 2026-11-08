<?php

namespace App\Http\Controllers;

use App\Models\StockHolding;
use App\Services\AnalysisTrendSummary;
use App\Services\MarketDataProvenance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class AdminAnalysisHistoricalPricesController extends Controller
{
    public function __invoke(StockHolding $holding, AnalysisTrendSummary $trends): JsonResponse
    {
        $prices = [];
        $trendAnchor = null;
        $trendDirection = null;
        $trendPriorCount = 0;
        $pendingFlatCount = 0;
        $scanPrice = null;
        $lastTradingDate = null;

        $storedPrices = $holding->dailyPrices()
            ->select(['id', 'stock_holding_id', 'trading_date', 'close', 'adjusted_close', 'currency', 'source_key', 'source_name'])
            ->where(function (Builder $query): void {
                $query->whereNotNull('adjusted_close')->orWhereNotNull('close');
            })
            ->orderByDesc('trading_date')
            ->orderByDesc('id')
            ->lazy();

        foreach ($storedPrices as $storedPrice) {
            $tradingDate = $storedPrice->trading_date->toDateString();

            if ($tradingDate === $lastTradingDate) {
                continue;
            }

            $lastTradingDate = $tradingDate;
            $price = [
                'trading_date' => $tradingDate,
                'price' => (string) ($storedPrice->adjusted_close ?? $storedPrice->close),
                'currency' => $storedPrice->currency,
                'source_markers' => MarketDataProvenance::forRecord($storedPrice),
            ];

            if (count($prices) < 101) {
                $prices[] = $price;

                if (count($prices) === 101) {
                    $trendDirection = $trends->direction($prices[99], $prices[100]);

                    if ($trendDirection === null) {
                        break;
                    }

                    $trendAnchor = $prices[100];
                    $scanPrice = $prices[100];
                }

                continue;
            }

            $olderDirection = $trends->direction($scanPrice, $price);

            if ($olderDirection === null || ($trendDirection !== 0 && $olderDirection !== 0 && $olderDirection !== $trendDirection)) {
                break;
            }

            $scanPrice = $price;

            if ($olderDirection === 0) {
                $pendingFlatCount++;

                continue;
            }

            if ($trendDirection === 0) {
                $trendDirection = $olderDirection;
            }

            $trendPriorCount += $pendingFlatCount + 1;
            $pendingFlatCount = 0;
            $trendAnchor = $price;
        }

        return response()->json([
            'prices' => $prices,
            'trend_anchor' => count($prices) === 101 && $trendAnchor !== $prices[100] ? $trendAnchor : null,
            'trend_prior_count' => $trendPriorCount,
        ]);
    }
}
