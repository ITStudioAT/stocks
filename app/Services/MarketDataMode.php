<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class MarketDataMode
{
    public function signature(): string
    {
        return hash('sha256', json_encode([gethostname(), array_intersect_key(DB::connection()->getConfig(), array_flip(['driver', 'host', 'port', 'database', 'unix_socket']))], JSON_THROW_ON_ERROR));
    }

    public function path(): string
    {
        return config('services.market_data.mode_file') ?? storage_path('framework/market-data-mode.json');
    }

    /** @return array<string, mixed>|null */
    public function read(): ?array
    {
        if (! is_file($this->path())) {
            return null;
        }
        $contents = @file_get_contents($this->path());
        if ($contents === false) {
            throw new \RuntimeException('The market-data activation file is not readable.');
        }
        $mode = json_decode($contents, true);
        if (! is_array($mode) || ! in_array($mode['mode'] ?? null, ['legacy', 'upgrading', 'active'], true)) {
            throw new \RuntimeException('The market-data activation file is invalid.');
        }

        return is_array($mode) && ($mode['database_signature'] ?? null) === $this->signature() ? $mode : null;
    }

    public function write(string $mode): void
    {
        File::ensureDirectoryExists(dirname($this->path()), 0755);
        File::replace($this->path(), json_encode(['mode' => $mode, 'database_signature' => $this->signature(), 'changed_at' => now()->utc()->toIso8601String()], JSON_THROW_ON_ERROR), 0644);
    }

    public function paused(): bool
    {
        return ($this->read()['mode'] ?? null) === 'upgrading';
    }
}
