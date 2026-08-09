<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PasswordInitializationMigrationTest extends TestCase
{
    public function test_migration_backfills_existing_users_but_leaves_future_users_uninitialized(): void
    {
        $originalConnection = DB::getDefaultConnection();
        $migrationConnection = 'password_initialization_migration_test';
        config()->set("database.connections.{$migrationConnection}", [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge($migrationConnection);
        DB::setDefaultConnection($migrationConnection);

        try {
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
                $table->string('email')->unique();
                $table->string('password');
                $table->unsignedBigInteger('auth_revision')->default(1);
            });

            DB::table('users')->insert([
                'email' => 'existing@example.com',
                'password' => 'existing-hash',
            ]);

            $migration = require database_path('migrations/2026_08_09_135847_add_password_initialized_at_to_users_table.php');
            $migration->up();

            $this->assertNotNull(DB::table('users')->where('email', 'existing@example.com')->value('password_initialized_at'));

            DB::table('users')->insert([
                'email' => 'new@example.com',
                'password' => 'new-random-hash',
            ]);

            $this->assertNull(DB::table('users')->where('email', 'new@example.com')->value('password_initialized_at'));
        } finally {
            DB::setDefaultConnection($originalConnection);
            DB::purge($migrationConnection);
        }
    }
}
