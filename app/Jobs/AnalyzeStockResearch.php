<?php

namespace App\Jobs;

use App\Services\StockAiResearchService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Throwable;

class AnalyzeStockResearch implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 240;

    public int $tries = 1;

    public int $uniqueFor = 300;

    public function __construct(
        public string $researchId,
        public int $userId,
        public int $stockHoldingId,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->userId}:{$this->stockHoldingId}";
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new RateLimited('stock-ai-research'))->releaseAfter(30),
        ];
    }

    /**
     * Execute the job.
     */
    public function handle(StockAiResearchService $researchService): void
    {
        $researchService->run($this->researchId);
    }

    public function failed(?Throwable $exception): void
    {
        app(StockAiResearchService::class)->fail($this->researchId);
    }
}
