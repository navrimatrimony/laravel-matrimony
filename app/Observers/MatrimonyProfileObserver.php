<?php

namespace App\Observers;

use App\Models\MatrimonyPhotoBatchAllocation;
use App\Models\MatrimonyProfile;
use App\Services\ProfileCompletionEngine;
use App\Services\ProfileVisibilitySettingsDefaultsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class MatrimonyProfileObserver
{
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
