<?php

namespace App\Http\Controllers\Internal\Admin;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Services\Location\LocationDuplicateDetectionService;
use App\Services\Location\LocationHierarchyIntegrityService;
use App\Services\Location\LocationMergeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CanonicalLocationAdminController extends Controller
{
    public function __construct(
        private readonly LocationHierarchyIntegrityService $integrity,
        private readonly LocationDuplicateDetectionService $duplicates,
        private readonly LocationMergeService $mergeService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', 'string', 'max:32'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $geo = Location::geoTable();
        $query = Location::query()->with(['parent', 'usageStat']);

        if (Schema::hasTable('location_usage_stats')) {
            $query->leftJoin('location_usage_stats as lus', $geo.'.id', '=', 'lus.location_id')
                ->select($geo.'.*')
                ->orderByDesc(DB::raw('COALESCE(lus.usage_count, 0)'))
                ->orderBy($geo.'.name');
        } else {
            $query->orderBy($geo.'.name');
        }

        if (! empty($validated['q'])) {
            $term = '%'.addcslashes(trim($validated['q']), '%_\\').'%';
            $query->where(function ($q) use ($term, $geo) {
                $q->where($geo.'.name', 'like', $term)
                    ->orWhere($geo.'.slug', 'like', $term);
            });
        }
        if (! empty($validated['type'])) {
            $query->where($geo.'.type', $validated['type']);
        }

        $perPage = (int) ($validated['per_page'] ?? 40);
        $paginator = $query->paginate($perPage)->withQueryString();

        return response()->json([
            'success' => true,
            'data' => $paginator,
        ]);
    }

    public function show(Location $location): JsonResponse
    {
        $location->load(['parent', 'aliases', 'usageStat', 'pincodes']);
        $similar = $this->duplicates->findSimilar($location);

        return response()->json([
            'success' => true,
            'data' => [
                'location' => $location,
                'possible_duplicates' => $similar,
            ],
        ]);
    }

    public function update(Request $request, Location $location): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:1', 'max:255'],
            'type' => ['sometimes', 'string', Rule::in([
                'country', 'state', 'district', 'taluka', 'village',
            ])],
            'category' => ['nullable', 'string', Rule::in([
                'metro', 'city', 'town', 'village', 'suburban',
            ])],
            'parent_id' => ['nullable', 'integer', 'exists:'.Location::geoTable().',id'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $parentId = array_key_exists('parent_id', $data)
            ? ($data['parent_id'] !== null ? (int) $data['parent_id'] : null)
            : ($location->parent_id !== null ? (int) $location->parent_id : null);
        if ($parentId !== null && (int) $parentId === (int) $location->id) {
            return response()->json([
                'success' => false,
                'message' => 'Location cannot be its own parent.',
            ], 422);
        }
        if ($parentId !== null && $this->integrity->isInAncestorChain((int) $parentId, (int) $location->id)) {
            return response()->json([
                'success' => false,
                'message' => 'Parent would create a circular hierarchy.',
            ], 422);
        }

        $newName = $data['name'] ?? $location->name;
        $typeForUniqueness = (string) ($data['type'] ?? $location->type);

        if ($this->integrity->duplicateSiblingExists($parentId, (string) $newName, (int) $location->id, $typeForUniqueness)) {
            return response()->json([
                'success' => false,
                'message' => 'Another location with the same name already exists under this parent.',
            ], 422);
        }

        if (isset($data['name'])) {
            $base = Str::slug($data['name']) ?: 'location';
            $data['slug'] = $this->integrity->uniqueSlugForParent(
                $parentId !== null ? (int) $parentId : null,
                $base,
                (int) $location->id
            );
        }

        $location->fill($data);
        $location->save();

        return response()->json([
            'success' => true,
            'data' => $location->fresh(['parent', 'usageStat']),
        ]);
    }

    public function merge(Request $request, Location $location): JsonResponse
    {
        $validated = $request->validate([
            'target_location_id' => ['required', 'integer', 'exists:'.Location::geoTable().',id', Rule::notIn([(int) $location->id])],
        ]);

        try {
            $this->mergeService->mergeInto((int) $location->id, (int) $validated['target_location_id']);

            return response()->json([
                'success' => true,
                'message' => 'Locations merged.',
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function possibleDuplicates(Location $location): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->duplicates->findSimilar($location),
        ]);
    }
}
