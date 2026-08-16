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
        Schema::create('stock_ai_researches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_holding_id')->constrained()->cascadeOnDelete();
            $table->string('previous_research_id')->nullable();
            $table->string('status')->default('queued')->index();
            $table->boolean('has_material_update')->nullable();
            $table->text('summary')->nullable();
            $table->text('stronger_case')->nullable();
            $table->text('weaker_case')->nullable();
            $table->text('trump_connection')->nullable();
            $table->string('recommendation', 16)->nullable();
            $table->text('justification')->nullable();
            $table->json('known_information')->nullable();
            $table->string('message')->nullable();
            $table->string('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('previous_research_id')
                ->references('id')
                ->on('stock_ai_researches')
                ->nullOnDelete();
            $table->index(['user_id', 'stock_holding_id', 'created_at'], 'stock_ai_research_user_stock_created_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_ai_researches');
    }
};
