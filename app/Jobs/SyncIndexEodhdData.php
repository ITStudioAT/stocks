<?php

namespace App\Jobs;

use App\Services\V2IndexEodhdSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncIndexEodhdData implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public int $uniqueFor = 2100;

    public function __construct(public string $refreshId) {}

    public function uniqueId(): string
    {
        return 'eodhd-v2-indices-sync';
    }

    public function handle(V2IndexEodhdSyncService $syncService): void
    {
        $syncService->run($this->refreshId);
    }

    public function failed(?Throwable $exception): void
    {
        app(V2IndexEodhdSyncService::class)->fail(
            $this->refreshId,
            $exception?->getMessage() ?? 'Unknown queue failure.',
        );
    }
}
