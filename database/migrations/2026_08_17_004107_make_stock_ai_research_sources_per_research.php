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
        Schema::table('stock_ai_research_sources', function (Blueprint $table) {
            $table->index('user_id', 'stock_ai_source_user_index');
            $table->index('stock_holding_id', 'stock_ai_source_stock_index');
        });

        Schema::table('stock_ai_research_sources', function (Blueprint $table) {
            $table->dropUnique('stock_ai_source_user_stock_url_unique');
            $table->string('source_type', 32)->nullable()->after('title');
            $table->string('confidence', 16)->nullable()->after('source_type');
            $table->boolean('is_primary')->default(false)->after('confidence');
            $table->timestamp('retrieved_at')->nullable()->after('is_primary');
            $table->unique(['stock_ai_research_id', 'url_hash'], 'stock_ai_source_research_url_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_ai_research_sources', function (Blueprint $table) {
            $table->dropUnique('stock_ai_source_research_url_unique');
            $table->unique(['user_id', 'stock_holding_id', 'url_hash'], 'stock_ai_source_user_stock_url_unique');
            $table->dropColumn(['source_type', 'confidence', 'is_primary', 'retrieved_at']);
        });

        Schema::table('stock_ai_research_sources', function (Blueprint $table) {
            $table->dropIndex('stock_ai_source_user_index');
            $table->dropIndex('stock_ai_source_stock_index');
        });
    }
};
