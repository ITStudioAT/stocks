<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('depot_transactions')
            ->whereIn('type', ['buy', 'sell'])
            ->update([
                'is_external_cashflow' => false,
                'affects_performance' => false,
                'is_estimated_date' => false,
            ]);

        DB::table('depot_transactions')
            ->whereNull('stock_holding_id')
            ->whereIn('type', ['deposit', 'withdrawal'])
            ->update([
                'is_external_cashflow' => true,
                'affects_performance' => false,
                'is_estimated_date' => false,
            ]);

        DB::table('depot_transactions')
            ->whereNull('stock_holding_id')
            ->where('type', 'deposit')
            ->whereMonth('booked_at', 1)
            ->whereDay('booked_at', 1)
            ->where(function ($query): void {
                $query
                    ->where('note', 'like', '%Start%')
                    ->orWhere('note', 'like', '%Opening%')
                    ->orWhere('note', 'like', '%Anfang%')
                    ->orWhere('note', 'like', '%Balance%')
                    ->orWhere('note', 'like', '%Saldo%')
                    ->orWhere('note', 'like', '%Startbestand%');
            })
            ->update([
                'type' => 'opening_balance',
                'is_external_cashflow' => true,
                'affects_performance' => false,
                'is_estimated_date' => false,
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('depot_transactions')
            ->where('type', 'opening_balance')
            ->whereMonth('booked_at', 1)
            ->whereDay('booked_at', 1)
            ->where(function ($query): void {
                $query
                    ->where('note', 'like', '%Start%')
                    ->orWhere('note', 'like', '%Opening%')
                    ->orWhere('note', 'like', '%Anfang%')
                    ->orWhere('note', 'like', '%Balance%')
                    ->orWhere('note', 'like', '%Saldo%')
                    ->orWhere('note', 'like', '%Startbestand%');
            })
            ->update([
                'type' => 'deposit',
                'is_external_cashflow' => true,
                'affects_performance' => false,
                'is_estimated_date' => false,
            ]);
    }
};
