<?php

namespace App\Services;

use App\Models\StockHolding;
use App\Models\StockHoldingDailyPrice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class StockHistoricalDailyPriceFetcher
{
    public function __construct(
        private EodhdApiClient $apiClient,
        private EodhdMarketData $marketData,
    ) {}

    public function fetch(StockHolding $holding, Carbon $from, Carbon $to): int
    {
        $path = "eod/{$this->eodhdSymbol($holding)}";
        $response = $this->apiClient->get($path, [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'period' => 'd',
            'fmt' => 'json',
        ]);
        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('EODHD returned an invalid historical price response.');
        }

        if (($payload['status'] ?? null) === 'error') {
            throw new RuntimeException($this->errorMessage($payload));
        }

        $now = now();
        $rows = collect($payload)
            ->filter(fn (mixed $record): bool => is_array($record) && $this->date($record['date'] ?? null) !== null)
            ->map(fn (array $record): array => [
                'stock_holding_id' => $holding->id,
                'trading_date' => $this->date($record['date'] ?? null),
                'open' => $this->decimal($record['open'] ?? null),
                'high' => $this->decimal($record['high'] ?? null),
                'low' => $this->decimal($record['low'] ?? null),
                'close' => $this->decimal($record['close'] ?? null),
                'adjusted_close' => $this->decimal($record['adjusted_close'] ?? null),
                'volume' => $this->integer($record['volume'] ?? null),
                'currency' => $this->currency($holding->currency),
                'source_key' => 'eodhd_eod',
                'source_name' => 'EODHD EOD',
                'source_url' => $this->sourceUrl($holding, $from, $to),
                'raw_payload' => json_encode($record),
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values();

        if ($rows->isEmpty()) {
            return 0;
        }

        StockHoldingDailyPrice::query()->upsert(
            $rows->all(),
            uniqueBy: ['stock_holding_id', 'trading_date'],
            update: ['open', 'high', 'low', 'close', 'adjusted_close', 'volume', 'currency', 'source_key', 'source_name', 'source_url', 'raw_payload', 'updated_at'],
        );

        return $rows->count();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function errorMessage(array $payload): string
    {
        $message = $payload['message'] ?? null;

        return is_string($message) && trim($message) !== ''
            ? $message
            : 'EODHD returned an error response.';
    }

    private function eodhdSymbol(StockHolding $holding): string
    {
        return Str::upper((string) $holding->symbol).'.'.$this->marketData->exchangeCodeForHolding($holding);
    }

    private function sourceUrl(StockHolding $holding, Carbon $from, Carbon $to): string
    {
        $baseUrl = rtrim((string) config('services.eodhd.base_url', 'https://eodhd.com/api'), '/');

        return "{$baseUrl}/eod/{$this->eodhdSymbol($holding)}?from={$from->toDateString()}&to={$to->toDateString()}&period=d&fmt=json";
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function decimal(mixed $value): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 8, '.', '');
    }

    private function integer(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max(0, (int) $value);
    }

    private function currency(?string $currency): ?string
    {
        $currency = Str::upper(trim((string) $currency));

        return $currency !== '' ? Str::limit($currency, 3, '') : null;
    }
}
