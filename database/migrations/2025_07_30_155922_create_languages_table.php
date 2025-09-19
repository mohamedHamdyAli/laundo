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
        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_en');
            $table->string('code');
            $table->string('country_code')->nullable();
            $table->enum('is_rtl', ['true', 'false'])->default('false');
            $table->string('icon')->nullable();
            $table->enum('app_scope',['user','vendor']);
            $table->string('app_file')->nullable();
            $table->string('panel_file')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('languages');
    }
};
