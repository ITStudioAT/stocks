<?php

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class SecurityStateMigrationRollbackTest extends TestCase
{
    public function test_security_state_migrations_refuse_rollback_without_losing_state(): void
    {
        $originalConnection = DB::getDefaultConnection();
        $migrationConnection = 'security_state_migration_rollback_test';
        config()->set("database.connections.{$migrationConnection}", [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge($migrationConnection);
        DB::setDefaultConnection($migrationConnection);

        try {
            $this->createBaseTables();

            $attemptsMigration = require database_path('migrations/2026_08_09_120000_add_attempts_to_admin_login_codes_table.php');
            $protectedMigration = require database_path('migrations/2026_08_09_123509_add_is_protected_to_users_table.php');
            $revisionMigration = require database_path('migrations/2026_08_09_132042_add_auth_revision_to_users_table.php');
            $initializationMigration = require database_path('migrations/2026_08_09_135847_add_password_initialized_at_to_users_table.php');

            $attemptsMigration->up();
            $protectedMigration->up();
            $revisionMigration->up();
            $initializationMigration->up();

            DB::table('users')->where('email', 'protected@example.com')->update([
                'is_protected' => true,
                'auth_revision' => 7,
                'password_initialized_at' => null,
            ]);
            DB::table('admin_login_codes')->insert([
                'email' => 'protected@example.com',
                'code_hash' => 'opaque-hash',
                'attempts' => 4,
                'expires_at' => now()->addMinutes(10),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->assertRollbackRefused(
                $attemptsMigration,
                'Rolling back login-code attempt state would reset security lockouts.',
            );
            $this->assertTrue(Schema::hasColumn('admin_login_codes', 'attempts'));
            $this->assertSame(4, DB::table('admin_login_codes')->value('attempts'));

            $this->assertRollbackRefused(
                $protectedMigration,
                'Rolling back protected-account security state is not supported.',
            );
            $this->assertTrue(Schema::hasColumn('users', 'is_protected'));
            $this->assertSame(1, DB::table('users')->value('is_protected'));

            $this->assertRollbackRefused(
                $revisionMigration,
                'Rolling back authentication revisions would revive revoked sessions.',
            );
            $this->assertTrue(Schema::hasColumn('users', 'auth_revision'));
            $this->assertSame(7, DB::table('users')->value('auth_revision'));

            $this->assertRollbackRefused(
                $initializationMigration,
                'Rolling back password-initialization state is not supported.',
            );
            $this->assertTrue(Schema::hasColumn('users', 'password_initialized_at'));
            $this->assertNull(DB::table('users')->value('password_initialized_at'));
        } finally {
            DB::setDefaultConnection($originalConnection);
            DB::purge($migrationConnection);
        }
    }

    private function createBaseTables(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
            $table->string('password');
        });

        Schema::create('admin_login_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->index();
            $table->string('code_hash');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });

        DB::table('users')->insert([
            'email' => 'protected@example.com',
            'password' => 'opaque-hash',
        ]);
    }

    private function assertRollbackRefused(Migration $migration, string $expectedMessage): void
    {
        try {
            $migration->down();
            $this->fail('The security-state migration unexpectedly allowed rollback.');
        } catch (RuntimeException $exception) {
            $this->assertSame($expectedMessage, $exception->getMessage());
        }
    }
}
