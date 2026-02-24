<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-5: Drop legacy columns from matrimony_profiles.
 * - education: replaced by highest_education (business logic and API use highest_education only).
 * - contact_number: replaced by profile_contacts relation (primary contact via getPrimaryContactNumberAttribute).
 *
 * DO NOT RUN until final confirmation after deploy and verification.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            $table->dropColumn(['education', 'contact_number']);
        });
    }

    public function down(): void
    {
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            $table->string('education')->nullable();
            $table->string('contact_number', 20)->nullable();
        });
    }
};
