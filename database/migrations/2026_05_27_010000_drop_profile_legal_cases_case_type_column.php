<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('profile_legal_cases') || ! Schema::hasColumn('profile_legal_cases', 'case_type')) {
            return;
        }

        Schema::table('profile_legal_cases', function (Blueprint $table) {
            $table->dropColumn('case_type');
        });
    }

    public function down(): void
    {
        // Intentionally irreversible: legal case type is no longer part of the SSOT.
    }
};
