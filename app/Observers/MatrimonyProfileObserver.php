<?php

namespace App\Observers;

use App\Models\MatrimonyPhotoBatchAllocation;
use App\Models\MatrimonyProfile;
use App\Services\ProfileVisibilitySettingsDefaultsService;
use Illuminate\Support\Facades\Schema;

class MatrimonyProfileObserver
{
    public function created(MatrimonyProfile $profile): void
    {
        ProfileVisibilitySettingsDefaultsService::ensureForProfile($profile);
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
