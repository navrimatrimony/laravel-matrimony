<?php

namespace App\Console\Commands;

use App\Models\Interest;
use App\Services\Interest\SuchakRoutedInterestService;
use App\Support\Suchak\SuchakContactRouting;
use Illuminate\Console\Command;

/**
 * Brings interests that were sent to Suchak-routed profiles BEFORE routing
 * existed into the existing Suchak request pipeline, so a Suchak can finally see
 * and answer them.
 *
 * Safe and idempotent by construction:
 *  - reads only `interests` + `suchak_profile_requests`; deletes nothing,
 *    overwrites nothing, changes no interest status
 *  - skips any pair that already has an OPEN request, so re-running creates
 *    nothing extra
 *  - only touches `pending` interests whose receiver is Suchak-routed right now
 *    (valid consent, verified + publicly active Suchak) — everything else is
 *    left exactly as it is
 *  - writes through {@see SuchakRoutedInterestService::routeInterest()}, i.e. the
 *    same path a live heart-tap takes, so a backfilled approach is
 *    indistinguishable from a fresh one
 *
 * Defaults to a DRY RUN. Pass --commit to actually create the requests.
 */
class BackfillSuchakRoutedInterests extends Command
{
    protected $signature = 'suchak:backfill-routed-interests
        {--commit : Actually create the pipeline requests (default is a dry run)}
        {--limit=500 : Maximum interests to scan}';

    protected $description = 'Route already-sent pending interests on Suchak-managed profiles into the Suchak request pipeline (idempotent).';

    public function handle(SuchakRoutedInterestService $routedInterests): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $commit = (bool) $this->option('commit');

        $candidates = $routedInterests->unroutedPendingInterests($limit);

        $this->line('Pending interests scanned (limit '.$limit.'): '.Interest::query()->where('status', 'pending')->count());
        $this->line('Invisible to their Suchak right now: '.$candidates->count());

        if ($candidates->isEmpty()) {
            $this->info('Nothing to backfill.');

            return self::SUCCESS;
        }

        $this->table(
            ['interest_id', 'sender_profile_id', 'receiver_profile_id', 'suchak_account_id', 'sent_at'],
            $candidates->map(function (Interest $interest): array {
                $representation = $interest->receiverProfile !== null
                    ? SuchakContactRouting::routableRepresentationFor($interest->receiverProfile)
                    : null;

                return [
                    (string) $interest->id,
                    (string) $interest->sender_profile_id,
                    (string) $interest->receiver_profile_id,
                    (string) ($representation?->suchak_account_id ?? '-'),
                    (string) ($interest->created_at?->toDateTimeString() ?? '-'),
                ];
            })->all(),
        );

        if (! $commit) {
            $this->warn('DRY RUN — nothing was written. Re-run with --commit to create '.$candidates->count().' pipeline request(s).');

            return self::SUCCESS;
        }

        $result = $routedInterests->backfillUnroutedPendingInterests(false, $limit);

        $this->info('Routed into the Suchak pipeline: '.$result['routed']);

        if ($result['skipped'] > 0) {
            $this->warn('Skipped (no owner account, consent gone, or the Suchak lead limit is full): '.$result['skipped']);
        }

        return self::SUCCESS;
    }
}
