<?php

namespace App\Modules\Suchak\Services;

use App\Models\AdminAuditLog;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakVerificationRecord;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SuchakAccountLifecycleService
{
    public function __construct(private readonly SuchakActivityLogger $activityLogger)
    {
    }

    public function approve(SuchakAccount $account, User $admin, string $reason, ?string $ipAddress = null, ?string $userAgent = null): SuchakAccount
    {
        $this->assertStatus($account, SuchakAccount::VERIFICATION_PENDING, 'Only pending Suchak accounts can be approved on Day-4.');

        return $this->transition(
            account: $account,
            admin: $admin,
            reason: $reason,
            actionType: 'suchak_account_approved',
            newVerificationStatus: SuchakAccount::VERIFICATION_VERIFIED,
            newPublicStatus: SuchakAccount::PUBLIC_HIDDEN,
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

    private function assertStatus(SuchakAccount $account, string $expectedStatus, string $message): void
    {
        $account->refresh();

        if ($account->verification_status !== $expectedStatus) {
            throw new InvalidArgumentException($message);
        }
    }

    /**
     * @param  array<string, string|null>  $oldValue
     * @param  array<string, string|null>  $newValue
     */
    private function writeAdminAuditLog(
        User $admin,
        string $actionType,
        SuchakAccount $account,
        string $reason,
        array $oldValue,
        array $newValue
    ): AdminAuditLog {
        return AuditLogService::log(
            $admin,
            $actionType,
            'SuchakAccount',
            $account->id,
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
}
