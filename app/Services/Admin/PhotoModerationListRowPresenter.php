<?php

namespace App\Services\Admin;

use App\Models\ProfilePhoto;

/**
 * List-row copy for “who decided” (automation vs admin) vs model output.
 */
final class PhotoModerationListRowPresenter
{
    /**
     * NudeNet / stored scan api_status (safe | review | unsafe), or em dash.
     */
    public static function modelApiLabel(?array $scan): string
    {
        $s = PhotoModerationStoredScan::apiStatus($scan);

        return $s ?? '—';
    }

    /**
     * Admin used the moderation engine on this row (override metadata set).
     */
    public static function adminHasTouched(ProfilePhoto $photo): bool
    {
        return $photo->admin_override_by !== null && (int) $photo->admin_override_by > 0;
    }

    /**
     * Short label: Auto = only pipeline/upload; Admin = at least one engine decision recorded.
     */
    public static function sourceLabel(ProfilePhoto $photo): string
    {
        return self::adminHasTouched($photo) ? 'Admin' : 'Auto';
    }

    /**
     * One-line hint for tooltip / screen readers.
     */
    public static function sourceTitle(ProfilePhoto $photo, ?array $scan): string
    {
        $ai = self::modelApiLabel($scan);
        $row = (string) ($photo->approved_status ?? 'pending');
        $ov = $photo->admin_override_status;
        $ovStr = ($ov !== null && $ov !== '') ? (string) $ov : '—';
        $out = $photo->effectiveApprovedStatus();

        if (self::adminHasTouched($photo)) {
            return "AI scan: {$ai}. Gallery row: {$row}. Admin override: {$ovStr}. Effective (public): {$out}.";
        }

        return "AI scan: {$ai}. No admin override on file. Gallery row: {$row}. Effective: {$out}.";
    }
}
