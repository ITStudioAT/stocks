<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_login_codes', function (Blueprint $table) {
            $table->unsignedTinyInteger('attempts')->default(0)->after('code_hash');
            $table->index(
                ['email', 'consumed_at', 'expires_at'],
                'admin_login_codes_active_lookup',
            );
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Rolling back login-code attempt state would reset security lockouts.');
    }
};
