<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-3 Day 9: Add is_enabled to field_registry for EXTENDED field visibility.
 * Governance only. Default true = no behavior change for existing rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('field_registry', function (Blueprint $table) {
            $table->boolean('is_enabled')->default(true)->after('display_order');
        });
    }

    public function down(): void
    {
        Schema::table('field_registry', function (Blueprint $table) {
            $table->dropColumn('is_enabled');
        });
    }
};
