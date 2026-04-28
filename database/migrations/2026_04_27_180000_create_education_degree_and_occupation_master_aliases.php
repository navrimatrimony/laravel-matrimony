<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('education_degrees') && ! Schema::hasTable('education_degree_aliases')) {
            Schema::create('education_degree_aliases', function (Blueprint $table) {
                $table->id();
                $table->foreignId('education_degree_id')->constrained('education_degrees')->cascadeOnDelete();
                $table->string('alias');
                $table->string('normalized_alias', 512);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique('normalized_alias');
                $table->index(['education_degree_id', 'is_active']);
            });
        }

        if (Schema::hasTable('occupation_master') && ! Schema::hasTable('occupation_master_aliases')) {
            Schema::create('occupation_master_aliases', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('occupation_master_id');
                $table->string('alias');
                $table->string('normalized_alias', 512);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique('normalized_alias');
                $table->index(['occupation_master_id', 'is_active']);
                $table->foreign('occupation_master_id')->references('id')->on('occupation_master')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('occupation_master_aliases');
        Schema::dropIfExists('education_degree_aliases');
    }
};
