<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Services\LocationSearchService;
use App\Models\MatrimonyProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocationSearchController extends Controller
{
    public function __construct(
        private LocationSearchService $locationSearchService
    ) {
    }

    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['required', 'string', 'max:100'],
        ]);

        $query = trim($request->input('q'));
        $localeOverride = $request->input('locale');
        if (is_string($localeOverride) && in_array($localeOverride, ['en', 'mr'], true)) {
            app()->setLocale($localeOverride);
        }
        $preferredStateIds = [];
        $preferredDistrictIds = [];

        $user = $request->user();
        if ($user) {
            $profile = MatrimonyProfile::where('user_id', $user->id)->first();
            if ($profile) {
                $preferredStateIds = array_values(array_unique(array_filter([
                    $profile->state_id,
                    $profile->birth_state_id,
                    $profile->native_state_id,
                    $profile->work_state_id,
                ], static fn ($v) => $v !== null)));

                $preferredDistrictIds = array_values(array_unique(array_filter([
                    $profile->district_id,
                    $profile->birth_district_id,
                    $profile->native_district_id,
                ], static fn ($v) => $v !== null)));
            }
        }

        $result = $this->locationSearchService->search($query, $preferredStateIds, $preferredDistrictIds);
        $results = $result['results'] ?? [];
        $contextDetected = $result['context_detected'] ?? null;

        if ($results !== []) {
            return response()->json([
                'success' => true,
                'data' => $results,
                'no_match' => false,
                'context_detected' => $contextDetected,
            ]);
        }

        $canSuggest = strlen($query) >= 3 && !(strlen($query) === 6 && ctype_digit($query));

        return response()->json([
            'success' => true,
            'data' => [],
            'no_match' => true,
            'can_suggest' => $canSuggest,
            'context_detected' => $contextDetected,
        ]);
    }
}
