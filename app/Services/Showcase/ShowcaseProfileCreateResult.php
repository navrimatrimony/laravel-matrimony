<?php

namespace App\Services\Showcase;

/**
 * Outcome of one {@see ShowcaseProfileFactory} create attempt (bulk or auto-engine).
 */
final class ShowcaseProfileCreateResult
{
    public const OUTCOME_CREATED = 'created';

    public const OUTCOME_CREATED_WITHOUT_PHOTO = 'created_without_photo';

    public const OUTCOME_SKIPPED_NO_PHOTO = 'skipped_no_photo';

    public const OUTCOME_SKIPPED_NO_LOCATION = 'skipped_no_location';

    public const OUTCOME_SKIPPED_DUPLICATE_USER = 'skipped_duplicate_user';

    public function __construct(
        public readonly ?int $profileId,
        public readonly string $outcome,
        public readonly ?string $photoSkipReason = null,
        public readonly ?string $photoCategoryLabel = null,
        public readonly ?string $expectedPhotoFolder = null,
    ) {}

    public function succeeded(): bool
    {
        return $this->profileId !== null;
    }
}
