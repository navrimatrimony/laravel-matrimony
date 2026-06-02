<?php

declare(strict_types=1);

namespace App\Services\Intake;

use App\Models\AdminSetting;
use App\Models\BiodataIntake;

final class IntakePhotoCandidatePreviewService
{
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

        $relativePath = trim((string) ($intake->file_path ?? ''));
        if (! $this->hasUploadedImage($relativePath)) {
            return $this->payload(
                $enabled,
                $showInNormalizedPreview,
                false,
                null,
                null,
                'No uploaded image biodata available.'
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

    private function hasUploadedImage(string $relativePath): bool
    {
        if ($relativePath === '') {
            return false;
        }

        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true)) {
            return false;
        }

        $fullPath = storage_path('app/private/'.$relativePath);

        return is_file($fullPath) && is_readable($fullPath);
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
