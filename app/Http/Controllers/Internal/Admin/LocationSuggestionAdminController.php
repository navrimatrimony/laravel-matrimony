<?php

namespace App\Http\Controllers\Internal\Admin;

use App\Http\Controllers\Controller;
use App\Models\LocationSuggestion;
use App\Services\LocationSuggestionApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocationSuggestionAdminController extends Controller
{
    public function __construct(
        private LocationSuggestionApprovalService $approvalService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'nullable|in:pending,approved,rejected',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = LocationSuggestion::query()
            ->with(['country', 'state', 'district', 'taluka', 'suggestedBy', 'adminReviewedBy'])
            ->orderByDesc('created_at');

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $perPage = $request->input('per_page', 20);
        $data = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function approve(int $id): JsonResponse
    {
        try {
            $this->approvalService->approve($id, (int) auth()->id());
            return response()->json([
                'success' => true,
                'message' => 'Suggestion approved.',
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function reject(int $id): JsonResponse
    {
        try {
            $this->approvalService->reject($id, (int) auth()->id());
            return response()->json([
                'success' => true,
                'message' => 'Suggestion rejected.',
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
