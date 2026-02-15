<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_horoscope_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')
                ->unique()
                ->constrained('matrimony_profiles')
                ->restrictOnDelete();
            $table->string('rashi')->nullable();
            $table->string('nakshatra')->nullable();
            $table->unsignedInteger('charan')->nullable();
            $table->string('gan')->nullable();
            $table->string('nadi')->nullable();
            $table->string('yoni')->nullable();
            $table->string('mangal_dosh_type')->nullable();
            $table->string('devak')->nullable();
            $table->string('kul')->nullable();
            $table->string('gotra')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_horoscope_data');
    }
};
