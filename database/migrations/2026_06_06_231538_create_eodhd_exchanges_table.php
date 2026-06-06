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
        Schema::create('eodhd_exchanges', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('detail_code')->index();
            $table->string('name')->nullable();
            $table->string('country')->nullable()->index();
            $table->string('currency')->nullable();
            $table->string('timezone')->nullable();
            $table->string('operating_mic')->nullable();
            $table->json('trading_hours')->nullable();
            $table->json('holidays')->nullable();
            $table->json('raw_exchange')->nullable();
            $table->json('raw_details')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('eodhd_exchanges');
    }
};
