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
   
    |
    */
    /*------------------------------------------------------------------------

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

    $user->matrimonyProfile()->create([
        'full_name'     => $request->full_name,
        'gender'        => $user->gender, // system-derived
        'date_of_birth' => $request->date_of_birth,
        'education'     => $request->education,
        'location'      => $request->location,
        'caste'         => $request->caste,
    ]);

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

    if ($request->hasFile('profile_photo')) {
        $photoPath = $request->file('profile_photo')
            ->store('matrimony_photos', 'public');
    }

    $user->matrimonyProfile->update([
        'full_name'     => $request->full_name,
        'date_of_birth' => $request->date_of_birth,
        'education'     => $request->education,
        'location'      => $request->location,
        'caste'         => $request->caste,
        'profile_photo' => $photoPath, // 🔴 THIS WAS MISSING
    ]);

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

    $photoPath = $request->file('profile_photo')
        ->store('matrimony_photos', 'public');

    $user->matrimonyProfile->update([
        'profile_photo' => $photoPath,
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
 

public function show($id)
{
    $authUser = auth()->user();

if (!$authUser->matrimonyProfile) {
    return redirect()
        ->route('matrimony.profile.create')
        ->with('error', 'Please create your matrimony profile first.');
}

    // Matrimony profile fetch करा
    $matrimonyProfile = MatrimonyProfile::findOrFail($id);


    $viewer = auth()->user();   // सध्या login user
    $isOwnProfile = $viewer && ($viewer->id === $matrimonyProfile->user_id);


    $interestAlreadySent = false;

    if (auth()->check()) {
        $interestAlreadySent = \App\Models\Interest::where(
            'sender_profile_id',
            auth()->user()->matrimonyProfile->id
        )
        ->where('receiver_profile_id', $matrimonyProfile->id)

        ->exists();
    }

    return view(
        'matrimony.profile.show',
        [
            'matrimonyProfile'     => $matrimonyProfile,
            'isOwnProfile'         => $isOwnProfile,
            'interestAlreadySent'  => $interestAlreadySent,
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

        // Caste filter
        if ($request->filled('caste')) {
            $query->where('caste', $request->caste);
        }

        // Location filter
        if ($request->filled('location')) {
            $query->where('location', $request->location);
        }

        

        $profiles = $query->get();

        return view('matrimony.profile.index', compact('profiles'));

    }
}
