<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakAccount;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakCrossSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Thin mobile adapter over SuchakCrossSearchService (masked search).
 */
class SuchakSearchApiController extends Controller
{
    public function __invoke(
        Request $request,
        SuchakCrossSearchService $searchService,
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        /** @var SuchakAccount|null $account */
        $account = $user->suchakAccount;
        if ($account === null) {
            return response()->json([
                'success' => false,
                'message' => 'Suchak account is required to access this section.',
            ], 403);
        }

        if (! $searchService->canSearch($account)) {
            return response()->json([
                'success' => false,
                'message' => 'Only verified Suchak accounts can use masked search.',
            ], 403);
        }

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'age_min' => ['nullable', 'integer', 'min:18', 'max:100'],
            'age_max' => ['nullable', 'integer', 'min:18', 'max:100'],
            'gender_id' => ['nullable', 'integer', 'min:1'],
            'caste_id' => ['nullable', 'integer', 'min:1'],
            'religion_id' => ['nullable', 'integer', 'min:1'],
            'marital_status_id' => ['nullable', 'integer', 'min:1'],
            'requesting_representation_id' => ['nullable', 'integer', 'exists:suchak_profile_representations,id'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $results = $searchService->search($account, $filters);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Suchak search results loaded.',
            'data' => [
                'filters' => $filters,
                'results' => $results->items(),
                'pagination' => [
                    'current_page' => $results->currentPage(),
                    'last_page' => $results->lastPage(),
                    'per_page' => $results->perPage(),
                    'total' => $results->total(),
                ],
            ],
        ]);
    }
}
