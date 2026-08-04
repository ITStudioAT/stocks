#!/usr/bin/env php
<?php

declare(strict_types=1);

$repositoryRoot = realpath(__DIR__.'/..');

if ($repositoryRoot === false) {
    fwrite(STDERR, "Unable to resolve repository root.\n");
    exit(1);
}

chdir($repositoryRoot);

$trackedFiles = shell_exec('git ls-files -z --cached --others --exclude-standard');

if (! is_string($trackedFiles)) {
    fwrite(STDERR, "Unable to enumerate source files via git ls-files.\n");
    exit(1);
}

$knownBinaryExtensions = [
    '7z',
    'avif',
    'bin',
    'class',
    'dll',
    'doc',
    'docx',
    'eot',
    'exe',
    'gif',
    'gz',
    'ico',
    'jpeg',
    'jpg',
    'mp3',
    'mp4',
    'otf',
    'pdf',
    'phar',
    'png',
    'ppt',
    'pptx',
    'psd',
    'rar',
    'so',
    'sqlite',
    'ttf',
    'wav',
    'webm',
    'webp',
    'woff',
    'woff2',
    'xls',
    'xlsx',
    'zip',
];

$bomViolations = [];
$utf16Violations = [];
$utf8Violations = [];

foreach (array_filter(explode("\0", $trackedFiles)) as $relativePath) {
    $fullPath = $repositoryRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

    if (! is_file($fullPath)) {
        continue;
    }

    $contents = file_get_contents($fullPath);

    if (! is_string($contents) || ! shouldCheckEncoding($relativePath, $contents, $knownBinaryExtensions)) {
        continue;
    }

    if (str_starts_with($contents, "\xEF\xBB\xBF")) {
        $bomViolations[] = $relativePath;
    }

    if (str_starts_with($contents, "\xFF\xFE") || str_starts_with($contents, "\xFE\xFF")) {
        $utf16Violations[] = $relativePath;

        continue;
    }

    if (! mb_check_encoding($contents, 'UTF-8')) {
        $utf8Violations[] = $relativePath;
    }
}

if ($bomViolations === [] && $utf16Violations === [] && $utf8Violations === []) {
    fwrite(STDOUT, "Encoding check passed: source text files are UTF-8 without BOM.\n");
    exit(0);
}

fwrite(STDERR, "Encoding check failed.\n");

foreach ([
    'Files with UTF-8 BOM' => $bomViolations,
    'Files with UTF-16 BOM' => $utf16Violations,
    'Files that are not valid UTF-8' => $utf8Violations,
] as $heading => $paths) {
    if ($paths === []) {
        continue;
    }

    fwrite(STDERR, "\n{$heading}:\n");

    foreach ($paths as $path) {
        fwrite(STDERR, " - {$path}\n");
    }
}

exit(1);

/** @param array<int, string> $knownBinaryExtensions */
function shouldCheckEncoding(string $relativePath, string $contents, array $knownBinaryExtensions): bool
{
    $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));

    if ($extension !== '' && in_array($extension, $knownBinaryExtensions, true)) {
        return false;
    }

    return preg_match('/\x00/', substr($contents, 0, 8192)) !== 1;
}
