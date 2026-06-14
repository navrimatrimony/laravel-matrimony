<?php

namespace App\Services\Intake;

use App\Models\BiodataIntake;
use App\Models\MatrimonyProfile;
use App\Models\SuchakBiodataIntakeLink;

/**
 * Resolve the matrimony profile whose data must win over intake on preview (same user / linked id).
 */
class IntakePreviewLinkedProfileResolver
{
    public function resolve(BiodataIntake $intake): ?MatrimonyProfile
    {
        if ($intake->matrimony_profile_id) {
            $profile = MatrimonyProfile::query()->find($intake->matrimony_profile_id);
            if ($profile) {
                return $profile;
            }
        }

        // Suchak biodata intake creates a new candidate profile on approve — never overlay the Suchak owner's profile.
        if (SuchakBiodataIntakeLink::query()->where('biodata_intake_id', $intake->id)->exists()) {
            return null;
        }

        if ($intake->uploaded_by) {
            return MatrimonyProfile::query()
                ->where('user_id', $intake->uploaded_by)
                ->orderByDesc('id')
                ->first();
        }

        return null;
    }
}
