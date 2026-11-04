<?php

namespace App\Services;

use App\Models\StockHolding;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class EodhdIntradayFallback
{
    public function __construct(private EodhdApiClient $apiClient, private EodhdBatchRealtimePriceService $realtime, private EodhdErrorSanitizer $sanitizer) {}

    /** @return array<int, array<string, mixed>> */
    public function records(StockHolding $holding, Carbon $from, Carbon $until): array
    {
        $symbol = $this->realtime->eodhdSymbol($holding);
        if (blank($holding->symbol) || blank($holding->currency) || blank($holding->exchange)) {
            throw new RuntimeException('EODHD fallback requires verified symbol, exchange and currency.');
        }
        $query = ['fmt' => 'json', 'interval' => '5m', 'from' => intdiv($from->timestamp, 300) * 300, 'to' => intdiv($until->timestamp, 300) * 300];
        $key = 'market-data.eodhd-intraday.'.hash('sha256', json_encode([$symbol, $holding->currency, $query]));

        $failureKey = 'market-data.eodhd-intraday.failure.'.hash('sha256', $symbol.'|'.$from->toDateString().'|'.$until->toDateString());
        if (Cache::has($failureKey)) {
            throw new RuntimeException('EODHD intraday is temporarily unavailable; existing data preserved.');
        }
        try {
            $records = Cache::lock($key.'.lock', 45)->block(1, fn (): array => Cache::remember($key, now()->addMinutes(5), function () use ($holding, $symbol, $query): array {
                $response = $this->apiClient->get('intraday/'.$symbol, $query);
                $payload = $this->sanitizer->payload($response->json());
                if ($response->failed() || ! is_array($payload) || ! array_is_list($payload)) {
                    throw new RuntimeException('EODHD intraday fallback failed; existing data preserved.');
                }
                $records = [];
                foreach ($payload as $row) {
                    if (! is_array($row) || ! is_numeric($row['timestamp'] ?? null)) {
                        continue;
                    }
                    $timestamp = (int) $row['timestamp'];
                    if ($timestamp < $query['from'] || $timestamp > $query['to'] || $timestamp > now()->addMinutes(5)->timestamp
                        || (isset($row['currency']) && strtoupper($row['currency']) !== strtoupper($holding->currency))) {
                        continue;
                    }
                    $prices = [];
                    foreach (['open', 'high', 'low', 'close'] as $field) {
                        $value = $row[$field] ?? null;
                        if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value <= 0) {
                            continue 2;
                        }
                        $prices[$field] = number_format((float) $value, 8, '.', '');
                    }
                    if ((float) $prices['low'] > min((float) $prices['open'], (float) $prices['close'])
                        || (float) $prices['high'] < max((float) $prices['open'], (float) $prices['close'])) {
                        continue;
                    }
                    $records[] = [...$prices, 'timestamp' => $timestamp, 'datetime' => Carbon::createFromTimestampUTC($timestamp)->toDateTimeString(),
                        'interval' => '5m', 'currency' => $holding->currency, 'mic' => $holding->mic_code, 'venue' => $holding->exchange,
                        'volume' => is_numeric($row['volume'] ?? null) ? max(0, (int) $row['volume']) : null,
                        'source_key' => 'eodhd_intraday', 'source_name' => 'EODHD intraday',
                        'source_url' => rtrim(config('services.eodhd.base_url'), '/').'/intraday/'.$symbol.'?interval=5m',
                        'source_markers' => ['EODHD']];
                }
                if ($records === []) {
                    throw new RuntimeException('EODHD returned no usable intraday observations; existing data preserved.');
                }

                return $records;
            }));
        } catch (Throwable $exception) {
            if (! ($exception instanceof LockTimeoutException)) {
                Cache::put($failureKey, true, now()->addMinutes(2));
            }
            throw $exception;
        }

        return array_values(array_filter($records, fn (array $row): bool => $row['timestamp'] >= $from->timestamp && $row['timestamp'] <= $until->timestamp));
    }
}
