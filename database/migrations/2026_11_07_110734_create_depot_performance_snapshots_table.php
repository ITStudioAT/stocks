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
        Schema::create('depot_performance_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('depot_id')->constrained()->cascadeOnDelete();
            $table->string('period', 8);
            $table->date('end_date');
            $table->json('payload');
            $table->timestamp('expires_at')->nullable();
            $table->unique(['depot_id', 'period', 'end_date'], 'depot_performance_period_end_unique');
            $table->index(['depot_id', 'end_date'], 'depot_performance_end_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('depot_performance_snapshots');
    }
};
