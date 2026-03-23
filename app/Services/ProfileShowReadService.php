<?php

namespace App\Services;

use App\Models\AdminSetting;
use App\Models\MatrimonyProfile;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Read-only helpers for the public profile show page (browse, verification list, similar profiles).
 * No mutations, no stored scores.
 */
class ProfileShowReadService
{
    /**
     * Base query aligned with matrimony profile index search visibility (active, completeness, demo, blocks).
     */
    public static function browseBaseQuery(User $viewer): Builder
    {
        $q = MatrimonyProfile::query()
            ->where(function ($q) {
                $q->where('lifecycle_state', 'active')->orWhereNull('lifecycle_state');
            })
            ->where('is_suspended', false);

        $q->whereRaw(ProfileCompletenessService::sqlSearchVisible('matrimony_profiles'));

        $demoVisible = AdminSetting::getBool('demo_profiles_visible_in_search', true);
        if (! $demoVisible) {
            $q->where(function ($q) {
                $q->where('is_demo', false)->orWhereNull('is_demo');
            });
        }

        $myId = $viewer->matrimonyProfile?->id;
        if ($myId) {
            $blockedIds = ViewTrackingService::getBlockedProfileIds($myId);
            if ($blockedIds->isNotEmpty()) {
                $q->whereNotIn('matrimony_profiles.id', $blockedIds);
            }
        }

        return $q;
    }

    /**
     * @return array<int, array{key: string, label: string}>
     */
    public static function buildVerificationItems(MatrimonyProfile $profile, ?User $user): array
    {
        $items = [];
        if ($user) {
            if ($user->email_verified_at) {
                $items[] = ['key' => 'email', 'label' => __('profile.show_verify_email')];
            }
            if ($user->mobile_verified_at) {
                $items[] = ['key' => 'mobile', 'label' => __('profile.show_verify_mobile')];
            }
        }
        if (($profile->profile_photo ?? '') !== '' && $profile->photo_approved === true) {
            $items[] = ['key' => 'photo', 'label' => __('profile.show_verify_photo')];
        }

        return $items;
    }

    /**
     * @return array{prev: ?array, next: ?array}
     */
    public static function navigationPeers(MatrimonyProfile $current, User $viewer): array
    {
        $prev = self::findAdjacentProfile($current->id, 'prev', $viewer);
        $next = self::findAdjacentProfile($current->id, 'next', $viewer);

        return [
            'prev' => $prev ? self::peerSummary($prev) : null,
            'next' => $next ? self::peerSummary($next) : null,
        ];
    }

    /**
     * @return array{id: int, name: string, photo_url: string, short_line: string}
     */
    public static function peerSummary(MatrimonyProfile $p): array
    {
        $p->loadMissing(['gender', 'district', 'state']);

        return [
            'id' => (int) $p->id,
            'name' => (string) ($p->full_name ?? ''),
            'photo_url' => self::photoThumbUrl($p),
            'short_line' => self::compactSummaryLine($p),
        ];
    }

    public static function photoThumbUrl(MatrimonyProfile $p): string
    {
        if ($p->profile_photo && $p->photo_approved !== false) {
            return asset('uploads/matrimony_photos/'.$p->profile_photo);
        }
        $g = $p->gender?->key ?? $p->gender;
        if ($g === 'male') {
            return asset('images/placeholders/male-profile.svg');
        }
        if ($g === 'female') {
            return asset('images/placeholders/female-profile.svg');
        }

        return asset('images/placeholders/default-profile.svg');
    }

    public static function compactSummaryLine(MatrimonyProfile $p): string
    {
        $p->loadMissing(['district', 'state']);
        $parts = array_filter([
            $p->highest_education ?: null,
            $p->occupation_title ?: null,
            $p->district?->name ?? $p->state?->name,
        ]);

        return implode(' · ', $parts);
    }

    private static function findAdjacentProfile(int $currentId, string $direction, User $viewer): ?MatrimonyProfile
    {
        $base = self::browseBaseQuery($viewer)->where('matrimony_profiles.id', '!=', $currentId);
        if ($direction === 'prev') {
            $ids = (clone $base)->where('matrimony_profiles.id', '<', $currentId)
                ->orderByDesc('matrimony_profiles.id')
                ->limit(40)
                ->pluck('matrimony_profiles.id');
        } else {
            $ids = (clone $base)->where('matrimony_profiles.id', '>', $currentId)
                ->orderBy('matrimony_profiles.id')
                ->limit(40)
                ->pluck('matrimony_profiles.id');
        }
        foreach ($ids as $id) {
            $p = MatrimonyProfile::query()->find($id);
            if ($p && ProfileVisibilityPolicyService::canViewProfile($p, $viewer)) {
                return $p;
            }
        }

        return null;
    }

    /**
     * Deterministic similarity ordering (structure only, not a compatibility score).
     *
     * @return Collection<int, MatrimonyProfile>
     */
    public static function similarProfiles(MatrimonyProfile $target, User $viewer, int $limit = 3): Collection
    {
        if (! $target->gender_id) {
            return collect();
        }

        $candidates = self::browseBaseQuery($viewer)
            ->where('matrimony_profiles.id', '!=', $target->id)
            ->where('gender_id', $target->gender_id)
            ->with(['gender', 'district', 'state', 'maritalStatus', 'religion', 'caste'])
            ->limit(120)
            ->get();

        $targetAge = null;
        if ($target->date_of_birth) {
            try {
                $targetAge = Carbon::parse($target->date_of_birth)->age;
            } catch (\Throwable) {
                $targetAge = null;
            }
        }

        $scored = $candidates->map(function (MatrimonyProfile $p) use ($target, $targetAge) {
            $score = 0;
            if ($target->religion_id && $p->religion_id === $target->religion_id) {
                $score += 4;
            }
            if ($target->caste_id && $p->caste_id === $target->caste_id) {
                $score += 3;
            }
            if ($target->district_id && $p->district_id === $target->district_id) {
                $score += 5;
            } elseif ($target->state_id && $p->state_id === $target->state_id) {
                $score += 2;
            }
            if ($targetAge !== null && $p->date_of_birth) {
                try {
                    $a = Carbon::parse($p->date_of_birth)->age;
                    if (abs($a - $targetAge) <= 5) {
                        $score += 2;
                    }
                } catch (\Throwable) {
                }
            }

            return ['score' => $score, 'profile' => $p];
        })->sortByDesc('score')->pluck('profile');

        $out = collect();
        foreach ($scored as $p) {
            if (! ProfileVisibilityPolicyService::canViewProfile($p, $viewer)) {
                continue;
            }
            $out->push($p);
            if ($out->count() >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Same district, else same state; excludes IDs in $alsoExclude.
     *
     * @return Collection<int, MatrimonyProfile>
     */
    public static function sameDistrictOrStateProfiles(MatrimonyProfile $target, User $viewer, int $limit = 3, array $alsoExclude = []): Collection
    {
        $q = self::browseBaseQuery($viewer)
            ->where('matrimony_profiles.id', '!=', $target->id);

        if (! empty($alsoExclude)) {
            $q->whereNotIn('matrimony_profiles.id', $alsoExclude);
        }

        if ($target->district_id) {
            $q->where('district_id', $target->district_id);
        } elseif ($target->state_id) {
            $q->where('state_id', $target->state_id);
        } else {
            return collect();
        }

        $candidates = $q->with(['gender', 'district', 'state', 'maritalStatus', 'religion'])
            ->orderByDesc('matrimony_profiles.id')
            ->limit(40)
            ->get();

        $out = collect();
        foreach ($candidates as $p) {
            if (! ProfileVisibilityPolicyService::canViewProfile($p, $viewer)) {
                continue;
            }
            $out->push($p);
            if ($out->count() >= $limit) {
                break;
            }
        }

        return $out;
    }

    /** One-line headline for hero (education · occupation · location · marital). */
    public static function profileHeadline(MatrimonyProfile $p): string
    {
        $p->loadMissing(['city', 'district', 'state', 'maritalStatus']);
        $loc = trim(implode(', ', array_filter([
            $p->city?->name,
            $p->district?->name,
            $p->state?->name,
        ])));

        $parts = array_filter([
            $p->highest_education ?: null,
            $p->occupation_title ?: null,
            $loc !== '' ? $loc : null,
            $p->maritalStatus?->label ?? null,
        ]);

        return implode(' · ', $parts);
    }

    /**
     * Short factual intro when narrative is absent (no personality claims).
     */
    public static function generatedIntroSentence(MatrimonyProfile $p): ?string
    {
        $name = trim((string) ($p->full_name ?? ''));
        if ($name === '') {
            return null;
        }
        $p->loadMissing(['city', 'district', 'state', 'familyType', 'gender']);

        $chunks = [];
        if (($p->highest_education ?? '') !== '') {
            $edu = $p->highest_education;
            $chunks[] = $edu.'-educated';
        }
        if (($p->occupation_title ?? '') !== '') {
            $chunks[] = $p->occupation_title;
        }
        $loc = trim(implode(', ', array_filter([$p->city?->name, $p->district?->name, $p->state?->name])));
        if ($loc !== '') {
            $chunks[] = 'from '.$loc;
        }
        if ($p->familyType && ($p->familyType->label ?? '') !== '') {
            $chunks[] = 'from a '.mb_strtolower((string) $p->familyType->label).' family';
        }

        if (empty($chunks)) {
            return null;
        }

        return $name.' is '.implode(', ', $chunks).'.';
    }
}
