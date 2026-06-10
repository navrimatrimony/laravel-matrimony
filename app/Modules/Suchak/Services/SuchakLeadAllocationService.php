<?php

namespace App\Modules\Suchak\Services;

use App\Models\AdminAuditLog;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakLeadAllocationEvent;
use App\Models\SuchakLeadAllocationPreference;
use App\Models\SuchakLeadRotationCursor;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakPlatformLead;
use App\Models\SuchakPlatformLeadAllocation;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SuchakLeadAllocationService
{
    public function __construct(
        private readonly SuchakAccessService $accessService,
        private readonly SuchakActivityLogger $activityLogger,
        private readonly SuchakLimitService $limitService,
        private readonly SuchakPolicyService $policyService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function recordAllocationPreference(
        SuchakAccount $account,
        User $admin,
        array $attributes,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakLeadAllocationPreference {
        $this->accessService->assertAdmin($admin, 'Only admins can record Suchak lead allocation preferences.');
        $account = $account->fresh();
        $this->accessService->assertCanOperate($account, 'Only verified Suchak accounts can receive platform lead allocation preferences.');

        $note = $this->privateSafeText($attributes['preference_note'] ?? null, 1000);

        return DB::transaction(function () use ($account, $admin, $attributes, $note, $ipAddress, $userAgent): SuchakLeadAllocationPreference {
            $preference = SuchakLeadAllocationPreference::query()->create([
                'suchak_account_id' => $account->id,
                'district_id' => $this->nullableId($attributes['district_id'] ?? null),
                'taluka_id' => $this->nullableId($attributes['taluka_id'] ?? null),
                'city_id' => $this->nullableId($attributes['city_id'] ?? null),
                'religion_id' => $this->nullableId($attributes['religion_id'] ?? null),
                'caste_id' => $this->nullableId($attributes['caste_id'] ?? null),
                'sub_caste_id' => $this->nullableId($attributes['sub_caste_id'] ?? null),
                'priority_weight' => $this->priorityWeight($attributes['priority_weight'] ?? 1),
                'is_active' => (bool) ($attributes['is_active'] ?? true),
                'preference_note' => $note,
                'created_by_admin_user_id' => $admin->id,
            ]);

            $fresh = $preference->fresh(['suchakAccount']);
            $audit = $this->writeAdminAuditLog(
                $admin,
                'suchak_lead_allocation_preference_recorded',
                $fresh,
                $note ?: 'Suchak lead allocation preference recorded.',
                [],
                [
                    'district_id' => $fresh->district_id,
                    'taluka_id' => $fresh->taluka_id,
                    'city_id' => $fresh->city_id,
                    'religion_id' => $fresh->religion_id,
                    'caste_id' => $fresh->caste_id,
                    'sub_caste_id' => $fresh->sub_caste_id,
                    'priority_weight' => $fresh->priority_weight,
                    'is_active' => $fresh->is_active,
                ],
            );
            $this->recordActivity(
                $fresh->suchak_account_id,
                $admin,
                SuchakActivityLog::ACTOR_ADMIN,
                SuchakActivityLog::ACTION_LEAD_ALLOCATION_PREFERENCE_RECORDED,
                'suchak_lead_allocation_preference',
                $fresh->id,
                null,
                $audit,
                [
                    'context' => 'lead_allocation_preference_recorded',
                    'priority_weight' => $fresh->priority_weight,
                    'is_active' => $fresh->is_active,
                ],
                $ipAddress,
                $userAgent,
            );

            return $fresh;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createPlatformLead(
        User $admin,
        array $attributes,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakPlatformLead {
        $this->accessService->assertAdmin($admin, 'Only admins can create platform-sourced Suchak leads.');

        $leadType = $this->allowedValue(
            $attributes['lead_type'] ?? null,
            SuchakPlatformLead::TYPES,
            'Suchak platform lead type is invalid.',
        );
        $leadSource = $this->allowedValue(
            $attributes['lead_source'] ?? SuchakPlatformLead::SOURCE_PLATFORM,
            SuchakPlatformLead::SOURCES,
            'Suchak platform lead source is invalid.',
        );
        $policy = $this->allowedValue(
            $attributes['allocation_policy'] ?? $this->policyService->leadAllocationPolicyMode(),
            SuchakPlatformLead::POLICIES,
            'Suchak platform lead allocation policy is invalid.',
        );
        $title = $this->requiredPrivateSafeText($attributes['lead_title'] ?? null, 'Suchak platform lead title is required.', 160);
        $note = $this->privateSafeText($attributes['lead_note'] ?? null, 1000);
        $slaHours = $this->positiveInteger(
            $attributes['allocation_sla_hours'] ?? $this->policyService->leadAllocationSlaHours(),
            'Suchak platform lead SLA hours must be positive.',
        );
        $targetProfileId = $this->nullableExistingProfileId($attributes['target_matrimony_profile_id'] ?? null);
        $requestingProfileId = $this->nullableExistingProfileId($attributes['requesting_matrimony_profile_id'] ?? null);
        $requestingUserId = $this->nullableExistingUserId($attributes['requesting_user_id'] ?? null);

        return DB::transaction(function () use (
            $admin,
            $leadType,
            $leadSource,
            $policy,
            $title,
            $note,
            $slaHours,
            $targetProfileId,
            $requestingProfileId,
            $requestingUserId,
            $attributes,
            $ipAddress,
            $userAgent,
        ): SuchakPlatformLead {
            $lead = SuchakPlatformLead::query()->create([
                'lead_type' => $leadType,
                'lead_source' => $leadSource,
                'lead_status' => SuchakPlatformLead::STATUS_OPEN,
                'allocation_policy' => $policy,
                'allocation_sla_hours' => $slaHours,
                'requesting_user_id' => $requestingUserId,
                'requesting_matrimony_profile_id' => $requestingProfileId,
                'target_matrimony_profile_id' => $targetProfileId,
                'service_context' => $this->serviceContext($attributes['service_context'] ?? SuchakCustomerContext::SERVICE_PACKAGE_LEAD),
                'district_id' => $this->nullableId($attributes['district_id'] ?? null),
                'taluka_id' => $this->nullableId($attributes['taluka_id'] ?? null),
                'city_id' => $this->nullableId($attributes['city_id'] ?? null),
                'religion_id' => $this->nullableId($attributes['religion_id'] ?? null),
                'caste_id' => $this->nullableId($attributes['caste_id'] ?? null),
                'sub_caste_id' => $this->nullableId($attributes['sub_caste_id'] ?? null),
                'lead_title' => $title,
                'lead_note' => $note,
                'created_by_admin_user_id' => $admin->id,
                'opened_at' => now(),
            ]);

            $fresh = $lead->fresh($this->leadRelations());
            $this->recordLeadEvent(
                $fresh,
                null,
                SuchakLeadAllocationEvent::EVENT_LEAD_CREATED,
                SuchakLeadAllocationEvent::ACTOR_ADMIN,
                $admin,
                null,
                $fresh->lead_status,
                $note ?: 'Platform Suchak lead created.',
                [
                    'lead_type' => $fresh->lead_type,
                    'lead_source' => $fresh->lead_source,
                    'allocation_policy' => $fresh->allocation_policy,
                    'allocation_sla_hours' => $fresh->allocation_sla_hours,
                ],
            );

            $audit = $this->writeAdminAuditLog(
                $admin,
                'suchak_platform_lead_created',
                $fresh,
                $note ?: 'Platform Suchak lead created.',
                [],
                [
                    'lead_status' => $fresh->lead_status,
                    'lead_type' => $fresh->lead_type,
                    'allocation_policy' => $fresh->allocation_policy,
                ],
            );
            $this->recordActivity(
                null,
                $admin,
                SuchakActivityLog::ACTOR_ADMIN,
                SuchakActivityLog::ACTION_PLATFORM_LEAD_CREATED,
                'suchak_platform_lead',
                $fresh->id,
                $fresh->target_matrimony_profile_id,
                $audit,
                [
                    'context' => 'platform_lead_created',
                    'lead_status' => $fresh->lead_status,
                    'lead_type' => $fresh->lead_type,
                    'allocation_policy' => $fresh->allocation_policy,
                ],
                $ipAddress,
                $userAgent,
            );

            return $fresh;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function allocateLead(
        SuchakPlatformLead $lead,
        User $admin,
        array $attributes = [],
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakPlatformLeadAllocation {
        $this->accessService->assertAdmin($admin, 'Only admins can allocate platform-sourced Suchak leads.');
        $lead = $lead->fresh(['targetProfile']);

        if ($lead->lead_status !== SuchakPlatformLead::STATUS_OPEN) {
            throw new InvalidArgumentException('Only open platform Suchak leads can be allocated.');
        }

        if ($lead->target_matrimony_profile_id === null) {
            throw new InvalidArgumentException('Platform Suchak lead allocation requires a target matrimony profile.');
        }

        $policy = $this->allowedValue(
            $attributes['allocation_policy'] ?? $lead->allocation_policy ?? $this->policyService->leadAllocationPolicyMode(),
            SuchakPlatformLead::POLICIES,
            'Suchak platform lead allocation policy is invalid.',
        );
        $note = $this->privateSafeText($attributes['allocation_note'] ?? null, 1000);
        $overrideAccountId = $this->nullableId($attributes['suchak_account_id'] ?? null);

        return DB::transaction(function () use ($lead, $admin, $policy, $note, $overrideAccountId, $ipAddress, $userAgent): SuchakPlatformLeadAllocation {
            /** @var SuchakPlatformLead $lockedLead */
            $lockedLead = SuchakPlatformLead::query()
                ->whereKey($lead->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedLead->lead_status !== SuchakPlatformLead::STATUS_OPEN) {
                throw new InvalidArgumentException('Only open platform Suchak leads can be allocated.');
            }

            $bucketKey = $this->rotationBucketKey($lockedLead, $policy);
            $selection = $this->selectedAccountForLead($lockedLead, $policy, $bucketKey, $overrideAccountId);
            $account = $selection['account'];
            $this->limitService->assertLeadRequestAllowed($account);
            $limitSnapshot = $this->limitService->leadRequestLimit($account);

            $cursor = $this->rotationCursor($lockedLead, $policy, $bucketKey, $admin);
            $rotationSequence = ((int) $cursor->last_rotation_sequence) + 1;
            $allocatedAt = now();

            $customerContext = SuchakCustomerContext::query()->create([
                'suchak_account_id' => $account->id,
                'candidate_matrimony_profile_id' => $lockedLead->target_matrimony_profile_id,
                'payer_user_id' => $lockedLead->requesting_user_id,
                'service_context' => $lockedLead->service_context,
                'source_owner' => SuchakCustomerContext::SOURCE_OWNER_PLATFORM,
                'source_type' => SuchakCustomerContext::SOURCE_TYPE_PLATFORM_REQUEST,
                'customer_lifecycle_status' => SuchakCustomerContext::STATUS_LEAD,
                'created_by_user_id' => $admin->id,
                'classified_by_user_id' => $admin->id,
                'classified_at' => $allocatedAt,
                'opened_at' => $allocatedAt,
            ]);

            $paymentContext = SuchakPaymentContext::query()->create([
                'suchak_account_id' => $account->id,
                'customer_context_id' => $customerContext->id,
                'matrimony_profile_id' => $lockedLead->target_matrimony_profile_id,
                'source_owner' => SuchakPaymentContext::SOURCE_PLATFORM,
                'payment_collector' => SuchakPaymentContext::COLLECTOR_PLATFORM,
                'context_status' => SuchakPaymentContext::STATUS_ACTIVE,
                'resolved_by_user_id' => $admin->id,
                'resolution_note' => 'Platform Suchak lead allocation locks platform payment collection.',
            ]);

            $allocation = SuchakPlatformLeadAllocation::query()->create([
                'platform_lead_id' => $lockedLead->id,
                'suchak_account_id' => $account->id,
                'customer_context_id' => $customerContext->id,
                'payment_context_id' => $paymentContext->id,
                'allocation_status' => SuchakPlatformLeadAllocation::STATUS_ALLOCATED,
                'allocation_policy' => $policy,
                'rotation_bucket_key' => $bucketKey,
                'rotation_sequence' => $rotationSequence,
                'matched_area_level' => $selection['area_level'],
                'matched_community_level' => $selection['community_level'],
                'plan_limit_snapshot' => $limitSnapshot,
                'allocated_by_admin_user_id' => $admin->id,
                'allocated_at' => $allocatedAt,
                'sla_expires_at' => $allocatedAt->copy()->addHours(max(1, (int) $lockedLead->allocation_sla_hours)),
                'status_note' => $note,
            ]);

            $lockedLead->forceFill([
                'lead_status' => SuchakPlatformLead::STATUS_ALLOCATED,
                'allocated_at' => $allocatedAt,
            ])->save();

            $cursor->forceFill([
                'last_allocated_suchak_account_id' => $account->id,
                'last_rotation_sequence' => $rotationSequence,
                'last_allocated_at' => $allocatedAt,
                'updated_by_admin_user_id' => $admin->id,
            ])->save();

            $fresh = $allocation->fresh($this->allocationRelations());
            $this->recordLeadEvent(
                $fresh->platformLead,
                $fresh,
                SuchakLeadAllocationEvent::EVENT_ALLOCATED,
                SuchakLeadAllocationEvent::ACTOR_ADMIN,
                $admin,
                SuchakPlatformLead::STATUS_OPEN,
                $fresh->platformLead->lead_status,
                $note ?: 'Platform Suchak lead allocated.',
                [
                    'allocation_policy' => $fresh->allocation_policy,
                    'rotation_bucket_key' => $fresh->rotation_bucket_key,
                    'rotation_sequence' => $fresh->rotation_sequence,
                    'matched_area_level' => $fresh->matched_area_level,
                    'matched_community_level' => $fresh->matched_community_level,
                    'customer_context_id' => $fresh->customer_context_id,
                    'payment_context_id' => $fresh->payment_context_id,
                    'source_owner' => $fresh->paymentContext->source_owner,
                    'payment_collector' => $fresh->paymentContext->payment_collector,
                ],
            );

            $audit = $this->writeAdminAuditLog(
                $admin,
                'suchak_platform_lead_allocated',
                $fresh,
                $note ?: 'Platform Suchak lead allocated.',
                ['lead_status' => SuchakPlatformLead::STATUS_OPEN],
                [
                    'lead_status' => $fresh->platformLead->lead_status,
                    'allocation_status' => $fresh->allocation_status,
                    'suchak_account_id' => $fresh->suchak_account_id,
                    'payment_collector' => $fresh->paymentContext->payment_collector,
                ],
            );
            $this->recordActivity(
                $fresh->suchak_account_id,
                $admin,
                SuchakActivityLog::ACTOR_ADMIN,
                SuchakActivityLog::ACTION_PLATFORM_LEAD_ALLOCATED,
                'suchak_platform_lead_allocation',
                $fresh->id,
                $fresh->platformLead->target_matrimony_profile_id,
                $audit,
                [
                    'context' => 'platform_lead_allocated',
                    'platform_lead_id' => $fresh->platform_lead_id,
                    'allocation_status' => $fresh->allocation_status,
                    'allocation_policy' => $fresh->allocation_policy,
                    'payment_context_id' => $fresh->payment_context_id,
                    'payment_collector' => $fresh->paymentContext->payment_collector,
                    'source_owner' => $fresh->paymentContext->source_owner,
                ],
                $ipAddress,
                $userAgent,
            );

            return $fresh;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function acceptAllocation(
        SuchakPlatformLeadAllocation $allocation,
        User $actor,
        array $attributes = [],
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakPlatformLeadAllocation {
        $allocation = $allocation->fresh(['suchakAccount', 'platformLead', 'customerContext']);
        $this->accessService->assertOwnerCanOperate(
            $allocation->suchakAccount,
            $actor,
            'Only the allocated Suchak can accept this platform lead.',
            'Only verified Suchak accounts can accept platform leads.',
        );
        $note = $this->privateSafeText($attributes['acceptance_note'] ?? null, 1000);

        return DB::transaction(function () use ($allocation, $actor, $note, $ipAddress, $userAgent): SuchakPlatformLeadAllocation {
            /** @var SuchakPlatformLeadAllocation $locked */
            $locked = SuchakPlatformLeadAllocation::query()
                ->with(['platformLead', 'customerContext', 'suchakAccount'])
                ->whereKey($allocation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->allocation_status !== SuchakPlatformLeadAllocation::STATUS_ALLOCATED) {
                throw new InvalidArgumentException('Only allocated platform Suchak leads can be accepted.');
            }

            $fromStatus = $locked->allocation_status;
            $locked->forceFill([
                'allocation_status' => SuchakPlatformLeadAllocation::STATUS_ACCEPTED,
                'accepted_by_user_id' => $actor->id,
                'accepted_at' => now(),
                'acceptance_note' => $note,
            ])->save();
            $locked->platformLead->forceFill(['lead_status' => SuchakPlatformLead::STATUS_ACCEPTED])->save();
            $locked->customerContext->forceFill([
                'customer_lifecycle_status' => SuchakCustomerContext::STATUS_ACTIVE_SERVICE,
                'classified_by_user_id' => $actor->id,
                'classified_at' => now(),
            ])->save();

            $fresh = $locked->fresh($this->allocationRelations());
            $this->recordLeadEvent(
                $fresh->platformLead,
                $fresh,
                SuchakLeadAllocationEvent::EVENT_ACCEPTED,
                SuchakLeadAllocationEvent::ACTOR_SUCHAK,
                $actor,
                $fromStatus,
                $fresh->allocation_status,
                $note ?: 'Allocated Suchak accepted the platform lead.',
                [
                    'customer_context_id' => $fresh->customer_context_id,
                    'payment_context_id' => $fresh->payment_context_id,
                ],
            );
            $this->recordActivity(
                $fresh->suchak_account_id,
                $actor,
                SuchakActivityLog::ACTOR_SUCHAK,
                SuchakActivityLog::ACTION_PLATFORM_LEAD_STATUS_CHANGED,
                'suchak_platform_lead_allocation',
                $fresh->id,
                $fresh->platformLead->target_matrimony_profile_id,
                null,
                [
                    'context' => 'platform_lead_accepted',
                    'from_status' => $fromStatus,
                    'to_status' => $fresh->allocation_status,
                    'platform_lead_id' => $fresh->platform_lead_id,
                ],
                $ipAddress,
                $userAgent,
            );

            return $fresh;
        });
    }

    public function expireAllocationIfPastSla(SuchakPlatformLeadAllocation $allocation): SuchakPlatformLeadAllocation
    {
        $allocation = $allocation->fresh(['platformLead', 'paymentContext']);

        if ($allocation->allocation_status !== SuchakPlatformLeadAllocation::STATUS_ALLOCATED || $allocation->sla_expires_at->isFuture()) {
            return $allocation;
        }

        return DB::transaction(function () use ($allocation): SuchakPlatformLeadAllocation {
            /** @var SuchakPlatformLeadAllocation $locked */
            $locked = SuchakPlatformLeadAllocation::query()
                ->with(['platformLead', 'paymentContext'])
                ->whereKey($allocation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->allocation_status !== SuchakPlatformLeadAllocation::STATUS_ALLOCATED || $locked->sla_expires_at->isFuture()) {
                return $locked;
            }

            $fromStatus = $locked->allocation_status;
            $locked->forceFill([
                'allocation_status' => SuchakPlatformLeadAllocation::STATUS_EXPIRED,
                'expired_at' => now(),
                'status_note' => 'Platform lead allocation expired after SLA.',
            ])->save();
            $locked->platformLead->forceFill([
                'lead_status' => SuchakPlatformLead::STATUS_EXPIRED,
                'closed_at' => now(),
            ])->save();
            $locked->paymentContext->forceFill([
                'context_status' => SuchakPaymentContext::STATUS_CANCELLED,
                'resolution_note' => 'Platform lead allocation expired after SLA.',
            ])->save();

            $fresh = $locked->fresh($this->allocationRelations());
            $this->recordLeadEvent(
                $fresh->platformLead,
                $fresh,
                SuchakLeadAllocationEvent::EVENT_EXPIRED,
                SuchakLeadAllocationEvent::ACTOR_SYSTEM,
                null,
                $fromStatus,
                $fresh->allocation_status,
                'Platform lead allocation expired after SLA.',
                [
                    'sla_expires_at' => $fresh->sla_expires_at?->toIso8601String(),
                    'payment_context_status' => $fresh->paymentContext->context_status,
                ],
            );
            $this->recordActivity(
                $fresh->suchak_account_id,
                null,
                SuchakActivityLog::ACTOR_SYSTEM,
                SuchakActivityLog::ACTION_PLATFORM_LEAD_STATUS_CHANGED,
                'suchak_platform_lead_allocation',
                $fresh->id,
                $fresh->platformLead->target_matrimony_profile_id,
                null,
                [
                    'context' => 'platform_lead_expired',
                    'from_status' => $fromStatus,
                    'to_status' => $fresh->allocation_status,
                    'platform_lead_id' => $fresh->platform_lead_id,
                ],
                null,
                null,
            );

            return $fresh;
        });
    }

    /**
     * @return array<int, string>
     */
    private function leadRelations(): array
    {
        return [
            'requestingUser',
            'requestingProfile',
            'targetProfile',
            'createdByAdmin',
            'allocations',
            'events',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function allocationRelations(): array
    {
        return [
            'platformLead',
            'suchakAccount',
            'customerContext',
            'paymentContext',
            'allocatedByAdmin',
            'acceptedByUser',
            'declinedByUser',
            'cancelledByAdmin',
            'events',
        ];
    }

    /**
     * @return array{account: SuchakAccount, area_level: string, community_level: string}
     */
    private function selectedAccountForLead(
        SuchakPlatformLead $lead,
        string $policy,
        string $bucketKey,
        ?int $overrideAccountId,
    ): array {
        if ($overrideAccountId !== null) {
            if ($policy !== SuchakPlatformLead::POLICY_ADMIN_OVERRIDE) {
                throw new InvalidArgumentException('Admin override account can be used only with admin override allocation policy.');
            }

            $account = SuchakAccount::query()->whereKey($overrideAccountId)->firstOrFail();
            $this->accessService->assertCanOperate($account, 'Only verified Suchak accounts can receive platform leads.');

            return [
                'account' => $account,
                'area_level' => $this->matchedAreaLevel($lead, $account, null),
                'community_level' => SuchakPlatformLeadAllocation::MATCH_NONE,
            ];
        }

        $candidates = $this->candidateRows($lead, $policy);
        if ($candidates === []) {
            throw new InvalidArgumentException('No eligible Suchak account matches this platform lead allocation policy.');
        }

        $cursor = SuchakLeadRotationCursor::query()
            ->where('rotation_bucket_key', $bucketKey)
            ->first();
        $lastId = (int) ($cursor?->last_allocated_suchak_account_id ?? 0);
        $selected = $candidates[0];

        if ($lastId > 0) {
            foreach ($candidates as $index => $candidate) {
                if ((int) $candidate['account']->id === $lastId) {
                    $selected = $candidates[($index + 1) % count($candidates)];
                    break;
                }
            }
        }

        return $selected;
    }

    /**
     * @return array<int, array{account: SuchakAccount, area_level: string, community_level: string, score: int}>
     */
    private function candidateRows(SuchakPlatformLead $lead, string $policy): array
    {
        /** @var Collection<int, SuchakAccount> $accounts */
        $accounts = SuchakAccount::query()
            ->with(['leadAllocationPreferences' => fn ($query) => $query->where('is_active', true)])
            ->where('verification_status', SuchakAccount::VERIFICATION_VERIFIED)
            ->where('public_status', SuchakAccount::PUBLIC_ACTIVE)
            ->orderBy('id')
            ->get();

        $rows = [];
        foreach ($accounts as $account) {
            $best = $this->bestPreferenceMatch($lead, $account, $policy);
            if ($best === null) {
                continue;
            }

            $rows[] = $best;
        }

        usort($rows, function (array $left, array $right): int {
            if ($left['score'] !== $right['score']) {
                return $right['score'] <=> $left['score'];
            }

            return $left['account']->id <=> $right['account']->id;
        });

        return $rows;
    }

    /**
     * @return array{account: SuchakAccount, area_level: string, community_level: string, score: int}|null
     */
    private function bestPreferenceMatch(SuchakPlatformLead $lead, SuchakAccount $account, string $policy): ?array
    {
        $preferences = $account->leadAllocationPreferences;
        $best = null;

        if ($preferences->isEmpty()) {
            $areaLevel = $this->matchedAreaLevel($lead, $account, null);
            if ($this->leadHasArea($lead) && $areaLevel === SuchakPlatformLeadAllocation::MATCH_NONE) {
                return null;
            }

            $score = $this->scoreForPolicy($policy, $areaLevel, SuchakPlatformLeadAllocation::MATCH_NONE, 1);

            return $score > 0 || ! $this->leadHasAnyCriteria($lead)
                ? ['account' => $account, 'area_level' => $areaLevel, 'community_level' => SuchakPlatformLeadAllocation::MATCH_NONE, 'score' => $score]
                : null;
        }

        foreach ($preferences as $preference) {
            if (! $this->preferenceCanServeLead($lead, $preference)) {
                continue;
            }

            $areaLevel = $this->matchedAreaLevel($lead, $account, $preference);
            $communityLevel = $this->matchedCommunityLevel($lead, $preference);
            if ($this->leadHasArea($lead) && $areaLevel === SuchakPlatformLeadAllocation::MATCH_NONE) {
                continue;
            }

            if ($this->leadHasCommunity($lead) && $communityLevel === SuchakPlatformLeadAllocation::MATCH_NONE) {
                continue;
            }

            $score = $this->scoreForPolicy($policy, $areaLevel, $communityLevel, max(1, (int) $preference->priority_weight));
            if ($best === null || $score > $best['score']) {
                $best = [
                    'account' => $account,
                    'area_level' => $areaLevel,
                    'community_level' => $communityLevel,
                    'score' => $score,
                ];
            }
        }

        return $best;
    }

    private function preferenceCanServeLead(SuchakPlatformLead $lead, SuchakLeadAllocationPreference $preference): bool
    {
        foreach (['district_id', 'taluka_id', 'city_id', 'religion_id', 'caste_id', 'sub_caste_id'] as $field) {
            $leadValue = $lead->{$field};
            $preferenceValue = $preference->{$field};
            if ($leadValue !== null && $preferenceValue !== null && (int) $leadValue !== (int) $preferenceValue) {
                return false;
            }
        }

        return true;
    }

    private function matchedAreaLevel(
        SuchakPlatformLead $lead,
        SuchakAccount $account,
        ?SuchakLeadAllocationPreference $preference,
    ): string {
        if ($lead->city_id !== null
            && ((int) ($preference?->city_id ?? 0) === (int) $lead->city_id
                || (int) ($account->city_id ?? 0) === (int) $lead->city_id)) {
            return SuchakPlatformLeadAllocation::MATCH_CITY;
        }

        if ($lead->taluka_id !== null
            && ((int) ($preference?->taluka_id ?? 0) === (int) $lead->taluka_id
                || (int) ($account->taluka_id ?? 0) === (int) $lead->taluka_id)) {
            return SuchakPlatformLeadAllocation::MATCH_TALUKA;
        }

        if ($lead->district_id !== null
            && ((int) ($preference?->district_id ?? 0) === (int) $lead->district_id
                || (int) ($account->district_id ?? 0) === (int) $lead->district_id)) {
            return SuchakPlatformLeadAllocation::MATCH_DISTRICT;
        }

        return SuchakPlatformLeadAllocation::MATCH_NONE;
    }

    private function matchedCommunityLevel(SuchakPlatformLead $lead, SuchakLeadAllocationPreference $preference): string
    {
        if ($lead->sub_caste_id !== null && (int) ($preference->sub_caste_id ?? 0) === (int) $lead->sub_caste_id) {
            return SuchakPlatformLeadAllocation::MATCH_SUB_CASTE;
        }

        if ($lead->caste_id !== null && (int) ($preference->caste_id ?? 0) === (int) $lead->caste_id) {
            return SuchakPlatformLeadAllocation::MATCH_CASTE;
        }

        if ($lead->religion_id !== null && (int) ($preference->religion_id ?? 0) === (int) $lead->religion_id) {
            return SuchakPlatformLeadAllocation::MATCH_RELIGION;
        }

        return SuchakPlatformLeadAllocation::MATCH_NONE;
    }

    private function scoreForPolicy(string $policy, string $areaLevel, string $communityLevel, int $priorityWeight): int
    {
        $areaScore = match ($areaLevel) {
            SuchakPlatformLeadAllocation::MATCH_CITY => 30,
            SuchakPlatformLeadAllocation::MATCH_TALUKA => 20,
            SuchakPlatformLeadAllocation::MATCH_DISTRICT => 10,
            default => 0,
        };
        $communityScore = match ($communityLevel) {
            SuchakPlatformLeadAllocation::MATCH_SUB_CASTE => 30,
            SuchakPlatformLeadAllocation::MATCH_CASTE => 20,
            SuchakPlatformLeadAllocation::MATCH_RELIGION => 10,
            default => 0,
        };

        return match ($policy) {
            SuchakPlatformLead::POLICY_AREA_FIRST => ($areaScore * 100) + ($communityScore * 10) + $priorityWeight,
            SuchakPlatformLead::POLICY_COMMUNITY_FIRST => ($communityScore * 100) + ($areaScore * 10) + $priorityWeight,
            default => ($areaScore * 50) + ($communityScore * 50) + $priorityWeight,
        };
    }

    private function rotationCursor(
        SuchakPlatformLead $lead,
        string $policy,
        string $bucketKey,
        User $admin,
    ): SuchakLeadRotationCursor {
        $cursor = SuchakLeadRotationCursor::query()
            ->where('rotation_bucket_key', $bucketKey)
            ->lockForUpdate()
            ->first();

        if ($cursor instanceof SuchakLeadRotationCursor) {
            return $cursor;
        }

        return SuchakLeadRotationCursor::query()->create([
            'rotation_bucket_key' => $bucketKey,
            'allocation_policy' => $policy,
            'district_id' => $lead->district_id,
            'taluka_id' => $lead->taluka_id,
            'city_id' => $lead->city_id,
            'religion_id' => $lead->religion_id,
            'caste_id' => $lead->caste_id,
            'sub_caste_id' => $lead->sub_caste_id,
            'last_allocated_suchak_account_id' => null,
            'last_rotation_sequence' => 0,
            'updated_by_admin_user_id' => $admin->id,
        ]);
    }

    private function rotationBucketKey(SuchakPlatformLead $lead, string $policy): string
    {
        return implode(':', [
            'policy='.$policy,
            'district='.($lead->district_id ?? 'any'),
            'taluka='.($lead->taluka_id ?? 'any'),
            'city='.($lead->city_id ?? 'any'),
            'religion='.($lead->religion_id ?? 'any'),
            'caste='.($lead->caste_id ?? 'any'),
            'sub_caste='.($lead->sub_caste_id ?? 'any'),
        ]);
    }

    private function leadHasAnyCriteria(SuchakPlatformLead $lead): bool
    {
        return $this->leadHasArea($lead) || $this->leadHasCommunity($lead);
    }

    private function leadHasArea(SuchakPlatformLead $lead): bool
    {
        return $lead->district_id !== null || $lead->taluka_id !== null || $lead->city_id !== null;
    }

    private function leadHasCommunity(SuchakPlatformLead $lead): bool
    {
        return $lead->religion_id !== null || $lead->caste_id !== null || $lead->sub_caste_id !== null;
    }

    private function recordLeadEvent(
        ?SuchakPlatformLead $lead,
        ?SuchakPlatformLeadAllocation $allocation,
        string $eventType,
        string $actorType,
        ?User $actor,
        ?string $fromStatus,
        ?string $toStatus,
        ?string $eventNote,
        array $metadata = [],
    ): SuchakLeadAllocationEvent {
        return SuchakLeadAllocationEvent::query()->create([
            'platform_lead_id' => $lead?->id,
            'lead_allocation_id' => $allocation?->id,
            'suchak_account_id' => $allocation?->suchak_account_id,
            'event_type' => $eventType,
            'actor_type' => $actorType,
            'actor_user_id' => $actor?->id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'event_note' => $eventNote,
            'metadata_json' => $metadata === [] ? null : $metadata,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordActivity(
        ?int $suchakAccountId,
        ?User $actor,
        string $actorType,
        string $actionType,
        string $targetType,
        int $targetId,
        ?int $matrimonyProfileId,
        ?AdminAuditLog $adminAuditLog,
        array $metadata,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        $this->activityLogger->record([
            'suchak_account_id' => $suchakAccountId,
            'actor_user_id' => $actor?->id,
            'actor_type' => $actorType,
            'action_type' => $actionType,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'matrimony_profile_id' => $matrimonyProfileId,
            'admin_audit_log_id' => $adminAuditLog?->id,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent === null ? null : Str::limit($userAgent, 512, ''),
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
        Model $entity,
        string $reason,
        array $oldValue,
        array $newValue,
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
    private function allowedValue(mixed $value, array $allowed, string $message): string
    {
        $normalized = trim((string) ($value ?? ''));
        if (! in_array($normalized, $allowed, true)) {
            throw new InvalidArgumentException($message);
        }

        return $normalized;
    }

    private function serviceContext(mixed $value): string
    {
        return $this->allowedValue(
            $value,
            SuchakCustomerContext::SERVICE_CONTEXTS,
            'Suchak platform lead service context is invalid.',
        );
    }

    private function requiredPrivateSafeText(mixed $value, string $message, int $limit): string
    {
        $text = $this->privateSafeText($value, $limit);
        if ($text === null) {
            throw new InvalidArgumentException($message);
        }

        return $text;
    }

    private function privateSafeText(mixed $value, int $limit): ?string
    {
        $normalized = trim((string) ($value ?? ''));
        if ($normalized === '') {
            return null;
        }

        $normalized = Str::limit($normalized, $limit, '');
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $normalized) === 1
            || preg_match('/(?<!\d)(?:\+?91[\s-]*)?[6-9]\d(?:[\s-]?\d){8}(?!\d)/', $normalized) === 1) {
            throw new InvalidArgumentException('Suchak platform lead records must not store private contact details.');
        }

        return $normalized;
    }

    private function nullableId(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        if (! is_numeric($value) || (int) $value <= 0) {
            throw new InvalidArgumentException('Suchak platform lead identifier is invalid.');
        }

        return (int) $value;
    }

    private function nullableExistingProfileId(mixed $value): ?int
    {
        $id = $this->nullableId($value);

        return $id === null ? null : (int) MatrimonyProfile::query()->findOrFail($id)->id;
    }

    private function nullableExistingUserId(mixed $value): ?int
    {
        $id = $this->nullableId($value);

        return $id === null ? null : (int) User::query()->findOrFail($id)->id;
    }

    private function priorityWeight(mixed $value): int
    {
        if (! is_numeric($value) || (int) $value < 1 || (int) $value > 1000) {
            throw new InvalidArgumentException('Suchak lead allocation preference priority is invalid.');
        }

        return (int) $value;
    }

    private function positiveInteger(mixed $value, string $message): int
    {
        if (! is_numeric($value) || (int) $value < 1) {
            throw new InvalidArgumentException($message);
        }

        return (int) $value;
    }
}
