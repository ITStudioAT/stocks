<?php

namespace App\Services;

use App\Models\AppConfig;
use App\Models\StockHolding;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StockTradingTimeHealthCheck
{
    private const ConfigKey = 'data_health.stock_trading_times';

    private const Timezone = 'Europe/Vienna';

    public function __construct(
        private EodhdMarketData $eodhdMarketData,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $storedStatus = AppConfig::query()
            ->where('key', self::ConfigKey)
            ->first()?->value;

        return is_array($storedStatus) ? $storedStatus : $this->emptyStatus();
    }

    /**
     * @return array<string, mixed>
     */
    public function run(): array
    {
        $result = $this->inspect();
        $this->persist($result);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function repair(): array
    {
        $beforeRepair = $this->inspect();
        $issues = collect($beforeRepair['issues']);
        $exchangeDetails = $this->freshExchangeDetails($issues);
        $repairedCount = 0;
        $failures = [];

        DB::transaction(function () use ($issues, $exchangeDetails, &$repairedCount, &$failures): void {
            $holdings = StockHolding::query()
                ->whereKey($issues->pluck('id')->all())
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($issues as $issue) {
                $holding = $holdings->get($issue['id']);
                $details = $exchangeDetails->get($issue['exchange_code']);
                $tradingTimes = $this->tradingTimesFromExchangeDetails($details);

                if (! $holding instanceof StockHolding || $tradingTimes === null) {
                    $failures[] = [
                        'id' => $issue['id'],
                        'label' => $issue['label'],
                        'message' => $this->repairFailureMessage($details),
                    ];

                    continue;
                }

                $holding->update(['trading_times' => $tradingTimes]);
                $repairedCount++;
            }
        });

        $result = $this->inspect();
        $result['repair'] = [
            'attempted_stocks_count' => $issues->count(),
            'repaired_stocks_count' => $repairedCount,
            'failed_stocks_count' => count($failures),
            'failures' => $failures,
        ];
        $this->persist($result);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function inspect(): array
    {
        $holdings = StockHolding::query()
            ->orderBy('symbol')
            ->orderBy('id')
            ->get(['id', 'symbol', 'name', 'exchange', 'mic_code', 'country', 'trading_times']);
        $issues = $holdings
            ->map(fn (StockHolding $holding): ?array => $this->issue($holding))
            ->filter()
            ->values();
        $missingCount = $issues->where('status', 'missing')->count();
        $brokenCount = $issues->where('status', 'broken')->count();
        $issueCount = $issues->count();

        return [
            'status' => $issueCount === 0 ? 'healthy' : 'issues',
            'last_executed_at' => now(self::Timezone)->toIso8601String(),
            'timezone' => self::Timezone,
            'summary' => [
                'total_stocks_count' => $holdings->count(),
                'healthy_stocks_count' => $holdings->count() - $issueCount,
                'missing_stocks_count' => $missingCount,
                'broken_stocks_count' => $brokenCount,
                'issue_stocks_count' => $issueCount,
            ],
            'issues' => $issues->all(),
        ];
    }

    /**
     * @return array{id: int, label: string, status: string, current_trading_times: ?string, exchange_code: string}|null
     */
    private function issue(StockHolding $holding): ?array
    {
        $tradingTimes = trim((string) $holding->trading_times);
        $status = $tradingTimes === ''
            ? 'missing'
            : ($this->hasValidTradingTimes($tradingTimes) ? null : 'broken');

        if ($status === null) {
            return null;
        }

        return [
            'id' => $holding->id,
            'label' => trim("{$holding->symbol} · {$holding->name}", ' ·'),
            'status' => $status,
            'current_trading_times' => $tradingTimes !== '' ? $tradingTimes : null,
            'exchange_code' => $this->eodhdMarketData->exchangeCodeForHolding($holding),
        ];
    }

    private function hasValidTradingTimes(string $tradingTimes): bool
    {
        if (! preg_match('/(?<![:\d])(?<open_hour>\d{1,2}):(?<open_minute>\d{2})(?::\d{2})?\s*(?:-|to|until|bis)\s*(?<close_hour>\d{1,2}):(?<close_minute>\d{2})(?::\d{2})?/i', $tradingTimes, $matches)) {
            return false;
        }

        $openHour = (int) $matches['open_hour'];
        $openMinute = (int) $matches['open_minute'];
        $closeHour = (int) $matches['close_hour'];
        $closeMinute = (int) $matches['close_minute'];

        if ($openHour > 23 || $closeHour > 23 || $openMinute > 59 || $closeMinute > 59) {
            return false;
        }

        if (($openHour * 60) + $openMinute >= ($closeHour * 60) + $closeMinute) {
            return false;
        }

        if (! preg_match('/(?<timezone>(?:[A-Za-z0-9_+\-]+\/)+[A-Za-z0-9_+\-]+|UTC)\s*$/', $tradingTimes, $timezoneMatches)) {
            return false;
        }

        return $timezoneMatches['timezone'] === 'UTC'
            || in_array($timezoneMatches['timezone'], timezone_identifiers_list(), true);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $issues
     * @return Collection<string, array<string, mixed>>
     */
    private function freshExchangeDetails(Collection $issues): Collection
    {
        return $issues
            ->pluck('exchange_code')
            ->filter()
            ->unique()
            ->mapWithKeys(fn (string $exchangeCode): array => [
                $exchangeCode => $this->eodhdMarketData->freshExchangeDetailsForCode($exchangeCode),
            ]);
    }

    private function tradingTimesFromExchangeDetails(mixed $details): ?string
    {
        if (! is_array($details) || ($details['error'] ?? null) !== null) {
            return null;
        }

        $open = trim((string) ($details['open'] ?? ''));
        $close = trim((string) ($details['close'] ?? ''));
        $timezone = trim((string) ($details['timezone'] ?? ''));
        $tradingTimes = "Monday-Friday {$open}-{$close} {$timezone}";

        return $this->hasValidTradingTimes($tradingTimes) ? $tradingTimes : null;
    }

    private function repairFailureMessage(mixed $details): string
    {
        if (is_array($details) && is_string($details['error'] ?? null) && trim($details['error']) !== '') {
            return $details['error'];
        }

        return 'EODHD did not return valid exchange trading hours.';
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function persist(array $result): void
    {
        AppConfig::query()->updateOrCreate(
            ['key' => self::ConfigKey],
            ['value' => $result],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyStatus(): array
    {
        return [
            'status' => 'never',
            'last_executed_at' => null,
            'timezone' => self::Timezone,
            'summary' => [
                'total_stocks_count' => 0,
                'healthy_stocks_count' => 0,
                'missing_stocks_count' => 0,
                'broken_stocks_count' => 0,
                'issue_stocks_count' => 0,
            ],
            'issues' => [],
        ];
    }
}
