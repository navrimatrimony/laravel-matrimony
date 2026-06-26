<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\District;
use App\Models\Location;
use App\Models\State;
use App\Models\Taluka;
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
    public function children(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'parent_id' => ['required', 'integer'],
            'q' => ['nullable', 'string', 'max:120'],
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
            ->first();
        if (! $parent) {
            return $this->emptyChildrenResponse($page, $limit);
        }

        $search = trim((string) ($validated['q'] ?? ''));

        $query = Location::query()
            ->where('parent_id', (int) $parent->id);
        $this->applyActiveFilter($query);

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
            ->orderByRaw("CASE WHEN hierarchy = 'taluka' THEN 0 WHEN tag = 'suburban' THEN 1 WHEN tag = 'city' THEN 2 WHEN tag = 'rural' OR (hierarchy = 'village' AND tag IS NULL) THEN 3 ELSE 4 END")
            ->orderBy('name')
            ->orderBy('id')
            ->skip(($page - 1) * $limit)
            ->take($limit + 1)
            ->get();

        $hasMore = $rows->count() > $limit;
        $results = $rows->take($limit)
            ->map(fn (Location $row): array => $this->childLocationItem($row))
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

    private function applyActiveFilter($query): void
    {
        if ($this->locationHasColumn('is_active')) {
            $query->where('is_active', true);
        }
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

    private function childLocationItem(Location $row): array
    {
        $type = $this->mobileLocationType($row);
        $tag = $row->tag === null ? null : (string) $row->tag;
        $isFinal = (string) $row->hierarchy === 'village' && ($tag === null || in_array($tag, ['city', 'suburban', 'rural'], true));
        $label = $this->simpleLocationLabel($row);
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
                'rural' => ['village', 'Village / Rural'],
                default => ['village', 'Village / Rural'],
            };
        }

        return ['other', 'Other'];
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

}
