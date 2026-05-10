<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Postal / pin intent is modeled on {@code addresses.pincode}; no duplicate column on member rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('profile_addresses')) {
            return;
        }
        if (! Schema::hasColumn('profile_addresses', 'postal_code')) {
            return;
        }
        Schema::table('profile_addresses', function (Blueprint $table): void {
            $table->dropColumn('postal_code');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('profile_addresses')) {
            return;
        }
        if (Schema::hasColumn('profile_addresses', 'postal_code')) {
            return;
        }
        Schema::table('profile_addresses', function (Blueprint $table): void {
            $table->string('postal_code', 32)->nullable()->after('location_id');
        });
    }
};
