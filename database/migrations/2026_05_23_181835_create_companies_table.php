<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('company_name_1');
            $table->string('company_name_2')->nullable();
            $table->string('street');
            $table->string('postal_code', 20);
            $table->string('city');
            $table->string('country');
            $table->timestamps();
        });
    }
};
