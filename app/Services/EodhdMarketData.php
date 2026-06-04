<?php

namespace App\Services;

use App\Models\StockHolding;
use App\Models\StockPrice;
use App\Services\WebMarketData\DTO\InstrumentIdentity;
use App\Services\WebMarketData\DTO\ParsedQuote;
use App\Services\WebMarketData\DTO\QuoteSelectionResult;
use App\Services\WebMarketData\DTO\ValidatedQuote;
use App\Services\WebMarketData\DTO\WebSourceCandidate;
use App\Services\WebMarketData\MarketHours;
use App\Services\WebMarketData\WebQuoteValidator;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class EodhdMarketData
{
    private const RealtimeSourceKey = 'eodhd_realtime';

    private const IntradaySourceKey = 'eodhd_intraday';

    private const EodSourceKey = 'eodhd_eod';

    public function __construct(
        private StockPriceCatalog $stockPriceCatalog,
        private WebQuoteValidator $validator,
        private MarketHours $marketHours,
        private EodhdApiClient $apiClient,
    ) {}

    public function resolve(StockHolding $holding): QuoteSelectionResult
    {
        $candidate = $this->candidate($holding, self::RealtimeSourceKey, 'EODHD real-time');
        $errors = [];
        $quote = $this->realtimeQuote($holding, $candidate, $errors);
        $validatedQuote = $quote
            ? $this->validator->validate($quote, InstrumentIdentity::fromHolding($holding))
            : null;

        $result = new QuoteSelectionResult(
            selectedQuote: $validatedQuote?->isSelectable() ? $validatedQuote : null,
            quotes: $validatedQuote ? [$validatedQuote] : [],
            attemptedSources: [$candidate],
            errors: $errors,
            status: $validatedQuote?->isSelectable()
                ? $validatedQuote->freshnessStatus
                : 'unavailable',
        );

        $this->persistResult($holding, $result);

        return $result;
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

    public function dailyOpenQuote(StockHolding $holding, Carbon $sessionDate, Carbon $asOf): ?ValidatedQuote
    {
        return $this->dailyQuote(
            holding: $holding,
            sessionDate: $sessionDate,
            asOf: $asOf,
            priceKey: 'open',
            priceType: 'historical_session_start',
            sourceName: 'EODHD EOD open',
        );
    }

    public function dailyCloseQuote(StockHolding $holding, Carbon $sessionDate, Carbon $asOf): ?ValidatedQuote
    {
        return $this->dailyQuote(
            holding: $holding,
            sessionDate: $sessionDate,
            asOf: $asOf,
            priceKey: 'close',
            priceType: 'historical_session_end',
            sourceName: 'EODHD EOD close',
        );
    }

    public function storeHistoricalQuote(StockHolding $holding, ValidatedQuote $validatedQuote): StockPrice
    {
        return $this->stockPriceCatalog->store(
            $holding,
            $validatedQuote,
            $holding->trading_times ?? $this->marketHours->tradingTimes($validatedQuote->quote),
        );
    }

    public function exchangeCodeForHolding(StockHolding $holding): string
    {
        return $this->exchangeCode($holding);
    }

    /**
     * @return array{code: string, name: ?string, operating_mic: ?string, country: ?string, currency: ?string, timezone: ?string, is_open: bool, open: ?string, close: ?string, open_utc: ?string, close_utc: ?string, working_days: ?string, error: ?string}
     */
    public function exchangeDetailsForCode(string $exchangeCode): array
    {
        return $this->exchangeDetails($exchangeCode);
    }

    /**
     * @param  iterable<int, StockHolding>  $holdings
     * @return array<int, array{code: string, name: ?string, operating_mic: ?string, country: ?string, currency: ?string, timezone: ?string, is_open: bool, open: ?string, close: ?string, open_utc: ?string, close_utc: ?string, working_days: ?string, error: ?string}>
     */
    public function exchangeTradingTimes(iterable $holdings): array
    {
        return collect($holdings)
            ->map(fn (StockHolding $holding): string => $this->exchangeCode($holding))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->map(fn (string $exchangeCode): array => $this->exchangeDetails($exchangeCode))
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

    private function realtimeQuote(StockHolding $holding, WebSourceCandidate $candidate, array &$errors): ?ParsedQuote
    {
        $response = $this->get("real-time/{$this->eodhdSymbol($holding)}", [
            'fmt' => 'json',
        ], $errors);

        if (! $response) {
            return null;
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            $errors[] = 'EODHD returned an invalid real-time response.';

            return null;
        }

        if (($payload['status'] ?? null) === 'error') {
            $errors[] = $this->errorMessage($payload);

            return null;
        }

        return $this->quoteFromPayload(
            holding: $holding,
            candidate: $candidate,
            payload: $payload,
            priceKey: 'close',
            priceType: 'last',
            freshnessStatus: 'fresh',
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function intradayRecords(StockHolding $holding, Carbon $from, Carbon $until, array &$errors): array
    {
        $response = $this->get("intraday/{$this->eodhdSymbol($holding)}", [
            'from' => $from->copy()->utc()->timestamp,
            'to' => $until->copy()->utc()->timestamp,
            'interval' => '1m',
            'fmt' => 'json',
        ], $errors);

        if (! $response) {
            return [];
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            $errors[] = 'EODHD returned an invalid intraday response.';

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

    private function dailyQuote(
        StockHolding $holding,
        Carbon $sessionDate,
        Carbon $asOf,
        string $priceKey,
        string $priceType,
        string $sourceName,
    ): ?ValidatedQuote {
        $candidate = $this->candidate(
            $holding,
            self::EodSourceKey,
            $sourceName,
            $this->eodUrl($holding, $sessionDate),
        );
        $errors = [];
        $records = $this->dailyRecords($holding, $sessionDate, $errors);
        $record = collect($records)->first();

        if (! is_array($record)) {
            return null;
        }

        $record['timestamp'] = $asOf->copy()->utc()->timestamp;

        $quote = $this->quoteFromPayload(
            holding: $holding,
            candidate: $candidate,
            payload: $record,
            priceKey: $priceKey,
            priceType: $priceType,
            freshnessStatus: 'historical',
        );

        return $quote ? $this->validatedHistoricalQuote($quote) : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function dailyRecords(StockHolding $holding, Carbon $sessionDate, array &$errors): array
    {
        $date = $sessionDate->toDateString();
        $response = $this->get("eod/{$this->eodhdSymbol($holding)}", [
            'from' => $date,
            'to' => $date,
            'period' => 'd',
            'fmt' => 'json',
        ], $errors);

        if (! $response) {
            return [];
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            $errors[] = 'EODHD returned an invalid EOD response.';

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
    ): ?ParsedQuote {
        $price = $this->decimal(Arr::get($payload, $priceKey));
        $asOf = $this->timestamp(Arr::get($payload, 'timestamp'), Arr::get($payload, 'datetime'));

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
            rawPayload: $payload,
        );
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
            ->filter(fn (array $record): bool => $record['as_of']->gte($from) && $record['as_of']->lt($until))
            ->sortBy(fn (array $record): int => $record['as_of']->timestamp)
            ->value('record');
    }

    private function persistResult(StockHolding $holding, QuoteSelectionResult $result): void
    {
        if (! $result->selectedQuote) {
            if (! $this->holdingHasStoredPrice($holding)) {
                $holding->update([
                    'latest_stock_price_id' => null,
                    'latest_price_source' => null,
                    'latest_price_source_url' => null,
                    'latest_price_as_of' => null,
                    'price_status' => 'unavailable_now',
                    'latest_price_type' => null,
                    'price_spread_pct' => null,
                ]);

                return;
            }

            $holding->update([
                'price_status' => 'stale',
            ]);

            return;
        }

        if ($this->selectedQuoteIsOlderThanHolding($result->selectedQuote, $holding)) {
            return;
        }

        $quote = $result->selectedQuote->quote;
        $selectedPrice = $this->stockPriceCatalog->store(
            $holding,
            $result->selectedQuote,
            $this->marketHours->tradingTimes($quote),
        );

        $holding->update([
            'currency' => $quote->currency ?? $holding->currency,
            'latest_stock_price_id' => $selectedPrice->id,
            'price_status' => $result->status,
            'source_verified_at' => now(),
            'trading_times' => $selectedPrice->trading_times,
        ]);
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
            $errors[] = "EODHD request failed: {$exception->getMessage()}";

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
            'open_utc' => $this->stringOrNull(Arr::get($tradingHours, 'OpenUTC')),
            'close_utc' => $this->stringOrNull(Arr::get($tradingHours, 'CloseUTC')),
            'working_days' => $this->stringOrNull(Arr::get($tradingHours, 'WorkingDays')),
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
            'open_utc' => null,
            'close_utc' => null,
            'working_days' => null,
            'error' => trim($message) !== '' ? $message : 'EODHD exchange details are unavailable.',
        ];
    }

    private function candidate(
        StockHolding $holding,
        string $sourceKey,
        string $sourceName,
        ?string $url = null,
    ): WebSourceCandidate {
        return new WebSourceCandidate(
            sourceKey: $sourceKey,
            sourceName: $sourceName,
            url: $url ?? $this->realtimeUrl($holding),
            parserKey: $sourceKey,
            quality: 'market_data_vendor',
            priority: 10,
            venue: $holding->exchange,
            mic: $holding->mic_code,
            confidenceScore: 100,
            verified: true,
        );
    }

    private function realtimeUrl(StockHolding $holding): string
    {
        return "{$this->baseUrl()}/real-time/{$this->eodhdSymbol($holding)}?fmt=json";
    }

    private function intradayUrl(StockHolding $holding, Carbon $from, Carbon $until): string
    {
        return "{$this->baseUrl()}/intraday/{$this->eodhdSymbol($holding)}?from={$from->copy()->utc()->timestamp}&to={$until->copy()->utc()->timestamp}&interval=1m&fmt=json";
    }

    private function eodUrl(StockHolding $holding, Carbon $sessionDate): string
    {
        $date = $sessionDate->toDateString();

        return "{$this->baseUrl()}/eod/{$this->eodhdSymbol($holding)}?from={$date}&to={$date}&period=d&fmt=json";
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.eodhd.base_url', 'https://eodhd.com/api'), '/');
    }

    private function eodhdSymbol(StockHolding $holding): string
    {
        return Str::upper((string) $holding->symbol).'.'.$this->exchangeCode($holding);
    }

    private function exchangeCode(StockHolding $holding): string
    {
        $mic = Str::upper((string) $holding->mic_code);
        $exchange = Str::lower((string) $holding->exchange);

        if ($mic === 'XETR' || Str::contains($exchange, 'xetra')) {
            return 'XETRA';
        }

        if (in_array($mic, ['XNAS', 'XNYS', 'ARCX'], true) || in_array(Str::lower((string) $holding->country), ['united states', 'usa', 'us'], true)) {
            return 'US';
        }

        if ($mic !== '') {
            return $mic;
        }

        return Str::upper(Str::replace(' ', '', (string) $holding->exchange));
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

    private function timestamp(mixed $timestamp, mixed $datetime = null): ?Carbon
    {
        if (is_numeric($timestamp) && (int) $timestamp > 0) {
            return Carbon::createFromTimestampUTC((int) $timestamp);
        }

        if (! is_string($datetime) || trim($datetime) === '') {
            return null;
        }

        try {
            return Carbon::parse($datetime, 'UTC')->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function errorMessage(array $payload): string
    {
        $message = Arr::get($payload, 'message');

        return is_string($message) && trim($message) !== ''
            ? $message
            : 'EODHD returned an error response.';
    }

    private function selectedQuoteIsOlderThanHolding(ValidatedQuote $quote, StockHolding $holding): bool
    {
        if (! $this->holdingHasStoredPrice($holding)) {
            return false;
        }

        $holdingAsOf = $this->holdingLatestPriceAsOf($holding);

        if ($holdingAsOf === null || $quote->quote->asOf === null) {
            return false;
        }

        return $quote->quote->asOf->lt($holdingAsOf);
    }

    private function holdingLatestPriceAsOf(StockHolding $holding): ?Carbon
    {
        $stockPrice = $holding->latestStockPrice ?? $holding->latestStockPrice()->first();

        if ($stockPrice?->as_of !== null) {
            return $stockPrice->as_of;
        }

        if (! $holding->latest_price_as_of) {
            return null;
        }

        try {
            return Carbon::parse($holding->latest_price_as_of);
        } catch (Throwable) {
            return null;
        }
    }

    private function holdingHasStoredPrice(StockHolding $holding): bool
    {
        return $holding->latest_stock_price_id !== null || $holding->latest_price !== null;
    }
}
