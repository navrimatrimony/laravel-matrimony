<?php

namespace App\Http\Controllers;

use App\Models\Interest;
use App\Models\MatrimonyProfile;
use App\Models\Shortlist;
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

        $profile = $user->matrimonyProfile->load(['gender', 'city', 'state']);
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
        $shortlistCount = Shortlist::where('owner_profile_id', $myProfileId)->count();
        $mobileVerified = (bool) $user->mobile_verified_at;

        // Profile Completeness Calculation (from service)
        $completenessPercentage = ProfileCompletenessService::percentage($profile);

        // Recent Interests (Last 3 received)
        $recentReceivedInterests = Interest::with('senderProfile.gender')
            ->where('receiver_profile_id', $myProfileId)
            ->latest()
            ->limit(3)
            ->get();

        // Recent Sent Interests (Last 3)
        $recentSentInterests = Interest::with('receiverProfile.gender')
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
            'shortlistCount' => $shortlistCount,
            'mobileVerified' => $mobileVerified,
            'completenessPercentage' => $completenessPercentage,
            'recentReceivedInterests' => $recentReceivedInterests,
            'recentSentInterests' => $recentSentInterests,
        ]);
    }
}
