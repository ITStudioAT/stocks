<?php

namespace App\Http\Controllers;

use App\Services\PreviewBackgroundState;
use App\Services\PreviewControlSignature;
use App\Services\PreviewIsolation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class PreviewControlController extends Controller
{
    public function show(Request $request, PreviewIsolation $isolation, PreviewControlSignature $signature, PreviewBackgroundState $state): JsonResponse
    {
        $this->authenticate($request, $isolation, $signature);

        return response()->json(['enabled' => $state->enabled()]);
    }

    public function update(Request $request, PreviewIsolation $isolation, PreviewControlSignature $signature, PreviewBackgroundState $state): JsonResponse
    {
        $this->authenticate($request, $isolation, $signature);
        $validated = $request->validate(['enabled' => ['required', 'boolean']]);
        try {
            $state->setEnabled((bool) $validated['enabled']);
        } catch (RuntimeException) {
            return response()->json(['message' => 'Preview processing prerequisites are incomplete.'], 503);
        }

        return response()->json(['enabled' => $state->enabled()]);
    }

    private function authenticate(Request $request, PreviewIsolation $isolation, PreviewControlSignature $signature): void
    {
        abort_unless($isolation->active() && config('security.preview.control_enabled') === true, 404);
        try {
            $signature->verify($request);
        } catch (RuntimeException) {
            abort(401);
        }
    }
}
