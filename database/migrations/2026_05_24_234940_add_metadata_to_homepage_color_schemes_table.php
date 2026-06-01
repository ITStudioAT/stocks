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
        Schema::table('homepage_color_schemes', function (Blueprint $table) {
            $table->json('warnings_json')->nullable()->after('css_variables');
            $table->decimal('harmony_score', 5, 2)->nullable()->after('warnings_json');
            $table->string('accessibility_mode', 40)->default('standard')->after('harmony_score');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('homepage_color_schemes', function (Blueprint $table) {
            $table->dropColumn([
                'warnings_json',
                'harmony_score',
                'accessibility_mode',
            ]);
        });
    }
};
