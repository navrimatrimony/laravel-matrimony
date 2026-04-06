<?php

namespace App\Observers;

use App\Models\MatrimonyProfile;
use App\Services\ProfileVisibilitySettingsDefaultsService;

class MatrimonyProfileObserver
{
    public function created(MatrimonyProfile $profile): void
    {
        ProfileVisibilitySettingsDefaultsService::ensureForProfile($profile);
    }
}
