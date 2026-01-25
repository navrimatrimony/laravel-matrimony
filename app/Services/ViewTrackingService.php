<?php

namespace App\Services;

use App\Jobs\ProcessDelayedViewBack;
use App\Models\Block;
use App\Models\MatrimonyProfile;
use App\Models\ProfileView;
use App\Notifications\ProfileViewedNotification;

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
    /**
     * Record a profile view (viewer → viewed) and notify viewed's user.
     * Skip if viewer = viewed (self) or blocked. No notification for self.
     */
    public static function recordView(MatrimonyProfile $viewer, MatrimonyProfile $viewed): void
    {
        if ($viewer->id === $viewed->id) {
            return;
        }
        if (self::isBlocked($viewer->id, $viewed->id)) {
            return;
        }

        ProfileView::create([
            'viewer_profile_id' => $viewer->id,
            'viewed_profile_id' => $viewed->id,
        ]);

        $owner = $viewed->user;
        if ($owner) {
            $owner->notify(new ProfileViewedNotification($viewer, false));
        }
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

        $realOwner = $realProfile->user;
        if ($realOwner) {
            $realOwner->notify(new ProfileViewedNotification($demoProfile, true));
        }
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
}
