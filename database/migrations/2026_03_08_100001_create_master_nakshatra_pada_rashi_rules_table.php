<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Defines rashi dependency for a given nakshatra + charan (pada) combination.
     * Used by HoroscopeRuleService for autofill and validation only; no DOB/API.
     */
    public function up(): void
    {
        Schema::create('master_nakshatra_pada_rashi_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('nakshatra_id');
            $table->unsignedTinyInteger('charan'); // 1..4
            $table->unsignedBigInteger('rashi_id');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['nakshatra_id', 'charan']);
            $table->index('nakshatra_id');
            $table->index('rashi_id');
            $table->foreign('nakshatra_id')->references('id')->on('master_nakshatras')->cascadeOnDelete();
            $table->foreign('rashi_id')->references('id')->on('master_rashis')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_nakshatra_pada_rashi_rules');
    }
};
