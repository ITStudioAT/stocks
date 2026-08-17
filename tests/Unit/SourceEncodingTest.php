<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class SourceEncodingTest extends TestCase
{
    public function test_project_source_files_are_utf8_without_mojibake_markers(): void
    {
        $roots = ['app', 'resources', 'tests', 'database', 'config', 'routes'];
        $extensions = ['php', 'vue', 'js', 'json', 'css', 'md', 'yml', 'yaml'];
        $mojibakeMarkers = [
            "\u{00C3}",
            "\u{00C2}",
            "\u{FFFD}",
            "\u{00E2}\u{0080}",
        ];
        $problems = [];

        foreach ($roots as $root) {
            $directory = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.$root;
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

            foreach ($files as $file) {
                if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                    continue;
                }

                if (! in_array(strtolower($file->getExtension()), $extensions, true)) {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());

                if (! is_string($contents) || preg_match('//u', $contents) !== 1) {
                    $problems[] = $file->getPathname().': invalid UTF-8';

                    continue;
                }

                foreach ($mojibakeMarkers as $marker) {
                    if (str_contains($contents, $marker)) {
                        $problems[] = $file->getPathname().': likely double-encoded UTF-8';

                        break;
                    }
                }
            }
        }

        $this->assertSame([], $problems, implode(PHP_EOL, $problems));
    }
}
