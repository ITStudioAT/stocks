<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\HomepageColorScheme;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HomepageColorScheme>
 */
class HomepageColorSchemeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'homepage_id' => Client::factory(),
            'scheme_name' => 'Default',
            'source_colors_json' => [
                'source_color_1' => null,
                'source_color_2' => null,
                'source_color_3' => null,
                'source_color_4' => null,
            ],
            'role_colors_json' => [
                'role_primary' => '#245C4F',
                'role_accent' => '#007BFF',
                'role_support' => '#28A745',
                'role_decorative' => '#DC3545',
            ],
            'generated_palette_json' => [
                'neutral_0' => '#FFFFFF',
                'neutral_50' => '#F7F7F4',
            ],
            'usage_tokens_json' => [
                'color_page_bg' => '#F7F7F4',
                'color_text' => '#1D2623',
            ],
            'css_variables' => ":root {\n  --color-page-bg: #F7F7F4;\n  --color-text: #1D2623;\n}\n",
            'warnings_json' => [],
            'harmony_score' => 92.00,
            'accessibility_mode' => 'standard',
            'is_active' => true,
        ];
    }
}
