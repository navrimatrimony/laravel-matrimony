<?php

namespace App\Modules\Suchak\Services;

use App\Models\AdminAuditLog;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakConsent;
use App\Models\SuchakConsentEvent;
use App\Models\SuchakDirectPaymentEvidence;
use App\Models\SuchakDispute;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakPaymentFeatureFreeze;
use App\Models\SuchakPayoutHold;
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

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function openDirectPaymentComplaint(
        SuchakAccount $account,
        User $reporter,
        array $attributes,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakDispute {
        $paymentContext = $this->resolvePlatformPaymentContext($account, $reporter, $attributes['payment_context_id'] ?? null);
        if (! $paymentContext instanceof SuchakPaymentContext) {
            throw new InvalidArgumentException('Direct payment complaints require a platform payment context.');
        }

        $profile = $this->resolveReporterProfile($reporter, $paymentContext, $attributes['matrimony_profile_id'] ?? null);
        $customerContextId = $paymentContext->customer_context_id ?? ($attributes['customer_context_id'] ?? null);
        $customerContextId = $customerContextId === null || $customerContextId === '' ? null : (int) $customerContextId;
        if ($customerContextId !== null && (int) $customerContextId !== (int) $paymentContext->customer_context_id) {
            throw new InvalidArgumentException('Direct payment complaint customer context must match the platform payment context.');
        }

        $summary = $this->requiredText($attributes['summary'] ?? null, 'Direct payment complaint summary is required.', 1000);
        $evidenceType = $this->requiredAllowedValue(
            $attributes['evidence_type'] ?? null,
            SuchakDirectPaymentEvidence::TYPES,
            'Direct payment complaint evidence type is invalid.',
        );
        $evidenceNote = $this->requiredText($attributes['evidence_note'] ?? null, 'Direct payment complaint evidence note is required.', 2000);
        $evidenceReference = $this->nullableLimitedText($attributes['evidence_reference'] ?? null, 500);

        return DB::transaction(function () use (
            $account,
            $reporter,
            $paymentContext,
            $profile,
            $customerContextId,
            $summary,
            $evidenceType,
            $evidenceNote,
            $evidenceReference,
            $ipAddress,
            $userAgent,
        ): SuchakDispute {
            $dispute = SuchakDispute::query()->create([
                'suchak_account_id' => $account->id,
                'matrimony_profile_id' => $profile?->id ?? $paymentContext->matrimony_profile_id,
                'representation_id' => null,
                'customer_context_id' => $customerContextId,
                'payment_context_id' => $paymentContext->id,
                'opened_by_user_id' => $reporter->id,
                'assigned_admin_user_id' => null,
                'dispute_type' => SuchakDispute::TYPE_DIRECT_PAYMENT_REQUEST,
                'status' => SuchakDispute::STATUS_OPEN,
                'priority' => SuchakDispute::PRIORITY_HIGH,
                'risk_source' => SuchakDispute::RISK_SOURCE_CUSTOMER_DIRECT_PAYMENT_REPORT,
                'summary' => $summary,
                'evidence_summary' => 'Customer-submitted direct payment evidence is captured in structured evidence records.',
                'resolution_note' => null,
                'opened_at' => now(),
                'resolved_at' => null,
            ]);

            $evidence = SuchakDirectPaymentEvidence::query()->create([
                'suchak_dispute_id' => $dispute->id,
                'suchak_account_id' => $account->id,
                'customer_context_id' => $customerContextId,
                'payment_context_id' => $paymentContext->id,
                'submitted_by_user_id' => $reporter->id,
                'evidence_type' => $evidenceType,
                'evidence_reference' => $evidenceReference,
                'evidence_note' => $evidenceNote,
                'submitted_at' => now(),
                'created_at' => now(),
            ]);

            $this->recordCustomerRiskActivity(
                $dispute,
                $reporter,
                SuchakActivityLog::ACTION_DIRECT_PAYMENT_COMPLAINT_OPENED,
                'direct_payment_complaint_opened',
                $ipAddress,
                $userAgent,
                [
                    'payment_context_id' => $dispute->payment_context_id,
                    'customer_context_id' => $dispute->customer_context_id,
                    'risk_source' => $dispute->risk_source,
                ],
            );
            $this->recordEvidenceActivity($evidence, $reporter, $ipAddress, $userAgent);
            $this->createPayoutHoldHook(
                $dispute,
                $reporter,
                'Customer reported direct Suchak payment request for a platform-collected context.',
                $ipAddress,
                $userAgent,
            );

            return $dispute->fresh(['suchakAccount', 'paymentContext', 'customerContext', 'directPaymentEvidence', 'payoutHolds']);
        });
    }

    public function freezeDirectPaymentAbility(
        SuchakDispute $dispute,
        User $admin,
        string $reason,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakPaymentFeatureFreeze {
        $this->accessService->assertAdmin($admin, 'Only admins can freeze Suchak payment features.');
        $reason = $this->requiredText($reason, 'Suchak payment feature freeze reason is required.', 1000);

        return DB::transaction(function () use ($dispute, $admin, $reason, $ipAddress, $userAgent): SuchakPaymentFeatureFreeze {
            /** @var SuchakDispute $lockedDispute */
            $lockedDispute = SuchakDispute::query()
                ->whereKey($dispute->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($lockedDispute->status, self::REVIEWABLE_DISPUTE_STATUSES, true)) {
                throw new InvalidArgumentException('Only open or under-review Suchak payment risk disputes can be frozen.');
            }

            $existing = SuchakPaymentFeatureFreeze::query()
                ->where('suchak_dispute_id', $lockedDispute->id)
                ->where('freeze_status', SuchakPaymentFeatureFreeze::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof SuchakPaymentFeatureFreeze) {
                throw new InvalidArgumentException('Suchak payment feature is already frozen for this dispute.');
            }

            $adminAuditLog = $this->writeAdminAuditLog(
                $admin,
                'suchak_payment_feature_freeze_opened',
                $lockedDispute,
                $reason,
                ['payment_feature_freeze' => null],
                [
                    'suchak_account_id' => $lockedDispute->suchak_account_id,
                    'customer_context_id' => $lockedDispute->customer_context_id,
                    'payment_context_id' => $lockedDispute->payment_context_id,
                    'freeze_status' => SuchakPaymentFeatureFreeze::STATUS_ACTIVE,
                ],
            );

            $freeze = SuchakPaymentFeatureFreeze::query()->create([
                'suchak_dispute_id' => $lockedDispute->id,
                'suchak_account_id' => $lockedDispute->suchak_account_id,
                'customer_context_id' => $lockedDispute->customer_context_id,
                'payment_context_id' => $lockedDispute->payment_context_id,
                'freeze_scope' => $lockedDispute->customer_context_id === null
                    ? SuchakPaymentFeatureFreeze::SCOPE_ACCOUNT
                    : SuchakPaymentFeatureFreeze::SCOPE_CUSTOMER_CONTEXT,
                'freeze_status' => SuchakPaymentFeatureFreeze::STATUS_ACTIVE,
                'freeze_reason' => $reason,
                'created_by_admin_user_id' => $admin->id,
            ]);

            $freshFreeze = $freeze->fresh(['dispute', 'suchakAccount', 'customerContext', 'paymentContext']);
            $this->activityLogger->record([
                'suchak_account_id' => $freshFreeze->suchak_account_id,
                'actor_user_id' => $admin->id,
                'actor_type' => SuchakActivityLog::ACTOR_ADMIN,
                'action_type' => SuchakActivityLog::ACTION_PAYMENT_FEATURE_FREEZE_OPENED,
                'target_type' => 'suchak_payment_feature_freeze',
                'target_id' => $freshFreeze->id,
                'matrimony_profile_id' => $lockedDispute->matrimony_profile_id,
                'admin_audit_log_id' => $adminAuditLog->id,
                'ip_address' => $ipAddress,
                'user_agent' => Str::limit((string) $userAgent, 512, ''),
                'metadata_json' => [
                    'context' => 'payment_feature_freeze_opened',
                    'dispute_id' => $lockedDispute->id,
                    'customer_context_id' => $freshFreeze->customer_context_id,
                    'payment_context_id' => $freshFreeze->payment_context_id,
                    'freeze_scope' => $freshFreeze->freeze_scope,
                    'freeze_status' => $freshFreeze->freeze_status,
                ],
            ]);

            $this->createPayoutHoldHook(
                $lockedDispute,
                $admin,
                'Admin froze Suchak direct payment collection during payment risk review.',
                $ipAddress,
                $userAgent,
                $adminAuditLog,
            );

            return $freshFreeze;
        });
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

    private function resolvePlatformPaymentContext(SuchakAccount $account, User $reporter, mixed $paymentContextId): ?SuchakPaymentContext
    {
        if ($paymentContextId === null || $paymentContextId === '') {
            return null;
        }

        /** @var SuchakPaymentContext $context */
        $context = SuchakPaymentContext::query()
            ->with('matrimonyProfile')
            ->whereKey((int) $paymentContextId)
            ->firstOrFail();

        if ((int) $context->suchak_account_id !== (int) $account->id) {
            throw new InvalidArgumentException('Direct payment complaint payment context must belong to the selected Suchak account.');
        }

        if ($context->source_owner !== SuchakPaymentContext::SOURCE_PLATFORM
            || $context->payment_collector !== SuchakPaymentContext::COLLECTOR_PLATFORM) {
            throw new InvalidArgumentException('Only platform-collected Suchak customer contexts can open direct payment complaints.');
        }

        if ($context->matrimonyProfile instanceof MatrimonyProfile
            && (int) $context->matrimonyProfile->user_id !== (int) $reporter->id) {
            throw new InvalidArgumentException('Only the platform customer can report this Suchak payment context.');
        }

        return $context;
    }

    private function resolveReporterProfile(User $reporter, ?SuchakPaymentContext $paymentContext, mixed $profileId): ?MatrimonyProfile
    {
        if ($paymentContext?->matrimonyProfile instanceof MatrimonyProfile) {
            return $paymentContext->matrimonyProfile;
        }

        if ($profileId === null || $profileId === '') {
            return null;
        }

        /** @var MatrimonyProfile $profile */
        $profile = MatrimonyProfile::query()->whereKey((int) $profileId)->firstOrFail();
        if ((int) $profile->user_id !== (int) $reporter->id) {
            throw new InvalidArgumentException('Only the platform customer can report this profile payment risk.');
        }

        return $profile;
    }

    private function createPayoutHoldHook(
        SuchakDispute $dispute,
        User $actor,
        string $reason,
        ?string $ipAddress,
        ?string $userAgent,
        ?AdminAuditLog $adminAuditLog = null,
    ): SuchakPayoutHold {
        $existing = SuchakPayoutHold::query()
            ->where('suchak_dispute_id', $dispute->id)
            ->where('hold_status', SuchakPayoutHold::STATUS_ACTIVE)
            ->lockForUpdate()
            ->first();

        if ($existing instanceof SuchakPayoutHold) {
            return $existing->fresh(['dispute', 'suchakAccount', 'customerContext', 'paymentContext']);
        }

        $hold = SuchakPayoutHold::query()->create([
            'suchak_dispute_id' => $dispute->id,
            'suchak_account_id' => $dispute->suchak_account_id,
            'customer_context_id' => $dispute->customer_context_id,
            'payment_context_id' => $dispute->payment_context_id,
            'hold_scope' => SuchakPayoutHold::SCOPE_DIRECT_PAYMENT_RISK,
            'hold_status' => SuchakPayoutHold::STATUS_ACTIVE,
            'hold_reason' => $reason,
            'created_by_user_id' => $actor->id,
        ]);

        $fresh = $hold->fresh(['dispute', 'suchakAccount', 'customerContext', 'paymentContext']);
        $actorType = $adminAuditLog instanceof AdminAuditLog ? SuchakActivityLog::ACTOR_ADMIN : SuchakActivityLog::ACTOR_USER;

        $this->activityLogger->record([
            'suchak_account_id' => $fresh->suchak_account_id,
            'actor_user_id' => $actor->id,
            'actor_type' => $actorType,
            'action_type' => SuchakActivityLog::ACTION_PAYOUT_HOLD_OPENED,
            'target_type' => 'suchak_payout_hold',
            'target_id' => $fresh->id,
            'matrimony_profile_id' => $dispute->matrimony_profile_id,
            'admin_audit_log_id' => $adminAuditLog?->id,
            'ip_address' => $ipAddress,
            'user_agent' => Str::limit((string) $userAgent, 512, ''),
            'metadata_json' => [
                'context' => 'payout_hold_opened',
                'dispute_id' => $dispute->id,
                'customer_context_id' => $fresh->customer_context_id,
                'payment_context_id' => $fresh->payment_context_id,
                'hold_scope' => $fresh->hold_scope,
                'hold_status' => $fresh->hold_status,
            ],
        ]);

        return $fresh;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordCustomerRiskActivity(
        SuchakDispute $dispute,
        User $actor,
        string $actionType,
        string $context,
        ?string $ipAddress,
        ?string $userAgent,
        array $metadata = [],
    ): void {
        $this->activityLogger->record([
            'suchak_account_id' => $dispute->suchak_account_id,
            'actor_user_id' => $actor->id,
            'actor_type' => SuchakActivityLog::ACTOR_USER,
            'action_type' => $actionType,
            'target_type' => 'suchak_dispute',
            'target_id' => $dispute->id,
            'matrimony_profile_id' => $dispute->matrimony_profile_id,
            'ip_address' => $ipAddress,
            'user_agent' => Str::limit((string) $userAgent, 512, ''),
            'metadata_json' => array_merge([
                'context' => $context,
                'dispute_type' => $dispute->dispute_type,
                'status' => $dispute->status,
                'priority' => $dispute->priority,
            ], $metadata),
        ]);
    }

    private function recordEvidenceActivity(
        SuchakDirectPaymentEvidence $evidence,
        User $actor,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        $evidence->loadMissing('dispute');

        $this->activityLogger->record([
            'suchak_account_id' => $evidence->suchak_account_id,
            'actor_user_id' => $actor->id,
            'actor_type' => SuchakActivityLog::ACTOR_USER,
            'action_type' => SuchakActivityLog::ACTION_DIRECT_PAYMENT_EVIDENCE_ADDED,
            'target_type' => 'suchak_direct_payment_evidence',
            'target_id' => $evidence->id,
            'matrimony_profile_id' => $evidence->dispute?->matrimony_profile_id,
            'ip_address' => $ipAddress,
            'user_agent' => Str::limit((string) $userAgent, 512, ''),
            'metadata_json' => [
                'context' => 'direct_payment_evidence_added',
                'dispute_id' => $evidence->suchak_dispute_id,
                'customer_context_id' => $evidence->customer_context_id,
                'payment_context_id' => $evidence->payment_context_id,
                'evidence_type' => $evidence->evidence_type,
                'has_reference' => $evidence->evidence_reference !== null,
            ],
        ]);
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
    private function requiredAllowedValue(mixed $value, array $allowed, string $message): string
    {
        $normalized = trim((string) ($value ?? ''));
        if (! in_array($normalized, $allowed, true)) {
            throw new InvalidArgumentException($message);
        }

        return $normalized;
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

    private function requiredText(mixed $value, string $message, int $limit): string
    {
        $text = $this->nullableLimitedText($value, $limit);
        if ($text === null) {
            throw new InvalidArgumentException($message);
        }

        return $text;
    }

    private function nullableLimitedText(mixed $value, int $limit): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed === '' ? null : Str::limit($trimmed, $limit, '');
    }

    private function nullableTrim(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed !== '' ? $trimmed : null;
    }
}
