<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\MatrimonyProfile;
use App\Services\ProfileLifecycleService;
use App\Services\ViewTrackingService;

class MatrimonyProfileApiController extends Controller
{
    /**
     * Store matrimony profile for logged-in user
     * SSOT: User ≠ MatrimonyProfile
     * CREATE ONLY - returns 409 if profile already exists
     */
    public function store(Request $request)
    {
        $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['required', 'date'],
            'caste' => ['required', 'string', 'max:255'],
            'education' => ['required', 'string', 'max:255'],
            'location' => ['required', 'string', 'max:255'],
        ]);

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
            'location'      => $request->location,
        ]);

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
    $request->validate([
        'full_name' => ['sometimes', 'required', 'string', 'max:255'],
        'date_of_birth' => ['sometimes', 'required', 'date'],
        'caste' => ['sometimes', 'required', 'string', 'max:255'],
        'education' => ['sometimes', 'required', 'string', 'max:255'],
        'location' => ['sometimes', 'required', 'string', 'max:255'],
    ]);

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
    $coreFields = ['full_name', 'date_of_birth', 'caste', 'education', 'location'];
    $updateData = [];
    foreach ($coreFields as $field) {
        if (!$request->has($field)) {
            continue;
        }
        $updateData[$field] = $request->input($field) === '' ? null : $request->input($field);
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

        // Location filter
        if ($request->filled('location')) {
            $query->where('location', $request->location);
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
        $profiles = $profiles->map(function ($profile) {
            return [
                'id' => $profile->id,
                'user_id' => $profile->user_id,
                'full_name' => $profile->full_name,
                'gender' => $profile->user ? ($profile->user->gender ?? null) : null,
                'date_of_birth' => $profile->date_of_birth,
                'caste' => $profile->caste,
                'education' => $profile->education,
                'location' => $profile->location,
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
        }

        // Transform to include gender from user relationship (SSOT-approved field)
        // PIR-006: Null-safe when user relation is missing
        $profileData = [
            'id' => $profile->id,
            'user_id' => $profile->user_id,
            'full_name' => $profile->full_name,
            'gender' => $profile->user ? ($profile->user->gender ?? null) : null,
            'date_of_birth' => $profile->date_of_birth,
            'caste' => $profile->caste,
            'education' => $profile->education,
            'location' => $profile->location,
            'profile_photo' => ($profile->profile_photo && $profile->photo_approved !== false) ? $profile->profile_photo : null,
            'created_at' => $profile->created_at,
            'updated_at' => $profile->updated_at,
        ];

        return response()->json([
            'success' => true,
            'profile' => $profileData,
        ]);
    }

}
