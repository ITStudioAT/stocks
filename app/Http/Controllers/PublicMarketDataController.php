<?php

namespace App\Http\Controllers;

use App\Models\IndexWatchItem;
use App\Services\MarketDataProvenance;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class PublicMarketDataController extends Controller
{
    public function indices(): JsonResponse
    {
        $indexes = Cache::remember('public-market.indices.v3', now()->addMinutes(5), fn (): array => IndexWatchItem::query()
            ->orderBy('symbol')
            ->get([
                'symbol',
                'name',
                'country',
                'currency',
                'latest_price',
                'latest_price_source',
                'latest_price_as_of',
                'latest_price_change_pct',
            ])
            ->map(fn (IndexWatchItem $item): array => [
                'symbol' => $item->symbol,
                'latest_price_as_of' => $item->getRawOriginal('latest_price_as_of') === null
                    ? null : Carbon::parse($item->getRawOriginal('latest_price_as_of'), 'UTC')->toIso8601String(),
                'quote_status' => config('services.market_data.free_only', true) && MarketDataProvenance::markers($item->latest_price_source) !== ['free'] ? 'stored' : 'available',
                'latest_price_source' => $item->latest_price_source,
                'name' => $item->name,
                'country' => $item->country,
                'currency' => $item->currency,
                'latest_price' => $item->latest_price !== null
                    ? number_format((float) $item->latest_price, 4, '.', '')
                    : null,
                'latest_price_change_pct' => $item->latest_price_change_pct !== null
                    ? number_format((float) $item->latest_price_change_pct, 2, '.', '')
                    : null,
            ])
            ->all());

        return $this->publicJson(['indexes' => $indexes]);
    }

    public function marketSign(): JsonResponse
    {
        $sign = Cache::remember('public-market.sign.v1', now()->addMinutes(5), function (): int {
            $sum = (float) IndexWatchItem::query()
                ->whereNotNull('latest_price_change_pct')
                ->sum('latest_price_change_pct');

            return $sum <=> 0.0;
        });

        return $this->publicJson(['sign' => $sign]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function publicJson(array $payload): JsonResponse
    {
        return response()
            ->json($payload)
            ->header('Cache-Control', 'public, max-age=60, stale-while-revalidate=300');
    }
}
