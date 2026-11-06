<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasIndex('market_prices', 'market_prices_source_series')) {
            return;
        }
        Schema::table('market_prices', function (Blueprint $table): void {
            $table->index(['source_key', 'is_current', 'validation_status', 'market_listing_id', 'interval_code', 'trading_date', 'effective_at'], 'market_prices_source_series');
        });
    }
};
