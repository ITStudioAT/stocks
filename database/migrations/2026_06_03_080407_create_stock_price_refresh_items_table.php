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
        Schema::create('stock_price_refresh_items', function (Blueprint $table) {
            $table->id();
            $table->string('refresh_run_id')->index();
            $table->foreignId('stock_holding_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('queued')->index();
            $table->json('attempted_sources')->nullable();
            $table->foreignId('selected_quote_id')->nullable()->constrained('stock_price_quotes')->nullOnDelete();
            $table->string('error_message')->nullable();
            $table->timestamps();

            $table->index(['refresh_run_id', 'stock_holding_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_price_refresh_items');
    }
};
