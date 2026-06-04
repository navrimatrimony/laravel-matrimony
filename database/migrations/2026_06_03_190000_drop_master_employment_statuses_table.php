<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('master_employment_statuses');
    }

    public function down(): void
    {
        if (Schema::hasTable('master_employment_statuses')) {
            return;
        }

        Schema::create('master_employment_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64);
            $table->string('name_mr', 64)->nullable();
            $table->string('code', 32)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }
};
