<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAnalyzeResearchSettingsRequest;
use App\Services\AnalyzeResearchSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAnalyzeResearchSettingsController extends Controller
{
    public function show(Request $request, AnalyzeResearchSettings $researchSettings): JsonResponse
    {
        return response()->json([
            'research_settings' => $researchSettings->payload($request->user()),
        ]);
    }

    public function update(
        UpdateAnalyzeResearchSettingsRequest $request,
        AnalyzeResearchSettings $researchSettings,
    ): JsonResponse {
        return response()->json([
            'message' => 'Research settings saved.',
            'research_settings' => $researchSettings->update($request->user(), $request->validated()),
        ]);
    }
}
