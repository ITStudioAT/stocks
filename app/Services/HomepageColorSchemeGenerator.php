<?php

namespace App\Services;

use App\Models\HomepageColorScheme;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class HomepageColorSchemeGenerator
{
    public const CACHE_TTL_SECONDS = 86400;

    private const BODY_TEXT_CONTRAST = 4.5;

    private const LARGE_TEXT_CONTRAST = 3.0;

    private const UI_CONTRAST = 3.0;

    private const HIGH_CONTRAST_TARGET = 7.0;

    private const ALLOWED_ORIGINS = [
        'analyzed_website',
        'manual_input',
        'logo',
        'brand_guide',
        'product',
        'generated',
        'preset',
    ];

    private const ROLE_KEYS = [
        'role_primary',
        'role_accent',
        'role_support',
        'role_decorative',
    ];

    /**
     * @param  array<int, array<string, mixed>|string|null>  $sourceColors
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function generate(array $sourceColors = [], array $options = []): array
    {
        $accessibilityMode = $options['accessibility_mode'] ?? 'standard';
        $sourceColorData = $this->normalizeSourceColors($sourceColors);
        $analyses = $this->analyzeSourceColors($sourceColorData);
        $warnings = $this->sourceWarnings($analyses);
        $roleColors = $this->assignRoleColors($sourceColorData, $analyses, $warnings);
        $harmonyScore = $this->harmonyScore($roleColors, $warnings);
        $generatedPalette = $this->generatePalette($roleColors, $sourceColorData, $analyses);
        $generatedPalette['source_analysis'] = $analyses;
        $usageTokens = $this->generateUsageTokens(
            roleColors: $roleColors,
            generatedPalette: $generatedPalette,
            sourceColorData: $sourceColorData,
            analyses: $analyses,
            accessibilityMode: $accessibilityMode,
            warnings: $warnings,
        );
        $warnings = array_values(array_unique($warnings));
        $generatedPalette['warnings'] = $warnings;
        $generatedPalette['harmony_score'] = $harmonyScore;

        return [
            'source_colors_json' => $sourceColorData,
            'role_colors_json' => $roleColors,
            'generated_palette_json' => $generatedPalette,
            'usage_tokens_json' => $usageTokens,
            'css_variables' => $this->cssVariables($usageTokens),
            'warnings_json' => $warnings,
            'harmony_score' => $harmonyScore,
            'accessibility_mode' => $accessibilityMode,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>|string|null>  $sourceColors
     * @param  array<string, mixed>  $options
     */
    public function createForHomepage(int $homepageId, array $sourceColors = [], array $options = []): HomepageColorScheme
    {
        $generatedScheme = $this->generate($sourceColors, $options);
        $schemeName = $options['scheme_name'] ?? 'Default';

        $scheme = DB::transaction(function () use ($homepageId, $generatedScheme, $schemeName): HomepageColorScheme {
            HomepageColorScheme::query()
                ->where('homepage_id', $homepageId)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            return HomepageColorScheme::create([
                'homepage_id' => $homepageId,
                'scheme_name' => $schemeName,
                ...$generatedScheme,
                'is_active' => true,
            ]);
        });

        Cache::forget($this->cacheKey($homepageId));

        return $scheme;
    }

    public function activeCssVariablesForHomepage(int $homepageId): string
    {
        return Cache::remember(
            $this->cacheKey($homepageId),
            self::CACHE_TTL_SECONDS,
            fn (): string => HomepageColorScheme::query()
                ->where('homepage_id', $homepageId)
                ->where('is_active', true)
                ->latest('id')
                ->value('css_variables') ?? self::defaultCssVariables(),
        );
    }

    public static function defaultCssVariables(): string
    {
        return self::cssVariablesForTokens(self::defaultUsageTokens());
    }

    public function normalizeHex(string $hex): string
    {
        $value = strtoupper(trim($hex));

        if (preg_match('/^#?[0-9A-F]{3}$/', $value) === 1) {
            $value = ltrim($value, '#');

            return '#'.$value[0].$value[0].$value[1].$value[1].$value[2].$value[2];
        }

        if (preg_match('/^#?[0-9A-F]{6}$/', $value) === 1) {
            return '#'.ltrim($value, '#');
        }

        throw new InvalidArgumentException("Invalid HEX color [{$hex}].");
    }

    public function relativeLuminanceHex(string $hex): float
    {
        return $this->relativeLuminance($this->hexToRgb($this->normalizeHex($hex)));
    }

    public function contrastRatio(string $firstHex, string $secondHex): float
    {
        $firstLuminance = $this->relativeLuminanceHex($firstHex);
        $secondLuminance = $this->relativeLuminanceHex($secondHex);
        $lighter = max($firstLuminance, $secondLuminance);
        $darker = min($firstLuminance, $secondLuminance);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    public function bestContrastText(string $backgroundHex, float $minimumRatio = self::BODY_TEXT_CONTRAST): string
    {
        $whiteContrast = $this->contrastRatio($backgroundHex, '#FFFFFF');
        $blackContrast = $this->contrastRatio($backgroundHex, '#111111');

        if ($whiteContrast >= $minimumRatio && $whiteContrast >= $blackContrast) {
            return '#FFFFFF';
        }

        return '#111111';
    }

    public function chooseContrastText(string $backgroundHex, float $minimumRatio = self::BODY_TEXT_CONTRAST): string
    {
        return $this->bestContrastText($backgroundHex, $minimumRatio);
    }

    /**
     * @return array{r: int, g: int, b: int}
     */
    public function hexToRgb(string $hex): array
    {
        $value = ltrim($this->normalizeHex($hex), '#');

        return [
            'r' => hexdec(substr($value, 0, 2)),
            'g' => hexdec(substr($value, 2, 2)),
            'b' => hexdec(substr($value, 4, 2)),
        ];
    }

    /**
     * @param  array{r: int, g: int, b: int}  $rgb
     * @return array{h: float, s: float, l: float}
     */
    public function rgbToHsl(array $rgb): array
    {
        $red = $rgb['r'] / 255;
        $green = $rgb['g'] / 255;
        $blue = $rgb['b'] / 255;
        $max = max($red, $green, $blue);
        $min = min($red, $green, $blue);
        $lightness = ($max + $min) / 2;

        if ($max === $min) {
            return ['h' => 0.0, 's' => 0.0, 'l' => $lightness];
        }

        $delta = $max - $min;
        $saturation = $lightness > 0.5
            ? $delta / (2 - $max - $min)
            : $delta / ($max + $min);

        if ($max === $red) {
            $hue = (($green - $blue) / $delta) + ($green < $blue ? 6 : 0);
        } elseif ($max === $green) {
            $hue = (($blue - $red) / $delta) + 2;
        } else {
            $hue = (($red - $green) / $delta) + 4;
        }

        return [
            'h' => $hue * 60,
            's' => $saturation,
            'l' => $lightness,
        ];
    }

    public function hslToHex(float $hue, float $saturation, float $lightness): string
    {
        $hue = fmod(fmod($hue, 360) + 360, 360) / 360;
        $saturation = $this->clamp($saturation);
        $lightness = $this->clamp($lightness);

        if ($saturation === 0.0) {
            $channel = (int) round($lightness * 255);

            return $this->rgbToHex(['r' => $channel, 'g' => $channel, 'b' => $channel]);
        }

        $q = $lightness < 0.5
            ? $lightness * (1 + $saturation)
            : $lightness + $saturation - ($lightness * $saturation);
        $p = (2 * $lightness) - $q;

        return $this->rgbToHex([
            'r' => (int) round($this->hueToRgb($p, $q, $hue + (1 / 3)) * 255),
            'g' => (int) round($this->hueToRgb($p, $q, $hue) * 255),
            'b' => (int) round($this->hueToRgb($p, $q, $hue - (1 / 3)) * 255),
        ]);
    }

    public function darken(string $hex, float $amount = 0.10): string
    {
        $oklch = $this->hexToOklch($hex);

        return $this->oklchToHex(max(0.18, $oklch['l'] - $amount), $oklch['c'], $oklch['h']);
    }

    public function softBackground(string $hex): string
    {
        $oklch = $this->hexToOklch($hex);

        return $this->oklchToHex(0.94, min($oklch['c'] * 0.28, 0.055), $oklch['h']);
    }

    public function hueDistance(float $firstHue, float $secondHue): float
    {
        $distance = abs($firstHue - $secondHue);

        return min($distance, 360 - $distance);
    }

    /**
     * @param  array<int, array<string, mixed>|string|null>  $sourceColors
     * @return array<string, array<string, mixed>|null>
     */
    private function normalizeSourceColors(array $sourceColors): array
    {
        if (count($sourceColors) > 4) {
            throw new InvalidArgumentException('A homepage color scheme accepts at most four source colors.');
        }

        $normalizedColors = [
            'source_color_1' => null,
            'source_color_2' => null,
            'source_color_3' => null,
            'source_color_4' => null,
        ];

        foreach (array_values($sourceColors) as $index => $sourceColor) {
            if ($sourceColor === null) {
                continue;
            }

            $metadata = is_array($sourceColor) ? $sourceColor : ['hex' => $sourceColor];
            $origin = $metadata['origin'] ?? 'manual_input';

            if (! in_array($origin, self::ALLOWED_ORIGINS, true)) {
                throw new InvalidArgumentException("Invalid source color origin [{$origin}].");
            }

            $normalizedColors['source_color_'.($index + 1)] = [
                'hex' => $this->normalizeHex((string) ($metadata['hex'] ?? '')),
                'must_use' => (bool) ($metadata['must_use'] ?? false),
                'source_url' => $metadata['source_url'] ?? null,
                'origin' => $origin,
                'detected_frequency' => $metadata['detected_frequency'] ?? null,
                'user_locked' => (bool) ($metadata['user_locked'] ?? false),
            ];
        }

        return $normalizedColors;
    }

    /**
     * @param  array<string, array<string, mixed>|null>  $sourceColorData
     * @return array<string, array<string, mixed>>
     */
    private function analyzeSourceColors(array $sourceColorData): array
    {
        $analyses = [];

        foreach ($sourceColorData as $sourceKey => $sourceColor) {
            if ($sourceColor === null) {
                continue;
            }

            $rgb = $this->hexToRgb($sourceColor['hex']);
            $linearRgb = $this->rgbToLinearRgb($rgb);
            $hsl = $this->rgbToHsl($rgb);
            $oklab = $this->linearRgbToOklab($linearRgb);
            $oklch = $this->oklabToOklch($oklab);
            $luminance = $this->relativeLuminance($rgb);
            $contrastWhite = $this->contrastRatio($sourceColor['hex'], '#FFFFFF');
            $contrastBlack = $this->contrastRatio($sourceColor['hex'], '#111111');

            $analyses[$sourceKey] = [
                'hex' => $sourceColor['hex'],
                'rgb' => $rgb,
                'linear_rgb' => $this->roundChannels($linearRgb),
                'hsl' => [
                    'h' => round($hsl['h'], 2),
                    's' => round($hsl['s'], 4),
                    'l' => round($hsl['l'], 4),
                ],
                'oklab' => [
                    'l' => round($oklab['l'], 5),
                    'a' => round($oklab['a'], 5),
                    'b' => round($oklab['b'], 5),
                ],
                'oklch' => [
                    'l' => round($oklch['l'], 5),
                    'c' => round($oklch['c'], 5),
                    'h' => round($oklch['h'], 2),
                ],
                'relative_luminance' => round($luminance, 5),
                'contrast_white' => round($contrastWhite, 2),
                'contrast_black' => round($contrastBlack, 2),
                'perceived_lightness' => round($oklch['l'], 5),
                'chroma_intensity' => round($oklch['c'], 5),
                'hue_angle' => round($oklch['h'], 2),
                'ui_safe_for_dark_text' => $contrastBlack >= self::BODY_TEXT_CONTRAST,
                'ui_safe_for_white_text' => $contrastWhite >= self::BODY_TEXT_CONTRAST,
                'risky_primary' => $this->isRiskyPrimary($oklch),
                'classification' => $this->classifyColor($hsl, $oklch),
                'preferred_uses' => $this->preferredUses($oklch),
            ];
        }

        foreach ($analyses as $sourceKey => $analysis) {
            $distances = [];

            foreach ($analyses as $otherSourceKey => $otherAnalysis) {
                if ($sourceKey === $otherSourceKey) {
                    continue;
                }

                $distances[$otherSourceKey] = round($this->hueDistance($analysis['hue_angle'], $otherAnalysis['hue_angle']), 2);
            }

            $analyses[$sourceKey]['hue_distance_from_sources'] = $distances;
        }

        return $analyses;
    }

    /**
     * @param  array{h: float, s: float, l: float}  $hsl
     * @param  array{l: float, c: float, h: float}  $oklch
     * @return array<int, string>
     */
    private function classifyColor(array $hsl, array $oklch): array
    {
        $classification = [];
        $classification[] = $oklch['l'] < 0.38 ? 'dark' : ($oklch['l'] > 0.82 ? 'light' : 'balanced');
        $classification[] = $oklch['c'] > 0.20 ? 'high-chroma' : ($oklch['c'] < 0.045 ? 'muted' : 'moderate-chroma');

        if ($oklch['c'] < 0.025 || $hsl['s'] < 0.10) {
            $classification[] = 'neutral-like';
        }

        if ($this->isRedHue($oklch['h'])) {
            $classification[] = 'semantic-error-risk';
            $classification[] = 'decorative';
        }

        if ($this->isWarningHue($oklch['h'])) {
            $classification[] = 'semantic-warning-risk';
        }

        if ($oklch['c'] > 0.25 && ($oklch['l'] > 0.68 || $oklch['l'] < 0.34)) {
            $classification[] = 'neon';
            $classification[] = 'decorative';
        }

        if ($oklch['l'] > 0.86) {
            $classification[] = 'low-contrast-light';
        }

        return array_values(array_unique($classification));
    }

    /**
     * @param  array{l: float, c: float, h: float}  $oklch
     * @return array<int, string>
     */
    private function preferredUses(array $oklch): array
    {
        if ($this->isRedHue($oklch['h'])) {
            return ['decorative', 'state_error', 'badges', 'icons'];
        }

        if ($this->isGreenHue($oklch['h'])) {
            return ['support', 'state_success', 'icons', 'soft_backgrounds'];
        }

        if ($this->isBlueHue($oklch['h'])) {
            return ['primary', 'links', 'state_info', 'buttons'];
        }

        if ($this->isWarningHue($oklch['h'])) {
            return ['decorative', 'state_warning', 'badges'];
        }

        return ['accent', 'support', 'decorative'];
    }

    /**
     * @param  array<string, array<string, mixed>>  $analyses
     * @return array<int, string>
     */
    private function sourceWarnings(array $analyses): array
    {
        $warnings = [];

        foreach ($analyses as $analysis) {
            if (in_array('low-contrast-light', $analysis['classification'], true)) {
                $warnings[] = "The selected color {$analysis['hex']} is very light, so it should be used as a soft or decorative color instead of important UI backgrounds.";
            }

            if (in_array('neon', $analysis['classification'], true)) {
                $warnings[] = "The selected color {$analysis['hex']} is very vivid; large surfaces were avoided and usage was limited.";
            }

            if ($this->isRedHue($analysis['hue_angle'])) {
                $warnings[] = "The selected red color {$analysis['hex']} was treated carefully because red often signals danger or destructive actions.";
            }
        }

        foreach ($analyses as $sourceKey => $analysis) {
            foreach ($analysis['hue_distance_from_sources'] as $otherSourceKey => $distance) {
                if ($sourceKey < $otherSourceKey && $distance < 24) {
                    $warnings[] = 'The selected colors are very similar; one was moved toward limited or decorative usage to avoid visual competition.';
                }
            }
        }

        return $warnings;
    }

    /**
     * @param  array<string, array<string, mixed>|null>  $sourceColorData
     * @param  array<string, array<string, mixed>>  $analyses
     * @param  array<int, string>  $warnings
     * @return array<string, array<string, mixed>>
     */
    private function assignRoleColors(array $sourceColorData, array $analyses, array &$warnings): array
    {
        $entries = [];

        foreach ($sourceColorData as $sourceKey => $sourceColor) {
            if ($sourceColor === null) {
                continue;
            }

            $entries[] = [
                'source_key' => $sourceKey,
                'hex' => $sourceColor['hex'],
                'must_use' => $sourceColor['must_use'],
                'user_locked' => $sourceColor['user_locked'],
                'analysis' => $analyses[$sourceKey],
            ];
        }

        $roles = [];
        $assignedSourceKeys = [];

        if ($entries !== []) {
            $decorativeEntry = $this->bestEntry($entries, $assignedSourceKeys, fn (array $entry): float => $this->decorativeScore($entry));

            if ($decorativeEntry && $this->decorativeScore($decorativeEntry) >= 2.2) {
                $roles['role_decorative'] = $this->roleFromEntry($decorativeEntry, 'source', 'role_decorative');
                $assignedSourceKeys[] = $decorativeEntry['source_key'];

                if ($this->isRedHue($decorativeEntry['analysis']['hue_angle'])) {
                    $warnings[] = "The red source color {$decorativeEntry['hex']} was assigned to decorative and error-safe roles instead of generic CTA usage.";
                }
            }

            $primaryEntry = $this->bestEntry($entries, $assignedSourceKeys, fn (array $entry): float => $this->primaryScore($entry, count($entries)));

            if ($primaryEntry) {
                $roles['role_primary'] = $this->roleFromEntry($primaryEntry, 'source', 'role_primary');
                $assignedSourceKeys[] = $primaryEntry['source_key'];
            }

            $supportEntry = $this->bestEntry($entries, $assignedSourceKeys, fn (array $entry): float => $this->supportScore($entry));

            if ($supportEntry) {
                $roles['role_support'] = $this->roleFromEntry($supportEntry, 'source', 'role_support');
                $assignedSourceKeys[] = $supportEntry['source_key'];
            }

            $primaryHue = $roles['role_primary']['metrics']['hue_angle'] ?? 250;
            $accentEntry = $this->bestEntry(
                $entries,
                $assignedSourceKeys,
                fn (array $entry): float => $this->accentScore($entry, $primaryHue),
            );

            if ($accentEntry) {
                $roles['role_accent'] = $this->roleFromEntry($accentEntry, 'source', 'role_accent');
                $assignedSourceKeys[] = $accentEntry['source_key'];
            }

            $decorativeEntry = $this->bestEntry($entries, $assignedSourceKeys, fn (array $entry): float => $this->decorativeScore($entry));

            if ($decorativeEntry && ! isset($roles['role_decorative'])) {
                $roles['role_decorative'] = $this->roleFromEntry($decorativeEntry, 'source', 'role_decorative');
                $assignedSourceKeys[] = $decorativeEntry['source_key'];
            }
        }

        $roles = $this->fillGeneratedRoles($roles);
        $roles = $this->restrictSimilarHighChromaRoles($roles, $warnings);

        foreach (self::ROLE_KEYS as $roleKey) {
            $roles[$roleKey] = [
                ...$roles[$roleKey],
                ...$this->roleUsagePolicy($roleKey, $roles[$roleKey], $roles),
            ];
        }

        return $roles;
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @param  array<int, string>  $assignedSourceKeys
     */
    private function bestEntry(array $entries, array $assignedSourceKeys, callable $score): ?array
    {
        $bestEntry = null;
        $bestScore = null;

        foreach ($entries as $entry) {
            if (in_array($entry['source_key'], $assignedSourceKeys, true)) {
                continue;
            }

            $entryScore = $score($entry);

            if ($bestScore !== null && $entryScore <= $bestScore) {
                continue;
            }

            $bestEntry = $entry;
            $bestScore = $entryScore;
        }

        return $bestEntry;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function roleFromEntry(array $entry, string $origin, string $roleKey): array
    {
        $analysis = $entry['analysis'];

        return [
            'hex' => $entry['hex'],
            'source_key' => $entry['source_key'],
            'origin' => $origin,
            'contrast_text' => $this->bestContrastText($entry['hex']),
            'metrics' => [
                'hsl' => $analysis['hsl'],
                'oklab' => $analysis['oklab'],
                'oklch' => $analysis['oklch'],
                'relative_luminance' => $analysis['relative_luminance'],
                'contrast_white' => $analysis['contrast_white'],
                'contrast_black' => $analysis['contrast_black'],
                'perceived_lightness' => $analysis['perceived_lightness'],
                'chroma_intensity' => $analysis['chroma_intensity'],
                'hue_angle' => $analysis['hue_angle'],
                'ui_safe_for_dark_text' => $analysis['ui_safe_for_dark_text'],
                'ui_safe_for_white_text' => $analysis['ui_safe_for_white_text'],
                'risky_primary' => $analysis['risky_primary'],
            ],
            'classification' => $analysis['classification'],
            'preferred_uses' => $analysis['preferred_uses'],
            'assignment_scores' => [
                'role_primary' => round($this->primaryScore($entry, 4), 2),
                'role_accent' => round($this->accentScore($entry, $analysis['hue_angle']), 2),
                'role_support' => round($this->supportScore($entry), 2),
                'role_decorative' => round($this->decorativeScore($entry), 2),
            ],
            'assigned_role' => $roleKey,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $roles
     * @return array<string, array<string, mixed>>
     */
    private function fillGeneratedRoles(array $roles): array
    {
        $base = $roles['role_primary']['hex'] ?? '#245C4F';
        $baseOklch = $this->hexToOklch($base);
        $generatedColors = [
            'role_primary' => $this->oklchToHex(0.48, max(0.08, min(0.13, $baseOklch['c'])), $baseOklch['h']),
            'role_accent' => $this->oklchToHex(0.58, 0.16, $baseOklch['h'] + 145),
            'role_support' => $this->oklchToHex(0.56, 0.09, $baseOklch['h'] + 70),
            'role_decorative' => $this->oklchToHex(0.64, 0.18, $baseOklch['h'] - 45),
        ];

        foreach (self::ROLE_KEYS as $roleKey) {
            if (isset($roles[$roleKey])) {
                continue;
            }

            $hex = $generatedColors[$roleKey];
            $analysis = $this->analysisForGeneratedColor($hex);

            $roles[$roleKey] = [
                'hex' => $hex,
                'source_key' => null,
                'origin' => 'generated',
                'contrast_text' => $this->bestContrastText($hex),
                'metrics' => [
                    'hsl' => $analysis['hsl'],
                    'oklab' => $analysis['oklab'],
                    'oklch' => $analysis['oklch'],
                    'relative_luminance' => $analysis['relative_luminance'],
                    'contrast_white' => $analysis['contrast_white'],
                    'contrast_black' => $analysis['contrast_black'],
                    'perceived_lightness' => $analysis['perceived_lightness'],
                    'chroma_intensity' => $analysis['chroma_intensity'],
                    'hue_angle' => $analysis['hue_angle'],
                    'ui_safe_for_dark_text' => $analysis['ui_safe_for_dark_text'],
                    'ui_safe_for_white_text' => $analysis['ui_safe_for_white_text'],
                    'risky_primary' => $analysis['risky_primary'],
                ],
                'classification' => $analysis['classification'],
                'preferred_uses' => $analysis['preferred_uses'],
                'assignment_scores' => [],
                'assigned_role' => $roleKey,
            ];
        }

        return $roles;
    }

    /**
     * @return array<string, mixed>
     */
    private function analysisForGeneratedColor(string $hex): array
    {
        $normalizedSource = [
            'source_color_1' => [
                'hex' => $hex,
                'must_use' => false,
                'source_url' => null,
                'origin' => 'generated',
                'detected_frequency' => null,
                'user_locked' => false,
            ],
        ];

        return $this->analyzeSourceColors($normalizedSource)['source_color_1'];
    }

    /**
     * @param  array<string, array<string, mixed>>  $roles
     * @param  array<int, string>  $warnings
     * @return array<string, array<string, mixed>>
     */
    private function restrictSimilarHighChromaRoles(array $roles, array &$warnings): array
    {
        foreach (self::ROLE_KEYS as $firstIndex => $firstRole) {
            foreach (self::ROLE_KEYS as $secondIndex => $secondRole) {
                if ($firstIndex >= $secondIndex) {
                    continue;
                }

                $first = $roles[$firstRole];
                $second = $roles[$secondRole];
                $distance = $this->hueDistance($first['metrics']['hue_angle'], $second['metrics']['hue_angle']);
                $bothLoud = $first['metrics']['chroma_intensity'] > 0.14 && $second['metrics']['chroma_intensity'] > 0.14;

                if ($distance >= 24 || ! $bothLoud) {
                    continue;
                }

                $warnings[] = "The colors {$first['hex']} and {$second['hex']} are close and vivid; one should use limited or decorative strength.";

                $limitedRole = in_array('role_accent', [$firstRole, $secondRole], true)
                    ? 'role_accent'
                    : ($secondRole !== 'role_primary' ? $secondRole : $firstRole);

                if ($limitedRole !== 'role_primary') {
                    $roles[$limitedRole]['force_limited_usage'] = true;
                }
            }
        }

        return $roles;
    }

    /**
     * @param  array<string, mixed>  $role
     * @param  array<string, array<string, mixed>>  $roles
     * @return array<string, mixed>
     */
    private function roleUsagePolicy(string $roleKey, array $role, array $roles): array
    {
        $classification = $role['classification'];
        $warnings = [];
        $usageStrength = match ($roleKey) {
            'role_primary' => 'dominant',
            'role_accent' => 'regular',
            'role_support' => 'regular',
            default => 'limited',
        };

        if (($role['force_limited_usage'] ?? false) || in_array('neon', $classification, true)) {
            $usageStrength = $roleKey === 'role_decorative' ? 'minimal' : 'limited';
            $warnings[] = 'High chroma or similar hue detected; use sparingly to avoid visual competition.';
        }

        if (in_array('semantic-error-risk', $classification, true) && $roleKey !== 'role_decorative') {
            $usageStrength = 'limited';
            $warnings[] = 'Red often communicates danger or destructive actions; avoid generic CTA overuse.';
        }

        $allowedUses = match ($usageStrength) {
            'dominant' => ['links', 'primary buttons after contrast adjustment', 'headers', 'brand accents'],
            'regular' => ['buttons after contrast adjustment', 'highlights', 'badges', 'icons', 'soft backgrounds'],
            'limited' => ['icons', 'badges', 'small decorative accents', 'soft backgrounds', 'chart color'],
            default => ['icons', 'badges', 'small decorative accents', 'borders', 'soft backgrounds'],
        };

        $forbiddenUses = match ($usageStrength) {
            'dominant' => ['body text without contrast validation', 'large page background'],
            'regular' => ['body text', 'large page background'],
            'limited' => ['body text', 'large page background', 'main navigation background'],
            default => ['body text', 'large page background', 'main navigation background', 'primary CTA background'],
        };

        return [
            'usage_strength' => $usageStrength,
            'allowed_uses' => $allowedUses,
            'forbidden_uses' => $forbiddenUses,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $roleColors
     * @param  array<string, array<string, mixed>|null>  $sourceColorData
     * @param  array<string, array<string, mixed>>  $analyses
     * @return array<string, mixed>
     */
    private function generatePalette(array $roleColors, array $sourceColorData, array $analyses): array
    {
        $palette = [];

        foreach ([
            'primary' => 'role_primary',
            'accent' => 'role_accent',
            'support' => 'role_support',
            'decorative' => 'role_decorative',
        ] as $paletteKey => $roleKey) {
            $hex = $roleColors[$roleKey]['hex'];
            $palette[$paletteKey.'_hover'] = $this->oklchVariant($hex, lightness: 0.48, chromaMultiplier: 0.95);
            $palette[$paletteKey.'_soft'] = $this->oklchVariant($hex, lightness: 0.94, chromaMultiplier: 0.28, maxChroma: 0.055);
            $palette[$paletteKey.'_border'] = $this->oklchVariant($hex, lightness: 0.78, chromaMultiplier: 0.40, maxChroma: 0.09);
            $palette[$paletteKey.'_text'] = $this->accessibleTextVariant($hex);
            $palette[$paletteKey.'_contrast'] = $this->bestContrastText($hex);
        }

        $palette['neutral'] = $this->generateNeutralScale($roleColors['role_primary']['hex']);
        $palette['states'] = $this->generateStateColors($sourceColorData, $analyses);

        return $palette;
    }

    /**
     * @return array<string, string>
     */
    private function generateNeutralScale(string $baseHex): array
    {
        $oklch = $this->hexToOklch($baseHex);
        $neutralChroma = min(0.018, max(0.006, $oklch['c'] * 0.08));
        $hue = $oklch['h'];

        return [
            'neutral_0' => '#FFFFFF',
            'neutral_50' => $this->oklchToHex(0.985, $neutralChroma, $hue),
            'neutral_100' => $this->oklchToHex(0.955, $neutralChroma, $hue),
            'neutral_200' => $this->oklchToHex(0.885, $neutralChroma * 1.2, $hue),
            'neutral_300' => $this->oklchToHex(0.780, $neutralChroma * 1.4, $hue),
            'neutral_500' => $this->oklchToHex(0.500, $neutralChroma * 1.8, $hue),
            'neutral_700' => $this->oklchToHex(0.310, $neutralChroma * 2.1, $hue),
            'neutral_900' => $this->oklchToHex(0.180, $neutralChroma * 2.4, $hue),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>|null>  $sourceColorData
     * @param  array<string, array<string, mixed>>  $analyses
     * @return array<string, string>
     */
    private function generateStateColors(array $sourceColorData, array $analyses): array
    {
        $states = [
            'state_success' => '#198754',
            'state_warning' => '#B7791F',
            'state_error' => '#DC3545',
            'state_info' => '#0D6EFD',
        ];

        foreach ($sourceColorData as $sourceKey => $sourceColor) {
            if ($sourceColor === null || ! $sourceColor['must_use']) {
                continue;
            }

            $hue = $analyses[$sourceKey]['hue_angle'];

            if ($this->isGreenHue($hue)) {
                $states['state_success'] = $sourceColor['hex'];
            } elseif ($this->isWarningHue($hue)) {
                $states['state_warning'] = $sourceColor['hex'];
            } elseif ($this->isRedHue($hue)) {
                $states['state_error'] = $sourceColor['hex'];
            } elseif ($this->isBlueHue($hue)) {
                $states['state_info'] = $sourceColor['hex'];
            }
        }

        foreach (['success', 'warning', 'error', 'info'] as $state) {
            $stateHex = $states['state_'.$state];
            $states['state_'.$state.'_soft'] = $this->oklchVariant($stateHex, lightness: 0.94, chromaMultiplier: 0.25, maxChroma: 0.055);
            $states['state_'.$state.'_text'] = $this->accessibleTextVariant($stateHex);
        }

        return $states;
    }

    /**
     * @param  array<string, array<string, mixed>>  $roleColors
     * @param  array<string, mixed>  $generatedPalette
     * @param  array<string, array<string, mixed>|null>  $sourceColorData
     * @param  array<string, array<string, mixed>>  $analyses
     * @param  array<int, string>  $warnings
     * @return array<string, string>
     */
    private function generateUsageTokens(
        array $roleColors,
        array $generatedPalette,
        array $sourceColorData,
        array $analyses,
        string $accessibilityMode,
        array &$warnings,
    ): array {
        $neutral = $generatedPalette['neutral'];
        $states = $generatedPalette['states'];
        $contrastTarget = $accessibilityMode === 'high_contrast' ? self::HIGH_CONTRAST_TARGET : self::BODY_TEXT_CONTRAST;
        $pageBackground = $accessibilityMode === 'high_contrast' ? '#FFFFFF' : $neutral['neutral_50'];
        $text = $accessibilityMode === 'high_contrast' ? '#111111' : $neutral['neutral_900'];
        $primary = $roleColors['role_primary']['hex'];
        $accent = $roleColors['role_accent']['hex'];
        $support = $roleColors['role_support']['hex'];
        $decorative = $roleColors['role_decorative']['hex'];
        $primaryButtonBackground = $this->safeUiBackground($primary, $warnings, 'primary button', $contrastTarget);
        $accentButtonBackground = $this->safeUiBackground($accent, $warnings, 'accent button', $contrastTarget);
        $headerBackground = $this->safeUiBackground($primary, $warnings, 'header', $contrastTarget);
        $footerBackground = $accessibilityMode === 'high_contrast' ? '#111111' : $neutral['neutral_900'];

        $usageTokens = [
            'color_page_bg' => $pageBackground,
            'color_section_bg' => $neutral['neutral_0'],
            'color_section_muted_bg' => $neutral['neutral_100'],
            'color_card_bg' => $neutral['neutral_0'],
            'color_card_border' => $neutral['neutral_200'],
            'color_heading' => $text,
            'color_heading_accent' => $this->safeTextAccent($primary, $pageBackground, self::LARGE_TEXT_CONTRAST),
            'color_text' => $text,
            'color_text_muted' => $accessibilityMode === 'high_contrast' ? $neutral['neutral_700'] : $neutral['neutral_500'],
            'color_text_inverse' => '#FFFFFF',
            'color_link' => $this->safeTextAccent($primary, $pageBackground, $contrastTarget),
            'color_link_hover' => $this->safeTextAccent($generatedPalette['primary_hover'], $pageBackground, $contrastTarget),
            'color_button_primary_bg' => $primaryButtonBackground,
            'color_button_primary_bg_hover' => $this->safeUiBackground($generatedPalette['primary_hover'], $warnings, 'primary button hover', $contrastTarget),
            'color_button_primary_text' => $this->bestContrastText($primaryButtonBackground, $contrastTarget),
            'color_button_secondary_bg' => $generatedPalette['primary_soft'],
            'color_button_secondary_bg_hover' => $this->oklchVariant($generatedPalette['primary_soft'], lightness: 0.89, chromaMultiplier: 1.0),
            'color_button_secondary_text' => $this->safeTextAccent($generatedPalette['primary_text'], $generatedPalette['primary_soft'], $contrastTarget),
            'color_button_accent_bg' => $accentButtonBackground,
            'color_button_accent_bg_hover' => $this->safeUiBackground($generatedPalette['accent_hover'], $warnings, 'accent button hover', $contrastTarget),
            'color_button_accent_text' => $this->bestContrastText($accentButtonBackground, $contrastTarget),
            'color_accent' => $accent,
            'color_accent_soft' => $generatedPalette['accent_soft'],
            'color_highlight' => $accent,
            'color_support' => $support,
            'color_support_soft' => $generatedPalette['support_soft'],
            'color_decorative' => $decorative,
            'color_decorative_soft' => $generatedPalette['decorative_soft'],
            'color_header_bg' => $headerBackground,
            'color_header_text' => $this->bestContrastText($headerBackground, $contrastTarget),
            'color_nav_link' => $this->bestContrastText($headerBackground, $contrastTarget),
            'color_nav_link_hover' => $generatedPalette['accent_soft'],
            'color_footer_bg' => $footerBackground,
            'color_footer_text' => '#FFFFFF',
            'color_footer_text_muted' => $neutral['neutral_300'],
            ...$states,
        ];

        return $this->ensureMustUseColorsAreVisible($usageTokens, $sourceColorData, $analyses);
    }

    /**
     * @param  array<string, string>  $usageTokens
     * @param  array<string, array<string, mixed>|null>  $sourceColorData
     * @param  array<string, array<string, mixed>>  $analyses
     * @return array<string, string>
     */
    private function ensureMustUseColorsAreVisible(array $usageTokens, array $sourceColorData, array $analyses): array
    {
        foreach ($sourceColorData as $sourceKey => $sourceColor) {
            if ($sourceColor === null || ! $sourceColor['must_use'] || in_array($sourceColor['hex'], $usageTokens, true)) {
                continue;
            }

            $hue = $analyses[$sourceKey]['hue_angle'];

            if ($this->isRedHue($hue)) {
                $usageTokens['color_decorative'] = $sourceColor['hex'];
                $usageTokens['state_error'] = $sourceColor['hex'];

                continue;
            }

            if ($this->isGreenHue($hue)) {
                $usageTokens['color_support'] = $sourceColor['hex'];
                $usageTokens['state_success'] = $sourceColor['hex'];

                continue;
            }

            if ($this->isBlueHue($hue)) {
                $usageTokens['color_accent'] = $sourceColor['hex'];
                $usageTokens['state_info'] = $sourceColor['hex'];

                continue;
            }

            $usageTokens['color_highlight'] = $sourceColor['hex'];
        }

        return $usageTokens;
    }

    /**
     * @param  array<int, string>  $warnings
     */
    private function safeUiBackground(string $hex, array &$warnings, string $roleName, float $minimumRatio = self::BODY_TEXT_CONTRAST): string
    {
        $background = $hex;

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $contrastText = $this->bestContrastText($background, $minimumRatio);

            if ($this->contrastRatio($background, $contrastText) >= $minimumRatio) {
                if ($background !== $hex) {
                    $warnings[] = "The selected color {$hex} failed contrast for {$roleName}, so a safer OKLCH variant {$background} was generated.";
                }

                return $background;
            }

            $oklch = $this->hexToOklch($background);
            $background = $this->oklchToHex(max(0.20, $oklch['l'] - 0.06), $oklch['c'], $oklch['h']);
        }

        $warnings[] = "The selected color {$hex} was unsafe for {$roleName}, so a neutral high-contrast color was used.";

        return '#111111';
    }

    private function safeTextAccent(string $hex, string $backgroundHex, float $minimumRatio = self::BODY_TEXT_CONTRAST): string
    {
        if ($this->contrastRatio($hex, $backgroundHex) >= $minimumRatio) {
            return $hex;
        }

        $candidate = $this->accessibleTextVariant($hex);

        if ($this->contrastRatio($candidate, $backgroundHex) >= $minimumRatio) {
            return $candidate;
        }

        return $this->bestContrastText($backgroundHex, $minimumRatio);
    }

    private function accessibleTextVariant(string $hex): string
    {
        $oklch = $this->hexToOklch($hex);

        return $this->oklchToHex(min($oklch['l'], 0.42), min($oklch['c'], 0.14), $oklch['h']);
    }

    /**
     * @return array<string, string>
     */
    private static function defaultUsageTokens(): array
    {
        return [
            'color_page_bg' => '#F8F9FA',
            'color_section_bg' => '#FFFFFF',
            'color_section_muted_bg' => '#F1F3F5',
            'color_card_bg' => '#FFFFFF',
            'color_card_border' => '#D9DDE3',
            'color_heading' => '#1D1D1F',
            'color_heading_accent' => '#245C4F',
            'color_text' => '#1D1D1F',
            'color_text_muted' => '#5F6670',
            'color_text_inverse' => '#FFFFFF',
            'color_link' => '#245C4F',
            'color_link_hover' => '#194139',
            'color_button_primary_bg' => '#245C4F',
            'color_button_primary_bg_hover' => '#183E35',
            'color_button_primary_text' => '#FFFFFF',
            'color_button_secondary_bg' => '#E7F0ED',
            'color_button_secondary_bg_hover' => '#D5E4DF',
            'color_button_secondary_text' => '#183E35',
            'color_button_accent_bg' => '#0D6EFD',
            'color_button_accent_bg_hover' => '#084DB2',
            'color_button_accent_text' => '#FFFFFF',
            'color_accent' => '#0D6EFD',
            'color_accent_soft' => '#E6F2FF',
            'color_highlight' => '#0D6EFD',
            'color_support' => '#198754',
            'color_support_soft' => '#DDF3E4',
            'color_decorative' => '#DC3545',
            'color_decorative_soft' => '#FBE1E5',
            'color_header_bg' => '#245C4F',
            'color_header_text' => '#FFFFFF',
            'color_nav_link' => '#FFFFFF',
            'color_nav_link_hover' => '#E7F0ED',
            'color_footer_bg' => '#1D1D1F',
            'color_footer_text' => '#FFFFFF',
            'color_footer_text_muted' => '#C3C9D1',
            'state_success' => '#198754',
            'state_success_soft' => '#DDF3E4',
            'state_success_text' => '#155724',
            'state_warning' => '#B7791F',
            'state_warning_soft' => '#F8E7C2',
            'state_warning_text' => '#6B3F00',
            'state_error' => '#DC3545',
            'state_error_soft' => '#FBE1E5',
            'state_error_text' => '#721C24',
            'state_info' => '#0D6EFD',
            'state_info_soft' => '#E6F2FF',
            'state_info_text' => '#004085',
        ];
    }

    /**
     * @param  array<string, string>  $usageTokens
     */
    private function cssVariables(array $usageTokens): string
    {
        return self::cssVariablesForTokens($usageTokens);
    }

    /**
     * @param  array<string, string>  $usageTokens
     */
    private static function cssVariablesForTokens(array $usageTokens): string
    {
        $lines = [':root {'];

        foreach ($usageTokens as $token => $value) {
            $lines[] = '  --'.str_replace('_', '-', $token).': '.$value.';';
        }

        $lines[] = '}';

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string, array<string, mixed>>  $roles
     * @param  array<int, string>  $warnings
     */
    private function harmonyScore(array $roles, array &$warnings): float
    {
        $score = 100.0;
        $highChromaCount = 0;

        foreach (self::ROLE_KEYS as $roleKey) {
            if ($roles[$roleKey]['metrics']['chroma_intensity'] > 0.15) {
                $highChromaCount++;
            }
        }

        if ($highChromaCount > 2) {
            $score -= ($highChromaCount - 2) * 8;
            $warnings[] = 'The palette contains several high-chroma colors; usage strength was reduced for secondary colors.';
        }

        foreach (self::ROLE_KEYS as $firstRole) {
            foreach (self::ROLE_KEYS as $secondRole) {
                if ($firstRole >= $secondRole) {
                    continue;
                }

                $distance = $this->hueDistance(
                    $roles[$firstRole]['metrics']['hue_angle'],
                    $roles[$secondRole]['metrics']['hue_angle'],
                );

                if ($distance < 18) {
                    $score -= 7;
                }
            }
        }

        $lightnessValues = array_map(fn (array $role): float => $role['metrics']['perceived_lightness'], $roles);

        if ((max($lightnessValues) - min($lightnessValues)) < 0.24) {
            $score -= 8;
            $warnings[] = 'The role colors have limited lightness range; neutral scale and variants provide the needed dark and light values.';
        }

        return round(max(0, min(100, $score)), 2);
    }

    /**
     * @return array<string, float>
     */
    private function hexToOklch(string $hex): array
    {
        return $this->oklabToOklch($this->linearRgbToOklab($this->rgbToLinearRgb($this->hexToRgb($hex))));
    }

    private function oklchVariant(string $hex, float $lightness, float $chromaMultiplier = 1.0, ?float $maxChroma = null): string
    {
        $oklch = $this->hexToOklch($hex);
        $chroma = $oklch['c'] * $chromaMultiplier;

        if ($maxChroma !== null) {
            $chroma = min($chroma, $maxChroma);
        }

        return $this->oklchToHex($lightness, $chroma, $oklch['h']);
    }

    private function oklchToHex(float $lightness, float $chroma, float $hue): string
    {
        $lightness = $this->clamp($lightness);
        $chroma = max(0, $chroma);

        for ($attempt = 0; $attempt < 18; $attempt++) {
            $oklab = $this->oklchToOklab($lightness, $chroma, $hue);
            $linearRgb = $this->oklabToLinearRgb($oklab);

            if ($this->isLinearRgbInGamut($linearRgb)) {
                return $this->rgbToHex($this->linearRgbToRgb($linearRgb));
            }

            $chroma *= 0.88;
        }

        return $this->rgbToHex($this->linearRgbToRgb($this->oklabToLinearRgb($this->oklchToOklab($lightness, 0, $hue))));
    }

    /**
     * @param  array{r: int, g: int, b: int}  $rgb
     * @return array{r: float, g: float, b: float}
     */
    private function rgbToLinearRgb(array $rgb): array
    {
        return [
            'r' => $this->srgbChannelToLinear($rgb['r'] / 255),
            'g' => $this->srgbChannelToLinear($rgb['g'] / 255),
            'b' => $this->srgbChannelToLinear($rgb['b'] / 255),
        ];
    }

    /**
     * @param  array{r: float, g: float, b: float}  $linearRgb
     * @return array{l: float, a: float, b: float}
     */
    private function linearRgbToOklab(array $linearRgb): array
    {
        $long = (0.4122214708 * $linearRgb['r']) + (0.5363325363 * $linearRgb['g']) + (0.0514459929 * $linearRgb['b']);
        $medium = (0.2119034982 * $linearRgb['r']) + (0.6806995451 * $linearRgb['g']) + (0.1073969566 * $linearRgb['b']);
        $short = (0.0883024619 * $linearRgb['r']) + (0.2817188376 * $linearRgb['g']) + (0.6299787005 * $linearRgb['b']);
        $longRoot = $this->cubeRoot($long);
        $mediumRoot = $this->cubeRoot($medium);
        $shortRoot = $this->cubeRoot($short);

        return [
            'l' => (0.2104542553 * $longRoot) + (0.7936177850 * $mediumRoot) - (0.0040720468 * $shortRoot),
            'a' => (1.9779984951 * $longRoot) - (2.4285922050 * $mediumRoot) + (0.4505937099 * $shortRoot),
            'b' => (0.0259040371 * $longRoot) + (0.7827717662 * $mediumRoot) - (0.8086757660 * $shortRoot),
        ];
    }

    /**
     * @param  array{l: float, a: float, b: float}  $oklab
     * @return array{l: float, c: float, h: float}
     */
    private function oklabToOklch(array $oklab): array
    {
        $hue = rad2deg(atan2($oklab['b'], $oklab['a']));

        return [
            'l' => $oklab['l'],
            'c' => sqrt(($oklab['a'] ** 2) + ($oklab['b'] ** 2)),
            'h' => $hue < 0 ? $hue + 360 : $hue,
        ];
    }

    /**
     * @return array{l: float, a: float, b: float}
     */
    private function oklchToOklab(float $lightness, float $chroma, float $hue): array
    {
        $hueRadians = deg2rad(fmod(fmod($hue, 360) + 360, 360));

        return [
            'l' => $lightness,
            'a' => cos($hueRadians) * $chroma,
            'b' => sin($hueRadians) * $chroma,
        ];
    }

    /**
     * @param  array{l: float, a: float, b: float}  $oklab
     * @return array{r: float, g: float, b: float}
     */
    private function oklabToLinearRgb(array $oklab): array
    {
        $longRoot = $oklab['l'] + (0.3963377774 * $oklab['a']) + (0.2158037573 * $oklab['b']);
        $mediumRoot = $oklab['l'] - (0.1055613458 * $oklab['a']) - (0.0638541728 * $oklab['b']);
        $shortRoot = $oklab['l'] - (0.0894841775 * $oklab['a']) - (1.2914855480 * $oklab['b']);
        $long = $longRoot ** 3;
        $medium = $mediumRoot ** 3;
        $short = $shortRoot ** 3;

        return [
            'r' => (4.0767416621 * $long) - (3.3077115913 * $medium) + (0.2309699292 * $short),
            'g' => (-1.2684380046 * $long) + (2.6097574011 * $medium) - (0.3413193965 * $short),
            'b' => (-0.0041960863 * $long) - (0.7034186147 * $medium) + (1.7076147010 * $short),
        ];
    }

    /**
     * @param  array{r: float, g: float, b: float}  $linearRgb
     * @return array{r: int, g: int, b: int}
     */
    private function linearRgbToRgb(array $linearRgb): array
    {
        return [
            'r' => (int) round($this->linearChannelToSrgb($this->clamp($linearRgb['r'])) * 255),
            'g' => (int) round($this->linearChannelToSrgb($this->clamp($linearRgb['g'])) * 255),
            'b' => (int) round($this->linearChannelToSrgb($this->clamp($linearRgb['b'])) * 255),
        ];
    }

    /**
     * @param  array{r: float, g: float, b: float}  $linearRgb
     */
    private function isLinearRgbInGamut(array $linearRgb): bool
    {
        return $linearRgb['r'] >= 0 && $linearRgb['r'] <= 1
            && $linearRgb['g'] >= 0 && $linearRgb['g'] <= 1
            && $linearRgb['b'] >= 0 && $linearRgb['b'] <= 1;
    }

    /**
     * @param  array{r: float, g: float, b: float}  $channels
     * @return array{r: float, g: float, b: float}
     */
    private function roundChannels(array $channels): array
    {
        return [
            'r' => round($channels['r'], 5),
            'g' => round($channels['g'], 5),
            'b' => round($channels['b'], 5),
        ];
    }

    private function srgbChannelToLinear(float $channel): float
    {
        return $channel <= 0.04045
            ? $channel / 12.92
            : (($channel + 0.055) / 1.055) ** 2.4;
    }

    private function linearChannelToSrgb(float $channel): float
    {
        return $channel <= 0.0031308
            ? $channel * 12.92
            : (1.055 * ($channel ** (1 / 2.4))) - 0.055;
    }

    /**
     * @param  array{r: int, g: int, b: int}  $rgb
     */
    private function relativeLuminance(array $rgb): float
    {
        $linearRgb = $this->rgbToLinearRgb($rgb);

        return (0.2126 * $linearRgb['r']) + (0.7152 * $linearRgb['g']) + (0.0722 * $linearRgb['b']);
    }

    /**
     * @param  array{r: int, g: int, b: int}  $rgb
     */
    private function rgbToHex(array $rgb): string
    {
        return sprintf('#%02X%02X%02X', $rgb['r'], $rgb['g'], $rgb['b']);
    }

    private function hueToRgb(float $p, float $q, float $t): float
    {
        if ($t < 0) {
            $t += 1;
        }

        if ($t > 1) {
            $t -= 1;
        }

        if ($t < 1 / 6) {
            return $p + (($q - $p) * 6 * $t);
        }

        if ($t < 1 / 2) {
            return $q;
        }

        if ($t < 2 / 3) {
            return $p + (($q - $p) * ((2 / 3) - $t) * 6);
        }

        return $p;
    }

    private function primaryScore(array $entry, int $sourceCount): float
    {
        $metrics = $entry['analysis'];
        $oklch = $metrics['oklch'];
        $lightnessScore = 1 - min(1, abs($oklch['l'] - 0.54) * 2.6);
        $chromaScore = 1 - min(1, abs($oklch['c'] - 0.12) * 5.0);
        $contrastPotential = max($metrics['contrast_white'], $metrics['contrast_black']) / 10;
        $semanticPenalty = ($this->isRedHue($oklch['h']) || $this->isWarningHue($oklch['h'])) && $sourceCount > 1 ? 1.2 : 0;
        $supportHuePenalty = $this->isGreenHue($oklch['h']) && $sourceCount > 1 ? 0.45 : 0;
        $blueTrustBonus = $this->isBlueHue($oklch['h']) ? 0.45 : 0;
        $neonPenalty = in_array('neon', $metrics['classification'], true) ? 1.1 : 0;
        $palePenalty = $oklch['l'] > 0.82 ? 1.4 : 0;
        $neutralPenalty = in_array('neutral-like', $metrics['classification'], true) ? 0.9 : 0;
        $brandBonus = ($entry['must_use'] || $entry['user_locked']) ? 0.25 : 0;

        return ($lightnessScore * 2.4) + ($chromaScore * 1.3) + $contrastPotential + $brandBonus + $blueTrustBonus - $semanticPenalty - $supportHuePenalty - $neonPenalty - $palePenalty - $neutralPenalty;
    }

    private function accentScore(array $entry, float $primaryHue): float
    {
        $metrics = $entry['analysis'];
        $oklch = $metrics['oklch'];
        $distanceScore = min(1.8, $this->hueDistance($oklch['h'], $primaryHue) / 75);
        $vividScore = min(1.3, $oklch['c'] * 7);
        $redPenalty = $this->isRedHue($oklch['h']) ? 0.75 : 0;
        $neonPenalty = in_array('neon', $metrics['classification'], true) ? 0.35 : 0;

        return $distanceScore + $vividScore - $redPenalty - $neonPenalty;
    }

    private function supportScore(array $entry): float
    {
        $metrics = $entry['analysis'];
        $oklch = $metrics['oklch'];
        $calmChroma = 1 - min(1, abs($oklch['c'] - 0.085) * 7);
        $lightness = 1 - min(1, abs($oklch['l'] - 0.58) * 2.2);
        $greenBonus = $this->isGreenHue($oklch['h']) ? 0.35 : 0;
        $redPenalty = $this->isRedHue($oklch['h']) ? 0.75 : 0;

        return $calmChroma + $lightness + $greenBonus - $redPenalty;
    }

    private function decorativeScore(array $entry): float
    {
        $metrics = $entry['analysis'];
        $oklch = $metrics['oklch'];

        return ($this->isRedHue($oklch['h']) ? 2.4 : 0)
            + ($this->isWarningHue($oklch['h']) ? 0.8 : 0)
            + ($oklch['c'] > 0.16 ? 0.9 : 0)
            + (in_array('neon', $metrics['classification'], true) ? 1.0 : 0)
            + ($oklch['l'] > 0.78 || $oklch['l'] < 0.32 ? 0.45 : 0);
    }

    /**
     * @param  array{l: float, c: float, h: float}  $oklch
     */
    private function isRiskyPrimary(array $oklch): bool
    {
        return $oklch['l'] > 0.82
            || $oklch['c'] < 0.025
            || $oklch['c'] > 0.26
            || $this->isRedHue($oklch['h'])
            || $this->isWarningHue($oklch['h']);
    }

    private function isRedHue(float $hue): bool
    {
        return $hue <= 24 || $hue >= 350;
    }

    private function isWarningHue(float $hue): bool
    {
        return $hue > 24 && $hue < 85;
    }

    private function isGreenHue(float $hue): bool
    {
        return $hue >= 118 && $hue <= 170;
    }

    private function isBlueHue(float $hue): bool
    {
        return $hue >= 220 && $hue <= 285;
    }

    private function cubeRoot(float $value): float
    {
        return $value < 0 ? -((-1 * $value) ** (1 / 3)) : $value ** (1 / 3);
    }

    private function clamp(float $value): float
    {
        return min(1, max(0, $value));
    }

    private function cacheKey(int $homepageId): string
    {
        return "homepage-color-schemes.{$homepageId}.active-css";
    }
}
