<?php

namespace App\Modules\Suchak\Services;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakBiodataIntakeLink;
use App\Models\SuchakConsent;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakCustomerTimelineEvent;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SuchakCustomerLifecycleService
{
    public function __construct(
        private readonly SuchakActivityLogger $activityLogger,
        private readonly SuchakAccessService $accessService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createFromSourceLink(
        SuchakAccount $account,
        User $actor,
        SuchakBiodataIntakeLink $sourceLink,
        array $attributes = [],
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakCustomerContext {
        $account->refresh();
        $sourceLink->refresh();
        $this->assertSuchakActor($account, $actor);
        $this->assertSourceLinkMatches($account, $sourceLink);

        return DB::transaction(function () use ($account, $actor, $sourceLink, $attributes, $ipAddress, $userAgent): SuchakCustomerContext {
            $duplicate = SuchakCustomerContext::query()
                ->where('source_link_id', $sourceLink->id)
                ->lockForUpdate()
                ->first();

            if ($duplicate !== null) {
                throw new InvalidArgumentException('Suchak customer context already exists for this source link.');
            }

            $sourceType = $this->allowedValue(
                $attributes['source_type'] ?? $this->sourceTypeForSourceLink($sourceLink),
                SuchakCustomerContext::SOURCE_TYPES,
                'Suchak customer source type is invalid.',
            );
            $status = $this->allowedValue(
                $attributes['customer_lifecycle_status'] ?? ($sourceLink->matrimony_profile_id === null
                    ? SuchakCustomerContext::STATUS_LEAD
                    : SuchakCustomerContext::STATUS_CANDIDATE_IDENTIFIED),
                SuchakCustomerContext::LIFECYCLE_STATUSES,
                'Suchak customer lifecycle status is invalid.',
            );

            $context = SuchakCustomerContext::query()->create(array_merge(
                $this->baseAttributes($account, $actor, $attributes, $status),
                [
                    'candidate_matrimony_profile_id' => $sourceLink->matrimony_profile_id,
                    'source_link_id' => $sourceLink->id,
                    'source_type' => $sourceType,
                ],
            ));

            $this->recordTimeline($context, SuchakCustomerTimelineEvent::EVENT_CONTEXT_CREATED, $actor, null, $status, null);
            $this->recordActivity($context, $actor, SuchakActivityLog::ACTION_CUSTOMER_CONTEXT_CREATED, 'customer_context_created', $ipAddress, $userAgent);

            return $context->fresh($this->relations());
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createForRepresentation(
        SuchakAccount $account,
        User $actor,
        SuchakProfileRepresentation $representation,
        array $attributes = [],
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakCustomerContext {
        $account->refresh();
        $representation->refresh()->loadMissing(['matrimonyProfile', 'suchakAccount']);
        $this->assertSuchakActor($account, $actor);
        $this->assertRepresentationMatches($account, $representation);

        return DB::transaction(function () use ($account, $actor, $representation, $attributes, $ipAddress, $userAgent): SuchakCustomerContext {
            $duplicate = SuchakCustomerContext::query()
                ->where('representation_id', $representation->id)
                ->lockForUpdate()
                ->first();

            if ($duplicate !== null) {
                throw new InvalidArgumentException('Suchak customer context already exists for this representation.');
            }

            $sourceLink = $this->sourceLinkForRepresentation($account, $representation);
            $consent = $this->optionalConsent($attributes['consent_id'] ?? null, $account, $representation);
            $status = $this->allowedValue(
                $attributes['customer_lifecycle_status'] ?? $this->statusForRepresentation($representation),
                SuchakCustomerContext::LIFECYCLE_STATUSES,
                'Suchak customer lifecycle status is invalid.',
            );

            $context = SuchakCustomerContext::query()->create(array_merge(
                $this->baseAttributes($account, $actor, $attributes, $status),
                [
                    'candidate_matrimony_profile_id' => $representation->matrimony_profile_id,
                    'source_link_id' => $sourceLink?->id,
                    'representation_id' => $representation->id,
                    'consent_id' => $consent?->id,
                    'consent_giver_name' => $this->limitedText($attributes['consent_giver_name'] ?? $consent?->consent_given_by_name, 255),
                    'consent_giver_relationship_to_candidate' => $this->limitedText($attributes['consent_giver_relationship_to_candidate'] ?? $consent?->relationship_to_candidate, 255),
                    'source_type' => $this->allowedValue(
                        $attributes['source_type'] ?? $this->sourceTypeForRepresentation($representation),
                        SuchakCustomerContext::SOURCE_TYPES,
                        'Suchak customer source type is invalid.',
                    ),
                ],
            ));

            $this->recordTimeline($context, SuchakCustomerTimelineEvent::EVENT_CONTEXT_CREATED, $actor, null, $status, null);
            if ($context->payer_user_id !== null || $context->payer_name !== null) {
                $this->recordTimeline($context, SuchakCustomerTimelineEvent::EVENT_PAYER_LINKED, $actor, $status, $status, null);
            }
            if ($context->consent_id !== null || $context->consent_giver_name !== null || $context->consent_giver_user_id !== null) {
                $this->recordTimeline($context, SuchakCustomerTimelineEvent::EVENT_CONSENT_GIVER_LINKED, $actor, $status, $status, null);
            }
            $this->recordActivity($context, $actor, SuchakActivityLog::ACTION_CUSTOMER_CONTEXT_CREATED, 'customer_context_created', $ipAddress, $userAgent);

            return $context->fresh($this->relations());
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function classifySource(
        SuchakCustomerContext $context,
        User $actor,
        array $attributes,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakCustomerContext {
        $context->refresh()->loadMissing('suchakAccount');
        $this->assertSuchakActor($context->suchakAccount, $actor);

        $sourceOwner = $this->allowedValue(
            $attributes['source_owner'] ?? $context->source_owner,
            SuchakCustomerContext::SOURCE_OWNERS,
            'Suchak customer source owner is invalid.',
        );
        $sourceType = $this->allowedValue(
            $attributes['source_type'] ?? $context->source_type,
            SuchakCustomerContext::SOURCE_TYPES,
            'Suchak customer source type is invalid.',
        );
        $status = $this->allowedValue(
            $attributes['customer_lifecycle_status'] ?? $context->customer_lifecycle_status,
            SuchakCustomerContext::LIFECYCLE_STATUSES,
            'Suchak customer lifecycle status is invalid.',
        );

        return DB::transaction(function () use ($context, $actor, $sourceOwner, $sourceType, $status, $ipAddress, $userAgent): SuchakCustomerContext {
            /** @var SuchakCustomerContext $locked */
            $locked = SuchakCustomerContext::query()
                ->whereKey($context->id)
                ->lockForUpdate()
                ->firstOrFail();
            $fromStatus = $locked->customer_lifecycle_status;

            $locked->forceFill([
                'source_owner' => $sourceOwner,
                'source_type' => $sourceType,
                'customer_lifecycle_status' => $status,
                'classified_by_user_id' => $actor->id,
                'classified_at' => now(),
                'closed_at' => in_array($status, [
                    SuchakCustomerContext::STATUS_CANCELLED,
                    SuchakCustomerContext::STATUS_CLOSED,
                    SuchakCustomerContext::STATUS_COMPLETED,
                ], true) ? now() : null,
            ])->save();

            $fresh = $locked->fresh($this->relations());
            $this->recordTimeline($fresh, SuchakCustomerTimelineEvent::EVENT_SOURCE_CLASSIFIED, $actor, $fromStatus, $status, null);
            if ($fromStatus !== $status) {
                $this->recordTimeline($fresh, SuchakCustomerTimelineEvent::EVENT_LIFECYCLE_STATUS_CHANGED, $actor, $fromStatus, $status, null);
            }
            $this->recordActivity($fresh, $actor, SuchakActivityLog::ACTION_CUSTOMER_SOURCE_CLASSIFIED, 'customer_source_classified', $ipAddress, $userAgent);

            return $fresh;
        });
    }

    private function assertSuchakActor(SuchakAccount $account, User $actor): void
    {
        $this->accessService->assertOwnerCanOperate(
            $account,
            $actor,
            'Only the owning Suchak account can manage customer lifecycle records.',
            'Only verified Suchak accounts can manage customer lifecycle records.',
        );
    }

    private function assertSourceLinkMatches(SuchakAccount $account, SuchakBiodataIntakeLink $sourceLink): void
    {
        if ((int) $sourceLink->suchak_account_id !== (int) $account->id) {
            throw new InvalidArgumentException('Suchak customer source link must belong to the Suchak account.');
        }

        if ($sourceLink->source_status === SuchakBiodataIntakeLink::STATUS_CANCELLED) {
            throw new InvalidArgumentException('Cancelled source links cannot create customer contexts.');
        }
    }

    private function assertRepresentationMatches(SuchakAccount $account, SuchakProfileRepresentation $representation): void
    {
        if ((int) $representation->suchak_account_id !== (int) $account->id) {
            throw new InvalidArgumentException('Suchak customer representation must belong to the Suchak account.');
        }
    }

    private function optionalConsent(mixed $consentId, SuchakAccount $account, SuchakProfileRepresentation $representation): ?SuchakConsent
    {
        if ($consentId === null || $consentId === '') {
            return null;
        }

        $consent = SuchakConsent::query()->findOrFail((int) $consentId);
        if ((int) $consent->suchak_account_id !== (int) $account->id
            || (int) $consent->matrimony_profile_id !== (int) $representation->matrimony_profile_id
            || (int) $consent->representation_id !== (int) $representation->id) {
            throw new InvalidArgumentException('Suchak customer consent giver context must match the representation.');
        }

        return $consent;
    }

    private function sourceLinkForRepresentation(SuchakAccount $account, SuchakProfileRepresentation $representation): ?SuchakBiodataIntakeLink
    {
        if ($representation->biodata_intake_id === null) {
            return null;
        }

        return SuchakBiodataIntakeLink::query()
            ->where('suchak_account_id', $account->id)
            ->where('biodata_intake_id', $representation->biodata_intake_id)
            ->where('matrimony_profile_id', $representation->matrimony_profile_id)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function baseAttributes(SuchakAccount $account, User $actor, array $attributes, string $status): array
    {
        return [
            'suchak_account_id' => $account->id,
            'payer_user_id' => $this->nullableUserId($attributes['payer_user_id'] ?? null),
            'payer_name' => $this->limitedText($attributes['payer_name'] ?? null, 255),
            'payer_relationship_to_candidate' => $this->limitedText($attributes['payer_relationship_to_candidate'] ?? null, 255),
            'consent_giver_user_id' => $this->nullableUserId($attributes['consent_giver_user_id'] ?? null),
            'service_context' => $this->allowedValue(
                $attributes['service_context'] ?? SuchakCustomerContext::SERVICE_PROFILE_REPRESENTATION,
                SuchakCustomerContext::SERVICE_CONTEXTS,
                'Suchak customer service context is invalid.',
            ),
            'source_owner' => $this->allowedValue(
                $attributes['source_owner'] ?? SuchakCustomerContext::SOURCE_OWNER_SUCHAK,
                SuchakCustomerContext::SOURCE_OWNERS,
                'Suchak customer source owner is invalid.',
            ),
            'customer_lifecycle_status' => $status,
            'created_by_user_id' => $actor->id,
            'classified_by_user_id' => $actor->id,
            'classified_at' => now(),
            'opened_at' => now(),
        ];
    }

    private function sourceTypeForSourceLink(SuchakBiodataIntakeLink $sourceLink): string
    {
        return $sourceLink->source_status === SuchakBiodataIntakeLink::STATUS_LINKED_TO_EXISTING_PROFILE
            ? SuchakCustomerContext::SOURCE_TYPE_EXISTING_PROFILE_MATCH
            : SuchakCustomerContext::SOURCE_TYPE_INTAKE_UPLOAD;
    }

    private function sourceTypeForRepresentation(SuchakProfileRepresentation $representation): string
    {
        return match ($representation->representation_mode) {
            SuchakProfileRepresentation::MODE_MATCHED_EXISTING_PROFILE => SuchakCustomerContext::SOURCE_TYPE_EXISTING_PROFILE_MATCH,
            SuchakProfileRepresentation::MODE_CANDIDATE_INVITED_SUCHAK => SuchakCustomerContext::SOURCE_TYPE_CANDIDATE_INVITED,
            SuchakProfileRepresentation::MODE_ADMIN_ASSIGNED => SuchakCustomerContext::SOURCE_TYPE_ADMIN_ASSIGNED,
            SuchakProfileRepresentation::MODE_MANUAL_FORM_BY_SUCHAK => SuchakCustomerContext::SOURCE_TYPE_MANUAL,
            default => SuchakCustomerContext::SOURCE_TYPE_INTAKE_UPLOAD,
        };
    }

    private function statusForRepresentation(SuchakProfileRepresentation $representation): string
    {
        return match ($representation->representation_status) {
            SuchakProfileRepresentation::STATUS_CONSENT_PENDING => SuchakCustomerContext::STATUS_CONSENT_PENDING,
            SuchakProfileRepresentation::STATUS_ACTIVE => SuchakCustomerContext::STATUS_ACTIVE_SERVICE,
            SuchakProfileRepresentation::STATUS_REVOKED,
            SuchakProfileRepresentation::STATUS_EXPIRED,
            SuchakProfileRepresentation::STATUS_REJECTED,
            SuchakProfileRepresentation::STATUS_SUSPENDED,
            SuchakProfileRepresentation::STATUS_CANDIDATE_DEACTIVATED => SuchakCustomerContext::STATUS_CLOSED,
            default => SuchakCustomerContext::STATUS_CANDIDATE_IDENTIFIED,
        };
    }

    private function nullableUserId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) User::query()->findOrFail((int) $value)->id;
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

    private function limitedText(mixed $value, int $limit): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized === ''
            ? null
            : Str::limit($normalized, $limit, '');
    }

    private function recordTimeline(
        SuchakCustomerContext $context,
        string $eventType,
        User $actor,
        ?string $fromStatus,
        ?string $toStatus,
        ?string $eventNote,
    ): void {
        SuchakCustomerTimelineEvent::query()->create([
            'customer_context_id' => $context->id,
            'suchak_account_id' => $context->suchak_account_id,
            'candidate_matrimony_profile_id' => $context->candidate_matrimony_profile_id,
            'event_type' => $eventType,
            'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
            'actor_user_id' => $actor->id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'event_note' => $eventNote,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }

    private function recordActivity(
        SuchakCustomerContext $context,
        User $actor,
        string $actionType,
        string $activityContext,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        $this->activityLogger->record([
            'suchak_account_id' => $context->suchak_account_id,
            'actor_user_id' => $actor->id,
            'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
            'action_type' => $actionType,
            'target_type' => 'suchak_customer_context',
            'target_id' => $context->id,
            'matrimony_profile_id' => $context->candidate_matrimony_profile_id,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent === null ? null : Str::limit($userAgent, 512, ''),
            'metadata_json' => [
                'context' => $activityContext,
                'service_context' => $context->service_context,
                'source_owner' => $context->source_owner,
                'source_type' => $context->source_type,
                'customer_lifecycle_status' => $context->customer_lifecycle_status,
                'source_link_id' => $context->source_link_id,
                'representation_id' => $context->representation_id,
                'has_payer_user' => $context->payer_user_id !== null,
                'has_consent_giver' => $context->consent_id !== null || $context->consent_giver_user_id !== null || $context->consent_giver_name !== null,
                'suchak_account_id' => $context->suchak_account_id,
                'matrimony_profile_id' => $context->candidate_matrimony_profile_id,
            ],
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function relations(): array
    {
        return [
            'suchakAccount',
            'candidateProfile',
            'sourceLink',
            'representation',
            'payerUser',
            'consent',
            'consentGiverUser',
            'timelineEvents',
        ];
    }
}
