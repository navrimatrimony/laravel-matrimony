<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Day 31 Part 2 Addendum: Sibling Details Engine.
 * Additive only: relation_type (Brother/Sister), name, contact_number, sort_order, soft deletes.
 * Existing columns (gender, marital_status, occupation, city_id, notes) unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profile_siblings', function (Blueprint $table) {
            $table->string('relation_type', 20)->nullable()->after('profile_id'); // brother | sister
            $table->string('name')->nullable()->after('relation_type');
            $table->string('contact_number', 30)->nullable()->after('notes');
            $table->unsignedInteger('sort_order')->default(0)->after('contact_number');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('profile_siblings', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn(['relation_type', 'name', 'contact_number', 'sort_order']);
        });
    }
};
