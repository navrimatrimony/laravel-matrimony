<?php

namespace App\Http\Controllers;

use App\Models\MatrimonyProfile;
use App\Models\ProfileView;
use App\Services\ViewTrackingService;
use Illuminate\Http\Request;

class WhoViewedController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->matrimonyProfile) {
            abort(403);
        }

        $profile = $user->matrimonyProfile;
        $retentionDays = 30;
        $since = now()->subDays($retentionDays);

        $blockedIds = ViewTrackingService::getBlockedProfileIds($profile->id);

        $query = ProfileView::query()
            ->where('viewed_profile_id', $profile->id)
            ->where('created_at', '>=', $since)
            ->whereNotIn('viewer_profile_id', $blockedIds)
            ->with('viewerProfile.user')
            ->orderByDesc('created_at');

        $rows = $query->get()->filter(function (ProfileView $view) {
            $viewerProfile = $view->viewerProfile;
            $user = $viewerProfile?->user;
            if (! $user) {
                return false;
            }
            if ($user->is_admin ?? false) {
                return false;
            }
            if ($viewerProfile->is_suspended ?? false) {
                return false;
            }
            return true;
        });

        $uniqueByViewer = $rows->groupBy('viewer_profile_id')->map(function ($group) {
            return $group->sortByDesc('created_at')->first();
        })->sortByDesc('created_at');

        $recentLimit = 10;
        $recent = $uniqueByViewer->take($recentLimit);
        $uniqueCount = $uniqueByViewer->count();

        return view('who-viewed.index', [
            'profile' => $profile,
            'uniqueCount' => $uniqueCount,
            'recentViewers' => $recent,
            'since' => $since,
        ]);
    }
}

