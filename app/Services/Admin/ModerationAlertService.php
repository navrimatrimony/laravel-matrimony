<?php

namespace App\Services\Admin;

use App\Models\ProfilePhoto;
use Illuminate\Support\Facades\Schema;

class ModerationAlertService
{
    private const UNSAFE_BURST_THRESHOLD = 10;

    private const UNSAFE_BURST_WINDOW_HOURS = 1;

    /**
     * Photos created in the last hour whose moderation scan JSON is classified unsafe (api_status).
     */
    public function unsafeUploadBurstLastHourCount(): int
    {
        if (! Schema::hasTable('profile_photos') || ! Schema::hasColumn('profile_photos', 'moderation_scan_json')) {
            return 0;
        }

        $since = now()->subHours(self::UNSAFE_BURST_WINDOW_HOURS);

        return ProfilePhoto::query()
            ->where('created_at', '>=', $since)
            ->get(['id', 'moderation_scan_json'])
            ->filter(fn (ProfilePhoto $p) => PhotoModerationAdminService::moderationScanIndicatesUnsafe(
                is_array($p->moderation_scan_json) ? $p->moderation_scan_json : null
            ))
            ->count();
    }

    public function shouldShowUnsafeBurstAdminBanner(): bool
    {
        return $this->unsafeUploadBurstLastHourCount() > self::UNSAFE_BURST_THRESHOLD;
    }

    public function moderationListHighRiskMessage(int $flaggedUsersOnPage): ?string
    {
        if ($flaggedUsersOnPage <= 0) {
            return null;
        }

        return $flaggedUsersOnPage === 1
            ? 'This page includes a flagged user (high moderation risk). Review counts and risk score.'
            : "This page includes {$flaggedUsersOnPage} flagged users (high moderation risk). Review counts and risk score.";
    }
}
