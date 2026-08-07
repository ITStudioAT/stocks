<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('stock_holdings')
            ->where('isin', 'FR0010930644')
            ->update(['name' => 'Amundi Global Hydrogen UCITS ETF Acc']);
    }
};
