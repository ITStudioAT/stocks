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
        Schema::table('website_analyses', function (Blueprint $table) {
            $table->string('analysis_step')->default('pending')->after('status')->index();
            $table->unsignedInteger('reachability_checked_count')->default(0)->after('assets_count');
            $table->unsignedInteger('reachability_total_count')->default(0)->after('reachability_checked_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('website_analyses', function (Blueprint $table) {
            $table->dropColumn([
                'analysis_step',
                'reachability_checked_count',
                'reachability_total_count',
            ]);
        });
    }
};
