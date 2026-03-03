<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }
        DB::statement("
            ALTER TABLE matrimony_profiles
            MODIFY lifecycle_state ENUM(
                'draft',
                'intake_uploaded',
                'parsed',
                'awaiting_user_approval',
                'approved_pending_mutation',
                'conflict_pending',
                'active',
                'suspended',
                'archived',
                'archived_due_to_marriage'
            ) NOT NULL DEFAULT 'draft'
        ");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }
        DB::statement("
            ALTER TABLE matrimony_profiles
            MODIFY lifecycle_state ENUM(
                'draft',
                'intake_uploaded',
                'parsed',
                'awaiting_user_approval',
                'approved_pending_mutation',
                'conflict_pending',
                'active',
                'suspended',
                'archived',
                'archived_due_to_marriage'
            ) NOT NULL DEFAULT 'active'
        ");
    }
};