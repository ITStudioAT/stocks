<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('clients')
            ->select('company_id')
            ->groupBy('company_id')
            ->havingRaw('COUNT(*) = 1')
            ->orderBy('company_id')
            ->pluck('company_id')
            ->each(function (int $companyId): void {
                DB::table('clients')
                    ->where('company_id', $companyId)
                    ->update(['is_active' => true]);
            });
    }

    public function down(): void
    {
        DB::table('clients')->update(['is_active' => false]);
    }
};
