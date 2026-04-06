<?php

namespace App\Http\Controllers;

use App\Models\ProfileView;
use App\Services\FeatureUsageService;
use App\Services\ViewTrackingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WhoViewedController extends Controller
{
    public function __construct(
        protected FeatureUsageService $featureUsage,
    ) {}

    public function index(Request $request): View|JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->matrimonyProfile) {
            abort(403);
        }

        $profile = $user->matrimonyProfile;
        $days = $this->featureUsage->getWhoViewedMeWindowDays((int) $user->id);
        $teaserUniqueCount = ViewTrackingService::countEligibleDistinctViewersForTeaser((int) $profile->id);
        $plansUrl = route('plans.index');

        if (! $this->featureUsage->canUse((int) $user->id, FeatureUsageService::FEATURE_WHO_VIEWED_ME_ACCESS)) {
            if ($request->wantsJson()) {
                return response()->json([
                    'locked' => true,
                    'message' => __('who_viewed.locked_json_message'),
                    'teaser_unique_count' => $teaserUniqueCount,
                ]);
            }

            return view('who-viewed.index', [
                'profile' => $profile,
                'uniqueCount' => 0,
                'recentViewers' => collect(),
                'since' => null,
                'whoViewedLocked' => true,
                'windowDays' => 0,
                'teaserUniqueCount' => $teaserUniqueCount,
                'plansUrl' => $plansUrl,
            ]);
        }

        $blockedIds = ViewTrackingService::getBlockedProfileIds($profile->id);

        $query = ProfileView::query()
            ->where('viewed_profile_id', $profile->id)
            ->whereNotIn('viewer_profile_id', $blockedIds)
            ->with('viewerProfile.user')
            ->orderByDesc('created_at');

        $since = null;
        if ($days < FeatureUsageService::WHO_VIEWED_UNLIMITED_DAYS_THRESHOLD) {
            $since = now()->subDays($days);
            $query->where('created_at', '>=', $since);
        }

        $rows = $query->get()->filter(function (ProfileView $view) {
            $viewerProfile = $view->viewerProfile;
            $viewerUser = $viewerProfile?->user;
            if (! $viewerUser) {
                return false;
            }
            if ($viewerUser->is_admin ?? false) {
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

        $windowDaysForView = $days >= FeatureUsageService::WHO_VIEWED_UNLIMITED_DAYS_THRESHOLD ? null : $days;

        if ($request->wantsJson()) {
            return response()->json([
                'locked' => false,
                'unique_count' => $uniqueCount,
                'recent' => $this->serializeWhoViewedRecent($recent),
                'window_days' => $windowDaysForView,
                'since' => $since?->toIso8601String(),
                'teaser_unique_count' => $uniqueCount,
            ]);
        }

        return view('who-viewed.index', [
            'profile' => $profile,
            'uniqueCount' => $uniqueCount,
            'recentViewers' => $recent,
            'since' => $since,
            'whoViewedLocked' => false,
            'windowDays' => $windowDaysForView,
            'teaserUniqueCount' => null,
            'plansUrl' => $plansUrl,
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ProfileView>  $recent
     * @return list<array<string, mixed>>
     */
    private function serializeWhoViewedRecent($recent): array
    {
        return $recent->map(function (ProfileView $view) {
            $viewerProfile = $view->viewerProfile;
            $viewerUser = $viewerProfile?->user;

            return [
                'viewer_profile_id' => $view->viewer_profile_id,
                'name' => $viewerProfile->full_name ?? $viewerUser?->name ?? 'Member',
                'profile_url' => $viewerProfile
                    ? route('matrimony.profile.show', $viewerProfile->id)
                    : null,
                'viewed_at' => $view->created_at->toIso8601String(),
                'viewed_at_human' => $view->created_at->diffForHumans(),
            ];
        })->values()->all();
    }
}
