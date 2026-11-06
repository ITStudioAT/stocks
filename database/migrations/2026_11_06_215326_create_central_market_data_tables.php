<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $create = function (string $name, Closure $definition): void {
            if (! Schema::hasTable($name)) {
                Schema::create($name, $definition);
            }
        };
        $create('market_instruments', function (Blueprint $table): void {
            $table->id();
            $table->string('identity_key', 191)->unique();
            $table->string('isin', 12)->nullable()->index();
            $table->string('wkn', 6)->nullable();
            $table->string('name');
            $table->string('instrument_type')->nullable();
            $table->timestamps(6);
        });
        $create('market_listings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_instrument_id')->constrained()->restrictOnDelete();
            $table->char('identity_key', 64)->unique();
            $table->string('venue_key', 64);
            $table->char('mic', 4)->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('quote_unit', 24)->default('unit');
            $table->string('timezone', 64)->nullable();
            $table->string('calendar_key', 24)->nullable();
            $table->string('status', 24)->default('unresolved');
            $table->unsignedBigInteger('data_version')->default(0);
            $table->timestamps(6);
        });
        $create('market_source_instruments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_listing_id')->constrained()->restrictOnDelete();
            $table->string('source_key', 64);
            $table->string('symbol', 96);
            $table->dateTime('verified_at', 6)->nullable();
            $table->dateTime('valid_until', 6)->nullable();
            $table->unique(['market_listing_id', 'source_key']);
            $table->timestamps(6);
        });
        $create('market_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_listing_id')->constrained()->restrictOnDelete();
            $table->string('source_key', 64);
            $table->string('source_record_id', 191)->nullable();
            $table->char('reported_mic', 4)->nullable();
            $table->char('currency', 3)->nullable();
            foreach (['record_kind', 'price_type', 'interval_code', 'session_type', 'time_precision', 'time_basis', 'adjustment_basis', 'validation_status'] as $field) {
                $table->string($field, 32);
            }
            $table->date('trading_date')->nullable();
            $table->string('source_time_raw', 96)->nullable();
            foreach (['observed_at', 'period_start_at', 'period_end_at', 'effective_at', 'source_published_at', 'fetched_at'] as $field) {
                $table->dateTime($field, 6)->nullable();
            }
            foreach (['price', 'bid', 'ask', 'last', 'nav', 'open', 'high', 'low', 'close', 'adjusted_close'] as $field) {
                $table->decimal($field, 28, 10)->nullable();
            }
            $table->decimal('volume', 28, 8)->nullable();
            $table->unsignedBigInteger('trade_count')->nullable();
            $table->unsignedInteger('delay_seconds')->nullable();
            $table->json('validation_errors')->nullable();
            $table->char('observation_key', 64);
            $table->char('payload_hash', 64);
            $table->unsignedInteger('revision');
            $table->boolean('is_current')->default(false);
            $table->foreignId('supersedes_id')->nullable()->constrained('market_prices')->restrictOnDelete();
            $table->char('current_key', 64)->nullable()->storedAs('CASE WHEN is_current = 1 THEN observation_key ELSE NULL END')->unique();
            $table->json('provenance');
            $table->json('raw_payload')->nullable();
            $table->timestamps(6);
            $table->unique(['observation_key', 'revision']);
            $table->index(['market_listing_id', 'is_current', 'validation_status', 'effective_at', 'id'], 'market_prices_latest');
            $table->index(['market_listing_id', 'interval_code', 'is_current', 'trading_date', 'effective_at'], 'market_prices_series');
        });
        $create('market_price_legacy_links', function (Blueprint $table): void {
            $table->id();
            $table->string('legacy_table', 64);
            $table->unsignedBigInteger('legacy_id');
            $table->foreignId('market_price_id')->nullable()->constrained()->restrictOnDelete();
            $table->char('content_hash', 64);
            $table->string('status', 24);
            $table->json('original_record');
            $table->timestamps(6);
            $table->unique(['legacy_table', 'legacy_id']);
        });
        $create('market_price_selections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_listing_id')->constrained()->restrictOnDelete();
            $table->string('purpose', 32);
            $table->foreignId('market_price_id')->nullable()->constrained()->restrictOnDelete();
            $table->dateTime('checked_at', 6)->nullable();
            $table->unique(['market_listing_id', 'purpose']);
        });
        $create('market_data_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('source_key', 64);
            $table->foreignId('market_listing_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status', 24);
            $table->json('details')->nullable();
            $table->timestamps(6);
        });
        if (! Schema::hasColumn('stock_holdings', 'market_listing_id')) {
            Schema::table('stock_holdings', function (Blueprint $table): void {
                $table->foreignId('market_listing_id')->nullable()->constrained()->restrictOnDelete();
            });
        }
        if (! Schema::hasColumn('stock_holdings', 'latest_market_price_id')) {
            Schema::table('stock_holdings', function (Blueprint $table): void {
                $table->foreignId('latest_market_price_id')->nullable()->constrained('market_prices')->nullOnDelete();
            });
        }
        $this->repairConstraints();
    }

    private function repairConstraints(): void
    {
        $indexes = [
            'market_instruments' => [['identity_key'], ['isin']],
            'market_listings' => [['identity_key']],
            'market_source_instruments' => [['market_listing_id', 'source_key']],
            'market_prices' => [['current_key'], ['observation_key', 'revision']],
            'market_price_legacy_links' => [['legacy_table', 'legacy_id']],
            'market_price_selections' => [['market_listing_id', 'purpose']],
        ];
        foreach ($indexes as $table => $definitions) {
            foreach ($definitions as $columns) {
                $unique = $columns !== ['isin'];
                $name = $table.'_'.implode('_', $columns).($unique ? '_unique' : '_index');
                if (! Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $blueprint) => $unique ? $blueprint->unique($columns, $name) : $blueprint->index($columns, $name));
                }
            }
        }
        foreach ([
            'market_prices_latest' => ['market_listing_id', 'is_current', 'validation_status', 'effective_at', 'id'],
            'market_prices_series' => ['market_listing_id', 'interval_code', 'is_current', 'trading_date', 'effective_at'],
        ] as $name => $columns) {
            if (! Schema::hasIndex('market_prices', $name)) {
                Schema::table('market_prices', fn (Blueprint $table) => $table->index($columns, $name));
            }
        }
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }
        foreach ([
            ['market_listings', 'market_instrument_id', 'market_instruments'],
            ['market_source_instruments', 'market_listing_id', 'market_listings'],
            ['market_prices', 'market_listing_id', 'market_listings'],
            ['market_prices', 'supersedes_id', 'market_prices'],
            ['market_price_legacy_links', 'market_price_id', 'market_prices'],
            ['market_price_selections', 'market_listing_id', 'market_listings'],
            ['market_price_selections', 'market_price_id', 'market_prices'],
            ['market_data_runs', 'market_listing_id', 'market_listings'],
            ['stock_holdings', 'market_listing_id', 'market_listings'],
            ['stock_holdings', 'latest_market_price_id', 'market_prices'],
        ] as [$table, $column, $target]) {
            $name = $table.'_'.$column.'_foreign';
            if (! collect(Schema::getForeignKeys($table))->contains('name', $name)) {
                Schema::table($table, function (Blueprint $blueprint) use ($column, $target): void {
                    $foreign = $blueprint->foreign($column)->references('id')->on($target);
                    $column === 'latest_market_price_id' ? $foreign->nullOnDelete() : $foreign->restrictOnDelete();
                });
            }
        }
    }
};
