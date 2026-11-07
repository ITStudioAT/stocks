<?php

namespace App\Services;

use App\Jobs\RunCloudwaysOperation;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class CloudwaysOperation
{
    private const Key = 'cloudways.operation';

    public function store(): Repository
    {
        return Cache::store('file');
    }

    public function current(): ?array
    {
        return $this->store()->get(self::Key);
    }

    public function start(string $action): array
    {
        return $this->store()->lock('cloudways.operation.dispatch', 10)->block(5, function () use ($action): array {
            $previous = $this->current();
            if (in_array($previous['status'] ?? null, ['queued', 'running'], true)) {
                return $previous;
            }
            $tables = [];
            if ($action === 'sync') {
                if (($previous['action'] ?? null) !== 'check' || ($previous['status'] ?? null) !== 'finished') {
                    throw new RuntimeException('Bitte zuerst den Vergleich durchführen.');
                }
                $tables = collect($previous['comparison']['tables'] ?? [])->where('status', '!=', 'identical')->pluck('name')->all();
                if ($tables === []) {
                    throw new RuntimeException('Es gibt keine abweichenden Tabellen zur Synchronisation.');
                }
            }
            $run = ['id' => (string) Str::uuid(), 'action' => $action, 'status' => 'queued', 'progress' => null, 'comparison' => $previous['comparison'] ?? null, 'sync' => null, 'error' => null];
            $this->save($run);
            try {
                RunCloudwaysOperation::dispatch($run['id'], $action, $tables);
            } catch (Throwable $exception) {
                $run['status'] = 'failed';
                $run['error'] = 'Der Auftrag konnte nicht in die Queue eingereiht werden.';
                $this->save($run);
                throw $exception;
            }

            return $this->current() ?? $run;
        });
    }

    public function save(array $run): void
    {
        $this->store()->put(self::Key, $run, now()->addDay());
    }
}
