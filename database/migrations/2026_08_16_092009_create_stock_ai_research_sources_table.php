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
        Schema::create('stock_ai_research_sources', function (Blueprint $table) {
            $table->id();
            $table->string('stock_ai_research_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_holding_id')->constrained()->cascadeOnDelete();
            $table->text('url');
            $table->string('url_hash', 64);
            $table->string('title')->nullable();
            $table->timestamps();

            $table->foreign('stock_ai_research_id')
                ->references('id')
                ->on('stock_ai_researches')
                ->cascadeOnDelete();
            $table->unique(['user_id', 'stock_holding_id', 'url_hash'], 'stock_ai_source_user_stock_url_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_ai_research_sources');
    }
};
