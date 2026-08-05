<?php

namespace App\Services\Maintenance;

use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deletes one user account and dependencies that would block {@see User::forceDelete()}
 * (profiles, intakes, admin logs, sessions, API tokens, etc.).
 */
final class UserAccountDatabasePurger
{
    /**
     * @param  bool  $keepCounterpartConversations  Leave other members' chat threads standing.
     *
     * Passed by a member's own account deletion, where the people they talked to
     * keep their side of the conversation. The user row then survives as a
     * tombstone rather than being force-deleted, because the profile row it owns
     * has to survive for those threads and `matrimony_profiles.user_id` is NOT
     * NULL. Nothing identifying is left on it — see {@see reduceUserToTombstone}.
     */
    public static function purgeUserAccount(User $user, bool $keepCounterpartConversations = false): void
    {
        DB::transaction(function () use ($user, $keepCounterpartConversations): void {
            $uid = (int) $user->id;
            $email = (string) $user->email;

            foreach (MatrimonyProfile::withTrashed()->where('user_id', $uid)->cursor() as $profile) {
                MatrimonyProfileDatabasePurger::purge($profile, $keepCounterpartConversations);
            }

            if (Schema::hasTable('biodata_intakes')) {
                $intakeIds = DB::table('biodata_intakes')->where('uploaded_by', $uid)->pluck('id');
                MatrimonyProfileDatabasePurger::deleteOcrAndIntakesByIntakeIds(collect($intakeIds));
            }

            if (Schema::hasTable('location_suggestions')) {
                DB::table('location_suggestions')->where('suggested_by', $uid)->delete();
            }

            if (Schema::hasTable('location_open_place_suggestions') && Schema::hasColumn('location_open_place_suggestions', 'suggested_by')) {
                DB::table('location_open_place_suggestions')->where('suggested_by', $uid)->delete();
            }

            if (Schema::hasTable('ocr_correction_logs') && Schema::hasColumn('ocr_correction_logs', 'corrected_by')) {
                DB::table('ocr_correction_logs')->where('corrected_by', $uid)->delete();
            }

            if (Schema::hasTable('admin_capabilities')) {
                DB::table('admin_capabilities')->where('admin_id', $uid)->delete();
            }
            if (Schema::hasTable('admin_audit_logs')) {
                DB::table('admin_audit_logs')->where('admin_id', $uid)->delete();
            }
            if (Schema::hasTable('abuse_reports') && Schema::hasColumn('abuse_reports', 'resolved_by_admin_id')) {
                DB::table('abuse_reports')->where('resolved_by_admin_id', $uid)->update(['resolved_by_admin_id' => null]);
            }
            if (Schema::hasTable('users') && Schema::hasColumn('users', 'mobile_duplicate_of_user_id')) {
                DB::table('users')->where('mobile_duplicate_of_user_id', $uid)->update(['mobile_duplicate_of_user_id' => null]);
            }

            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $uid)->delete();
            }

            if (Schema::hasTable('personal_access_tokens')) {
                DB::table('personal_access_tokens')
                    ->where('tokenable_type', User::class)
                    ->where('tokenable_id', $uid)
                    ->delete();
            }

            if (Schema::hasTable('password_reset_tokens') && $email !== '') {
                DB::table('password_reset_tokens')->where('email', $email)->delete();
            }

            if ($keepCounterpartConversations) {
                self::reduceUserToTombstone($user);

                return;
            }

            $user->forceDelete();
        });
    }

    /**
     * Blanks every identifying value on the user row.
     *
     * `email`, `mobile` and `referral_code` are nulled rather than scrambled
     * because each carries a UNIQUE index: nulling releases them, so the same
     * person can sign up again tomorrow with the same number. Scrambled values
     * would hold those identifiers hostage forever.
     *
     * `password` is nulled and every token and session is already gone by this
     * point, so the shell cannot be authenticated into.
     */
    private static function reduceUserToTombstone(User $user): void
    {
        $update = [
            'name' => null,
            'email' => null,
            'mobile' => null,
            'password' => null,
            'email_verified_at' => null,
            'mobile_verified_at' => null,
            'account_deleted_at' => now(),
            'deletion_requested_at' => null,
            'updated_at' => now(),
        ];

        foreach ([
            'mobile_backup',
            'referral_code',
            'notification_preferences',
            'preferred_locale',
            'registering_for',
            'deletion_reason_key',
            'deletion_reason_note',
        ] as $column) {
            if (Schema::hasColumn('users', $column)) {
                $update[$column] = null;
            }
        }

        DB::table('users')->where('id', $user->id)->update($update);
    }
}
