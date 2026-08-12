<?php

namespace App\Services;

use App\Models\AnalyzeResearchSetting;
use App\Models\User;

class AnalyzeResearchSettings
{
    public const MaxRowCount = 1000;

    /**
     * @var array<int, float>
     */
    private const DefaultBuyThresholds = [-4.0, -3.0, -2.0, -1.0, 0.0];

    /**
     * @return array{
     *     rows: int,
     *     buy_rules: array<int, array{enabled: bool, from: float, to: float}>,
     *     buy_step: float,
     *     sell: array{from: float, to: float, step: float},
     *     invest: array{from: float, to: float, step: float},
     *     max_invest: array{value: float},
     * }
     */
    public function payload(User $user): array
    {
        $setting = AnalyzeResearchSetting::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['settings' => $this->defaultSettings()],
        );
        $settings = $this->normalize($setting->settings);

        if ($settings != $setting->settings) {
            $setting->update(['settings' => $settings]);
        }

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{
     *     rows: int,
     *     buy_rules: array<int, array{enabled: bool, from: float, to: float}>,
     *     buy_step: float,
     *     sell: array{from: float, to: float, step: float},
     *     invest: array{from: float, to: float, step: float},
     *     max_invest: array{value: float},
     * }
     */
    public function update(User $user, array $settings): array
    {
        $settings = $this->normalize($settings);

        AnalyzeResearchSetting::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['settings' => $settings],
        );

        return $settings;
    }

    /**
     * @return array{
     *     rows: int,
     *     buy_rules: array<int, array{enabled: bool, from: float, to: float}>,
     *     buy_step: float,
     *     sell: array{from: float, to: float, step: float},
     *     invest: array{from: float, to: float, step: float},
     *     max_invest: array{value: float},
     * }
     */
    public function defaultSettings(): array
    {
        return [
            'rows' => 200,
            'buy_rules' => array_map(
                fn (float $threshold): array => [
                    'enabled' => true,
                    'from' => $threshold,
                    'to' => $threshold,
                ],
                self::DefaultBuyThresholds,
            ),
            'buy_step' => 0.1,
            'sell' => [
                'from' => 3.0,
                'to' => 3.0,
                'step' => 0.1,
            ],
            'invest' => [
                'from' => 7000.0,
                'to' => 7000.0,
                'step' => 100.0,
            ],
            'max_invest' => [
                'value' => 80000.0,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $settings
     * @return array{
     *     rows: int,
     *     buy_rules: array<int, array{enabled: bool, from: float, to: float}>,
     *     buy_step: float,
     *     sell: array{from: float, to: float, step: float},
     *     invest: array{from: float, to: float, step: float},
     *     max_invest: array{value: float},
     * }
     */
    private function normalize(?array $settings): array
    {
        $defaults = $this->defaultSettings();
        $buyRules = is_array($settings['buy_rules'] ?? null)
            ? array_slice($settings['buy_rules'], 0, 20)
            : $defaults['buy_rules'];

        if ($buyRules === []) {
            $buyRules = $defaults['buy_rules'];
        }

        $legacyStep = $this->normalizeDecimal($settings['step'] ?? null, 0.1, 0.000001, 100.0);

        return [
            'rows' => $this->normalizeInteger($settings['rows'] ?? null, 200, 1, self::MaxRowCount),
            'buy_rules' => array_values(array_map(
                fn (mixed $buyRule): array => $this->normalizeBuyRule($buyRule),
                $buyRules,
            )),
            'buy_step' => $this->normalizeDecimal(
                $settings['buy_step'] ?? null,
                $legacyStep,
                0.000001,
                100.0,
            ),
            'sell' => $this->normalizeSell($settings['sell'] ?? null, $legacyStep),
            'invest' => $this->normalizeInvest($settings['invest'] ?? null),
            'max_invest' => $this->normalizeMaxInvest($settings['max_invest'] ?? null),
        ];
    }

    /**
     * @return array{enabled: bool, from: float, to: float}
     */
    private function normalizeBuyRule(mixed $buyRule): array
    {
        if (! is_array($buyRule)) {
            return ['enabled' => true, 'from' => 0.0, 'to' => 0.0];
        }

        $from = $this->normalizeDecimal($buyRule['from'] ?? null, 0.0, -100.0, 0.0);
        $to = $this->normalizeDecimal($buyRule['to'] ?? null, 0.0, -100.0, 0.0);

        return [
            'enabled' => filter_var($buyRule['enabled'] ?? true, FILTER_VALIDATE_BOOL),
            'from' => min($from, $to),
            'to' => max($from, $to),
        ];
    }

    /**
     * @return array{from: float, to: float, step: float}
     */
    private function normalizeSell(mixed $sell, float $legacyStep): array
    {
        if (! is_array($sell)) {
            return ['from' => 3.0, 'to' => 3.0, 'step' => $legacyStep];
        }

        $from = $this->normalizeDecimal($sell['from'] ?? null, 3.0, 0.0, 100.0);
        $to = $this->normalizeDecimal($sell['to'] ?? null, 3.0, 0.0, 100.0);

        return [
            'from' => min($from, $to),
            'to' => max($from, $to),
            'step' => $this->normalizeDecimal($sell['step'] ?? null, $legacyStep, 0.000001, 100.0),
        ];
    }

    /**
     * @return array{from: float, to: float, step: float}
     */
    private function normalizeInvest(mixed $invest): array
    {
        if (! is_array($invest)) {
            return ['from' => 7000.0, 'to' => 7000.0, 'step' => 100.0];
        }

        $from = $this->normalizeDecimal($invest['from'] ?? null, 7000.0, 0.0, 1000000.0);
        $to = $this->normalizeDecimal($invest['to'] ?? null, 7000.0, 0.0, 1000000.0);

        return [
            'from' => min($from, $to),
            'to' => max($from, $to),
            'step' => $this->normalizeDecimal($invest['step'] ?? null, 100.0, 0.000001, 1000000.0),
        ];
    }

    /**
     * @return array{value: float}
     */
    private function normalizeMaxInvest(mixed $maxInvest): array
    {
        if (! is_array($maxInvest)) {
            return ['value' => 80000.0];
        }

        return [
            'value' => $this->normalizeDecimal(
                $maxInvest['value'] ?? $maxInvest['from'] ?? null,
                80000.0,
                0.0,
                1000000.0,
            ),
        ];
    }

    private function normalizeInteger(mixed $value, int $default, int $minimum, int $maximum): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            return $default;
        }

        return min(max((int) $value, $minimum), $maximum);
    }

    private function normalizeDecimal(mixed $value, float $default, float $minimum, float $maximum): float
    {
        if (! is_numeric($value)) {
            return $default;
        }

        return min(max((float) $value, $minimum), $maximum);
    }
}
