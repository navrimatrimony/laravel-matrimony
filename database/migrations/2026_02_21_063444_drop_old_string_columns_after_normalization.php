<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop old identity string columns
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'religion',
                'caste',
                'sub_caste'
            ]);
        });

        // Drop old preference string columns
        Schema::table('profile_preferences', function (Blueprint $table) {
            $table->dropColumn([
                'preferred_caste',
                'preferred_city'
            ]);
        });

        // Drop old village string column
        Schema::table('profile_addresses', function (Blueprint $table) {
            $table->dropColumn('village');
        });
    }

    public function down(): void
    {
        // Reverse only if absolutely required (simple rollback structure)
        Schema::table('matrimony_profiles', function (Blueprint $table) {
            $table->string('religion')->nullable();
            $table->string('caste')->nullable();
            $table->string('sub_caste')->nullable();
        });

        Schema::table('profile_preferences', function (Blueprint $table) {
            $table->string('preferred_caste')->nullable();
            $table->string('preferred_city')->nullable();
        });

        Schema::table('profile_addresses', function (Blueprint $table) {
            $table->string('village')->nullable();
        });
    }
};