<?php

namespace App\Jobs;

use App\Services\EodhdExchangeDataImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ReloadEodhdExchanges implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(
        public string $refreshId,
    ) {}

    public function handle(EodhdExchangeDataImporter $importer): void
    {
        $importer->import($this->refreshId);
    }

    public function failed(?Throwable $exception): void
    {
        app(EodhdExchangeDataImporter::class)->fail(
            $this->refreshId,
            $exception?->getMessage() ?? 'Unknown queue failure.',
        );
    }
}
