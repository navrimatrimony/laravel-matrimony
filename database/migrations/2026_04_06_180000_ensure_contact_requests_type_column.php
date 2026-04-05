<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ensures contact_requests.type exists for environments that pre-date mediator migrations.
 * Idempotent: skips if the column already exists (e.g. from 2026_04_03_100000_add_mediator_fields_to_contact_requests).
 * Does not drop, rename, or alter other columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contact_requests')) {
            return;
        }

        if (! Schema::hasColumn('contact_requests', 'type')) {
            Schema::table('contact_requests', function (Blueprint $table) {
                $table->string('type', 32)->default('contact')->after('receiver_id');
            });
        }

        DB::table('contact_requests')
            ->where(function ($q) {
                $q->whereNull('type')->orWhere('type', '');
            })
            ->update(['type' => 'contact']);
    }

    public function down(): void
    {
        // No-op: `type` is required by application code and may have been introduced by an earlier migration.
        // Dropping here would break indexes and contradict additive production rollout.
    }
};
