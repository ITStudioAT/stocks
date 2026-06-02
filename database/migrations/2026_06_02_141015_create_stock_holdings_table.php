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
        Schema::create('stock_holdings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('depot_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('isin', 12)->nullable();
            $table->string('wkn', 6)->nullable();
            $table->timestamps();

            $table->index(['depot_id', 'name']);
            $table->unique(['depot_id', 'isin']);
            $table->unique(['depot_id', 'wkn']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_holdings');
    }
};
