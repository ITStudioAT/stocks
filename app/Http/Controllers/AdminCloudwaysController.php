<?php

namespace App\Http\Controllers;

use App\Services\CloudwaysDatabaseSync;
use Illuminate\Http\JsonResponse;

class AdminCloudwaysController extends Controller
{
    public function sync(CloudwaysDatabaseSync $cloudwaysDatabaseSync): JsonResponse
    {
        $sync = $cloudwaysDatabaseSync->syncAllTables();

        return response()->json([
            'message' => "Synced {$sync['synced_tables']} table(s) and {$sync['rows']} row(s) from Cloudways.",
            'sync' => $sync,
        ]);
    }
}
