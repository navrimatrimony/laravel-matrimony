<?php

namespace App\Console\Commands;

use App\Models\Location;
use App\Services\Location\LocationCategoryResolver;
use App\Services\Location\LocationMergeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Production-safe: normalize legacy types, merge true duplicates (same parent_id + same normalized name + same type),
 * then set {@see Location::$category} from business rules.
 */
class LocationsDedupeAndCategorize extends Command
{
    /** @var array<string, true> */
    private array $dedupeIgnoredGroupKeys = [];

    protected $signature = 'locations:dedupe-and-categorize
                            {--dry-run : List duplicate groups and category assignments without writing}
                            {--without-normalize-types : Skip legacy type normalization (city->district)}
                            {--without-dedupe : Only assign category}
                            {--without-categorize : Only dedupe}';

    protected $description = 'Merge duplicate location rows (same parent + name + type) and assign category (metro/city/town/village/suburban)';

    public function __construct(
        private readonly LocationMergeService $mergeService,
        private readonly LocationCategoryResolver $categoryResolver,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $geoTable = (new Location)->getTable();

        if (! Schema::hasTable($geoTable)) {
            $this->error('Geographic SSOT table "'.$geoTable.'" does not exist.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $doNormalizeTypes = ! (bool) $this->option('without-normalize-types');
        $doDedupe = ! (bool) $this->option('without-dedupe');
        $doCategory = ! (bool) $this->option('without-categorize');

        if ($dryRun) {
            $this->warn('Dry run: no database writes.');
        }

        if ($doNormalizeTypes) {
            $this->normalizeLegacyTypes($dryRun);
        }

        if ($doDedupe) {
            $this->dedupeIgnoredGroupKeys = [];
            $this->dedupeDuplicates($dryRun);
        }

        if ($doCategory && ! Schema::hasColumn($geoTable, 'tag')) {
            $this->error('Column '.$geoTable.'.tag is missing. Run: php artisan migrate');

            return self::FAILURE;
        }

        if ($doCategory) {
            $this->assignCategories($dryRun);
        }

        $this->info('Done.');

        return self::SUCCESS;
    }

    private function dedupeDuplicates(bool $dryRun): void
    {
        $merged = 0;
        $skipped = 0;

        if ($dryRun) {
            $groups = $this->buildDuplicateGroups();
            ksort($groups);
            foreach ($groups as $key => $rows) {
                $ids = array_map(static fn ($r) => (int) $r->id, $rows);
                sort($ids, SORT_NUMERIC);
                $types = array_values(array_unique(array_map(static fn ($r) => (string) $r->type, $rows)));
                $this->line(sprintf(
                    '[dry-run] Duplicate key=%s keep id=%d merge all=%s types=%s',
                    $key,
                    $ids[0],
                    json_encode($ids),
                    implode('|', $types)
                ));
                if (count($types) !== 1) {
                    $skipped++;
                }
            }
            $this->info("Dedupe dry-run: {$skipped} group(s) need manual review (mixed types).");

            return;
        }

        while (($group = $this->findNextDuplicateGroup()) !== null) {
            [$groupKey, $survivorId, $duplicateIds, $types] = $group;

            $this->line(sprintf(
                'Duplicate group: keep id=%d, merge ids=[%s] (type=%s)',
                $survivorId,
                implode(', ', $duplicateIds),
                $types[0]
            ));

            rsort($duplicateIds, SORT_NUMERIC);
            $groupFailed = false;
            foreach ($duplicateIds as $sourceId) {
                if ((int) $sourceId === (int) $survivorId) {
                    continue;
                }
                try {
                    $this->mergeService->mergeInto((int) $sourceId, (int) $survivorId);
                    $merged++;
                } catch (Throwable $e) {
                    $this->error(sprintf('Merge failed %d → %d: %s', $sourceId, $survivorId, $e->getMessage()));
                    $this->dedupeIgnoredGroupKeys[$groupKey] = true;
                    $skipped++;
                    $groupFailed = true;
                    break;
                }
            }
            if ($groupFailed) {
                continue;
            }
        }

        $this->info("Dedupe: merged {$merged} duplicate row(s); skipped {$skipped} group(s).");
    }

    /**
     * @return array{0: string, 1: int, 2: array<int, int>, 3: array<int, string>}|null
     */
    private function findNextDuplicateGroup(): ?array
    {
        $groups = $this->buildDuplicateGroups();
        ksort($groups);
        foreach ($groups as $key => $rows) {
            if (isset($this->dedupeIgnoredGroupKeys[$key])) {
                continue;
            }
            if (count($rows) < 2) {
                continue;
            }
            $ids = array_map(static fn ($r) => (int) $r->id, $rows);
            sort($ids, SORT_NUMERIC);
            $survivorId = $ids[0];
            $types = array_values(array_unique(array_map(static fn ($r) => (string) $r->type, $rows)));
            if (count($types) !== 1) {
                $this->dedupeIgnoredGroupKeys[$key] = true;
                $this->warn(sprintf(
                    'Skipping duplicate key %s: mixed types [%s] (manual cleanup required).',
                    $key,
                    implode(', ', $types)
                ));

                continue;
            }

            return [$key, $survivorId, $ids, $types];
        }

        return null;
    }

    /**
     * @return array<string, list<object{id:int,parent_id:int|null,name:string,type:string}>>
     */
    private function buildDuplicateGroups(): array
    {
        $byKey = [];
        foreach (Location::query()->orderBy('id')->cursor() as $loc) {
            $parentKey = $loc->parent_id === null ? 'null' : (string) (int) $loc->parent_id;
            $norm = mb_strtolower(trim((string) $loc->name), 'UTF-8');
            if ($norm === '') {
                continue;
            }
            $key = $parentKey.'|'.$norm;
            $byKey[$key][] = (object) [
                'id' => (int) $loc->id,
                'parent_id' => $loc->parent_id !== null ? (int) $loc->parent_id : null,
                'name' => (string) $loc->name,
                'type' => (string) $loc->type,
            ];
        }

        return array_filter($byKey, static fn ($rows) => count($rows) > 1);
    }

    private function assignCategories(bool $dryRun): void
    {
        $geoTable = (new Location)->getTable();
        $updates = 0;
        foreach (Location::query()->orderBy('id')->cursor() as $loc) {
            $category = $this->categoryResolver->resolve((string) $loc->name, (string) $loc->type);
            $categoryStr = $category ?? '';
            $currentStr = (string) ($loc->category ?? '');
            if ($categoryStr === $currentStr) {
                continue;
            }

            if ($dryRun) {
                $this->line(sprintf('id=%d name=%s type=%s → category=%s', $loc->id, $loc->name, $loc->type, $categoryStr !== '' ? $categoryStr : '(null)'));
                $updates++;

                continue;
            }

            DB::table($geoTable)->where('id', $loc->id)->update([
                'tag' => $category,
                'updated_at' => now(),
            ]);
            $updates++;
        }

        $this->info("Category: {$updates} row(s) ".($dryRun ? 'would be updated' : 'updated').'.');
    }

    /**
     * Converts legacy city rows to district without breaking links:
     * - preferred parent is state's id when current parent is a district with state parent
     * - if target district already exists (same parent + name), merges city into that district
     */
    private function normalizeLegacyTypes(bool $dryRun): void
    {
        $changed = 0;
        $merged = 0;

        $cityRows = Location::query()->where('type', 'city')->orderBy('id')->get();
        foreach ($cityRows as $city) {
            $city->loadMissing('parent.parent');
            $targetParentId = $city->parent_id;
            $parent = $city->parent;
            if ($parent !== null && (string) $parent->type === 'district' && $parent->parent_id !== null) {
                $state = $parent->parent;
                if ($state !== null && (string) $state->type === 'state') {
                    $targetParentId = (int) $state->id;
                }
            }

            $existingDistrict = Location::query()
                ->where('type', 'district')
                ->where('name', $city->name)
                ->where('parent_id', $targetParentId)
                ->where('id', '!=', $city->id)
                ->orderBy('id')
                ->first();

            if ($existingDistrict !== null) {
                if ($dryRun) {
                    $this->line(sprintf('[dry-run] merge city#%d into district#%d (%s)', $city->id, $existingDistrict->id, $city->name));
                    $merged++;
                    continue;
                }
                try {
                    $this->mergeService->mergeInto((int) $city->id, (int) $existingDistrict->id);
                    $merged++;
                } catch (Throwable $e) {
                    $this->error(sprintf('Normalize merge failed %d → %d: %s', $city->id, $existingDistrict->id, $e->getMessage()));
                }
                continue;
            }

            if ($dryRun) {
                $this->line(sprintf('[dry-run] city#%d %s: type city -> district, parent %s -> %s', $city->id, $city->name, var_export($city->parent_id, true), var_export($targetParentId, true)));
                $changed++;
                continue;
            }

            DB::table((new Location)->getTable())->where('id', $city->id)->update([
                'type' => 'district',
                'parent_id' => $targetParentId,
                'level' => 2,
                'updated_at' => now(),
            ]);
            $changed++;
        }

        $this->info('Normalize: '.($dryRun ? 'would change' : 'changed')." {$changed} row(s), ".($dryRun ? 'would merge' : 'merged')." {$merged} row(s).");
    }
}
