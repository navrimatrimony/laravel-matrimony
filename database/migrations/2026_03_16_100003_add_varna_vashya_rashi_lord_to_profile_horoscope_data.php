<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-5 additive: Varna, Vashya, Rashi Lord as editable fields on profile_horoscope_data.
 */
return new class extends Migration
{
    public function up(): void
    {
        $t = 'profile_horoscope_data';
        if (! Schema::hasTable($t)) {
            return;
        }
        Schema::table($t, function (Blueprint $table) use ($t) {
            if (! Schema::hasColumn($t, 'varna_id')) {
                $table->foreignId('varna_id')->nullable()->after('yoni_id')->constrained('master_varnas')->nullOnDelete();
            }
            if (! Schema::hasColumn($t, 'vashya_id')) {
                $table->foreignId('vashya_id')->nullable()->after('varna_id')->constrained('master_vashyas')->nullOnDelete();
            }
            if (! Schema::hasColumn($t, 'rashi_lord_id')) {
                $table->foreignId('rashi_lord_id')->nullable()->after('vashya_id')->constrained('master_rashi_lords')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        $t = 'profile_horoscope_data';
        if (! Schema::hasTable($t)) {
            return;
        }
        Schema::table($t, function (Blueprint $table) use ($t) {
            if (Schema::hasColumn($t, 'rashi_lord_id')) {
                $table->dropForeign(['rashi_lord_id']);
            }
            if (Schema::hasColumn($t, 'vashya_id')) {
                $table->dropForeign(['vashya_id']);
            }
            if (Schema::hasColumn($t, 'varna_id')) {
                $table->dropForeign(['varna_id']);
            }
        });
    }
};
