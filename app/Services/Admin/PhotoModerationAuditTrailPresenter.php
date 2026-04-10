<?php

namespace App\Services\Admin;

use App\Models\ProfilePhoto;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Compact timeline: AI outcome vs admin log entries vs current effective state.
 */
final class PhotoModerationAuditTrailPresenter
{
    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\PhotoModerationLog>  $logsNewestFirst
     * @return list<array{kind: string, text: string, at?: string}>
     */
    public static function timeline(ProfilePhoto $photo, Collection $logsNewestFirst): array
    {
        $scan = PhotoModerationStoredScan::asArray($photo->moderation_scan_json);
        $ai = PhotoModerationListRowPresenter::modelApiLabel($scan);
        $aiDisplay = $ai !== '—' ? strtoupper($ai) : '—';

        $lines = [];
        $lines[] = [
            'kind' => 'ai',
            'text' => 'AI: '.$aiDisplay.' · row '.$photo->approved_status.' · awaiting moderation',
        ];

        foreach ($logsNewestFirst->sortBy('id')->values() as $log) {
            $old = (string) ($log->old_status ?? '—');
            $new = (string) ($log->new_status ?? '—');
            $snippet = Str::limit(trim((string) ($log->reason ?? '')), 72);
            $lines[] = [
                'kind' => 'admin',
                'text' => 'Admin: '.$old.' → '.$new.($snippet !== '' ? ' · '.$snippet : ''),
                'at' => optional($log->created_at)?->format('Y-m-d H:i'),
            ];
        }

        $lines[] = [
            'kind' => 'now',
            'text' => 'Effective now: '.$photo->effectiveApprovedStatus()
                .' · override '.($photo->admin_override_status ?? '—'),
        ];

        return $lines;
    }
}
