<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TagAssignmentService
{
    public const ACTION_ASSIGNED = 'assigned';
    public const ACTION_REMOVED = 'removed';
    public const ACTION_RESTORED = 'restored';

    private const ALLOWED_ACTIONS = [
        self::ACTION_ASSIGNED,
        self::ACTION_REMOVED,
        self::ACTION_RESTORED,
    ];

    /**
     * Assign a verification tag to a matrimony profile.
     *
     * @param \App\Models\User $admin
     * @param \App\Models\MatrimonyProfile $matrimonyProfile
     * @param object $verificationTag Must have id, and tag must not be soft-deleted
     * @param string|null $reason
     * @return void
     * @throws ValidationException
     */
    public function assignTag($admin, $matrimonyProfile, $verificationTag, ?string $reason): void
    {
        $this->ensureCanManageVerificationTags($admin);
        $this->ensureReasonProvided($reason);
        $this->ensureTagNotSoftDeleted($verificationTag);

        $profileId = $matrimonyProfile->id;
        $tagId = $verificationTag->id;

        DB::transaction(function () use ($admin, $profileId, $tagId, $reason) {
            $existsActive = DB::table('profile_verification_tag')
                ->where('matrimony_profile_id', $profileId)
                ->where('verification_tag_id', $tagId)
                ->whereNull('deleted_at')
                ->exists();

            if ($existsActive) {
                throw ValidationException::withMessages([
                    'verification_tag_id' => ['This tag is already assigned to this profile.'],
                ]);
            }

            $existingSoftDeleted = DB::table('profile_verification_tag')
                ->where('matrimony_profile_id', $profileId)
                ->where('verification_tag_id', $tagId)
                ->whereNotNull('deleted_at')
                ->first();

            if ($existingSoftDeleted) {
                DB::table('profile_verification_tag')
                    ->where('id', $existingSoftDeleted->id)
                    ->update(['deleted_at' => null, 'updated_at' => now()]);
                $action = self::ACTION_RESTORED;
            } else {
                DB::table('profile_verification_tag')->insert([
                    'matrimony_profile_id' => $profileId,
                    'verification_tag_id' => $tagId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $action = self::ACTION_ASSIGNED;
            }

            $this->insertAudit($admin->id, $profileId, $tagId, $action, $reason);
        });
    }

    /**
     * Remove a verification tag from a matrimony profile (soft delete pivot + audit).
     *
     * @param \App\Models\User $admin
     * @param \App\Models\MatrimonyProfile $matrimonyProfile
     * @param object $verificationTag Must have id
     * @param string|null $reason
     * @return void
     * @throws ValidationException
     */
    public function removeTag($admin, $matrimonyProfile, $verificationTag, ?string $reason): void
    {
        $this->ensureCanManageVerificationTags($admin);
        $this->ensureReasonProvided($reason);

        $profileId = $matrimonyProfile->id;
        $tagId = $verificationTag->id;

        DB::transaction(function () use ($admin, $profileId, $tagId, $reason) {
            $updated = DB::table('profile_verification_tag')
                ->where('matrimony_profile_id', $profileId)
                ->where('verification_tag_id', $tagId)
                ->whereNull('deleted_at')
                ->update(['deleted_at' => now(), 'updated_at' => now()]);

            if ($updated === 0) {
                throw ValidationException::withMessages([
                    'verification_tag_id' => ['This tag is not currently assigned to this profile.'],
                ]);
            }

            $this->insertAudit($admin->id, $profileId, $tagId, self::ACTION_REMOVED, $reason);
        });
    }

    /**
     * Insert audit row. Action must be one of ACTION_ASSIGNED, ACTION_REMOVED, ACTION_RESTORED.
     */
    protected function insertAudit(int $adminId, int $profileId, int $tagId, string $action, string $reason): void
    {
        $this->ensureValidAction($action);
        DB::table('profile_verification_tag_audits')->insert([
            'matrimony_profile_id' => $profileId,
            'verification_tag_id' => $tagId,
            'action' => $action,
            'performed_by_admin_id' => $adminId,
            'reason' => $reason,
            'performed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function ensureValidAction(string $action): void
    {
        if (!in_array($action, self::ALLOWED_ACTIONS, true)) {
            throw new \InvalidArgumentException(
                'Invalid audit action. Allowed: ' . implode(', ', self::ALLOWED_ACTIONS)
            );
        }
    }

    protected function ensureCanManageVerificationTags($admin): void
    {
        if (!$admin) {
            throw ValidationException::withMessages([
                'admin' => ['You do not have permission to manage verification tags.'],
            ]);
        }

        if (method_exists($admin, 'isSuperAdmin') && $admin->isSuperAdmin()) {
            return;
        }

        $cap = DB::table('admin_capabilities')
            ->where('admin_id', $admin->id)
            ->first();

        if (!$cap || !$cap->can_manage_verification_tags) {
            throw ValidationException::withMessages([
                'admin' => ['You do not have permission to manage verification tags.'],
            ]);
        }
    }

    protected function ensureReasonProvided(?string $reason): void
    {
        if ($reason === null || trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => ['Reason is required for this action.'],
            ]);
        }
    }

    protected function ensureTagNotSoftDeleted($verificationTag): void
    {
        $tag = DB::table('verification_tags')
            ->where('id', $verificationTag->id)
            ->whereNull('deleted_at')
            ->exists();

        if (!$tag) {
            throw ValidationException::withMessages([
                'verification_tag_id' => ['The selected verification tag is not available or has been removed.'],
            ]);
        }
    }
}
