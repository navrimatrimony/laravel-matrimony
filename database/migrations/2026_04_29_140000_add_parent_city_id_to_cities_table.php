<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 5: optional metro/locality parent (e.g. Wakad → Pune). PHASE-5 additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cities')) {
            return;
        }

        if (! Schema::hasColumn('cities', 'parent_city_id')) {
            Schema::table('cities', function (Blueprint $table) {
                $table->foreignId('parent_city_id')->nullable()->after('taluka_id')->constrained('cities')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('cities') || ! Schema::hasColumn('cities', 'parent_city_id')) {
            return;
        }

        Schema::table('cities', function (Blueprint $table) {
            $table->dropForeign(['parent_city_id']);
            $table->dropColumn('parent_city_id');
        });
    }
};
