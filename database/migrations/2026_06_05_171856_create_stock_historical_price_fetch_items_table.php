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
        if (Schema::hasTable('stock_historical_price_fetch_items')) {
            return;
        }

        Schema::create('stock_historical_price_fetch_items', function (Blueprint $table) {
            $table->id();
            $table->string('fetch_run_id')->index();
            $table->foreignId('stock_holding_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('queued')->index();
            $table->date('date_from');
            $table->date('date_to');
            $table->unsignedInteger('stored_count')->default(0);
            $table->string('error_message')->nullable();
            $table->timestamps();

            $table->foreign('fetch_run_id', 'shpfi_run_fk')
                ->references('id')
                ->on('stock_historical_price_fetch_runs')
                ->cascadeOnDelete();
            $table->index(['fetch_run_id', 'stock_holding_id'], 'shpfi_run_holding_idx');
            $table->index(['stock_holding_id', 'date_from', 'date_to', 'status'], 'shpfi_holding_dates_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_historical_price_fetch_items');
    }
};
