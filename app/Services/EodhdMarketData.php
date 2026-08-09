<?php

namespace App\Services;

use App\Models\EodhdExchange;
use App\Models\IndexWatchItem;
use App\Models\StockHolding;
use App\Models\StockHoldingIntradayCandle;
use App\Models\StockPrice;
use App\Models\StockRealtimePrice;
use App\Services\WebMarketData\DTO\ParsedQuote;
use App\Services\WebMarketData\DTO\ValidatedQuote;
use App\Services\WebMarketData\DTO\WebSourceCandidate;
use App\Services\WebMarketData\MarketHours;
use App\Services\WebMarketData\WebQuoteValidator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class EodhdMarketData
{
    private const IntradayDetailTradingDayCount = 7;

    /**
     * @var array<int, string>
     */
    private const IntradayIntervals = ['1m', '5m', '1h'];

    private const RealtimeSourceKey = 'eodhd_realtime';

    private const EodSourceKey = 'eodhd_eod';

    private const IntradaySourceKey = 'eodhd_intraday';

    public function __construct(
        private StockPriceCatalog $stockPriceCatalog,
        private StockRealtimePriceCatalog $stockRealtimePriceCatalog,
        private WebQuoteValidator $validator,
        private MarketHours $marketHours,
        private EodhdApiClient $apiClient,
        private EodhdErrorSanitizer $errorSanitizer,
        private IndexMarketHours $indexMarketHours,
    ) {}

    public function refreshSessionPriceFields(StockHolding $holding, ?ValidatedQuote $latestQuote = null): void
    {
        $session = $this->sessionPriceWindow($holding, $latestQuote?->quote);

        if ($session === null) {
            return;
        }

        $startQuote = $session['is_current_trading_day']
            ? $this->intradayStartQuote($holding, $session['open'], $session['close'])
            : null;

        collect([$startQuote])
            ->filter()
            ->each(fn (ValidatedQuote $quote): StockPrice => $this->storeHistoricalQuote($holding, $quote));

        $holding->update([
            ...$this->latestPriceAttributes($latestQuote, $session['is_open']),
            'start_price' => $this->quotePrice($startQuote)
                ?? $this->storedSessionStartPrice($holding, $session['open'], $session['close']),
            'end_price' => $session['is_open']
                ? null
                : $this->quotePriceWithinWindow($latestQuote, $session['open'], $session['close']->copy()->addDay())
                    ?? $this->storedSessionLatestRealtimePrice($holding, $session['open'], $session['close']->copy()->addDay())
                    ?? $this->storedSessionEndPrice($holding, $session['open'], $session['close']->copy()->addDay()),
            'end_price_24' => $this->storedSessionEndPrice($holding, $session['previous_open'], $session['today_open']),
            'end_price_48' => $this->storedSessionEndPrice($holding, $session['two_ago_open'], $session['previous_open']),
        ]);
    }

    public function startPrice(StockHolding $holding, Carbon $from, Carbon $until): ?string
    {
        $validatedQuote = $this->intradayStartQuote($holding, $from, $until);

        if ($validatedQuote === null) {
            return null;
        }

        $stockPrice = $this->stockPriceCatalog->store(
            $holding,
            $validatedQuote,
            $holding->trading_times ?? $this->marketHours->tradingTimes($validatedQuote->quote),
        );

        return (string) $stockPrice->price;
    }

    public function intradayStartQuote(StockHolding $holding, Carbon $from, Carbon $until): ?ValidatedQuote
    {
        $errors = [];
        $candidate = $this->candidate(
            $holding,
            self::IntradaySourceKey,
            'EODHD intraday',
            $this->intradayUrl($holding, $from, $until),
        );
        $records = $this->intradayRecords($holding, $from, $until, $errors);
        $record = $this->firstPriceRecordBetween($records, $from, $until);

        if ($record === null) {
            return null;
        }

        $quote = $this->quoteFromPayload(
            holding: $holding,
            candidate: $candidate,
            payload: $record,
            priceKey: 'close',
            priceType: 'historical_session_start',
            freshnessStatus: 'historical',
        );

        return $quote ? $this->validatedHistoricalQuote($quote) : null;
    }

    public function ensureIntradaySamples(StockHolding $holding): void
    {
        $session = $this->sessionPriceWindow($holding, null);

        if ($session === null) {
            return;
        }

        $until = $session['is_open']
            ? now()->utc()
            : $session['close'];

        if ($until->lessThanOrEqualTo($session['open'])) {
            return;
        }

        $emptySessionCacheKey = $this->intradayEmptySessionCacheKey($holding, $session['date']);

        if (Cache::get($emptySessionCacheKey) === true) {
            return;
        }

        $errors = [];
        $records = $this->intradayRecordsForInterval($holding, $session['open'], $until, '5m', $errors);

        if ($records === []) {
            Cache::put($emptySessionCacheKey, true, now()->addHours(6));

            return;
        }

        Cache::forget($emptySessionCacheKey);

        $this->persistIntradayCandles(
            $holding,
            $session['date'],
            '5m',
            $records,
            $this->intradayUrl($holding, $session['open'], $until, '5m'),
        );
    }

    /**
     * @return array{title: string, trading_date: string, interval: string, rows: array<int, array{timestamp: ?int, gmtoffset: ?int, datetime: ?string, open: ?string, high: ?string, low: ?string, close: ?string, volume: ?int}>}|null
     */
    public function ensureLastTradingDayFiveMinuteCandles(StockHolding $holding): ?array
    {
        $session = $this->lastCompletedTradingSessions($holding, 1)[0] ?? null;

        if ($session === null) {
            return null;
        }

        return $this->ensureFiveMinuteIntradayCandles($holding, $session);
    }

    /**
     * @return array<int, array{title: string, trading_date: string, interval: string, rows: array<int, array{timestamp: ?int, gmtoffset: ?int, datetime: ?string, open: ?string, high: ?string, low: ?string, close: ?string, volume: ?int}>}>
     */
    public function ensureLastSevenTradingDayFiveMinuteCandles(StockHolding $holding): array
    {
        $currentSession = $this->currentTradingSessionWithStoredFiveMinuteCandles($holding);
        $sessions = collect($this->lastCompletedTradingSessions($holding, self::IntradayDetailTradingDayCount));

        if ($currentSession !== null) {
            $sessions = collect([$currentSession])
                ->merge($sessions)
                ->unique(fn (array $session): string => $session['date']->toDateString())
                ->take(self::IntradayDetailTradingDayCount);
        }

        return $sessions
            ->map(fn (array $session): array => $this->ensureFiveMinuteIntradayCandles($holding, $session))
            ->all();
    }

    /**
     * @return array{date: Carbon, open: Carbon, close: Carbon}|null
     */
    private function currentTradingSessionWithStoredFiveMinuteCandles(StockHolding $holding): ?array
    {
        $session = $this->sessionPriceWindow($holding, null);

        if ($session === null || ! $session['is_open']) {
            return null;
        }

        if (! $this->hasStoredIntradayCandlePrices($holding, $session['date'], '5m')) {
            return null;
        }

        return [
            'date' => $session['date'],
            'open' => $session['open'],
            'close' => $session['close'],
        ];
    }

    /**
     * @param  array{date: Carbon, open: Carbon, close: Carbon}  $session
     * @return array{title: string, trading_date: string, interval: string, rows: array<int, array{timestamp: ?int, gmtoffset: ?int, datetime: ?string, open: ?string, high: ?string, low: ?string, close: ?string, volume: ?int}>}
     */
    private function ensureFiveMinuteIntradayCandles(StockHolding $holding, array $session): array
    {
        if (! $this->storedIntradayCandleQuery($holding, $session['date'], '5m')->exists()) {
            $errors = [];
            $records = $this->intradayRecordsForInterval($holding, $session['open'], $session['close'], '5m', $errors);
            $this->persistIntradayCandles(
                $holding,
                $session['date'],
                '5m',
                $records,
                $this->intradayUrl($holding, $session['open'], $session['close'], '5m'),
            );
        }

        return [
            'title' => 'Intraday '.$session['date']->format('d.m.Y'),
            'trading_date' => $session['date']->toDateString(),
            'interval' => '5m',
            'rows' => $this->storedIntradayCandlePayload($holding, $session['date'], '5m'),
        ];
    }

    public function intradaySessionDate(StockHolding $holding): ?string
    {
        $session = $this->sessionPriceWindow($holding, null);

        return $session === null
            ? null
            : $session['date']->toDateString();
    }

    public function storeHistoricalQuote(StockHolding $holding, ValidatedQuote $validatedQuote): StockPrice
    {
        $stockPrice = $this->stockPriceCatalog->store(
            $holding,
            $validatedQuote,
            $holding->trading_times ?? $this->marketHours->tradingTimes($validatedQuote->quote),
        );

        return $stockPrice;
    }

    public function exchangeCodeForHolding(StockHolding $holding): string
    {
        return $this->exchangeCode($holding);
    }

    public function exchangeCodeForIndexWatchItem(IndexWatchItem $item): string
    {
        $exchange = Str::upper((string) $item->exchange);

        if ($exchange !== '') {
            return $exchange;
        }

        return 'INDX';
    }

    /**
     * @return array{code: string, name: ?string, operating_mic: ?string, country: ?string, currency: ?string, timezone: ?string, is_open: bool, open: ?string, close: ?string, open_utc: ?string, close_utc: ?string, working_days: ?string, error: ?string}
     */
    public function exchangeDetailsForCode(string $exchangeCode): array
    {
        return $this->exchangeDetails($exchangeCode);
    }

    /**
     * @return array{code: string, name: ?string, operating_mic: ?string, country: ?string, currency: ?string, timezone: ?string, is_open: bool, open: ?string, close: ?string, open_utc: ?string, close_utc: ?string, working_days: ?string, error: ?string}
     */
    public function freshExchangeDetailsForCode(string $exchangeCode): array
    {
        return $this->fetchExchangeDetails(Str::upper(trim($exchangeCode)));
    }

    /**
     * @return array{code: string, name: ?string, operating_mic: ?string, country: ?string, currency: ?string, timezone: ?string, is_open: bool, open: ?string, close: ?string, open_utc: ?string, close_utc: ?string, working_days: ?string, error: ?string}
     */
    public function exchangeDetailsForIndexWatchItem(IndexWatchItem $item): array
    {
        $profile = $this->indexMarketHours->profile($item);
        $exchange = $this->storedMarketExchangeForIndexWatchItem($item);
        $details = $exchange
            ? $this->storedExchangeDetails($exchange->code)
            : $this->exchangeDetails($this->exchangeCodeForIndexWatchItem($item));

        if ($profile === null || $details === null) {
            return $details ?? $this->genericIndexExchangeDetails();
        }

        return [
            ...$details,
            'timezone' => $profile['timezone'],
            'open' => $profile['open'],
            'close' => $profile['close'],
        ];
    }

    /**
     * @param  iterable<int, StockHolding>  $holdings
     * @return array<int, array{code: string, name: ?string, operating_mic: ?string, country: ?string, currency: ?string, timezone: ?string, is_open: bool, open: ?string, close: ?string, open_utc: ?string, close_utc: ?string, working_days: ?string, error: ?string}>
     */
    public function exchangeTradingTimes(iterable $holdings): array
    {
        $exchangeCodes = collect($holdings)
            ->map(fn (StockHolding $holding): string => $this->exchangeCode($holding))
            ->filter();

        return $this->exchangeTradingTimesForCodes($exchangeCodes);
    }

    /**
     * @param  iterable<int, string>  $exchangeCodes
     * @return array<int, array{code: string, name: ?string, operating_mic: ?string, country: ?string, currency: ?string, timezone: ?string, is_open: bool, open: ?string, close: ?string, open_utc: ?string, close_utc: ?string, working_days: ?string, error: ?string}>
     */
    public function exchangeTradingTimesForCodes(iterable $exchangeCodes): array
    {
        return collect($exchangeCodes)
            ->filter(fn (string $exchangeCode): bool => trim($exchangeCode) !== '')
            ->unique()
            ->sort()
            ->values()
            ->map(fn (string $exchangeCode): array => $this->exchangeDetails($exchangeCode))
            ->all();
    }

    /**
     * @param  iterable<int, string>  $exchangeCodes
     * @return array<int, array{code: string, name: ?string, operating_mic: ?string, country: ?string, currency: ?string, timezone: ?string, is_open: bool, open: ?string, close: ?string, open_utc: ?string, close_utc: ?string, working_days: ?string, error: ?string}>
     */
    public function storedExchangeTradingTimesForCodes(iterable $exchangeCodes): array
    {
        return collect($exchangeCodes)
            ->filter(fn (string $exchangeCode): bool => trim($exchangeCode) !== '')
            ->unique()
            ->sort()
            ->values()
            ->map(fn (string $exchangeCode): ?array => $this->storedOrGenericExchangeDetails($exchangeCode))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public static function sourceKeys(): array
    {
        return [
            self::RealtimeSourceKey,
            self::IntradaySourceKey,
            self::EodSourceKey,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function intradayRecords(StockHolding $holding, Carbon $from, Carbon $until, array &$errors): array
    {
        foreach (self::IntradayIntervals as $interval) {
            $records = $this->intradayRecordsForInterval($holding, $from, $until, $interval, $errors);

            if ($records !== []) {
                return $records;
            }
        }

        return [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function intradayRecordsForInterval(
        StockHolding $holding,
        Carbon $from,
        Carbon $until,
        string $interval,
        array &$errors,
    ): array {
        $response = $this->get("intraday/{$this->eodhdSymbol($holding)}", [
            'from' => $from->copy()->utc()->timestamp,
            'to' => $until->copy()->utc()->timestamp,
            'interval' => $interval,
            'fmt' => 'json',
        ], $errors);

        if (! $response) {
            return [];
        }

        $payload = $this->errorSanitizer->payload($response->json());

        if (! is_array($payload)) {
            $errors[] = "EODHD returned an invalid {$interval} intraday response.";

            return [];
        }

        if (($payload['status'] ?? null) === 'error') {
            $errors[] = $this->errorMessage($payload);

            return [];
        }

        return collect($payload)
            ->filter(fn (mixed $record): bool => is_array($record))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function quoteFromPayload(
        StockHolding $holding,
        WebSourceCandidate $candidate,
        array $payload,
        string $priceKey,
        string $priceType,
        string $freshnessStatus,
        ?Carbon $asOf = null,
    ): ?ParsedQuote {
        $price = $this->decimal(Arr::get($payload, $priceKey));
        $asOf ??= $this->timestamp(Arr::get($payload, 'timestamp'), Arr::get($payload, 'datetime'));

        if ($price === null || $asOf === null) {
            return null;
        }

        return new ParsedQuote(
            sourceKey: $candidate->sourceKey,
            sourceName: $candidate->sourceName,
            sourceUrl: $candidate->url,
            sourceQuality: $candidate->quality,
            venue: $holding->exchange ?? $this->exchangeCode($holding),
            mic: $holding->mic_code,
            isin: $holding->isin,
            wkn: $holding->wkn,
            symbol: $holding->symbol,
            currency: $holding->currency ?? (string) config('market-data.default_currency', 'EUR'),
            close: $price,
            price: $price,
            priceType: $priceType,
            asOf: $asOf,
            fetchedAt: now(),
            freshnessStatus: $freshnessStatus,
            rawPayload: $this->errorSanitizer->payload($payload),
        );
    }

    /**
     * @return array{today_open: Carbon, previous_close: Carbon}|null
     */
    private function sessionAsOfTimes(StockHolding $holding, ParsedQuote $quote): ?array
    {
        if ($quote->asOf === null) {
            return null;
        }

        $tradingTimes = $holding->trading_times ?? $this->marketHours->tradingTimes($quote);
        $window = $this->tradingWindow($tradingTimes);

        if ($window === null) {
            return null;
        }

        $marketTimezone = $this->marketTimezone($tradingTimes) ?? $this->marketHours->timezone($quote);
        $sessionDate = $quote->asOf->copy()->setTimezone($marketTimezone)->startOfDay();
        $previousTradingDay = $this->previousTradingDay($sessionDate);

        return [
            'today_open' => $sessionDate->copy()->addMinutes($window[0])->utc(),
            'previous_close' => $previousTradingDay->copy()->addMinutes($window[1])->utc(),
        ];
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private function tradingWindow(string $tradingTimes): ?array
    {
        if (! preg_match('/(?<![:\d])(?<open_hour>\d{1,2}):(?<open_minute>\d{2})(?::\d{2})?\s*(?:-|to|until|bis)\s*(?<close_hour>\d{1,2}):(?<close_minute>\d{2})(?::\d{2})?/i', $tradingTimes, $matches)) {
            return null;
        }

        return [
            ((int) $matches['open_hour'] * 60) + (int) $matches['open_minute'],
            ((int) $matches['close_hour'] * 60) + (int) $matches['close_minute'],
        ];
    }

    private function marketTimezone(string $tradingTimes): ?string
    {
        if (preg_match('/\bEurope\/[A-Za-z_]+\b/', $tradingTimes, $matches)) {
            return $matches[0];
        }

        return null;
    }

    private function previousTradingDay(Carbon $date): Carbon
    {
        $previousTradingDay = $date->copy()->subDay();

        while ($previousTradingDay->isWeekend()) {
            $previousTradingDay->subDay();
        }

        return $previousTradingDay;
    }

    private function validatedHistoricalQuote(ParsedQuote $quote): ValidatedQuote
    {
        return new ValidatedQuote(
            quote: $quote,
            validationStatus: 'valid',
            freshnessStatus: 'historical',
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @return array<string, mixed>|null
     */
    private function firstPriceRecordBetween(array $records, Carbon $from, Carbon $until): ?array
    {
        return collect($records)
            ->map(function (array $record): ?array {
                $asOf = $this->timestamp(Arr::get($record, 'timestamp'), Arr::get($record, 'datetime'));
                $price = $this->decimal(Arr::get($record, 'close'));

                if ($asOf === null || $price === null) {
                    return null;
                }

                return [
                    'record' => $record,
                    'as_of' => $asOf,
                ];
            })
            ->filter()
            ->filter(fn (array $record): bool => $record['as_of']->gte($from) && $record['as_of']->lte($until))
            ->sortBy(fn (array $record): int => $record['as_of']->timestamp)
            ->value('record');
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @return array<string, mixed>|null
     */
    private function latestPriceRecordBetween(array $records, Carbon $from, Carbon $until): ?array
    {
        return collect($records)
            ->map(function (array $record): ?array {
                $asOf = $this->timestamp(Arr::get($record, 'timestamp'), Arr::get($record, 'datetime'));
                $price = $this->decimal(Arr::get($record, 'close'));

                if ($asOf === null || $price === null) {
                    return null;
                }

                return [
                    'record' => $record,
                    'as_of' => $asOf,
                ];
            })
            ->filter()
            ->filter(fn (array $record): bool => $record['as_of']->gte($from) && $record['as_of']->lte($until))
            ->sortByDesc(fn (array $record): int => $record['as_of']->timestamp)
            ->value('record');
    }

    /**
     * @param  array{date: Carbon, today_open: Carbon, open: Carbon, close: Carbon, previous_date: Carbon, previous_open: Carbon, previous_close: Carbon, two_ago_date: Carbon, two_ago_open: Carbon, two_ago_close: Carbon, is_open: bool, is_current_trading_day: bool}  $session
     */
    private function currentSessionIntradayUntil(array $session): Carbon
    {
        return $session['is_open']
            ? now()->utc()
            : $session['close'];
    }

    /**
     * @return array<string, mixed>
     */
    private function latestPriceAttributes(?ValidatedQuote $latestQuote, bool $exchangeIsOpen): array
    {
        if (! $latestQuote?->isSelectable()) {
            return [];
        }

        if (! $exchangeIsOpen) {
            return [
                'latest_price' => null,
            ];
        }

        $quote = $latestQuote->quote;

        return [
            'latest_price' => $quote->price,
            'latest_price_fetched_at' => $quote->fetchedAt?->copy()->utc(),
            'latest_price_source' => $quote->sourceName,
            'latest_price_source_url' => $quote->sourceUrl,
            'latest_price_as_of' => $quote->asOf?->copy()->utc(),
            'latest_price_type' => $quote->priceType,
            'price_spread_pct' => $latestQuote->spreadPct,
        ];
    }

    /**
     * @return array{date: Carbon, today_open: Carbon, open: Carbon, close: Carbon, previous_date: Carbon, previous_open: Carbon, previous_close: Carbon, two_ago_date: Carbon, two_ago_open: Carbon, two_ago_close: Carbon, is_open: bool, is_current_trading_day: bool}|null
     */
    private function sessionPriceWindow(StockHolding $holding, ?ParsedQuote $quote): ?array
    {
        $tradingTimes = $holding->trading_times ?? ($quote ? $this->marketHours->tradingTimes($quote) : null);

        if ($tradingTimes === null) {
            return null;
        }

        $window = $this->tradingWindow($tradingTimes);

        if ($window === null) {
            return null;
        }

        $timezone = $this->marketTimezone($tradingTimes) ?? ($quote ? $this->marketHours->timezone($quote) : 'Europe/Berlin');
        $localNow = now()->setTimezone($timezone);
        $today = $localNow->copy()->startOfDay();
        $currentMinute = ($localNow->hour * 60) + $localNow->minute;
        $isWorkingDay = ! $today->isWeekend();
        $isOpen = $isWorkingDay && $currentMinute >= $window[0] && $currentMinute < $window[1];
        $isCurrentTradingDay = $isWorkingDay && $currentMinute >= $window[0];
        $sessionDate = $isCurrentTradingDay
            ? $today
            : $this->previousTradingDay($today);
        $previousDate = $this->previousTradingDay($sessionDate);
        $twoAgoDate = $this->previousTradingDay($previousDate);

        return [
            'date' => $sessionDate,
            'today_open' => $sessionDate->copy()->addMinutes($window[0])->utc(),
            'open' => $sessionDate->copy()->addMinutes($window[0])->utc(),
            'close' => $sessionDate->copy()->addMinutes($window[1])->utc(),
            'previous_date' => $previousDate,
            'previous_open' => $previousDate->copy()->addMinutes($window[0])->utc(),
            'previous_close' => $previousDate->copy()->addMinutes($window[1])->utc(),
            'two_ago_date' => $twoAgoDate,
            'two_ago_open' => $twoAgoDate->copy()->addMinutes($window[0])->utc(),
            'two_ago_close' => $twoAgoDate->copy()->addMinutes($window[1])->utc(),
            'is_open' => $isOpen,
            'is_current_trading_day' => $isCurrentTradingDay,
        ];
    }

    private function quotePrice(?ValidatedQuote $quote): ?string
    {
        return $quote?->quote->price;
    }

    private function quotePriceWithinWindow(?ValidatedQuote $quote, Carbon $from, Carbon $until): ?string
    {
        if (! $quote?->isSelectable() || $quote->quote->asOf === null) {
            return null;
        }

        if ($quote->quote->asOf->lt($from) || $quote->quote->asOf->gte($until)) {
            return null;
        }

        return $quote->quote->price;
    }

    private function storedSessionStartPrice(StockHolding $holding, Carbon $from, Carbon $until): ?string
    {
        return $this->storedSessionPriceQuery($holding, $from, $until)
            ->where('price_type', 'historical_session_start')
            ->orderBy('as_of')
            ->orderBy('id')
            ->value('price')
            ?? $this->storedSessionRealtimePriceQuery($holding, $from, $until)
                ->where('price_type', 'historical_session_start')
                ->orderBy('as_of')
                ->orderBy('id')
                ->value('price')
            ?? $this->storedSessionPriceQuery($holding, $from, $until)
                ->orderBy('as_of')
                ->orderBy('id')
                ->value('price')
            ?? $this->storedSessionRealtimePriceQuery($holding, $from, $until)
                ->orderBy('as_of')
                ->orderBy('id')
                ->value('price');
    }

    private function storedSessionEndPrice(StockHolding $holding, Carbon $from, Carbon $until): ?string
    {
        return $this->storedSessionPriceQuery($holding, $from, $until)
            ->where('price_type', 'historical_session_end')
            ->orderByDesc('as_of')
            ->orderByDesc('id')
            ->value('price')
            ?? $this->storedSessionRealtimePriceQuery($holding, $from, $until)
                ->where('price_type', 'historical_session_end')
                ->orderByDesc('as_of')
                ->orderByDesc('id')
                ->value('price')
            ?? $this->storedSessionPriceQuery($holding, $from, $until)
                ->orderByDesc('as_of')
                ->orderByDesc('id')
                ->value('price')
            ?? $this->storedSessionRealtimePriceQuery($holding, $from, $until)
                ->orderByDesc('as_of')
                ->orderByDesc('id')
                ->value('price');
    }

    private function storedSessionLatestRealtimePrice(StockHolding $holding, Carbon $from, Carbon $until): ?string
    {
        return $this->storedSessionRealtimePriceQuery($holding, $from, $until)
            ->where('price_type', 'last')
            ->orderByDesc('as_of')
            ->orderByDesc('id')
            ->value('price');
    }

    /**
     * @return Builder<StockPrice>
     */
    private function storedSessionPriceQuery(StockHolding $holding, Carbon $from, Carbon $until): Builder
    {
        return $this->stockPriceCatalog
            ->pricesForHolding($holding)
            ->whereIn('source_key', self::sourceKeys())
            ->where('validation_status', 'valid')
            ->whereNotNull('price')
            ->whereNotNull('as_of')
            ->where('as_of', '>=', $from->copy()->utc())
            ->where('as_of', '<', $until->copy()->utc());
    }

    /**
     * @return Builder<StockRealtimePrice>
     */
    private function storedSessionRealtimePriceQuery(StockHolding $holding, Carbon $from, Carbon $until): Builder
    {
        return $this->stockRealtimePriceCatalog
            ->pricesForHolding($holding)
            ->where('source_key', self::RealtimeSourceKey)
            ->where('validation_status', 'valid')
            ->whereNotNull('price')
            ->whereNotNull('as_of')
            ->where('as_of', '>=', $from->copy()->utc())
            ->where('as_of', '<', $until->copy()->utc());
    }

    private function get(string $path, array $query, array &$errors): ?Response
    {
        if (! $this->apiClient->configured()) {
            $errors[] = 'EODHD API token is not configured.';

            return null;
        }

        try {
            $response = $this->apiClient->get($path, $query);
        } catch (Throwable $exception) {
            $errors[] = 'EODHD request failed: '.$this->errorSanitizer->message($exception->getMessage());

            return null;
        }

        if ($response->failed()) {
            $errors[] = "EODHD request failed with HTTP {$response->status()}.";

            return null;
        }

        return $response;
    }

    /**
     * @return array{code: string, name: ?string, operating_mic: ?string, country: ?string, currency: ?string, timezone: ?string, is_open: bool, open: ?string, close: ?string, open_utc: ?string, close_utc: ?string, working_days: ?string, error: ?string}
     */
    private function exchangeDetails(string $exchangeCode): array
    {
        $exchangeCode = Str::upper(trim($exchangeCode));

        $storedDetails = $this->storedOrGenericExchangeDetails($exchangeCode);

        if ($storedDetails !== null) {
            return $storedDetails;
        }

        $cacheKey = "eodhd.exchange-details.{$exchangeCode}";
        $cachedDetails = Cache::get($cacheKey);

        if (is_array($cachedDetails)) {
            return $cachedDetails;
        }

        $details = $this->fetchExchangeDetails($exchangeCode);

        if ($details['error'] === null) {
            Cache::put($cacheKey, $details, now()->addHours(6));
        }

        return $details;
    }

    /**
     * @return array{code: string, name: ?string, operating_mic: ?string, country: ?string, currency: ?string, timezone: ?string, is_open: bool, open: ?string, close: ?string, lunch_begin: ?string, lunch_end: ?string, open_utc: ?string, close_utc: ?string, working_days: ?string, sessions: array<int, array{open: string, close: string}>, holidays: array<int, string>, error: ?string}|null
     */
    private function storedOrGenericExchangeDetails(string $exchangeCode): ?array
    {
        $exchangeCode = Str::upper(trim($exchangeCode));

        if ($exchangeCode === 'INDX') {
            return $this->genericIndexExchangeDetails();
        }

        return $this->storedExchangeDetails($exchangeCode);
    }

    /**
     * @return array{code: string, name: ?string, operating_mic: ?string, country: ?string, currency: ?string, timezone: ?string, is_open: bool, open: ?string, close: ?string, lunch_begin: ?string, lunch_end: ?string, open_utc: ?string, close_utc: ?string, working_days: ?string, sessions: array<int, array{open: string, close: string}>, holidays: array<int, string>, error: ?string}|null
     */
    private function storedExchangeDetails(string $exchangeCode): ?array
    {
        $exchange = EodhdExchange::query()
            ->where('code', $exchangeCode)
            ->orWhere('detail_code', $exchangeCode)
            ->first();

        if ($exchange === null) {
            return null;
        }

        $tradingHours = is_array($exchange->trading_hours) ? $exchange->trading_hours : [];

        return [
            'code' => $exchange->code,
            'name' => $exchange->name,
            'operating_mic' => $exchange->operating_mic,
            'country' => $exchange->country,
            'currency' => $exchange->currency,
            'timezone' => $exchange->timezone,
            'is_open' => false,
            'open' => $this->stringOrNull(Arr::get($tradingHours, 'Open')),
            'close' => $this->stringOrNull(Arr::get($tradingHours, 'Close')),
            'lunch_begin' => $this->stringOrNull(Arr::get($tradingHours, 'LunchBegin')),
            'lunch_end' => $this->stringOrNull(Arr::get($tradingHours, 'LunchEnd')),
            'open_utc' => $this->stringOrNull(Arr::get($tradingHours, 'OpenUTC')),
            'close_utc' => $this->stringOrNull(Arr::get($tradingHours, 'CloseUTC')),
            'working_days' => $this->stringOrNull(Arr::get($tradingHours, 'WorkingDays')),
            'sessions' => $this->exchangeSessions($tradingHours),
            'holidays' => $this->exchangeHolidays(['ExchangeHolidays' => $exchange->holidays ?? []]),
            'error' => null,
        ];
    }

    private function storedMarketExchangeForIndexWatchItem(IndexWatchItem $item): ?EodhdExchange
    {
        $marketIdentifiers = collect([$item->exchange, $item->mic_code])
            ->filter(fn (mixed $identifier): bool => is_string($identifier) && trim($identifier) !== '')
            ->map(fn (string $identifier): string => Str::upper(trim($identifier)))
            ->reject(fn (string $identifier): bool => $identifier === 'INDX')
            ->unique()
            ->values();

        if ($marketIdentifiers->isNotEmpty()) {
            $exchange = EodhdExchange::query()
                ->whereNotNull('trading_hours')
                ->where(function (Builder $query) use ($marketIdentifiers): void {
                    $query
                        ->whereIn('code', $marketIdentifiers)
                        ->orWhereIn('detail_code', $marketIdentifiers)
                        ->orWhereIn('operating_mic', $marketIdentifiers);
                })
                ->latest('synced_at')
                ->first();

            if ($exchange) {
                return $exchange;
            }
        }

        $country = trim((string) $item->country);

        if ($country === '') {
            return null;
        }

        return EodhdExchange::query()
            ->where('country', $country)
            ->whereNotNull('trading_hours')
            ->latest('synced_at')
            ->first();
    }

    /**
     * @return array{code: string, name: ?string, operating_mic: ?string, country: ?string, currency: ?string, timezone: ?string, is_open: bool, open: ?string, close: ?string, lunch_begin: ?string, lunch_end: ?string, open_utc: ?string, close_utc: ?string, working_days: ?string, sessions: array<int, array{open: string, close: string}>, holidays: array<int, string>, error: ?string}
     */
    private function genericIndexExchangeDetails(): array
    {
        $tradingHours = [
            'Open' => '00:00:00',
            'Close' => '23:59:00',
            'OpenUTC' => '00:00:00',
            'CloseUTC' => '23:59:00',
            'WorkingDays' => 'Mon,Tue,Wed,Thu,Fri',
        ];

        return [
            'code' => 'INDX',
            'name' => 'Index Data',
            'operating_mic' => null,
            'country' => null,
            'currency' => null,
            'timezone' => 'UTC',
            'is_open' => false,
            'open' => $tradingHours['Open'],
            'close' => $tradingHours['Close'],
            'lunch_begin' => null,
            'lunch_end' => null,
            'open_utc' => $tradingHours['OpenUTC'],
            'close_utc' => $tradingHours['CloseUTC'],
            'working_days' => $tradingHours['WorkingDays'],
            'sessions' => $this->exchangeSessions($tradingHours),
            'holidays' => [],
            'error' => null,
        ];
    }

    /**
     * @return array{code: string, name: ?string, operating_mic: ?string, country: ?string, currency: ?string, timezone: ?string, is_open: bool, open: ?string, close: ?string, open_utc: ?string, close_utc: ?string, working_days: ?string, error: ?string}
     */
    private function fetchExchangeDetails(string $exchangeCode): array
    {
        $errors = [];
        $response = $this->get("exchange-details/{$exchangeCode}", [
            'fmt' => 'json',
        ], $errors);

        if (! $response) {
            return $this->exchangeDetailsError($exchangeCode, implode(' ', $errors));
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            return $this->exchangeDetailsError($exchangeCode, 'EODHD returned an invalid exchange details response.');
        }

        if (($payload['status'] ?? null) === 'error') {
            return $this->exchangeDetailsError($exchangeCode, $this->errorMessage($payload));
        }

        $tradingHours = Arr::get($payload, 'TradingHours');

        if (! is_array($tradingHours)) {
            $tradingHours = [];
        }

        return [
            'code' => $this->stringOrNull(Arr::get($payload, 'Code')) ?? $exchangeCode,
            'name' => $this->stringOrNull(Arr::get($payload, 'Name')),
            'operating_mic' => $this->stringOrNull(Arr::get($payload, 'OperatingMIC')),
            'country' => $this->stringOrNull(Arr::get($payload, 'Country')),
            'currency' => $this->stringOrNull(Arr::get($payload, 'Currency')),
            'timezone' => $this->stringOrNull(Arr::get($payload, 'Timezone')),
            'is_open' => (bool) Arr::get($payload, 'isOpen', false),
            'open' => $this->stringOrNull(Arr::get($tradingHours, 'Open')),
            'close' => $this->stringOrNull(Arr::get($tradingHours, 'Close')),
            'lunch_begin' => $this->stringOrNull(Arr::get($tradingHours, 'LunchBegin')),
            'lunch_end' => $this->stringOrNull(Arr::get($tradingHours, 'LunchEnd')),
            'open_utc' => $this->stringOrNull(Arr::get($tradingHours, 'OpenUTC')),
            'close_utc' => $this->stringOrNull(Arr::get($tradingHours, 'CloseUTC')),
            'working_days' => $this->stringOrNull(Arr::get($tradingHours, 'WorkingDays')),
            'sessions' => $this->exchangeSessions($tradingHours),
            'holidays' => $this->exchangeHolidays($payload),
            'error' => null,
        ];
    }

    /**
     * @return array{code: string, name: ?string, operating_mic: ?string, country: ?string, currency: ?string, timezone: ?string, is_open: bool, open: ?string, close: ?string, open_utc: ?string, close_utc: ?string, working_days: ?string, error: ?string}
     */
    private function exchangeDetailsError(string $exchangeCode, string $message): array
    {
        return [
            'code' => $exchangeCode,
            'name' => null,
            'operating_mic' => null,
            'country' => null,
            'currency' => null,
            'timezone' => null,
            'is_open' => false,
            'open' => null,
            'close' => null,
            'lunch_begin' => null,
            'lunch_end' => null,
            'open_utc' => null,
            'close_utc' => null,
            'working_days' => null,
            'sessions' => [],
            'holidays' => [],
            'error' => trim($message) !== '' ? $message : 'EODHD exchange details are unavailable.',
        ];
    }

    private function candidate(
        StockHolding $holding,
        string $sourceKey,
        string $sourceName,
        string $url,
    ): WebSourceCandidate {
        return new WebSourceCandidate(
            sourceKey: $sourceKey,
            sourceName: $sourceName,
            url: $url,
            parserKey: $sourceKey,
            quality: 'market_data_vendor',
            priority: 10,
            venue: $holding->exchange,
            mic: $holding->mic_code,
            confidenceScore: 100,
            verified: true,
        );
    }

    /**
     * @return array<int, array{date: Carbon, open: Carbon, close: Carbon}>
     */
    private function lastCompletedTradingSessions(StockHolding $holding, int $count): array
    {
        $session = $this->sessionPriceWindow($holding, null);

        if ($session === null) {
            return [];
        }

        $openMinute = (int) $session['previous_date']->copy()->startOfDay()->diffInMinutes($session['previous_open']);
        $closeMinute = (int) $session['previous_date']->copy()->startOfDay()->diffInMinutes($session['previous_close']);
        $tradingDate = $session['is_open']
            ? $session['previous_date']->copy()
            : $session['date']->copy();
        $sessions = [];

        while (count($sessions) < $count) {
            $sessions[] = [
                'date' => $tradingDate->copy(),
                'open' => $tradingDate->copy()->addMinutes($openMinute)->utc(),
                'close' => $tradingDate->copy()->addMinutes($closeMinute)->utc(),
            ];

            $tradingDate = $this->previousTradingDay($tradingDate);
        }

        return $sessions;
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     */
    private function persistIntradayCandles(
        StockHolding $holding,
        Carbon $tradingDate,
        string $interval,
        array $records,
        string $sourceUrl,
    ): void {
        $now = now();
        $rows = collect($records)
            ->map(fn (array $record): ?array => $this->intradayCandleRow($holding, $tradingDate, $interval, $record, $sourceUrl, $now))
            ->filter()
            ->values()
            ->all();

        if ($rows === []) {
            return;
        }

        StockHoldingIntradayCandle::query()->upsert(
            $rows,
            uniqueBy: ['stock_holding_id', 'interval', 'as_of'],
            update: ['timestamp', 'gmtoffset', 'datetime', 'open', 'high', 'low', 'close', 'volume', 'currency', 'source_key', 'source_name', 'source_url', 'raw_payload', 'updated_at'],
        );
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array{stock_holding_id: int|null, trading_date: string, interval: string, as_of: Carbon, timestamp: ?int, gmtoffset: ?int, datetime: ?string, open: ?string, high: ?string, low: ?string, close: ?string, volume: ?int, currency: ?string, source_key: string, source_name: string, source_url: string, raw_payload: ?string, created_at: Carbon, updated_at: Carbon}|null
     */
    private function intradayCandleRow(
        StockHolding $holding,
        Carbon $tradingDate,
        string $interval,
        array $record,
        string $sourceUrl,
        Carbon $now,
    ): ?array {
        $asOf = $this->timestamp(Arr::get($record, 'timestamp'), Arr::get($record, 'datetime'));

        if ($asOf === null) {
            return null;
        }

        $prices = [
            'open' => $this->decimal(Arr::get($record, 'open')),
            'high' => $this->decimal(Arr::get($record, 'high')),
            'low' => $this->decimal(Arr::get($record, 'low')),
            'close' => $this->decimal(Arr::get($record, 'close')),
        ];

        if (! $this->hasIntradayCandlePriceData($prices)) {
            return null;
        }

        return [
            'stock_holding_id' => $holding->id,
            'trading_date' => $tradingDate->toDateString(),
            'interval' => $interval,
            'as_of' => $asOf,
            'timestamp' => $this->integerOrNull(Arr::get($record, 'timestamp')),
            'gmtoffset' => $this->integerOrNull(Arr::get($record, 'gmtoffset')),
            'datetime' => $this->stringOrNull(Arr::get($record, 'datetime')),
            ...$prices,
            'volume' => $this->positiveIntegerOrNull(Arr::get($record, 'volume')),
            'currency' => $holding->currency,
            'source_key' => self::IntradaySourceKey,
            'source_name' => 'EODHD intraday',
            'source_url' => $sourceUrl,
            'raw_payload' => $this->jsonPayload($record),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @param  array{open: ?string, high: ?string, low: ?string, close: ?string}  $prices
     */
    private function hasIntradayCandlePriceData(array $prices): bool
    {
        return collect($prices)->contains(fn (?string $price): bool => $price !== null);
    }

    /**
     * @return array<int, array{timestamp: ?int, gmtoffset: ?int, datetime: ?string, open: ?string, high: ?string, low: ?string, close: ?string, volume: ?int}>
     */
    private function storedIntradayCandlePayload(StockHolding $holding, Carbon $tradingDate, string $interval): array
    {
        return $this->storedIntradayCandleQuery($holding, $tradingDate, $interval)
            ->orderBy('as_of')
            ->orderBy('id')
            ->get(['timestamp', 'gmtoffset', 'datetime', 'open', 'high', 'low', 'close', 'volume'])
            ->map(fn (StockHoldingIntradayCandle $candle): array => [
                'timestamp' => $candle->timestamp,
                'gmtoffset' => $candle->gmtoffset,
                'datetime' => $candle->datetime,
                'open' => $candle->open === null ? null : (string) $candle->open,
                'high' => $candle->high === null ? null : (string) $candle->high,
                'low' => $candle->low === null ? null : (string) $candle->low,
                'close' => $candle->close === null ? null : (string) $candle->close,
                'volume' => $candle->volume,
            ])
            ->all();
    }

    /**
     * @return Builder<StockHoldingIntradayCandle>
     */
    private function storedIntradayCandleQuery(StockHolding $holding, Carbon $tradingDate, string $interval): Builder
    {
        return StockHoldingIntradayCandle::query()
            ->where('stock_holding_id', $holding->id)
            ->whereDate('trading_date', $tradingDate->toDateString())
            ->where('interval', $interval)
            ->where('source_key', self::IntradaySourceKey);
    }

    private function hasStoredIntradayCandlePrices(StockHolding $holding, Carbon $tradingDate, string $interval): bool
    {
        return $this->storedIntradayCandleQuery($holding, $tradingDate, $interval)
            ->where(function (Builder $query): void {
                $query
                    ->whereNotNull('open')
                    ->orWhereNotNull('high')
                    ->orWhereNotNull('low')
                    ->orWhereNotNull('close');
            })
            ->exists();
    }

    private function intradayUrl(StockHolding $holding, Carbon $from, Carbon $until, string $interval = '1m'): string
    {
        return "{$this->baseUrl()}/intraday/{$this->eodhdSymbol($holding)}?from={$from->copy()->utc()->timestamp}&to={$until->copy()->utc()->timestamp}&interval={$interval}&fmt=json";
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.eodhd.base_url', 'https://eodhd.com/api'), '/');
    }

    private function eodhdSymbol(StockHolding $holding): string
    {
        return Str::upper((string) $holding->symbol).'.'.$this->exchangeCode($holding);
    }

    private function intradayEmptySessionCacheKey(StockHolding $holding, Carbon $sessionDate): string
    {
        return 'eodhd.intraday-empty-session.v2.'.implode('.', [
            $holding->id,
            $this->eodhdSymbol($holding),
            $sessionDate->toDateString(),
        ]);
    }

    private function exchangeCode(StockHolding $holding): string
    {
        $mic = Str::upper((string) $holding->mic_code);
        $exchange = Str::lower((string) $holding->exchange);

        if ($mic === 'XETR' || Str::contains($exchange, 'xetra')) {
            return 'XETRA';
        }

        if ($mic === 'XSHG' || Str::contains($exchange, ['shanghai', 'shg'])) {
            return 'SHG';
        }

        if (in_array($mic, ['XNAS', 'XNYS', 'ARCX'], true) || in_array(Str::lower((string) $holding->country), ['united states', 'usa', 'us'], true)) {
            return 'US';
        }

        if ($mic !== '') {
            return $mic;
        }

        return Str::upper(Str::replace(' ', '', (string) $holding->exchange));
    }

    /**
     * @param  array<string, mixed>  $tradingHours
     * @return array<int, array{open: string, close: string}>
     */
    private function exchangeSessions(array $tradingHours): array
    {
        $open = $this->stringOrNull(Arr::get($tradingHours, 'Open'));
        $close = $this->stringOrNull(Arr::get($tradingHours, 'Close'));

        if ($open === null || $close === null) {
            return [];
        }

        $lunchBegin = $this->stringOrNull(Arr::get($tradingHours, 'LunchBegin'));
        $lunchEnd = $this->stringOrNull(Arr::get($tradingHours, 'LunchEnd'));

        if (
            $lunchBegin !== null
            && $lunchEnd !== null
            && $this->clockMinutes($open) < $this->clockMinutes($lunchBegin)
            && $this->clockMinutes($lunchBegin) < $this->clockMinutes($lunchEnd)
            && $this->clockMinutes($lunchEnd) < $this->clockMinutes($close)
        ) {
            return [
                ['open' => $open, 'close' => $lunchBegin],
                ['open' => $lunchEnd, 'close' => $close],
            ];
        }

        return [
            ['open' => $open, 'close' => $close],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    private function exchangeHolidays(array $payload): array
    {
        $holidays = Arr::get($payload, 'ExchangeHolidays');

        if (! is_array($holidays)) {
            return [];
        }

        return collect($holidays)
            ->map(fn (mixed $holiday): ?string => is_array($holiday)
                ? $this->stringOrNull(Arr::get($holiday, 'Date'))
                : null)
            ->filter()
            ->values()
            ->all();
    }

    private function clockMinutes(?string $value): int
    {
        if ($value === null || ! preg_match('/^(?<hour>\d{1,2}):(?<minute>\d{2})/', $value, $matches)) {
            return -1;
        }

        return ((int) $matches['hour'] * 60) + (int) $matches['minute'];
    }

    private function decimal(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        if (! preg_match('/^-?\d+(?:\.\d+)?$/', $value) || (float) $value <= 0) {
            return null;
        }

        return number_format((float) $value, 8, '.', '');
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function integerOrNull(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function positiveIntegerOrNull(mixed $value): ?int
    {
        if (! is_numeric($value) || (int) $value < 0) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function jsonPayload(array $payload): ?string
    {
        $encodedPayload = json_encode($this->errorSanitizer->payload($payload));

        return is_string($encodedPayload) ? $encodedPayload : null;
    }

    private function timestamp(mixed $timestamp, mixed $datetime = null): ?Carbon
    {
        if (is_numeric($timestamp) && (int) $timestamp > 0) {
            return Carbon::createFromTimestampUTC((int) $timestamp)
                ->setTimezone(config('app.timezone'));
        }

        if (is_string($datetime) && trim($datetime) !== '') {
            try {
                return Carbon::parse($datetime, 'UTC')
                    ->setTimezone(config('app.timezone'));
            } catch (Throwable) {
            }
        }

        return null;
    }

    private function errorMessage(array $payload): string
    {
        $message = Arr::get($payload, 'message');

        $message = is_string($message) && trim($message) !== ''
            ? $message
            : 'EODHD returned an error response.';

        return $this->errorSanitizer->message($message);
    }
}
