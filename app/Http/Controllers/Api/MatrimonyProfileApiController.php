<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Caste;
use App\Models\Location;
use App\Models\MatrimonyProfile;
use App\Models\SubCaste;
use App\Services\Api\MobileProfileDisplayPresenter;
use App\Services\MutationService;
use App\Services\Parsing\IntakeControlledFieldNormalizer;
use App\Services\ProfileLifecycleService;
use App\Services\ProfileFieldLockService;
use App\Services\ProfileVisibilityPolicyService;
use App\Services\ViewTrackingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class MatrimonyProfileApiController extends Controller
{
    /**
     * Phase-5B: Build snapshot from API request (same structure as manual). Only keys present in request.
     */
    private function buildMobileProfileSnapshotFromApi(Request $request): array
    {
        $core = [];
        $coreFields = [
            'full_name',
            'date_of_birth',
            'caste',
            'highest_education',
            'location_id',
            'address_line',
            'religion_id',
            'caste_id',
            'sub_caste_id',
        ];
        foreach ($coreFields as $key) {
            if (! $request->has($key)) {
                continue;
            }
            $val = $request->input($key);
            if ($val instanceof \Carbon\Carbon) {
                $val = $val->format('Y-m-d');
            }
            $core[$key] = $val === '' ? null : $val;
        }

        if ($core !== []) {
            $normalizedCore = app(IntakeControlledFieldNormalizer::class)->normalizeCore($core);
            foreach (['religion_id', 'caste_id', 'sub_caste_id'] as $key) {
                if (array_key_exists($key, $normalizedCore)) {
                    $core[$key] = $normalizedCore[$key];
                }
            }
        }

        return ['core' => $core];
    }

    private function validateMobileProfileRequest(Request $request, bool $creating): void
    {
        $rules = [
            'full_name' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:255'],
            'date_of_birth' => [$creating ? 'required' : 'sometimes', 'required', 'date'],
            'caste' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:255'],
            'highest_education' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:255'],
            'location_id' => [$creating ? 'required' : 'sometimes', 'required', 'exists:'.Location::geoTable().',id'],
            'religion_id' => ['nullable', 'integer', 'exists:master_religions,id'],
            'caste_id' => ['nullable', 'integer', 'exists:master_castes,id'],
            'sub_caste_id' => ['nullable', 'integer', 'exists:master_sub_castes,id'],
        ];

        if (! $creating) {
            $rules['address_line'] = ['nullable', 'string', 'max:255'];
        }

        $validator = Validator::make($request->all(), $rules);
        $validator->after(function ($validator) use ($request): void {
            $religionId = $request->input('religion_id');
            $casteId = $request->input('caste_id');
            $subCasteId = $request->input('sub_caste_id');

            if ($religionId !== null && $religionId !== '' && $casteId !== null && $casteId !== '') {
                $casteReligionId = Caste::query()->whereKey((int) $casteId)->value('religion_id');
                if ($casteReligionId !== null && (int) $casteReligionId !== (int) $religionId) {
                    $validator->errors()->add('caste_id', 'The selected caste does not belong to the selected religion.');
                }
            }

            if ($casteId !== null && $casteId !== '' && $subCasteId !== null && $subCasteId !== '') {
                $subCasteCasteId = SubCaste::query()->whereKey((int) $subCasteId)->value('caste_id');
                if ($subCasteCasteId !== null && (int) $subCasteCasteId !== (int) $casteId) {
                    $validator->errors()->add('sub_caste_id', 'The selected sub-caste does not belong to the selected caste.');
                }
            }
        });

        $validator->validate();
    }

    /**
     * Lock canonical keys after mobile writes. Raw legacy text keys such as caste are accepted for
     * compatibility, but MutationService writes canonical *_id fields when the value resolves.
     */
    private function lockKeysForMobileSnapshot(array $core): array
    {
        return array_values(array_diff(array_keys($core), ['caste']));
    }

    /**
     * Store matrimony profile for logged-in user
     * SSOT: User ≠ MatrimonyProfile
     * CREATE ONLY - returns 409 if profile already exists
     */
    public function store(Request $request)
    {
        // Phase-4 Day-8: Location hierarchy validation
        $this->validateMobileProfileRequest($request, creating: true);

        $user = $request->user(); // sanctum authenticated user

        // Check if profile already exists
        $existingProfile = MatrimonyProfile::where('user_id', $user->id)->first();

        if ($existingProfile) {
            return response()->json([
                'success' => false,
                'message' => 'Profile already exists',
            ], 409);
        }

        $mutation = app(MutationService::class);
        $profile = $mutation->createDraftProfileForUser($user);
        $snapshot = $this->buildMobileProfileSnapshotFromApi($request);
        $mutation->applyManualSnapshot($profile, $snapshot, (int) $user->id, 'manual');

        $profileData = $this->buildGovernanceParityProfilePayload($profile->fresh(['user', 'horoscope', 'preferenceCriteria']));

        return response()->json([
            'success' => true,
            'message' => 'Matrimony profile created',
            'profile' => $profileData,
        ]);
    }

    /**
     * Get matrimony profile for logged-in user
     */
    public function show(Request $request)
    {
        $user = $request->user();

        $profile = MatrimonyProfile::where('user_id', $user->id)->first();

        if (! $profile) {
            return response()->json([
                'success' => false,
                'message' => 'Profile not found',
            ], 404);
        }

        $profileData = $this->buildGovernanceParityProfilePayload($profile);

        return response()->json([
            'success' => true,
            'profile' => $profileData,
            'display' => app(MobileProfileDisplayPresenter::class)->forProfile($profile, $user),
        ]);
    }

    /**
     * Update matrimony profile for logged-in user
     */
    public function update(Request $request)
    {
        // Phase-4 Day-8: Location hierarchy validation
        $this->validateMobileProfileRequest($request, creating: false);

        $user = $request->user();

        $profile = MatrimonyProfile::where('user_id', $user->id)->first();

        if (! $profile) {
            return response()->json([
                'success' => false,
                'message' => 'Profile not found',
            ], 404);
        }

        // Day 7: Archived/Suspended → edit blocked
        if (! \App\Services\ProfileLifecycleService::isEditable($profile)) {
            return response()->json([
                'success' => false,
                'message' => 'Your profile cannot be edited in its current state.',
            ], 403);
        }

        // Phase-5B: All updates via MutationService (source=manual, profile_change_history)
        $snapshot = $this->buildMobileProfileSnapshotFromApi($request);
        if (! empty($snapshot['core'])) {
            app(MutationService::class)->applyManualSnapshot($profile, $snapshot, (int) $user->id, 'manual');
            $changedFields = $this->lockKeysForMobileSnapshot($snapshot['core']);
            if (! empty($changedFields)) {
                ProfileFieldLockService::applyLocks($profile, $changedFields, 'CORE', $user);
            }
        }

        $profileData = $this->buildGovernanceParityProfilePayload($profile->fresh(['user', 'horoscope', 'preferenceCriteria']));

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
        Log::info('UPLOAD ENTRY HIT', [
            'controller' => __METHOD__,
            'user_id' => auth()->id() ?? null,
        ]);

        $request->validate([
            'profile_photo' => 'required|image|max:2048',
        ]);

        $user = $request->user();

        $profile = MatrimonyProfile::where('user_id', $user->id)->first();

        if (! $profile) {
            return response()->json([
                'success' => false,
                'message' => 'Profile not found.',
            ], 404);
        }

        if (Schema::hasColumn('users', 'photo_uploads_suspended') && (bool) $user->photo_uploads_suspended) {
            return response()->json([
                'success' => false,
                'message' => 'Photo uploads have been suspended for your account.',
            ], 403);
        }

        $file = $request->file('profile_photo');
        $pending = app(\App\Services\Image\ImageProcessingService::class)
            ->enqueueProfilePhotoProcessing($file, (int) $profile->id);

        $snapshot = [
            'core' => [
                'profile_photo' => $pending,
            ],
        ];
        app(\App\Services\MutationService::class)->applyManualSnapshot($profile, $snapshot, (int) $user->id, 'manual');
        app(\App\Services\Image\ProfilePhotoPendingStateService::class)->applyPendingReviewState($profile);

        return response()->json([
            'success' => true,
            'message' => 'Profile photo uploaded. Processing will complete shortly.',
            'data' => [
                'profile_photo' => $pending,
                'status' => 'processing',
            ],
        ]);
    }

    /**
     * List all matrimony profiles with filters
     */
    public function index(Request $request)
    {
        $query = MatrimonyProfile::with('user')->latest();

        // Day 7: Only active profiles searchable; NULL treated as active (backward compat)
        $query->where(function ($q) {
            $q->where('lifecycle_state', 'active')->orWhereNull('lifecycle_state');
        })->where('is_suspended', false);
        // Soft deletes are automatically excluded by Laravel's SoftDeletes trait

        // Caste filter
        if ($request->filled('caste')) {
            $query->where('caste', $request->caste);
        }

        // Residence geo: filter by ancestor {@code addresses.id} (canonical leaf is {@see location_id}).
        if ($request->filled('country_id')) {
            $query->whereResidenceUnderAncestor((int) $request->country_id);
        }
        if ($request->filled('state_id')) {
            $query->whereResidenceUnderAncestor((int) $request->state_id);
        }
        if ($request->filled('district_id')) {
            $query->whereResidenceUnderAncestor((int) $request->district_id);
        }
        if ($request->filled('taluka_id')) {
            $query->whereResidenceUnderAncestor((int) $request->taluka_id);
        }
        if ($request->filled('location_id')) {
            $query->where('location_id', $request->location_id);
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
            $hints = $profile->residenceLocationHierarchyHints();
            $geo = $profile->residenceGeoAddressIds();

            return [
                'id' => $profile->id,
                'user_id' => $profile->user_id,
                'full_name' => $profile->full_name,
                'gender' => $profile->user ? ($profile->user->gender ?? null) : null,
                'date_of_birth' => $profile->date_of_birth,
                'caste' => $profile->caste,
                'highest_education' => $profile->highest_education,
                'location_id' => $profile->location_id,
                'country_id' => $geo['country_id'],
                'state_id' => $geo['state_id'],
                'district_id' => $geo['district_id'],
                'taluka_id' => $hints['taluka_id'] !== '' ? (int) $hints['taluka_id'] : null,
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

        if (! $profile) {
            return response()->json([
                'success' => false,
                'message' => 'Profile not found',
            ], 404);
        }

        $user = request()->user();
        $viewerProfile = $user ? $user->matrimonyProfile : null;
        $isOwnProfile = $viewerProfile && (int) $viewerProfile->id === (int) $profile->id;

        if (! $isOwnProfile) {
            if (! ProfileLifecycleService::isVisibleToOthers($profile)) {
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
            if (! ProfileVisibilityPolicyService::canViewProfile($profile, $user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Profile not found',
                ], 404);
            }
        }

        // Transform to include gender from user relationship (SSOT-approved field)
        // PIR-006: Null-safe when user relation is missing
        // Phase-4 Day-8: Use hierarchical location fields
        $profileData = $this->buildGovernanceParityProfilePayload($profile);

        return response()->json([
            'success' => true,
            'profile' => $profileData,
            'display' => app(MobileProfileDisplayPresenter::class)->forProfile($profile, $user),
        ]);
    }

    /**
     * Deterministic API payload aligned with snapshot / governance canonical registry (Phase-6E).
     * Includes explicit *_id columns and legacy aliases where snapshots compare logical keys.
     *
     * @return array<string, mixed>
     */
    private function buildGovernanceParityProfilePayload(MatrimonyProfile $profile): array
    {
        $profile->loadMissing(['user', 'horoscope', 'preferenceCriteria', 'religion', 'caste', 'subCaste']);

        $hints = $profile->residenceLocationHierarchyHints();
        $geo = $profile->residenceGeoAddressIds();
        $horoscope = $profile->horoscope;
        $locationLabel = trim($profile->residenceLocationDisplayLine());

        $criteria = $profile->preferenceCriteria;
        $partnerPreferences = $criteria !== null ? $criteria->toArray() : null;

        $base = $profile->toArray();
        $parity = [
            'gender' => $profile->user ? ($profile->user->gender ?? null) : null,
            'gender_id' => $profile->gender_id,
            'caste_id' => $profile->caste_id,
            'sub_caste_id' => $profile->sub_caste_id,
            'location_id' => $profile->location_id,
            'country_id' => $geo['country_id'],
            'state_id' => $geo['state_id'],
            'district_id' => $geo['district_id'],
            'taluka_id' => $hints['taluka_id'] !== '' ? (int) $hints['taluka_id'] : null,
            'height_cm' => $profile->height_cm,
            'religion_id' => $profile->religion_id,
            'religion_label' => $profile->getRelation('religion')?->display_label,
            'caste_label' => $profile->getRelation('caste')?->display_label,
            'sub_caste_label' => $profile->getRelation('subCaste')?->display_label,
            'location_label' => $locationLabel !== '' ? $locationLabel : null,
            'mother_tongue_id' => $profile->mother_tongue_id,
            'marital_status_id' => $profile->marital_status_id,
            'occupation_title' => $profile->occupation_title,
            'annual_income' => $profile->annual_income,
            'family_type_id' => $profile->family_type_id,
            'complexion_id' => $profile->complexion_id,
            'blood_group_id' => $profile->blood_group_id,
            'profession_id' => $profile->profession_id,
            'income_range_id' => $profile->income_range_id,
            'nakshatra_id' => $horoscope ? $horoscope->nakshatra_id : null,
            'rashi_id' => $horoscope ? $horoscope->rashi_id : null,
            'mangal_dosh_type_id' => $horoscope ? $horoscope->mangal_dosh_type_id : null,
            'partner_preferences' => $partnerPreferences,
        ];

        $profileData = array_merge($base, $parity);

        if ($profile->photo_approved === false || ! $profile->profile_photo) {
            $profileData['profile_photo'] = null;
        }

        return $profileData;
    }

}
