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
        Schema::create('stock_holding_source_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_holding_id')->constrained()->cascadeOnDelete();
            $table->string('source_key')->index();
            $table->text('source_url');
            $table->string('venue')->nullable();
            $table->string('mic')->nullable();
            $table->string('parser_key')->index();
            $table->unsignedSmallInteger('confidence_score')->default(0);
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->boolean('active')->default(true)->index();
            $table->boolean('verified')->default(false)->index();
            $table->timestamps();

            $table->index(['stock_holding_id', 'source_key', 'parser_key'], 'holding_source_parser_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_holding_source_candidates');
    }
};
