<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\MatrimonyProfile;
use App\Services\Location\LocationService;
use App\Services\LocationSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class LocationSearchController extends Controller
{
    public function __construct(
        private LocationSearchService $locationSearchService
    ) {}

    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
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
                $preferredStateIds = [];
                $preferredDistrictIds = [];
                $geo = $profile->residenceGeoAddressIds();
                if ($geo['state_id']) {
                    $preferredStateIds[] = (int) $geo['state_id'];
                }
                if ($geo['district_id']) {
                    $preferredDistrictIds[] = (int) $geo['district_id'];
                }
                foreach (['birth_city_id', 'native_city_id'] as $leafCol) {
                    $lid = $profile->getAttribute($leafCol);
                    if (! $lid || ! Schema::hasTable(Location::geoTable())) {
                        continue;
                    }
                    $leaf = Location::query()->find((int) $lid);
                    if ($leaf === null) {
                        continue;
                    }
                    $h = app(LocationService::class)->getFullHierarchy($leaf);
                    if ($h['state']) {
                        $preferredStateIds[] = (int) $h['state']->id;
                    }
                    if ($h['district']) {
                        $preferredDistrictIds[] = (int) $h['district']->id;
                    }
                }
                if ($profile->native_state_id) {
                    $preferredStateIds[] = (int) $profile->native_state_id;
                }
                if ($profile->native_district_id) {
                    $preferredDistrictIds[] = (int) $profile->native_district_id;
                }
                if ($profile->work_state_id) {
                    $preferredStateIds[] = (int) $profile->work_state_id;
                }
                $preferredStateIds = array_values(array_unique(array_filter($preferredStateIds)));
                $preferredDistrictIds = array_values(array_unique(array_filter($preferredDistrictIds)));
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

        $canSuggest = strlen($query) >= 3 && ! (strlen($query) === 6 && ctype_digit($query));

        return response()->json([
            'success' => true,
            'data' => [],
            'no_match' => true,
            'can_suggest' => $canSuggest,
            'context_detected' => $contextDetected,
        ]);
    }
}
