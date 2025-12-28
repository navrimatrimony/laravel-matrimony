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
    ]);

    return redirect()
    ->route('matrimony.profile.edit')
    ->with('success', 'Profile updated successfully');

}
public function show($id)
{
    $profile = \App\Models\Profile::findOrFail($id);

    return view('matrimony.show', compact('profile'));
}
public function index()
{
    $profiles = \App\Models\Profile::latest()->get();

    return view('matrimony.index', compact('profiles'));
}


}
