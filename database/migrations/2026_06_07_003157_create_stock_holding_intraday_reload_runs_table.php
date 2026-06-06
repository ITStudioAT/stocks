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
        Schema::create('stock_holding_intraday_reload_runs', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('stock_holding_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('finished')->index();
            $table->date('date_from');
            $table->date('date_to');
            $table->unsignedInteger('stored_count')->default(0);
            $table->string('message')->nullable();
            $table->json('error_summary')->nullable();
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
        Schema::dropIfExists('stock_holding_intraday_reload_runs');
    }
};
