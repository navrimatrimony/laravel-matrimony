<?php

namespace App\Services\Location;

use App\Models\Location;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * SSOT cleanup: strip redundant " City" suffix from names, standardize slugs + levels, dedupe by (parent_id, name, type).
 */
class LocationSsotNormalizationService
{
    public function __construct(
        private readonly LocationHierarchyIntegrityService $integrity,
        private readonly LocationMergeService $mergeService,
    ) {}

    public function normalizeNamesSlugsLevels(): void
    {
        if (! Schema::hasTable(Location::geoTable())) {
            return;
        }

        Location::query()->orderBy('id')->each(function (Location $loc): void {
            $name = trim((string) $loc->name);
            $clean = preg_replace('/\s+City$/iu', '', $name);
            $clean = trim((string) $clean);
            if ($clean === '') {
                $clean = $name;
            }

            $baseSlug = self::standardSlug($clean, (string) $loc->type);
            $slug = $this->integrity->uniqueSlugGlobally($baseSlug, (int) $loc->id);

            $loc->name = $clean;
            $loc->slug = $slug;
            $loc->level = Location::defaultLevelForType((string) $loc->type);
            $loc->saveQuietly();
        });
    }

    /**
     * Merge duplicates into the lowest id per (parent_id, LOWER(TRIM(name)), type).
     */
    public function deduplicateParents(): void
    {
        if (! Schema::hasTable(Location::geoTable())) {
            return;
        }

        $keepers = [];

        foreach (Location::query()->orderBy('id')->cursor() as $loc) {
            $parentKey = $loc->parent_id === null ? '_root_' : (string) $loc->parent_id;
            $key = $parentKey.'|'.mb_strtolower(trim((string) $loc->name), 'UTF-8').'|'.(string) $loc->type;

            if (! isset($keepers[$key])) {
                $keepers[$key] = (int) $loc->id;

                continue;
            }

            $this->mergeService->mergeInto((int) $loc->id, $keepers[$key]);
        }
    }

    public function addUniqueIdentityConstraint(): void
    {
        $geo = Location::geoTable();

        if (! Schema::hasTable($geo)) {
            return;
        }

        $indexName = $geo.'_parent_name_type_unique';

        if (Schema::hasIndex($geo, $indexName)) {
            return;
        }

        Schema::table($geo, function ($table) use ($indexName): void {
            $table->unique(['parent_id', 'name', 'type'], $indexName);
        });
    }

    public static function standardSlug(string $name, string $type): string
    {
        $base = Str::slug(trim($name));

        return ($base !== '' ? $base : 'loc').'-'.$type;
    }
}
