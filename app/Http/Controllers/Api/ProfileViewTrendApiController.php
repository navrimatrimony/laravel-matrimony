<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MatrimonyProfile;
use App\Services\WhoViewed\ProfileViewTrendService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only daily profile-view trend for the member dashboard chart.
 */
class ProfileViewTrendApiController extends Controller
{
    public function __construct(
        private readonly ProfileViewTrendService $trend,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = MatrimonyProfile::where('user_id', $user->id)->first();

        if (! $profile) {
            return response()->json([
                'success' => false,
                'message' => 'Profile not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->trend->dailyTrend($profile, ProfileViewTrendService::DEFAULT_WINDOW_DAYS),
        ]);
    }
}
