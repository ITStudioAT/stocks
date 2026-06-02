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
            $table->string('symbol')->nullable()->after('depot_id');
            $table->string('exchange')->nullable()->after('wkn');
            $table->string('mic_code')->nullable()->after('exchange');
            $table->string('instrument_type')->nullable()->after('mic_code');
            $table->string('country')->nullable()->after('instrument_type');
            $table->string('currency', 8)->nullable()->after('country');

            $table->index(['depot_id', 'symbol']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_holdings', function (Blueprint $table) {
            $table->dropIndex(['depot_id', 'symbol']);
            $table->dropColumn(['symbol', 'exchange', 'mic_code', 'instrument_type', 'country', 'currency']);
        });
    }
};
