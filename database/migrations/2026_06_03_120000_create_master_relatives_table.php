<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_relatives', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('relation_group', 32)->index();
            $table->string('label', 128);
            $table->string('label_mr', 128)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_relatives');
    }
};
