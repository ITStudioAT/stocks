<?php

namespace App\Services;

use App\Models\AppConfig;
use App\Models\User;

class UiPreferences
{
    /**
     * @var array<int, string>
     */
    public const DepotPriceSources = ['latest', 'flatex'];

    /**
     * @var array<int, string>
     */
    public const AnalyzeTrendSignalColumns = [
        'vbuy_vsell',
        'vbuy_vsell_max_invest',
        'vbuy_once',
        'vbuy_once_emergency',
    ];

    private const ConfigKey = 'ui.preferences';

    private const UserConfigKeyPrefix = 'ui.preferences.user.';

    private const DefaultAnalyzeTrendRowLimit = 200;

    public const MaxAnalyzeTrendRowLimit = 1000;

    private const DefaultAnalyzeTrendMaxInvestAmount = 0;

    private const MaxAnalyzeTrendMaxInvestAmount = 1000000;

    private const DefaultAnalyzeTrendVirtualBuyAmount = 7000;

    private const MaxAnalyzeTrendVirtualBuyAmount = 1000000;

    private const DefaultAnalyzeTrendStreakBuyThresholds = [-4, -3, -2, -1, 0];

    private const MaxAnalyzeTrendStreakBuyThresholdCount = 20;

    private const DefaultAnalyzeTrendStreakSellThreshold = 3;

    /**
     * @return array{
     *     depot_price_source: string,
     *     analyze_trend_row_limit: int,
     *     analyze_trend_max_invest_amount: int,
     *     analyze_trend_virtual_buy_amount: int,
     *     analyze_trend_streak_buy_thresholds: array<int, float|int|null>,
     *     analyze_trend_streak_sell_threshold: float|int,
     *     analyze_trend_signal_columns: array<int, string>,
     * }
     */
    public function settings(?User $user = null): array
    {
        $config = AppConfig::query()->firstOrCreate(
            ['key' => self::ConfigKey],
            ['value' => $this->defaultGlobalSettings()],
        );
        $settings = $this->normalizeGlobalSettings($config->value);

        if ($settings !== $config->value) {
            $config->update(['value' => $settings]);
        }

        return [
            ...$settings,
            'analyze_trend_row_limit' => $this->userAnalyzeTrendRowLimit($user),
            'analyze_trend_max_invest_amount' => $this->userAnalyzeTrendMaxInvestAmount($user),
            'analyze_trend_virtual_buy_amount' => $this->userAnalyzeTrendVirtualBuyAmount($user),
            'analyze_trend_streak_buy_thresholds' => $this->userAnalyzeTrendStreakBuyThresholds($user),
            'analyze_trend_streak_sell_threshold' => $this->userAnalyzeTrendStreakSellThreshold($user),
            'analyze_trend_signal_columns' => $this->userAnalyzeTrendSignalColumns($user),
        ];
    }

    /**
     * @return array{
     *     depot_price_source: string,
     *     analyze_trend_row_limit: int,
     *     analyze_trend_max_invest_amount: int,
     *     analyze_trend_virtual_buy_amount: int,
     *     analyze_trend_streak_buy_thresholds: array<int, float|int|null>,
     *     analyze_trend_streak_sell_threshold: float|int,
     *     analyze_trend_signal_columns: array<int, string>,
     * }
     */
    public function payload(?User $user = null): array
    {
        return $this->settings($user);
    }

    /**
     * @return array{
     *     depot_price_source: string,
     *     analyze_trend_row_limit: int,
     *     analyze_trend_max_invest_amount: int,
     *     analyze_trend_virtual_buy_amount: int,
     *     analyze_trend_streak_buy_thresholds: array<int, float|int|null>,
     *     analyze_trend_streak_sell_threshold: float|int,
     *     analyze_trend_signal_columns: array<int, string>,
     * }
     */
    public function updateDepotPriceSource(string $depotPriceSource, ?User $user = null): array
    {
        $settings = [
            ...$this->globalSettings(),
            'depot_price_source' => $this->normalizeDepotPriceSource($depotPriceSource),
        ];

        AppConfig::query()->updateOrCreate(
            ['key' => self::ConfigKey],
            ['value' => $settings],
        );

        return $this->payload($user);
    }

    /**
     * @return array{depot_price_source: string}
     */
    private function defaultGlobalSettings(): array
    {
        return [
            'depot_price_source' => 'latest',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $value
     * @return array{depot_price_source: string}
     */
    private function normalizeGlobalSettings(?array $value): array
    {
        $settings = [
            ...$this->defaultGlobalSettings(),
            ...($value ?? []),
        ];

        return [
            'depot_price_source' => $this->normalizeDepotPriceSource($settings['depot_price_source'] ?? null),
        ];
    }

    /**
     * @return array{depot_price_source: string}
     */
    private function globalSettings(): array
    {
        $config = AppConfig::query()->firstOrCreate(
            ['key' => self::ConfigKey],
            ['value' => $this->defaultGlobalSettings()],
        );

        return $this->normalizeGlobalSettings($config->value);
    }

    /**
     * @return array{
     *     depot_price_source: string,
     *     analyze_trend_row_limit: int,
     *     analyze_trend_max_invest_amount: int,
     *     analyze_trend_virtual_buy_amount: int,
     *     analyze_trend_streak_buy_thresholds: array<int, float|int|null>,
     *     analyze_trend_streak_sell_threshold: float|int,
     *     analyze_trend_signal_columns: array<int, string>,
     * }
     */
    public function updateAnalyzeTrendRowLimit(User $user, int $rowLimit): array
    {
        $settings = $this->userSettings($user);
        $settings['analyze_trend_row_limit'] = $this->normalizeAnalyzeTrendRowLimit($rowLimit);

        AppConfig::query()->updateOrCreate(
            ['key' => $this->userConfigKey($user)],
            ['value' => $settings],
        );

        return $this->payload($user);
    }

    /**
     * @return array{
     *     depot_price_source: string,
     *     analyze_trend_row_limit: int,
     *     analyze_trend_max_invest_amount: int,
     *     analyze_trend_virtual_buy_amount: int,
     *     analyze_trend_streak_buy_thresholds: array<int, float|int|null>,
     *     analyze_trend_streak_sell_threshold: float|int,
     *     analyze_trend_signal_columns: array<int, string>,
     * }
     */
    public function updateAnalyzeTrendInvestmentSettings(
        User $user,
        ?int $maxInvestAmount,
        ?int $virtualBuyAmount,
    ): array {
        $settings = $this->userSettings($user);

        if ($maxInvestAmount !== null) {
            $settings['analyze_trend_max_invest_amount'] = $this->normalizeAnalyzeTrendMaxInvestAmount($maxInvestAmount);
        }

        if ($virtualBuyAmount !== null) {
            $settings['analyze_trend_virtual_buy_amount'] = $this->normalizeAnalyzeTrendVirtualBuyAmount(
                $virtualBuyAmount,
            );
        }

        AppConfig::query()->updateOrCreate(
            ['key' => $this->userConfigKey($user)],
            ['value' => $settings],
        );

        return $this->payload($user);
    }

    /**
     * @param  array<int, mixed>|null  $buyThresholds
     * @return array{
     *     depot_price_source: string,
     *     analyze_trend_row_limit: int,
     *     analyze_trend_max_invest_amount: int,
     *     analyze_trend_virtual_buy_amount: int,
     *     analyze_trend_streak_buy_thresholds: array<int, float|int|null>,
     *     analyze_trend_streak_sell_threshold: float|int,
     *     analyze_trend_signal_columns: array<int, string>,
     * }
     */
    public function updateAnalyzeTrendStreakSettings(
        User $user,
        ?array $buyThresholds,
        float|int|null $sellThreshold,
    ): array {
        $settings = $this->userSettings($user);

        if ($buyThresholds !== null) {
            $settings['analyze_trend_streak_buy_thresholds'] = $this->normalizeAnalyzeTrendStreakBuyThresholds(
                $buyThresholds,
            );
        }

        if ($sellThreshold !== null) {
            $settings['analyze_trend_streak_sell_threshold'] = $this->normalizeAnalyzeTrendStreakSellThreshold(
                $sellThreshold,
            );
        }

        AppConfig::query()->updateOrCreate(
            ['key' => $this->userConfigKey($user)],
            ['value' => $settings],
        );

        return $this->payload($user);
    }

    /**
     * @param  array<int, string>  $signalColumns
     * @return array{
     *     depot_price_source: string,
     *     analyze_trend_row_limit: int,
     *     analyze_trend_max_invest_amount: int,
     *     analyze_trend_virtual_buy_amount: int,
     *     analyze_trend_streak_buy_thresholds: array<int, float|int|null>,
     *     analyze_trend_streak_sell_threshold: float|int,
     *     analyze_trend_signal_columns: array<int, string>,
     * }
     */
    public function updateAnalyzeTrendSignalColumns(User $user, array $signalColumns): array
    {
        $settings = $this->userSettings($user);
        $settings['analyze_trend_signal_columns'] = $this->normalizeAnalyzeTrendSignalColumns($signalColumns);

        AppConfig::query()->updateOrCreate(
            ['key' => $this->userConfigKey($user)],
            ['value' => $settings],
        );

        return $this->payload($user);
    }

    /**
     * @return array{
     *     analyze_trend_row_limit: int,
     *     analyze_trend_max_invest_amount: int,
     *     analyze_trend_virtual_buy_amount: int,
     *     analyze_trend_streak_buy_thresholds: array<int, float|int|null>,
     *     analyze_trend_streak_sell_threshold: float|int,
     *     analyze_trend_signal_columns: array<int, string>,
     * }
     */
    private function userSettings(User $user): array
    {
        $config = AppConfig::query()->firstOrCreate(
            ['key' => $this->userConfigKey($user)],
            ['value' => $this->defaultUserSettings()],
        );
        $settings = $this->normalizeUserSettings($config->value);

        if ($settings !== $config->value) {
            $config->update(['value' => $settings]);
        }

        return $settings;
    }

    private function userAnalyzeTrendRowLimit(?User $user): int
    {
        if (! $user) {
            return self::DefaultAnalyzeTrendRowLimit;
        }

        return $this->userSettings($user)['analyze_trend_row_limit'];
    }

    private function userAnalyzeTrendMaxInvestAmount(?User $user): int
    {
        if (! $user) {
            return self::DefaultAnalyzeTrendMaxInvestAmount;
        }

        return $this->userSettings($user)['analyze_trend_max_invest_amount'];
    }

    private function userAnalyzeTrendVirtualBuyAmount(?User $user): int
    {
        if (! $user) {
            return self::DefaultAnalyzeTrendVirtualBuyAmount;
        }

        return $this->userSettings($user)['analyze_trend_virtual_buy_amount'];
    }

    /**
     * @return array<int, float|int|null>
     */
    private function userAnalyzeTrendStreakBuyThresholds(?User $user): array
    {
        if (! $user) {
            return self::DefaultAnalyzeTrendStreakBuyThresholds;
        }

        return $this->userSettings($user)['analyze_trend_streak_buy_thresholds'];
    }

    private function userAnalyzeTrendStreakSellThreshold(?User $user): float|int
    {
        if (! $user) {
            return self::DefaultAnalyzeTrendStreakSellThreshold;
        }

        return $this->userSettings($user)['analyze_trend_streak_sell_threshold'];
    }

    /**
     * @return array<int, string>
     */
    private function userAnalyzeTrendSignalColumns(?User $user): array
    {
        if (! $user) {
            return self::AnalyzeTrendSignalColumns;
        }

        return $this->userSettings($user)['analyze_trend_signal_columns'];
    }

    /**
     * @return array{
     *     analyze_trend_row_limit: int,
     *     analyze_trend_max_invest_amount: int,
     *     analyze_trend_virtual_buy_amount: int,
     *     analyze_trend_streak_buy_thresholds: array<int, float|int|null>,
     *     analyze_trend_streak_sell_threshold: float|int,
     *     analyze_trend_signal_columns: array<int, string>,
     * }
     */
    private function defaultUserSettings(): array
    {
        return [
            'analyze_trend_row_limit' => self::DefaultAnalyzeTrendRowLimit,
            'analyze_trend_max_invest_amount' => self::DefaultAnalyzeTrendMaxInvestAmount,
            'analyze_trend_virtual_buy_amount' => self::DefaultAnalyzeTrendVirtualBuyAmount,
            'analyze_trend_streak_buy_thresholds' => self::DefaultAnalyzeTrendStreakBuyThresholds,
            'analyze_trend_streak_sell_threshold' => self::DefaultAnalyzeTrendStreakSellThreshold,
            'analyze_trend_signal_columns' => self::AnalyzeTrendSignalColumns,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $value
     * @return array{
     *     analyze_trend_row_limit: int,
     *     analyze_trend_max_invest_amount: int,
     *     analyze_trend_virtual_buy_amount: int,
     *     analyze_trend_streak_buy_thresholds: array<int, float|int|null>,
     *     analyze_trend_streak_sell_threshold: float|int,
     *     analyze_trend_signal_columns: array<int, string>,
     * }
     */
    private function normalizeUserSettings(?array $value): array
    {
        return [
            'analyze_trend_row_limit' => $this->normalizeAnalyzeTrendRowLimit($value['analyze_trend_row_limit'] ?? null),
            'analyze_trend_max_invest_amount' => $this->normalizeAnalyzeTrendMaxInvestAmount(
                $value['analyze_trend_max_invest_amount'] ?? null,
            ),
            'analyze_trend_virtual_buy_amount' => $this->normalizeAnalyzeTrendVirtualBuyAmount(
                $value['analyze_trend_virtual_buy_amount'] ?? null,
            ),
            'analyze_trend_streak_buy_thresholds' => $this->normalizeAnalyzeTrendStreakBuyThresholds(
                $value['analyze_trend_streak_buy_thresholds'] ?? null,
            ),
            'analyze_trend_streak_sell_threshold' => $this->normalizeAnalyzeTrendStreakSellThreshold(
                $value['analyze_trend_streak_sell_threshold'] ?? null,
            ),
            'analyze_trend_signal_columns' => $this->normalizeAnalyzeTrendSignalColumns(
                array_key_exists('analyze_trend_signal_columns', $value ?? [])
                    ? $value['analyze_trend_signal_columns']
                    : null,
            ),
        ];
    }

    private function normalizeAnalyzeTrendRowLimit(mixed $rowLimit): int
    {
        if (! is_numeric($rowLimit)) {
            return self::DefaultAnalyzeTrendRowLimit;
        }

        return min(max((int) $rowLimit, 1), self::MaxAnalyzeTrendRowLimit);
    }

    private function normalizeAnalyzeTrendMaxInvestAmount(mixed $maxInvestAmount): int
    {
        if (! is_numeric($maxInvestAmount)) {
            return self::DefaultAnalyzeTrendMaxInvestAmount;
        }

        return min(max((int) $maxInvestAmount, 0), self::MaxAnalyzeTrendMaxInvestAmount);
    }

    private function normalizeAnalyzeTrendVirtualBuyAmount(mixed $virtualBuyAmount): int
    {
        if (! is_numeric($virtualBuyAmount)) {
            return self::DefaultAnalyzeTrendVirtualBuyAmount;
        }

        return min(max((int) $virtualBuyAmount, 0), self::MaxAnalyzeTrendVirtualBuyAmount);
    }

    /**
     * @return array<int, float|int|null>
     */
    private function normalizeAnalyzeTrendStreakBuyThresholds(mixed $buyThresholds): array
    {
        if (
            ! is_array($buyThresholds)
            || $buyThresholds === []
            || count($buyThresholds) > self::MaxAnalyzeTrendStreakBuyThresholdCount
        ) {
            return self::DefaultAnalyzeTrendStreakBuyThresholds;
        }

        return collect($buyThresholds)
            ->map(fn (mixed $buyThreshold): float|int|null => $buyThreshold === null
                ? null
                : $this->normalizeAnalyzeTrendStreakPercentage($buyThreshold, -100, 0))
            ->values()
            ->all();
    }

    private function normalizeAnalyzeTrendStreakSellThreshold(mixed $sellThreshold): float|int
    {
        if (! is_numeric($sellThreshold)) {
            return self::DefaultAnalyzeTrendStreakSellThreshold;
        }

        return $this->normalizeAnalyzeTrendStreakPercentage($sellThreshold, 0, 100);
    }

    /**
     * @return array<int, string>
     */
    private function normalizeAnalyzeTrendSignalColumns(mixed $signalColumns): array
    {
        if (! is_array($signalColumns)) {
            return self::AnalyzeTrendSignalColumns;
        }

        return collect(self::AnalyzeTrendSignalColumns)
            ->filter(fn (string $signalColumn): bool => in_array($signalColumn, $signalColumns, true))
            ->values()
            ->all();
    }

    private function normalizeAnalyzeTrendStreakPercentage(mixed $percentage, int $minimum, int $maximum): float|int
    {
        $normalizedPercentage = min(max((float) $percentage, $minimum), $maximum);

        return floor($normalizedPercentage) === $normalizedPercentage
            ? (int) $normalizedPercentage
            : $normalizedPercentage;
    }

    private function userConfigKey(User $user): string
    {
        return self::UserConfigKeyPrefix.$user->id;
    }

    private function normalizeDepotPriceSource(mixed $depotPriceSource): string
    {
        if (is_string($depotPriceSource) && in_array($depotPriceSource, self::DepotPriceSources, true)) {
            return $depotPriceSource;
        }

        return 'latest';
    }
}
