<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('stock_holdings', function (Blueprint $table) {
            $table->decimal('flatex_price', 20, 6)->nullable()->after('latest_price');
        });

        DB::table('stock_holdings')
            ->whereNull('flatex_price')
            ->update([
                'flatex_price' => DB::raw('latest_price'),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_holdings', function (Blueprint $table) {
            $table->dropColumn('flatex_price');
        });
    }
};
