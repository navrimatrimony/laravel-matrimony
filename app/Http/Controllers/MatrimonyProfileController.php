<?php

namespace App\Http\Controllers;

use App\Models\MatrimonyProfile;
use App\Models\ProfileFieldConfig;
use App\Models\Shortlist;
use App\Services\ProfileCompletenessService;
use App\Services\ProfileFieldConfigurationService;
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
    /*
    |--------------------------------------------------------------------------
    | Show Create Profile Form
    |--------------------------------------------------------------------------
    |
    | हा method तेव्हाच वापरला जातो
    | जेव्हा user कडे अजून matrimony profile नसतो
    |
    */
    public function create()
    {
        $user = auth()->user();
    
        // 🔒 GUARD:
        // Profile आधीच असेल तर पुन्हा create करू देऊ नका
        if ($user->matrimonyProfile) {
            return redirect()
                ->route('matrimony.profiles.index')
                ->with('info', 'Your matrimony profile already exists. You can search profiles.');
        }
    
        // Day-18: Pass visible and enabled fields info to view
        $visibleFields = ProfileFieldConfigurationService::getVisibleFieldKeys();
        $enabledFields = ProfileFieldConfigurationService::getEnabledFieldKeys();
        
        // Profile नाही → create form
        return view('matrimony.profile.create', [
            'visibleFields' => $visibleFields,
            'enabledFields' => $enabledFields,
        ]);
    }
    


/*
|--------------------------------------------------------------------------
| Store Matrimony Profile (FIRST TIME CREATE)
|--------------------------------------------------------------------------
|
| 👉 User चा पहिल्यांदा biodata save करण्यासाठी
| 👉 $user->matrimonyProfile() relation वापरतो
|
*/
public function store(Request $request)
{
    $request->validate([
        'marital_status' => 'required|in:single,divorced,widowed',
    ]);

    $user = auth()->user();

    // Policy: Check manual activation requirement
    $manualActivationRequired = \App\Services\AdminSettingService::isManualProfileActivationRequired();
    $isSuspended = $manualActivationRequired ? true : false;

    // Day-18: Only include enabled fields in create/update
    $enabledFields = ProfileFieldConfigurationService::getEnabledFieldKeys();
    $enabledFieldsMap = array_flip($enabledFields);

    $profileData = [
        'full_name'      => $request->full_name,
        'gender'         => $user->gender, // system-derived
        'is_suspended'   => $isSuspended,
    ];

    // Only add enabled fields from request
    if (isset($enabledFieldsMap['date_of_birth']) && $request->has('date_of_birth')) {
        $profileData['date_of_birth'] = $request->date_of_birth;
    }
    if (isset($enabledFieldsMap['marital_status']) && $request->has('marital_status')) {
        $profileData['marital_status'] = $request->marital_status;
    }
    if (isset($enabledFieldsMap['education']) && $request->has('education')) {
        $profileData['education'] = $request->education;
    }
    if (isset($enabledFieldsMap['location']) && $request->has('location')) {
        $profileData['location'] = $request->location;
    }
    if (isset($enabledFieldsMap['caste']) && $request->has('caste')) {
        $profileData['caste'] = $request->caste;
    }

    MatrimonyProfile::updateOrCreate(
        ['user_id' => $user->id],
        $profileData
    );


    return redirect()
        ->route('matrimony.profile.upload-photo')
        ->with('success', 'Matrimony profile created successfully. Please upload your photo.');
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
            ->route('matrimony.profile.create')
            ->with('error', 'Please create your matrimony profile first.');
    }

    // Day-18: Pass visible and enabled fields info to view
    $visibleFields = ProfileFieldConfigurationService::getVisibleFieldKeys();
    $enabledFields = ProfileFieldConfigurationService::getEnabledFieldKeys();
    
    // ✅ Profile exists → edit page
    return view('matrimony.profile.edit', [
        'matrimonyProfile' => $user->matrimonyProfile,
        'visibleFields' => $visibleFields,
        'enabledFields' => $enabledFields,
    ]);
}




    /*
    |--------------------------------------------------------------------------
    | Update Matrimony Profile
    |--------------------------------------------------------------------------
    |
    | 👉 Existing biodata update करण्यासाठी
    |
    */
    public function update(Request $request)
{
    $request->validate([
        'marital_status' => 'required|in:single,divorced,widowed',
    ]);

    $user = auth()->user();

    if (!$user->matrimonyProfile) {
        return redirect()
            ->route('matrimony.profile.create')
            ->with('error', 'Please create your matrimony profile first.');
    }

    // 🔴 PHOTO UPLOAD LOGIC (IMPORTANT)
    $photoPath = $user->matrimonyProfile->profile_photo;

    if ($request->hasFile('profile_photo')) 
    $photoPath = $user->matrimonyProfile->profile_photo;

if ($request->hasFile('profile_photo')) {

    $file = $request->file('profile_photo');
    $filename = time().'_'.$file->getClientOriginalName();

    $file->move(
        public_path('uploads/matrimony_photos'),
        $filename
    );

    $photoPath = $filename;
}

    // Day-18: Only include enabled fields in update
    $enabledFields = ProfileFieldConfigurationService::getEnabledFieldKeys();
    $enabledFieldsMap = array_flip($enabledFields);

    // Prepare update data
    $updateData = [
        'full_name'      => $request->full_name,
        'profile_photo'  => $photoPath,
    ];

    // Only add enabled fields from request
    if (isset($enabledFieldsMap['date_of_birth']) && $request->has('date_of_birth')) {
        $updateData['date_of_birth'] = $request->date_of_birth;
    }
    if (isset($enabledFieldsMap['marital_status']) && $request->has('marital_status')) {
        $updateData['marital_status'] = $request->marital_status;
    }
    if (isset($enabledFieldsMap['education']) && $request->has('education')) {
        $updateData['education'] = $request->education;
    }
    if (isset($enabledFieldsMap['location']) && $request->has('location')) {
        $updateData['location'] = $request->location;
    }
    if (isset($enabledFieldsMap['caste']) && $request->has('caste')) {
        $updateData['caste'] = $request->caste;
    }

    // If new photo uploaded, apply policy-based approval status
    if ($request->hasFile('profile_photo')) {
        $photoApprovalRequired = \App\Services\AdminSettingService::isPhotoApprovalRequired();
        
        if ($photoApprovalRequired) {
            // Policy: Approval required - photo hidden until admin approves
            $updateData['photo_approved'] = false;
        } else {
            // Policy: No approval required - photo visible immediately
            $updateData['photo_approved'] = true;
        }
        
        $updateData['photo_rejected_at'] = null;
        $updateData['photo_rejection_reason'] = null; // Clear rejection reason on new upload
    }

    // Policy: Check suspend after profile edit
    $suspendAfterEdit = \App\Services\AdminSettingService::shouldSuspendAfterProfileEdit();
    if ($suspendAfterEdit) {
        $suspendMode = \App\Services\AdminSettingService::getSuspendMode();
        
        if ($suspendMode === 'full') {
            // Policy: Full suspension - entire profile suspended
            $updateData['is_suspended'] = true;
        } elseif ($suspendMode === 'new_content_only') {
            // Policy: New content only - profile remains active but new edits hidden
            // Note: This requires additional tracking which is out of scope
            // For now, we'll treat it as no suspension
        }
    }

    $user->matrimonyProfile->update($updateData);

    return redirect()
        ->route('matrimony.profile.edit')
        ->with('success', 'Profile updated successfully.');
}

public function uploadPhoto()
{
    $user = auth()->user();

    if (!$user->matrimonyProfile) {
        return redirect()
            ->route('matrimony.profile.create')
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
        ->route('matrimony.profile.create')
        ->with('error', 'Please create your profile first.');
}

// 🔐 AUTHORIZATION HARDENING (DAY 20)
// 👉 Logged-in user कडे profile आहेच (वर check केले)
// 👉 पण future-proofing साठी explicit ownership स्पष्ट करतो

$matrimonyProfile = $user->matrimonyProfile;

// ❌ Extra safety: profile mismatch impossible, पण explicit guard
if ($matrimonyProfile->user_id !== $user->id) {
    abort(403, 'Unauthorized profile photo update attempt.');
}



    $file = $request->file('profile_photo');

// 🔒 PROFILE PHOTO UPLOAD (SSOT locked)
// 👉 DB मध्ये फक्त filename save होईल

$file = $request->file('profile_photo');

// ⚠️ basename वापरून path duplication थांबवतो
$filename = time().'_'.basename($file->getClientOriginalName());

// 📁 Physical upload location
$file->move(
    public_path('uploads/matrimony_photos'),
    $filename
);

// 🗂️ DB: ONLY filename (NO folder)
// Apply policy-based approval status
$photoApprovalRequired = \App\Services\AdminSettingService::isPhotoApprovalRequired();

if ($photoApprovalRequired) {
    // Policy: Approval required - photo hidden until admin approves
    $photoApproved = false;
} else {
    // Policy: No approval required - photo visible immediately
    $photoApproved = true;
}

$user->matrimonyProfile->update([
    'profile_photo' => $filename,
    'photo_approved' => $photoApproved,
    'photo_rejected_at' => null,
    'photo_rejection_reason' => null,
]);



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
 


// 🔒 SSOT-COMPLIANT ROUTE MODEL BINDING
// Route param: {matrimony_profile_id}
// Internal variable: $matrimonyProfile (SSOT rule)
public function show(MatrimonyProfile $matrimony_profile_id)
{
    // 🔁 clarity alias (SSOT variable rule)
    $matrimonyProfile = $matrimony_profile_id;


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
            ->route('matrimony.profile.create')
            ->with('error', 'Please create your matrimony profile first.');
    }

    $viewer = auth()->user(); // logged-in user
    $isOwnProfile = $viewer && (
        $viewer->matrimonyProfile->id === $matrimonyProfile->id
    );

    // 🔒 GUARD: Cannot view suspended or soft-deleted profiles (unless owner viewing own profile)
    if (!$isOwnProfile && ($matrimonyProfile->is_suspended || $matrimonyProfile->trashed())) {
        abort(404, 'Profile not found.');
    }

    // 🔒 GUARD: Block excludes profile view (either direction)
    if (!$isOwnProfile && $viewer->matrimonyProfile) {
        if (ViewTrackingService::isBlocked($viewer->matrimonyProfile->id, $matrimonyProfile->id)) {
            abort(404, 'Profile not found.');
        }
    }

    $interestAlreadySent = false;

    if (auth()->check()) {
        $interestAlreadySent = \App\Models\Interest::where(
            'sender_profile_id',
            auth()->user()->matrimonyProfile->id
        )
        ->where('receiver_profile_id', $matrimonyProfile->id)
        ->exists();
    }

    // Check if user has already submitted an open abuse report for this profile
    $hasAlreadyReported = false;
    if (auth()->check() && !$isOwnProfile) {
        $hasAlreadyReported = \App\Models\AbuseReport::where('reporter_user_id', auth()->id())
            ->where('reported_profile_id', $matrimonyProfile->id)
            ->where('status', 'open')
            ->exists();
    }

    $inShortlist = false;
    if (!$isOwnProfile && $viewer->matrimonyProfile) {
        $inShortlist = Shortlist::where('owner_profile_id', $viewer->matrimonyProfile->id)
            ->where('shortlisted_profile_id', $matrimonyProfile->id)
            ->exists();
    }

    if (!$isOwnProfile && $viewer->matrimonyProfile) {
        ViewTrackingService::recordView($viewer->matrimonyProfile, $matrimonyProfile);
        ViewTrackingService::maybeTriggerViewBack($viewer->matrimonyProfile, $matrimonyProfile);
    }

    // Profile completeness (from service, passed to view)
    $completenessPct = ProfileCompletenessService::percentage($matrimonyProfile);

    // Day-18: Calculate individual boolean visibility flags (Blade Purity Law compliance)
    $visibleFields = ProfileFieldConfigurationService::getVisibleFieldKeys();
    $profilePhotoVisible = in_array('profile_photo', $visibleFields, true);
    $dateOfBirthVisible = in_array('date_of_birth', $visibleFields, true);
    $maritalStatusVisible = in_array('marital_status', $visibleFields, true);
    $educationVisible = in_array('education', $visibleFields, true);
    $locationVisible = in_array('location', $visibleFields, true);
    $casteVisible = in_array('caste', $visibleFields, true);

    // Match explanation data (rule-based comparison)
    $matchData = null;
    if (!$isOwnProfile && $viewer->matrimonyProfile) {
        $matchData = self::calculateMatchExplanation($viewer->matrimonyProfile, $matrimonyProfile);
    }

    return view(
        'matrimony.profile.show',
        [
            'matrimonyProfile'     => $matrimonyProfile,
            'isOwnProfile'         => $isOwnProfile,
            'interestAlreadySent'  => $interestAlreadySent,
            'hasAlreadyReported'   => $hasAlreadyReported,
            'inShortlist'          => $inShortlist,
            'completenessPct'      => $completenessPct,
            'profilePhotoVisible' => $profilePhotoVisible,
            'dateOfBirthVisible'  => $dateOfBirthVisible,
            'maritalStatusVisible' => $maritalStatusVisible,
            'educationVisible'     => $educationVisible,
            'locationVisible'      => $locationVisible,
            'casteVisible'         => $casteVisible,
            'matchData'            => $matchData,
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

        // Exclude suspended and soft-deleted profiles
        $query->where('is_suspended', false);
        // Soft deletes are automatically excluded by Laravel's SoftDeletes trait

        // Day-18: Only use enabled AND searchable fields for search
        $searchableFields = ProfileFieldConfigurationService::getSearchableFieldKeys();
        $enabledFields = ProfileFieldConfigurationService::getEnabledFieldKeys();
        
        // Intersection: fields that are both enabled and searchable
        $enabledSearchableFields = array_intersect($searchableFields, $enabledFields);

        // Helper: check if field is enabled and searchable
        $isSearchable = fn(string $fieldKey) => in_array($fieldKey, $enabledSearchableFields, true);

        // Caste filter (only if searchable)
        if ($isSearchable('caste') && $request->filled('caste')) {
            $query->where('caste', $request->caste);
        }

        // Location filter (only if searchable)
        if ($isSearchable('location') && $request->filled('location')) {
            $query->where('location', $request->location);
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

        // Marital status filter (only if searchable)
        if ($isSearchable('marital_status') && $request->filled('marital_status')) {
            $query->where('marital_status', $request->marital_status);
        }

        // Education filter (only if searchable)
        if ($isSearchable('education') && $request->filled('education')) {
            $query->where('education', $request->education);
        }

        // 70% completeness or admin override (search visibility only)
        $query->whereRaw(ProfileCompletenessService::sqlSearchVisible());

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
        $profiles = $query->paginate($perPage)->withQueryString();

        return view('matrimony.profile.index', compact('profiles'));

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

        // Define comparison fields (preferences to check)
        $preferenceFields = [
            'education' => ['label' => 'शिक्षण', 'icon' => '🎓'],
            'location' => ['label' => 'शहर', 'icon' => '📍'],
            'caste' => ['label' => 'जात', 'icon' => '🗣️'],
            'marital_status' => ['label' => 'वैवाहिक स्थिती', 'icon' => '💑'],
        ];

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
}
