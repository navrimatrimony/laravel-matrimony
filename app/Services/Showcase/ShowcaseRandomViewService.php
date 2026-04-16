<?php

namespace App\Services\Showcase;

use App\Models\AdminSetting;
use App\Models\Interest;
use App\Models\MatrimonyProfile;
use App\Models\MasterGender;
use App\Models\ProfileView;
use App\Models\User;
use App\Services\ViewTrackingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Picks real members for showcase-initiated "random" profile views (scheduled engine).
 * Weighted by district, religion, caste, age proximity, new-user boost — all admin-tunable.
 */
final class ShowcaseRandomViewService
{
    public function run(): int
    {
        if (! AdminSetting::getBool('showcase_random_view_enabled', false)) {
            return 0;
        }

        $batchTotal = max(0, (int) AdminSetting::getValue('showcase_random_view_batch_per_run', '15'));
        if ($batchTotal === 0) {
            return 0;
        }

        $showcases = MatrimonyProfile::query()
            ->whereShowcase()
            ->where(function ($q) {
                $q->where('lifecycle_state', 'active')->orWhereNull('lifecycle_state');
            })
            ->where(function ($q) {
                $q->where('is_suspended', false)->orWhereNull('is_suspended');
            })
            ->whereNull('deleted_at')
            ->whereNotNull('gender_id')
            ->with(['user'])
            ->get();

        if ($showcases->isEmpty()) {
            return 0;
        }

        $showcases = $showcases->shuffle();
        $created = 0;

        foreach ($showcases as $showcase) {
            if ($created >= $batchTotal) {
                break;
            }
            if (! $showcase->user) {
                continue;
            }

            $real = $this->pickTargetProfile($showcase);
            if ($real === null) {
                continue;
            }

            ViewTrackingService::recordShowcaseRandomProfileView($showcase, $real);
            $created++;
        }

        return $created;
    }

    public function pickTargetProfile(MatrimonyProfile $showcase): ?MatrimonyProfile
    {
        $oppositeGenderId = $this->oppositeGenderId((int) $showcase->gender_id);
        if ($oppositeGenderId === null) {
            return null;
        }

        $blocked = ViewTrackingService::getBlockedProfileIds($showcase->id);

        $interestExclude = Interest::query()
            ->where(function ($q) use ($showcase) {
                $q->where('sender_profile_id', $showcase->id)
                    ->orWhere('receiver_profile_id', $showcase->id);
            })
            ->get()
            ->map(function (Interest $i) use ($showcase) {
                return $i->sender_profile_id === $showcase->id
                    ? (int) $i->receiver_profile_id
                    : (int) $i->sender_profile_id;
            })
            ->unique()
            ->all();

        $excludeIds = collect($blocked)->merge($interestExclude)->merge([$showcase->id])->unique()->all();

        $candidateLimit = max(80, (int) AdminSetting::getValue('showcase_random_view_candidate_pool', '120'));

        $query = MatrimonyProfile::query()
            ->whereNonShowcase()
            ->where(function ($q) {
                $q->where('lifecycle_state', 'active')->orWhereNull('lifecycle_state');
            })
            ->where(function ($q) {
                $q->where('is_suspended', false)->orWhereNull('is_suspended');
            })
            ->whereNull('deleted_at')
            ->where('gender_id', $oppositeGenderId)
            ->whereNotIn('id', $excludeIds)
            ->whereHas('user', function ($uq) {
                $uq->where(function ($q) {
                    $q->whereNull('is_admin')->orWhere('is_admin', false);
                });
            })
            ->inRandomOrder()
            ->limit($candidateLimit);

        /** @var Collection<int, MatrimonyProfile> $candidates */
        $candidates = $query->with('user')->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        $revisitMode = (string) AdminSetting::getValue('showcase_random_view_revisit_mode', '30d');
        if (! in_array($revisitMode, ['never', '1d', '7d', '30d', 'random'], true)) {
            $revisitMode = '30d';
        }
        $randomGapDaysMin = max(1, (int) AdminSetting::getValue('showcase_random_view_revisit_random_min_days', '3'));
        $randomGapDaysMax = max($randomGapDaysMin, (int) AdminSetting::getValue('showcase_random_view_revisit_random_max_days', '14'));
        $rolledGapDays = random_int($randomGapDaysMin, $randomGapDaysMax);

        $maxPerWeek = (int) AdminSetting::getValue('showcase_random_view_max_per_real_per_week', '5');
        $maxPerMonth = (int) AdminSetting::getValue('showcase_random_view_max_per_real_per_month', '10');

        $sinceWeek = now()->subDays(7);
        $sinceMonth = now()->subDays(30);

        $filtered = $candidates->filter(function (MatrimonyProfile $real) use (
            $showcase,
            $revisitMode,
            $rolledGapDays,
            $sinceWeek,
            $sinceMonth,
            $maxPerWeek,
            $maxPerMonth
        ): bool {
            if ($this->realFailsShowcaseViewCaps($real, $sinceWeek, $sinceMonth, $maxPerWeek, $maxPerMonth)) {
                return false;
            }

            return $this->pairAllowedByRevisitRule($showcase->id, $real->id, $revisitMode, $rolledGapDays);
        });

        if ($filtered->isEmpty()) {
            return null;
        }

        $weights = $this->weightMap($showcase, $filtered);
        if ($weights->isEmpty()) {
            return null;
        }

        return $this->weightedPick($filtered, $weights);
    }

    private function realFailsShowcaseViewCaps(
        MatrimonyProfile $real,
        Carbon $sinceWeek,
        Carbon $sinceMonth,
        int $maxPerWeek,
        int $maxPerMonth
    ): bool {
        if ($maxPerWeek > 0) {
            $w = $this->countShowcaseViewsToProfileSince($real->id, $sinceWeek);
            if ($w >= $maxPerWeek) {
                return true;
            }
        }
        if ($maxPerMonth > 0) {
            $m = $this->countShowcaseViewsToProfileSince($real->id, $sinceMonth);
            if ($m >= $maxPerMonth) {
                return true;
            }
        }

        return false;
    }

    private function countShowcaseViewsToProfileSince(int $viewedProfileId, Carbon $since): int
    {
        return (int) ProfileView::query()
            ->where('viewed_profile_id', $viewedProfileId)
            ->where('created_at', '>=', $since)
            ->whereHas('viewerProfile', function ($q) {
                $q->where('is_showcase', true);
            })
            ->count();
    }

    private function pairAllowedByRevisitRule(
        int $showcaseId,
        int $realId,
        string $mode,
        int $rolledRandomGapDays
    ): bool {
        $last = ProfileView::query()
            ->where('viewer_profile_id', $showcaseId)
            ->where('viewed_profile_id', $realId)
            ->orderByDesc('created_at')
            ->first();

        if ($last === null) {
            return true;
        }

        return match ($mode) {
            'never' => false,
            '1d' => $last->created_at->lte(now()->subDay()),
            '7d' => $last->created_at->lte(now()->subDays(7)),
            '30d' => $last->created_at->lte(now()->subDays(30)),
            'random' => $last->created_at->lte(now()->subDays($rolledRandomGapDays)),
            default => $last->created_at->lte(now()->subDays(30)),
        };
    }

    /**
     * @param  Collection<int, MatrimonyProfile>  $candidates
     * @return Collection<int, int> profile id => weight
     */
    private function weightMap(MatrimonyProfile $showcase, Collection $candidates): Collection
    {
        $wDistrict = max(0, (int) AdminSetting::getValue('showcase_random_view_weight_district', '40'));
        $wReligion = max(0, (int) AdminSetting::getValue('showcase_random_view_weight_religion', '30'));
        $wCaste = max(0, (int) AdminSetting::getValue('showcase_random_view_weight_caste', '30'));
        $wAge = max(0, (int) AdminSetting::getValue('showcase_random_view_weight_age', '20'));
        $wNew = max(0, (int) AdminSetting::getValue('showcase_random_view_weight_new_user', '35'));
        $wBase = max(0, (int) AdminSetting::getValue('showcase_random_view_weight_base', '10'));
        $ageSpread = max(1, (int) AdminSetting::getValue('showcase_random_view_age_spread_years', '6'));
        $newDays = max(1, (int) AdminSetting::getValue('showcase_random_view_new_user_days', '30'));

        $showcaseDob = $this->parseDob($showcase->date_of_birth);
        $showcaseReligion = $showcase->religion_id;
        $showcaseCaste = $showcase->caste_id;
        $showcaseDistrict = $showcase->district_id;

        $weights = collect();
        foreach ($candidates as $real) {
            $score = $wBase;
            if ($showcaseDistrict && $real->district_id && (int) $real->district_id === (int) $showcaseDistrict) {
                $score += $wDistrict;
            }
            if ($showcaseReligion && $real->religion_id && (int) $real->religion_id === (int) $showcaseReligion) {
                $score += $wReligion;
            }
            if ($showcaseCaste && $real->caste_id && (int) $real->caste_id === (int) $showcaseCaste) {
                $score += $wCaste;
            }
            $realDob = $this->parseDob($real->date_of_birth);
            if ($showcaseDob && $realDob) {
                $ydiff = (int) abs($showcaseDob->diffInYears($realDob));
                if ($ydiff <= $ageSpread) {
                    $score += $wAge;
                }
            }
            $user = $real->user;
            if ($user instanceof User && $user->created_at && $user->created_at->gte(now()->subDays($newDays))) {
                $score += $wNew;
            }
            $weights[(int) $real->id] = max(1, $score);
        }

        return $weights;
    }

    /**
     * @param  Collection<int, MatrimonyProfile>  $candidates
     * @param  Collection<int, int>  $weights
     */
    private function weightedPick(Collection $candidates, Collection $weights): ?MatrimonyProfile
    {
        $total = $weights->sum();
        if ($total <= 0) {
            return null;
        }
        $r = random_int(1, $total);
        $acc = 0;
        foreach ($candidates as $real) {
            $w = (int) $weights->get((int) $real->id, 1);
            $acc += $w;
            if ($r <= $acc) {
                return $real;
            }
        }

        return $candidates->first();
    }

    private function parseDob(mixed $dob): ?Carbon
    {
        if ($dob === null || $dob === '') {
            return null;
        }
        try {
            return Carbon::parse($dob)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function oppositeGenderId(int $genderId): ?int
    {
        $self = MasterGender::query()->find($genderId);
        if (! $self || ! $self->key) {
            return null;
        }
        $wantKey = $self->key === 'male' ? 'female' : ($self->key === 'female' ? 'male' : null);
        if ($wantKey === null) {
            return null;
        }
        $other = MasterGender::query()->where('key', $wantKey)->where('is_active', true)->first();

        return $other ? (int) $other->id : null;
    }
}
