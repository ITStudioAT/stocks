<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdminCloudwaysSyncRequest;
use App\Services\CloudwaysDatabaseSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AdminCloudwaysController extends Controller
{
    public function status(CloudwaysDatabaseSync $cloudwaysDatabaseSync): JsonResponse
    {
        return response()->json([
            'execution_status' => $cloudwaysDatabaseSync->executionStatus(),
        ]);
    }

    public function show(Request $request, CloudwaysDatabaseSync $cloudwaysDatabaseSync): JsonResponse|StreamedResponse
    {
        if ($this->wantsStreamedResponse($request)) {
            return $this->streamCheck($cloudwaysDatabaseSync);
        }

        $comparison = $cloudwaysDatabaseSync->compareAllTables();

        return response()->json([
            'message' => "Checked {$comparison['total_tables']} table(s); {$comparison['different_tables']} differ.",
            'comparison' => $comparison,
        ]);
    }

    public function sync(
        AdminCloudwaysSyncRequest $request,
        CloudwaysDatabaseSync $cloudwaysDatabaseSync,
    ): JsonResponse|StreamedResponse {
        $checkedDifferentTables = $request->validated('tables');

        if ($this->wantsStreamedResponse($request)) {
            return $this->streamSync($cloudwaysDatabaseSync, $checkedDifferentTables);
        }

        $sync = $cloudwaysDatabaseSync->syncAllTables(checkedDifferentTables: $checkedDifferentTables);

        return response()->json([
            'message' => "Synced {$sync['synced_tables']} table(s) and {$sync['rows']} row(s) from Cloudways.",
            'sync' => $sync,
        ]);
    }

    private function wantsStreamedResponse(Request $request): bool
    {
        return str_contains((string) $request->header('Accept'), 'application/x-ndjson');
    }

    private function streamCheck(CloudwaysDatabaseSync $cloudwaysDatabaseSync): StreamedResponse
    {
        return response()->stream(function () use ($cloudwaysDatabaseSync): void {
            try {
                $comparison = $cloudwaysDatabaseSync->compareAllTables(
                    onTableCompared: fn (array $table, int $completed, int $total): bool => $this->streamCloudwaysEvent([
                        'type' => 'table',
                        'table' => $table,
                        'completed' => $completed,
                        'total' => $total,
                    ]),
                    onProgress: fn (array $progress): bool => $this->streamCloudwaysEvent([
                        'type' => 'progress',
                        'progress' => $progress,
                    ]),
                );

                $this->streamCloudwaysEvent([
                    'type' => 'finished',
                    'message' => "Checked {$comparison['total_tables']} table(s); {$comparison['different_tables']} differ.",
                    'comparison' => $comparison,
                ]);
            } catch (Throwable $exception) {
                report($exception);

                $this->streamCloudwaysEvent([
                    'type' => 'error',
                    'message' => $exception->getMessage(),
                ]);
            }
        }, 200, $this->streamHeaders());
    }

    /**
     * @param  array<int, string>  $checkedDifferentTables
     */
    private function streamSync(
        CloudwaysDatabaseSync $cloudwaysDatabaseSync,
        array $checkedDifferentTables,
    ): StreamedResponse {
        return response()->stream(function () use ($cloudwaysDatabaseSync, $checkedDifferentTables): void {
            try {
                $sync = $cloudwaysDatabaseSync->syncAllTables(
                    onTableSynced: fn (array $table, int $completed, int $total): bool => $this->streamCloudwaysEvent([
                        'type' => 'table',
                        'table' => $table,
                        'completed' => $completed,
                        'total' => $total,
                    ]),
                    onProgress: fn (array $progress): bool => $this->streamCloudwaysEvent([
                        'type' => 'progress',
                        'progress' => $progress,
                    ]),
                    checkedDifferentTables: $checkedDifferentTables,
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
        }, 200, $this->streamHeaders());
    }

    /**
     * @return array<string, string>
     */
    private function streamHeaders(): array
    {
        return [
            'Cache-Control' => 'no-cache',
            'Content-Type' => 'application/x-ndjson',
            'X-Accel-Buffering' => 'no',
        ];
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
