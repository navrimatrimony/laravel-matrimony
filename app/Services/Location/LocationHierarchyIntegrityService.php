<?php

namespace App\Services\Location;

use App\Models\Location;

class LocationHierarchyIntegrityService
{
    /**
     * True if $ancestorId appears anywhere up-chain from $startId (would create a cycle if used as parent).
     */
    public function isInAncestorChain(int $startId, int $ancestorId): bool
    {
        if ($startId <= 0 || $ancestorId <= 0) {
            return false;
        }

        $id = $startId;
        $guard = 0;
        while ($id > 0 && $guard < 128) {
            if ($id === $ancestorId) {
                return true;
            }
            $id = (int) (Location::query()->whereKey($id)->value('parent_id') ?? 0);
            $guard++;
        }

        return false;
    }

    /**
     * Another row under the same parent with same normalized name (case-insensitive) and same type when provided.
     */
    public function duplicateSiblingExists(?int $parentId, string $name, ?int $exceptLocationId = null, ?string $type = null): bool
    {
        $key = mb_strtolower(trim($name), 'UTF-8');
        if ($key === '') {
            return false;
        }

        $q = Location::query()->whereRaw('LOWER(TRIM(name)) = ?', [$key]);
        if ($parentId === null) {
            $q->whereNull('parent_id');
        } else {
            $q->where('parent_id', $parentId);
        }
        if ($type !== null && $type !== '') {
            $q->where('type', $type);
        }
        if ($exceptLocationId !== null) {
            $q->where('id', '!=', $exceptLocationId);
        }

        return $q->exists();
    }

    /**
     * Slug unique among siblings (same parent_id).
     */
    public function uniqueSlugForParent(?int $parentId, string $baseSlug, ?int $exceptLocationId = null): string
    {
        $slug = $baseSlug;
        $n = 0;
        while ($this->slugTakenAmongSiblings($parentId, $slug, $exceptLocationId)) {
            $n++;
            $slug = $baseSlug.'-'.$n;
        }

        return $slug;
    }

    private function slugTakenAmongSiblings(?int $parentId, string $slug, ?int $exceptLocationId): bool
    {
        $q = Location::query()->where('slug', $slug);
        if ($parentId === null) {
            $q->whereNull('parent_id');
        } else {
            $q->where('parent_id', $parentId);
        }
        if ($exceptLocationId !== null) {
            $q->where('id', '!=', $exceptLocationId);
        }

        return $q->exists();
    }

    /**
     * Slug must remain globally unique (legacy schema: locations.slug unique index).
     */
    public function uniqueSlugGlobally(string $baseSlug, ?int $exceptLocationId = null): string
    {
        $slug = $baseSlug;
        $n = 0;
        while ($this->slugTakenGlobally($slug, $exceptLocationId)) {
            $n++;
            $slug = $baseSlug.'-'.$n;
        }

        return $slug;
    }

    private function slugTakenGlobally(string $slug, ?int $exceptLocationId): bool
    {
        $q = Location::query()->where('slug', $slug);
        if ($exceptLocationId !== null) {
            $q->where('id', '!=', $exceptLocationId);
        }

        return $q->exists();
    }
}
