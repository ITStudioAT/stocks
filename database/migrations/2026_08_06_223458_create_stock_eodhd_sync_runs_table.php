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
        Schema::create('stock_eodhd_sync_runs', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('status')->default('queued')->index();
            $table->string('stage')->default('eod');
            $table->string('intraday_reload_run_id')->nullable()->index();
            $table->date('date_from');
            $table->date('date_to');
            $table->unsignedInteger('eod_requested_count')->default(0);
            $table->unsignedInteger('eod_stored_count')->default(0);
            $table->unsignedInteger('eod_skipped_count')->default(0);
            $table->unsignedInteger('eod_failed_count')->default(0);
            $table->unsignedInteger('intraday_total_count')->default(0);
            $table->unsignedInteger('intraday_processed_count')->default(0);
            $table->unsignedInteger('intraday_stored_count')->default(0);
            $table->unsignedInteger('intraday_success_count')->default(0);
            $table->unsignedInteger('intraday_failed_count')->default(0);
            $table->string('current')->nullable();
            $table->json('steps');
            $table->string('message')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_eodhd_sync_runs');
    }
};
