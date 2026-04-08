<?php

namespace App\Services;

use App\Models\ContactRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Optional "Discover" ordering for profile search: reduce repeats, stable pagination.
 */
class ProfileRotationService
{
    public static function discoverRequested(Request $request): bool
    {
        return (string) $request->input('sort') === 'discover';
    }

    public static function isEnabled(): bool
    {
        return (bool) config('profile_rotation.enabled', true);
    }

    /**
     * Stable seed for the current session so page 2+ matches page 1 ordering.
     * New browser session ⇒ new shuffle (more variety than a once-per-day seed).
     */
    public static function stableSeedForSession(): string
    {
        $key = 'profile_discover_order_seed';
        if (! session()->has($key)) {
            session()->put($key, bin2hex(random_bytes(16)));
        }

        return (string) session($key);
    }

    /**
     * Exclusions for discover: self, recently viewed, interest pair, chat pair, contact requests.
     */
    public static function applyDiscoverScope(Builder $query, int $viewerProfileId, ?int $viewerUserId): void
    {
        $query->where('matrimony_profiles.id', '!=', $viewerProfileId);

        $suppressHours = (int) config('profile_rotation.recent_view_suppress_hours', 72);
        $legacyDays = (int) config('profile_rotation.view_cooldown_days', 0);
        if ($suppressHours <= 0 && $legacyDays > 0) {
            $suppressHours = $legacyDays * 24;
        }
        if ($suppressHours > 0) {
            $cutoff = now()->subHours($suppressHours);
            $query->whereNotExists(function ($sub) use ($viewerProfileId, $cutoff) {
                $sub->selectRaw('1')
                    ->from('profile_views as pv')
                    ->whereColumn('pv.viewed_profile_id', 'matrimony_profiles.id')
                    ->where('pv.viewer_profile_id', $viewerProfileId)
                    ->where('pv.created_at', '>=', $cutoff);
            });
        }

        $query->whereNotExists(function ($sub) use ($viewerProfileId) {
            $sub->selectRaw('1')
                ->from('interests as i')
                ->where(function ($q) use ($viewerProfileId) {
                    $q->where(function ($q2) use ($viewerProfileId) {
                        $q2->where('i.sender_profile_id', $viewerProfileId)
                            ->whereColumn('i.receiver_profile_id', 'matrimony_profiles.id');
                    })->orWhere(function ($q2) use ($viewerProfileId) {
                        $q2->where('i.receiver_profile_id', $viewerProfileId)
                            ->whereColumn('i.sender_profile_id', 'matrimony_profiles.id');
                    });
                });
        });

        $query->whereNotExists(function ($sub) use ($viewerProfileId) {
            $sub->selectRaw('1')
                ->from('conversations as c')
                ->where(function ($q) use ($viewerProfileId) {
                    $q->where(function ($q2) use ($viewerProfileId) {
                        $q2->where('c.profile_one_id', $viewerProfileId)
                            ->whereColumn('c.profile_two_id', 'matrimony_profiles.id');
                    })->orWhere(function ($q2) use ($viewerProfileId) {
                        $q2->where('c.profile_two_id', $viewerProfileId)
                            ->whereColumn('c.profile_one_id', 'matrimony_profiles.id');
                    });
                });
        });

        if ($viewerUserId) {
            $query->whereNotExists(function ($sub) use ($viewerUserId) {
                $sub->selectRaw('1')
                    ->from('contact_requests as cr')
                    ->where('cr.type', ContactRequest::TYPE_CONTACT)
                    ->whereNotNull('matrimony_profiles.user_id')
                    ->where(function ($q) use ($viewerUserId) {
                        $q->where(function ($q2) use ($viewerUserId) {
                            $q2->where('cr.sender_id', $viewerUserId)
                                ->whereColumn('cr.receiver_id', 'matrimony_profiles.user_id');
                        })->orWhere(function ($q2) use ($viewerUserId) {
                            $q2->where('cr.receiver_id', $viewerUserId)
                                ->whereColumn('cr.sender_id', 'matrimony_profiles.user_id');
                        });
                    });
            });
        }
    }

    /**
     * Freshness: profiles never recorded in profile_views for this viewer first, then older re-surfaces.
     * Tie-break: deterministic MD5/hex order from stable seed (stable pagination).
     */
    public static function applyDiscoverOrdering(Builder $query, int $viewerProfileId, string $seed): void
    {
        $query->select('matrimony_profiles.*');

        $sub = DB::table('profile_views')
            ->select('viewed_profile_id', DB::raw('MAX(created_at) as last_viewed_at'))
            ->where('viewer_profile_id', $viewerProfileId)
            ->groupBy('viewed_profile_id');

        $query->leftJoinSub($sub, 'pv_agg', function ($join) {
            $join->on('pv_agg.viewed_profile_id', '=', 'matrimony_profiles.id');
        });

        $query->orderByRaw('CASE WHEN pv_agg.last_viewed_at IS NULL THEN 0 ELSE 1 END ASC');

        // Portable pseudo-random tie-break (same seed ⇒ same order across pages; not cryptographic).
        $mix = hexdec(substr(hash('sha256', $seed, false), 0, 8)) & 0x7FFFFFFF;
        $query->orderByRaw('((matrimony_profiles.id * 1103515245 + ?) & 2147483647)', [$mix]);
    }
}
