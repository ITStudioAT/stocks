<?php

namespace App\Http\Controllers;

use App\Services\StockSearchQueryResolver;
use App\Services\TwelveDataClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminStockSearchController extends Controller
{
    public function index(Request $request, TwelveDataClient $twelveData, StockSearchQueryResolver $queryResolver): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:120'],
        ]);

        $results = $twelveData->symbolSearch($validated['query'], 10);

        if ($results === []) {
            $results = $this->fallbackSearch($twelveData, $queryResolver, $validated['query']);
        }

        return response()->json([
            'results' => collect($results)
                ->take(10)
                ->map(fn (array $result): array => [
                    'symbol' => $result['symbol'] ?? null,
                    'name' => $result['instrument_name'] ?? null,
                    'isin' => $result['isin'] ?? null,
                    'wkn' => $result['wkn'] ?? null,
                    'exchange' => $result['exchange'] ?? null,
                    'mic_code' => $result['mic_code'] ?? null,
                    'instrument_type' => $result['instrument_type'] ?? null,
                    'country' => $result['country'] ?? null,
                    'currency' => $result['currency'] ?? null,
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fallbackSearch(TwelveDataClient $twelveData, StockSearchQueryResolver $queryResolver, string $query): array
    {
        $results = collect();

        foreach ($queryResolver->resolveCandidates($query) as $candidate) {
            foreach ($candidate['search_terms'] as $resolvedQuery) {
                if (Str::upper($resolvedQuery) === Str::upper($query)) {
                    continue;
                }

                $results = $results
                    ->merge(collect($twelveData->symbolSearch($resolvedQuery, 10))
                        ->map(fn (array $result): array => $this->enrichResult($result, $candidate)))
                    ->unique(fn (array $result): string => implode('|', [
                        $result['symbol'] ?? '',
                        $result['exchange'] ?? '',
                        $result['mic_code'] ?? '',
                    ]))
                    ->values();

                if ($results->count() >= 10) {
                    break 2;
                }
            }

            if ($results->count() >= 10) {
                break;
            }
        }

        return $results->take(10)->all();
    }

    /**
     * @param  array{name: ?string, isin: ?string, wkn: ?string, symbol: ?string, exchange: ?string, search_terms: array<int, string>}  $candidate
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function enrichResult(array $result, array $candidate): array
    {
        return [
            ...$result,
            'isin' => $result['isin'] ?? $candidate['isin'],
            'wkn' => $result['wkn'] ?? $candidate['wkn'],
        ];
    }
}
