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
        Schema::table('index_watch_item_prices', function (Blueprint $table) {
            $table->string('intraday_sync_status', 32)->nullable()->after('raw_payload')->index();
            $table->unsignedSmallInteger('intraday_candle_count')->nullable()->after('intraday_sync_status');
            $table->unsignedTinyInteger('intraday_sync_attempts')->nullable()->after('intraday_candle_count');
            $table->unsignedSmallInteger('intraday_http_status')->nullable()->after('intraday_sync_attempts');
            $table->string('intraday_sync_message')->nullable()->after('intraday_http_status');
            $table->timestamp('intraday_checked_at')->nullable()->after('intraday_sync_message');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('index_watch_item_prices', function (Blueprint $table) {
            $table->dropIndex(['intraday_sync_status']);
            $table->dropColumn([
                'intraday_sync_status',
                'intraday_candle_count',
                'intraday_sync_attempts',
                'intraday_http_status',
                'intraday_sync_message',
                'intraday_checked_at',
            ]);
        });
    }
};
