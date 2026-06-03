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
        Schema::create('stock_price_quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_holding_id')->constrained()->cascadeOnDelete();
            $table->string('source_key')->index();
            $table->string('source_name');
            $table->text('source_url');
            $table->string('source_quality')->index();
            $table->string('venue')->nullable()->index();
            $table->string('mic')->nullable()->index();
            $table->string('isin', 12)->nullable()->index();
            $table->string('wkn', 6)->nullable();
            $table->string('symbol')->nullable();
            $table->char('currency', 3)->nullable()->index();
            $table->decimal('bid', 20, 8)->nullable();
            $table->decimal('ask', 20, 8)->nullable();
            $table->decimal('last', 20, 8)->nullable();
            $table->decimal('close', 20, 8)->nullable();
            $table->decimal('nav', 20, 8)->nullable();
            $table->decimal('price', 20, 8)->nullable();
            $table->string('price_type')->default('unavailable')->index();
            $table->decimal('spread_abs', 20, 8)->nullable();
            $table->decimal('spread_pct', 12, 6)->nullable();
            $table->timestamp('as_of')->nullable()->index();
            $table->timestamp('fetched_at')->index();
            $table->string('freshness_status')->default('unavailable')->index();
            $table->string('validation_status')->default('invalid')->index();
            $table->json('validation_errors')->nullable();
            $table->string('raw_text_hash')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->index(['stock_holding_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_price_quotes');
    }
};
