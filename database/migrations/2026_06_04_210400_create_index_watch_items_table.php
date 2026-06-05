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
        Schema::create('index_watch_items', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 32);
            $table->string('name')->nullable();
            $table->string('isin', 12)->nullable();
            $table->string('wkn', 6)->nullable();
            $table->string('exchange')->nullable();
            $table->string('mic_code', 32)->nullable();
            $table->string('instrument_type')->nullable();
            $table->string('country')->nullable();
            $table->string('currency', 8)->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique('isin');
            $table->unique('wkn');
            $table->index(['symbol', 'exchange']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('index_watch_items');
    }
};
