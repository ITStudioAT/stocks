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
        Schema::create('depot_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('depot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_holding_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->decimal('pieces', 20, 8)->nullable();
            $table->decimal('total_amount', 15, 2);
            $table->decimal('unit_price', 20, 8)->nullable();
            $table->decimal('cash_delta', 15, 2);
            $table->decimal('balance_after', 15, 2);
            $table->timestamp('booked_at');
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['depot_id', 'booked_at']);
            $table->index(['depot_id', 'stock_holding_id']);
            $table->index('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('depot_transactions');
    }
};
