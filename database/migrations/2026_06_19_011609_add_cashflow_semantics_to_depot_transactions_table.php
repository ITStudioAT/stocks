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
        Schema::table('depot_transactions', function (Blueprint $table) {
            $table->char('currency', 3)->default('EUR')->after('total_amount');
            $table->boolean('is_external_cashflow')->default(false)->after('note');
            $table->boolean('affects_performance')->default(false)->after('is_external_cashflow');
            $table->boolean('is_estimated_date')->default(false)->after('affects_performance');

            $table->index(['depot_id', 'is_external_cashflow', 'booked_at'], 'depot_transactions_depot_external_cashflow_booked_index');
            $table->index(['depot_id', 'affects_performance', 'booked_at'], 'depot_transactions_depot_performance_booked_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('depot_transactions', function (Blueprint $table) {
            $table->dropIndex('depot_transactions_depot_external_cashflow_booked_index');
            $table->dropIndex('depot_transactions_depot_performance_booked_index');
            $table->dropColumn([
                'currency',
                'is_external_cashflow',
                'affects_performance',
                'is_estimated_date',
            ]);
        });
    }
};
