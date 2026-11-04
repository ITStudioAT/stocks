<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Symfony\Component\Console\Input\ArgvInput;

define('LARAVEL_START', microtime(true));

require getcwd().'/vendor/autoload.php';

// The staged command lets an older live release use the corrected Pull workflow.
if (is_file(__DIR__.'/CloudwaysPullCommand.php')) {
    require __DIR__.'/CloudwaysApiClient.php';
    require __DIR__.'/CloudwaysPullCommand.php';
}

/** @var Application $app */
$app = require getcwd().'/bootstrap/app.php';

exit($app->handleCommand(new ArgvInput));
