<?php

namespace App\Http\Controllers;

use App\Services\PreviewControlClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class AdminPreviewControlController extends Controller
{
    public function show(PreviewControlClient $client): JsonResponse
    {
        try {
            return response()->json($client->status());
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'Preview status is unavailable.'], 503);
        }
    }

    public function update(Request $request, PreviewControlClient $client): JsonResponse
    {
        $validated = $request->validate(['enabled' => ['required', 'boolean']]);
        try {
            $result = $client->update((bool) $validated['enabled']);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'Preview control is unavailable.'], 503);
        }

        Log::notice('security.preview.control_changed', [
            'actor_user_id' => $request->user()?->getKey(),
            'ip' => $request->ip(),
            'enabled' => $result['enabled'],
        ]);

        return response()->json($result);
    }
}
