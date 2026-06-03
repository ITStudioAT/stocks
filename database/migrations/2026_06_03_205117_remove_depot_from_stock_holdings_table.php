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
        Schema::table('stock_holdings', function (Blueprint $table) {
            $table->dropForeign(['depot_id']);
            $table->dropUnique(['depot_id', 'isin']);
            $table->dropUnique(['depot_id', 'wkn']);
            $table->dropIndex(['depot_id', 'name']);
            $table->dropIndex(['depot_id', 'symbol']);
            $table->dropColumn('depot_id');

            $table->index('name');
            $table->index('symbol');
            $table->unique('isin');
            $table->unique('wkn');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_holdings', function (Blueprint $table) {
            $table->dropUnique(['isin']);
            $table->dropUnique(['wkn']);
            $table->dropIndex(['name']);
            $table->dropIndex(['symbol']);

            $table->foreignId('depot_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->nullOnDelete();

            $table->index(['depot_id', 'name']);
            $table->index(['depot_id', 'symbol']);
            $table->unique(['depot_id', 'isin']);
            $table->unique(['depot_id', 'wkn']);
        });
    }
};
