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
        Schema::table('stock_holding_intraday_reload_runs', function (Blueprint $table) {
            if (Schema::hasColumn('stock_holding_intraday_reload_runs', 'stock_holding_id')) {
                $table->foreignId('stock_holding_id')->nullable()->change();
            }

            if (! Schema::hasColumn('stock_holding_intraday_reload_runs', 'total_count')) {
                $table->unsignedInteger('total_count')->default(0)->after('status');
                $table->unsignedInteger('processed_count')->default(0)->after('total_count');
                $table->unsignedInteger('success_count')->default(0)->after('processed_count');
                $table->unsignedInteger('failed_count')->default(0)->after('success_count');
                $table->string('current')->nullable()->after('failed_count');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_holding_intraday_reload_runs', function (Blueprint $table) {
            if (Schema::hasColumn('stock_holding_intraday_reload_runs', 'total_count')) {
                $table->dropColumn([
                    'total_count',
                    'processed_count',
                    'success_count',
                    'failed_count',
                    'current',
                ]);
            }
        });

        if (DB::table('stock_holding_intraday_reload_runs')->whereNull('stock_holding_id')->doesntExist()) {
            Schema::table('stock_holding_intraday_reload_runs', function (Blueprint $table) {
                $table->foreignId('stock_holding_id')->nullable(false)->change();
            });
        }
    }
};
