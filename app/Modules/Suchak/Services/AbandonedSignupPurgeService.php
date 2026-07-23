<?php

namespace App\Modules\Suchak\Services;

use App\Models\SuchakAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finds and removes Suchak signups that were abandoned before they ever became
 * anything. Single source of truth for this rule — the admin "clean up" button
 * and the `suchak:purge-abandoned-signups` command both call it, so there is no
 * second deletion path that could drift.
 *
 * Safety is structural, not procedural (PO requirement 2026-07-23: a future
 * admin must never be able to delete a real Suchak by mistake). An account is
 * only ever reachable when ALL of these hold:
 *
 *   1. verification_status is still `pending` — verified / rejected / suspended
 *      / archived accounts are out of reach whatever is passed in.
 *   2. registration_completed_at is null — the signup never finished.
 *   3. It is older than the age floor, which can never go below
 *      MINIMUM_AGE_DAYS.
 *   4. It owns ZERO rows in EVERY table that references suchak_accounts.
 *
 * Point 4 reads the live schema rather than a list kept in code. There are 85+
 * such tables today, and a hardcoded list would go stale the first time a new
 * Suchak table shipped — which is exactly how a real account would end up
 * deleted.
 */
class AbandonedSignupPurgeService
{
    /** Hard floor. Anything lower is rejected, not clamped. */
    public const MINIMUM_AGE_DAYS = 30;

    /**
     * The account's OWN paperwork — its registration audit trail and the
     * documents it submitted about itself. These are written for every signup,
     * including one abandoned at step 1, so treating them as "this account is in
     * use" made the purge permanently unable to delete anything (found on live
     * data 2026-07-23: 24 activity-log rows and 3 verification records across
     * the 18 abandoned signups). They carry no meaning once the account is gone,
     * so they are removed with it instead of blocking it.
     *
     * This list is deliberately tiny and explicit. Everything else blocks by
     * default, so a Suchak table added in future is protective automatically
     * rather than being silently ignored.
     */
    public const SELF_PAPERWORK_TABLES = [
        'suchak_activity_logs',
        'suchak_verification_records',
    ];

    /**
     * Accounts that may be deleted right now. Safe to call any time — reads only.
     *
     * @return Collection<int, SuchakAccount>
     */
    public function eligible(int $days = self::MINIMUM_AGE_DAYS): Collection
    {
        $days = max($days, self::MINIMUM_AGE_DAYS);

        $candidates = SuchakAccount::query()
            ->where('verification_status', SuchakAccount::VERIFICATION_PENDING)
            ->whereNull('registration_completed_at')
            ->where('created_at', '<', now()->subDays($days))
            ->orderBy('created_at')
            ->get(['id', 'suchak_name', 'mobile_number', 'onboarding_step', 'created_at']);

        if ($candidates->isEmpty()) {
            return $candidates;
        }

        $used = $this->accountIdsWithAnyRelatedRow($candidates->pluck('id')->all());

        return $candidates->reject(fn (SuchakAccount $a): bool => isset($used[$a->id]))->values();
    }

    /**
     * Deletes the eligible accounts and returns how many went.
     *
     * Re-resolves eligibility inside the call rather than trusting ids handed in
     * from a form, so a stale or tampered submission can never widen the set.
     */
    public function purge(int $days = self::MINIMUM_AGE_DAYS): int
    {
        $eligible = $this->eligible($days);
        if ($eligible->isEmpty()) {
            return 0;
        }

        $ids = $eligible->pluck('id')->all();
        DB::transaction(function () use ($ids): void {
            // The account's own paperwork goes with it, children first so no
            // foreign key is left dangling.
            foreach (self::SELF_PAPERWORK_TABLES as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->whereIn('suchak_account_id', $ids)->delete();
                }
            }
            SuchakAccount::query()->whereIn('id', $ids)->delete();
        });

        return count($ids);
    }

    /**
     * Tables whose presence means the account did real work, so it must be kept.
     * Everything referencing suchak_accounts counts, except the account's own
     * paperwork.
     *
     * @return array<int, string>
     */
    public function referencingTables(): array
    {
        $tables = [];
        foreach (Schema::getTableListing() as $table) {
            $name = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;
            if ($name === 'suchak_accounts' || in_array($name, self::SELF_PAPERWORK_TABLES, true)) {
                continue;
            }
            if (Schema::hasColumn($name, 'suchak_account_id')) {
                $tables[] = $name;
            }
        }

        return $tables;
    }

    /**
     * @param  array<int, int>  $accountIds
     * @return array<int, true>
     */
    private function accountIdsWithAnyRelatedRow(array $accountIds): array
    {
        $used = [];
        foreach ($this->referencingTables() as $table) {
            $found = DB::table($table)
                ->whereIn('suchak_account_id', $accountIds)
                ->distinct()
                ->pluck('suchak_account_id');
            foreach ($found as $id) {
                $used[(int) $id] = true;
            }
        }

        return $used;
    }
}
