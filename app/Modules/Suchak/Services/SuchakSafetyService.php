<?php

namespace App\Modules\Suchak\Services;

use App\Models\AdminAuditLog;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakConsent;
use App\Models\SuchakConsentEvent;
use App\Models\SuchakDispute;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakQrToken;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SuchakSafetyService
{
    private const REVIEWABLE_DISPUTE_STATUSES = [
        SuchakDispute::STATUS_OPEN,
        SuchakDispute::STATUS_UNDER_REVIEW,
    ];

    public function __construct(
        private readonly SuchakActivityLogger $activityLogger,
        private readonly SuchakAccessService $accessService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function openDispute(
        SuchakAccount $account,
        User $admin,
        array $attributes,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakDispute {
        $this->accessService->assertAdmin($admin, 'Only admins can open Suchak disputes.');
        $this->assertAllowedValue((string) $attributes['dispute_type'], SuchakDispute::TYPES, 'Invalid Suchak dispute type.');
        $this->assertAllowedValue((string) $attributes['priority'], SuchakDispute::PRIORITIES, 'Invalid Suchak dispute priority.');

        return DB::transaction(function () use ($account, $admin, $attributes, $ipAddress, $userAgent): SuchakDispute {
            $account = SuchakAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $representation = $this->resolveAccountRepresentation($account, $attributes['representation_id'] ?? null);

            $dispute = SuchakDispute::query()->create([
                'suchak_account_id' => $account->id,
                'matrimony_profile_id' => $representation?->matrimony_profile_id ?? ($attributes['matrimony_profile_id'] ?? null),
                'representation_id' => $representation?->id,
                'opened_by_user_id' => $admin->id,
                'assigned_admin_user_id' => null,
                'dispute_type' => $attributes['dispute_type'],
                'status' => SuchakDispute::STATUS_OPEN,
                'priority' => $attributes['priority'],
                'summary' => trim((string) $attributes['summary']),
                'evidence_summary' => $this->nullableTrim($attributes['evidence_summary'] ?? null),
                'resolution_note' => null,
                'opened_at' => now(),
                'resolved_at' => null,
            ]);

            $adminAuditLog = $this->writeAdminAuditLog(
                $admin,
                'suchak_dispute_opened',
                $dispute,
                $dispute->summary,
                [],
                [
                    'status' => $dispute->status,
                    'dispute_type' => $dispute->dispute_type,
                    'priority' => $dispute->priority,
                ],
            );

            $this->recordAdminActivity(
                $dispute,
                $admin,
                $adminAuditLog,
                SuchakActivityLog::ACTION_DISPUTE_OPENED,
                $ipAddress,
                $userAgent,
                [
                    'dispute_type' => $dispute->dispute_type,
                    'status' => $dispute->status,
                    'priority' => $dispute->priority,
                    'representation_id' => $dispute->representation_id,
                    'has_evidence_summary' => $dispute->evidence_summary !== null,
                ],
            );

            return $dispute->fresh(['suchakAccount', 'representation']);
        });
    }

    public function startReview(
        SuchakDispute $dispute,
        User $admin,
        string $reason,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakDispute {
        return $this->transitionDispute(
            $dispute,
            $admin,
            SuchakDispute::STATUS_UNDER_REVIEW,
            $reason,
            $ipAddress,
            $userAgent,
        );
    }

    public function closeDispute(
        SuchakDispute $dispute,
        User $admin,
        string $closingStatus,
        string $resolutionNote,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakDispute {
        $this->assertAllowedValue($closingStatus, SuchakDispute::CLOSING_STATUSES, 'Invalid Suchak dispute closing status.');

        return $this->transitionDispute(
            $dispute,
            $admin,
            $closingStatus,
            $resolutionNote,
            $ipAddress,
            $userAgent,
        );
    }

    public function revokeRepresentation(
        SuchakProfileRepresentation $representation,
        User $admin,
        string $reason,
        ?int $linkedDisputeId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakProfileRepresentation {
        $this->accessService->assertAdmin($admin, 'Only admins can revoke Suchak representations.');

        return DB::transaction(function () use ($representation, $admin, $reason, $linkedDisputeId, $ipAddress, $userAgent): SuchakProfileRepresentation {
            $lockedRepresentation = SuchakProfileRepresentation::query()
                ->with('suchakAccount')
                ->whereKey($representation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRepresentation->representation_status === SuchakProfileRepresentation::STATUS_REVOKED) {
                throw new InvalidArgumentException('Suchak representation is already revoked.');
            }

            $linkedDispute = $this->resolveRepresentationDispute($lockedRepresentation, $linkedDisputeId);
            $revokedAt = now();
            $oldValue = [
                'representation_status' => $lockedRepresentation->representation_status,
                'consent_status' => $lockedRepresentation->consent_status,
                'revoked_at' => $lockedRepresentation->revoked_at?->toIso8601String(),
            ];
            $newValue = [
                'representation_status' => SuchakProfileRepresentation::STATUS_REVOKED,
                'consent_status' => SuchakProfileRepresentation::CONSENT_REVOKED,
                'revoked_at' => $revokedAt->toIso8601String(),
            ];

            $adminAuditLog = $this->writeAdminAuditLog(
                $admin,
                'suchak_representation_revoked',
                $lockedRepresentation,
                $reason,
                $oldValue,
                $newValue,
            );

            $lockedRepresentation->forceFill([
                'representation_status' => SuchakProfileRepresentation::STATUS_REVOKED,
                'consent_status' => SuchakProfileRepresentation::CONSENT_REVOKED,
                'revoked_at' => $revokedAt,
            ])->save();

            $revokedConsentIds = SuchakConsent::query()
                ->where('representation_id', $lockedRepresentation->id)
                ->where('consent_status', '!=', SuchakConsent::STATUS_REVOKED)
                ->lockForUpdate()
                ->pluck('id');

            if ($revokedConsentIds->isNotEmpty()) {
                SuchakConsent::query()
                    ->whereIn('id', $revokedConsentIds)
                    ->update([
                        'consent_status' => SuchakConsent::STATUS_REVOKED,
                        'revoked_at' => $revokedAt,
                        'revocation_reason' => $reason,
                    ]);

                foreach ($revokedConsentIds as $consentId) {
                    SuchakConsentEvent::query()->create([
                        'consent_id' => $consentId,
                        'event_type' => SuchakConsentEvent::EVENT_CONSENT_REVOKED,
                        'event_note' => $reason,
                        'actor_type' => SuchakConsentEvent::ACTOR_ADMIN,
                        'actor_id' => $admin->id,
                        'created_at' => $revokedAt,
                    ]);
                }
            }

            $revokedQrTokenCount = SuchakQrToken::query()
                ->where('representation_id', $lockedRepresentation->id)
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => $revokedAt,
                    'revoked_reason' => 'admin_representation_revoke',
                    'updated_at' => $revokedAt,
                ]);

            $this->activityLogger->record([
                'suchak_account_id' => $lockedRepresentation->suchak_account_id,
                'actor_user_id' => $admin->id,
                'actor_type' => SuchakActivityLog::ACTOR_ADMIN,
                'action_type' => SuchakActivityLog::ACTION_REPRESENTATION_REVOKED,
                'target_type' => 'suchak_profile_representation',
                'target_id' => $lockedRepresentation->id,
                'matrimony_profile_id' => $lockedRepresentation->matrimony_profile_id,
                'admin_audit_log_id' => $adminAuditLog->id,
                'ip_address' => $ipAddress,
                'user_agent' => Str::limit((string) $userAgent, 512, ''),
                'metadata_json' => [
                    'previous_representation_status' => $oldValue['representation_status'],
                    'new_representation_status' => $newValue['representation_status'],
                    'previous_consent_status' => $oldValue['consent_status'],
                    'new_consent_status' => $newValue['consent_status'],
                    'revoked_consent_count' => $revokedConsentIds->count(),
                    'revoked_qr_token_count' => $revokedQrTokenCount,
                    'linked_dispute_id' => $linkedDispute?->id,
                    'has_reason' => trim($reason) !== '',
                ],
            ]);

            return $lockedRepresentation->fresh(['suchakAccount', 'consents', 'qrTokens']);
        });
    }

    private function transitionDispute(
        SuchakDispute $dispute,
        User $admin,
        string $newStatus,
        string $reason,
        ?string $ipAddress,
        ?string $userAgent,
    ): SuchakDispute {
        $this->accessService->assertAdmin($admin, 'Only admins can change Suchak disputes.');
        $this->assertAllowedValue($newStatus, SuchakDispute::STATUSES, 'Invalid Suchak dispute status.');

        return DB::transaction(function () use ($dispute, $admin, $newStatus, $reason, $ipAddress, $userAgent): SuchakDispute {
            $lockedDispute = SuchakDispute::query()
                ->whereKey($dispute->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($lockedDispute->status, self::REVIEWABLE_DISPUTE_STATUSES, true)) {
                throw new InvalidArgumentException('Only open or under-review Suchak disputes can be changed.');
            }

            $oldValue = [
                'status' => $lockedDispute->status,
                'assigned_admin_user_id' => $lockedDispute->assigned_admin_user_id,
                'resolved_at' => $lockedDispute->resolved_at?->toIso8601String(),
            ];
            $newValue = [
                'status' => $newStatus,
                'assigned_admin_user_id' => $admin->id,
                'resolved_at' => in_array($newStatus, SuchakDispute::CLOSING_STATUSES, true) ? now()->toIso8601String() : null,
            ];

            $adminAuditLog = $this->writeAdminAuditLog(
                $admin,
                $newStatus === SuchakDispute::STATUS_UNDER_REVIEW ? 'suchak_dispute_under_review' : 'suchak_dispute_closed',
                $lockedDispute,
                $reason,
                $oldValue,
                $newValue,
            );

            $lockedDispute->forceFill([
                'status' => $newStatus,
                'assigned_admin_user_id' => $admin->id,
                'resolution_note' => in_array($newStatus, SuchakDispute::CLOSING_STATUSES, true) ? $reason : $lockedDispute->resolution_note,
                'resolved_at' => in_array($newStatus, SuchakDispute::CLOSING_STATUSES, true) ? now() : null,
            ])->save();

            $freshDispute = $lockedDispute->fresh(['suchakAccount', 'representation']);
            $this->recordAdminActivity(
                $freshDispute,
                $admin,
                $adminAuditLog,
                SuchakActivityLog::ACTION_DISPUTE_STATUS_CHANGED,
                $ipAddress,
                $userAgent,
                [
                    'from_status' => $oldValue['status'],
                    'to_status' => $freshDispute->status,
                    'dispute_type' => $freshDispute->dispute_type,
                    'priority' => $freshDispute->priority,
                    'has_resolution_note' => $freshDispute->resolution_note !== null,
                ],
            );

            return $freshDispute;
        });
    }

    private function resolveAccountRepresentation(SuchakAccount $account, mixed $representationId): ?SuchakProfileRepresentation
    {
        if ($representationId === null || $representationId === '') {
            return null;
        }

        $representation = SuchakProfileRepresentation::query()
            ->whereKey((int) $representationId)
            ->firstOrFail();

        if ((int) $representation->suchak_account_id !== (int) $account->id) {
            throw new InvalidArgumentException('Selected representation does not belong to this Suchak account.');
        }

        return $representation;
    }

    private function resolveRepresentationDispute(SuchakProfileRepresentation $representation, ?int $disputeId): ?SuchakDispute
    {
        if ($disputeId === null) {
            return null;
        }

        $dispute = SuchakDispute::query()->whereKey($disputeId)->firstOrFail();

        if ((int) $dispute->suchak_account_id !== (int) $representation->suchak_account_id) {
            throw new InvalidArgumentException('Linked dispute does not belong to this Suchak account.');
        }

        if ($dispute->representation_id !== null && (int) $dispute->representation_id !== (int) $representation->id) {
            throw new InvalidArgumentException('Linked dispute does not belong to this representation.');
        }

        return $dispute;
    }

    private function recordAdminActivity(
        SuchakDispute $dispute,
        User $admin,
        AdminAuditLog $adminAuditLog,
        string $actionType,
        ?string $ipAddress,
        ?string $userAgent,
        array $metadata
    ): void {
        $this->activityLogger->record([
            'suchak_account_id' => $dispute->suchak_account_id,
            'actor_user_id' => $admin->id,
            'actor_type' => SuchakActivityLog::ACTOR_ADMIN,
            'action_type' => $actionType,
            'target_type' => 'suchak_dispute',
            'target_id' => $dispute->id,
            'matrimony_profile_id' => $dispute->matrimony_profile_id,
            'admin_audit_log_id' => $adminAuditLog->id,
            'ip_address' => $ipAddress,
            'user_agent' => Str::limit((string) $userAgent, 512, ''),
            'metadata_json' => $metadata,
        ]);
    }

    /**
     * @param  array<string, mixed>  $oldValue
     * @param  array<string, mixed>  $newValue
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
            trim($reason).' | old='.json_encode($oldValue).' | new='.json_encode($newValue),
            false,
        );
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function assertAllowedValue(string $value, array $allowed, string $message): void
    {
        if (! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($message);
        }
    }

    private function nullableTrim(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed !== '' ? $trimmed : null;
    }
}
