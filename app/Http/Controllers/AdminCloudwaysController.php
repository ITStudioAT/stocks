<?php

namespace App\Http\Controllers;

use App\Services\CloudwaysDatabaseSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AdminCloudwaysController extends Controller
{
    public function sync(Request $request, CloudwaysDatabaseSync $cloudwaysDatabaseSync): JsonResponse|StreamedResponse
    {
        if ($this->wantsStreamedSync($request)) {
            return $this->streamSync($cloudwaysDatabaseSync);
        }

        $sync = $cloudwaysDatabaseSync->syncAllTables();

        return response()->json([
            'message' => "Synced {$sync['synced_tables']} table(s) and {$sync['rows']} row(s) from Cloudways.",
            'sync' => $sync,
        ]);
    }

    private function wantsStreamedSync(Request $request): bool
    {
        return str_contains((string) $request->header('Accept'), 'application/x-ndjson');
    }

    private function streamSync(CloudwaysDatabaseSync $cloudwaysDatabaseSync): StreamedResponse
    {
        return response()->stream(function () use ($cloudwaysDatabaseSync): void {
            try {
                $sync = $cloudwaysDatabaseSync->syncAllTables(
                    onTableSynced: fn (array $table): bool => $this->streamCloudwaysEvent([
                        'type' => 'table',
                        'table' => $table,
                    ]),
                );

                $this->streamCloudwaysEvent([
                    'type' => 'finished',
                    'message' => "Synced {$sync['synced_tables']} table(s) and {$sync['rows']} row(s) from Cloudways.",
                    'sync' => $sync,
                ]);
            } catch (Throwable $exception) {
                report($exception);

                $this->streamCloudwaysEvent([
                    'type' => 'error',
                    'message' => $exception->getMessage(),
                ]);
            }
        }, 200, [
            'Cache-Control' => 'no-cache',
            'Content-Type' => 'application/x-ndjson',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function streamCloudwaysEvent(array $event): bool
    {
        echo json_encode($event)."\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();

        return true;
    }
}
