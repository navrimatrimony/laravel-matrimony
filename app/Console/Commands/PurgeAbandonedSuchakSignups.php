<?php

namespace App\Console\Commands;

use App\Models\SuchakAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deletes Suchak signups that were abandoned before they ever became anything.
 *
 * The safety here is STRUCTURAL, not procedural — it does not depend on the
 * operator being careful (PO requirement 2026-07-23: "a future admin must never
 * be able to delete a good Suchak by mistake"). An account is only ever touched
 * when ALL of the following hold:
 *
 *   1. verification_status is still `pending` — a verified, rejected, suspended
 *      or archived account is structurally out of reach, whatever flags are passed.
 *   2. registration_completed_at is null — the signup never finished.
 *   3. It is older than the age floor, and that floor can never go below
 *      MINIMUM_AGE_DAYS. Passing a smaller --days aborts the command.
 *   4. It owns ZERO rows in EVERY table that references suchak_accounts.
 *
 * Point 4 is why the referencing tables are discovered from the live schema
 * instead of being listed in code: there are 85+ of them today, and a hardcoded
 * list would silently go stale the first time a new Suchak table is added —
 * which is exactly how a real account would end up deleted. Anything the schema
 * knows about is checked automatically.
 *
 * Dry-run is the default. Nothing is deleted without --force.
 */
class PurgeAbandonedSuchakSignups extends Command
{
    protected $signature = 'suchak:purge-abandoned-signups
                            {--days=30 : Minimum age in days (never below 30)}
                            {--force : Actually delete. Without this nothing is removed}';

    protected $description = 'Delete never-completed, never-used Suchak signups older than 30 days';

    /** Hard floor. Not a default — a lower value is refused. */
    public const MINIMUM_AGE_DAYS = 30;

    public function handle(): int
    {
        $days = (int) $this->option('days');

        if ($days < self::MINIMUM_AGE_DAYS) {
            $this->error(sprintf(
                'Refusing to run: --days=%d is below the hard minimum of %d days.',
                $days,
                self::MINIMUM_AGE_DAYS
            ));

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);

        // Guards 1-3. Note this never selects a non-pending account.
        $candidates = SuchakAccount::query()
            ->where('verification_status', SuchakAccount::VERIFICATION_PENDING)
            ->whereNull('registration_completed_at')
            ->where('created_at', '<', $cutoff)
            ->orderBy('id')
            ->get(['id', 'suchak_name', 'mobile_number', 'onboarding_step', 'created_at']);

        if ($candidates->isEmpty()) {
            $this->info(sprintf('Nothing to purge: no abandoned signups older than %d days.', $days));

            return self::SUCCESS;
        }

        $tables = $this->referencingTables();
        $this->line(sprintf('Checking %d candidate(s) against %d related table(s).', $candidates->count(), count($tables)));

        // Guard 4: any account appearing in any related table is spared.
        $used = $this->accountIdsWithAnyRelatedRow($tables, $candidates->pluck('id')->all());
        $purgeable = $candidates->reject(fn (SuchakAccount $a): bool => isset($used[$a->id]))->values();
        $spared = $candidates->count() - $purgeable->count();

        if ($spared > 0) {
            $this->line(sprintf('Skipping %d account(s) that have related records.', $spared));
        }

        if ($purgeable->isEmpty()) {
            $this->info('Nothing to purge: every candidate has related records.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Mobile', 'Step', 'Created'],
            $purgeable->map(fn (SuchakAccount $a): array => [
                $a->id,
                $a->suchak_name ?: '(no name)',
                $a->mobile_number ?: '—',
                $a->onboarding_step ?: '—',
                $a->created_at?->format('Y-m-d'),
            ])->all()
        );

        if (! $this->option('force')) {
            $this->warn(sprintf(
                'DRY RUN — nothing was deleted. %d account(s) would be removed. Re-run with --force to apply.',
                $purgeable->count()
            ));

            return self::SUCCESS;
        }

        DB::transaction(function () use ($purgeable): void {
            SuchakAccount::query()->whereIn('id', $purgeable->pluck('id')->all())->delete();
        });

        $this->info(sprintf('Deleted %d abandoned Suchak signup(s).', $purgeable->count()));

        return self::SUCCESS;
    }

    /**
     * Every table the live schema says points at suchak_accounts.
     *
     * @return array<int, string>
     */
    private function referencingTables(): array
    {
        $tables = [];
        foreach (Schema::getTableListing() as $table) {
            // Some drivers return schema-qualified names.
            $name = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;
            if ($name === 'suchak_accounts') {
                continue;
            }
            if (Schema::hasColumn($name, 'suchak_account_id')) {
                $tables[] = $name;
            }
        }

        return $tables;
    }

    /**
     * @param  array<int, string>  $tables
     * @param  array<int, int>  $accountIds
     * @return array<int, true>  account ids that appear somewhere
     */
    private function accountIdsWithAnyRelatedRow(array $tables, array $accountIds): array
    {
        $used = [];
        foreach ($tables as $table) {
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
