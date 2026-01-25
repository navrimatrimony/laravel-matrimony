<?php

namespace App\Http\Controllers;

use App\Models\Interest;
use App\Models\MatrimonyProfile;
use App\Services\ProfileCompletenessService;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| DashboardController
|--------------------------------------------------------------------------
|
| User dashboard data preparation.
| All queries moved from Blade view to controller (SSOT refactor).
|
*/

class DashboardController extends Controller
{
    /**
     * Show user dashboard.
     */
    public function index()
    {
        $user = auth()->user();

        // No profile case - view handles this with empty data
        if (!$user->matrimonyProfile) {
            return view('dashboard', [
                'hasProfile' => false,
            ]);
        }

        $profile = $user->matrimonyProfile;
        $myProfileId = $profile->id;

        // Statistics
        $sentInterestsCount = Interest::where('sender_profile_id', $myProfileId)->count();
        $receivedPendingCount = Interest::where('receiver_profile_id', $myProfileId)
            ->where('status', 'pending')
            ->count();
        $acceptedInterestsCount = Interest::where('receiver_profile_id', $myProfileId)
            ->where('status', 'accepted')
            ->count();
        $rejectedInterestsCount = Interest::where('receiver_profile_id', $myProfileId)
            ->where('status', 'rejected')
            ->count();
        $totalProfilesCount = MatrimonyProfile::where('id', '!=', $myProfileId)->count();

        // Profile Completeness Calculation (from service)
        $completenessPercentage = ProfileCompletenessService::percentage($profile);

        // Recent Interests (Last 3 received)
        $recentReceivedInterests = Interest::with('senderProfile')
            ->where('receiver_profile_id', $myProfileId)
            ->latest()
            ->limit(3)
            ->get();

        // Recent Sent Interests (Last 3)
        $recentSentInterests = Interest::with('receiverProfile')
            ->where('sender_profile_id', $myProfileId)
            ->latest()
            ->limit(3)
            ->get();

        return view('dashboard', [
            'hasProfile' => true,
            'profile' => $profile,
            'myProfileId' => $myProfileId,
            'sentInterestsCount' => $sentInterestsCount,
            'receivedPendingCount' => $receivedPendingCount,
            'acceptedInterestsCount' => $acceptedInterestsCount,
            'rejectedInterestsCount' => $rejectedInterestsCount,
            'totalProfilesCount' => $totalProfilesCount,
            'completenessPercentage' => $completenessPercentage,
            'recentReceivedInterests' => $recentReceivedInterests,
            'recentSentInterests' => $recentSentInterests,
        ]);
    }
}
