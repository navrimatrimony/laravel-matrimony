<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-3 Day-6: Add lock metadata columns to field_registry.
 * Backward-safe: nullable, no data loss.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('field_registry', function (Blueprint $table) {
            $table->unsignedBigInteger('locked_by')->nullable()->after('lock_after_user_edit');
            $table->timestamp('locked_at')->nullable()->after('locked_by');
        });
    }

    public function down(): void
    {
        Schema::table('field_registry', function (Blueprint $table) {
            $table->dropColumn(['locked_by', 'locked_at']);
        });
    }
};
