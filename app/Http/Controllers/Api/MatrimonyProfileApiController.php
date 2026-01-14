<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\MatrimonyProfile;

class MatrimonyProfileApiController extends Controller
{
    /**
     * Store matrimony profile for logged-in user
     * SSOT: User ≠ MatrimonyProfile
     */
    public function store(Request $request)
    {
        $user = $request->user(); // sanctum authenticated user

        $profile = MatrimonyProfile::updateOrCreate(
    ['user_id' => $user->id],
    [
        'full_name'     => $request->full_name,
        'date_of_birth' => $request->date_of_birth,
        'caste'         => $request->caste,
        'education'     => $request->education,
        'location'      => $request->location,
    ]
);


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

    return response()->json([
        'success' => true,
        'profile' => $profile,
    ]);
}
/**
 * Update matrimony profile for logged-in user
 */
public function update(Request $request)
{
    $user = $request->user();

    $profile = MatrimonyProfile::where('user_id', $user->id)->first();

    if (!$profile) {
        return response()->json([
            'success' => false,
            'message' => 'Profile not found',
        ], 404);
    }

    $profile->update([
        'full_name'     => $request->full_name,
        'date_of_birth' => $request->date_of_birth,
        'caste'         => $request->caste,
        'education'     => $request->education,
        'location'      => $request->location,
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Matrimony profile updated',
        'profile' => $profile,
    ]);
}

}
