<?php

namespace App\Services\Maintenance;

use App\Models\MatrimonyProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hard-deletes one matrimony profile and known dependent rows (same order as admin showcase delete).
 * Use only for maintenance / dev cleanup — not exposed as a member self-serve path.
 */
final class MatrimonyProfileDatabasePurger
{
    /**
     * @param  Collection<int, mixed>|list<int>  $intakeIds
     */
    public static function deleteOcrAndIntakesByIntakeIds(Collection|array $intakeIds): void
    {
        $ids = collect($intakeIds)->filter()->values();
        if ($ids->isEmpty() || ! Schema::hasTable('biodata_intakes')) {
            return;
        }

        if (Schema::hasTable('ocr_correction_logs')) {
            $logCol = Schema::hasColumn('ocr_correction_logs', 'intake_id') ? 'intake_id' : (Schema::hasColumn('ocr_correction_logs', 'biodata_intake_id') ? 'biodata_intake_id' : null);
            if ($logCol !== null) {
                $logIds = DB::table('ocr_correction_logs')->whereIn($logCol, $ids)->pluck('id');
                if ($logIds->isNotEmpty() && Schema::hasTable('ocr_correction_logs_actor_archive')) {
                    DB::table('ocr_correction_logs_actor_archive')->whereIn('ocr_correction_log_id', $logIds)->delete();
                }
                DB::table('ocr_correction_logs')->whereIn($logCol, $ids)->delete();
            }
        }

        DB::table('biodata_intakes')->whereIn('id', $ids)->delete();
    }

    public static function purge(MatrimonyProfile $profile): void
    {
        $pid = (int) $profile->id;

        if (Schema::hasTable('biodata_intakes')) {
            $intakeIds = DB::table('biodata_intakes')->where('matrimony_profile_id', $pid)->pluck('id');
            self::deleteOcrAndIntakesByIntakeIds($intakeIds);
        }

        $conversationIds = DB::table('conversations')
            ->where('profile_one_id', $pid)
            ->orWhere('profile_two_id', $pid)
            ->pluck('id')
            ->map(fn ($v) => (int) $v)
            ->all();

        if ($conversationIds !== []) {
            if (Schema::hasTable('message_participant_states')) {
                DB::table('message_participant_states')->whereIn('conversation_id', $conversationIds)->delete();
            }
            DB::table('messages')->whereIn('conversation_id', $conversationIds)->delete();
            DB::table('conversations')->whereIn('id', $conversationIds)->delete();
        }

        if (Schema::hasTable('message_policy_cooldowns')) {
            DB::table('message_policy_cooldowns')
                ->where('sender_profile_id', $pid)
                ->orWhere('receiver_profile_id', $pid)
                ->delete();
        }

        if (Schema::hasTable('contact_requests') && Schema::hasColumn('contact_requests', 'sender_profile_id')) {
            DB::table('contact_requests')
                ->where('sender_profile_id', $pid)
                ->orWhere('receiver_profile_id', $pid)
                ->delete();
        }

        if (Schema::hasTable('interests')) {
            DB::table('interests')->where('sender_profile_id', $pid)->orWhere('receiver_profile_id', $pid)->delete();
        }
        if (Schema::hasTable('shortlists')) {
            DB::table('shortlists')->where('owner_profile_id', $pid)->orWhere('shortlisted_profile_id', $pid)->delete();
        }
        if (Schema::hasTable('blocks')) {
            DB::table('blocks')->where('blocker_profile_id', $pid)->orWhere('blocked_profile_id', $pid)->delete();
        }
        if (Schema::hasTable('profile_views')) {
            DB::table('profile_views')->where('viewer_profile_id', $pid)->orWhere('viewed_profile_id', $pid)->delete();
        }
        if (Schema::hasTable('hidden_profiles')) {
            DB::table('hidden_profiles')->where('owner_profile_id', $pid)->orWhere('hidden_profile_id', $pid)->delete();
        }

        if (Schema::hasTable('profile_photos')) {
            DB::table('profile_photos')->where('profile_id', $pid)->delete();
        }
        if (Schema::hasTable('profile_contacts')) {
            DB::table('profile_contacts')->where('profile_id', $pid)->delete();
        }
        if (Schema::hasTable('profile_preference_criteria')) {
            DB::table('profile_preference_criteria')->where('profile_id', $pid)->delete();
        }
        foreach ([
            'profile_preferred_religions',
            'profile_preferred_castes',
            'profile_preferred_districts',
            'profile_preferred_talukas',
            'profile_preferred_cities',
            'profile_preferred_states',
            'profile_preferred_educations',
        ] as $tbl) {
            if (Schema::hasTable($tbl)) {
                DB::table($tbl)->where('profile_id', $pid)->delete();
            }
        }
        if (Schema::hasTable('profile_extended_attributes')) {
            DB::table('profile_extended_attributes')->where('profile_id', $pid)->delete();
        }
        if (Schema::hasTable('profile_marriages')) {
            DB::table('profile_marriages')->where('profile_id', $pid)->delete();
        }
        if (Schema::hasTable('profile_siblings')) {
            DB::table('profile_siblings')->where('profile_id', $pid)->delete();
        }
        if (Schema::hasTable('profile_relatives')) {
            DB::table('profile_relatives')->where('profile_id', $pid)->delete();
        }
        if (Schema::hasTable('profile_properties')) {
            DB::table('profile_properties')->where('profile_id', $pid)->delete();
        }
        if (Schema::hasTable('profile_horoscopes')) {
            DB::table('profile_horoscopes')->where('profile_id', $pid)->delete();
        }

        foreach ([
            ['profile_change_history', 'profile_id'],
            ['profile_field_locks', 'profile_id'],
            ['profile_visibility_settings', 'profile_id'],
            ['profile_preferences', 'profile_id'],
            ['profile_education', 'profile_id'],
            ['profile_career', 'profile_id'],
            ['profile_children', 'profile_id'],
            ['profile_addresses', 'profile_id'],
            ['profile_property_summary', 'profile_id'],
            ['profile_property_assets', 'profile_id'],
            ['profile_horoscope_data', 'profile_id'],
            ['profile_legal_cases', 'profile_id'],
            ['profile_alliance_networks', 'profile_id'],
            ['profile_kyc_submissions', 'matrimony_profile_id'],
            ['profile_verification_tag', 'matrimony_profile_id'],
            ['profile_verification_tag_audits', 'matrimony_profile_id'],
            ['conflict_records', 'profile_id'],
        ] as [$tbl, $col]) {
            if (Schema::hasTable($tbl) && Schema::hasColumn($tbl, $col)) {
                DB::table($tbl)->where($col, $pid)->delete();
            }
        }

        if (Schema::hasTable('mutation_log') && Schema::hasColumn('mutation_log', 'profile_id')) {
            DB::table('mutation_log')->where('profile_id', $pid)->delete();
        }

        $profile->forceDelete();
    }
}
