<?php

namespace App\Http\Controllers;

use App\Models\MasterAddressType;
use App\Models\MasterAssetType;
use App\Models\MasterBloodGroup;
use App\Models\MasterChildLivingWith;
use App\Models\MasterComplexion;
use App\Models\MasterContactRelation;
use App\Models\MasterFamilyType;
use App\Models\MasterGender;
use App\Models\MasterGan;
use App\Models\MasterIncomeCurrency;
use App\Models\MasterLegalCaseType;
use App\Models\MasterMangalDoshType;
use App\Models\MasterMaritalStatus;
use App\Models\MasterNadi;
use App\Models\MasterNakshatra;
use App\Models\MasterOwnershipType;
use App\Models\MasterPhysicalBuild;
use App\Models\MasterRashi;
use App\Models\MasterYoni;
use App\Models\MatrimonyProfile;
use App\Models\ProfileFieldConfig;
use App\Models\Shortlist;
use App\Services\ProfileCompletenessService;
use App\Services\ProfileFieldConfigurationService;
use App\Services\ProfileFieldLockService;
use App\Services\ViewTrackingService;
use Illuminate\Http\Request;


/*
|--------------------------------------------------------------------------
| MatrimonyProfileController
|--------------------------------------------------------------------------
|
| 👉 हा controller MATRIMONY BIODATA साठी आहे
| 👉 User login / auth logic इथे येणार नाही
|
| लक्षात ठेव:
| User = authentication only
| MatrimonyProfile = full biodata
|
*/

class MatrimonyProfileController extends Controller
{
    /**
     * Phase-5B: Build snapshot (same schema as approval_snapshot_json) from request + profile.
     * Only includes keys present in request (or in overrides). No DB write.
     *
     * @param  array<string, mixed>  $overrides  e.g. ['profile_photo' => $path, 'photo_approved' => true]
     * @return array{core: array, contacts: array, children: array, education_history: array, career_history: array, addresses: array, property_summary: array, property_assets: array, horoscope: array, legal_cases: array, preferences: array, extended_narrative: array}
     */
    private function buildManualSnapshot(Request $request, MatrimonyProfile $profile, array $overrides = []): array
    {
        $enabledFields = ProfileFieldConfigurationService::getEnabledFieldKeys();
        $enabledMap = array_flip($enabledFields);

        $core = [];
        $coreKeys = [
            'full_name', 'date_of_birth', 'gender_id', 'marital_status_id', 'highest_education',
            'country_id', 'state_id', 'district_id', 'taluka_id', 'city_id',
            'religion_id', 'caste_id', 'sub_caste_id', 'height_cm', 'profile_photo', 'serious_intent_id',
            'photo_approved', 'photo_rejected_at', 'photo_rejection_reason', 'is_suspended',
        ];
        foreach ($coreKeys as $key) {
            if (array_key_exists($key, $overrides)) {
                $core[$key] = $overrides[$key];
                continue;
            }
            if ($key === 'gender_id' && !$request->has('gender_id')) {
                $core[$key] = $profile->getAttribute('gender_id');
                continue;
            }
            $enabled = $key === 'location' ? isset($enabledMap['location']) : isset($enabledMap[$key]);
            if (!$enabled && !in_array($key, ['gender_id', 'profile_photo', 'photo_approved', 'photo_rejected_at', 'photo_rejection_reason', 'is_suspended'], true)) {
                continue;
            }
            if ($request->has($key) || array_key_exists($key, $overrides)) {
                $val = $request->input($key, $overrides[$key] ?? null);
                if ($val instanceof \Carbon\Carbon) {
                    $val = $val->format('Y-m-d');
                }
                $core[$key] = $val === '' ? null : $val;
            }
        }
        if ($request->has('country_id') || $request->has('state_id') || $request->has('city_id')) {
            if (isset($enabledMap['location'])) {
                $core['country_id'] = $core['country_id'] ?? $request->input('country_id');
                $core['state_id'] = $core['state_id'] ?? $request->input('state_id');
                $core['district_id'] = $core['district_id'] ?? $request->input('district_id');
                $core['taluka_id'] = $core['taluka_id'] ?? $request->input('taluka_id');
                $core['city_id'] = $core['city_id'] ?? $request->input('city_id');
            }
        }

        $contacts = [];
        if ($request->has('primary_contact_phone') || $request->has('primary_contact_number')) {
            $phone = trim((string) ($request->input('primary_contact_phone') ?? $request->input('primary_contact_number') ?? ''));
            if ($phone !== '') {
                $contacts[] = [
                    'relation_type' => 'self',
                    'contact_name' => 'Primary',
                    'phone_number' => $phone,
                    'is_primary' => true,
                ];
            }
        }

        $children = [];
        if ($request->has('children') && is_array($request->input('children'))) {
            $currentYear = (int) date('Y');
            foreach (array_values($request->input('children')) as $row) {
                $id = !empty($row['id']) ? (int) $row['id'] : null;
                $birthYear = !empty($row['child_birth_year']) ? (int) $row['child_birth_year'] : null;
                $age = $birthYear > 0 ? $currentYear - $birthYear : 0;
                $custody = $row['custody_status'] ?? '';
                $children[] = [
                    'id' => $id,
                    'child_name' => trim((string) ($row['child_name'] ?? '')),
                    'gender' => trim((string) ($row['child_gender'] ?? '')),
                    'age' => $age,
                    'lives_with_parent' => $custody === 'with_me',
                ];
            }
        }

        $education_history = [];
        if ($request->has('education_history') && is_array($request->input('education_history'))) {
            foreach (array_values($request->input('education_history')) as $row) {
                $education_history[] = [
                    'id' => !empty($row['id']) ? (int) $row['id'] : null,
                    'degree' => trim((string) ($row['degree'] ?? '')),
                    'specialization' => trim((string) ($row['field_of_study'] ?? '')),
                    'university' => trim((string) ($row['institution'] ?? '')),
                    'year_completed' => !empty($row['year_completed']) ? (int) $row['year_completed'] : 0,
                ];
            }
            $latest = collect($education_history)->filter(fn ($r) => ($r['year_completed'] ?? 0) > 0 && ($r['degree'] ?? '') !== '')->sortByDesc('year_completed')->first();
            if ($latest !== null) {
                $core['highest_education'] = $latest['degree'];
            }
        }

        $career_history = [];
        if ($request->has('career_history') && is_array($request->input('career_history'))) {
            foreach (array_values($request->input('career_history')) as $row) {
                $career_history[] = [
                    'id' => !empty($row['id']) ? (int) $row['id'] : null,
                    'designation' => trim((string) ($row['job_title'] ?? '')),
                    'company' => trim((string) ($row['company_name'] ?? '')),
                    'start_year' => !empty($row['start_year']) ? (int) $row['start_year'] : null,
                    'end_year' => !empty($row['end_year']) ? (int) $row['end_year'] : null,
                ];
            }
        }

        $snapshot = ['core' => $core];
        if ($contacts !== []) {
            $snapshot['contacts'] = $contacts;
        }
        if ($request->has('children') && is_array($request->input('children'))) {
            $snapshot['children'] = $children;
        }
        if ($request->has('education_history') && is_array($request->input('education_history'))) {
            $snapshot['education_history'] = $education_history;
        }
        if ($request->has('career_history') && is_array($request->input('career_history'))) {
            $snapshot['career_history'] = $career_history;
        }
        // Phase-5B PART-2: Extended fields passed into snapshot; applied inside MutationService transaction.
        if ($request->has('extended_fields') && is_array($request->input('extended_fields'))) {
            $snapshot['extended_fields'] = $request->input('extended_fields');
        }
        return $snapshot;
    }

    /*
    |--------------------------------------------------------------------------
    | Edit Matrimony Profile
    |--------------------------------------------------------------------------
    |
    | 👉 Existing profile असल्यास edit form दाखवतो
    |
    */
    public function edit()
{
    $user = auth()->user();

    // 🔒 GUARD: Profile नसेल तर edit allowed नाही
    if (!$user->matrimonyProfile) {
        return redirect()
            ->route('matrimony.profile.wizard.section', ['section' => 'basic-info'])
            ->with('error', 'Please create your matrimony profile first.');
    }

    // Phase-5B: Single edit path = wizard. Redirect to wizard (full section).
    return redirect()->route('matrimony.profile.wizard.section', ['section' => 'full']);
}

    /**
     * Phase-5B: Legacy update route removed. Use wizard only.
     */
    public function update(Request $request)
    {
        abort(404);
    }

public function uploadPhoto()
{
    $user = auth()->user();

    if (!$user->matrimonyProfile) {
        return redirect()
            ->route('matrimony.profile.wizard.section', ['section' => 'basic-info'])
            ->with('error', 'Please create your profile first.');
    }

    return view('matrimony.profile.upload-photo');
}

public function storePhoto(Request $request)
{
    $request->validate([
        'profile_photo' => 'required|image|max:2048',
    ]);

    $user = auth()->user();

    // 🔒 Guard: MatrimonyProfile must exist
if (!$user->matrimonyProfile) {
    return redirect()
        ->route('matrimony.profile.wizard.section', ['section' => 'basic-info'])
        ->with('error', 'Please create your profile first.');
}

    $profile = $user->matrimonyProfile;
    if ($profile->user_id !== $user->id) {
        abort(403, 'Unauthorized profile photo update attempt.');
    }

    // Phase-5 PART-5: Block manual edit when lifecycle blocks it
    if (in_array($profile->lifecycle_state, [
        'intake_uploaded', 'awaiting_user_approval', 'approved_pending_mutation', 'conflict_pending',
    ], true)) {
        return redirect()->back()->with('error', 'Profile cannot be edited while intake or conflict is pending.');
    }

    $file = $request->file('profile_photo');
    $filename = time() . '_' . basename($file->getClientOriginalName());
    $file->move(public_path('uploads/matrimony_photos'), $filename);

    $photoApprovalRequired = \App\Services\AdminSettingService::isPhotoApprovalRequired();
    $photoApproved = !$photoApprovalRequired;

    $snapshot = [
        'core' => [
            'profile_photo' => $filename,
            'photo_approved' => $photoApproved,
            'photo_rejected_at' => null,
            'photo_rejection_reason' => null,
        ],
        'contacts' => [],
        'children' => [],
        'education_history' => [],
        'career_history' => [],
        'addresses' => [],
        'property_summary' => [],
        'property_assets' => [],
        'horoscope' => [],
        'legal_cases' => [],
        'preferences' => [],
        'extended_narrative' => [],
    ];

    try {
        $result = app(\App\Services\MutationService::class)->applyManualSnapshot($profile, $snapshot, (int) $user->id);
    } catch (\RuntimeException $e) {
        return redirect()->back()->with('error', $e->getMessage());
    }

    if ($result['conflict_detected']) {
        return redirect()->route('matrimony.profile.wizard.section', ['section' => 'full'])->with('warning', 'Photo uploaded but some conflicts were detected.');
    }

    return redirect()
        ->route('matrimony.profiles.index')
        ->with('success', 'Profile photo uploaded successfully.');
}


    /*
    |--------------------------------------------------------------------------
    | Show Single Matrimony Profile
    |--------------------------------------------------------------------------
    |
    | 👉 Public / logged-in users साठी profile view
    |
    | ⚠️ Interest logic इथे तात्पुरता आहे
    | पुढच्या step मध्ये refactor होईल
    |
    */
 


// Route param: {matrimony_profile_id} (profile id)
public function show($matrimony_profile_id)
{
    $profile = \App\Models\MatrimonyProfile::with([
        'gender',
        'maritalStatus',
        'complexion',
        'physicalBuild',
        'bloodGroup',
        'familyType',
        'incomeCurrency',
        'horoscope',
        'children',
        'educationHistory',
        'career',
        'addresses.village',
        'relatives.city',
        'relatives.state',
        'allianceNetworks.city',
        'allianceNetworks.state',
        'allianceNetworks.district',
        'allianceNetworks.taluka',
        'birthCity',
        'birthState',
        'birthDistrict',
        'birthTaluka',
        'nativeCity',
        'nativeState',
        'nativeDistrict',
        'nativeTaluka',
        'siblings.city',
        'religion',
        'caste',
        'subCaste',
    ])->findOrFail($matrimony_profile_id);

    $extendedAttributes = \Illuminate\Support\Facades\DB::table('profile_extended_attributes')->where('profile_id', $profile->id)->first();
    $preferences = \Illuminate\Support\Facades\DB::table('profile_preferences')->where('profile_id', $profile->id)->first();


    // 🔒 GUARD: Guest users are NOT allowed to view single profiles
    if (!auth()->check()) {
        return redirect()
            ->route('login')
            ->with('error', 'Please login to view matrimony profiles.');
    }

    $authUser = auth()->user();

    // 🔒 Logged-in but no profile
    if (!$authUser->matrimonyProfile) {
        return redirect()
            ->route('matrimony.profile.wizard.section', ['section' => 'basic-info'])
            ->with('error', 'Please create your matrimony profile first.');
    }

    $viewer = auth()->user(); // logged-in user
    $isOwnProfile = $viewer && (
        $viewer->matrimonyProfile->id === $profile->id
    );

    // 🔒 GUARD: Day 7 lifecycle — Archived/Suspended not visible to others (backward compat: is_suspended, trashed)
    if (!$isOwnProfile && !\App\Services\ProfileLifecycleService::isVisibleToOthers($profile)) {
        abort(404, 'Profile not found.');
    }

    // 🔒 GUARD: Block excludes profile view (either direction)
    if (!$isOwnProfile && $viewer->matrimonyProfile) {
        if (ViewTrackingService::isBlocked($viewer->matrimonyProfile->id, $profile->id)) {
            abort(404, 'Profile not found.');
        }
    }

    // 🔒 GUARD: Phase-4 Day-10 — Women-First Safety visibility policy
    if (!$isOwnProfile && !\App\Services\ProfileVisibilityPolicyService::canViewProfile($profile, $viewer)) {
        abort(404, 'Profile not found.');
    }

    $interestAlreadySent = false;

    if (auth()->check()) {
        $interestAlreadySent = \App\Models\Interest::where(
            'sender_profile_id',
            auth()->user()->matrimonyProfile->id
        )
        ->where('receiver_profile_id', $profile->id)
        ->exists();
    }

    // Check if user has already submitted an open abuse report for this profile
    $hasAlreadyReported = false;
    if (auth()->check() && !$isOwnProfile) {
        $hasAlreadyReported = \App\Models\AbuseReport::where('reporter_user_id', auth()->id())
            ->where('reported_profile_id', $profile->id)
            ->where('status', 'open')
            ->exists();
    }

    $inShortlist = false;
    if (!$isOwnProfile && $viewer->matrimonyProfile) {
        $inShortlist = Shortlist::where('owner_profile_id', $viewer->matrimonyProfile->id)
            ->where('shortlisted_profile_id', $profile->id)
            ->exists();
    }

    if (!$isOwnProfile && $viewer->matrimonyProfile) {
        ViewTrackingService::recordView($viewer->matrimonyProfile, $profile);
        ViewTrackingService::maybeTriggerViewBack($viewer->matrimonyProfile, $profile);
    }

    // Profile completeness (from service, passed to view)
    $completenessPct = ProfileCompletenessService::percentage($profile);

    // Day-18: Calculate individual boolean visibility flags (Blade Purity Law compliance)
    $visibleFields = ProfileFieldConfigurationService::getVisibleFieldKeys();
    $profilePhotoVisible = in_array('profile_photo', $visibleFields, true);
    $dateOfBirthVisible = in_array('date_of_birth', $visibleFields, true);
    $maritalStatusVisible = in_array('marital_status', $visibleFields, true);
    $educationVisible = in_array('education', $visibleFields, true);
    $locationVisible = in_array('location', $visibleFields, true);
    $casteVisible = in_array('caste', $visibleFields, true);
    $heightVisible = in_array('height_cm', $visibleFields, true);

    // Match explanation data (rule-based comparison)
    $matchData = null;
    if (!$isOwnProfile && $viewer->matrimonyProfile) {
        $matchData = self::calculateMatchExplanation($viewer->matrimonyProfile, $profile);
    }

    $canViewContact = \App\Services\ContactVisibilityPolicyService::canViewContact(
        $profile,
        $viewer->matrimonyProfile ?? null
    );

    $extendedValues = \App\Services\ExtendedFieldService::getValuesForProfile($profile);
    // Phase-4: Only show extended fields that are enabled in registry (visibility)
    $visibleExtendedKeys = \App\Models\FieldRegistry::where('field_type', 'EXTENDED')
        ->where(function ($q) {
            $q->where('is_enabled', true)->orWhereNull('is_enabled');
        })
        ->pluck('field_key')
        ->flip()
        ->all();
    $extendedValues = array_intersect_key($extendedValues, $visibleExtendedKeys);
    $extendedMeta = \App\Models\FieldRegistry::where('field_type', 'EXTENDED')
        ->where(function ($q) {
            $q->where('is_enabled', true)->orWhereNull('is_enabled');
        })
        ->pluck('display_label', 'field_key')
        ->toArray();

    $primaryContactPhone = \Illuminate\Support\Facades\DB::table('profile_contacts')
        ->where('profile_id', $profile->id)
        ->where('is_primary', true)
        ->value('phone_number');

    $hasBlockingConflicts = \App\Services\ProfileLifecycleService::hasBlockingUnresolvedConflicts($profile);

    $visibilitySettings = \Illuminate\Support\Facades\DB::table('profile_visibility_settings')
        ->where('profile_id', $profile->id)
        ->first();
    $enableRelativesSection = optional($visibilitySettings)->enable_relatives_section ?? true;

    $profilePropertySummary = \Illuminate\Support\Facades\DB::table('profile_property_summary')
        ->where('profile_id', $profile->id)
        ->first();

    return view(
        'matrimony.profile.show',
        [
            'profile'              => $profile,
            'profilePropertySummary' => $profilePropertySummary,
            'enableRelativesSection' => $enableRelativesSection,
            'isOwnProfile'         => $isOwnProfile,
            'interestAlreadySent'  => $interestAlreadySent,
            'hasAlreadyReported'   => $hasAlreadyReported,
            'inShortlist'          => $inShortlist,
            'extendedValues'       => $extendedValues,
            'extendedMeta'         => $extendedMeta,
            'extendedAttributes'   => $extendedAttributes,
            'preferences'          => $preferences,
            'completenessPct'      => $completenessPct,
            'profilePhotoVisible' => $profilePhotoVisible,
            'dateOfBirthVisible'  => $dateOfBirthVisible,
            'maritalStatusVisible' => $maritalStatusVisible,
            'educationVisible'     => $educationVisible,
            'locationVisible'      => $locationVisible,
            'casteVisible'         => $casteVisible,
            'heightVisible'        => $heightVisible,
            'matchData'            => $matchData,
            'canViewContact'       => $canViewContact,
            'primaryContactPhone'  => $primaryContactPhone,
            'hasBlockingConflicts'  => $hasBlockingConflicts,
        ]
    );
}



    /*
    |--------------------------------------------------------------------------
    | List & Search Matrimony Profiles
    |--------------------------------------------------------------------------
    |
    | 👉 Search + listing साठी
    | 👉 Only MatrimonyProfile model वापरतो
    |
    */
    public function index(Request $request)
    {
        $query = MatrimonyProfile::latest();

        // Day 7: Only active profiles searchable; NULL treated as active (backward compat)
        $query->where(function ($q) {
            $q->where('lifecycle_state', 'active')->orWhereNull('lifecycle_state');
        })->where('is_suspended', false);
        // Soft deletes are automatically excluded by Laravel's SoftDeletes trait

        // Day-18: Only use enabled AND searchable fields for search
        $searchableFields = ProfileFieldConfigurationService::getSearchableFieldKeys();
        $enabledFields = ProfileFieldConfigurationService::getEnabledFieldKeys();
        
        // Intersection: fields that are both enabled and searchable
        $enabledSearchableFields = array_intersect($searchableFields, $enabledFields);

        // Helper: check if field is enabled and searchable
        $isSearchable = fn(string $fieldKey) => in_array($fieldKey, $enabledSearchableFields, true);

        // Caste filter (only if searchable) — normalized: use caste_id
        if ($isSearchable('caste') && $request->filled('caste_id')) {
            $query->where('caste_id', $request->input('caste_id'));
        }

        // Phase-4 Day-8: Location hierarchy filters (only if searchable)
        if ($isSearchable('location')) {
            if ($request->filled('city_id')) {
                $query->where('city_id', $request->city_id);
            }
        }

        // Age filter from date_of_birth (only if searchable)
        if ($isSearchable('date_of_birth') && ($request->filled('age_from') || $request->filled('age_to'))) {
            $query->whereNotNull('date_of_birth');
            if ($request->filled('age_from')) {
                $minDate = now()->subYears((int) $request->age_from)->format('Y-m-d');
                $query->whereDate('date_of_birth', '<=', $minDate);
            }
            if ($request->filled('age_to')) {
                $maxDate = now()->subYears((int) $request->age_to + 1)->addDay()->format('Y-m-d');
                $query->whereDate('date_of_birth', '>=', $maxDate);
            }
        }

        // Height filter (only if searchable)
        if ($isSearchable('height_cm')) {
            if ($request->filled('height_from')) {
                $query->whereNotNull('height_cm')->where('height_cm', '>=', (int) $request->height_from);
            }
            if ($request->filled('height_to')) {
                $query->whereNotNull('height_cm')->where('height_cm', '<=', (int) $request->height_to);
            }
        }

        // Marital status filter (Phase-5: marital_status_id)
        if ($isSearchable('marital_status_id') && ($request->filled('marital_status_id') || $request->filled('marital_status'))) {
            $msId = $request->input('marital_status_id') ?: ($request->input('marital_status') === 'single'
                ? \App\Models\MasterMaritalStatus::where('key', 'never_married')->value('id')
                : \App\Models\MasterMaritalStatus::where('key', $request->input('marital_status'))->value('id'));
            if ($msId) {
                $query->where('marital_status_id', $msId);
            }
        }

        // Education filter (only if searchable) — column: highest_education
        if ($isSearchable('education') && $request->filled('education')) {
            $query->where('highest_education', $request->input('education'));
        }

        // 70% completeness or admin override (search visibility only)
        $query->whereRaw(ProfileCompletenessService::sqlSearchVisible('matrimony_profiles'));

        // Admin global toggle: hide demo profiles from search when OFF (Day-8)
        $demoVisible = \App\Models\AdminSetting::getBool('demo_profiles_visible_in_search', true);
        if (!$demoVisible) {
            $query->where(function ($q) {
                $q->where('is_demo', false)->orWhereNull('is_demo');
            });
        }

        // Exclude blocked profiles (either direction) when viewer has profile
        $myId = auth()->user()?->matrimonyProfile?->id;
        if ($myId) {
            $blockedIds = ViewTrackingService::getBlockedProfileIds($myId);
            if ($blockedIds->isNotEmpty()) {
                $query->whereNotIn('id', $blockedIds);
            }
        }

        $perPage = (int) $request->input('per_page', 15);
        $perPage = $perPage >= 1 && $perPage <= 100 ? $perPage : 15;
        $profiles = $query->with(['country', 'state', 'district', 'taluka', 'city'])->paginate($perPage)->withQueryString();

        // Phase-4 Day-8: Pass location data for search filters
        $cities = \App\Models\City::all();

        return view('matrimony.profile.index', compact('profiles', 'cities'));

    }

    /**
     * Calculate match explanation between viewer's profile and viewed profile.
     * Rule-based comparison, no AI/ML. Returns match data for UI display.
     *
     * @param MatrimonyProfile $viewerProfile Viewer's own profile
     * @param MatrimonyProfile $viewedProfile Profile being viewed
     * @return array|null Match explanation data or null if own profile
     */
    private static function calculateMatchExplanation(MatrimonyProfile $viewerProfile, MatrimonyProfile $viewedProfile): array
    {
        $matches = [];
        $commonGround = [];

        // Define comparison fields (preferences to check) — location handled separately via hierarchy
        $preferenceFields = [
            'highest_education' => ['label' => 'शिक्षण', 'icon' => '🎓'],
            'caste' => ['label' => 'जात', 'icon' => '🗣️'],
            'marital_status' => ['label' => 'वैवाहिक स्थिती', 'icon' => '💑'],
        ];

        // Location comparison (hierarchy: city_id = exact match, state_id = partial)
        $viewerCityId = $viewerProfile->city_id;
        $viewedCityId = $viewedProfile->city_id;
        $viewerStateId = $viewerProfile->state_id;
        $viewedStateId = $viewedProfile->state_id;
        if ($viewerCityId || $viewedCityId || $viewerStateId || $viewedStateId) {
            $locationMatched = false;
            if ($viewerCityId && $viewedCityId && (int) $viewerCityId === (int) $viewedCityId) {
                $locationMatched = true;
            } elseif ($viewerStateId && $viewedStateId && (int) $viewerStateId === (int) $viewedStateId) {
                $locationMatched = true; // partial (same state)
            }
            $matches[] = [
                'field' => 'location',
                'label' => 'Location',
                'icon' => '📍',
                'matched' => $locationMatched,
            ];
            if ($locationMatched) {
                $commonGround[] = [
                    'field' => 'location',
                    'label' => 'Location',
                    'icon' => '📍',
                    'value' => $viewedProfile->city_id ? ($viewedProfile->city?->name ?? '—') : ($viewedProfile->state?->name ?? '—'),
                ];
            }
        }

        // Age comparison (from date_of_birth)
        if ($viewerProfile->date_of_birth && $viewedProfile->date_of_birth) {
            $viewerAge = now()->diffInYears($viewerProfile->date_of_birth);
            $viewedAge = now()->diffInYears($viewedProfile->date_of_birth);
            $ageDiff = abs($viewerAge - $viewedAge);
            
            // Consider age match if within 5 years (flexible)
            if ($ageDiff <= 5) {
                $matches[] = [
                    'field' => 'age',
                    'label' => 'वय',
                    'icon' => '🎂',
                    'matched' => true,
                ];
            } else {
                $matches[] = [
                    'field' => 'age',
                    'label' => 'वय',
                    'icon' => '🎂',
                    'matched' => false,
                ];
            }
        }

        // Compare other preference fields
        foreach ($preferenceFields as $fieldKey => $fieldInfo) {
            $viewerValue = $viewerProfile->$fieldKey;
            $viewedValue = $viewedProfile->$fieldKey;

            if ($viewerValue && $viewedValue) {
                $isMatch = strtolower(trim($viewerValue)) === strtolower(trim($viewedValue));
                
                $matches[] = [
                    'field' => $fieldKey,
                    'label' => $fieldInfo['label'],
                    'icon' => $fieldInfo['icon'],
                    'matched' => $isMatch,
                ];

                // Add to common ground if matched
                if ($isMatch) {
                    $commonGround[] = [
                        'field' => $fieldKey,
                        'label' => $fieldInfo['label'],
                        'icon' => $fieldInfo['icon'],
                        'value' => $viewedValue,
                    ];
                }
            }
        }

        // Calculate match summary
        $matchedCount = count(array_filter($matches, fn($m) => $m['matched']));
        $totalCount = count($matches);

        // Generate summary text
        if ($totalCount > 0) {
            if ($matchedCount > 0) {
                $summaryText = "तुमची प्रोफाइल त्यांच्या {$totalCount} पैकी {$matchedCount} अपेक्षांशी जुळते";
            } else {
                $summaryText = "या प्रोफाइलशी काही बाबतीत साम्य आहे";
            }
        } else {
            $summaryText = "या प्रोफाइलशी काही बाबतीत साम्य आहे";
        }

        // Celebration text
        $celebrationText = null;
        if ($matchedCount >= 3) {
            $celebrationText = "बर्‍याच गोष्टी जुळत आहेत";
        } elseif ($matchedCount > 0) {
            $celebrationText = "चांगली सुरुवात 👍";
        }

        return [
            'matches' => $matches,
            'commonGround' => $commonGround,
            'matchedCount' => $matchedCount,
            'totalCount' => $totalCount,
            'summaryText' => $summaryText,
            'celebrationText' => $celebrationText,
        ];
    }

    /**
     * Phase-4 Day-8: Validate location hierarchy integrity
     * Ensures child location references correct parent in hierarchy
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
