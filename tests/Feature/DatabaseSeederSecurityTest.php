<?php

namespace Tests\Feature;

use App\Models\AdminLoginCode;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DatabaseSeederSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_an_uninitialized_protected_admin_without_using_legacy_sa_pw(): void
    {
        config()->set([
            'stocks.protected_admin.email' => 'bootstrap@example.com',
            'stocks.protected_admin.first_name' => 'Bootstrap',
            'stocks.protected_admin.last_name' => 'Administrator',
            'services.super_admin.password' => 'legacy-master-password',
        ]);
        AdminLoginCode::query()->create([
            'email' => 'bootstrap@example.com',
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'bootstrap@example.com')->firstOrFail();

        $this->assertTrue($user->is_protected);
        $this->assertNull($user->password_initialized_at);
        $this->assertFalse(Hash::check('legacy-master-password', $user->password));
        $this->assertSame(['admin', 'super_admin'], $user->getRoleNames()->sort()->values()->all());
        $this->assertFalse(AdminLoginCode::query()->whereNull('consumed_at')->where('email', $user->email)->exists());
    }
}
