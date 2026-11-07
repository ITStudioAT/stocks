<?php

namespace App\Http\Controllers;

use App\Services\CloudwaysOperation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AdminCloudwaysOperationController extends Controller
{
    public function show(CloudwaysOperation $operations): JsonResponse
    {
        return response()->json(['run' => $operations->current()]);
    }

    public function store(Request $request, CloudwaysOperation $operations): JsonResponse
    {
        abort_unless(app()->environment(['local', 'testing']), 403, 'Cloudways synchronization is available only in the local environment.');
        $validated = $request->validate(['action' => ['required', 'in:check,sync']]);
        Log::notice('security.cloudways.operation_requested', [
            'actor_user_id' => $request->user()?->getKey(),
            'ip' => $request->ip(),
            'action' => $validated['action'],
        ]);
        try {
            $run = $operations->start($validated['action']);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['run' => $run]);
    }
}
