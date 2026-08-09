<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('password_initialized_at')->nullable()->after('auth_revision');
        });

        DB::table('users')
            ->whereNull('password_initialized_at')
            ->update(['password_initialized_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Rolling back password-initialization state is not supported.');
    }
};
