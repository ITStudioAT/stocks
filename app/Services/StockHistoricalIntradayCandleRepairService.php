<?php

namespace App\Services;

use App\Models\StockHolding;
use App\Models\StockHoldingIntradayCandle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

class StockHistoricalIntradayCandleRepairService
{
    private const Interval = '5m';

    private const SourceKey = 'eodhd_intraday';

    public function __construct(
        private EodhdApiClient $apiClient,
        private EodhdMarketData $marketData,
        private CompletedTradingDay $completedTradingDay,
        private StockHistoricalDataRepairService $summaryService,
    ) {}

    /**
     * @return array{
     *     message: string,
     *     repaired_stocks_count: int,
     *     stored_candles_count: int,
     *     repair: array{historical_data: array<string, mixed>},
     * }
     */
    public function repair(): array
    {
        $dateFrom = now()->subYear()->startOfDay();
        $dateTo = $this->completedTradingDay->date()->endOfDay();
        $holdings = $this->missingHoldings($dateFrom->toDateString(), $dateTo->toDateString());
        $storedCount = 0;

        foreach ($holdings as $holding) {
            $storedCount += $this->repairHolding($holding)['stored_candles_count'];
        }

        return [
            'message' => trans_choice('{0} No missing stocks found.|{1} 1 stock repaired.|[2,*] :count stocks repaired.', $holdings->count()),
            'repaired_stocks_count' => $holdings->count(),
            'stored_candles_count' => $storedCount,
            'repair' => [
                'historical_data' => $this->summaryService->summary(),
            ],
        ];
    }

    /**
     * @return array{stock: array{id: int, label: string}, stored_candles_count: int}
     */
    public function repairHolding(StockHolding $holding): array
    {
        $dateFrom = now()->subYear()->startOfDay();
        $dateTo = $this->completedTradingDay->date()->endOfDay();
        $storedCount = 0;

        if ($this->isCovered($holding, $dateFrom->toDateString(), $dateTo->toDateString())) {
            return [
                'stock' => [
                    'id' => $holding->id,
                    'label' => collect([$holding->symbol, $holding->name, $holding->subtitle])->filter()->implode(' - '),
                ],
                'stored_candles_count' => 0,
            ];
        }

        foreach ($this->missingDateRanges($holding, $dateFrom, $dateTo) as $range) {
            $storedCount += $this->storeIntradayCandlesFromEodhd($holding, $range['from'], $range['to']);
        }

        return [
            'stock' => [
                'id' => $holding->id,
                'label' => collect([$holding->symbol, $holding->name, $holding->subtitle])->filter()->implode(' - '),
            ],
            'stored_candles_count' => $storedCount,
        ];
    }

    /**
     * @return Collection<int, StockHolding>
     */
    private function missingHoldings(string $minimumDate, string $lastTradingDay): Collection
    {
        $coveredHoldingIds = StockHoldingIntradayCandle::query()
            ->where('interval', self::Interval)
            ->where('source_key', self::SourceKey)
            ->selectRaw('stock_holding_id, MIN(trading_date) as first_date, MAX(trading_date) as last_date')
            ->groupBy('stock_holding_id')
            ->havingRaw('DATE(MIN(trading_date)) <= ?', [$minimumDate])
            ->havingRaw('DATE(MAX(trading_date)) >= ?', [$lastTradingDay])
            ->pluck('stock_holding_id')
            ->flip();

        return StockHolding::query()
            ->orderBy('id')
            ->get(['id', 'name', 'subtitle', 'symbol', 'exchange', 'mic_code', 'currency'])
            ->filter(fn (StockHolding $holding): bool => ! $coveredHoldingIds->has($holding->id))
            ->values();
    }

    private function isCovered(StockHolding $holding, string $minimumDate, string $lastTradingDay): bool
    {
        return StockHoldingIntradayCandle::query()
            ->where('stock_holding_id', $holding->id)
            ->where('interval', self::Interval)
            ->where('source_key', self::SourceKey)
            ->selectRaw('MIN(trading_date) as first_date, MAX(trading_date) as last_date')
            ->havingRaw('DATE(MIN(trading_date)) <= ?', [$minimumDate])
            ->havingRaw('DATE(MAX(trading_date)) >= ?', [$lastTradingDay])
            ->exists();
    }

    private function storeIntradayCandlesFromEodhd(StockHolding $holding, Carbon $dateFrom, Carbon $dateTo): int
    {
        $symbol = Str::upper((string) $holding->symbol);

        if ($symbol === '') {
            return 0;
        }

        $exchangeCode = $this->marketData->exchangeCodeForHolding($holding);

        if ($exchangeCode === '') {
            throw new RuntimeException("Missing EODHD exchange code for {$symbol}.");
        }

        $response = $this->apiClient->get("intraday/{$symbol}.{$exchangeCode}", [
            'from' => $dateFrom->copy()->utc()->timestamp,
            'to' => $dateTo->copy()->utc()->timestamp,
            'interval' => self::Interval,
            'fmt' => 'json',
        ]);
        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException("EODHD returned an invalid intraday response for {$symbol}.");
        }

        if (($payload['status'] ?? null) === 'error') {
            throw new RuntimeException((string) ($payload['message'] ?? "EODHD returned an error response for {$symbol}."));
        }

        $now = now();
        $sourceUrl = $this->sourceUrl($symbol, $exchangeCode, $dateFrom, $dateTo);
        $rows = collect($payload)
            ->map(fn (mixed $record): ?array => is_array($record)
                ? $this->candleRow($holding, $record, $sourceUrl, $now)
                : null)
            ->filter()
            ->values();

        if ($rows->isEmpty()) {
            return 0;
        }

        $storedCount = 0;

        $rows->chunk(500)->each(function (Collection $chunk) use (&$storedCount): void {
            $storedCount += StockHoldingIntradayCandle::query()->insertOrIgnore($chunk->all());
        });

        return $storedCount;
    }

    /**
     * @return array<int, array{from: Carbon, to: Carbon}>
     */
    private function missingDateRanges(StockHolding $holding, Carbon $dateFrom, Carbon $dateTo): array
    {
        $storedDates = StockHoldingIntradayCandle::query()
            ->where('stock_holding_id', $holding->id)
            ->where('interval', self::Interval)
            ->where('source_key', self::SourceKey)
            ->whereDate('trading_date', '>=', $dateFrom->toDateString())
            ->whereDate('trading_date', '<=', $dateTo->toDateString())
            ->pluck('trading_date')
            ->map(fn (mixed $date): string => Carbon::parse($date)->toDateString())
            ->flip();
        $ranges = [];
        $rangeStart = null;
        $previousMissingDate = null;
        $date = $dateFrom->copy()->startOfDay();

        while ($date->lte($dateTo)) {
            if (! $date->isWeekday()) {
                $date->addDay();

                continue;
            }

            if ($storedDates->has($date->toDateString())) {
                if ($rangeStart !== null && $previousMissingDate !== null) {
                    $ranges[] = [
                        'from' => $rangeStart->copy()->startOfDay(),
                        'to' => $previousMissingDate->copy()->endOfDay(),
                    ];
                }

                $rangeStart = null;
                $previousMissingDate = null;
                $date->addDay();

                continue;
            }

            $rangeStart ??= $date->copy();
            $previousMissingDate = $date->copy();
            $date->addDay();
        }

        if ($rangeStart !== null && $previousMissingDate !== null) {
            $ranges[] = [
                'from' => $rangeStart->copy()->startOfDay(),
                'to' => $previousMissingDate->copy()->endOfDay(),
            ];
        }

        return $ranges;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>|null
     */
    private function candleRow(StockHolding $holding, array $record, string $sourceUrl, Carbon $now): ?array
    {
        $asOf = $this->asOf($record);

        if ($asOf === null) {
            return null;
        }

        $prices = [
            'open' => $this->decimal($record['open'] ?? null),
            'high' => $this->decimal($record['high'] ?? null),
            'low' => $this->decimal($record['low'] ?? null),
            'close' => $this->decimal($record['close'] ?? null),
        ];

        if (! collect($prices)->contains(fn (?string $price): bool => $price !== null)) {
            return null;
        }

        return [
            'stock_holding_id' => $holding->id,
            'trading_date' => $asOf->copy()->setTimezone('Europe/Vienna')->toDateString(),
            'interval' => self::Interval,
            'as_of' => $asOf,
            'timestamp' => $this->integer($record['timestamp'] ?? null),
            'gmtoffset' => $this->integer($record['gmtoffset'] ?? null),
            'datetime' => $this->text($record['datetime'] ?? null),
            ...$prices,
            'volume' => $this->positiveInteger($record['volume'] ?? null),
            'currency' => $this->currency($holding->currency),
            'source_key' => self::SourceKey,
            'source_name' => 'EODHD intraday',
            'source_url' => $sourceUrl,
            'raw_payload' => json_encode($record, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function asOf(array $record): ?Carbon
    {
        if (is_numeric($record['timestamp'] ?? null)) {
            return Carbon::createFromTimestampUTC((int) $record['timestamp']);
        }

        if (is_string($record['datetime'] ?? null) && trim($record['datetime']) !== '') {
            return Carbon::parse($record['datetime'], 'UTC');
        }

        return null;
    }

    private function sourceUrl(string $symbol, string $exchangeCode, Carbon $dateFrom, Carbon $dateTo): string
    {
        $baseUrl = rtrim((string) config('services.eodhd.base_url', 'https://eodhd.com/api'), '/');

        return "{$baseUrl}/intraday/{$symbol}.{$exchangeCode}?from={$dateFrom->copy()->utc()->timestamp}&to={$dateTo->copy()->utc()->timestamp}&interval=".self::Interval.'&fmt=json';
    }

    private function decimal(mixed $value): ?string
    {
        return is_numeric($value)
            ? number_format((float) $value, 8, '.', '')
            : null;
    }

    private function integer(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function positiveInteger(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, (int) $value) : null;
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function currency(?string $currency): ?string
    {
        $currency = Str::upper(trim((string) $currency));

        return $currency !== '' ? Str::limit($currency, 3, '') : null;
    }
}
