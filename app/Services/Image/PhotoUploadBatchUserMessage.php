<?php

namespace App\Services\Image;

use App\Services\Admin\AdminSettingService;
use App\Services\Admin\PhotoModerationStoredScan;

/**
 * Short user-facing upload flash copy + tone (green = ok, red = pending/rejected).
 */
final class PhotoUploadBatchUserMessage
{
    /**
     * @param  list<array{approved_status: string, moderation_scan_json: mixed}>  $metas
     * @return array{message: string, tone: 'success'|'danger'}
     */
    public static function forUploadResponse(int $uploadedCount, array $metas): array
    {
        if ($uploadedCount < 1) {
            $uploadedCount = max(1, count($metas));
        }

        if ($metas === []) {
            return [
                'message' => __('photo.upload_line_pending_generic'),
                'tone' => 'danger',
            ];
        }

        $approved = 0;
        $rejected = 0;
        $pendingKinds = [
            'sensitive' => 0,
            'review' => 0,
            'admin_queue' => 0,
            'unknown' => 0,
        ];

        foreach ($metas as $meta) {
            $st = (string) ($meta['approved_status'] ?? 'pending');
            if ($st === 'approved') {
                $approved++;
            } elseif ($st === 'rejected') {
                $rejected++;
            } else {
                $kind = self::classifyPending($meta);
                $pendingKinds[$kind] = ($pendingKinds[$kind] ?? 0) + 1;
            }
        }

        $pendingTotal = array_sum($pendingKinds);
        $tone = ($pendingTotal > 0 || $rejected > 0) ? 'danger' : 'success';

        $line = self::oneLineStatus($uploadedCount, $approved, $rejected, $pendingKinds, $pendingTotal);

        return ['message' => $line, 'tone' => $tone];
    }

    /**
     * @param  array<string, int>  $pendingKinds
     */
    private static function oneLineStatus(
        int $uploadedCount,
        int $approved,
        int $rejected,
        array $pendingKinds,
        int $pendingTotal,
    ): string {
        $s = (int) ($pendingKinds['sensitive'] ?? 0);
        $r = (int) ($pendingKinds['review'] ?? 0);
        $a = (int) ($pendingKinds['admin_queue'] ?? 0);
        $u = (int) ($pendingKinds['unknown'] ?? 0);

        $prefix = $uploadedCount > 1
            ? trans_choice('photo.upload_prefix_count', $uploadedCount, ['count' => $uploadedCount])
            : '';

        $core = '';
        if ($rejected > 0 && $pendingTotal === 0 && $approved === 0 && $rejected === $uploadedCount) {
            $core = trans_choice('photo.upload_line_rejected_only', $rejected);
        } elseif ($s > 0) {
            $core = trans_choice('photo.upload_line_sensitive', $s);
        } elseif ($rejected > 0) {
            $core = trans_choice('photo.upload_line_rejected_mixed', $rejected);
        } elseif ($r > 0) {
            $core = trans_choice('photo.upload_line_review', $r);
        } elseif ($a > 0) {
            $core = trans_choice('photo.upload_line_admin', $a);
        } elseif ($u > 0) {
            $core = trans_choice('photo.upload_line_unknown', $u);
        } elseif ($pendingTotal > 0) {
            $core = __('photo.upload_line_pending_generic');
        } else {
            $core = trans_choice('photo.upload_line_all_ok', $uploadedCount);
        }

        if ($prefix !== '' && $core !== '') {
            return trim($prefix).' '.$core;
        }

        return $core;
    }

    /**
     * @param  array{approved_status: string, moderation_scan_json: mixed}  $meta
     */
    private static function classifyPending(array $meta): string
    {
        $scan = PhotoModerationStoredScan::asArray($meta['moderation_scan_json'] ?? null);
        if ($scan === null) {
            return 'unknown';
        }

        if (($scan['pipeline_safe'] ?? null) === false) {
            return 'sensitive';
        }

        $api = PhotoModerationStoredScan::apiStatus($scan);
        if (in_array($api, ['unsafe', 'flagged'], true)) {
            return 'sensitive';
        }
        if ($api === 'review') {
            return 'review';
        }
        if ($api === 'safe') {
            return AdminSettingService::isPhotoApprovalRequired() ? 'admin_queue' : 'unknown';
        }
        if (($scan['pipeline_safe'] ?? null) === true) {
            return AdminSettingService::isPhotoApprovalRequired() ? 'admin_queue' : 'unknown';
        }

        return 'unknown';
    }
}
