<?php

declare(strict_types=1);

/** Standalone gate before Composer, maintenance mode, Git pull or migrations. */
function stocksPreviewInstance(string $root): bool
{
    if (file_exists($root.'/storage/framework/stocks-preview-instance')
        || getenv('APP_ENV') === 'preview'
        || in_array(strtolower((string) getenv('STOCKS_PREVIEW')), ['true', '1', '(true)'], true)) {
        return true;
    }
    if (is_file($root.'/.env')) {
        foreach (file($root.'/.env', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^\s*(?:export\s+)?(APP_ENV|STOCKS_PREVIEW)\s*=\s*[\x22\x27]?(preview|true|1|\(true\))[\x22\x27]?\s*(?:#.*)?$/iD', $line)) {
                return true;
            }
        }
    }
    if (is_file($root.'/bootstrap/cache/config.php')) {
        $configuration = require $root.'/bootstrap/cache/config.php';
        if (($configuration['app']['env'] ?? null) === 'preview' || ($configuration['security']['preview']['enabled'] ?? false)) {
            return true;
        }
    }

    return false;
}

if (stocksPreviewInstance(dirname(__DIR__))) {
    fwrite(STDERR, "The live deployment workflow is disabled on a Stocks preview instance. Use the reviewed preview installation plan.\n");
    exit(1);
}
