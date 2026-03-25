<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partner community intent (e.g. open to intercaste) — separate from profile_preference_criteria pivots.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_partner_community_flags', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('profile_id');
            $table->boolean('interested_in_intercaste')->default(false);
            $table->timestamps();

            $table->unique('profile_id');
            $table->foreign('profile_id')->references('id')->on('matrimony_profiles')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_partner_community_flags');
    }
};
