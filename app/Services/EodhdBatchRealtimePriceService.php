<?php

namespace App\Services;

use App\Models\StockHolding;
use App\Models\StockRealtimePrice;
use App\Services\WebMarketData\DTO\InstrumentIdentity;
use App\Services\WebMarketData\DTO\ParsedQuote;
use App\Services\WebMarketData\WebQuoteValidator;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class EodhdBatchRealtimePriceService
{
    private const BatchSize = 20;

    private const SourceKey = 'eodhd_realtime';

    public function __construct(
        private EodhdApiClient $apiClient,
        private StockRealtimePriceCatalog $realtimePriceCatalog,
        private WebQuoteValidator $validator,
    ) {}

    /**
     * @return array{message: string, requested_count: int, stored_count: int, unchanged_count: int, updated_count: int, failed_count: int, errors: array<int, string>}
     */
    public function syncAll(): array
    {
        $requestedCount = 0;
        $storedCount = 0;
        $unchangedCount = 0;
        $updatedCount = 0;
        $failedCount = 0;
        $errors = [];

        StockHolding::query()
            ->whereNotNull('symbol')
            ->orderBy('name')
            ->orderBy('symbol')
            ->get()
            ->chunk(self::BatchSize)
            ->each(function (Collection $holdings) use (&$requestedCount, &$storedCount, &$unchangedCount, &$updatedCount, &$failedCount, &$errors): void {
                $requestedCount += $holdings->count();

                try {
                    $result = $this->syncBatch($holdings);
                    $storedCount += $result['stored_count'];
                    $unchangedCount += $result['unchanged_count'];
                    $updatedCount += $result['updated_count'];
                    $failedCount += $result['failed_count'];
                    $errors = [
                        ...$errors,
                        ...$result['errors'],
                    ];
                } catch (Throwable $exception) {
                    $failedCount += $holdings->count();
                    $errors[] = $exception->getMessage();
                }
            });

        return [
            'message' => "EODHD sync: {$storedCount} record(s) created, {$updatedCount} record(s) updated.",
            'requested_count' => $requestedCount,
            'stored_count' => $storedCount,
            'unchanged_count' => $unchangedCount,
            'updated_count' => $updatedCount,
            'failed_count' => $failedCount,
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /**
     * @param  Collection<int, StockHolding>  $holdings
     * @return array{stored_count: int, unchanged_count: int, updated_count: int, failed_count: int, errors: array<int, string>}
     */
    private function syncBatch(Collection $holdings): array
    {
        $symbols = $holdings
            ->mapWithKeys(fn (StockHolding $holding): array => [$holding->id => $this->eodhdSymbol($holding)])
            ->filter();

        if ($symbols->isEmpty()) {
            return [
                'stored_count' => 0,
                'unchanged_count' => 0,
                'updated_count' => 0,
                'failed_count' => $holdings->count(),
                'errors' => ['No EODHD symbols could be built for the selected holdings.'],
            ];
        }

        $primarySymbol = $symbols->first();
        $secondarySymbols = $symbols->skip(1)->values();
        $response = $this->apiClient->get("real-time/{$primarySymbol}", [
            'fmt' => 'json',
            ...($secondarySymbols->isNotEmpty() ? ['s' => $secondarySymbols->implode(',')] : []),
        ]);

        if ($response->failed()) {
            throw new RuntimeException("EODHD realtime request failed with HTTP {$response->status()}.");
        }

        $payloadsBySymbol = $this->payloadsBySymbol($response, $primarySymbol);
        $storedCount = 0;
        $unchangedCount = 0;
        $updatedCount = 0;
        $failedCount = 0;
        $errors = [];

        foreach ($holdings as $holding) {
            $symbol = $symbols->get($holding->id);
            $payload = is_string($symbol) ? $payloadsBySymbol->get($symbol) : null;

            if (! is_array($payload)) {
                $failedCount++;
                $errors[] = "No EODHD realtime payload returned for {$holding->symbol}.";

                continue;
            }

            $quote = $this->quoteFromPayload($holding, $payload, $symbol);

            if ($quote === null) {
                $failedCount++;
                $errors[] = "EODHD realtime payload for {$holding->symbol} did not include a usable price and timestamp.";

                continue;
            }

            $validatedQuote = $this->validator->validate($quote, InstrumentIdentity::fromHolding($holding));
            $storedPrice = $this->realtimePriceCatalog->store(
                $holding,
                $validatedQuote,
                $holding->trading_times,
            );

            if ($storedPrice->wasRecentlyCreated) {
                $storedCount++;
            } else {
                $unchangedCount++;
            }

            if ($validatedQuote->isSelectable()) {
                $updatedCount += $this->updateHolding($holding, $storedPrice, $validatedQuote->freshnessStatus);
            }
        }

        return [
            'stored_count' => $storedCount,
            'unchanged_count' => $unchangedCount,
            'updated_count' => $updatedCount,
            'failed_count' => $failedCount,
            'errors' => $errors,
        ];
    }

    /**
     * @return Collection<string, array<string, mixed>>
     */
    private function payloadsBySymbol(Response $response, string $primarySymbol): Collection
    {
        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('EODHD returned an invalid realtime response.');
        }

        if (($payload['status'] ?? null) === 'error') {
            throw new RuntimeException((string) ($payload['message'] ?? 'EODHD returned an error response.'));
        }

        $payloads = $this->payloadList($payload);

        return collect($payloads)
            ->filter(fn (mixed $item): bool => is_array($item))
            ->mapWithKeys(function (array $item) use ($primarySymbol): array {
                $symbol = $this->payloadSymbol($item) ?? $primarySymbol;

                return [Str::upper($symbol) => $item];
            });
    }

    /**
     * @param  array<string, mixed>|array<int, mixed>  $payload
     * @return array<int, mixed>
     */
    private function payloadList(array $payload): array
    {
        if (array_is_list($payload)) {
            return $payload;
        }

        if ($this->payloadSymbol($payload) !== null || Arr::has($payload, 'close')) {
            return [$payload];
        }

        return array_values($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function quoteFromPayload(StockHolding $holding, array $payload, string $symbol): ?ParsedQuote
    {
        $asOf = $this->timestamp(Arr::get($payload, 'timestamp'), Arr::get($payload, 'datetime'));
        $price = $this->decimal(Arr::get($payload, 'close'));

        if ($asOf === null || $price === null) {
            return null;
        }

        return new ParsedQuote(
            sourceKey: self::SourceKey,
            sourceName: 'EODHD real-time',
            sourceUrl: $this->sourceUrl($symbol),
            sourceQuality: 'market_data_vendor',
            venue: $holding->exchange,
            mic: $holding->mic_code,
            isin: $holding->isin,
            wkn: $holding->wkn,
            symbol: $holding->symbol,
            currency: $this->stringOrNull(Arr::get($payload, 'currency')) ?? $holding->currency,
            bid: $this->decimal(Arr::get($payload, 'bid')),
            ask: $this->decimal(Arr::get($payload, 'ask')),
            close: $price,
            price: $price,
            priceType: 'last',
            asOf: $asOf,
            fetchedAt: now(),
            freshnessStatus: 'fresh',
            rawTextHash: hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            rawPayload: $payload,
        );
    }

    private function updateHolding(StockHolding $holding, StockRealtimePrice $storedPrice, string $priceStatus): int
    {
        if ((int) $holding->latest_realtime_price_id === (int) $storedPrice->id && $holding->price_status === $priceStatus) {
            return 0;
        }

        $holding->update([
            'currency' => $storedPrice->currency ?? $holding->currency,
            'latest_realtime_price_id' => $storedPrice->id,
            'price_status' => $priceStatus,
            'source_verified_at' => now(),
            'trading_times' => $storedPrice->trading_times,
        ]);

        return 1;
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
     * @param  array<string, mixed>  $payload
     */
    private function payloadSymbol(array $payload): ?string
    {
        return $this->stringOrNull(Arr::get($payload, 'code'))
            ?? $this->stringOrNull(Arr::get($payload, 'symbol'));
    }

    private function sourceUrl(string $symbol): string
    {
        $baseUrl = rtrim((string) config('services.eodhd.base_url', 'https://eodhd.com/api'), '/');

        return "{$baseUrl}/real-time/{$symbol}?fmt=json";
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

    private function timestamp(mixed $timestamp, mixed $datetime = null): ?Carbon
    {
        if (is_numeric($timestamp) && (int) $timestamp > 0) {
            return Carbon::createFromTimestampUTC((int) $timestamp)
                ->setTimezone(config('app.timezone'));
        }

        if (is_string($datetime) && trim($datetime) !== '') {
            return Carbon::parse($datetime, 'UTC')
                ->setTimezone(config('app.timezone'));
        }

        return null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
