<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('price_refresh_settings');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('price_refresh_settings', function ($table): void {
            $table->id();
            $table->unsignedSmallInteger('trading_interval_minutes')->default(20);
            $table->unsignedSmallInteger('closed_interval_minutes')->default(60);
            $table->timestamp('last_refreshed_at')->nullable();
            $table->timestamp('next_refresh_at')->nullable()->index();
            $table->timestamps();
        });
    }
};
