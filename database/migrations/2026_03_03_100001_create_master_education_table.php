<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_education', function (Blueprint $table) {
            $table->id();
            $table->string('name', 128);
            $table->string('code', 32)->nullable();
            $table->string('group', 32)->nullable(); // school, bachelor, master, other
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_education');
    }
};
