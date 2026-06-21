<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\QuotaPolicySourceViolation;
use App\Http\Controllers\Controller;
use App\Models\Caste;
use App\Models\Location;
use App\Models\MatrimonyProfile;
use App\Models\SubCaste;
use App\Models\User;
use App\Services\Api\MobileDiscoveryFilterService;
use App\Services\Api\MobileMoreMatchesSectionService;
use App\Services\Api\MobileProfileDisplayPresenter;
use App\Services\Matching\MatchingService;
use App\Services\MutationService;
use App\Services\Parsing\IntakeControlledFieldNormalizer;
use App\Services\ProfileFieldLockService;
use App\Services\ProfileRotationService;
use App\Services\ViewTrackingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

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
            'gender_id',
            'date_of_birth',
            'birth_time',
            'birth_city_id',
            'birth_place_text',
            'caste',
            'highest_education',
            'location_id',
            'address_line',
            'religion_id',
            'caste_id',
            'sub_caste_id',
            'mother_tongue_id',
            'height_cm',
            'weight_kg',
            'complexion_id',
            'blood_group_id',
            'physical_build_id',
            'spectacles_lens',
            'physical_condition',
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

        $snapshot = ['core' => $core];

        if ($request->has('birth_city_id')) {
            $snapshot['birth_place'] = [
                'city_id' => $request->input('birth_city_id') ? (int) $request->input('birth_city_id') : null,
                'taluka_id' => null,
                'district_id' => null,
                'state_id' => null,
            ];
        }

        return $snapshot;
    }

    private function validateMobileProfileRequest(Request $request, bool $creating): void
    {
        $rules = [
            'full_name' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:255'],
            'gender_id' => [
                $creating ? 'required' : 'sometimes',
                'integer',
                Rule::exists('master_genders', 'id')->where('is_active', true),
            ],
            'date_of_birth' => [$creating ? 'required' : 'sometimes', 'required', 'date'],
            'caste' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:255'],
            'highest_education' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:255'],
            'location_id' => [$creating ? 'required' : 'sometimes', 'required', 'exists:'.Location::geoTable().',id'],
            'religion_id' => ['nullable', 'integer', 'exists:master_religions,id'],
            'caste_id' => ['nullable', 'integer', 'exists:master_castes,id'],
            'sub_caste_id' => ['nullable', 'integer', 'exists:master_sub_castes,id'],
            'birth_time' => ['nullable', 'string', 'max:20'],
            'birth_city_id' => ['nullable', 'integer', 'exists:'.Location::geoTable().',id'],
            'birth_place_text' => ['nullable', 'string', 'max:255'],
            'mother_tongue_id' => ['nullable', 'integer', Rule::exists('master_mother_tongues', 'id')->where('is_active', true)],
            'height_cm' => ['nullable', 'integer', 'min:50', 'max:250'],
            'weight_kg' => ['nullable', 'integer', 'min:20', 'max:250'],
            'complexion_id' => ['nullable', 'integer', Rule::exists('master_complexions', 'id')->where('is_active', true)],
            'blood_group_id' => ['nullable', 'integer', Rule::exists('master_blood_groups', 'id')->where('is_active', true)],
            'physical_build_id' => ['nullable', 'integer', Rule::exists('master_physical_builds', 'id')->where('is_active', true)],
            'spectacles_lens' => ['nullable', 'string', 'max:50', Rule::in(['no', 'spectacles', 'contact_lens', 'both'])],
            'physical_condition' => ['nullable', 'string', 'max:50', Rule::in(['none', 'physically_challenged', 'hearing_condition', 'vision_condition', 'other', 'prefer_not_to_say'])],
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

        if (! $request->filled('gender_id') && ! $this->profileHasGovernedGender($profile)) {
            return response()->json([
                'success' => false,
                'message' => 'The gender id field is required.',
                'errors' => [
                    'gender_id' => ['The gender id field is required.'],
                ],
            ], 422);
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
    public function index(Request $request, MobileDiscoveryFilterService $discovery, MatchingService $matching)
    {
        $viewer = $request->user();
        $relations = $this->mobileListRelations();
        $feed = $this->mobileListFeed($request);
        $profiles = $feed === null
            ? $this->legacyMobileListProfiles($request, $viewer, $discovery, $relations)
            : $this->feedMobileListProfiles($request, $viewer, $discovery, $matching, $relations, $feed);
        $presenter = app(MobileProfileDisplayPresenter::class);

        // Transform to include gender from the governed profile relation only.
        // PIR-006: Null-safe when profile's user is missing (orphaned profile)
        // Phase-4 Day-8: Use hierarchical location fields
        $profiles = $profiles->map(function ($profile) use ($presenter, $viewer) {
            $hints = $profile->residenceLocationHierarchyHints();
            $geo = $profile->residenceGeoAddressIds();

            return [
                'id' => $profile->id,
                'user_id' => $profile->user_id,
                'full_name' => $profile->full_name,
                'gender' => $profile->gender?->key,
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
                'display' => $presenter->forListCard($profile, $viewer),
            ];
        });

        return response()->json([
            'success' => true,
            'profiles' => $profiles,
        ]);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function mobileListRelations(): array
    {
        $relations = [
            'user.activeSubscription.plan',
            'gender',
            'religion',
            'caste',
            'subCaste',
            'occupationMaster',
            'occupationCustom',
            'horoscope',
        ];
        if (Schema::hasTable('profile_photos')) {
            $relations['photos'] = fn ($query) => $query->effectivelyApproved();
        }

        return $relations;
    }

    private function mobileListFeed(Request $request): ?string
    {
        $feed = strtolower(trim((string) $request->query('feed', '')));

        return match ($feed) {
            'new' => 'new',
            'daily' => 'daily',
            'my_matches', 'my-matches', 'matches', 'perfect' => 'my_matches',
            'near_me', 'near-me', 'nearby' => 'nearby',
            default => null,
        };
    }

    /**
     * @param  array<int|string, mixed>  $relations
     * @return Collection<int, MatrimonyProfile>
     */
    private function legacyMobileListProfiles(
        Request $request,
        ?User $viewer,
        MobileDiscoveryFilterService $discovery,
        array $relations
    ): Collection {
        $query = MatrimonyProfile::with($relations);
        $this->applyMobileDiscoveryQuery($query, $viewer, $discovery);
        $this->applyMobileListFilters($query, $request);
        ProfileRotationService::applyApprovedPhotoOrdering($query);

        return $query->latest()->get();
    }

    /**
     * @param  array<int|string, mixed>  $relations
     * @return Collection<int, MatrimonyProfile>
     */
    private function feedMobileListProfiles(
        Request $request,
        ?User $viewer,
        MobileDiscoveryFilterService $discovery,
        MatchingService $matching,
        array $relations,
        string $feed
    ): Collection {
        if (! $viewer instanceof User || ! $discovery->viewerCanDiscover($viewer)) {
            return collect();
        }

        return match ($feed) {
            'daily' => $this->matchingFeedProfiles($request, $viewer, $discovery, $matching, $relations, MatchingService::TAB_DAILY),
            'my_matches' => $this->matchingFeedProfiles($request, $viewer, $discovery, $matching, $relations, MatchingService::TAB_PERFECT),
            'nearby' => $this->matchingFeedProfiles($request, $viewer, $discovery, $matching, $relations, MatchingService::TAB_NEAR),
            default => $this->newFeedProfiles($request, $viewer, $discovery, $relations),
        };
    }

    /**
     * @param  array<int|string, mixed>  $relations
     * @return Collection<int, MatrimonyProfile>
     */
    private function newFeedProfiles(
        Request $request,
        User $viewer,
        MobileDiscoveryFilterService $discovery,
        array $relations
    ): Collection {
        $query = MatrimonyProfile::with($relations);
        $this->applyMobileDiscoveryQuery($query, $viewer, $discovery);
        $this->applyMobileListFilters($query, $request);

        $viewer->loadMissing('matrimonyProfile.gender');
        $viewerProfile = $viewer->matrimonyProfile;
        if ($viewerProfile instanceof MatrimonyProfile && ProfileRotationService::isEnabled()) {
            ProfileRotationService::applyDiscoverScope($query, (int) $viewerProfile->id, (int) $viewer->id);
            ProfileRotationService::applyDiscoverOrdering(
                $query,
                (int) $viewerProfile->id,
                $this->mobileFeedSeed($viewerProfile, 'new'),
                true
            );

            return $query->get();
        }

        ProfileRotationService::applyApprovedPhotoOrdering($query);

        return $query->latest()->get();
    }

    /**
     * @param  array<int|string, mixed>  $relations
     * @return Collection<int, MatrimonyProfile>
     */
    private function matchingFeedProfiles(
        Request $request,
        User $viewer,
        MobileDiscoveryFilterService $discovery,
        MatchingService $matching,
        array $relations,
        string $matchingTab
    ): Collection {
        $viewer->loadMissing('matrimonyProfile.gender');
        $viewerProfile = $viewer->matrimonyProfile;
        if (! $viewerProfile instanceof MatrimonyProfile) {
            return collect();
        }

        $ids = $matching
            ->findMatchesForTab($viewerProfile, $matchingTab, 160)
            ->filter(function (array $row) use ($viewer, $discovery): bool {
                $profile = $row['profile'] ?? null;

                return $profile instanceof MatrimonyProfile
                    && $discovery->isAllowedTarget($viewer, $profile);
            })
            ->map(fn (array $row): int => (int) $row['profile']->id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        return $this->profilesByMobileFeedOrder($request, $viewer, $discovery, $relations, $ids);
    }

    private function mobileFeedSeed(MatrimonyProfile $viewerProfile, string $feed): string
    {
        return implode('|', [
            'mobile',
            $feed,
            (string) $viewerProfile->id,
            now()->toDateString(),
        ]);
    }

    /**
     * @param  array<int|string, mixed>  $relations
     * @param  list<int>  $profileIds
     * @return Collection<int, MatrimonyProfile>
     */
    private function profilesByMobileFeedOrder(
        Request $request,
        User $viewer,
        MobileDiscoveryFilterService $discovery,
        array $relations,
        array $profileIds
    ): Collection {
        $profileIds = array_values(array_unique(array_filter(array_map('intval', $profileIds))));
        if ($profileIds === []) {
            return collect();
        }

        $query = MatrimonyProfile::with($relations)->whereIn('id', $profileIds);
        $this->applyMobileDiscoveryQuery($query, $viewer, $discovery);
        $this->applyMobileListFilters($query, $request);

        $profiles = $query->get()->keyBy(fn (MatrimonyProfile $profile): int => (int) $profile->id);

        return collect($profileIds)
            ->map(fn (int $id) => $profiles->get($id))
            ->filter()
            ->values();
    }

    /**
     * @param  Builder<MatrimonyProfile>  $query
     */
    private function applyMobileDiscoveryQuery(Builder $query, ?User $viewer, MobileDiscoveryFilterService $discovery): void
    {
        if ($viewer instanceof User) {
            $discovery->applyCandidateQuery($query, $viewer);

            return;
        }

        $query->whereRaw('1 = 0');
    }

    /**
     * @param  Builder<MatrimonyProfile>  $query
     */
    private function applyMobileListFilters(Builder $query, Request $request): void
    {
        // Soft deletes are automatically excluded by Laravel's SoftDeletes trait.
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

        // Age filter (from date_of_birth).
        if ($request->filled('age_from') || $request->filled('age_to')) {
            $query->whereNotNull('date_of_birth');

            if ($request->filled('age_from')) {
                $minDate = now()->subYears((int) $request->age_from)->format('Y-m-d');
                $query->whereDate('date_of_birth', '<=', $minDate);
            }

            if ($request->filled('age_to')) {
                $maxDate = now()->subYears(((int) $request->age_to) + 1)->addDay()->format('Y-m-d');
                $query->whereDate('date_of_birth', '>=', $maxDate);
            }
        }
    }

    /**
     * Mobile More Matches sections for the Flutter discovery screen.
     */
    public function moreSections(Request $request, MobileMoreMatchesSectionService $sections)
    {
        return response()->json($sections->forUser($request->user()));
    }

    /**
     * Get matrimony profile by ID
     * PIR-005: Visibility parity with Web — 404 when not visible to others or blocked.
     * PIR-006: Null-safe when profile's user is missing.
     */
    public function showById($id, MobileDiscoveryFilterService $discovery)
    {
        $profile = MatrimonyProfile::with('user')->find($id);

        if (! $profile) {
            return response()->json([
                'success' => false,
                'message' => 'Profile not found',
            ], 404);
        }

        $user = request()->user();
        if (! $user instanceof User || ! $discovery->isAllowedTarget($user, $profile)) {
            return response()->json([
                'success' => false,
                'message' => 'Profile not found',
            ], 404);
        }

        $viewerProfile = $user->matrimonyProfile;
        $this->recordMobileProfileViewIfEligible($user, $viewerProfile, $profile, false);

        // Transform to include gender from the governed profile relation only.
        // PIR-006: Null-safe when user relation is missing
        // Phase-4 Day-8: Use hierarchical location fields
        $profileData = $this->buildGovernanceParityProfilePayload($profile);

        return response()->json([
            'success' => true,
            'profile' => $profileData,
            'display' => app(MobileProfileDisplayPresenter::class)->forProfile($profile, $user),
        ]);
    }

    private function profileHasGovernedGender(MatrimonyProfile $profile): bool
    {
        return $profile->gender_id !== null && (int) $profile->gender_id > 0;
    }

    private function recordMobileProfileViewIfEligible(
        ?User $user,
        ?MatrimonyProfile $viewerProfile,
        MatrimonyProfile $profile,
        bool $isOwnProfile
    ): void {
        if (! $user || ! $viewerProfile || $isOwnProfile || $this->shouldSkipMobileProfileViewTracking($user)) {
            return;
        }

        if (ViewTrackingService::recordView($viewerProfile, $profile)) {
            try {
                ViewTrackingService::consumeDailyProfileViewUsageForViewer($viewerProfile);
            } catch (QuotaPolicySourceViolation $exception) {
                Log::warning('Mobile profile view quota consumption skipped because quota policy is incomplete.', [
                    'viewer_profile_id' => $viewerProfile->id,
                    'viewed_profile_id' => $profile->id,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }
            ViewTrackingService::maybeTriggerViewBack($viewerProfile, $profile);
        }
    }

    private function shouldSkipMobileProfileViewTracking(User $user): bool
    {
        if ($user->isAnyAdmin()) {
            return true;
        }

        return $user->suchakAccount()->exists();
    }

    /**
     * Deterministic API payload aligned with snapshot / governance canonical registry (Phase-6E).
     * Includes explicit *_id columns and legacy aliases where snapshots compare logical keys.
     *
     * @return array<string, mixed>
     */
    private function buildGovernanceParityProfilePayload(MatrimonyProfile $profile): array
    {
        $profile->loadMissing([
            'user',
            'gender',
            'horoscope',
            'preferenceCriteria',
            'religion',
            'caste',
            'subCaste',
            'birthCity',
            'motherTongue',
            'complexion',
            'bloodGroup',
            'physicalBuild',
        ]);

        $hints = $profile->residenceLocationHierarchyHints();
        $geo = $profile->residenceGeoAddressIds();
        $horoscope = $profile->horoscope;
        $locationLabel = trim($profile->residenceLocationDisplayLine());

        $criteria = $profile->preferenceCriteria;
        $partnerPreferences = $criteria !== null ? $criteria->toArray() : null;
        $birthPlaceLabel = trim($profile->birthLocationDisplayLine());
        if ($birthPlaceLabel === '') {
            $birthPlaceLabel = trim((string) ($profile->birth_place_text ?? ''));
        }

        $base = $profile->toArray();
        $parity = [
            'gender' => $profile->gender?->key,
            'gender_id' => $profile->gender_id,
            'caste_id' => $profile->caste_id,
            'sub_caste_id' => $profile->sub_caste_id,
            'location_id' => $profile->location_id,
            'country_id' => $geo['country_id'],
            'state_id' => $geo['state_id'],
            'district_id' => $geo['district_id'],
            'taluka_id' => $hints['taluka_id'] !== '' ? (int) $hints['taluka_id'] : null,
            'height_cm' => $profile->height_cm,
            'weight_kg' => $profile->weight_kg,
            'birth_time' => $profile->birth_time,
            'birth_city_id' => $profile->birth_city_id,
            'birth_place_text' => $profile->birth_place_text,
            'birth_place_label' => $birthPlaceLabel !== '' ? $birthPlaceLabel : null,
            'religion_id' => $profile->religion_id,
            'religion_label' => $profile->getRelation('religion')?->display_label,
            'caste_label' => $profile->getRelation('caste')?->display_label,
            'sub_caste_label' => $profile->getRelation('subCaste')?->display_label,
            'location_label' => $locationLabel !== '' ? $locationLabel : null,
            'mother_tongue_id' => $profile->mother_tongue_id,
            'mother_tongue_label' => $this->masterLookupLabel($profile->getRelation('motherTongue')),
            'marital_status_id' => $profile->marital_status_id,
            'occupation_title' => $profile->occupation_title,
            'annual_income' => $profile->annual_income,
            'family_type_id' => $profile->family_type_id,
            'complexion_id' => $profile->complexion_id,
            'complexion_label' => $this->masterLookupLabel($profile->getRelation('complexion')),
            'blood_group_id' => $profile->blood_group_id,
            'blood_group_label' => $this->masterLookupLabel($profile->getRelation('bloodGroup')),
            'physical_build_id' => $profile->physical_build_id,
            'physical_build_label' => $this->masterLookupLabel($profile->getRelation('physicalBuild')),
            'spectacles_lens' => $profile->spectacles_lens,
            'physical_condition' => $profile->physical_condition,
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

    private function masterLookupLabel(mixed $row): ?string
    {
        if (! $row) {
            return null;
        }

        foreach (['display_label', 'label_mr', 'label_en', 'label', 'name', 'key'] as $key) {
            $value = trim((string) ($row->getAttribute($key) ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

}
