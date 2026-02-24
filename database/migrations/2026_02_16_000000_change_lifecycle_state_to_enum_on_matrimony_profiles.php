<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $enumList = implode("','", [
            'draft',
            'intake_uploaded',
            'parsed',
            'awaiting_user_approval',
            'approved_pending_mutation',
            'conflict_pending',
            'active',
            'suspended',
            'archived',
            'archived_due_to_marriage',
        ]);
        DB::statement("ALTER TABLE matrimony_profiles MODIFY COLUMN lifecycle_state ENUM('{$enumList}') NOT NULL DEFAULT 'active'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE matrimony_profiles MODIFY COLUMN lifecycle_state VARCHAR(32) NOT NULL DEFAULT 'active'");
    }
};
