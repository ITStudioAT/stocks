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
        if (! Schema::hasTable('stock_realtime_prices')) {
            Schema::create('stock_realtime_prices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('stock_holding_id')
                    ->nullable()
                    ->constrained()
                    ->nullOnDelete();
                $table->foreignId('legacy_stock_price_id')
                    ->nullable()
                    ->unique()
                    ->constrained('stock_prices')
                    ->nullOnDelete();
                $table->string('instrument_key')->index();
                $table->string('quote_hash', 64)->unique();
                $table->string('source_key')->index();
                $table->string('source_name');
                $table->text('source_url');
                $table->string('source_quality')->index();
                $table->string('venue')->nullable()->index();
                $table->string('mic')->nullable()->index();
                $table->string('isin', 12)->nullable()->index();
                $table->string('wkn', 6)->nullable();
                $table->string('symbol')->nullable();
                $table->char('currency', 3)->nullable()->index();
                $table->decimal('bid', 20, 8)->nullable();
                $table->decimal('ask', 20, 8)->nullable();
                $table->decimal('last', 20, 8)->nullable();
                $table->decimal('close', 20, 8)->nullable();
                $table->decimal('nav', 20, 8)->nullable();
                $table->decimal('price', 20, 8)->nullable();
                $table->string('price_type')->default('unavailable')->index();
                $table->decimal('spread_abs', 20, 8)->nullable();
                $table->decimal('spread_pct', 12, 6)->nullable();
                $table->timestamp('as_of')->nullable()->index();
                $table->timestamp('fetched_at')->index();
                $table->string('freshness_status')->default('unavailable')->index();
                $table->string('validation_status')->default('invalid')->index();
                $table->json('validation_errors')->nullable();
                $table->string('raw_text_hash')->nullable();
                $table->json('raw_payload')->nullable();
                $table->string('trading_times')->nullable();
                $table->timestamps();

                $table->index(['stock_holding_id', 'as_of']);
                $table->index(['instrument_key', 'source_key', 'as_of']);
                $table->index(['instrument_key', 'created_at']);
            });
        }

        if (! Schema::hasColumn('stock_holdings', 'latest_realtime_price_id')) {
            Schema::table('stock_holdings', function (Blueprint $table) {
                $table->foreignId('latest_realtime_price_id')
                    ->nullable()
                    ->after('latest_stock_price_id')
                    ->constrained('stock_realtime_prices')
                    ->nullOnDelete();
            });
        }

        $now = now();

        DB::table('stock_prices')
            ->where('source_key', 'eodhd_realtime')
            ->orderBy('id')
            ->chunkById(500, function ($stockPrices) use ($now): void {
                foreach ($stockPrices as $stockPrice) {
                    DB::table('stock_realtime_prices')->updateOrInsert([
                        'legacy_stock_price_id' => $stockPrice->id,
                    ], [
                        'stock_holding_id' => null,
                        'instrument_key' => $stockPrice->instrument_key,
                        'quote_hash' => $stockPrice->quote_hash,
                        'source_key' => $stockPrice->source_key,
                        'source_name' => $stockPrice->source_name,
                        'source_url' => $stockPrice->source_url,
                        'source_quality' => $stockPrice->source_quality,
                        'venue' => $stockPrice->venue,
                        'mic' => $stockPrice->mic,
                        'isin' => $stockPrice->isin,
                        'wkn' => $stockPrice->wkn,
                        'symbol' => $stockPrice->symbol,
                        'currency' => $stockPrice->currency,
                        'bid' => $stockPrice->bid,
                        'ask' => $stockPrice->ask,
                        'last' => $stockPrice->last,
                        'close' => $stockPrice->close,
                        'nav' => $stockPrice->nav,
                        'price' => $stockPrice->price,
                        'price_type' => $stockPrice->price_type,
                        'spread_abs' => $stockPrice->spread_abs,
                        'spread_pct' => $stockPrice->spread_pct,
                        'as_of' => $stockPrice->as_of,
                        'fetched_at' => $stockPrice->fetched_at,
                        'freshness_status' => $stockPrice->freshness_status,
                        'validation_status' => $stockPrice->validation_status,
                        'validation_errors' => $stockPrice->validation_errors,
                        'raw_text_hash' => $stockPrice->raw_text_hash,
                        'raw_payload' => $stockPrice->raw_payload,
                        'trading_times' => $stockPrice->trading_times,
                        'created_at' => $stockPrice->created_at ?? $now,
                        'updated_at' => $stockPrice->updated_at ?? $now,
                    ]);
                }
            });

        DB::table('stock_holdings')
            ->whereNotNull('latest_stock_price_id')
            ->orderBy('id')
            ->chunkById(500, function ($holdings): void {
                foreach ($holdings as $holding) {
                    $realtimePriceId = DB::table('stock_realtime_prices')
                        ->where('legacy_stock_price_id', $holding->latest_stock_price_id)
                        ->value('id');

                    if ($realtimePriceId === null) {
                        continue;
                    }

                    DB::table('stock_realtime_prices')
                        ->where('id', $realtimePriceId)
                        ->update(['stock_holding_id' => $holding->id]);

                    DB::table('stock_holdings')
                        ->where('id', $holding->id)
                        ->update(['latest_realtime_price_id' => $realtimePriceId]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_holdings', function (Blueprint $table) {
            $table->dropForeign(['latest_realtime_price_id']);
            $table->dropColumn('latest_realtime_price_id');
        });

        Schema::dropIfExists('stock_realtime_prices');
    }
};
