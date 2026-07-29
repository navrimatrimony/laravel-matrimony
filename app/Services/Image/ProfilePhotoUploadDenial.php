<?php

namespace App\Services\Image;

/**
 * Value object describing WHY a member photo upload was refused.
 *
 * Carries everything each surface needs to render its own existing error
 * format: a machine reason, the user-facing message, the HTTP status the
 * API surfaces already return, and the extra JSON keys the gallery endpoint
 * has always included (can_upload / max_photos / current_photo_count).
 */
final class ProfilePhotoUploadDenial
{
    public const REASON_SUSPENDED = 'suspended';

    public const REASON_LIFECYCLE_LOCKED = 'lifecycle_locked';

    public const REASON_MAX_PHOTOS = 'max_photos';

    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public readonly string $reason,
        public readonly string $message,
        public readonly int $status,
        public readonly array $extra = [],
    ) {}
}
