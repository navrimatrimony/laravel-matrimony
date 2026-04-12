<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive: population for auto-showcase residence fallback (≥1L hub rule).
     */
    public function up(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->unsignedInteger('population')->nullable()->after('pincode');
        });
    }

    public function down(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->dropColumn('population');
        });
    }
};
