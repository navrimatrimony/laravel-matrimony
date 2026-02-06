<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\MatrimonyProfile;
use App\Models\ProfileFieldLock;

class ProfileFieldLockApiController extends Controller
{
    /**
     * List field locks for authenticated user's profile
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $profile = MatrimonyProfile::where('user_id', $user->id)->first();

        if (!$profile) {
            return response()->json([
                'success' => false,
                'message' => 'Profile not found',
            ], 404);
        }

        $locks = ProfileFieldLock::where('profile_id', $profile->id)->get();

        return response()->json([
            'success' => true,
            'data' => $locks,
        ]);
    }
}
