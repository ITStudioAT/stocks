<?php

namespace Tests\Unit;

use App\Services\HomepageColorSchemeGenerator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class HomepageColorSchemeGeneratorTest extends TestCase
{
    public function test_it_generates_a_complete_scheme_without_source_colors(): void
    {
        $scheme = $this->generator()->generate();

        $this->assertNull($scheme['source_colors_json']['source_color_1']);
        $this->assertArrayHasKey('color_page_bg', $scheme['usage_tokens_json']);
        $this->assertArrayHasKey('color_button_primary_bg', $scheme['usage_tokens_json']);
        $this->assertArrayHasKey('state_error', $scheme['usage_tokens_json']);
        $this->assertStringContainsString('--color-page-bg:', $scheme['css_variables']);
        $this->assertStringContainsString('--state-error:', $scheme['css_variables']);
        $this->assertArrayHasKey('warnings_json', $scheme);
        $this->assertArrayHasKey('harmony_score', $scheme);
    }

    public function test_it_normalizes_one_source_color_and_uses_it_when_required(): void
    {
        $scheme = $this->generator()->generate([
            [
                'hex' => '#abc',
                'must_use' => true,
                'origin' => 'manual_input',
                'user_locked' => true,
            ],
        ]);

        $this->assertSame('#AABBCC', $scheme['source_colors_json']['source_color_1']['hex']);
        $this->assertContains('#AABBCC', $scheme['usage_tokens_json']);
        $this->assertArrayHasKey('oklch', $scheme['generated_palette_json']['source_analysis']['source_color_1']);
        $this->assertArrayHasKey('linear_rgb', $scheme['generated_palette_json']['source_analysis']['source_color_1']);
    }

    public function test_it_handles_two_source_colors_without_assuming_input_order(): void
    {
        $scheme = $this->generator()->generate([
            ['hex' => '#DC3545', 'must_use' => true],
            ['hex' => '#007BFF', 'must_use' => true],
        ]);

        $this->assertSame('#DC3545', $scheme['role_colors_json']['role_decorative']['hex']);
        $this->assertNotSame('#DC3545', $scheme['role_colors_json']['role_primary']['hex']);
        $this->assertContains('#DC3545', $scheme['usage_tokens_json']);
        $this->assertContains('#007BFF', $scheme['usage_tokens_json']);
    }

    public function test_it_assigns_three_source_colors_to_safe_design_roles(): void
    {
        $scheme = $this->generator()->generate([
            ['hex' => '#DC3545', 'must_use' => true],
            ['hex' => '#28A745', 'must_use' => true],
            ['hex' => '#007BFF', 'must_use' => true],
        ]);

        $this->assertSame('#DC3545', $scheme['role_colors_json']['role_decorative']['hex']);
        $this->assertNotSame('#DC3545', $scheme['role_colors_json']['role_primary']['hex']);
        $this->assertContains('#28A745', $scheme['usage_tokens_json']);
        $this->assertContains('#007BFF', $scheme['usage_tokens_json']);
    }

    public function test_it_keeps_four_must_use_source_colors_visible_in_usage_tokens(): void
    {
        $sourceColors = [
            ['hex' => '#007BFF', 'must_use' => true],
            ['hex' => '#28A745', 'must_use' => true],
            ['hex' => '#DC3545', 'must_use' => true],
            ['hex' => '#6F42C1', 'must_use' => true],
        ];

        $scheme = $this->generator()->generate($sourceColors);

        foreach (['#007BFF', '#28A745', '#DC3545', '#6F42C1'] as $hex) {
            $this->assertContains($hex, $scheme['usage_tokens_json']);
            $this->assertStringContainsString($hex, $scheme['css_variables']);
        }
    }

    public function test_it_rejects_invalid_source_colors(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->generator()->generate([
            ['hex' => 'not-a-color'],
        ]);
    }

    public function test_low_contrast_source_colors_are_repositioned_safely(): void
    {
        $generator = $this->generator();
        $scheme = $generator->generate([
            ['hex' => '#FFFFAA', 'must_use' => true],
        ]);
        $usageTokens = $scheme['usage_tokens_json'];

        $this->assertContains('#FFFFAA', $usageTokens);
        $this->assertGreaterThanOrEqual(
            4.5,
            $generator->contrastRatio($usageTokens['color_button_primary_bg'], $usageTokens['color_button_primary_text']),
        );
        $this->assertStringContainsString('very light', implode(' ', $scheme['generated_palette_json']['warnings']));
    }

    public function test_blue_source_color_can_become_primary_info_and_link(): void
    {
        $scheme = $this->generator()->generate([
            ['hex' => '#007BFF', 'must_use' => true],
        ]);

        $this->assertSame('#007BFF', $scheme['source_colors_json']['source_color_1']['hex']);
        $this->assertContains($scheme['role_colors_json']['role_primary']['usage_strength'], ['dominant', 'regular']);
        $this->assertSame('#007BFF', $scheme['usage_tokens_json']['state_info']);
        $this->assertContains('#007BFF', $scheme['usage_tokens_json']);
    }

    public function test_green_source_color_can_become_support_and_success(): void
    {
        $scheme = $this->generator()->generate([
            ['hex' => '#28A745', 'must_use' => true],
            ['hex' => '#007BFF', 'must_use' => true],
        ]);

        $this->assertSame('#28A745', $scheme['usage_tokens_json']['state_success']);
        $this->assertContains('#28A745', [
            $scheme['role_colors_json']['role_support']['hex'],
            $scheme['usage_tokens_json']['color_support'],
        ]);
    }

    public function test_red_source_color_is_not_generic_cta_by_default(): void
    {
        $scheme = $this->generator()->generate([
            ['hex' => '#007BFF', 'must_use' => true],
            ['hex' => '#DC3545', 'must_use' => true],
        ]);

        $this->assertSame('#DC3545', $scheme['role_colors_json']['role_decorative']['hex']);
        $this->assertSame('#DC3545', $scheme['usage_tokens_json']['state_error']);
        $this->assertNotSame('#DC3545', $scheme['usage_tokens_json']['color_button_primary_bg']);
    }

    public function test_bright_teal_close_to_green_is_limited_or_decorative(): void
    {
        $scheme = $this->generator()->generate([
            ['hex' => '#007BFF', 'must_use' => true],
            ['hex' => '#28A745', 'must_use' => true],
            ['hex' => '#00D6A4', 'must_use' => true],
        ]);
        $tealRole = collect($scheme['role_colors_json'])->firstWhere('hex', '#00D6A4');

        $this->assertNotNull($tealRole);
        $this->assertContains($tealRole['usage_strength'], ['limited', 'minimal']);
        $this->assertStringContainsString('similar', implode(' ', $scheme['warnings_json']));
    }

    public function test_very_dark_colors_get_white_contrast_text(): void
    {
        $scheme = $this->generator()->generate([
            ['hex' => '#101820', 'must_use' => true],
        ]);

        $this->assertSame('#FFFFFF', $scheme['role_colors_json']['role_primary']['contrast_text']);
    }

    public function test_neon_colors_are_restricted_to_limited_or_decorative_use(): void
    {
        $scheme = $this->generator()->generate([
            ['hex' => '#39FF14', 'must_use' => true],
            ['hex' => '#007BFF', 'must_use' => true],
        ]);
        $neonRole = collect($scheme['role_colors_json'])->firstWhere('hex', '#39FF14');

        $this->assertNotNull($neonRole);
        $this->assertContains($neonRole['usage_strength'], ['limited', 'minimal']);
        $this->assertContains('neon', $neonRole['classification']);
    }

    public function test_generated_palette_contains_oklch_variants_for_each_role(): void
    {
        $scheme = $this->generator()->generate([
            ['hex' => '#007BFF', 'must_use' => true],
        ]);

        foreach (['primary', 'accent', 'support', 'decorative'] as $role) {
            foreach (['hover', 'soft', 'border', 'text', 'contrast'] as $variant) {
                $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $scheme['generated_palette_json'][$role.'_'.$variant]);
            }
        }
    }

    public function test_foundational_usage_token_contrast_passes(): void
    {
        $generator = $this->generator();
        $scheme = $generator->generate([
            ['hex' => '#FFFFAA', 'must_use' => true],
            ['hex' => '#007BFF', 'must_use' => true],
            ['hex' => '#DC3545', 'must_use' => true],
        ]);
        $tokens = $scheme['usage_tokens_json'];

        $this->assertGreaterThanOrEqual(4.5, $generator->contrastRatio($tokens['color_text'], $tokens['color_page_bg']));
        $this->assertGreaterThanOrEqual(4.5, $generator->contrastRatio($tokens['color_button_primary_text'], $tokens['color_button_primary_bg']));
        $this->assertGreaterThanOrEqual(4.5, $generator->contrastRatio($tokens['color_header_text'], $tokens['color_header_bg']));
        $this->assertGreaterThanOrEqual(4.5, $generator->contrastRatio($tokens['color_footer_text'], $tokens['color_footer_bg']));
    }

    public function test_high_contrast_mode_uses_stronger_foundation_tokens(): void
    {
        $scheme = $this->generator()->generate(
            sourceColors: [['hex' => '#7CC7FF', 'must_use' => true]],
            options: ['accessibility_mode' => 'high_contrast'],
        );

        $this->assertSame('#FFFFFF', $scheme['usage_tokens_json']['color_page_bg']);
        $this->assertSame('#111111', $scheme['usage_tokens_json']['color_text']);
    }

    private function generator(): HomepageColorSchemeGenerator
    {
        return new HomepageColorSchemeGenerator;
    }
}
