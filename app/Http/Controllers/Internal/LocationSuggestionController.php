<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\District;
use App\Models\LocationAlias;
use App\Models\LocationSuggestion;
use App\Models\State;
use App\Models\Taluka;
use App\Models\Village;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocationSuggestionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'suggested_name' => ['required', 'string', 'max:100'],
            'state_id' => ['required', 'integer', Rule::exists('addresses', 'id')->where(fn ($q) => $q->where('type', 'state'))],
            'district_id' => ['required', 'integer', Rule::exists('addresses', 'id')->where(fn ($q) => $q->where('type', 'district'))],
            'taluka_id' => ['required', 'integer', Rule::exists('addresses', 'id')->where(fn ($q) => $q->where('type', 'taluka'))],
            'suggestion_type' => ['required', Rule::in(['city', 'village'])],
            'suggested_pincode' => ['nullable', 'string', 'max:10'],
        ]);

        $state = State::find($validated['state_id']);
        $district = District::find($validated['district_id']);
        $taluka = Taluka::find($validated['taluka_id']);
        if ($state === null || $district === null || $taluka === null) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid location hierarchy.',
            ], 422);
        }

        $countryId = (int) $state->parent_id;
        if ((int) $district->parent_id !== (int) $validated['state_id']) {
            return response()->json(['success' => false, 'message' => 'District does not match state.'], 422);
        }
        if ((int) $taluka->parent_id !== (int) $validated['district_id']) {
            return response()->json(['success' => false, 'message' => 'Taluka does not match district.'], 422);
        }

        $normalized = strtolower(trim($validated['suggested_name']));

        if ($validated['suggestion_type'] === 'city') {
            $cityExists = City::where('parent_id', $validated['taluka_id'])
                ->whereRaw('LOWER(TRIM(name)) = ?', [$normalized])
                ->exists();
            if ($cityExists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Location already exists.',
                ]);
            }

            if (LocationAlias::where('normalized_alias', $normalized)
                ->whereHas('location', function ($q) use ($validated) {
                    $q->where('parent_id', (int) $validated['taluka_id'])->where('type', 'city');
                })
                ->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Location already exists.',
                ]);
            }
        } else {
            $villageExists = Village::where('parent_id', $validated['taluka_id'])
                ->whereRaw('LOWER(TRIM(name)) = ?', [$normalized])
                ->exists();
            if ($villageExists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Location already exists.',
                ]);
            }
        }

        $pendingExists = LocationSuggestion::where('normalized_name', $normalized)
            ->where('taluka_id', $validated['taluka_id'])
            ->where('status', 'pending')
            ->exists();
        if ($pendingExists) {
            return response()->json([
                'success' => false,
                'message' => 'Suggestion already under review.',
            ]);
        }

        $suggestedBy = auth()->id();
        if ($suggestedBy === null) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required.',
            ], 401);
        }

        $pinRaw = trim((string) ($validated['suggested_pincode'] ?? ''));
        $pinDigits = $pinRaw === '' ? null : preg_replace('/\D+/', '', $pinRaw);
        if ($pinDigits !== null && (strlen($pinDigits) < 3 || strlen($pinDigits) > 10)) {
            return response()->json([
                'success' => false,
                'message' => 'Pincode must be 3–10 digits when provided.',
            ], 422);
        }

        $suggestion = LocationSuggestion::create([
            'suggested_name' => $validated['suggested_name'],
            'normalized_name' => $normalized,
            'country_id' => $countryId,
            'state_id' => $validated['state_id'],
            'district_id' => $validated['district_id'],
            'taluka_id' => $validated['taluka_id'],
            'suggested_pincode' => $pinDigits,
            'suggestion_type' => $validated['suggestion_type'],
            'suggested_by' => $suggestedBy,
            'status' => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Location submitted for admin approval.',
            'data' => [
                'suggestion_id' => $suggestion->id,
            ],
        ]);
    }
}
