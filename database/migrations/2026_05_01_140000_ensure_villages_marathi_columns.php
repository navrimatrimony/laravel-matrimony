<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GeoSeeder / LocationMarathiLabels expect villages.name_en and villages.name_mr (see GeoSeeder).
 * Additive only when columns are missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('villages')) {
            return;
        }

        Schema::table('villages', function (Blueprint $table): void {
            if (! Schema::hasColumn('villages', 'name_en')) {
                $table->string('name_en')->nullable();
            }
            if (! Schema::hasColumn('villages', 'name_mr')) {
                $table->string('name_mr')->nullable();
            }
            if (! Schema::hasColumn('villages', 'lgd_code')) {
                $table->string('lgd_code', 32)->nullable();
            }
        });
    }

    public function down(): void
    {
        // PHASE-5: additive-only; do not drop seeded business columns on rollback.
    }
};
