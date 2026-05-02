<?php

namespace App\Observers;

use App\Models\Location;
use App\Models\MatrimonyPhotoBatchAllocation;
use App\Models\MatrimonyProfile;
use App\Services\Location\LocationUsageStatsService;
use App\Services\ProfileCompletionEngine;
use App\Services\ProfileVisibilitySettingsDefaultsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class MatrimonyProfileObserver
{
    /**
     * Residence SSOT: {@see MatrimonyProfile::$location_id} required once profile leaves draft.
     */
    public function saving(MatrimonyProfile $profile): void
    {
        if (! Schema::hasColumn('matrimony_profiles', 'location_id')) {
            return;
        }
        if ($this->allowsNullResidenceLocation($profile)) {
            return;
        }
        $lid = $profile->location_id;
        if ($lid === null || $lid === '' || (int) $lid < 1) {
            throw ValidationException::withMessages([
                'location_id' => ['Residence location is required.'],
            ]);
        }
        if (! Location::query()->whereKey((int) $lid)->exists()) {
            throw ValidationException::withMessages([
                'location_id' => ['Select a valid residence location.'],
            ]);
        }
    }

    private function allowsNullResidenceLocation(MatrimonyProfile $profile): bool
    {
        return ($profile->lifecycle_state ?? 'draft') === 'draft';
    }

    public function created(MatrimonyProfile $profile): void
    {
        ProfileVisibilitySettingsDefaultsService::ensureForProfile($profile);
    }

    public function saved(MatrimonyProfile $profile): void
    {
        if ($profile->user_id) {
            Cache::forget('profile_completion_'.$profile->user_id);
            app(ProfileCompletionEngine::class)->forgetRequestCacheForUser((int) $profile->user_id);
        }
        Cache::forget('profile_completion_profile_'.$profile->id);

        if (! Schema::hasTable('location_usage_stats')) {
            return;
        }

        $stats = app(LocationUsageStatsService::class);
        foreach (['location_id', 'birth_city_id', 'native_city_id', 'work_city_id'] as $col) {
            if (! Schema::hasColumn('matrimony_profiles', $col)) {
                continue;
            }
            if (! $profile->wasChanged($col)) {
                continue;
            }
            $id = $profile->{$col};
            if ($id !== null && is_numeric($id)) {
                $stats->recordUse((int) $id);
            }
        }
    }

    public function deleted(MatrimonyProfile $profile): void
    {
        if ($profile->user_id) {
            Cache::forget('profile_completion_'.$profile->user_id);
            app(ProfileCompletionEngine::class)->forgetRequestCacheForUser((int) $profile->user_id);
        }
        Cache::forget('profile_completion_profile_'.$profile->id);
    }

    public function deleting(MatrimonyProfile $profile): void
    {
        $allocationId = (int) ($profile->photo_batch_allocation_id ?? 0);
        if ($allocationId <= 0 || ! Schema::hasTable('matrimony_photo_batch_allocations')) {
            return;
        }

        MatrimonyPhotoBatchAllocation::query()
            ->whereKey($allocationId)
            ->where('profiles_count', '>', 0)
            ->decrement('profiles_count');
    }
}
