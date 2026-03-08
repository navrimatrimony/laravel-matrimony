<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Varna and Vashya depend on Rashi; Graha Maitri uses rashi lord. Additive only. */
    public function up(): void
    {
        Schema::table('master_rashis', function (Blueprint $table) {
            $table->foreignId('varna_id')->nullable()->after('label')->constrained('master_varnas')->nullOnDelete();
            $table->foreignId('vashya_id')->nullable()->after('varna_id')->constrained('master_vashyas')->nullOnDelete();
            $table->foreignId('rashi_lord_id')->nullable()->after('vashya_id')->constrained('master_rashi_lords')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('master_rashis', function (Blueprint $table) {
            $table->dropForeign(['varna_id']);
            $table->dropForeign(['vashya_id']);
            $table->dropForeign(['rashi_lord_id']);
        });
    }
};
