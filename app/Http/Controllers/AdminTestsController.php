<?php

namespace App\Http\Controllers;

use App\Models\IndexWatchItem;
use App\Models\StockHolding;
use App\Services\EodhdApiClient;
use App\Services\EodhdApiUsage;
use App\Services\EodhdExchangeDataImporter;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class AdminTestsController extends Controller
{
    private const TickerExchangeCode = 'XETRA';

    public function options(): JsonResponse
    {
        return response()->json([
            'indices' => IndexWatchItem::query()
                ->get(['id', 'name', 'symbol'])
                ->map(fn (IndexWatchItem $item): array => [
                    'id' => $item->id,
                    'symbol' => $item->symbol,
                    'name' => $item->name ?: $item->symbol,
                ])
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all(),
            'stocks' => StockHolding::query()
                ->get(['id', 'name', 'symbol'])
                ->map(fn (StockHolding $holding): array => [
                    'id' => $holding->id,
                    'symbol' => $holding->symbol,
                    'name' => $holding->name ?: $holding->symbol,
                ])
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all(),
        ]);
    }

    public function tickers(EodhdApiClient $eodhdApiClient, EodhdApiUsage $eodhdApiUsage): JsonResponse
    {
        try {
            $response = $eodhdApiClient->get('exchange-symbol-list/'.self::TickerExchangeCode, [
                'fmt' => 'json',
            ]);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'exchange_code' => self::TickerExchangeCode,
                'tickers' => [],
                'eodhd_api_usage' => $eodhdApiUsage->payload(),
            ], 422);
        }

        if ($response->failed()) {
            return response()->json([
                'message' => "EODHD request failed with HTTP {$response->status()}.",
                'exchange_code' => self::TickerExchangeCode,
                'tickers' => [],
                'eodhd_api_usage' => $eodhdApiUsage->payload(),
            ], 422);
        }

        $tickers = $response->json();

        if (! is_array($tickers)) {
            return response()->json([
                'message' => 'EODHD returned an invalid ticker response.',
                'exchange_code' => self::TickerExchangeCode,
                'tickers' => [],
                'eodhd_api_usage' => $eodhdApiUsage->payload(),
            ], 422);
        }

        return response()->json([
            'exchange_code' => self::TickerExchangeCode,
            'tickers' => $tickers,
            'eodhd_api_usage' => $eodhdApiUsage->payload(),
        ]);
    }

    public function exchanges(EodhdApiClient $eodhdApiClient, EodhdApiUsage $eodhdApiUsage, EodhdExchangeDataImporter $importer): JsonResponse
    {
        try {
            $response = $eodhdApiClient->get('exchanges-list/', [
                'fmt' => 'json',
            ]);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'exchanges' => [],
                'eodhd_api_usage' => $eodhdApiUsage->payload(),
            ], 422);
        }

        if ($response->failed()) {
            return response()->json([
                'message' => "EODHD request failed with HTTP {$response->status()}.",
                'exchanges' => [],
                'eodhd_api_usage' => $eodhdApiUsage->payload(),
            ], 422);
        }

        $exchanges = $response->json();

        if (! is_array($exchanges)) {
            return response()->json([
                'message' => 'EODHD returned an invalid exchange response.',
                'exchanges' => [],
                'eodhd_api_usage' => $eodhdApiUsage->payload(),
            ], 422);
        }

        $exchangeDetails = [];
        $exchangeDetailErrors = [];
        $exchanges = collect($exchanges)
            ->map(function (mixed $exchange) use (&$exchangeDetails, &$exchangeDetailErrors, $eodhdApiClient, $importer): mixed {
                if (! is_array($exchange)) {
                    return $exchange;
                }

                $exchangeDetailCode = $importer->exchangeDetailCode($exchange);

                if ($exchangeDetailCode === null) {
                    return $exchange;
                }

                $exchange['exchange_detail_code'] = $exchangeDetailCode;

                if (array_key_exists($exchangeDetailCode, $exchangeDetails) || array_key_exists($exchangeDetailCode, $exchangeDetailErrors)) {
                    return $exchange;
                }

                $detailResponse = $eodhdApiClient->get('v2/exchange-details/'.rawurlencode($exchangeDetailCode));

                if ($detailResponse->failed()) {
                    $exchangeDetailErrors[$exchangeDetailCode] = "EODHD detail request failed with HTTP {$detailResponse->status()}.";

                    return $exchange;
                }

                $details = $detailResponse->json();

                if (! is_array($details)) {
                    $exchangeDetailErrors[$exchangeDetailCode] = 'EODHD returned an invalid exchange detail response.';

                    return $exchange;
                }

                if (isset($details['data']) && is_array($details['data'])) {
                    $details = $details['data'];
                }

                $exchangeDetails[$exchangeDetailCode] = $details;

                return $exchange;
            })
            ->values()
            ->all();

        return response()->json([
            'exchanges' => $exchanges,
            'exchange_details' => $exchangeDetails,
            'exchange_detail_errors' => $exchangeDetailErrors,
            'eodhd_api_usage' => $eodhdApiUsage->payload(),
        ]);
    }
}
