<?php

namespace App\Services\WhoViewed;

use App\Models\MatrimonyProfile;
use App\Models\ProfileView;
use App\Models\User;
use App\Services\FeatureUsageService;
use App\Services\ViewTrackingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Whether a profile-view notification may include the viewer's identity (same rules as who-viewed UI).
 */
final class WhoViewedNotificationIdentityGate
{
    /**
     * Opaque token for deduplicating profile-view notifications without exposing viewer_profile_id in JSON.
     */
    public static function viewerDedupeToken(User $ownerUser, int $viewerProfileId): string
    {
        return hash('sha256', (string) $ownerUser->id.'|'.$viewerProfileId.'|'.(string) config('app.key'));
    }

    public static function mayRevealViewerInProfileViewNotification(User $ownerUser, int $viewerProfileId): bool
    {
        if ($viewerProfileId < 1) {
            return false;
        }

        $featureUsage = app(FeatureUsageService::class);
        $uid = (int) $ownerUser->id;

        if (! $featureUsage->canUse($uid, FeatureUsageService::FEATURE_WHO_VIEWED_ME_ACCESS)) {
            return false;
        }

        if ($featureUsage->whoViewedMeHasFullViewerList($ownerUser)) {
            return true;
        }

        $previewLimit = $featureUsage->getWhoViewedMePreviewLimit($uid);
        if ($previewLimit < 1) {
            return false;
        }

        $ownerProfile = $ownerUser->matrimonyProfile;
        if (! $ownerProfile instanceof MatrimonyProfile) {
            return false;
        }

        $since = $featureUsage->whoViewedMePreviewWindow($ownerUser)['since'] ?? null;
        $fullViewerIds = self::fifoFullViewerProfileIds($ownerProfile, $since, $previewLimit);

        return in_array($viewerProfileId, $fullViewerIds, true);
    }

    /**
     * First N distinct viewers by first view in window (matches {@see WhoViewedController::uniqueViewerViewsForProfile} FIFO mode).
     *
     * @return list<int>
     */
    private static function fifoFullViewerProfileIds(MatrimonyProfile $profile, ?Carbon $since, int $previewLimit): array
    {
        $blockedIds = ViewTrackingService::getBlockedProfileIds($profile->id);

        $query = ProfileView::query()
            ->where('viewed_profile_id', $profile->id)
            ->whereNotIn('viewer_profile_id', $blockedIds)
            ->with('viewerProfile.user')
            ->orderByDesc('created_at');

        if ($since !== null) {
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

        $perViewer = $rows->groupBy('viewer_profile_id')->map(function (Collection $group) {
            $latest = $group->sortByDesc('created_at')->first();
            $firstAt = $group->min('created_at');
            $firstAt = $firstAt instanceof Carbon ? $firstAt : Carbon::parse((string) $firstAt);

            return [
                'view' => $latest,
                'first_at' => $firstAt,
            ];
        });

        $byFirst = $perViewer->sortBy(fn (array $meta) => [$meta['first_at']->timestamp, (int) $meta['view']->viewer_profile_id])->values();

        return $byFirst->take($previewLimit)
            ->map(fn (array $meta) => (int) $meta['view']->viewer_profile_id)
            ->values()
            ->all();
    }
}
