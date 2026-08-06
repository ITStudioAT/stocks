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
        Schema::table('index_eodhd_sync_runs', function (Blueprint $table) {
            $table->json('index_progress')->nullable()->after('steps');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('index_eodhd_sync_runs', function (Blueprint $table) {
            $table->dropColumn('index_progress');
        });
    }
};
