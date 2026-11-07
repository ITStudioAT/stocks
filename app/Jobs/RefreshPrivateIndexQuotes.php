<?php

namespace App\Jobs;

use App\Services\PrivateIndexQuotes;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RefreshPrivateIndexQuotes implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public int $tries = 1;

    public function __construct(public bool $force = false, public ?string $runId = null) {}

    public function handle(PrivateIndexQuotes $quotes): void
    {
        $id = $quotes->beginRefresh($this->runId ?? null);
        if ($id === null) {
            return;
        }

        try {
            $quotes->refresh($this->force);
        } finally {
            $quotes->finishRefresh($id);
        }
    }

    public function failed(?Throwable $exception): void
    {
        if (isset($this->runId)) {
            app(PrivateIndexQuotes::class)->finishRefresh($this->runId);
        }
        if ($exception) {
            report($exception);
        }
    }
}
