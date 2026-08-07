<?php

namespace App\Http\Controllers;

use App\Services\InstalledVersionCatalog;
use Illuminate\Http\JsonResponse;

class AdminDashboardVersionController extends Controller
{
    public function show(InstalledVersionCatalog $installedVersionCatalog): JsonResponse
    {
        return response()->json($installedVersionCatalog->payload());
    }
}
