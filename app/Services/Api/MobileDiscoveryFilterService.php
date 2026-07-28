<?php

namespace App\Services\Api;

use App\Models\HiddenProfile;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\ProfileLifecycleService;
use App\Services\ProfileVisibilityPolicyService;
use App\Services\ViewTrackingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class MobileDiscoveryFilterService
{
    /**
     * @param  Builder<MatrimonyProfile>  $query
     * @return Builder<MatrimonyProfile>
     */
    public function applyCandidateQuery(Builder $query, User $viewer): Builder
    {
        $viewerProfile = $this->viewerProfile($viewer);
        $targetGender = $this->oppositeGenderKeyForViewer($viewer);

        $query
            ->where(function (Builder $q): void {
                $q->where('lifecycle_state', 'active')->orWhereNull('lifecycle_state');
            })
            ->where('is_suspended', false)
            ->whereMemberAccountsOnly();

        if (! $viewerProfile || $targetGender === null) {
            return $query->whereRaw('1 = 0');
        }

        $query
            ->whereKeyNot((int) $viewerProfile->id)
            ->where('user_id', '!=', (int) $viewer->id)
            ->whereNotNull('gender_id')
            ->whereHas('gender', static fn (Builder $gender): Builder => $gender->where('key', $targetGender));

        $excluded = $this->excludedProfileIdsForViewer($viewer);
        if ($excluded !== []) {
            $query->whereNotIn('id', $excluded);
        }

        return $query;
    }

    public function isAllowedTarget(User $viewer, MatrimonyProfile $target): bool
    {
        $viewerProfile = $this->viewerProfile($viewer);
        $targetGender = $this->oppositeGenderKeyForViewer($viewer);
        if (! $viewerProfile || $targetGender === null) {
            return false;
        }

        $target->loadMissing(['user', 'gender']);
        if ((int) $target->id === (int) $viewerProfile->id) {
            return false;
        }
        if ((int) $target->user_id === (int) $viewer->id) {
            return false;
        }
        if ($target->gender_id === null || (int) $target->gender_id <= 0) {
            return false;
        }

        $targetUser = $target->user;
        if (! $targetUser || $targetUser->isAnyAdmin()) {
            return false;
        }
        if (! ProfileLifecycleService::isVisibleToOthers($target)) {
            return false;
        }
        if ($this->genderKey($target) !== $targetGender) {
            return false;
        }
        if (ViewTrackingService::isBlocked((int) $viewerProfile->id, (int) $target->id)) {
            return false;
        }
        if ($this->isHiddenByViewer($viewerProfile, $target)) {
            return false;
        }

        return ProfileVisibilityPolicyService::canViewProfile($target, $viewer);
    }

    public function viewerCanDiscover(User $viewer): bool
    {
        return $this->viewerProfile($viewer) !== null
            && $this->oppositeGenderKeyForViewer($viewer) !== null;
    }

    public function oppositeGenderKeyForViewer(User $viewer): ?string
    {
        $viewerProfile = $this->viewerProfile($viewer);

        return match ($this->genderKey($viewerProfile)) {
            'male' => 'female',
            'female' => 'male',
            default => null,
        };
    }

    /**
     * @return list<int>
     */
    public function excludedProfileIdsForViewer(User $viewer): array
    {
        $viewerProfile = $this->viewerProfile($viewer);
        if (! $viewerProfile) {
            return [];
        }

        $ids = ViewTrackingService::getBlockedProfileIds((int) $viewerProfile->id)
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if (Schema::hasTable('hidden_profiles')) {
            $hidden = HiddenProfile::query()
                ->where('owner_profile_id', (int) $viewerProfile->id)
                ->pluck('hidden_profile_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            $ids = array_merge($ids, $hidden);
        }

        return array_values(array_unique(array_filter($ids)));
    }

    private function viewerProfile(User $viewer): ?MatrimonyProfile
    {
        $viewer->loadMissing('matrimonyProfile.gender');
        $profile = $viewer->matrimonyProfile;

        return $profile instanceof MatrimonyProfile ? $profile : null;
    }

    private function isHiddenByViewer(MatrimonyProfile $viewerProfile, MatrimonyProfile $target): bool
    {
        if (! Schema::hasTable('hidden_profiles')) {
            return false;
        }

        return HiddenProfile::query()
            ->where('owner_profile_id', (int) $viewerProfile->id)
            ->where('hidden_profile_id', (int) $target->id)
            ->exists();
    }

    private function genderKey(?MatrimonyProfile $profile): ?string
    {
        if (! $profile) {
            return null;
        }

        $profile->loadMissing('gender');

        return $this->genderString($profile->gender?->key ?? $profile->gender?->label ?? null);
    }

    private function genderString(mixed $gender): ?string
    {
        $value = mb_strtolower(trim((string) $gender));
        if ($value === '') {
            return null;
        }
        if (str_contains($value, 'female') || str_contains($value, 'स्त्री') || str_contains($value, 'महिला')) {
            return 'female';
        }
        if (str_contains($value, 'male') || str_contains($value, 'पुरुष')) {
            return 'male';
        }

        return null;
    }
}
