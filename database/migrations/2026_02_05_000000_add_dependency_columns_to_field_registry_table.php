<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-3 Day 10: Add dependency columns to field_registry (EXTENDED display/visibility only).
 * parent_field_key = EXTENDED field key; dependency_condition = JSON {type, value?}.
 * Add-only, no destructive changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('field_registry', function (Blueprint $table) {
            $table->string('parent_field_key', 64)->nullable()->after('replaced_by_field');
            $table->json('dependency_condition')->nullable()->after('parent_field_key');
        });
    }

    public function down(): void
    {
        Schema::table('field_registry', function (Blueprint $table) {
            $table->dropColumn(['parent_field_key', 'dependency_condition']);
        });
    }
};
