<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('companies')->updateOrInsert(
            ['company_name_1' => 'ITStudio.at'],
            [
                'company_name_2' => 'by Dipl.-Ing. Gütnher Kron',
                'street' => 'Salzburger Straße 87b',
                'postal_code' => '5110',
                'city' => 'Oberndorf',
                'country' => 'Österreich',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $initialCompanyId = DB::table('companies')
            ->where('company_name_1', 'ITStudio.at')
            ->value('id');

        Schema::table('clients', function (Blueprint $table) {
            $table->foreignId('company_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->restrictOnDelete();
        });

        DB::table('clients')
            ->whereNull('company_id')
            ->update(['company_id' => $initialCompanyId]);

        if (DB::connection()->getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE clients MODIFY company_id BIGINT UNSIGNED NOT NULL');
        }
    }
};
