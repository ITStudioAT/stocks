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

    private const ConfigKey = 'ui.preferences';

    private const UserConfigKeyPrefix = 'ui.preferences.user.';

    private const DefaultAnalyzeTrendRowLimit = 200;

    private const MaxAnalyzeTrendRowLimit = 2000;

    private const DefaultAnalyzeTrendMaxInvestAmount = 0;

    private const MaxAnalyzeTrendMaxInvestAmount = 1000000;

    /**
     * @return array{
     *     depot_price_source: string,
     *     analyze_trend_row_limit: int,
     *     analyze_trend_excluded_holding_ids: array<int, int>,
     *     analyze_trend_trade_amounts: array<int, int>,
     *     analyze_trend_max_invest_amount: int,
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
            'analyze_trend_excluded_holding_ids' => $this->userAnalyzeTrendExcludedHoldingIds($user),
            'analyze_trend_trade_amounts' => $this->userAnalyzeTrendTradeAmounts($user),
            'analyze_trend_max_invest_amount' => $this->userAnalyzeTrendMaxInvestAmount($user),
        ];
    }

    /**
     * @return array{
     *     depot_price_source: string,
     *     analyze_trend_row_limit: int,
     *     analyze_trend_excluded_holding_ids: array<int, int>,
     *     analyze_trend_trade_amounts: array<int, int>,
     *     analyze_trend_max_invest_amount: int,
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
     *     analyze_trend_excluded_holding_ids: array<int, int>,
     *     analyze_trend_trade_amounts: array<int, int>,
     *     analyze_trend_max_invest_amount: int,
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
     *     analyze_trend_excluded_holding_ids: array<int, int>,
     *     analyze_trend_trade_amounts: array<int, int>,
     *     analyze_trend_max_invest_amount: int,
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
     * @param  array<int, mixed>  $holdingIds
     * @return array{
     *     depot_price_source: string,
     *     analyze_trend_row_limit: int,
     *     analyze_trend_excluded_holding_ids: array<int, int>,
     *     analyze_trend_trade_amounts: array<int, int>,
     *     analyze_trend_max_invest_amount: int,
     * }
     */
    public function updateAnalyzeTrendExcludedHoldingIds(User $user, array $holdingIds): array
    {
        $settings = $this->userSettings($user);
        $settings['analyze_trend_excluded_holding_ids'] = $this->normalizeAnalyzeTrendExcludedHoldingIds($holdingIds);

        AppConfig::query()->updateOrCreate(
            ['key' => $this->userConfigKey($user)],
            ['value' => $settings],
        );

        return $this->payload($user);
    }

    /**
     * @param  array<int, mixed>  $tradeAmounts
     * @return array{
     *     depot_price_source: string,
     *     analyze_trend_row_limit: int,
     *     analyze_trend_excluded_holding_ids: array<int, int>,
     *     analyze_trend_trade_amounts: array<int, int>,
     *     analyze_trend_max_invest_amount: int,
     * }
     */
    public function updateAnalyzeTrendTradeAmounts(User $user, array $tradeAmounts): array
    {
        return $this->updateAnalyzeTrendInvestmentSettings($user, $tradeAmounts, null);
    }

    /**
     * @param  array<int, mixed>|null  $tradeAmounts
     * @return array{
     *     depot_price_source: string,
     *     analyze_trend_row_limit: int,
     *     analyze_trend_excluded_holding_ids: array<int, int>,
     *     analyze_trend_trade_amounts: array<int, int>,
     *     analyze_trend_max_invest_amount: int,
     * }
     */
    public function updateAnalyzeTrendInvestmentSettings(User $user, ?array $tradeAmounts, ?int $maxInvestAmount): array
    {
        $settings = $this->userSettings($user);

        if ($tradeAmounts !== null) {
            $settings['analyze_trend_trade_amounts'] = $this->normalizeAnalyzeTrendTradeAmounts($tradeAmounts);
        }

        if ($maxInvestAmount !== null) {
            $settings['analyze_trend_max_invest_amount'] = $this->normalizeAnalyzeTrendMaxInvestAmount($maxInvestAmount);
        }

        AppConfig::query()->updateOrCreate(
            ['key' => $this->userConfigKey($user)],
            ['value' => $settings],
        );

        return $this->payload($user);
    }

    /**
     * @return array{
     *     analyze_trend_row_limit: int,
     *     analyze_trend_excluded_holding_ids: array<int, int>,
     *     analyze_trend_trade_amounts: array<int, int>,
     *     analyze_trend_max_invest_amount: int,
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

    /**
     * @return array<int, int>
     */
    private function userAnalyzeTrendExcludedHoldingIds(?User $user): array
    {
        if (! $user) {
            return [];
        }

        return $this->userSettings($user)['analyze_trend_excluded_holding_ids'];
    }

    /**
     * @return array<int, int>
     */
    private function userAnalyzeTrendTradeAmounts(?User $user): array
    {
        if (! $user) {
            return $this->defaultAnalyzeTrendTradeAmounts();
        }

        return $this->userSettings($user)['analyze_trend_trade_amounts'];
    }

    private function userAnalyzeTrendMaxInvestAmount(?User $user): int
    {
        if (! $user) {
            return self::DefaultAnalyzeTrendMaxInvestAmount;
        }

        return $this->userSettings($user)['analyze_trend_max_invest_amount'];
    }

    /**
     * @return array{
     *     analyze_trend_row_limit: int,
     *     analyze_trend_excluded_holding_ids: array<int, int>,
     *     analyze_trend_trade_amounts: array<int, int>,
     *     analyze_trend_max_invest_amount: int,
     * }
     */
    private function defaultUserSettings(): array
    {
        return [
            'analyze_trend_row_limit' => self::DefaultAnalyzeTrendRowLimit,
            'analyze_trend_excluded_holding_ids' => [],
            'analyze_trend_trade_amounts' => $this->defaultAnalyzeTrendTradeAmounts(),
            'analyze_trend_max_invest_amount' => self::DefaultAnalyzeTrendMaxInvestAmount,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $value
     * @return array{
     *     analyze_trend_row_limit: int,
     *     analyze_trend_excluded_holding_ids: array<int, int>,
     *     analyze_trend_trade_amounts: array<int, int>,
     *     analyze_trend_max_invest_amount: int,
     * }
     */
    private function normalizeUserSettings(?array $value): array
    {
        return [
            'analyze_trend_row_limit' => $this->normalizeAnalyzeTrendRowLimit($value['analyze_trend_row_limit'] ?? null),
            'analyze_trend_excluded_holding_ids' => $this->normalizeAnalyzeTrendExcludedHoldingIds(
                $value['analyze_trend_excluded_holding_ids'] ?? null,
            ),
            'analyze_trend_trade_amounts' => $this->normalizeAnalyzeTrendTradeAmounts(
                $value['analyze_trend_trade_amounts'] ?? null,
            ),
            'analyze_trend_max_invest_amount' => $this->normalizeAnalyzeTrendMaxInvestAmount(
                $value['analyze_trend_max_invest_amount'] ?? null,
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

    /**
     * @return array<int, int>
     */
    private function normalizeAnalyzeTrendExcludedHoldingIds(mixed $holdingIds): array
    {
        if (! is_array($holdingIds)) {
            return [];
        }

        return collect($holdingIds)
            ->map(fn (mixed $holdingId): int => (int) $holdingId)
            ->filter(fn (int $holdingId): bool => $holdingId > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeAnalyzeTrendMaxInvestAmount(mixed $maxInvestAmount): int
    {
        if (! is_numeric($maxInvestAmount)) {
            return self::DefaultAnalyzeTrendMaxInvestAmount;
        }

        return min(max((int) $maxInvestAmount, 0), self::MaxAnalyzeTrendMaxInvestAmount);
    }

    /**
     * @return array<int, int>
     */
    private function normalizeAnalyzeTrendTradeAmounts(mixed $tradeAmounts): array
    {
        if (! is_array($tradeAmounts) || count($tradeAmounts) !== 3) {
            return $this->defaultAnalyzeTrendTradeAmounts();
        }

        return collect($tradeAmounts)
            ->map(fn (mixed $tradeAmount): int => (int) $tradeAmount)
            ->map(fn (int $tradeAmount): int => min(max($tradeAmount, 0), 1000000))
            ->values()
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function defaultAnalyzeTrendTradeAmounts(): array
    {
        return [7000, 5000, 3000];
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
