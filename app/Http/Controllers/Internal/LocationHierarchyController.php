<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\District;
use App\Models\Location;
use App\Models\State;
use App\Models\Taluka;
use App\Services\Location\LocationService;
use App\Support\Validation\AddressHierarchyRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class LocationHierarchyController extends Controller
{
    /**
     * States (addresses.hierarchy = state).
     * Optional {@code parent_ids[]} lists country row ids (parents of states); when omitted, returns all states.
     */
    public function states(Request $request): JsonResponse
    {
        if ($request->has('parent_ids')) {
            $ids = $request->input('parent_ids', []);
            if (! is_array($ids)) {
                $ids = [];
            }
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
            if ($ids === []) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                ]);
            }
            $request->validate([
                'parent_ids' => ['required', 'array', 'min:1'],
                'parent_ids.*' => ['integer', AddressHierarchyRules::existsCountryId()],
            ]);
            $states = State::whereIn('parent_id', $ids)->orderBy('name')->get(['id', 'name', 'parent_id']);
        } else {
            $states = State::orderBy('name')->get(['id', 'name', 'parent_id']);
        }

        return response()->json([
            'success' => true,
            'data' => $states,
        ]);
    }

    /**
     * Districts under one or more state rows. Parent id(s) must be {@code addresses} rows with hierarchy=state.
     */
    public function districts(Request $request): JsonResponse
    {
        if ($request->filled('parent_ids')) {
            $request->validate([
                'parent_ids' => ['required', 'array', 'min:1'],
                'parent_ids.*' => ['integer', AddressHierarchyRules::existsStateId()],
            ]);
            $ids = array_values(array_unique(array_map('intval', $request->input('parent_ids', []))));
            $districts = District::whereIn('parent_id', $ids)->orderBy('name')->get(['id', 'name', 'slug', 'parent_id']);

            return response()->json([
                'success' => true,
                'data' => $districts,
            ]);
        }

        $request->validate([
            'parent_id' => ['required', 'integer', AddressHierarchyRules::existsStateId()],
        ]);

        $districts = District::where('parent_id', $request->integer('parent_id'))
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'parent_id']);

        return response()->json([
            'success' => true,
            'data' => $districts,
        ]);
    }

    /**
     * Talukas under one or more district rows.
     */
    public function talukas(Request $request): JsonResponse
    {
        if ($request->filled('parent_ids')) {
            $request->validate([
                'parent_ids' => ['required', 'array', 'min:1'],
                'parent_ids.*' => ['integer', AddressHierarchyRules::existsDistrictId()],
            ]);
            $ids = array_values(array_unique(array_map('intval', $request->input('parent_ids', []))));
            $talukas = Taluka::whereIn('parent_id', $ids)->orderBy('name')->get(['id', 'name', 'parent_id']);

            return response()->json([
                'success' => true,
                'data' => $talukas,
            ]);
        }

        $request->validate([
            'parent_id' => ['required', 'integer', AddressHierarchyRules::existsDistrictId()],
        ]);

        $talukas = Taluka::where('parent_id', $request->integer('parent_id'))
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id']);

        return response()->json([
            'success' => true,
            'data' => $talukas,
        ]);
    }

    /**
     * Cities under one taluka row (parent must be hierarchy=taluka).
     */
    public function cities(Request $request): JsonResponse
    {
        $request->validate([
            'parent_id' => ['required', 'integer', AddressHierarchyRules::existsTalukaId()],
        ]);

        $cities = City::where('parent_id', $request->integer('parent_id'))
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id']);

        return response()->json([
            'success' => true,
            'data' => $cities,
        ]);
    }

    /**
     * Parent-scoped mobile picker rows from the canonical addresses table.
     */
    public function children(Request $request, LocationService $locationService): JsonResponse
    {
        $validated = $request->validate([
            'parent_id' => ['required', 'integer'],
            'q' => ['nullable', 'string', 'max:120'],
            'types' => ['nullable', 'string', 'max:120'],
            'tags' => ['nullable', 'string', 'max:120'],
            'locale' => ['nullable', 'string', Rule::in(['en', 'mr'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        if (! empty($validated['locale'])) {
            app()->setLocale((string) $validated['locale']);
        }

        $page = max(1, (int) ($validated['page'] ?? 1));
        $limit = min(200, max(1, (int) ($validated['limit'] ?? 80)));

        $parent = Location::query()
            ->whereKey((int) $validated['parent_id'])
            ->when($this->locationHasColumn('is_active'), fn ($query) => $query->where('is_active', true))
            ->first();
        if (! $parent) {
            return $this->emptyChildrenResponse($page, $limit);
        }

        $search = trim((string) ($validated['q'] ?? ''));
        $types = $this->csvList($validated['types'] ?? null, ['taluka', 'city', 'suburb', 'suburban', 'village', 'rural']);
        $tags = $this->csvList($validated['tags'] ?? null, ['city', 'suburban', 'rural']);

        $query = Location::query();
        $this->applyActiveFilter($query);
        $parentHierarchy = (string) $parent->hierarchy;
        if ($parentHierarchy === 'district') {
            $talukaQuery = Location::query()
                ->where('hierarchy', 'taluka')
                ->where('parent_id', (int) $parent->id);
            $this->applyActiveFilter($talukaQuery);

            $talukaIds = $talukaQuery->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            $includeTaluka = $types === [] || in_array('taluka', $types, true);
            $leafTags = $tags !== [] ? $tags : $this->leafTagsForTypes($types);

            if (! $includeTaluka && ($leafTags === [] || $talukaIds === [])) {
                return $this->emptyChildrenResponse($page, $limit);
            }

            $query->where(function ($scope) use ($includeTaluka, $leafTags, $parent, $talukaIds): void {
                if ($includeTaluka) {
                    $scope->where(function ($talukas) use ($parent): void {
                        $talukas->where('hierarchy', 'taluka')
                            ->where('parent_id', (int) $parent->id);
                    });
                }

                if ($talukaIds !== [] && $leafTags !== []) {
                    $method = $includeTaluka ? 'orWhere' : 'where';
                    $scope->{$method}(function ($leaves) use ($talukaIds, $leafTags): void {
                        $leaves->where('hierarchy', 'village')
                            ->whereIn('parent_id', $talukaIds);
                        $this->applyLeafTagFilter($leaves, $leafTags);
                    });
                }
            });
        } elseif ($parentHierarchy === 'taluka') {
            $leafTags = $tags !== [] ? $tags : $this->leafTagsForTypes($types);
            if ($leafTags === [] && $types !== []) {
                return $this->emptyChildrenResponse($page, $limit);
            }

            $query->where('hierarchy', 'village')
                ->where('parent_id', (int) $parent->id);
            if ($leafTags !== []) {
                $this->applyLeafTagFilter($query, $leafTags);
            }
        } else {
            return $this->emptyChildrenResponse($page, $limit);
        }

        if (mb_strlen($search, 'UTF-8') >= 2) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $pincodeLike = addcslashes($search, '%_\\').'%';
            $query->where(function ($scope) use ($like, $pincodeLike): void {
                $scope->where('name', 'like', $like)
                    ->orWhere('slug', 'like', $like)
                    ->orWhere('name_en', 'like', $like)
                    ->orWhere('name_mr', 'like', $like)
                    ->orWhere('pincode', 'like', $pincodeLike);
            });
        }

        $rows = $query
            ->orderByRaw("CASE WHEN hierarchy = 'taluka' THEN 0 WHEN hierarchy = 'village' AND tag = 'suburban' THEN 1 WHEN hierarchy = 'village' AND tag = 'city' THEN 2 WHEN hierarchy = 'village' AND (tag = 'rural' OR tag IS NULL) THEN 3 ELSE 4 END")
            ->orderBy('name')
            ->orderBy('id')
            ->skip(($page - 1) * $limit)
            ->take($limit + 1)
            ->get();

        $hasMore = $rows->count() > $limit;
        $results = $rows->take($limit)
            ->map(fn (Location $row): array => $this->childLocationItem($row, $locationService))
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'data' => [
                'results' => $results,
                'pagination' => [
                    'page' => $page,
                    'per_page' => $limit,
                    'has_more' => $hasMore,
                ],
            ],
        ]);
    }

    /**
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private function csvList(mixed $value, array $allowed): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        return collect(explode(',', $value))
            ->map(fn (string $item): string => trim($item))
            ->filter(fn (string $item): bool => in_array($item, $allowed, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $types
     * @return list<string>
     */
    private function leafTagsForTypes(array $types): array
    {
        if ($types === []) {
            return ['city', 'suburban', 'rural'];
        }

        $tags = [];
        if (in_array('city', $types, true)) {
            $tags[] = 'city';
        }
        if (in_array('suburb', $types, true) || in_array('suburban', $types, true)) {
            $tags[] = 'suburban';
        }
        if (in_array('village', $types, true) || in_array('rural', $types, true)) {
            $tags[] = 'rural';
        }

        return array_values(array_unique($tags));
    }

    private function applyActiveFilter($query): void
    {
        if ($this->locationHasColumn('is_active')) {
            $query->where('is_active', true);
        }
    }

    /**
     * @param  list<string>  $leafTags
     */
    private function applyLeafTagFilter($query, array $leafTags): void
    {
        $tagValues = array_values(array_filter(
            $leafTags,
            fn (string $tag): bool => in_array($tag, ['city', 'suburban', 'rural'], true),
        ));
        $includeNull = in_array('rural', $tagValues, true);

        $query->where(function ($scope) use ($tagValues, $includeNull): void {
            if ($tagValues !== []) {
                $scope->whereIn('tag', $tagValues);
                if ($includeNull) {
                    $scope->orWhereNull('tag');
                }

                return;
            }

            if ($includeNull) {
                $scope->whereNull('tag');
            }
        });
    }

    private function emptyChildrenResponse(int $page, int $limit): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'results' => [],
                'pagination' => ['page' => $page, 'per_page' => $limit, 'has_more' => false],
            ],
        ]);
    }

    private function locationHasColumn(string $column): bool
    {
        static $cache = [];

        $table = Location::geoTable();
        $key = "{$table}.{$column}";
        if (! array_key_exists($key, $cache)) {
            $cache[$key] = Schema::hasColumn($table, $column);
        }

        return $cache[$key];
    }

    private function childLocationItem(Location $row, LocationService $locationService): array
    {
        $row->loadMissing('parent');
        $locationService->ensureAncestorsLoaded($row);
        $hierarchy = $locationService->fillHierarchyGaps($row, $locationService->getFullHierarchy($row));
        $type = $this->mobileLocationType($row);
        $tag = $row->tag === null ? null : (string) $row->tag;
        $isFinal = (string) $row->hierarchy === 'village' && ($tag === null || in_array($tag, ['city', 'suburban', 'rural'], true));
        $label = $this->simpleLocationLabel($row);
        $pathLabel = $locationService->getDisplayLabel($row);
        [$group, $groupLabel] = $this->locationGroup($row);
        $isActive = $this->locationHasColumn('is_active') ? (bool) $row->is_active : true;
        $childrenQuery = $row->children();
        $this->applyActiveFilter($childrenQuery);

        return [
            'id' => (int) $row->id,
            'location_id' => (int) $row->id,
            'key' => $row->slug,
            'name' => $label,
            'name_en' => $row->name_en,
            'name_mr' => $row->name_mr,
            'label' => $label,
            'display_name' => $label,
            'path_label' => $pathLabel,
            'display_hierarchy' => $pathLabel,
            'type' => $type,
            'hierarchy' => (string) $row->hierarchy,
            'tag' => $tag,
            'group' => $group,
            'group_label' => $groupLabel,
            'parent_id' => $row->parent_id ? (int) $row->parent_id : null,
            'status' => $isActive ? 'approved' : 'inactive',
            'is_active' => $isActive,
            'is_final_node' => $isFinal,
            'has_children' => (bool) $childrenQuery->exists(),
            'pincode' => $row->pincode,
            'state_id' => $hierarchy['state']?->id ? (int) $hierarchy['state']->id : null,
            'district_id' => $hierarchy['district']?->id ? (int) $hierarchy['district']->id : null,
            'taluka_id' => $hierarchy['taluka']?->id ? (int) $hierarchy['taluka']->id : null,
            'parent' => [
                'state' => $this->parentNode($hierarchy['state'] ?? null),
                'district' => $this->parentNode($hierarchy['district'] ?? null),
                'taluka' => $this->parentNode($hierarchy['taluka'] ?? null),
                'city' => $this->parentNode($hierarchy['city'] ?? null),
            ],
        ];
    }

    private function simpleLocationLabel(Location $location): string
    {
        $label = trim($location->localizedName());
        if ($label !== '') {
            return $label;
        }

        foreach (['name_en', 'name_mr', 'slug'] as $column) {
            $value = trim((string) $location->{$column});
            if ($value !== '') {
                return $value;
            }
        }

        return (string) $location->id;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function locationGroup(Location $location): array
    {
        if ((string) $location->hierarchy === 'taluka') {
            return ['taluka', 'Taluka'];
        }

        if ((string) $location->hierarchy === 'village') {
            return match ((string) ($location->tag ?? '')) {
                'suburban' => ['suburban', 'Suburban'],
                'city' => ['city', 'City'],
                default => ['village', 'Village / Rural'],
            };
        }

        $hierarchy = (string) $location->hierarchy;

        return [$hierarchy, ucfirst($hierarchy)];
    }

    private function mobileLocationType(Location $location): string
    {
        if ((string) $location->hierarchy !== 'village') {
            return (string) $location->hierarchy;
        }

        return match ((string) ($location->tag ?? '')) {
            'city' => 'city',
            'suburban' => 'suburb',
            default => 'village',
        };
    }

    private function parentNode(?Location $location): ?array
    {
        if (! $location instanceof Location) {
            return null;
        }

        return [
            'id' => (int) $location->id,
            'label' => $location->localizedName(),
        ];
    }
}
