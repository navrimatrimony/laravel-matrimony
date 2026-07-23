<?php

namespace App\Modules\Suchak\Services;

use App\Models\AdminAuditLog;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakVerificationRecord;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class SuchakAccountLifecycleService
{
    public function __construct(private readonly SuchakActivityLogger $activityLogger)
    {
    }

    public function approve(SuchakAccount $account, User $admin, string $reason, ?string $ipAddress = null, ?string $userAgent = null, bool $publishOnApproval = false): SuchakAccount
    {
        $this->assertStatus($account, SuchakAccount::VERIFICATION_PENDING, 'Only pending Suchak accounts can be approved on Day-4.');

        return $this->transition(
            account: $account,
            admin: $admin,
            reason: $reason,
            actionType: 'suchak_account_approved',
            newVerificationStatus: SuchakAccount::VERIFICATION_VERIFIED,
            newPublicStatus: $publishOnApproval ? SuchakAccount::PUBLIC_ACTIVE : SuchakAccount::PUBLIC_HIDDEN,
            timestamps: [
                'verified_at' => now(),
                'rejected_at' => null,
                'suspended_at' => null,
                'suspension_reason' => null,
            ],
            verificationRecordStatus: SuchakVerificationRecord::STATUS_APPROVED,
            ipAddress: $ipAddress,
            userAgent: $userAgent,
        );
    }

    public function autoApproveAfterOtp(SuchakAccount $account, string $reason, ?string $ipAddress = null, ?string $userAgent = null, bool $publishOnApproval = false): SuchakAccount
    {
        $this->assertStatus($account, SuchakAccount::VERIFICATION_PENDING, 'Only pending Suchak accounts can be auto-approved after WhatsApp OTP verification.');

        return $this->systemTransition(
            account: $account,
            reason: $reason,
            actionType: SuchakActivityLog::ACTION_SUCHAK_AUTO_APPROVED,
            newVerificationStatus: SuchakAccount::VERIFICATION_VERIFIED,
            newPublicStatus: $publishOnApproval ? SuchakAccount::PUBLIC_ACTIVE : SuchakAccount::PUBLIC_HIDDEN,
            timestamps: [
                'verified_at' => now(),
                'rejected_at' => null,
                'suspended_at' => null,
                'suspension_reason' => null,
            ],
            verificationRecordStatus: SuchakVerificationRecord::STATUS_APPROVED,
            ipAddress: $ipAddress,
            userAgent: $userAgent,
        );
    }

    public function reject(SuchakAccount $account, User $admin, string $reason, ?string $ipAddress = null, ?string $userAgent = null): SuchakAccount
    {
        $this->assertStatus($account, SuchakAccount::VERIFICATION_PENDING, 'Only pending Suchak accounts can be rejected on Day-4.');

        return $this->transition(
            account: $account,
            admin: $admin,
            reason: $reason,
            actionType: 'suchak_account_rejected',
            newVerificationStatus: SuchakAccount::VERIFICATION_REJECTED,
            newPublicStatus: SuchakAccount::PUBLIC_HIDDEN,
            timestamps: [
                'verified_at' => null,
                'rejected_at' => now(),
                'suspended_at' => null,
                'suspension_reason' => null,
            ],
            verificationRecordStatus: SuchakVerificationRecord::STATUS_REJECTED,
            ipAddress: $ipAddress,
            userAgent: $userAgent,
        );
    }

    public function suspend(SuchakAccount $account, User $admin, string $reason, ?string $ipAddress = null, ?string $userAgent = null): SuchakAccount
    {
        $this->assertStatus($account, SuchakAccount::VERIFICATION_VERIFIED, 'Only verified Suchak accounts can be suspended on Day-4.');

        return $this->transition(
            account: $account,
            admin: $admin,
            reason: $reason,
            actionType: 'suchak_account_suspended',
            newVerificationStatus: SuchakAccount::VERIFICATION_SUSPENDED,
            newPublicStatus: SuchakAccount::PUBLIC_HIDDEN,
            timestamps: [
                'suspended_at' => now(),
                'suspension_reason' => $reason,
            ],
            verificationRecordStatus: null,
            ipAddress: $ipAddress,
            userAgent: $userAgent,
        );
    }

    public function archive(SuchakAccount $account, User $admin, string $reason, ?string $ipAddress = null, ?string $userAgent = null): SuchakAccount
    {
        $account->refresh();

        if (! in_array($account->verification_status, [
            SuchakAccount::VERIFICATION_VERIFIED,
            SuchakAccount::VERIFICATION_SUSPENDED,
        ], true)) {
            throw new InvalidArgumentException('Only verified or suspended Suchak accounts can be archived.');
        }

        return $this->transition(
            account: $account,
            admin: $admin,
            reason: $reason,
            actionType: 'suchak_account_archived',
            newVerificationStatus: SuchakAccount::VERIFICATION_ARCHIVED,
            newPublicStatus: SuchakAccount::PUBLIC_HIDDEN,
            timestamps: [
                'archived_at' => now(),
            ],
            verificationRecordStatus: null,
            ipAddress: $ipAddress,
            userAgent: $userAgent,
        );
    }

    public function reactivate(SuchakAccount $account, User $admin, string $reason, ?string $ipAddress = null, ?string $userAgent = null): SuchakAccount
    {
        $account->refresh();

        if ($account->verification_status === SuchakAccount::VERIFICATION_SUSPENDED) {
            return $this->transition(
                account: $account,
                admin: $admin,
                reason: $reason,
                actionType: 'suchak_account_reactivated',
                newVerificationStatus: SuchakAccount::VERIFICATION_VERIFIED,
                newPublicStatus: SuchakAccount::PUBLIC_HIDDEN,
                timestamps: [
                    'verified_at' => $account->verified_at ?? now(),
                    'suspended_at' => null,
                    'suspension_reason' => null,
                    'archived_at' => null,
                ],
                verificationRecordStatus: null,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );
        }

        if (in_array($account->verification_status, [
            SuchakAccount::VERIFICATION_ARCHIVED,
            SuchakAccount::VERIFICATION_REJECTED,
        ], true)) {
            return $this->transition(
                account: $account,
                admin: $admin,
                reason: $reason,
                actionType: 'suchak_account_reactivated_for_review',
                newVerificationStatus: SuchakAccount::VERIFICATION_PENDING,
                newPublicStatus: SuchakAccount::PUBLIC_HIDDEN,
                timestamps: [
                    'verified_at' => null,
                    'rejected_at' => null,
                    'suspended_at' => null,
                    'archived_at' => null,
                    'suspension_reason' => null,
                ],
                verificationRecordStatus: SuchakVerificationRecord::STATUS_PENDING,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );
        }

        throw new InvalidArgumentException('Only suspended, rejected, or archived Suchak accounts can be reactivated.');
    }

    public function updatePublicStatus(SuchakAccount $account, User $admin, string $publicStatus, string $reason, ?string $ipAddress = null, ?string $userAgent = null): SuchakAccount
    {
        $account->refresh();
        $this->assertPublicStatusTransition($account, $publicStatus);

        return $this->transition(
            account: $account,
            admin: $admin,
            reason: $reason,
            actionType: 'suchak_public_status_changed',
            newVerificationStatus: $account->verification_status,
            newPublicStatus: $publicStatus,
            timestamps: [],
            verificationRecordStatus: null,
            ipAddress: $ipAddress,
            userAgent: $userAgent,
        );
    }

    public function updateVerificationRecordStatus(
        SuchakVerificationRecord $verificationRecord,
        User $admin,
        string $adminStatus,
        string $reason,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakVerificationRecord {
        if (! in_array($adminStatus, [
            SuchakVerificationRecord::STATUS_PENDING,
            SuchakVerificationRecord::STATUS_APPROVED,
            SuchakVerificationRecord::STATUS_REJECTED,
        ], true)) {
            throw new InvalidArgumentException('Invalid Suchak verification record status.');
        }

        return DB::transaction(function () use ($verificationRecord, $admin, $adminStatus, $reason, $ipAddress, $userAgent): SuchakVerificationRecord {
            $verificationRecord->refresh();
            $account = $verificationRecord->suchakAccount()->firstOrFail();

            $oldValue = [
                'admin_status' => $verificationRecord->admin_status,
            ];
            $newValue = [
                'admin_status' => $adminStatus,
            ];

            $adminAuditLog = $this->writeAdminAuditLog(
                $admin,
                'suchak_verification_record_status_changed',
                $verificationRecord,
                $reason,
                $oldValue,
                $newValue,
            );

            $verificationRecord->forceFill([
                'admin_status' => $adminStatus,
                'admin_user_id' => $admin->id,
                'remarks' => $reason,
                'verified_at' => $adminStatus === SuchakVerificationRecord::STATUS_APPROVED ? now() : null,
                'rejected_at' => $adminStatus === SuchakVerificationRecord::STATUS_REJECTED ? now() : null,
            ])->save();

            $this->syncPublicProfilePhoto($verificationRecord, $account, $adminStatus);

            $this->activityLogger->record([
                'suchak_account_id' => $account->id,
                'actor_user_id' => $admin->id,
                'actor_type' => SuchakActivityLog::ACTOR_ADMIN,
                'action_type' => SuchakActivityLog::ACTION_ADMIN_AUDIT_LINKED,
                'target_type' => 'suchak_verification_record',
                'target_id' => $verificationRecord->id,
                'admin_audit_log_id' => $adminAuditLog->id,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'metadata_json' => [
                    'admin_action_type' => 'suchak_verification_record_status_changed',
                    'from_admin_status' => $oldValue['admin_status'],
                    'to_admin_status' => $newValue['admin_status'],
                ],
            ]);

            return $verificationRecord->fresh();
        });
    }

    private function syncPublicProfilePhoto(
        SuchakVerificationRecord $verificationRecord,
        SuchakAccount $account,
        string $adminStatus,
    ): void {
        if ($verificationRecord->verification_type !== SuchakVerificationRecord::TYPE_PROFILE_PHOTO) {
            return;
        }

        if ($adminStatus === SuchakVerificationRecord::STATUS_APPROVED) {
            $this->copyApprovedProfilePhotoToPublicDisk($verificationRecord, $account);

            return;
        }

        if ($adminStatus === SuchakVerificationRecord::STATUS_REJECTED) {
            $this->clearPublicProfilePhoto($account);
        }
    }

    /**
     * Used by automated AI-safe photo approval during registration upload.
     */
    public function publishApprovedProfilePhotoFromRecord(SuchakVerificationRecord $verificationRecord): void
    {
        $account = $verificationRecord->suchakAccount()->first();
        if (! $account) {
            return;
        }

        $this->copyApprovedProfilePhotoToPublicDisk($verificationRecord, $account);
    }

    private function clearPublicProfilePhoto(SuchakAccount $account): void
    {
        $publicPath = trim((string) $account->profile_photo_path);
        if ($publicPath !== '' && Storage::disk('public')->exists($publicPath)) {
            Storage::disk('public')->delete($publicPath);
        }

        $account->forceFill([
            'profile_photo_path' => null,
        ])->save();
    }

    private function copyApprovedProfilePhotoToPublicDisk(
        SuchakVerificationRecord $verificationRecord,
        SuchakAccount $account,
    ): void {
        $sourcePath = trim((string) $verificationRecord->document_path);
        if ($sourcePath === '' || ! Storage::disk('local')->exists($sourcePath)) {
            return;
        }

        $extension = strtolower((string) pathinfo($sourcePath, PATHINFO_EXTENSION));
        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $extension = 'jpg';
        }

        $publicPath = 'suchak/profile-photos/'.$account->id.'/approved-'.$verificationRecord->id.'.'.$extension;
        Storage::disk('public')->put($publicPath, Storage::disk('local')->get($sourcePath));

        $account->forceFill([
            'profile_photo_path' => $publicPath,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $timestamps
     */
    private function transition(
        SuchakAccount $account,
        User $admin,
        string $reason,
        string $actionType,
        string $newVerificationStatus,
        string $newPublicStatus,
        array $timestamps,
        ?string $verificationRecordStatus,
        ?string $ipAddress,
        ?string $userAgent,
    ): SuchakAccount {
        return DB::transaction(function () use (
            $account,
            $admin,
            $reason,
            $actionType,
            $newVerificationStatus,
            $newPublicStatus,
            $timestamps,
            $verificationRecordStatus,
            $ipAddress,
            $userAgent
        ): SuchakAccount {
            $account->refresh();

            $oldValue = [
                'verification_status' => $account->verification_status,
                'public_status' => $account->public_status,
            ];

            $newValue = [
                'verification_status' => $newVerificationStatus,
                'public_status' => $newPublicStatus,
            ];

            $adminAuditLog = $this->writeAdminAuditLog($admin, $actionType, $account, $reason, $oldValue, $newValue);

            $account->forceFill(array_merge($timestamps, [
                'verification_status' => $newVerificationStatus,
                'public_status' => $newPublicStatus,
            ]))->save();

            if ($verificationRecordStatus !== null) {
                $this->writeVerificationRecord($account, $admin, $reason, $verificationRecordStatus);
            }

            $this->activityLogger->record([
                'suchak_account_id' => $account->id,
                'actor_user_id' => $admin->id,
                'actor_type' => SuchakActivityLog::ACTOR_ADMIN,
                'action_type' => SuchakActivityLog::ACTION_ADMIN_AUDIT_LINKED,
                'target_type' => 'suchak_account',
                'target_id' => $account->id,
                'admin_audit_log_id' => $adminAuditLog->id,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'metadata_json' => [
                    'admin_action_type' => $actionType,
                    'from_verification_status' => $oldValue['verification_status'],
                    'to_verification_status' => $newValue['verification_status'],
                    'from_public_status' => $oldValue['public_status'],
                    'to_public_status' => $newValue['public_status'],
                ],
            ]);

            return $account->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $timestamps
     */
    private function systemTransition(
        SuchakAccount $account,
        string $reason,
        string $actionType,
        string $newVerificationStatus,
        string $newPublicStatus,
        array $timestamps,
        ?string $verificationRecordStatus,
        ?string $ipAddress,
        ?string $userAgent,
    ): SuchakAccount {
        return DB::transaction(function () use (
            $account,
            $reason,
            $actionType,
            $newVerificationStatus,
            $newPublicStatus,
            $timestamps,
            $verificationRecordStatus,
            $ipAddress,
            $userAgent
        ): SuchakAccount {
            $account->refresh();

            $oldValue = [
                'verification_status' => $account->verification_status,
                'public_status' => $account->public_status,
            ];

            $newValue = [
                'verification_status' => $newVerificationStatus,
                'public_status' => $newPublicStatus,
            ];

            $account->forceFill(array_merge($timestamps, [
                'verification_status' => $newVerificationStatus,
                'public_status' => $newPublicStatus,
            ]))->save();

            if ($verificationRecordStatus !== null) {
                $this->writeSystemVerificationRecord($account, $reason, $verificationRecordStatus);
            }

            $this->activityLogger->record([
                'suchak_account_id' => $account->id,
                'actor_user_id' => null,
                'actor_type' => SuchakActivityLog::ACTOR_SYSTEM,
                'action_type' => $actionType,
                'target_type' => 'suchak_account',
                'target_id' => $account->id,
                'admin_audit_log_id' => null,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'metadata_json' => [
                    'system_action_type' => $actionType,
                    'reason' => $reason,
                    'from_verification_status' => $oldValue['verification_status'],
                    'to_verification_status' => $newValue['verification_status'],
                    'from_public_status' => $oldValue['public_status'],
                    'to_public_status' => $newValue['public_status'],
                ],
            ]);

            return $account->fresh();
        });
    }

    private function assertStatus(SuchakAccount $account, string $expectedStatus, string $message): void
    {
        $account->refresh();

        if ($account->verification_status !== $expectedStatus) {
            throw new InvalidArgumentException($message);
        }
    }

    private function assertPublicStatusTransition(SuchakAccount $account, string $newPublicStatus): void
    {
        if (! in_array($newPublicStatus, [
            SuchakAccount::PUBLIC_HIDDEN,
            SuchakAccount::PUBLIC_ACTIVE,
            SuchakAccount::PUBLIC_INACTIVE,
        ], true)) {
            throw new InvalidArgumentException('Invalid Suchak public status.');
        }

        if ($newPublicStatus === $account->public_status) {
            return;
        }

        if ($newPublicStatus === SuchakAccount::PUBLIC_HIDDEN) {
            return;
        }

        if ($newPublicStatus === SuchakAccount::PUBLIC_ACTIVE && $account->verification_status === SuchakAccount::VERIFICATION_VERIFIED) {
            return;
        }

        if ($account->public_status === SuchakAccount::PUBLIC_ACTIVE && $newPublicStatus === SuchakAccount::PUBLIC_INACTIVE) {
            return;
        }

        throw new InvalidArgumentException('This Suchak public status transition is not allowed.');
    }

    /**
     * @param  array<string, string|null>  $oldValue
     * @param  array<string, string|null>  $newValue
     */
    private function writeAdminAuditLog(
        User $admin,
        string $actionType,
        object $entity,
        string $reason,
        array $oldValue,
        array $newValue
    ): AdminAuditLog {
        return AuditLogService::log(
            $admin,
            $actionType,
            class_basename($entity),
            $entity->id,
            $reason.' | old='.json_encode($oldValue).' | new='.json_encode($newValue),
            false
        );
    }

    private function writeVerificationRecord(
        SuchakAccount $account,
        User $admin,
        string $reason,
        string $adminStatus
    ): void {
        SuchakVerificationRecord::query()->create([
            'suchak_account_id' => $account->id,
            'verification_type' => SuchakVerificationRecord::TYPE_OTHER,
            'document_path' => null,
            'admin_status' => $adminStatus,
            'admin_user_id' => $admin->id,
            'remarks' => $reason,
            'verified_at' => $adminStatus === SuchakVerificationRecord::STATUS_APPROVED ? now() : null,
            'rejected_at' => $adminStatus === SuchakVerificationRecord::STATUS_REJECTED ? now() : null,
        ]);
    }

    private function writeSystemVerificationRecord(
        SuchakAccount $account,
        string $reason,
        string $adminStatus
    ): void {
        SuchakVerificationRecord::query()->create([
            'suchak_account_id' => $account->id,
            'verification_type' => SuchakVerificationRecord::TYPE_OTHER,
            'document_path' => null,
            'admin_status' => $adminStatus,
            'admin_user_id' => null,
            'remarks' => $reason,
            'verified_at' => $adminStatus === SuchakVerificationRecord::STATUS_APPROVED ? now() : null,
            'rejected_at' => $adminStatus === SuchakVerificationRecord::STATUS_REJECTED ? now() : null,
        ]);
    }
}
