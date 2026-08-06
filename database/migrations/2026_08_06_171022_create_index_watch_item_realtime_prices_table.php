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
        Schema::create('index_watch_item_realtime_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('index_watch_item_id')->constrained()->cascadeOnDelete();
            $table->date('trading_date');
            $table->decimal('price', 20, 8);
            $table->decimal('start_price', 20, 8)->nullable();
            $table->decimal('previous_close', 20, 8)->nullable();
            $table->decimal('change_percent', 12, 6)->nullable();
            $table->string('currency', 8)->nullable();
            $table->timestamp('as_of');
            $table->string('source_name')->default('EODHD real-time');
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(
                ['index_watch_item_id', 'as_of'],
                'index_watch_item_realtime_unique',
            );
            $table->index(
                ['index_watch_item_id', 'trading_date', 'as_of'],
                'index_watch_item_realtime_date_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('index_watch_item_realtime_prices');
    }
};
