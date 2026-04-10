<?php

namespace App\Services\Admin;

use App\Models\ProfilePhoto;
use Illuminate\Support\Facades\Schema;

/**
 * Placeholder hook for future alerting when many unsafe-classified uploads arrive in a short window.
 */
class ModerationBurstAlertService
{
    public function countRecentUnsafeScans(int $hours = 24): int
    {
        if (! Schema::hasTable('profile_photos') || ! Schema::hasColumn('profile_photos', 'moderation_scan_json')) {
            return 0;
        }

        return ProfilePhoto::query()
            ->where('created_at', '>=', now()->subHours($hours))
            ->where(function ($q): void {
                $q->where('moderation_scan_json->api_status', 'unsafe')
                    ->orWhere('moderation_scan_json->status', 'unsafe');
            })
            ->count();
    }

    public function shouldShowAdminBanner(): bool
    {
        $threshold = (int) config('moderation.unsafe_burst_threshold', 99999);

        return $threshold > 0 && $threshold < PHP_INT_MAX && $this->countRecentUnsafeScans() >= $threshold;
    }
}
