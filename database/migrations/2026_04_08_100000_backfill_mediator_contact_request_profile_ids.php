<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotent: legacy mediator rows may lack sender_profile_id / receiver_profile_id.
 * Contact-type rows stay nullable; only type=mediator rows are updated.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contact_requests')) {
            return;
        }

        if (! Schema::hasColumn('contact_requests', 'sender_profile_id')) {
            return;
        }

        DB::table('contact_requests')
            ->where('type', 'mediator')
            ->whereNotNull('subject_profile_id')
            ->whereNull('receiver_profile_id')
            ->update(['receiver_profile_id' => DB::raw('subject_profile_id')]);

        foreach (DB::table('contact_requests')->where('type', 'mediator')->whereNull('sender_profile_id')->cursor() as $row) {
            $pid = DB::table('matrimony_profiles')->where('user_id', $row->sender_id)->value('id');
            if ($pid) {
                DB::table('contact_requests')->where('id', $row->id)->update(['sender_profile_id' => $pid]);
            }
        }
    }

    public function down(): void
    {
        // Data backfill only; no schema change.
    }
};
