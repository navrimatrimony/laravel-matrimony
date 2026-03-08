<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tara Koota is calculated from nakshatra number (1-27). Additive only. */
    public function up(): void
    {
        Schema::table('master_nakshatras', function (Blueprint $table) {
            $table->unsignedSmallInteger('nakshatra_number')->nullable()->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('master_nakshatras', function (Blueprint $table) {
            $table->dropColumn('nakshatra_number');
        });
    }
};
