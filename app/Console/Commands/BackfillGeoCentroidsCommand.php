<?php

namespace App\Console\Commands;

use App\Services\Location\GeoCentroidBackfillService;
use Illuminate\Console\Command;

/**
 * Recompute the derived taluka / district coordinates in `addresses.lat` / `addresses.lng`.
 *
 * The one-time fill happens in the 2026_07_26_170100 migration. This is the maintenance twin: run it
 * whenever the villages underneath change, otherwise a taluka keeps the centre of the villages it had
 * at import time.
 *
 *   - after importing or re-importing `addresses`  → `--force` (existing rows must be recomputed)
 *   - after `locations:update-village-coordinates` → `--force`
 *   - after adding a handful of new talukas         → no flag (fills only the empty ones)
 *
 * Both modes are safe to re-run; without `--force` an already-populated coordinate is never touched,
 * so an operator's manual correction survives.
 *
 * Scope is per state and comes from {@see GeoCentroidBackfillService::STATE_BOUNDS} — enabling another
 * state is one array entry there, no schema change and no flag change here.
 */
class BackfillGeoCentroidsCommand extends Command
{
    protected $signature = 'locations:backfill-geo-centroids
                            {--force : Recompute rows that already carry a coordinate}
                            {--only= : Restrict to "taluka" or "district" (default: both)}
                            {--state=* : State slug(s) to process (default: every configured state)}';

    protected $description = 'Derive addresses.lat/lng for taluka and district rows from the villages beneath them';

    public function handle(GeoCentroidBackfillService $service): int
    {
        $only = strtolower(trim((string) $this->option('only')));
        if ($only !== '' && ! in_array($only, ['taluka', 'district'], true)) {
            $this->error('--only must be "taluka" or "district".');

            return self::FAILURE;
        }

        $supported = GeoCentroidBackfillService::supportedStates();

        /** @var list<string> $requested */
        $requested = array_values(array_filter(array_map(
            static fn ($slug): string => strtolower(trim((string) $slug)),
            (array) $this->option('state')
        )));

        $states = $requested === [] ? $supported : $requested;

        $unknown = array_diff($states, $supported);
        if ($unknown !== []) {
            $this->error('No coordinate bounds configured for: '.implode(', ', $unknown)
                .'. Add an entry to GeoCentroidBackfillService::STATE_BOUNDS first — a state without '
                .'bounds cannot be sanity-checked, and an unchecked centre is worse than none.');

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        if ($force) {
            $this->warn('--force: existing taluka/district coordinates will be recomputed and overwritten.');
        }

        foreach ($states as $stateSlug) {
            $this->line('State: '.$stateSlug);

            // DISTRICTS FIRST — a district centre is the reference point for the taluka distance check.
            if ($only === '' || $only === 'district') {
                $this->report('district', $service->backfillDistricts($force, $stateSlug));
            }

            if ($only === '' || $only === 'taluka') {
                $this->report('taluka', $service->backfillTalukas($force, $stateSlug));
            }
        }

        $this->newLine();
        $this->line('Verify with: php artisan locations:audit-geo-centroids --state='.implode(',', $states));

        return self::SUCCESS;
    }

    /**
     * @param  array{filled: int, skipped: int, without_source: int, rejected: int, rejections: array<string, int>, state: string}  $result
     */
    private function report(string $hierarchy, array $result): void
    {
        $this->info(sprintf(
            '  %-9s written %d, left as-is %d, no usable villages %d, refused %d',
            $hierarchy,
            $result['filled'],
            $result['skipped'],
            $result['without_source'],
            $result['rejected'],
        ));

        foreach ($result['rejections'] as $reason => $count) {
            $this->line(sprintf('    refused (%s): %d', $reason, $count));
        }

        $unresolved = $result['without_source'] + $result['rejected'];
        if ($unresolved > 0) {
            $this->line(sprintf(
                '    %d %s row(s) keep a NULL coordinate. That is deliberate: a centre we cannot defend '
                .'would produce confidently wrong "nearby" answers, whereas a NULL one falls back to the '
                .'row itself and merely narrows the pool.',
                $unresolved,
                $hierarchy,
            ));
        }
    }
}
