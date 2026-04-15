<?php

namespace App\Services;

use App\Models\MatrimonyProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Governed admin path for matrimony profile soft delete (deleted_at via SoftDeletes).
 * Controllers must not call $profile->delete() directly for this moderation action.
 */
class AdminProfileSoftDeleteService
{
    /**
     * Soft-delete the profile and return data needed for audit logging and user notification.
     *
     * @return array{profile_id: int, owner: User|null, is_showcase: bool}
     */
    public static function perform(MatrimonyProfile $profile): array
    {
        return DB::transaction(function () use ($profile) {
            $profileId = $profile->id;
            $owner = $profile->user;
            $isShowcase = $profile->isShowcaseProfile();

            $profile->delete();

            return [
                'profile_id' => $profileId,
                'owner' => $owner,
                'is_showcase' => $isShowcase,
            ];
        });
    }
}
