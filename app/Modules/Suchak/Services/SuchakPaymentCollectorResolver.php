<?php

namespace App\Modules\Suchak\Services;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakPipeline;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SuchakPaymentCollectorResolver
{
    public function __construct(
        private readonly SuchakActivityLogger $activityLogger,
    ) {
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function resolveForManualLedger(
        SuchakAccount $account,
        User $actor,
        MatrimonyProfile $profile,
        array $attributes,
        ?SuchakPipeline $pipeline,
        ?SuchakCollaborationRequest $collaboration,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakPaymentContext {
        $context = $this->resolveContext(
            $account,
            $actor,
            $profile,
            $attributes,
            $pipeline,
            $collaboration,
            $ipAddress,
            $userAgent,
        );

        $this->assertAllowsDirectSuchakCollection($context);

        return $context;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function resolveContext(
        SuchakAccount $account,
        User $actor,
        MatrimonyProfile $profile,
        array $attributes,
        ?SuchakPipeline $pipeline,
        ?SuchakCollaborationRequest $collaboration,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakPaymentContext {
        if (($attributes['payment_context_id'] ?? null) !== null && $attributes['payment_context_id'] !== '') {
            $context = SuchakPaymentContext::query()->findOrFail((int) $attributes['payment_context_id']);
            $this->assertContextMatches($context, $account, $profile, $pipeline, $collaboration);

            return $context;
        }

        $sourceOwner = $this->allowedValue(
            $attributes['source_owner'] ?? null,
            SuchakPaymentContext::SOURCE_OWNERS,
            'Suchak payment source owner must be resolved before ledger payment entries.',
        );
        $collector = $this->allowedValue(
            $attributes['payment_collector'] ?? null,
            SuchakPaymentContext::PAYMENT_COLLECTORS,
            'Suchak payment collector must be resolved before ledger payment entries.',
        );
        $this->assertOwnerCollectorRule($sourceOwner, $collector, $collaboration);

        $existing = SuchakPaymentContext::query()
            ->where('suchak_account_id', $account->id)
            ->where('matrimony_profile_id', $profile->id)
            ->where('pipeline_id', $pipeline?->id)
            ->where('collaboration_request_id', $collaboration?->id)
            ->where('context_status', SuchakPaymentContext::STATUS_ACTIVE)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            if ($existing->source_owner === $sourceOwner && $existing->payment_collector === $collector) {
                return $existing->fresh(['suchakAccount', 'matrimonyProfile', 'pipeline', 'collaborationRequest']);
            }

            throw new InvalidArgumentException('Suchak payment collector is already locked for this customer context.');
        }

        $context = SuchakPaymentContext::query()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'pipeline_id' => $pipeline?->id,
            'collaboration_request_id' => $collaboration?->id,
            'source_owner' => $sourceOwner,
            'payment_collector' => $collector,
            'context_status' => SuchakPaymentContext::STATUS_ACTIVE,
            'resolved_by_user_id' => $actor->id,
            'resolution_note' => $this->limitedText($attributes['resolution_note'] ?? null),
        ]);

        $this->activityLogger->record([
            'suchak_account_id' => $account->id,
            'actor_user_id' => $actor->id,
            'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
            'action_type' => SuchakActivityLog::ACTION_PAYMENT_CONTEXT_RESOLVED,
            'target_type' => 'suchak_payment_context',
            'target_id' => $context->id,
            'matrimony_profile_id' => $profile->id,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent === null ? null : Str::limit($userAgent, 512, ''),
            'metadata_json' => [
                'context' => 'payment_context_resolved',
                'source_owner' => $context->source_owner,
                'payment_collector' => $context->payment_collector,
                'pipeline_id' => $context->pipeline_id,
                'collaboration_request_id' => $context->collaboration_request_id,
                'suchak_account_id' => $account->id,
                'matrimony_profile_id' => $profile->id,
            ],
        ]);

        return $context->fresh(['suchakAccount', 'matrimonyProfile', 'pipeline', 'collaborationRequest']);
    }

    public function assertAllowsDirectSuchakCollection(SuchakPaymentContext $context): void
    {
        if ($context->source_owner === SuchakPaymentContext::SOURCE_PLATFORM) {
            throw new InvalidArgumentException(SuchakPaymentContext::PLATFORM_DIRECT_PAYMENT_BLOCK_MESSAGE);
        }

        if ($context->payment_collector !== SuchakPaymentContext::COLLECTOR_SUCHAK) {
            throw new InvalidArgumentException(SuchakPaymentContext::DIRECT_PAYMENT_BLOCK_MESSAGE);
        }
    }

    public function assertContextMatches(
        SuchakPaymentContext $context,
        SuchakAccount $account,
        MatrimonyProfile $profile,
        ?SuchakPipeline $pipeline,
        ?SuchakCollaborationRequest $collaboration,
    ): void {
        if ((int) $context->suchak_account_id !== (int) $account->id) {
            throw new InvalidArgumentException('Suchak payment context must belong to the Suchak account.');
        }

        if ((int) $context->matrimony_profile_id !== (int) $profile->id) {
            throw new InvalidArgumentException('Suchak payment context profile mismatch.');
        }

        if ((int) ($context->pipeline_id ?? 0) !== (int) ($pipeline?->id ?? 0)) {
            throw new InvalidArgumentException('Suchak payment context pipeline mismatch.');
        }

        if ((int) ($context->collaboration_request_id ?? 0) !== (int) ($collaboration?->id ?? 0)) {
            throw new InvalidArgumentException('Suchak payment context collaboration mismatch.');
        }

        if ($context->context_status !== SuchakPaymentContext::STATUS_ACTIVE) {
            throw new InvalidArgumentException('Suchak payment context is not active.');
        }
    }

    private function assertOwnerCollectorRule(string $sourceOwner, string $collector, ?SuchakCollaborationRequest $collaboration): void
    {
        if ($sourceOwner === SuchakPaymentContext::SOURCE_PLATFORM && $collector !== SuchakPaymentContext::COLLECTOR_PLATFORM) {
            throw new InvalidArgumentException('Platform-owned Suchak customer payments must be collected by platform.');
        }

        if ($sourceOwner === SuchakPaymentContext::SOURCE_COLLABORATION && $collaboration === null) {
            throw new InvalidArgumentException('Collaboration-owned Suchak payment context requires a collaboration request.');
        }
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

    private function limitedText(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized === ''
            ? null
            : Str::limit($normalized, 1000, '');
    }
}
