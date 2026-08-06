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
        Schema::create('index_eodhd_sync_runs', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('status')->default('queued')->index();
            $table->string('stage')->default('check_indices');
            $table->unsignedInteger('total_indices')->default(0);
            $table->unsignedInteger('processed_indices')->default(0);
            $table->date('date_from');
            $table->date('date_to');
            $table->unsignedInteger('eod_missing_count')->default(0);
            $table->unsignedInteger('eod_synced_count')->default(0);
            $table->unsignedInteger('intraday_missing_count')->default(0);
            $table->unsignedInteger('intraday_synced_count')->default(0);
            $table->unsignedInteger('unsupported_intraday_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->string('current')->nullable();
            $table->json('steps');
            $table->json('summary')->nullable();
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
        Schema::dropIfExists('index_eodhd_sync_runs');
    }
};
