<?php

namespace App\Http\Controllers\Internal\Admin;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\LocationOpenPlaceSuggestion;
use App\Services\Admin\LocationOpenPlaceApprovalService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OpenPlaceSuggestionAdminController extends Controller
{
    public function __construct(
        private LocationOpenPlaceApprovalService $approvalService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:pending,auto_candidate,approved,rejected,merged'],
            'min_usage' => ['nullable', 'integer', 'min:0'],
            'q' => ['nullable', 'string', 'max:200'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $unresolvedOnly = $request->boolean('unresolved_only');
        $highPriority = $request->boolean('high_priority');

        $query = LocationOpenPlaceSuggestion::query()
            ->with(['resolvedCity', 'suggestedBy', 'mergedInto'])
            ->orderByDesc('usage_count')
            ->orderByDesc('created_at');

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (array_key_exists('min_usage', $validated) && $validated['min_usage'] !== null) {
            $query->where('usage_count', '>=', (int) $validated['min_usage']);
        }
        if ($unresolvedOnly) {
            $query->whereNull('resolved_city_id');
        }
        if ($highPriority) {
            $query->where('usage_count', '>=', 5);
        }
        if (! empty($validated['q'])) {
            $term = '%'.addcslashes(trim($validated['q']), '%_\\').'%';
            $query->where('raw_input', 'like', $term);
        }

        $perPage = (int) ($validated['per_page'] ?? 25);
        $paginator = $query->paginate($perPage)->withQueryString();

        return response()->json([
            'success' => true,
            'data' => $paginator,
        ]);
    }

    /**
     * Typeahead for “map to existing city” (admin-only; name LIKE, capped).
     */
    public function citySearch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $q = trim($validated['q'] ?? '');
        if (mb_strlen($q) < 2) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $like = '%'.addcslashes($q, '%_\\').'%';
        $rows = City::query()
            ->with(['taluka.district.state'])
            ->where('name', 'like', $like)
            ->orderBy('name')
            ->limit(30)
            ->get();

        $data = $rows->map(static function (City $c) {
            return [
                'id' => $c->id,
                'name' => $c->name,
                'taluka' => $c->taluka?->name,
                'district' => $c->taluka?->district?->name,
                'state' => $c->taluka?->district?->state?->name,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function approveAsCity(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'taluka_id' => ['required', 'integer', 'exists:talukas,id'],
            'district_id' => ['nullable', 'integer', 'exists:districts,id'],
        ]);

        try {
            $this->approvalService->approveAsNewCity(
                $id,
                (int) $request->user()->id,
                (int) $validated['taluka_id'],
                isset($validated['district_id']) ? (int) $validated['district_id'] : null,
            );

            return response()->json([
                'success' => true,
                'message' => 'City created and suggestion approved.',
            ]);
        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Suggestion not found or not pending.',
            ], 404);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function mapToCity(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'city_id' => ['required', 'integer', 'exists:cities,id'],
        ]);

        try {
            $this->approvalService->mapToExistingCity(
                $id,
                (int) $request->user()->id,
                (int) $validated['city_id'],
            );

            return response()->json([
                'success' => true,
                'message' => 'Alias added and suggestion approved.',
            ]);
        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Suggestion or city not found, or suggestion not pending.',
            ], 404);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        try {
            $this->approvalService->reject($id, (int) $request->user()->id);

            return response()->json([
                'success' => true,
                'message' => 'Suggestion rejected.',
            ]);
        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Suggestion not found or not pending.',
            ], 404);
        }
    }

    public function merge(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'target_id' => ['required', 'integer', 'exists:location_open_place_suggestions,id'],
        ]);

        try {
            $this->approvalService->mergeInto(
                $id,
                (int) $validated['target_id'],
                (int) $request->user()->id,
            );

            return response()->json([
                'success' => true,
                'message' => 'Suggestions merged.',
            ]);
        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Row not found.',
            ], 404);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
