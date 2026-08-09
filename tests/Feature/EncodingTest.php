<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class EncodingTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_admin_name_preserves_utf8_characters(): void
    {
        config()->set([
            'stocks.protected_admin.email' => 'protected@example.com',
            'stocks.protected_admin.first_name' => 'Günther',
            'stocks.protected_admin.last_name' => 'Kron',
        ]);

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('users', [
            'email' => 'protected@example.com',
            'last_name' => 'Kron',
            'first_name' => 'Günther',
            'is_protected' => true,
        ]);
    }

    public function test_project_text_files_do_not_contain_common_mojibake_sequences(): void
    {
        $mojibakePatterns = [
            "\xC3\x83",
            "\xC3\x82",
            "\xC3\xA2\xE2\x82\xAC\xE2\x84\xA2",
            "\xC3\xA2\xE2\x82\xAC\xC5\x93",
            "\xC3\xA2\xE2\x82\xAC",
            "\xEF\xBF\xBD",
        ];

        $files = collect(File::allFiles(base_path()))
            ->reject(fn ($file): bool => str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR))
            ->reject(fn ($file): bool => str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'node_modules'.DIRECTORY_SEPARATOR))
            ->reject(fn ($file): bool => str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR))
            ->filter(fn ($file): bool => in_array($file->getExtension(), [
                'css',
                'js',
                'json',
                'md',
                'php',
                'toml',
                'vue',
                'xml',
                'yml',
                'yaml',
            ], true));

        $offenders = [];

        foreach ($files as $file) {
            $contents = File::get($file->getPathname());

            foreach ($mojibakePatterns as $pattern) {
                if (str_contains($contents, $pattern)) {
                    $offenders[] = $file->getRelativePathname().' contains '.bin2hex($pattern);
                }
            }
        }

        $this->assertSame([], $offenders);
    }
}
