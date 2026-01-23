<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\MatrimonyProfile;


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
    
        // Profile नाही → create form
        return view('matrimony.profile.create');
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
    $user = auth()->user();

    // Policy: Check manual activation requirement
    $manualActivationRequired = \App\Services\AdminSettingService::isManualProfileActivationRequired();
    $isSuspended = $manualActivationRequired ? true : false;

    MatrimonyProfile::updateOrCreate(
    ['user_id' => $user->id],
    [
        'full_name'     => $request->full_name,
        'gender'        => $user->gender, // system-derived
        'date_of_birth' => $request->date_of_birth,
        'education'     => $request->education,
        'location'      => $request->location,
        'caste'         => $request->caste,
        'is_suspended'  => $isSuspended,
    ]
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

    // ✅ Profile exists → edit page
    return view('matrimony.profile.edit', [
        'matrimonyProfile' => $user->matrimonyProfile
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

    // Prepare update data
    $updateData = [
        'full_name'     => $request->full_name,
        'date_of_birth' => $request->date_of_birth,
        'education'     => $request->education,
        'location'      => $request->location,
        'caste'         => $request->caste,
        'profile_photo' => $photoPath,
    ];

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

    return view(
        'matrimony.profile.show',
        [
            'matrimonyProfile'     => $matrimonyProfile,
            'isOwnProfile'         => $isOwnProfile,
            'interestAlreadySent'  => $interestAlreadySent,
            'hasAlreadyReported'   => $hasAlreadyReported,
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

        return view('matrimony.profile.index', compact('profiles'));

    }
}
