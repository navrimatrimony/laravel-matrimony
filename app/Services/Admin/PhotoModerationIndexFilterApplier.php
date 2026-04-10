<?php

namespace App\Services\Admin;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Applies GET filter params on the photo moderation engine list query.
 */
final class PhotoModerationIndexFilterApplier
{
    public static function apply(Builder $query, Request $request): void
    {
        if (! $request->boolean('include_approved')) {
            $query->whereNotEffectivelyApproved();
        }

        if ($request->boolean('flagged_users') && Schema::hasTable('user_moderation_stats')) {
            $query->whereHas('profile.user.moderationStat', function ($q): void {
                $q->where('is_flagged', true);
            });
        }

        $eff = (string) $request->input('eff_status', '');
        if (in_array($eff, ['approved', 'pending', 'rejected'], true)) {
            $query->whereEffectiveOutcome($eff);
        }

        $ai = strtolower((string) $request->input('ai_result', ''));
        if (in_array($ai, ['safe', 'review', 'unsafe'], true)) {
            $query->where(function ($q) use ($ai): void {
                $q->where('moderation_scan_json->api_status', $ai)
                    ->orWhere('moderation_scan_json->status', $ai);
            });
        }

        $risk = strtolower((string) $request->input('risk_band', ''));
        if ($risk === 'high') {
            $query->where(function ($q): void {
                $q->where('moderation_scan_json->api_status', 'unsafe')
                    ->orWhere('moderation_scan_json->status', 'unsafe');
            });
        } elseif ($risk === 'medium') {
            $query->where('moderation_scan_json->api_status', 'review');
        } elseif ($risk === 'low') {
            $query->where('moderation_scan_json->api_status', 'safe');
        }

        $preset = (string) $request->input('date_preset', '');
        $col = 'profile_photos.updated_at';
        match ($preset) {
            'today' => $query->where($col, '>=', now()->startOfDay()),
            'month' => $query->where($col, '>=', now()->subMonth()->startOfDay()),
            'year' => $query->where($col, '>=', now()->subYear()->startOfDay()),
            default => null,
        };
        if ($preset === 'custom') {
            $from = $request->input('date_from');
            $to = $request->input('date_to');
            if ($from) {
                $query->whereDate($col, '>=', $from);
            }
            if ($to) {
                $query->whereDate($col, '<=', $to);
            }
        }

        if ($request->boolean('new_only')) {
            $query->where('profile_photos.created_at', '>=', now()->subDays(7));
        } elseif ($request->boolean('old_only')) {
            $query->where('profile_photos.created_at', '<', now()->subDays(30));
        }
    }
}
