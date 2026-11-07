<?php

namespace App\Jobs;

use App\Services\CloudwaysDatabaseSync;
use App\Services\CloudwaysOperation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunCloudwaysOperation implements ShouldQueue
{
    use Queueable;

    public int $timeout = 7200;

    public int $tries = 1;

    public function __construct(public string $runId, public string $action, public array $tables) {}

    public function handle(CloudwaysDatabaseSync $sync, CloudwaysOperation $operations): void
    {
        abort_unless(app()->environment(['local', 'testing']), 403, 'Cloudways synchronization is available only in the local environment.');
        $run = $operations->current();
        if (($run['id'] ?? null) !== $this->runId) {
            return;
        }
        $run['status'] = 'running';
        $operations->save($run);
        $onProgress = function (array $progress) use (&$run, $operations): void {
            $run['progress'] = $progress;
            $operations->save($run);
        };
        if ($this->action === 'check') {
            $run['comparison'] = $sync->compareAllTables(onProgress: $onProgress);
        } else {
            $run['sync'] = $sync->syncAllTables(checkedDifferentTables: $this->tables, onProgress: $onProgress);
        }
        $run['status'] = 'finished';
        $run['finished_at'] = now()->toIso8601String();
        $operations->save($run);
    }

    public function failed(?Throwable $exception): void
    {
        $operations = app(CloudwaysOperation::class);
        $run = $operations->current();
        if (($run['id'] ?? null) === $this->runId) {
            $run['status'] = 'failed';
            $run['error'] = 'Der Cloudways-Auftrag ist fehlgeschlagen. Details stehen im Anwendungsprotokoll.';
            $operations->save($run);
        }
    }
}
