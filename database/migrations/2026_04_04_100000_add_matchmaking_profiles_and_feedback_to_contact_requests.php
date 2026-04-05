<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Assisted matchmaking: explicit sender/receiver profiles + optional response feedback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_requests', function (Blueprint $table) {
            $table->foreignId('sender_profile_id')->nullable()->constrained('matrimony_profiles')->nullOnDelete();
            $table->foreignId('receiver_profile_id')->nullable()->constrained('matrimony_profiles')->nullOnDelete();
            $table->text('response_feedback')->nullable();
        });

        if (Schema::hasTable('contact_requests')) {
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
    }

    public function down(): void
    {
        Schema::table('contact_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sender_profile_id');
            $table->dropConstrainedForeignId('receiver_profile_id');
            $table->dropColumn('response_feedback');
        });
    }
};
