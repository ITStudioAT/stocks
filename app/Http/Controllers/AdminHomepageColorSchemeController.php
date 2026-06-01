<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\HomepageColorScheme;
use App\Services\HomepageColorSchemeGenerator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminHomepageColorSchemeController extends Controller
{
    public function store(Request $request, Client $client, HomepageColorSchemeGenerator $generator): JsonResponse
    {
        $this->authorizeClientAccess($request, $client);

        $validated = $request->validate([
            'scheme_name' => ['nullable', 'string', 'max:120'],
            'accessibility_mode' => ['nullable', 'string', Rule::in(['standard', 'high_contrast'])],
            'homepage_type' => ['nullable', 'string', 'max:120'],
            'mood' => ['nullable', 'string', 'max:120'],
            'target_audience' => ['nullable', 'string', 'max:120'],
            'source_colors' => ['nullable', 'array', 'max:4'],
            'source_colors.*.hex' => ['required_with:source_colors', 'string', 'max:7', 'regex:/^#?(?:[0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/'],
            'source_colors.*.must_use' => ['sometimes', 'boolean'],
            'source_colors.*.source_url' => ['nullable', 'url', 'max:2048'],
            'source_colors.*.origin' => [
                'nullable',
                'string',
                Rule::in(['analyzed_website', 'manual_input', 'logo', 'brand_guide', 'product', 'generated', 'preset']),
            ],
            'source_colors.*.detected_frequency' => ['nullable', 'integer', 'min:0'],
            'source_colors.*.user_locked' => ['sometimes', 'boolean'],
        ]);

        $colorScheme = $generator->createForHomepage(
            homepageId: $client->id,
            sourceColors: $validated['source_colors'] ?? [],
            options: [
                'scheme_name' => $validated['scheme_name'] ?? 'Default',
                'accessibility_mode' => $validated['accessibility_mode'] ?? 'standard',
                'homepage_type' => $validated['homepage_type'] ?? null,
                'mood' => $validated['mood'] ?? null,
                'target_audience' => $validated['target_audience'] ?? null,
            ],
        );

        return response()->json([
            'message' => 'Homepage color scheme generated.',
            'color_scheme' => $this->colorSchemeData($colorScheme),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function colorSchemeData(HomepageColorScheme $colorScheme): array
    {
        return [
            'id' => $colorScheme->id,
            'homepage_id' => $colorScheme->homepage_id,
            'scheme_name' => $colorScheme->scheme_name,
            'source_colors' => $colorScheme->source_colors_json,
            'role_colors' => $colorScheme->role_colors_json,
            'generated_palette' => $colorScheme->generated_palette_json,
            'usage_tokens' => $colorScheme->usage_tokens_json,
            'css_variables' => $colorScheme->css_variables,
            'warnings' => $colorScheme->warnings_json,
            'harmony_score' => $colorScheme->harmony_score,
            'accessibility_mode' => $colorScheme->accessibility_mode,
            'is_active' => $colorScheme->is_active,
        ];
    }

    private function authorizeClientAccess(Request $request, Client $client): void
    {
        if ($request->user()->hasRole('super_admin')) {
            return;
        }

        if ($client->company_id === $request->user()->company_id) {
            return;
        }

        throw new AuthorizationException;
    }
}
