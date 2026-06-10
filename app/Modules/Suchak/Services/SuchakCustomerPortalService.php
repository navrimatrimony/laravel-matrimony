<?php

namespace App\Modules\Suchak\Services;

use App\Models\SuchakActivityLog;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakCustomerFamilyMember;
use App\Models\SuchakCustomerPortalEvent;
use App\Models\SuchakCustomerPortalLink;
use App\Models\SuchakPaymentRequest;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SuchakCustomerPortalService
{
    public function __construct(
        private readonly SuchakAccessService $accessService,
        private readonly SuchakActivityLogger $activityLogger,
    ) {
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function addFamilyMember(
        SuchakCustomerContext $context,
        User $actor,
        array $attributes,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakCustomerFamilyMember {
        $context->refresh()->loadMissing('suchakAccount');
        $this->assertOwnerCanManage($context, $actor);

        $linkedProfileId = $attributes['linked_matrimony_profile_id'] ?? null;
        if ($linkedProfileId !== null
            && $context->candidate_matrimony_profile_id !== null
            && (int) $linkedProfileId !== (int) $context->candidate_matrimony_profile_id) {
            throw new InvalidArgumentException('Suchak customer family member profile must match the customer context candidate.');
        }

        $memberRole = $this->allowedValue(
            $attributes['member_role'] ?? SuchakCustomerFamilyMember::ROLE_FAMILY_MEMBER,
            SuchakCustomerFamilyMember::ROLES,
            'Suchak customer family member role is invalid.',
        );
        $payerRole = $this->allowedValue(
            $attributes['payer_role'] ?? SuchakCustomerFamilyMember::PAYER_NONE,
            SuchakCustomerFamilyMember::PAYER_ROLES,
            'Suchak customer family payer role is invalid.',
        );
        $relationship = $this->requiredText(
            $attributes['relationship_to_candidate'] ?? null,
            'Suchak customer family relationship is required.',
            80,
        );
        $displayName = $this->limitedPrivateSafeText($attributes['display_name'] ?? null, 160);

        return DB::transaction(function () use (
            $context,
            $actor,
            $attributes,
            $memberRole,
            $payerRole,
            $relationship,
            $displayName,
            $linkedProfileId,
            $ipAddress,
            $userAgent,
        ): SuchakCustomerFamilyMember {
            $member = SuchakCustomerFamilyMember::query()->create([
                'suchak_account_id' => $context->suchak_account_id,
                'customer_context_id' => $context->id,
                'linked_user_id' => $attributes['linked_user_id'] ?? null,
                'linked_matrimony_profile_id' => $linkedProfileId,
                'member_role' => $memberRole,
                'payer_role' => $payerRole,
                'relationship_to_candidate' => $relationship,
                'display_name' => $displayName,
                'access_status' => SuchakCustomerFamilyMember::STATUS_ACTIVE,
                'added_by_user_id' => $actor->id,
            ]);

            $fresh = $member->fresh($this->familyRelations());
            $this->recordFamilyActivity(
                $fresh,
                SuchakActivityLog::ACTION_CUSTOMER_FAMILY_MEMBER_LINKED,
                'customer_family_member_linked',
                $actor,
                $ipAddress,
                $userAgent,
            );

            return $fresh;
        });
    }

    public function revokeFamilyMember(
        SuchakCustomerFamilyMember $member,
        User $actor,
        string $reason,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakCustomerFamilyMember {
        $member->refresh()->loadMissing($this->familyRelations());
        $this->assertOwnerCanManage($member->customerContext, $actor);
        $reason = $this->requiredText($reason, 'Suchak customer family member revocation reason is required.', 1000);

        return DB::transaction(function () use ($member, $actor, $reason, $ipAddress, $userAgent): SuchakCustomerFamilyMember {
            /** @var SuchakCustomerFamilyMember $locked */
            $locked = SuchakCustomerFamilyMember::query()
                ->whereKey($member->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->access_status !== SuchakCustomerFamilyMember::STATUS_ACTIVE) {
                throw new InvalidArgumentException('Only active Suchak customer family members can be revoked.');
            }

            $locked->forceFill([
                'access_status' => SuchakCustomerFamilyMember::STATUS_REVOKED,
                'revoked_by_user_id' => $actor->id,
                'revoked_at' => now(),
                'revocation_reason' => $reason,
            ])->save();

            $fresh = $locked->fresh($this->familyRelations());
            $this->recordFamilyActivity(
                $fresh,
                SuchakActivityLog::ACTION_CUSTOMER_FAMILY_MEMBER_REVOKED,
                'customer_family_member_revoked',
                $actor,
                $ipAddress,
                $userAgent,
            );

            return $fresh;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{portal_link: SuchakCustomerPortalLink, portal_url: string, plain_token: string}
     */
    public function issuePaymentPortalLink(
        SuchakPaymentRequest $paymentRequest,
        User $actor,
        array $attributes = [],
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        $paymentRequest->refresh()->loadMissing([
            'suchakAccount',
            'customerContext',
            'servicePackage',
            'customerAgreement',
            'customerPayments.documents',
        ]);

        $this->accessService->assertOwnerCanOperate(
            $paymentRequest->suchakAccount,
            $actor,
            'Only the owning Suchak account can issue customer portal links.',
            'Only verified Suchak accounts can issue customer portal links.',
        );

        if ($paymentRequest->customer_context_id === null) {
            throw new InvalidArgumentException('Suchak customer portal links require a structured customer context.');
        }

        if (in_array($paymentRequest->payment_status, [
            SuchakPaymentRequest::STATUS_CANCELLED,
            SuchakPaymentRequest::STATUS_EXPIRED,
            SuchakPaymentRequest::STATUS_FAILED,
        ], true)) {
            throw new InvalidArgumentException('Suchak customer portal links require an active payment request.');
        }

        [$plainToken, $tokenHash] = $this->uniqueTokenPair();
        $expiresAt = $this->futureExpiry($attributes['expires_at'] ?? $paymentRequest->expires_at ?? now()->addDays(14));
        $recipientRole = $this->allowedValue(
            $attributes['recipient_role'] ?? SuchakCustomerPortalLink::RECIPIENT_PAYER,
            SuchakCustomerPortalLink::RECIPIENT_ROLES,
            'Suchak customer portal recipient role is invalid.',
        );
        $recipientLabel = $this->limitedPrivateSafeText($attributes['recipient_label'] ?? 'Customer family', 160);

        $familyMemberId = $attributes['customer_family_member_id'] ?? null;
        if ($familyMemberId !== null) {
            $familyMember = SuchakCustomerFamilyMember::query()
                ->whereKey($familyMemberId)
                ->where('customer_context_id', $paymentRequest->customer_context_id)
                ->where('suchak_account_id', $paymentRequest->suchak_account_id)
                ->first();

            if (! $familyMember instanceof SuchakCustomerFamilyMember) {
                throw new InvalidArgumentException('Suchak customer portal family member must belong to the same customer context.');
            }
        }

        $portalLink = DB::transaction(function () use (
            $paymentRequest,
            $actor,
            $tokenHash,
            $expiresAt,
            $recipientRole,
            $recipientLabel,
            $familyMemberId,
            $ipAddress,
            $userAgent,
        ): SuchakCustomerPortalLink {
            $link = SuchakCustomerPortalLink::query()->create([
                'suchak_account_id' => $paymentRequest->suchak_account_id,
                'customer_context_id' => $paymentRequest->customer_context_id,
                'payment_request_id' => $paymentRequest->id,
                'customer_family_member_id' => $familyMemberId,
                'issued_by_user_id' => $actor->id,
                'token_hash' => $tokenHash,
                'portal_status' => SuchakCustomerPortalLink::STATUS_ACTIVE,
                'recipient_role' => $recipientRole,
                'recipient_label' => $recipientLabel,
                'expires_at' => $expiresAt,
            ]);

            $fresh = $link->fresh($this->portalRelations());
            $this->recordPortalEvent(
                $fresh,
                SuchakCustomerPortalEvent::EVENT_LINK_ISSUED,
                SuchakActivityLog::ACTOR_SUCHAK,
                $actor,
                null,
                SuchakCustomerPortalLink::STATUS_ACTIVE,
                'Customer portal link issued.',
            );
            $this->recordPortalActivity(
                $fresh,
                SuchakActivityLog::ACTION_CUSTOMER_PORTAL_LINK_ISSUED,
                'customer_portal_link_issued',
                SuchakActivityLog::ACTOR_SUCHAK,
                $actor,
                $ipAddress,
                $userAgent,
            );

            return $fresh;
        });

        return [
            'portal_link' => $portalLink,
            'portal_url' => $this->portalUrl($plainToken),
            'plain_token' => $plainToken,
        ];
    }

    public function openPortalLink(
        string $plainToken,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakCustomerPortalLink {
        $tokenHash = $this->tokenHash($plainToken);

        /** @var SuchakCustomerPortalLink|null $link */
        $link = SuchakCustomerPortalLink::query()
            ->where('token_hash', $tokenHash)
            ->with($this->portalRelations())
            ->first();

        if (! $link instanceof SuchakCustomerPortalLink) {
            throw new InvalidArgumentException('Suchak customer portal link is invalid.');
        }

        $this->accessService->assertCanOperate(
            $link->suchakAccount,
            'Suchak customer portal is not available.',
        );

        if ($link->portal_status === SuchakCustomerPortalLink::STATUS_REVOKED) {
            throw new InvalidArgumentException('Suchak customer portal link has been revoked.');
        }

        if ($link->portal_status === SuchakCustomerPortalLink::STATUS_EXPIRED || $link->isExpired()) {
            $this->expirePortalLink($link);

            throw new InvalidArgumentException('Suchak customer portal link has expired.');
        }

        if ($link->opened_at !== null) {
            return $link->fresh($this->portalRelations());
        }

        return DB::transaction(function () use ($link, $ipAddress, $userAgent): SuchakCustomerPortalLink {
            /** @var SuchakCustomerPortalLink $locked */
            $locked = SuchakCustomerPortalLink::query()
                ->whereKey($link->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->opened_at !== null) {
                return $locked->fresh($this->portalRelations());
            }

            if ($locked->portal_status === SuchakCustomerPortalLink::STATUS_REVOKED) {
                throw new InvalidArgumentException('Suchak customer portal link has been revoked.');
            }

            $locked->forceFill(['opened_at' => now()])->save();
            $fresh = $locked->fresh($this->portalRelations());
            $this->recordPortalEvent(
                $fresh,
                SuchakCustomerPortalEvent::EVENT_LINK_OPENED,
                SuchakActivityLog::ACTOR_USER,
                null,
                $fresh->portal_status,
                $fresh->portal_status,
                'Customer portal link opened.',
            );
            $this->recordPortalActivity(
                $fresh,
                SuchakActivityLog::ACTION_CUSTOMER_PORTAL_LINK_OPENED,
                'customer_portal_link_opened',
                SuchakActivityLog::ACTOR_USER,
                null,
                $ipAddress,
                $userAgent,
            );

            return $fresh;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function claimPortalLink(
        SuchakCustomerPortalLink $link,
        array $attributes,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakCustomerPortalLink {
        $link->refresh()->loadMissing($this->portalRelations());
        $this->assertPortalClaimable($link);

        $claimedName = $this->requiredText($attributes['claimed_name'] ?? null, 'Suchak customer portal claim name is required.', 160);
        $relationship = $this->requiredText($attributes['claimed_relationship_to_candidate'] ?? null, 'Suchak customer portal relationship is required.', 80);

        return DB::transaction(function () use ($link, $claimedName, $relationship, $ipAddress, $userAgent): SuchakCustomerPortalLink {
            /** @var SuchakCustomerPortalLink $locked */
            $locked = SuchakCustomerPortalLink::query()
                ->whereKey($link->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertPortalClaimable($locked);

            $fromStatus = $locked->portal_status;
            $locked->forceFill([
                'portal_status' => SuchakCustomerPortalLink::STATUS_CLAIMED,
                'claimed_at' => now(),
                'claimed_name' => $claimedName,
                'claimed_relationship_to_candidate' => $relationship,
            ])->save();

            $fresh = $locked->fresh($this->portalRelations());
            $this->recordPortalEvent(
                $fresh,
                SuchakCustomerPortalEvent::EVENT_LINK_CLAIMED,
                SuchakActivityLog::ACTOR_USER,
                null,
                $fromStatus,
                SuchakCustomerPortalLink::STATUS_CLAIMED,
                'Customer portal link claimed.',
            );
            $this->recordPortalActivity(
                $fresh,
                SuchakActivityLog::ACTION_CUSTOMER_PORTAL_LINK_CLAIMED,
                'customer_portal_link_claimed',
                SuchakActivityLog::ACTOR_USER,
                null,
                $ipAddress,
                $userAgent,
            );

            return $fresh;
        });
    }

    public function revokePortalLink(
        SuchakCustomerPortalLink $link,
        ?User $actor,
        string $reason,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakCustomerPortalLink {
        $link->refresh()->loadMissing($this->portalRelations());
        $reason = $this->requiredText($reason, 'Suchak customer portal revoke reason is required.', 1000);

        return DB::transaction(function () use ($link, $actor, $reason, $ipAddress, $userAgent): SuchakCustomerPortalLink {
            /** @var SuchakCustomerPortalLink $locked */
            $locked = SuchakCustomerPortalLink::query()
                ->whereKey($link->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->portal_status === SuchakCustomerPortalLink::STATUS_REVOKED) {
                throw new InvalidArgumentException('Suchak customer portal link has already been revoked.');
            }

            if ($locked->portal_status === SuchakCustomerPortalLink::STATUS_EXPIRED) {
                throw new InvalidArgumentException('Expired Suchak customer portal links cannot be revoked.');
            }

            $fromStatus = $locked->portal_status;
            $locked->forceFill([
                'portal_status' => SuchakCustomerPortalLink::STATUS_REVOKED,
                'revoked_by_user_id' => $actor?->id,
                'revoked_at' => now(),
                'revoke_reason' => $reason,
            ])->save();

            $fresh = $locked->fresh($this->portalRelations());
            $actorType = $actor instanceof User ? SuchakActivityLog::ACTOR_SUCHAK : SuchakActivityLog::ACTOR_USER;
            $this->recordPortalEvent(
                $fresh,
                SuchakCustomerPortalEvent::EVENT_LINK_REVOKED,
                $actorType,
                $actor,
                $fromStatus,
                SuchakCustomerPortalLink::STATUS_REVOKED,
                'Customer portal link revoked.',
            );
            $this->recordPortalActivity(
                $fresh,
                SuchakActivityLog::ACTION_CUSTOMER_PORTAL_LINK_REVOKED,
                'customer_portal_link_revoked',
                $actorType,
                $actor,
                $ipAddress,
                $userAgent,
            );

            return $fresh;
        });
    }

    public function portalUrl(string $plainToken): string
    {
        return route('suchak.customer-portal.show', ['token' => $plainToken], true);
    }

    private function expirePortalLink(SuchakCustomerPortalLink $link): SuchakCustomerPortalLink
    {
        return DB::transaction(function () use ($link): SuchakCustomerPortalLink {
            /** @var SuchakCustomerPortalLink $locked */
            $locked = SuchakCustomerPortalLink::query()
                ->whereKey($link->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->portal_status === SuchakCustomerPortalLink::STATUS_EXPIRED) {
                return $locked->fresh($this->portalRelations());
            }

            if ($locked->portal_status === SuchakCustomerPortalLink::STATUS_REVOKED) {
                return $locked->fresh($this->portalRelations());
            }

            $fromStatus = $locked->portal_status;
            $locked->forceFill(['portal_status' => SuchakCustomerPortalLink::STATUS_EXPIRED])->save();
            $fresh = $locked->fresh($this->portalRelations());
            $this->recordPortalEvent(
                $fresh,
                SuchakCustomerPortalEvent::EVENT_LINK_EXPIRED,
                SuchakActivityLog::ACTOR_SYSTEM,
                null,
                $fromStatus,
                SuchakCustomerPortalLink::STATUS_EXPIRED,
                'Customer portal link expired.',
            );
            $this->recordPortalActivity(
                $fresh,
                SuchakActivityLog::ACTION_CUSTOMER_PORTAL_LINK_EXPIRED,
                'customer_portal_link_expired',
                SuchakActivityLog::ACTOR_SYSTEM,
                null,
                null,
                null,
            );

            return $fresh;
        });
    }

    private function assertOwnerCanManage(SuchakCustomerContext $context, User $actor): void
    {
        $context->loadMissing('suchakAccount');
        $this->accessService->assertOwnerCanOperate(
            $context->suchakAccount,
            $actor,
            'Only the owning Suchak account can manage customer portal context.',
            'Only verified Suchak accounts can manage customer portal context.',
        );
    }

    private function assertPortalClaimable(SuchakCustomerPortalLink $link): void
    {
        if ($link->portal_status !== SuchakCustomerPortalLink::STATUS_ACTIVE) {
            throw new InvalidArgumentException('Only active Suchak customer portal links can be claimed.');
        }

        if ($link->isExpired()) {
            $this->expirePortalLink($link);

            throw new InvalidArgumentException('Suchak customer portal link has expired.');
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function uniqueTokenPair(): array
    {
        do {
            $plainToken = Str::random(64);
            $tokenHash = hash('sha256', $plainToken);
        } while (SuchakCustomerPortalLink::query()->where('token_hash', $tokenHash)->exists());

        return [$plainToken, $tokenHash];
    }

    private function tokenHash(string $plainToken): string
    {
        $token = trim($plainToken);
        if (! preg_match('/^[A-Za-z0-9]{64}$/', $token)) {
            throw new InvalidArgumentException('Suchak customer portal link is invalid.');
        }

        return hash('sha256', $token);
    }

    private function futureExpiry(mixed $value): Carbon
    {
        $expiresAt = $value instanceof Carbon ? $value : Carbon::parse($value);

        if ($expiresAt->isPast()) {
            throw new InvalidArgumentException('Suchak customer portal link expiry must be in the future.');
        }

        return $expiresAt;
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function allowedValue(mixed $value, array $allowed, string $message): string
    {
        $normalized = trim((string) ($value ?? ''));
        if (! in_array($normalized, $allowed, true)) {
            throw new InvalidArgumentException($message);
        }

        return $normalized;
    }

    private function requiredText(mixed $value, string $message, int $limit): string
    {
        $normalized = $this->limitedPrivateSafeText($value, $limit);
        if ($normalized === null) {
            throw new InvalidArgumentException($message);
        }

        return $normalized;
    }

    private function limitedPrivateSafeText(mixed $value, int $limit): ?string
    {
        $normalized = trim((string) ($value ?? ''));
        if ($normalized === '') {
            return null;
        }

        $normalized = Str::limit($normalized, $limit, '');
        $this->assertNoPrivateContactText($normalized);

        return $normalized;
    }

    private function assertNoPrivateContactText(string $value): void
    {
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $value) === 1
            || preg_match('/(?<!\d)(?:\+?91[\s-]*)?[6-9]\d(?:[\s-]?\d){8}(?!\d)/', $value) === 1) {
            throw new InvalidArgumentException('Suchak customer portal records must not store private contact details.');
        }
    }

    private function recordPortalEvent(
        SuchakCustomerPortalLink $link,
        string $eventType,
        string $actorType,
        ?User $actor,
        ?string $fromStatus,
        ?string $toStatus,
        ?string $note,
    ): void {
        SuchakCustomerPortalEvent::query()->create([
            'customer_portal_link_id' => $link->id,
            'suchak_account_id' => $link->suchak_account_id,
            'customer_context_id' => $link->customer_context_id,
            'event_type' => $eventType,
            'actor_type' => $actorType,
            'actor_user_id' => $actor?->id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'event_note' => $note,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }

    private function recordPortalActivity(
        SuchakCustomerPortalLink $link,
        string $actionType,
        string $context,
        string $actorType,
        ?User $actor,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        $link->loadMissing('customerContext');

        $this->activityLogger->record([
            'suchak_account_id' => $link->suchak_account_id,
            'actor_user_id' => $actor?->id,
            'actor_type' => $actorType,
            'action_type' => $actionType,
            'target_type' => 'suchak_customer_portal_link',
            'target_id' => $link->id,
            'matrimony_profile_id' => $link->customerContext?->candidate_matrimony_profile_id,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent === null ? null : Str::limit($userAgent, 512, ''),
            'metadata_json' => [
                'context' => $context,
                'customer_context_id' => $link->customer_context_id,
                'payment_request_id' => $link->payment_request_id,
                'customer_family_member_id' => $link->customer_family_member_id,
                'portal_status' => $link->portal_status,
                'recipient_role' => $link->recipient_role,
                'expires_at' => $link->expires_at?->toIso8601String(),
                'opened_at' => $link->opened_at?->toIso8601String(),
                'claimed_at' => $link->claimed_at?->toIso8601String(),
                'revoked_at' => $link->revoked_at?->toIso8601String(),
            ],
        ]);
    }

    private function recordFamilyActivity(
        SuchakCustomerFamilyMember $member,
        string $actionType,
        string $context,
        User $actor,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        $member->loadMissing('customerContext');

        $this->activityLogger->record([
            'suchak_account_id' => $member->suchak_account_id,
            'actor_user_id' => $actor->id,
            'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
            'action_type' => $actionType,
            'target_type' => 'suchak_customer_family_member',
            'target_id' => $member->id,
            'matrimony_profile_id' => $member->customerContext?->candidate_matrimony_profile_id,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent === null ? null : Str::limit($userAgent, 512, ''),
            'metadata_json' => [
                'context' => $context,
                'customer_context_id' => $member->customer_context_id,
                'linked_user_id' => $member->linked_user_id,
                'linked_matrimony_profile_id' => $member->linked_matrimony_profile_id,
                'member_role' => $member->member_role,
                'payer_role' => $member->payer_role,
                'access_status' => $member->access_status,
                'revoked_at' => $member->revoked_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function familyRelations(): array
    {
        return [
            'suchakAccount',
            'customerContext',
            'linkedUser',
            'linkedProfile',
            'addedByUser',
            'revokedByUser',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function portalRelations(): array
    {
        return [
            'suchakAccount',
            'customerContext.familyMembers',
            'paymentRequest.servicePackage.stages',
            'paymentRequest.servicePackage.deliverables',
            'paymentRequest.customerAgreement.stages',
            'paymentRequest.customerAgreement.deliverables',
            'paymentRequest.customerPayments.documents',
            'paymentRequest.customerPayments.corrections',
            'paymentRequest.customerPayments.overdueServiceActions',
            'paymentRequest.customerPaymentCorrections',
            'paymentRequest.overdueServiceActions',
            'familyMember',
            'events',
        ];
    }
}
