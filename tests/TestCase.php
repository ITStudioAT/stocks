<?php

namespace Tests;

use App\Models\User;
use App\Services\AdminSessionManager;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;
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

        $this->withoutVite();
    }

    public function actingAs(UserContract $user, mixed $guard = null): static
    {
        parent::actingAs($user, $guard);

        $guardName = is_string($guard) ? $guard : config('auth.defaults.guard');

        if ($user instanceof User && $guardName === 'web') {
            $this->withSession([
                AdminSessionManager::TargetRevisionSessionKey => $user->auth_revision,
            ]);
        }

        return $this;
    }
}
