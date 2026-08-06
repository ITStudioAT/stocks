<?php

namespace App\Http\Controllers;

use App\Models\IndexWatchItem;
use App\Models\IndexWatchItemPrice;
use App\Models\IndexWatchItemRealtimePrice;
use App\Services\EodhdApiUsage;
use App\Services\IndexWatchItemPriceRefresher;
use App\Services\KnownInstrumentMetadataCorrections;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminIndexWatchItemController extends Controller
{
    private const PriceRangeLimits = [
        'intraday' => 1,
        '1w' => 7,
        '1m' => 30,
        '6m' => 132,
        '1y' => 264,
    ];

    public function __construct(
        private IndexWatchItemPriceRefresher $priceRefresher,
        private EodhdApiUsage $eodhdApiUsage,
        private KnownInstrumentMetadataCorrections $metadataCorrections,
    ) {}

    public function index(): JsonResponse
    {
        $items = IndexWatchItem::query()
            ->orderBy('symbol')
            ->orderBy('id')
            ->get()
            ->map(fn (IndexWatchItem $item): array => $this->indexWatchItemPayload($item));

        return response()->json([
            'indexes' => $items,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatedIndexWatchItemData($request);

        $item = IndexWatchItem::query()->create([
            ...$validated,
            'raw_payload' => Arr::only($request->all(), [
                'symbol',
                'name',
                'isin',
                'wkn',
                'valor',
                'exchange',
                'mic_code',
                'instrument_type',
                'country',
                'currency',
            ]),
        ]);

        $this->priceRefresher->refresh($item);
        $item->refresh();

        return response()->json([
            'message' => 'Index added.',
            'index' => $this->indexWatchItemPayload($item),
        ], 201);
    }

    public function ensurePrices(Request $request, IndexWatchItem $indexWatchItem): JsonResponse
    {
        $validated = $request->validate([
            'range' => ['nullable', Rule::in(array_keys(self::PriceRangeLimits))],
        ]);
        $range = $validated['range'] ?? null;
        $priceLimit = self::PriceRangeLimits[$range] ?? IndexWatchItemPriceRefresher::RecentPriceLimit;
        $hasPrices = $range === 'intraday'
            ? $this->refreshIntradayPrice($indexWatchItem)
            : $this->priceRefresher->ensureRecentPrices($indexWatchItem, $priceLimit);
        $indexWatchItem->refresh();
        $payload = $this->indexWatchItemPayload($indexWatchItem, $priceLimit);

        if (! $hasPrices && count($payload['recent_prices']) === 0) {
            return response()->json([
                'message' => 'Index prices could not be retrieved from EODHD.',
                'index' => $payload,
                'range' => $range,
                'eodhd_api_usage' => $this->eodhdApiUsage->payload(),
            ], 422);
        }

        return response()->json([
            'message' => 'Index prices loaded.',
            'index' => $payload,
            'range' => $range,
            'eodhd_api_usage' => $this->eodhdApiUsage->payload(),
        ]);
    }

    public function destroy(IndexWatchItem $indexWatchItem): JsonResponse
    {
        $indexWatchItem->delete();

        return response()->json([
            'message' => 'Index removed.',
        ]);
    }

    private function refreshIntradayPrice(IndexWatchItem $indexWatchItem): bool
    {
        if ($this->priceRefresher->refresh($indexWatchItem)) {
            return true;
        }

        return $indexWatchItem->prices()->exists();
    }

    /**
     * @return array{symbol: string, name?: string, isin?: string, wkn?: string, exchange?: string, mic_code?: string, instrument_type?: string, country?: string, currency?: string}
     */
    private function validatedIndexWatchItemData(Request $request): array
    {
        $request->merge([
            'symbol' => $request->filled('symbol') ? Str::upper(trim((string) $request->input('symbol'))) : null,
            'name' => $request->filled('name') ? trim((string) $request->input('name')) : null,
            'isin' => $request->filled('isin') ? Str::upper(trim((string) $request->input('isin'))) : null,
            'wkn' => $request->filled('wkn') ? Str::upper(trim((string) $request->input('wkn'))) : null,
            'exchange' => $request->filled('exchange') ? trim((string) $request->input('exchange')) : null,
            'mic_code' => $request->filled('mic_code') ? Str::upper(trim((string) $request->input('mic_code'))) : null,
            'instrument_type' => $request->filled('instrument_type') ? Str::upper(trim((string) $request->input('instrument_type'))) : null,
            'country' => $request->filled('country') ? trim((string) $request->input('country')) : null,
            'currency' => $request->filled('currency') ? Str::upper(trim((string) $request->input('currency'))) : null,
        ]);

        $request->merge($this->metadataCorrections->apply($request->all()));

        return $request->validate([
            'symbol' => ['required', 'string', 'max:32'],
            'name' => ['nullable', 'string', 'max:255'],
            'isin' => [
                'nullable',
                'string',
                'size:12',
                Rule::unique(IndexWatchItem::class, 'isin'),
            ],
            'wkn' => [
                'nullable',
                'string',
                'size:6',
                Rule::unique(IndexWatchItem::class, 'wkn'),
            ],
            'exchange' => ['nullable', 'string', 'max:255'],
            'mic_code' => ['nullable', 'string', 'max:32'],
            'instrument_type' => ['required', 'string', Rule::in(['INDEX'])],
            'country' => ['nullable', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'max:8'],
        ]);
    }

    /**
     * @return array{id: int, symbol: string, name: ?string, isin: ?string, wkn: ?string, exchange: ?string, mic_code: ?string, instrument_type: ?string, country: ?string, currency: ?string, eodhd_code: string, start_price: ?string, latest_price: ?string, last_price: ?string, latest_price_change_pct: ?string, latest_price_as_of: ?string, latest_price_source: ?string, trading_times: ?string, recent_prices: array<int, array{trading_date: ?string, start_price: ?string, actual_price: ?string, last_price: ?string, actual_price_as_of: ?string, last_price_as_of: ?string}>, realtime_prices: array<int, array{trading_date: ?string, price: ?string, as_of: ?string}>, created_at: ?string}
     */
    private function indexWatchItemPayload(
        IndexWatchItem $item,
        int $priceLimit = IndexWatchItemPriceRefresher::RecentPriceLimit,
    ): array {
        $recentPrices = $item->prices()
            ->orderByDesc('trading_date')
            ->limit($priceLimit)
            ->get();
        $latestRealtimeTradingDate = $item->realtimePrices()
            ->orderByDesc('trading_date')
            ->value('trading_date');
        $realtimePrices = $latestRealtimeTradingDate
            ? $item->realtimePrices()
                ->whereDate('trading_date', $latestRealtimeTradingDate)
                ->orderByDesc('as_of')
                ->orderByDesc('id')
                ->get(['id', 'trading_date', 'price', 'as_of'])
            : collect();

        return [
            'id' => $item->id,
            'symbol' => $item->symbol,
            'name' => $item->name,
            'isin' => $item->isin,
            'wkn' => $item->wkn,
            'exchange' => $item->exchange,
            'mic_code' => $item->mic_code,
            'instrument_type' => $item->instrument_type,
            'country' => $item->country,
            'currency' => $item->currency,
            'eodhd_code' => $this->eodhdCode($item),
            'start_price' => $this->pricePayload($item->start_price),
            'latest_price' => $this->pricePayload($item->latest_price),
            'last_price' => $this->pricePayload($item->last_price),
            'latest_price_change_pct' => $this->percentPayload($this->latestPriceChangePercent($item, $recentPrices)),
            'latest_price_as_of' => $this->storedUtcTimestamp($item, 'latest_price_as_of'),
            'latest_price_source' => $item->latest_price_source,
            'trading_times' => $item->trading_times,
            'recent_prices' => $recentPrices
                ->map(fn (IndexWatchItemPrice $price): array => [
                    'trading_date' => $price->trading_date?->toDateString(),
                    'start_price' => $this->pricePayload($price->start_price),
                    'actual_price' => $this->pricePayload($price->actual_price),
                    'last_price' => $this->pricePayload($price->actual_price ?? $price->last_price),
                    'actual_price_as_of' => $this->storedUtcTimestamp($price, 'actual_price_as_of'),
                    'last_price_as_of' => $this->storedUtcTimestamp(
                        $price,
                        $price->getRawOriginal('actual_price_as_of') !== null
                            ? 'actual_price_as_of'
                            : 'last_price_as_of',
                    ),
                ])
                ->all(),
            'realtime_prices' => $realtimePrices
                ->map(fn (IndexWatchItemRealtimePrice $price): array => [
                    'trading_date' => $price->trading_date?->toDateString(),
                    'price' => $this->pricePayload($price->price),
                    'as_of' => $this->storedUtcTimestamp($price, 'as_of'),
                ])
                ->all(),
            'created_at' => $item->created_at?->toIso8601String(),
        ];
    }

    private function storedUtcTimestamp(Model $model, string $column): ?string
    {
        $value = $model->getRawOriginal($column);

        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return Carbon::parse((string) $value, 'UTC')
            ->setTimezone(config('app.timezone', 'UTC'))
            ->toIso8601String();
    }

    private function eodhdCode(IndexWatchItem $item): string
    {
        $symbol = Str::upper((string) $item->symbol);
        $exchange = Str::upper((string) $item->exchange);

        return $symbol.'.'.($exchange !== '' ? $exchange : 'INDX');
    }

    /**
     * @param  Collection<int, IndexWatchItemPrice>  $recentPrices
     */
    private function latestPriceChangePercent(IndexWatchItem $item, Collection $recentPrices): ?string
    {
        $storedChangePercent = $this->pricePayload($item->latest_price_change_pct);

        if ($storedChangePercent !== null) {
            return $storedChangePercent;
        }

        $latestPrice = $this->pricePayload($item->latest_price) ?? $this->indexPriceDisplayValue($recentPrices->first());
        $referencePrice = $recentPrices
            ->skip(1)
            ->map(fn (IndexWatchItemPrice $price): ?string => $this->indexPriceDisplayValue($price))
            ->first(fn (?string $price): bool => $price !== null);

        if ($latestPrice === null || $referencePrice === null) {
            return $this->pricePayload($item->latest_price_change_pct);
        }

        if ((float) $referencePrice === 0.0) {
            return null;
        }

        return number_format((((float) $latestPrice - (float) $referencePrice) / (float) $referencePrice) * 100, 6, '.', '');
    }

    private function indexPriceDisplayValue(?IndexWatchItemPrice $price): ?string
    {
        if (! $price) {
            return null;
        }

        return $this->pricePayload($price->actual_price ?? $price->last_price);
    }

    private function currentOpenTradingDate(IndexWatchItem $item): ?string
    {
        if ($item->trading_times === null) {
            return null;
        }

        $window = $this->tradingWindow((string) $item->trading_times);

        if ($window === null) {
            return null;
        }

        $referenceTime = $item->latest_price_as_of instanceof Carbon
            ? $item->latest_price_as_of
            : Carbon::now();

        $localTime = $referenceTime->copy()->setTimezone($window['timezone']);

        if ($localTime->isWeekend()) {
            return null;
        }

        $currentMinute = ($localTime->hour * 60) + $localTime->minute;

        if ($currentMinute < $window['open_minute'] || $currentMinute >= $window['close_minute']) {
            return null;
        }

        return $localTime->toDateString();
    }

    /**
     * @return array{timezone: string, open_minute: int, close_minute: int}|null
     */
    private function tradingWindow(string $tradingTimes): ?array
    {
        if (! preg_match('/(?<open_hour>\d{1,2}):(?<open_minute>\d{2})(?::\d{2})?\s*(?:-|to|until|bis)\s*(?<close_hour>\d{1,2}):(?<close_minute>\d{2})(?::\d{2})?/i', $tradingTimes, $matches)) {
            return null;
        }

        $timezone = preg_match('/\b[A-Za-z_]+\/[A-Za-z_]+\b/', $tradingTimes, $timezoneMatches)
            ? $timezoneMatches[0]
            : config('app.timezone', 'UTC');

        return [
            'timezone' => $timezone,
            'open_minute' => ((int) $matches['open_hour'] * 60) + (int) $matches['open_minute'],
            'close_minute' => ((int) $matches['close_hour'] * 60) + (int) $matches['close_minute'],
        ];
    }

    private function pricePayload(mixed $price): ?string
    {
        return $price === null ? null : number_format((float) $price, 6, '.', '');
    }

    private function percentPayload(mixed $percent): ?string
    {
        return $percent === null ? null : number_format((float) $percent, 2, '.', '');
    }
}
