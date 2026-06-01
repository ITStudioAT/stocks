<?php

namespace App\Jobs;

use App\Exceptions\WebsiteAnalysisCancelled;
use App\Models\WebsiteAnalysis;
use App\Services\WebsiteAnalyzer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AnalyzeWebsite implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public int $tries = 1;

    public function __construct(public WebsiteAnalysis $analysis) {}

    public function handle(WebsiteAnalyzer $analyzer): void
    {
        if ($this->analysis->refresh()->status === 'canceled') {
            return;
        }

        $this->analysis->update([
            'status' => 'running',
            'analysis_step' => 'analysis',
            'reachability_checked_count' => 0,
            'reachability_total_count' => 0,
            'started_at' => now(),
            'error_message' => null,
        ]);

        try {
            $result = $analyzer->analyze(
                $this->analysis,
                function (
                    int $pagesCount,
                    int $assetsCount,
                    string $analysisStep = 'analysis',
                    int $reachabilityCheckedCount = 0,
                    int $reachabilityTotalCount = 0,
                ): int {
                    if (WebsiteAnalysis::query()->whereKey($this->analysis->id)->value('status') === 'canceled') {
                        throw new WebsiteAnalysisCancelled;
                    }

                    return WebsiteAnalysis::query()
                        ->whereKey($this->analysis->id)
                        ->where('status', '!=', 'canceled')
                        ->update([
                            'analysis_step' => $analysisStep,
                            'pages_count' => $pagesCount,
                            'assets_count' => $assetsCount,
                            'reachability_checked_count' => $reachabilityCheckedCount,
                            'reachability_total_count' => $reachabilityTotalCount,
                        ]);
                },
            );

            if ($this->analysis->refresh()->status === 'canceled') {
                Storage::disk('local')->delete($result['stored_at']);

                throw new WebsiteAnalysisCancelled;
            }

            $this->analysis->update([
                'status' => 'completed',
                'analysis_step' => 'completed',
                'pages_count' => count($result['pages']),
                'assets_count' => $result['site_assets']['total'],
                'reachability_checked_count' => $result['summary']['reachability_checked'] ?? 0,
                'reachability_total_count' => $result['summary']['reachability_total'] ?? 0,
                'result_path' => $result['stored_at'],
                'completed_at' => now(),
            ]);
        } catch (WebsiteAnalysisCancelled) {
            $this->analysis->update([
                'status' => 'canceled',
                'analysis_step' => 'canceled',
                'error_message' => null,
                'completed_at' => now(),
            ]);
        } catch (Throwable $throwable) {
            $this->analysis->update([
                'status' => 'failed',
                'analysis_step' => 'failed',
                'error_message' => $throwable->getMessage(),
                'completed_at' => now(),
            ]);

            throw $throwable;
        }
    }
}
