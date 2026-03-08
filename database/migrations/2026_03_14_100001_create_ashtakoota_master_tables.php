<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Ashta-Koota / 36 Gun Milan: Varna, Vashya, Graha Maitri (rashi lord) master tables. */
    public function up(): void
    {
        Schema::create('master_varnas', function (Blueprint $table) {
            $table->id();
            $table->string('key', 32)->unique();
            $table->string('label', 64);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('master_vashyas', function (Blueprint $table) {
            $table->id();
            $table->string('key', 32)->unique();
            $table->string('label', 64);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('master_rashi_lords', function (Blueprint $table) {
            $table->id();
            $table->string('key', 32)->unique();
            $table->string('label', 64);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_rashi_lords');
        Schema::dropIfExists('master_vashyas');
        Schema::dropIfExists('master_varnas');
    }
};
