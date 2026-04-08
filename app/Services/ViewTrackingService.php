<?php

namespace App\Services;

use App\Jobs\ProcessDelayedViewBack;
use App\Models\Block;
use App\Models\MatrimonyProfile;
use App\Models\ProfileView;
use App\Models\User;
use App\Notifications\ProfileViewedNotification;
use App\Services\AdminActivityNotificationGate;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| ViewTrackingService (SSOT Day-9 — Recovery-Day-R4)
|--------------------------------------------------------------------------
|
| Records profile views, triggers demo→real view-back when eligible.
| Respects enable, probability, 24h cap, delay min/max, no recursion.
|
*/
class ViewTrackingService
{
    /** Prevent repeat "profile viewed" notifications from same viewer within this window. */
    private const PROFILE_VIEW_NOTIFY_DEDUP_MINUTES = 10;

    /**
     * Record a profile view (viewer → viewed) and notify viewed's user.
     * Skip if viewer = viewed (self) or blocked. No notification for self.
     *
     * When this returns true, the caller must call {@see consumeDailyProfileViewUsageForViewer}
     * (or equivalent {@see FeatureUsageService::consume}) so quota stays aligned with {@code profile_views}.
     */
    public static function recordView(MatrimonyProfile $viewer, MatrimonyProfile $viewed): bool
    {
        if ($viewer->id === $viewed->id) {
            return false;
        }
        if (self::isBlocked($viewer->id, $viewed->id)) {
            return false;
        }

        ProfileView::create([
            'viewer_profile_id' => $viewer->id,
            'viewed_profile_id' => $viewed->id,
        ]);

        self::notifyProfileViewIfEligible($viewed->user, $viewer, false);

        return true;
    }

    /**
     * When real user views demo profile: maybe trigger view-back (demo views real).
     * Respects view_back_enabled, view_back_probability, delay min/max, 24h cap. No recursion.
     */
    public static function maybeTriggerViewBack(MatrimonyProfile $viewer, MatrimonyProfile $viewed): void
    {
        if ($viewer->id === $viewed->id) {
            return;
        }
        if (self::isBlocked($viewer->id, $viewed->id)) {
            return;
        }
        if (!($viewed->is_demo ?? false) || ($viewer->is_demo ?? false)) {
            return;
        }

        $enabled = \App\Models\AdminSetting::getBool('view_back_enabled', false);
        if (!$enabled) {
            return;
        }

        $pct = (int) \App\Models\AdminSetting::getValue('view_back_probability', '0');
        $pct = max(0, min(100, $pct));
        if ($pct === 0) {
            return;
        }
        if (random_int(1, 100) > $pct) {
            return;
        }

        $demoId = $viewed->id;
        $realId = $viewer->id;
        $since = now()->subDay();
        $exists = ProfileView::where('viewer_profile_id', $demoId)
            ->where('viewed_profile_id', $realId)
            ->where('created_at', '>=', $since)
            ->exists();
        if ($exists) {
            return;
        }

        // Get delay settings (minutes)
        $delayMin = (int) \App\Models\AdminSetting::getValue('view_back_delay_min', '0');
        $delayMax = (int) \App\Models\AdminSetting::getValue('view_back_delay_max', '0');
        $delayMin = max(0, $delayMin);
        $delayMax = max($delayMin, $delayMax);

        // Calculate random delay in minutes
        $delayMinutes = ($delayMin === $delayMax) ? $delayMin : random_int($delayMin, $delayMax);

        if ($delayMinutes > 0) {
            // Dispatch delayed job
            ProcessDelayedViewBack::dispatch($demoId, $realId)
                ->delay(now()->addMinutes($delayMinutes));
        } else {
            // Instant view-back (backward compatible)
            self::createViewBackNow($viewed, $viewer);
        }
    }

    /**
     * Create view-back immediately (no delay).
     */
    private static function createViewBackNow(MatrimonyProfile $demoProfile, MatrimonyProfile $realProfile): void
    {
        ProfileView::create([
            'viewer_profile_id' => $demoProfile->id,
            'viewed_profile_id' => $realProfile->id,
        ]);

        self::consumeDailyProfileViewUsageForViewer($demoProfile);

        self::notifyProfileViewIfEligible($realProfile->user, $demoProfile, true);
    }

    /**
     * After a {@link ProfileView} row is inserted for {@code viewer_profile_id}, increment
     * {@code user_feature_usages} for {@see FeatureUsageService::FEATURE_DAILY_PROFILE_VIEW_LIMIT} (real users only).
     * Skips demo profiles; quota increments via {@see FeatureUsageService::consume} (respects admin bypass mode).
     */
    public static function consumeDailyProfileViewUsageForViewer(MatrimonyProfile $viewer): void
    {
        $user = $viewer->user;
        if (! $user) {
            return;
        }
        if ($viewer->is_demo ?? false) {
            return;
        }

        app(FeatureUsageService::class)->consume(
            (int) $user->id,
            FeatureUsageService::FEATURE_DAILY_PROFILE_VIEW_LIMIT
        );
    }

    /**
     * Send profile-view notification only when not recently sent by same viewer.
     */
    public static function notifyProfileViewIfEligible(?User $owner, MatrimonyProfile $viewerProfile, bool $isViewBack): void
    {
        if (! $owner) {
            return;
        }
        $viewerProfile->loadMissing('user');
        if (! AdminActivityNotificationGate::allowsPeerActivityNotification($viewerProfile->user)) {
            return;
        }
        if (! self::shouldSendProfileViewNotification($owner, (int) $viewerProfile->id)) {
            return;
        }

        $owner->notify(new ProfileViewedNotification($viewerProfile, $isViewBack));
    }

    private static function shouldSendProfileViewNotification(User $owner, int $viewerProfileId): bool
    {
        $since = now()->subMinutes(self::PROFILE_VIEW_NOTIFY_DEDUP_MINUTES);
        $recent = $owner->notifications()
            ->where('type', ProfileViewedNotification::class)
            ->where('created_at', '>=', $since)
            ->get(['data']);

        foreach ($recent as $notification) {
            $data = is_array($notification->data) ? $notification->data : [];
            if (($data['type'] ?? null) !== 'profile_viewed') {
                continue;
            }
            if ((int) ($data['viewer_profile_id'] ?? 0) === $viewerProfileId) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if two profiles have blocked each other (bidirectional).
     * Single source for block-check logic.
     */
    public static function isBlocked(int $profileA, int $profileB): bool
    {
        return Block::where(function ($q) use ($profileA, $profileB) {
            $q->where('blocker_profile_id', $profileA)->where('blocked_profile_id', $profileB);
        })->orWhere(function ($q) use ($profileA, $profileB) {
            $q->where('blocker_profile_id', $profileB)->where('blocked_profile_id', $profileA);
        })->exists();
    }

    /**
     * Get all profile IDs blocked by or blocking the given profile (bidirectional).
     * Used for search exclusion. Single source for blocked-IDs retrieval.
     *
     * @return \Illuminate\Support\Collection<int>
     */
    public static function getBlockedProfileIds(int $profileId): \Illuminate\Support\Collection
    {
        return Block::where('blocker_profile_id', $profileId)->pluck('blocked_profile_id')
            ->merge(Block::where('blocked_profile_id', $profileId)->pluck('blocker_profile_id'))
            ->unique()
            ->values();
    }

    /**
     * Distinct eligible viewers for monetization teaser (blocked excluded; mirrors who-viewed list filters).
     */
    public static function countEligibleDistinctViewersForTeaser(int $viewedProfileId): int
    {
        $blocked = self::getBlockedProfileIds($viewedProfileId);
        $vpTable = (new MatrimonyProfile)->getTable();
        $uTable = (new User)->getTable();

        $q = DB::table('profile_views')
            ->join("{$vpTable} as vp", 'vp.id', '=', 'profile_views.viewer_profile_id')
            ->join("{$uTable} as u", 'u.id', '=', 'vp.user_id')
            ->where('profile_views.viewed_profile_id', $viewedProfileId)
            ->where(function ($q) {
                $q->whereNull('u.is_admin')->orWhere('u.is_admin', false);
            })
            ->where(function ($q) {
                $q->whereNull('vp.is_suspended')->orWhere('vp.is_suspended', false);
            });

        if ($blocked->isNotEmpty()) {
            $q->whereNotIn('profile_views.viewer_profile_id', $blocked->all());
        }

        return (int) $q->selectRaw('count(distinct profile_views.viewer_profile_id) as c')->value('c');
    }
}
