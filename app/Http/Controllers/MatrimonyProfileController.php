<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class MatrimonyProfileController extends Controller
{
    
public function create()
{
    return view('matrimony.profile.create');
}



public function store(Request $request)
{
    $user = auth()->user();

    $user->profile()->create([
        'full_name'      => $request->full_name,
        'gender'         => $user->gender,
        'date_of_birth'  => $request->date_of_birth,
        'education'      => $request->education,
        'location'       => $request->location,
		'caste' 		 => $request->caste,

    ]);

    return redirect()
    ->route('matrimony.profile.edit')
    ->with('success', 'Profile created successfully');

}
public function edit()
{
    $user = auth()->user();

    return view('matrimony.profile.edit', [
        'profile' => $user->profile
    ]);
}
public function update(Request $request)
{
    $user = auth()->user();

    $user->profile->update([
        'full_name'      => $request->full_name,
        'date_of_birth'  => $request->date_of_birth,
        'education'      => $request->education,
        'location'       => $request->location,
		'caste'          => $request->caste,
    ]);

    return redirect()
    ->route('matrimony.profile.edit')
    ->with('success', 'Profile updated successfully');

}
public function show($id)
{
    $profile = \App\Models\Profile::findOrFail($id);

    $viewer = auth()->user();   // सध्या login user
    $isOwnProfile = $viewer && ($viewer->id === $profile->user_id);

    $interestAlreadySent = false;

if (auth()->check()) {
    $interestAlreadySent = \App\Models\Interest::where('sender_id', auth()->id())
        ->where('receiver_id', $profile->user_id)
        ->exists();
}

return view(
    'matrimony.show',
    compact('profile', 'isOwnProfile', 'interestAlreadySent')
);

}

public function index(\Illuminate\Http\Request $request)
{
    $query = \App\Models\Profile::latest();

    // Caste search
    if ($request->filled('caste')) {
        $query->where('caste', $request->caste);
    }

    // Location search
    if ($request->filled('location')) {
        $query->where('location', $request->location);
    }

    // Age From (DOB <= calculated date)
    if ($request->filled('age_from')) {
        $date = now()->subYears($request->age_from)->toDateString();
        $query->where('date_of_birth', '<=', $date);
    }

    // Age To (DOB >= calculated date)
    if ($request->filled('age_to')) {
        $date = now()->subYears($request->age_to)->toDateString();
        $query->where('date_of_birth', '>=', $date);
    }

    $profiles = $query->get();

    return view('matrimony.index', compact('profiles'));
}






}
