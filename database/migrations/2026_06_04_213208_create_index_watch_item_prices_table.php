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
        Schema::create('index_watch_item_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('index_watch_item_id')->constrained()->cascadeOnDelete();
            $table->date('trading_date');
            $table->decimal('start_price', 20, 8)->nullable();
            $table->decimal('actual_price', 20, 8)->nullable();
            $table->decimal('last_price', 20, 8)->nullable();
            $table->timestamp('actual_price_as_of')->nullable();
            $table->timestamp('last_price_as_of')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['index_watch_item_id', 'trading_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('index_watch_item_prices');
    }
};
