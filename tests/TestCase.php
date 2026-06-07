<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        if (file_exists(dirname(__DIR__).'/bootstrap/cache/config.php')) {
            throw new RuntimeException(
                'Refusing to run tests while Laravel configuration is cached. Run "php artisan config:clear" first so PHPUnit can use its isolated test database.',
            );
        }

        parent::setUp();
    }
}
