<?php

namespace App\Jobs;

use App\Services\PrivateIndexQuotes;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RefreshPrivateIndexQuotes implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public int $tries = 1;

    public int $uniqueFor = 300;

    public function __construct(public bool $force = false) {}

    public function uniqueId(): string
    {
        return 'private-index-quotes';
    }

    public function handle(PrivateIndexQuotes $quotes): void
    {
        $quotes->refresh($this->force);
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception) {
            report($exception);
        }
    }
}
