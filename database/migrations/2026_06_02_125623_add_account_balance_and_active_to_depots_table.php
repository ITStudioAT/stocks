<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('depots', function (Blueprint $table) {
            $table->decimal('account_balance', 15, 2)->default(0)->after('name');
            $table->boolean('is_active')->default(false)->after('account_balance');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('depots', function (Blueprint $table) {
            $table->dropIndex(['is_active']);
            $table->dropColumn(['account_balance', 'is_active']);
        });
    }
};
