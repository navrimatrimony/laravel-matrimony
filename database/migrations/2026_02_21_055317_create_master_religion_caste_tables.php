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
    Schema::create('religions', function (Blueprint $table) {
        $table->id();
        $table->string('key')->unique();
        $table->string('label');
        $table->boolean('is_active')->default(true);
        $table->timestamps();
    });

    Schema::create('castes', function (Blueprint $table) {
        $table->id();
        $table->foreignId('religion_id')->nullable()->constrained()->nullOnDelete();
        $table->string('key')->unique();
        $table->string('label');
        $table->boolean('is_active')->default(true);
        $table->timestamps();
    });

    Schema::create('sub_castes', function (Blueprint $table) {
        $table->id();
        $table->foreignId('caste_id')->constrained()->cascadeOnDelete();
        $table->string('key');
        $table->string('label');
        $table->boolean('is_active')->default(true);
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
{
    Schema::dropIfExists('sub_castes');
    Schema::dropIfExists('castes');
    Schema::dropIfExists('religions');
}
};
