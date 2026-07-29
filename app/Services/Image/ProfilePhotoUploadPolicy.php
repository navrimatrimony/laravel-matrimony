<?php

namespace App\Services\Image;

use App\Models\AdminSetting;
use App\Models\MatrimonyProfile;
use App\Models\ProfilePhoto;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * THE single answer to "may this profile upload N photos of this size right now,
 * and if not, why not?".
 *
 * Every member photo-upload surface funnels through this class:
 *  - Api\MobileProfilePhotoApiController::store()   (gallery, many photos)
 *  - Api\MatrimonyProfileApiController::uploadPhoto() (legacy single photo, old APKs)
 *  - ProfileWizardController::buildPhotoSnapshot()  (website wizard)
 *
 * The four admin rules it owns:
 *  1. AdminSetting 'photo_max_upload_mb'   (default 8) -> validation max, in KB
 *  2. AdminSetting 'photo_max_per_profile' (default 5) vs current photo count
 *  3. users.photo_uploads_suspended        (Schema::hasColumn guarded)
 *  4. profile lifecycle states that lock photo changes
 *
 * It returns a ProfilePhotoUploadDenial (or null) and never renders anything —
 * each caller keeps its own existing response shape.
 */
class ProfilePhotoUploadPolicy
{
    /**
     * Lifecycle states during which the profile is locked for photo changes.
     */
    public const LIFECYCLE_BLOCKING_STATES = [
        'intake_uploaded',
        'awaiting_user_approval',
        'approved_pending_mutation',
        'conflict_pending',
    ];

    public function maxUploadMb(): int
    {
        return max(1, (int) AdminSetting::getValue('photo_max_upload_mb', '8'));
    }

    /**
     * Laravel's `max:` rule for image uploads is expressed in kilobytes.
     */
    public function maxUploadKb(): int
    {
        return $this->maxUploadMb() * 1024;
    }

    public function maxPerProfile(): int
    {
        return max(1, (int) AdminSetting::getValue('photo_max_per_profile', '5'));
    }

    public function uploadsSuspended(?User $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        return Schema::hasColumn('users', 'photo_uploads_suspended')
            && (bool) $user->photo_uploads_suspended;
    }

    public function lifecycleBlocks(MatrimonyProfile $profile): bool
    {
        return in_array($profile->lifecycle_state, self::LIFECYCLE_BLOCKING_STATES, true);
    }

    /**
     * How many photos this profile already counts against the per-profile cap.
     */
    public function currentPhotoCount(MatrimonyProfile $profile): int
    {
        $count = Schema::hasTable('profile_photos')
            ? (int) ProfilePhoto::query()->where('profile_id', $profile->id)->count()
            : 0;

        $corePhoto = trim((string) ($profile->profile_photo ?? ''));
        if ($count === 0 && $corePhoto !== '') {
            return 1;
        }

        return $count;
    }

    /**
     * Account/profile level gate — evaluated BEFORE the uploaded files are validated,
     * because a suspended or locked profile must not be told about file size first.
     */
    public function denyBeforeUpload(?User $user, MatrimonyProfile $profile): ?ProfilePhotoUploadDenial
    {
        if ($this->uploadsSuspended($user)) {
            return new ProfilePhotoUploadDenial(
                ProfilePhotoUploadDenial::REASON_SUSPENDED,
                'Photo uploads have been suspended for your account.',
                403,
                ['can_upload' => false],
            );
        }

        if ($this->lifecycleBlocks($profile)) {
            return new ProfilePhotoUploadDenial(
                ProfilePhotoUploadDenial::REASON_LIFECYCLE_LOCKED,
                'Profile is locked for photo changes right now.',
                422,
                ['can_upload' => false],
            );
        }

        return null;
    }

    /**
     * Quantity gate — evaluated once the number of incoming files is known.
     *
     * @param  int  $incomingCount  1 for the legacy single-photo surfaces, N for the gallery.
     */
    public function denyForCount(MatrimonyProfile $profile, int $incomingCount): ?ProfilePhotoUploadDenial
    {
        $maxPerProfile = $this->maxPerProfile();
        $currentCount = $this->currentPhotoCount($profile);

        if (($currentCount + $incomingCount) <= $maxPerProfile) {
            return null;
        }

        return new ProfilePhotoUploadDenial(
            ProfilePhotoUploadDenial::REASON_MAX_PHOTOS,
            "You can upload up to {$maxPerProfile} photos. Delete one photo before uploading a new one.",
            422,
            [
                'max_photos' => $maxPerProfile,
                'current_photo_count' => $currentCount,
            ],
        );
    }
}
