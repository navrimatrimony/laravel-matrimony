<?php

declare(strict_types=1);

namespace App\Services\Intake;

use App\Models\AdminSetting;
use App\Models\BiodataIntake;

final class IntakePhotoCandidatePreviewService
{
    public function __construct(
        private readonly IntakePhotoCandidateCropService $candidateCrop,
    ) {}

    /**
     * @return array{
     *     enabled: bool,
     *     show_in_normalized_preview: bool,
     *     available: bool,
     *     thumbnail_url: ?string,
     *     source: ?string,
     *     message: string
     * }
     */
    public function preview(BiodataIntake $intake): array
    {
        $enabled = AdminSetting::getBool('intake_photo_crop_enabled', false);
        $showInNormalizedPreview = AdminSetting::getBool('intake_photo_show_in_normalized_preview', false);

        if (! $enabled) {
            return $this->payload(false, $showInNormalizedPreview, false, null, null, '');
        }

        if (! $showInNormalizedPreview) {
            return $this->payload($enabled, false, false, null, null, '');
        }

        if (! $this->candidateCrop->isImageIntake($intake)) {
            return $this->payload(
                $enabled,
                $showInNormalizedPreview,
                false,
                null,
                null,
                'No uploaded image biodata available.'
            );
        }

        if ($this->candidateCrop->exists($intake)) {
            return $this->payload(
                $enabled,
                $showInNormalizedPreview,
                true,
                route('intake.photo-candidate-image', $intake),
                'manual_candidate_crop',
                'Preview only. Not saved as profile photo yet.'
            );
        }

        return $this->payload(
            $enabled,
            $showInNormalizedPreview,
            false,
            null,
            'uploaded_image',
            'Candidate photo extraction is not available yet.'
        );
    }

    /**
     * @return array{
     *     enabled: bool,
     *     show_in_normalized_preview: bool,
     *     available: bool,
     *     thumbnail_url: ?string,
     *     source: ?string,
     *     message: string
     * }
     */
    private function payload(
        bool $enabled,
        bool $showInNormalizedPreview,
        bool $available,
        ?string $thumbnailUrl,
        ?string $source,
        string $message
    ): array {
        return [
            'enabled' => $enabled,
            'show_in_normalized_preview' => $showInNormalizedPreview,
            'available' => $available,
            'thumbnail_url' => $thumbnailUrl,
            'source' => $source,
            'message' => $message,
        ];
    }
}
