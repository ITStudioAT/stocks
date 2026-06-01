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
        Schema::create('homepage_color_schemes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('homepage_id')->constrained('clients')->cascadeOnDelete();
            $table->string('scheme_name', 120)->default('Default');
            $table->json('source_colors_json');
            $table->json('role_colors_json');
            $table->json('generated_palette_json');
            $table->json('usage_tokens_json');
            $table->mediumText('css_variables');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['homepage_id', 'is_active'], 'idx_homepage_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('homepage_color_schemes');
    }
};
