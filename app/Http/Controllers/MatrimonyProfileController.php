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

        $user->matrimonyProfile()->create([
            'full_name'     => $request->full_name,
            'gender'        => $user->gender, // system-derived
            'date_of_birth' => $request->date_of_birth,
            'education'     => $request->education,
            'location'      => $request->location,
            'caste'         => $request->caste,
        ]);

        return redirect()
            ->route('matrimony.profile.edit')
            ->with('success', 'Matrimony profile created successfully');
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

        return view('matrimony.profile.edit', [
            // ❗ SSOT: $user->profile ❌
            'profile' => $user->matrimonyProfile
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

        $user->matrimonyProfile->update([
            'full_name'     => $request->full_name,
            'date_of_birth' => $request->date_of_birth,
            'education'     => $request->education,
            'location'      => $request->location,
            'caste'         => $request->caste,
        ]);

        return redirect()
            ->route('matrimony.profile.edit')
            ->with('success', 'Matrimony profile updated successfully');
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
    // Matrimony profile fetch करा
    $profile = MatrimonyProfile::findOrFail($id);

    $viewer = auth()->user();   // सध्या login user
    $isOwnProfile = $viewer && ($viewer->id === $profile->user_id);

    $interestAlreadySent = false;

    if (auth()->check()) {
        $interestAlreadySent = \App\Models\Interest::where(
            'sender_profile_id',
            auth()->user()->matrimonyProfile->id
        )
        ->where('receiver_profile_id', $profile->id)
        ->exists();
    }

    return view(
        'matrimony.show',
        compact('profile', 'isOwnProfile', 'interestAlreadySent')
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

        // Age From
        if ($request->filled('age_from')) {
            $date = now()->subYears($request->age_from)->toDateString();
            $query->where('date_of_birth', '<=', $date);
        }

        // Age To
        if ($request->filled('age_to')) {
            $date = now()->subYears($request->age_to)->toDateString();
            $query->where('date_of_birth', '>=', $date);
        }

        $profiles = $query->get();

        return view('matrimony.index', compact('profiles'));
    }
}
