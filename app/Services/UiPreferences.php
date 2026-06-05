<?php

namespace App\Services;

use App\Models\AppConfig;

class UiPreferences
{
    /**
     * @var array<int, string>
     */
    public const DepotPriceSources = ['latest', 'flatex'];

    private const ConfigKey = 'ui.preferences';

    /**
     * @return array{depot_price_source: string}
     */
    public function settings(): array
    {
        $config = AppConfig::query()->firstOrCreate(
            ['key' => self::ConfigKey],
            ['value' => $this->defaultSettings()],
        );
        $settings = $this->normalizeSettings($config->value);

        if ($settings !== $config->value) {
            $config->update(['value' => $settings]);
        }

        return $settings;
    }

    /**
     * @return array{depot_price_source: string}
     */
    public function payload(): array
    {
        return $this->settings();
    }

    /**
     * @return array{depot_price_source: string}
     */
    public function updateDepotPriceSource(string $depotPriceSource): array
    {
        $settings = [
            ...$this->settings(),
            'depot_price_source' => $this->normalizeDepotPriceSource($depotPriceSource),
        ];

        AppConfig::query()->updateOrCreate(
            ['key' => self::ConfigKey],
            ['value' => $settings],
        );

        return $settings;
    }

    /**
     * @return array{depot_price_source: string}
     */
    private function defaultSettings(): array
    {
        return [
            'depot_price_source' => 'latest',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $value
     * @return array{depot_price_source: string}
     */
    private function normalizeSettings(?array $value): array
    {
        $settings = [
            ...$this->defaultSettings(),
            ...($value ?? []),
        ];

        return [
            'depot_price_source' => $this->normalizeDepotPriceSource($settings['depot_price_source'] ?? null),
        ];
    }

    private function normalizeDepotPriceSource(mixed $depotPriceSource): string
    {
        if (is_string($depotPriceSource) && in_array($depotPriceSource, self::DepotPriceSources, true)) {
            return $depotPriceSource;
        }

        return 'latest';
    }
}
