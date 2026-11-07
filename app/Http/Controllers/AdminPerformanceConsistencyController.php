<?php

namespace App\Http\Controllers;

use App\Jobs\CheckPerformanceConsistency;
use App\Models\AppConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class AdminPerformanceConsistencyController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['run' => AppConfig::query()->where('key', CheckPerformanceConsistency::Key)->value('value')]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(['repair' => ['required', 'boolean']]);

        return Cache::lock('performance-consistency-dispatch', 10)->block(5, function () use ($validated): JsonResponse {
            $run = AppConfig::query()->where('key', CheckPerformanceConsistency::Key)->first()?->value;
            if (in_array($run['status'] ?? null, ['queued', 'running'], true)) {
                return response()->json(['run' => $run]);
            }
            $run = ['id' => (string) Str::uuid(), 'status' => 'queued', 'repair' => $validated['repair'], 'step' => 'Wartet auf die Queue', 'steps' => [], 'periods' => [], 'issues' => [], 'checked' => 0, 'missing' => 0, 'incorrect' => 0, 'unavailable' => 0, 'repaired' => 0];
            AppConfig::query()->updateOrCreate(['key' => CheckPerformanceConsistency::Key], ['value' => $run]);
            try {
                CheckPerformanceConsistency::dispatch($run['id']);
            } catch (Throwable $exception) {
                $run['status'] = 'failed';
                $run['step'] = 'Start der Queue fehlgeschlagen';
                AppConfig::query()->where('key', CheckPerformanceConsistency::Key)->update(['value' => json_encode($run, JSON_THROW_ON_ERROR)]);
                throw $exception;
            }

            return response()->json(['run' => $run]);
        });
    }
}
