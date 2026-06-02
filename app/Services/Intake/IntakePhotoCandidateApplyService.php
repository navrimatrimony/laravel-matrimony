<?php

declare(strict_types=1);

namespace App\Services\Intake;

use App\Jobs\ProcessProfilePhoto;
use App\Models\AdminSetting;
use App\Models\BiodataIntake;
use App\Models\MatrimonyProfile;
use App\Services\Image\ImageProcessingService;

final class IntakePhotoCandidateApplyService
{
    public function __construct(
        private readonly IntakePhotoCandidateCropService $cropService,
        private readonly ImageProcessingService $imageProcessingService,
    ) {}

    public function applyAfterSuccessfulIntakeMutation(BiodataIntake $intake, ?int $profileId = null): ?string
    {
        if (! AdminSetting::getBool('intake_photo_apply_as_profile_photo', false)) {
            return null;
        }

        if (! $this->cropService->exists($intake)) {
            return null;
        }

        $profileId ??= $intake->matrimony_profile_id ? (int) $intake->matrimony_profile_id : null;
        if (! $profileId) {
            return null;
        }

        $profile = MatrimonyProfile::query()->find($profileId);
        if (! $profile) {
            return null;
        }

        return $this->imageProcessingService->enqueueExistingProfilePhotoPath(
            $this->cropService->absolutePath($intake),
            (int) $profile->id,
            'intake_crop',
            ProcessProfilePhoto::PRIMARY_MODE_INTAKE_CROP_PRIMARY_IF_NONE,
        );
    }
}
