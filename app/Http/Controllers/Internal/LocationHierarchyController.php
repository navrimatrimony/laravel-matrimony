<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\District;
use App\Models\Location;
use App\Models\State;
use App\Models\Taluka;
use App\Services\Location\LocationFormatterService;
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

        $query = Location::query();
        if ((string) $parent->hierarchy === 'district') {
            $talukaIdQuery = Location::query()
                ->where('parent_id', (int) $parent->id)
                ->where('hierarchy', 'taluka');
            $this->applyActiveFilter($talukaIdQuery);
            $talukaIds = $talukaIdQuery
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $query->where(function ($scope) use ($parent, $talukaIds): void {
                $scope->where(function ($directTalukas) use ($parent): void {
                    $directTalukas
                        ->where('parent_id', (int) $parent->id)
                        ->where('hierarchy', 'taluka');
                });

                if ($talukaIds !== []) {
                    $scope->orWhere(function ($talukaPlaces) use ($talukaIds): void {
                        $talukaPlaces
                            ->where('hierarchy', 'village')
                            ->whereIn('parent_id', $talukaIds)
                            ->whereIn('tag', ['city', 'suburban']);
                    });
                }
            });
        } else {
            $query->where('parent_id', (int) $parent->id);
        }
        $this->applyActiveFilter($query);

        if (mb_strlen($search, 'UTF-8') >= 2) {
            $prefix = addcslashes($search, '%_\\').'%';
            $columns = array_values(array_filter(
                ['name', 'slug', 'name_en', 'name_mr', 'pincode'],
                fn (string $column): bool => $this->locationHasColumn($column)
            ));
            $query->where(function ($scope) use ($prefix, $columns): void {
                foreach ($columns as $index => $column) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $scope->{$method}($column, 'like', $prefix);
                }
            });
        }

        $rows = $query
            ->orderByRaw("CASE WHEN hierarchy = 'taluka' THEN 0 WHEN hierarchy = 'village' AND tag = 'city' THEN 1 WHEN hierarchy = 'village' AND tag = 'suburban' THEN 2 WHEN hierarchy = 'village' AND (tag = 'rural' OR tag IS NULL OR tag = '') THEN 3 ELSE 4 END")
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
        $displayLabel = app(LocationFormatterService::class)->formatForLocation($row);
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
            'display_label' => $displayLabel,
            'profile_display_label' => $displayLabel,
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
     * @return array{0: string|null, 1: string|null}
     */
    private function locationGroup(Location $location): array
    {
        $hierarchy = trim((string) ($location->hierarchy ?? ''));
        if ($hierarchy === 'taluka') {
            return ['taluka', 'Taluka'];
        }
        if ($hierarchy !== 'village') {
            return [null, null];
        }

        $tag = trim((string) ($location->tag ?? ''));

        return match ($tag) {
            'city' => ['city', 'City'],
            'suburban' => ['suburban', 'Suburban'],
            'rural', '' => ['rural', 'Rural'],
            default => [null, null],
        };
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
