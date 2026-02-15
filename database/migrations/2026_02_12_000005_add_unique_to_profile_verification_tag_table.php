<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Unique (matrimony_profile_id, verification_tag_id) is already defined
 * in 2026_02_12_000004_create_profile_verification_tag_table.
 * This migration is a no-op to avoid duplicate key on migrate:fresh.
 */
return new class extends Migration
{
    public function up(): void
    {
        // No-op: unique constraint already exists in create migration (000004).
    }

    public function down(): void
    {
        // No-op: do not drop unique; it belongs to create migration (000004).
    }
};
