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
        Schema::table('depot_transactions', function (Blueprint $table) {
            $table->dropColumn('is_estimated_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('depot_transactions', function (Blueprint $table) {
            $table->boolean('is_estimated_date')->default(false)->after('affects_performance');
        });
    }
};
