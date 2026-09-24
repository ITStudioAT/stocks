<?php

namespace App\Services;

use App\Models\AppConfig;
use RuntimeException;

class PreviewBackgroundState
{
    private const ConfigKey = 'preview.background.enabled';

    public function __construct(private PreviewIsolation $isolation) {}

    public function enabled(): bool
    {
        if (! $this->isolation->active()) {
            return false;
        }

        $value = AppConfig::query()->where('key', self::ConfigKey)->first()?->value;

        return is_array($value) && ($value['enabled'] ?? false) === true;
    }

    public function setEnabled(bool $enabled): void
    {
        if (! $this->isolation->active() || config('security.preview.control_enabled') !== true) {
            throw new RuntimeException('Preview background processing can only be changed in the preview.');
        }

        if ($enabled && $this->isolation->problems() !== []) {
            throw new RuntimeException('Preview background processing prerequisites are incomplete.');
        }

        AppConfig::query()->updateOrCreate(
            ['key' => self::ConfigKey],
            ['value' => ['enabled' => $enabled, 'changed_at' => now()->toIso8601String()]],
        );
    }
}
