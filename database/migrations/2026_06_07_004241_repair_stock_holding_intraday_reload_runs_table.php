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
        if (! Schema::hasTable('stock_holding_intraday_reload_runs')) {
            return;
        }

        if (! Schema::hasColumn('stock_holding_intraday_reload_runs', 'stock_holding_id')) {
            if (DB::connection()->getDriverName() === 'mysql') {
                DB::statement('ALTER TABLE stock_holding_intraday_reload_runs MODIFY id BIGINT UNSIGNED NOT NULL');
                DB::statement('ALTER TABLE stock_holding_intraday_reload_runs DROP PRIMARY KEY');
                DB::statement('ALTER TABLE stock_holding_intraday_reload_runs MODIFY id VARCHAR(255) NOT NULL');
                DB::statement('ALTER TABLE stock_holding_intraday_reload_runs ADD PRIMARY KEY (id)');
            }

            Schema::table('stock_holding_intraday_reload_runs', function (Blueprint $table) {
                $table->foreignId('stock_holding_id')->after('id')->constrained()->cascadeOnDelete();
                $table->string('status')->default('finished')->after('stock_holding_id')->index();
                $table->date('date_from')->after('status');
                $table->date('date_to')->after('date_from');
                $table->unsignedInteger('stored_count')->default(0)->after('date_to');
                $table->string('message')->nullable()->after('stored_count');
                $table->json('error_summary')->nullable()->after('message');
                $table->timestamp('started_at')->nullable()->after('error_summary');
                $table->timestamp('finished_at')->nullable()->after('started_at');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('stock_holding_intraday_reload_runs', 'stock_holding_id')) {
            return;
        }

        Schema::table('stock_holding_intraday_reload_runs', function (Blueprint $table) {
            $table->dropForeign(['stock_holding_id']);
            $table->dropColumn([
                'stock_holding_id',
                'status',
                'date_from',
                'date_to',
                'stored_count',
                'message',
                'error_summary',
                'started_at',
                'finished_at',
            ]);
        });
    }
};
