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

    /**
     * @param  bool  $keepCounterpartConversations  Leave the other member's chat thread standing.
     *
     * Only a real member's own deletion passes true. A showcase profile or a
     * stress-test row has no counterpart whose record is worth preserving, so
     * those callers keep the full erase.
     *
     * True cannot mean "delete the profile anyway": conversations.profile_one_id
     * / profile_two_id and messages.sender/receiver_profile_id are NOT NULL with
     * RESTRICT foreign keys, so the row has to survive for the thread to. It
     * survives as a tombstone — every column wiped, soft-deleted, owning no
     * personal data — and the counterpart sees a member who is simply gone.
     */
    public static function purge(MatrimonyProfile $profile, bool $keepCounterpartConversations = false): void
    {
        $pid = (int) $profile->id;

        self::closeSuchakSideForDepartedCandidate($pid);

        if (Schema::hasTable('biodata_intakes')) {
            $intakeIds = DB::table('biodata_intakes')->where('matrimony_profile_id', $pid)->pluck('id');
            self::deleteOcrAndIntakesByIntakeIds($intakeIds);
        }

        if (! $keepCounterpartConversations) {
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
            'profile_preferred_master_education',
            'profile_preferred_education_degrees',
            'profile_preferred_occupation_master',
            'profile_preferred_working_with_types',
            'profile_preferred_professions',
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
            ['profile_children', 'profile_id'],
            ['profile_addresses', 'profile_id'],
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

        if ($keepCounterpartConversations) {
            self::reduceProfileToTombstone($profile);

            return;
        }

        $profile->forceDelete();
    }

    /**
     * The AUTO_CLOSE half of a candidate leaving: their Suchak stops counting
     * them, and anything pending stops waiting for an answer that will never
     * come. Nothing here deletes — representations cannot be deleted at all
     * ({@see \App\Models\SuchakProfileRepresentation::delete()} throws), and the
     * event/log tables beside them are immutable history.
     *
     * `candidate_deactivated_at` is the column three readers already honour
     * (SuchakConsentService, SuchakCustomerListService, DashboardController);
     * this is deliberately its only writer.
     *
     * `shared_display_name` is nulled because it is a runtime display alias a
     * Suchak typed — no snapshot or hash carries it, and its only reader
     * returns early once `full_name` is wiped — so after a purge it would be
     * nothing but stored PII contradicting the published erasure promise.
     */
    private static function closeSuchakSideForDepartedCandidate(int $pid): void
    {
        if (Schema::hasTable('suchak_profile_representations')) {
            // Two statements, not a COALESCE(NOW()) — the test suite runs on
            // SQLite, where NOW() does not exist. An already-deactivated stamp
            // is preserved; the alias is wiped regardless.
            DB::table('suchak_profile_representations')
                ->where('matrimony_profile_id', $pid)
                ->whereNull('candidate_deactivated_at')
                ->update(['candidate_deactivated_at' => now()]);

            DB::table('suchak_profile_representations')
                ->where('matrimony_profile_id', $pid)
                ->update(['shared_display_name' => null, 'updated_at' => now()]);
        }

        if (Schema::hasTable('suchak_profile_requests')) {
            DB::table('suchak_profile_requests')
                ->where(function ($q) use ($pid) {
                    $q->where('target_matrimony_profile_id', $pid)
                        ->orWhere('requesting_matrimony_profile_id', $pid);
                })
                ->whereNotIn('request_status', ['closed', 'expired', 'cancelled'])
                ->update(['request_status' => 'cancelled', 'updated_at' => now()]);
        }

        if (Schema::hasTable('suchak_pipelines')) {
            DB::table('suchak_pipelines')
                ->where(function ($q) use ($pid) {
                    $q->where('target_matrimony_profile_id', $pid)
                        ->orWhere('requesting_matrimony_profile_id', $pid);
                })
                ->where('pipeline_status', 'pending')
                ->update([
                    'pipeline_status' => 'cancelled',
                    'closed_at' => now(),
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * Columns a tombstone may keep. Everything else on the row is wiped.
     *
     * Deliberately an allow-list: a column added to matrimony_profiles next
     * month is erased by default rather than surviving because nobody
     * remembered to add it to a deny-list. Privacy fails safe here.
     *
     * - `user_id` stays because the column is NOT NULL and the user row is
     *   itself reduced to a tombstone by {@see UserAccountDatabasePurger}.
     * - `lifecycle_state` stays so the row keeps a legal enum value.
     * - `is_showcase` stays so showcase reporting is not skewed by deletions.
     */
    private const TOMBSTONE_KEEP_COLUMNS = [
        'id',
        'user_id',
        'is_showcase',
        'lifecycle_state',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /**
     * Strips every identifying value from the profile row and soft-deletes it,
     * leaving only the foreign-key anchor the counterpart's conversation needs.
     */
    private static function reduceProfileToTombstone(MatrimonyProfile $profile): void
    {
        $update = [];

        // Schema::getColumns() rather than SHOW COLUMNS: the latter is MySQL-only
        // and the test suite runs on SQLite.
        foreach (Schema::getColumns('matrimony_profiles') as $column) {
            $name = (string) $column['name'];
            if (in_array($name, self::TOMBSTONE_KEEP_COLUMNS, true)) {
                continue;
            }

            // A NOT NULL column cannot simply be nulled, so fall back to the
            // column's own default — the emptiest legal value the schema itself
            // defines.
            $update[$name] = ($column['nullable'] ?? true)
                ? null
                : ($column['default'] ?? '');
        }

        $update['lifecycle_state'] = 'archived';
        $update['updated_at'] = now();

        DB::table('matrimony_profiles')->where('id', $profile->id)->update($update);
        DB::table('matrimony_profiles')->where('id', $profile->id)->update(['deleted_at' => now()]);
    }
}
