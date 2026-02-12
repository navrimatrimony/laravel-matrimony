<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\MatrimonyProfile;
use App\Services\FieldValueHistoryService;
use App\Services\ProfileLifecycleService;
use App\Services\ViewTrackingService;
use App\Services\ProfileVisibilityPolicyService;

class MatrimonyProfileApiController extends Controller
{
    /**
     * Store matrimony profile for logged-in user
     * SSOT: User ≠ MatrimonyProfile
     * CREATE ONLY - returns 409 if profile already exists
     */
    public function store(Request $request)
    {
        // Phase-4 Day-8: Location hierarchy validation
        $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['required', 'date'],
            'caste' => ['required', 'string', 'max:255'],
            'education' => ['required', 'string', 'max:255'],
            'country_id' => ['required', 'exists:countries,id'],
            'state_id' => ['required', 'exists:states,id'],
            'district_id' => ['nullable', 'exists:districts,id'],
            'taluka_id' => ['nullable', 'exists:talukas,id'],
            'city_id' => ['required', 'exists:cities,id'],
        ]);

        // Phase-4 Day-8: Validate location hierarchy integrity
        $this->validateLocationHierarchy($request);

        $user = $request->user(); // sanctum authenticated user

        // Check if profile already exists
        $existingProfile = MatrimonyProfile::where('user_id', $user->id)->first();

        if ($existingProfile) {
            return response()->json([
                'success' => false,
                'message' => 'Profile already exists',
            ], 409);
        }

        // Create new profile
        $profile = MatrimonyProfile::create([
            'user_id'      => $user->id,
            'full_name'     => $request->full_name,
            'date_of_birth' => $request->date_of_birth,
            'caste'         => $request->caste,
            'education'     => $request->education,
            'country_id'    => $request->country_id,
            'state_id'      => $request->state_id,
            'district_id'   => $request->district_id,
            'taluka_id'     => $request->taluka_id,
            'city_id'       => $request->city_id,
        ]);

        // Day-6 BUGFIX-B FINAL: API create initial history (Law 9) — प्रत्येक initial field साठी एक row
        $initialFields = ['full_name', 'date_of_birth', 'caste', 'education', 'country_id', 'state_id', 'district_id', 'taluka_id', 'city_id'];
        foreach ($initialFields as $fieldKey) {
            $newVal = $profile->$fieldKey;
            if ($newVal instanceof \Carbon\Carbon) {
                $newVal = $newVal->format('Y-m-d');
            }
            $newVal = $newVal === '' || $newVal === null ? null : (string) $newVal;
            FieldValueHistoryService::record(
                $profile->id,
                $fieldKey,
                'CORE',
                null,
                $newVal,
                'API'
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Matrimony profile created',
            'profile' => $profile,
        ]);
    }
	/**
 * Get matrimony profile for logged-in user
 */
public function show(Request $request)
{
    $user = $request->user();

    $profile = MatrimonyProfile::where('user_id', $user->id)->first();

    if (!$profile) {
        return response()->json([
            'success' => false,
            'message' => 'Profile not found',
        ], 404);
    }

    // Hide rejected images - return null for profile_photo if explicitly rejected
    $profileData = $profile->toArray();
    if ($profile->photo_approved === false || !$profile->profile_photo) {
        $profileData['profile_photo'] = null;
    }

    return response()->json([
        'success' => true,
        'profile' => $profileData,
    ]);
}
/**
 * Update matrimony profile for logged-in user
 */
public function update(Request $request)
{
    // Phase-4 Day-8: Location hierarchy validation
    $request->validate([
        'full_name' => ['sometimes', 'required', 'string', 'max:255'],
        'date_of_birth' => ['sometimes', 'required', 'date'],
        'caste' => ['sometimes', 'required', 'string', 'max:255'],
        'education' => ['sometimes', 'required', 'string', 'max:255'],
        'country_id' => ['sometimes', 'required', 'exists:countries,id'],
        'state_id' => ['sometimes', 'required', 'exists:states,id'],
        'district_id' => ['nullable', 'exists:districts,id'],
        'taluka_id' => ['nullable', 'exists:talukas,id'],
        'city_id' => ['sometimes', 'required', 'exists:cities,id'],
    ]);

    // Phase-4 Day-8: Validate location hierarchy integrity if any location field provided
    if ($request->hasAny(['country_id', 'state_id', 'district_id', 'taluka_id', 'city_id'])) {
        $this->validateLocationHierarchy($request);
    }

    $user = $request->user();

    $profile = MatrimonyProfile::where('user_id', $user->id)->first();

    if (!$profile) {
        return response()->json([
            'success' => false,
            'message' => 'Profile not found',
        ], 404);
    }

    // Day 7: Archived/Suspended → edit blocked
    if (!\App\Services\ProfileLifecycleService::isEditable($profile)) {
        return response()->json([
            'success' => false,
            'message' => 'Your profile cannot be edited in its current state.',
        ], 403);
    }

    // PIR-007: Only update fields PRESENT in request; do not overwrite others with null/empty
    $coreFields = ['full_name', 'date_of_birth', 'caste', 'education'];
    $locationFields = ['country_id', 'state_id', 'district_id', 'taluka_id', 'city_id'];
    $updateData = [];
    foreach ($coreFields as $field) {
        if (!$request->has($field)) {
            continue;
        }
        $updateData[$field] = $request->input($field) === '' ? null : $request->input($field);
    }

    // Phase-4 Day-8: Process location hierarchy fields
    foreach ($locationFields as $field) {
        if (!$request->has($field)) {
            continue;
        }
        $updateData[$field] = $request->input($field);
    }

    // Day-6.4: Detect only ACTUALLY CHANGED core fields for lock check (among those being updated)
    $changedFields = [];
    foreach (array_keys($updateData) as $field) {
        $newVal = $updateData[$field];
        $oldVal = $profile->$field === '' ? null : $profile->$field;
        if ((string) $newVal !== (string) $oldVal) {
            $changedFields[] = $field;
        }
    }

    if (!empty($changedFields)) {
        \App\Services\ProfileFieldLockService::assertNotLocked($profile, $changedFields, $user);
    }

    // Day-6: Record history for every changed CORE field before update
    foreach ($changedFields as $fieldKey) {
        $oldVal = $profile->$fieldKey === '' ? null : $profile->$fieldKey;
        $newVal = $updateData[$fieldKey] ?? null;
        $newVal = $newVal === '' ? null : $newVal;
        if ($newVal instanceof \Carbon\Carbon) {
            $newVal = $newVal->format('Y-m-d');
        }
        FieldValueHistoryService::record($profile->id, $fieldKey, 'CORE', $oldVal, $newVal, FieldValueHistoryService::CHANGED_BY_API);
    }

    if (!empty($updateData)) {
        $profile->update($updateData);
    }

    if (!empty($changedFields)) {
        \App\Services\ProfileFieldLockService::applyLocks($profile, $changedFields, 'CORE', $user);
    }

    // Hide rejected images - return null for profile_photo if explicitly rejected
    $profileData = $profile->toArray();
    if ($profile->photo_approved === false || !$profile->profile_photo) {
        $profileData['profile_photo'] = null;
    }

    return response()->json([
        'success' => true,
        'message' => 'Matrimony profile updated',
        'profile' => $profileData,
    ]);
}

    /**
     * Upload or replace matrimony profile photo for logged-in user
     */
    public function uploadPhoto(Request $request)
    {
        $request->validate([
            'profile_photo' => 'required|image|max:2048',
        ]);

        $user = $request->user();

        $profile = MatrimonyProfile::where('user_id', $user->id)->first();

        if (!$profile) {
            return response()->json([
                'success' => false,
                'message' => 'Profile not found.',
            ], 404);
        }

        $file = $request->file('profile_photo');
        $filename = time() . '_' . basename($file->getClientOriginalName());

        $file->move(
            public_path('uploads/matrimony_photos'),
            $filename
        );

        // Apply policy-based approval status
        $photoApprovalRequired = \App\Services\AdminSettingService::isPhotoApprovalRequired();
        
        if ($photoApprovalRequired) {
            // Policy: Approval required - photo hidden until admin approves
            $photoApproved = false;
        } else {
            // Policy: No approval required - photo visible immediately
            $photoApproved = true;
        }

        // Day-6: Record history for photo fields before update
        if ((string) ($profile->profile_photo ?? '') !== (string) $filename) {
            FieldValueHistoryService::record($profile->id, 'profile_photo', 'CORE', $profile->profile_photo, $filename, FieldValueHistoryService::CHANGED_BY_API);
        }
        if ((string) ($profile->photo_approved ?? '') !== (string) $photoApproved) {
            FieldValueHistoryService::record($profile->id, 'photo_approved', 'CORE', $profile->photo_approved ? '1' : '0', $photoApproved ? '1' : '0', FieldValueHistoryService::CHANGED_BY_API);
        }
        if ($profile->photo_rejected_at !== null) {
            FieldValueHistoryService::record($profile->id, 'photo_rejected_at', 'CORE', $profile->photo_rejected_at?->format('Y-m-d H:i:s'), null, FieldValueHistoryService::CHANGED_BY_API);
        }
        if (!empty($profile->photo_rejection_reason)) {
            FieldValueHistoryService::record($profile->id, 'photo_rejection_reason', 'CORE', $profile->photo_rejection_reason, null, FieldValueHistoryService::CHANGED_BY_API);
        }

        $profile->update([
            'profile_photo' => $filename,
            'photo_approved' => $photoApproved,
            'photo_rejected_at' => null,
            'photo_rejection_reason' => null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Profile photo uploaded successfully.',
            'data' => [
                'profile_photo' => $filename,
                'url' => asset('uploads/matrimony_photos/' . $filename),
            ],
        ]);
    }

    /**
     * List all matrimony profiles with filters
     */
    public function index(Request $request)
    {
        $query = MatrimonyProfile::with('user')->latest();

        // Day 7: Only Active profiles searchable; NULL treated as Active (backward compat)
        $query->where(function ($q) {
            $q->where('lifecycle_state', 'Active')->orWhereNull('lifecycle_state');
        })->where('is_suspended', false);
        // Soft deletes are automatically excluded by Laravel's SoftDeletes trait

        // Caste filter
        if ($request->filled('caste')) {
            $query->where('caste', $request->caste);
        }

        // Phase-4 Day-8: Location hierarchy filters
        if ($request->filled('country_id')) {
            $query->where('country_id', $request->country_id);
        }
        if ($request->filled('state_id')) {
            $query->where('state_id', $request->state_id);
        }
        if ($request->filled('district_id')) {
            $query->where('district_id', $request->district_id);
        }
        if ($request->filled('taluka_id')) {
            $query->where('taluka_id', $request->taluka_id);
        }
        if ($request->filled('city_id')) {
            $query->where('city_id', $request->city_id);
        }

        // Age filter (from date_of_birth)
        if ($request->filled('age_from') || $request->filled('age_to')) {
            $query->whereNotNull('date_of_birth');
            
            if ($request->filled('age_from')) {
                $minDate = now()->subYears($request->age_from)->format('Y-m-d');
                $query->whereDate('date_of_birth', '<=', $minDate);
            }
            
            if ($request->filled('age_to')) {
                $maxDate = now()->subYears($request->age_to + 1)->addDay()->format('Y-m-d');
                $query->whereDate('date_of_birth', '>=', $maxDate);
            }
        }

        $profiles = $query->get();

        // Transform to include gender from user relationship (SSOT-approved fields only)
        // PIR-006: Null-safe when profile's user is missing (orphaned profile)
        // Phase-4 Day-8: Use hierarchical location fields
        $profiles = $profiles->map(function ($profile) {
            return [
                'id' => $profile->id,
                'user_id' => $profile->user_id,
                'full_name' => $profile->full_name,
                'gender' => $profile->user ? ($profile->user->gender ?? null) : null,
                'date_of_birth' => $profile->date_of_birth,
                'caste' => $profile->caste,
                'education' => $profile->education,
                'country_id' => $profile->country_id,
                'state_id' => $profile->state_id,
                'district_id' => $profile->district_id,
                'taluka_id' => $profile->taluka_id,
                'city_id' => $profile->city_id,
                'profile_photo' => ($profile->profile_photo && $profile->photo_approved !== false) ? $profile->profile_photo : null,
                'created_at' => $profile->created_at,
                'updated_at' => $profile->updated_at,
            ];
        });

        return response()->json([
            'success' => true,
            'profiles' => $profiles,
        ]);
    }

    /**
     * Get matrimony profile by ID
     * PIR-005: Visibility parity with Web — 404 when not visible to others or blocked.
     * PIR-006: Null-safe gender when profile's user is missing.
     */
    public function showById($id)
    {
        $profile = MatrimonyProfile::with('user')->find($id);

        if (!$profile) {
            return response()->json([
                'success' => false,
                'message' => 'Profile not found',
            ], 404);
        }

        $user = request()->user();
        $viewerProfile = $user ? $user->matrimonyProfile : null;
        $isOwnProfile = $viewerProfile && (int) $viewerProfile->id === (int) $profile->id;

        if (!$isOwnProfile) {
            if (!ProfileLifecycleService::isVisibleToOthers($profile)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Profile not found',
                ], 404);
            }
            if ($viewerProfile && ViewTrackingService::isBlocked($viewerProfile->id, $profile->id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Profile not found',
                ], 404);
            }
            if (!ProfileVisibilityPolicyService::canViewProfile($profile, $user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Profile not found',
                ], 404);
            }
        }

        // Transform to include gender from user relationship (SSOT-approved field)
        // PIR-006: Null-safe when user relation is missing
        // Phase-4 Day-8: Use hierarchical location fields
        $profileData = [
            'id' => $profile->id,
            'user_id' => $profile->user_id,
            'full_name' => $profile->full_name,
            'gender' => $profile->user ? ($profile->user->gender ?? null) : null,
            'date_of_birth' => $profile->date_of_birth,
            'caste' => $profile->caste,
            'education' => $profile->education,
            'country_id' => $profile->country_id,
            'state_id' => $profile->state_id,
            'district_id' => $profile->district_id,
            'taluka_id' => $profile->taluka_id,
            'city_id' => $profile->city_id,
            'profile_photo' => ($profile->profile_photo && $profile->photo_approved !== false) ? $profile->profile_photo : null,
            'created_at' => $profile->created_at,
            'updated_at' => $profile->updated_at,
        ];

        return response()->json([
            'success' => true,
            'profile' => $profileData,
        ]);
    }

    /**
     * Phase-4 Day-8: Validate location hierarchy integrity
     */
    private function validateLocationHierarchy(Request $request): void
    {
        // If city provided, validate it belongs to the selected taluka (if provided)
        if ($request->filled('city_id') && $request->filled('taluka_id')) {
            $city = \App\Models\City::find($request->city_id);
            if ($city && $city->taluka_id != $request->taluka_id) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'city_id' => 'Selected city does not belong to the selected taluka.'
                ]);
            }
        }

        // If taluka provided, validate it belongs to the selected district (if provided)
        if ($request->filled('taluka_id') && $request->filled('district_id')) {
            $taluka = \App\Models\Taluka::find($request->taluka_id);
            if ($taluka && $taluka->district_id != $request->district_id) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'taluka_id' => 'Selected taluka does not belong to the selected district.'
                ]);
            }
        }

        // If district provided, validate it belongs to the selected state
        if ($request->filled('district_id') && $request->filled('state_id')) {
            $district = \App\Models\District::find($request->district_id);
            if ($district && $district->state_id != $request->state_id) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'district_id' => 'Selected district does not belong to the selected state.'
                ]);
            }
        }

        // State must belong to the selected country
        if ($request->filled('state_id') && $request->filled('country_id')) {
            $state = \App\Models\State::find($request->state_id);
            if ($state && $state->country_id != $request->country_id) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'state_id' => 'Selected state does not belong to the selected country.'
                ]);
            }
        }
    }

}
