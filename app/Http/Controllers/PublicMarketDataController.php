<?php

namespace App\Http\Controllers;

use App\Models\IndexWatchItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class PublicMarketDataController extends Controller
{
    public function indices(): JsonResponse
    {
        $indexes = Cache::remember('public-market.indices.v1', now()->addMinutes(5), fn (): array => IndexWatchItem::query()
            ->orderBy('symbol')
            ->get([
                'symbol',
                'name',
                'country',
                'currency',
                'latest_price',
                'latest_price_change_pct',
            ])
            ->map(fn (IndexWatchItem $item): array => [
                'symbol' => $item->symbol,
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
