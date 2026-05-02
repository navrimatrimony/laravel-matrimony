<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical geographic hierarchy lives in {@code addresses}. Legacy installs had {@code locations}
 * (renamed from places); rename to {@code addresses} only when {@code addresses} does not exist yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('addresses')) {
            return;
        }

        if (! Schema::hasTable('locations')) {
            return;
        }

        Schema::rename('locations', 'addresses');
    }

    public function down(): void
    {
        if (Schema::hasTable('locations')) {
            return;
        }

        if (! Schema::hasTable('addresses')) {
            return;
        }

        Schema::rename('addresses', 'locations');
    }
};
