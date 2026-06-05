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
        $needsFetchRunId = ! Schema::hasColumn('stock_historical_price_fetch_items', 'fetch_run_id');
        $needsStockHoldingId = ! Schema::hasColumn('stock_historical_price_fetch_items', 'stock_holding_id');

        Schema::table('stock_historical_price_fetch_items', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_historical_price_fetch_items', 'fetch_run_id')) {
                $table->string('fetch_run_id')->after('id')->index();
            }

            if (! Schema::hasColumn('stock_historical_price_fetch_items', 'stock_holding_id')) {
                $table->foreignId('stock_holding_id')->after('fetch_run_id')->constrained()->cascadeOnDelete();
            }

            if (! Schema::hasColumn('stock_historical_price_fetch_items', 'status')) {
                $table->string('status')->default('queued')->after('stock_holding_id')->index();
            }

            if (! Schema::hasColumn('stock_historical_price_fetch_items', 'date_from')) {
                $table->date('date_from')->after('status');
            }

            if (! Schema::hasColumn('stock_historical_price_fetch_items', 'date_to')) {
                $table->date('date_to')->after('date_from');
            }

            if (! Schema::hasColumn('stock_historical_price_fetch_items', 'stored_count')) {
                $table->unsignedInteger('stored_count')->default(0)->after('date_to');
            }

            if (! Schema::hasColumn('stock_historical_price_fetch_items', 'error_message')) {
                $table->string('error_message')->nullable()->after('stored_count');
            }
        });

        Schema::table('stock_historical_price_fetch_items', function (Blueprint $table) {
            if (! $this->hasForeignKey('stock_historical_price_fetch_items', 'fetch_run_id')) {
                $table->foreign('fetch_run_id', 'shpfi_run_fk')
                    ->references('id')
                    ->on('stock_historical_price_fetch_runs')
                    ->cascadeOnDelete();
            }

            if (! $this->hasIndex('stock_historical_price_fetch_items', 'shpfi_run_holding_idx')) {
                $table->index(['fetch_run_id', 'stock_holding_id'], 'shpfi_run_holding_idx');
            }

            if (! $this->hasIndex('stock_historical_price_fetch_items', 'shpfi_holding_dates_status_idx')) {
                $table->index(['stock_holding_id', 'date_from', 'date_to', 'status'], 'shpfi_holding_dates_status_idx');
            }
        });
    }

    private function hasIndex(string $table, string $index): bool
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return true;
        }

        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $index)
            ->exists();
    }

    private function hasForeignKey(string $table, string $column): bool
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return true;
        }

        return DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->exists();
    }
};
