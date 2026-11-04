<?php

namespace App\Http\Controllers;

use App\Services\EodhdApiClient;
use App\Services\EodhdApiUsage;
use App\Services\FreeInstrumentSearch;
use App\Services\StockSearchQueryResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminStockSearchController extends Controller
{
    public function index(Request $request, StockSearchQueryResolver $queryResolver, FreeInstrumentSearch $freeSearch, EodhdApiUsage $eodhdApiUsage, EodhdApiClient $apiClient): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:120'],
            'source' => ['sometimes', 'required', 'in:free,eodhd'],
        ]);

        if (($validated['source'] ?? 'free') === 'free') {
            $freeResult = $freeSearch->search($validated['query']);
            if ($freeResult['results'] !== [] || ! $apiClient->configured()) {
                return response()->json($freeResult);
            }
        }
        if (! $apiClient->configured()) {
            return response()->json(['message' => 'EODHD search is disabled or its access is not configured.'], 422);
        }

        return response()->json([
            'source' => 'EODHD',
            'message' => 'EODHD search uses the configured account when selected or when free search has no suitable result.',
            'results' => collect($queryResolver->resolveCandidates($validated['query']))
                ->map(fn (array $candidate): array => $this->resultPayload($candidate, $validated['query']))
                ->filter(fn (array $result): bool => filled($result['symbol']))
                ->unique(fn (array $result): string => implode('|', [
                    $result['symbol'],
                    $result['exchange'] ?? '',
                    $result['mic_code'] ?? '',
                    $result['isin'] ?? '',
                    $result['valor'] ?? '',
                    $result['currency'] ?? '',
                ]))
                ->take(10)
                ->values()
                ->all(),
            'eodhd_api_usage' => $eodhdApiUsage->payload(),
        ]);
    }

    /**
     * @param  array{name?: ?string, isin?: ?string, wkn?: ?string, valor?: ?string, symbol?: ?string, exchange?: ?string, mic_code?: ?string, instrument_type?: ?string, country?: ?string, currency?: ?string}  $candidate
     * @return array{symbol: ?string, name: ?string, isin: ?string, wkn: ?string, valor: ?string, exchange: ?string, mic_code: ?string, instrument_type: ?string, country: ?string, currency: ?string}
     */
    private function resultPayload(array $candidate, string $query): array
    {
        return [
            'source_markers' => ['EODHD'],
            'symbol' => $this->fallbackSymbol($candidate, $query),
            'name' => $candidate['name'] ?? null,
            'isin' => $candidate['isin'] ?? null,
            'wkn' => $candidate['wkn'] ?? null,
            'valor' => $candidate['valor'] ?? null,
            'exchange' => $candidate['exchange'] ?? null,
            'mic_code' => $candidate['mic_code'] ?? null,
            'instrument_type' => $candidate['instrument_type'] ?? null,
            'country' => $candidate['country'] ?? null,
            'currency' => $candidate['currency'] ?? null,
        ];
    }

    /**
     * @param  array{symbol?: ?string, isin?: ?string, wkn?: ?string, valor?: ?string}  $candidate
     */
    private function fallbackSymbol(array $candidate, string $query): ?string
    {
        foreach (['symbol', 'isin', 'wkn', 'valor'] as $key) {
            if (filled($candidate[$key] ?? null)) {
                return $candidate[$key];
            }
        }

        return filled($query) ? trim($query) : null;
    }
}
