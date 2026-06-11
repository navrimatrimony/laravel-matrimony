<?php

namespace App\Modules\Suchak\Services;

use App\Models\AdminAuditLog;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakFeatureSuspension;
use App\Models\SuchakGrowthAttribution;
use App\Models\SuchakGrowthReward;
use App\Models\SuchakGrowthRewardEvent;
use App\Models\SuchakGrowthRewardRule;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakPlatformPayout;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SuchakGrowthRewardService
{
    public function __construct(
        private readonly SuchakAccessService $accessService,
        private readonly SuchakActivityLogger $activityLogger,
        private readonly SuchakPlatformPayoutService $platformPayoutService,
        private readonly SuchakQualityControlService $qualityControlService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function recordAttribution(
        SuchakAccount $account,
        User $admin,
        array $attributes,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakGrowthAttribution {
        $this->accessService->assertAdmin($admin, 'Only admins can record Suchak growth attribution.');
        $account = $account->fresh();
        $this->accessService->assertCanOperate($account, 'Only verified Suchak accounts can receive growth attribution.');
        $this->qualityControlService->assertFeatureAvailable($account, SuchakFeatureSuspension::FEATURE_REFERRAL);

        $source = $this->requiredAllowedValue(
            $attributes['attribution_source'] ?? null,
            SuchakGrowthAttribution::SOURCES,
            'Suchak growth attribution source is invalid.',
        );
        $policy = $this->requiredAllowedValue(
            $attributes['attribution_policy'] ?? $this->defaultPolicyForSource($source),
            SuchakGrowthAttribution::POLICIES,
            'Suchak growth attribution policy is invalid.',
        );
        $attributionKey = $this->normalizedKey($attributes['attribution_key'] ?? null);
        $attributedUserId = $this->nullableId($attributes['attributed_user_id'] ?? null);
        $matrimonyProfileId = $this->nullableId($attributes['matrimony_profile_id'] ?? null);
        $customerContextId = $this->nullableId($attributes['customer_context_id'] ?? null);
        $paymentContextId = $this->nullableId($attributes['payment_context_id'] ?? null);
        $referralCode = $this->codeForSource($source, $attributes['referral_code'] ?? null, SuchakGrowthAttribution::SOURCE_REFERRAL_CODE);
        $couponCode = $this->codeForSource($source, $attributes['coupon_code'] ?? null, SuchakGrowthAttribution::SOURCE_COUPON_CODE);
        $note = $this->requiredText($attributes['attribution_note'] ?? null, 'Suchak growth attribution note is required.', 1000);

        return DB::transaction(function () use (
            $account,
            $admin,
            $source,
            $policy,
            $attributionKey,
            $attributedUserId,
            $matrimonyProfileId,
            $customerContextId,
            $paymentContextId,
            $referralCode,
            $couponCode,
            $note,
            $ipAddress,
            $userAgent,
        ): SuchakGrowthAttribution {
            $existing = SuchakGrowthAttribution::query()
                ->where('suchak_account_id', $account->id)
                ->where('attribution_source', $source)
                ->where('attribution_key', $attributionKey)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof SuchakGrowthAttribution) {
                throw new InvalidArgumentException('Suchak growth attribution already exists for this source and key.');
            }

            $fraudFlags = $this->fraudFlagsForAttribution($account, $attributedUserId, $source);
            $fraudStatus = $fraudFlags === []
                ? SuchakGrowthAttribution::FRAUD_CLEAR
                : SuchakGrowthAttribution::FRAUD_REVIEW_REQUIRED;
            $status = $fraudFlags === []
                ? SuchakGrowthAttribution::STATUS_ACTIVE
                : SuchakGrowthAttribution::STATUS_REVIEW_REQUIRED;

            $attribution = SuchakGrowthAttribution::query()->create([
                'suchak_account_id' => $account->id,
                'attributed_user_id' => $attributedUserId,
                'matrimony_profile_id' => $matrimonyProfileId,
                'customer_context_id' => $customerContextId,
                'payment_context_id' => $paymentContextId,
                'attribution_source' => $source,
                'attribution_policy' => $policy,
                'attribution_key' => $attributionKey,
                'referral_code' => $referralCode,
                'coupon_code' => $couponCode,
                'attribution_status' => $status,
                'fraud_status' => $fraudStatus,
                'fraud_flags' => $fraudFlags === [] ? null : $fraudFlags,
                'attribution_note' => $note,
                'attributed_by_admin_user_id' => $admin->id,
                'attributed_at' => now(),
            ]);

            $fresh = $attribution->fresh($this->attributionRelations());
            $this->recordGrowthEvent(
                null,
                $fresh,
                SuchakGrowthRewardEvent::EVENT_ATTRIBUTION_RECORDED,
                $admin,
                null,
                $fresh->attribution_status,
                $note,
                [
                    'attribution_source' => $source,
                    'attribution_policy' => $policy,
                    'fraud_status' => $fraudStatus,
                    'fraud_flags' => $fraudFlags,
                ],
            );

            if ($fraudFlags !== []) {
                $this->recordGrowthEvent(
                    null,
                    $fresh,
                    SuchakGrowthRewardEvent::EVENT_FRAUD_REVIEW_REQUIRED,
                    $admin,
                    null,
                    $fresh->attribution_status,
                    'Suchak growth attribution requires fraud review.',
                    ['fraud_flags' => $fraudFlags],
                );
            }

            $audit = $this->writeAdminAuditLog(
                $admin,
                'suchak_growth_attribution_recorded',
                $fresh,
                $note,
                [],
                [
                    'attribution_status' => $fresh->attribution_status,
                    'fraud_status' => $fresh->fraud_status,
                    'attribution_source' => $fresh->attribution_source,
                    'attribution_policy' => $fresh->attribution_policy,
                ],
            );
            $this->recordActivity(
                $fresh->suchak_account_id,
                $admin,
                $audit,
                SuchakActivityLog::ACTION_GROWTH_ATTRIBUTION_RECORDED,
                'suchak_growth_attribution',
                $fresh->id,
                $fresh->matrimony_profile_id,
                [
                    'context' => 'growth_attribution_recorded',
                    'attribution_status' => $fresh->attribution_status,
                    'fraud_status' => $fresh->fraud_status,
                    'attribution_source' => $fresh->attribution_source,
                    'attribution_policy' => $fresh->attribution_policy,
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
    public function createRewardRule(
        User $admin,
        array $attributes,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakGrowthRewardRule {
        $this->accessService->assertAdmin($admin, 'Only admins can create Suchak growth reward rules.');

        $ruleKey = $this->ruleKey($attributes['rule_key'] ?? null);
        $trigger = $this->requiredAllowedValue(
            $attributes['reward_trigger'] ?? SuchakGrowthRewardRule::TRIGGER_PLATFORM_PAYMENT_CONFIRMED,
            SuchakGrowthRewardRule::TRIGGERS,
            'Suchak growth reward trigger is invalid.',
        );
        $type = $this->requiredAllowedValue(
            $attributes['reward_type'] ?? null,
            SuchakGrowthRewardRule::TYPES,
            'Suchak growth reward type is invalid.',
        );
        $policy = $this->requiredAllowedValue(
            $attributes['attribution_policy'] ?? SuchakGrowthAttribution::POLICY_COUPON_PRIORITY,
            SuchakGrowthAttribution::POLICIES,
            'Suchak growth reward attribution policy is invalid.',
        );
        $currency = $this->currency($attributes['reward_currency'] ?? 'INR');
        $rewardAmount = $this->money($attributes['reward_amount'] ?? 0, 'Suchak growth reward amount is invalid.');
        $creditValue = $this->money($attributes['credit_value'] ?? 0, 'Suchak growth reward credit value is invalid.');
        $adminActionKey = $this->nullableLimitedText($attributes['admin_action_key'] ?? null, 96);
        [$rewardAmount, $creditValue, $adminActionKey] = $this->normalizeRuleValues($type, $rewardAmount, $creditValue, $adminActionKey);
        $startsAt = $this->nullableDateTime($attributes['starts_at'] ?? null, 'Suchak growth reward start date is invalid.');
        $endsAt = $this->nullableDateTime($attributes['ends_at'] ?? null, 'Suchak growth reward end date is invalid.');

        if ($startsAt instanceof Carbon && $endsAt instanceof Carbon && $endsAt->lt($startsAt)) {
            throw new InvalidArgumentException('Suchak growth reward end date must be after start date.');
        }

        return DB::transaction(function () use (
            $admin,
            $ruleKey,
            $trigger,
            $type,
            $policy,
            $rewardAmount,
            $currency,
            $creditValue,
            $adminActionKey,
            $startsAt,
            $endsAt,
        ): SuchakGrowthRewardRule {
            $existing = SuchakGrowthRewardRule::query()
                ->where('rule_key', $ruleKey)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof SuchakGrowthRewardRule) {
                throw new InvalidArgumentException('Suchak growth reward rule key is already used.');
            }

            $rule = SuchakGrowthRewardRule::query()->create([
                'rule_key' => $ruleKey,
                'reward_trigger' => $trigger,
                'reward_type' => $type,
                'attribution_policy' => $policy,
                'reward_amount' => $rewardAmount,
                'reward_currency' => $currency,
                'credit_value' => $creditValue,
                'admin_action_key' => $adminActionKey,
                'is_active' => true,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'created_by_admin_user_id' => $admin->id,
            ]);

            $this->writeAdminAuditLog(
                $admin,
                'suchak_growth_reward_rule_created',
                $rule,
                'Suchak growth reward rule created.',
                [],
                [
                    'rule_key' => $rule->rule_key,
                    'reward_trigger' => $rule->reward_trigger,
                    'reward_type' => $rule->reward_type,
                    'attribution_policy' => $rule->attribution_policy,
                ],
            );

            $rule->setRelation('createdByAdmin', $admin);

            return $rule->fresh(['createdByAdmin']);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function qualifyRewardFromPlatformPayment(
        SuchakGrowthAttribution $attribution,
        SuchakPaymentContext $paymentContext,
        SuchakGrowthRewardRule $rule,
        User $admin,
        array $attributes = [],
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakGrowthReward {
        $this->accessService->assertAdmin($admin, 'Only admins can qualify Suchak growth rewards.');
        $paymentContext = $paymentContext->fresh(['suchakAccount', 'customerContext', 'matrimonyProfile']);
        $attribution = $attribution->fresh(['suchakAccount']);
        $rule = $rule->fresh();

        $this->assertPlatformPaymentContext($paymentContext);
        $this->assertAttributionQualifies($attribution, $paymentContext);
        $this->assertRuleQualifies($rule);
        $this->qualityControlService->assertFeatureAvailable($paymentContext->suchakAccount, SuchakFeatureSuspension::FEATURE_REFERRAL);

        $qualificationNote = $this->requiredText(
            $attributes['qualification_note'] ?? null,
            'Suchak growth reward qualification note is required.',
            1000,
        );
        $eventKey = $this->platformEventKey($attributes['platform_event_key'] ?? null, $attribution, $paymentContext, $rule);

        return DB::transaction(function () use (
            $attribution,
            $paymentContext,
            $rule,
            $admin,
            $qualificationNote,
            $eventKey,
            $ipAddress,
            $userAgent,
        ): SuchakGrowthReward {
            $existing = SuchakGrowthReward::query()
                ->where('growth_attribution_id', $attribution->id)
                ->where('payment_context_id', $paymentContext->id)
                ->where('reward_rule_id', $rule->id)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof SuchakGrowthReward) {
                throw new InvalidArgumentException('Suchak growth reward already exists for this attribution, payment context, and rule.');
            }

            $status = $this->statusForRuleType($rule->reward_type);
            $reward = SuchakGrowthReward::query()->create([
                'growth_attribution_id' => $attribution->id,
                'reward_rule_id' => $rule->id,
                'suchak_account_id' => $paymentContext->suchak_account_id,
                'customer_context_id' => $paymentContext->customer_context_id,
                'payment_context_id' => $paymentContext->id,
                'matrimony_profile_id' => $paymentContext->matrimony_profile_id,
                'platform_event_key' => $eventKey,
                'reward_trigger' => $rule->reward_trigger,
                'reward_type' => $rule->reward_type,
                'reward_status' => $status,
                'reward_amount' => $rule->reward_amount,
                'reward_currency' => $rule->reward_currency,
                'credit_value' => $rule->credit_value,
                'admin_action_key' => $rule->admin_action_key,
                'qualification_source' => SuchakGrowthReward::SOURCE_PLATFORM_CONFIRMED_PAYMENT,
                'fraud_status' => $attribution->fraud_status,
                'fraud_flags' => $attribution->fraud_flags,
                'qualified_by_admin_user_id' => $admin->id,
                'qualified_at' => now(),
            ]);

            if ($rule->reward_type === SuchakGrowthRewardRule::TYPE_CASH) {
                $payout = $this->platformPayoutService->qualifyFromPlatformEvent(
                    $paymentContext,
                    $admin,
                    [
                        'platform_event_type' => SuchakPlatformPayout::EVENT_PLATFORM_GROWTH_REWARD_CONFIRMED,
                        'platform_event_key' => $eventKey,
                        'payout_reason' => SuchakPlatformPayout::REASON_PLATFORM_GROWTH_REWARD,
                        'qualification_source' => SuchakPlatformPayout::SOURCE_PLATFORM_CONFIRMED_EVENT,
                        'amount' => $rule->reward_amount,
                        'currency' => $rule->reward_currency,
                        'qualification_note' => $qualificationNote,
                    ],
                    $ipAddress,
                    $userAgent,
                );

                $reward->forceFill([
                    'platform_payout_id' => $payout->id,
                    'reward_status' => SuchakGrowthReward::STATUS_PAYOUT_QUALIFIED,
                ])->save();
            }

            $attribution->forceFill(['attribution_status' => SuchakGrowthAttribution::STATUS_REWARDED])->save();

            $fresh = $reward->fresh($this->rewardRelations());
            $this->recordGrowthEvent(
                $fresh,
                $fresh->attribution,
                SuchakGrowthRewardEvent::EVENT_REWARD_QUALIFIED,
                $admin,
                null,
                $fresh->reward_status,
                $qualificationNote,
                [
                    'reward_type' => $fresh->reward_type,
                    'qualification_source' => $fresh->qualification_source,
                    'platform_event_key' => $fresh->platform_event_key,
                    'platform_payout_id' => $fresh->platform_payout_id,
                ],
            );

            $audit = $this->writeAdminAuditLog(
                $admin,
                'suchak_growth_reward_qualified',
                $fresh,
                $qualificationNote,
                [],
                [
                    'reward_status' => $fresh->reward_status,
                    'reward_type' => $fresh->reward_type,
                    'platform_event_key' => $fresh->platform_event_key,
                    'platform_payout_id' => $fresh->platform_payout_id,
                ],
            );
            $this->recordActivity(
                $fresh->suchak_account_id,
                $admin,
                $audit,
                SuchakActivityLog::ACTION_GROWTH_REWARD_QUALIFIED,
                'suchak_growth_reward',
                $fresh->id,
                $fresh->matrimony_profile_id,
                [
                    'context' => 'growth_reward_qualified',
                    'reward_status' => $fresh->reward_status,
                    'reward_type' => $fresh->reward_type,
                    'payment_context_id' => $fresh->payment_context_id,
                    'platform_payout_id' => $fresh->platform_payout_id,
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
    public function reverseRewardForRefund(
        SuchakGrowthReward $reward,
        User $admin,
        array $attributes,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakGrowthReward {
        $this->accessService->assertAdmin($admin, 'Only admins can reverse Suchak growth rewards.');
        $reason = $this->requiredText(
            $attributes['reversal_reason'] ?? null,
            'Suchak growth reward reversal reason is required.',
            1000,
        );

        return DB::transaction(function () use ($reward, $admin, $reason, $ipAddress, $userAgent): SuchakGrowthReward {
            /** @var SuchakGrowthReward $locked */
            $locked = SuchakGrowthReward::query()
                ->with(['attribution', 'platformPayout'])
                ->whereKey($reward->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->reward_status === SuchakGrowthReward::STATUS_REVERSED) {
                throw new InvalidArgumentException('Suchak growth reward is already reversed.');
            }

            $fromStatus = $locked->reward_status;
            if ($locked->platformPayout instanceof SuchakPlatformPayout) {
                if ($locked->platformPayout->payout_status === SuchakPlatformPayout::STATUS_PAID) {
                    $this->platformPayoutService->reversePayout(
                        $locked->platformPayout,
                        $admin,
                        ['reversal_reason' => $reason],
                        $ipAddress,
                        $userAgent,
                    );
                } elseif (! in_array($locked->platformPayout->payout_status, [
                    SuchakPlatformPayout::STATUS_CANCELLED,
                    SuchakPlatformPayout::STATUS_REVERSED,
                ], true)) {
                    $this->platformPayoutService->cancelPayout(
                        $locked->platformPayout,
                        $admin,
                        $reason,
                        $ipAddress,
                        $userAgent,
                    );
                }
            }

            $locked->forceFill([
                'reward_status' => SuchakGrowthReward::STATUS_REVERSED,
                'reversed_by_admin_user_id' => $admin->id,
                'reversed_at' => now(),
                'reversal_reason' => $reason,
            ])->save();

            if ($locked->attribution instanceof SuchakGrowthAttribution) {
                $locked->attribution->forceFill([
                    'attribution_status' => SuchakGrowthAttribution::STATUS_REVERSED,
                ])->save();
            }

            $fresh = $locked->fresh($this->rewardRelations());
            $this->recordGrowthEvent(
                $fresh,
                $fresh->attribution,
                SuchakGrowthRewardEvent::EVENT_REWARD_REVERSED,
                $admin,
                $fromStatus,
                $fresh->reward_status,
                $reason,
                [
                    'platform_payout_id' => $fresh->platform_payout_id,
                    'reversal_reason' => $reason,
                ],
            );

            $audit = $this->writeAdminAuditLog(
                $admin,
                'suchak_growth_reward_reversed',
                $fresh,
                $reason,
                ['reward_status' => $fromStatus],
                ['reward_status' => $fresh->reward_status],
            );
            $this->recordActivity(
                $fresh->suchak_account_id,
                $admin,
                $audit,
                SuchakActivityLog::ACTION_GROWTH_REWARD_REVERSED,
                'suchak_growth_reward',
                $fresh->id,
                $fresh->matrimony_profile_id,
                [
                    'context' => 'growth_reward_reversed',
                    'from_status' => $fromStatus,
                    'to_status' => $fresh->reward_status,
                    'platform_payout_id' => $fresh->platform_payout_id,
                ],
                $ipAddress,
                $userAgent,
            );

            return $fresh;
        });
    }

    /**
     * @return array<int, string>
     */
    private function attributionRelations(): array
    {
        return [
            'suchakAccount',
            'attributedUser',
            'matrimonyProfile',
            'customerContext',
            'paymentContext',
            'attributedByAdmin',
            'events',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function rewardRelations(): array
    {
        return [
            'attribution',
            'rewardRule',
            'suchakAccount',
            'customerContext',
            'paymentContext',
            'matrimonyProfile',
            'platformPayout',
            'qualifiedByAdmin',
            'reversedByAdmin',
            'events',
        ];
    }

    private function defaultPolicyForSource(string $source): string
    {
        return match ($source) {
            SuchakGrowthAttribution::SOURCE_ADMIN_OVERRIDE => SuchakGrowthAttribution::POLICY_ADMIN_OVERRIDE,
            SuchakGrowthAttribution::SOURCE_COUPON_CODE => SuchakGrowthAttribution::POLICY_COUPON_PRIORITY,
            default => SuchakGrowthAttribution::POLICY_FIRST_TOUCH,
        };
    }

    /**
     * @return array<int, string>
     */
    private function fraudFlagsForAttribution(SuchakAccount $account, ?int $attributedUserId, string $source): array
    {
        $flags = [];

        if ($attributedUserId !== null && (int) $account->user_id === $attributedUserId) {
            $flags[] = 'self_referral';
        }

        if ($source === SuchakGrowthAttribution::SOURCE_ADMIN_OVERRIDE && $attributedUserId !== null && (int) $account->user_id === $attributedUserId) {
            $flags[] = 'admin_override_self_referral';
        }

        return array_values(array_unique($flags));
    }

    private function assertPlatformPaymentContext(SuchakPaymentContext $paymentContext): void
    {
        if ($paymentContext->context_status !== SuchakPaymentContext::STATUS_ACTIVE
            || $paymentContext->source_owner !== SuchakPaymentContext::SOURCE_PLATFORM
            || $paymentContext->payment_collector !== SuchakPaymentContext::COLLECTOR_PLATFORM) {
            throw new InvalidArgumentException('Suchak growth rewards qualify only from active platform-collected payment contexts.');
        }
    }

    private function assertAttributionQualifies(SuchakGrowthAttribution $attribution, SuchakPaymentContext $paymentContext): void
    {
        if ((int) $attribution->suchak_account_id !== (int) $paymentContext->suchak_account_id) {
            throw new InvalidArgumentException('Suchak growth attribution does not belong to this payment context account.');
        }

        if ($attribution->attribution_status !== SuchakGrowthAttribution::STATUS_ACTIVE) {
            throw new InvalidArgumentException('Only active Suchak growth attribution can qualify rewards.');
        }

        if ($attribution->fraud_status !== SuchakGrowthAttribution::FRAUD_CLEAR) {
            throw new InvalidArgumentException('Suchak growth attribution requires fraud review before reward qualification.');
        }

        if ($attribution->payment_context_id !== null && (int) $attribution->payment_context_id !== (int) $paymentContext->id) {
            throw new InvalidArgumentException('Suchak growth attribution is tied to a different payment context.');
        }

        if ($attribution->customer_context_id !== null && (int) $attribution->customer_context_id !== (int) $paymentContext->customer_context_id) {
            throw new InvalidArgumentException('Suchak growth attribution is tied to a different customer context.');
        }
    }

    private function assertRuleQualifies(SuchakGrowthRewardRule $rule): void
    {
        if (! $rule->is_active) {
            throw new InvalidArgumentException('Inactive Suchak growth reward rules cannot qualify rewards.');
        }

        if ($rule->reward_trigger !== SuchakGrowthRewardRule::TRIGGER_PLATFORM_PAYMENT_CONFIRMED) {
            throw new InvalidArgumentException('Suchak growth reward rule trigger must be platform payment confirmed.');
        }

        $now = now();
        if ($rule->starts_at instanceof Carbon && $rule->starts_at->isFuture()) {
            throw new InvalidArgumentException('Suchak growth reward rule has not started.');
        }

        if ($rule->ends_at instanceof Carbon && $rule->ends_at->lt($now)) {
            throw new InvalidArgumentException('Suchak growth reward rule has expired.');
        }
    }

    private function statusForRuleType(string $rewardType): string
    {
        return match ($rewardType) {
            SuchakGrowthRewardRule::TYPE_CREDIT => SuchakGrowthReward::STATUS_CREDITED,
            SuchakGrowthRewardRule::TYPE_ADMIN_ACTION => SuchakGrowthReward::STATUS_ADMIN_ACTION_PENDING,
            default => SuchakGrowthReward::STATUS_QUALIFIED,
        };
    }

    private function platformEventKey(
        mixed $value,
        SuchakGrowthAttribution $attribution,
        SuchakPaymentContext $paymentContext,
        SuchakGrowthRewardRule $rule,
    ): string {
        $text = $this->nullableLimitedText($value, 160);
        if ($text !== null) {
            return $text;
        }

        return sprintf(
            'growth-attribution-%d-payment-context-%d-rule-%d',
            $attribution->id,
            $paymentContext->id,
            $rule->id,
        );
    }

    private function codeForSource(string $source, mixed $value, string $requiredForSource): ?string
    {
        $code = $this->nullableLimitedText($value, 80);
        if ($source === $requiredForSource && $code === null) {
            throw new InvalidArgumentException('Suchak growth attribution code is required for this source.');
        }

        return $code === null ? null : strtoupper($code);
    }

    private function normalizedKey(mixed $value): string
    {
        $text = $this->requiredText($value, 'Suchak growth attribution key is required.', 160);

        return Str::lower($text);
    }

    private function ruleKey(mixed $value): string
    {
        $key = Str::lower($this->requiredText($value, 'Suchak growth reward rule key is required.', 96));
        if (! preg_match('/^[a-z0-9][a-z0-9._-]{2,95}$/', $key)) {
            throw new InvalidArgumentException('Suchak growth reward rule key format is invalid.');
        }

        return $key;
    }

    /**
     * @return array{0: string, 1: string, 2: ?string}
     */
    private function normalizeRuleValues(string $type, string $rewardAmount, string $creditValue, ?string $adminActionKey): array
    {
        if ($type === SuchakGrowthRewardRule::TYPE_CASH && (float) $rewardAmount <= 0) {
            throw new InvalidArgumentException('Cash Suchak growth reward amount must be greater than zero.');
        }

        if ($type === SuchakGrowthRewardRule::TYPE_CREDIT && (float) $creditValue <= 0) {
            throw new InvalidArgumentException('Credit Suchak growth reward value must be greater than zero.');
        }

        if ($type === SuchakGrowthRewardRule::TYPE_ADMIN_ACTION && $adminActionKey === null) {
            throw new InvalidArgumentException('Admin-action Suchak growth reward key is required.');
        }

        if ($type !== SuchakGrowthRewardRule::TYPE_CREDIT) {
            $creditValue = '0.00';
        }

        if ($type !== SuchakGrowthRewardRule::TYPE_CASH) {
            $rewardAmount = '0.00';
        }

        if ($type !== SuchakGrowthRewardRule::TYPE_ADMIN_ACTION) {
            $adminActionKey = null;
        }

        return [$rewardAmount, $creditValue, $adminActionKey];
    }

    private function recordGrowthEvent(
        ?SuchakGrowthReward $reward,
        ?SuchakGrowthAttribution $attribution,
        string $eventType,
        User $admin,
        ?string $fromStatus,
        ?string $toStatus,
        ?string $eventNote,
        array $metadata = [],
    ): SuchakGrowthRewardEvent {
        return SuchakGrowthRewardEvent::query()->create([
            'growth_reward_id' => $reward?->id,
            'growth_attribution_id' => $attribution?->id,
            'suchak_account_id' => $reward?->suchak_account_id ?? $attribution?->suchak_account_id,
            'event_type' => $eventType,
            'actor_type' => SuchakGrowthRewardEvent::ACTOR_ADMIN,
            'actor_user_id' => $admin->id,
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
        int $suchakAccountId,
        User $admin,
        AdminAuditLog $adminAuditLog,
        string $actionType,
        string $targetType,
        int $targetId,
        ?int $matrimonyProfileId,
        array $metadata,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        $this->activityLogger->record([
            'suchak_account_id' => $suchakAccountId,
            'actor_user_id' => $admin->id,
            'actor_type' => SuchakActivityLog::ACTOR_ADMIN,
            'action_type' => $actionType,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'matrimony_profile_id' => $matrimonyProfileId,
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
    private function requiredAllowedValue(mixed $value, array $allowed, string $message): string
    {
        $normalized = trim((string) ($value ?? ''));
        if (! in_array($normalized, $allowed, true)) {
            throw new InvalidArgumentException($message);
        }

        return $normalized;
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

    private function nullableId(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        if (! is_numeric($value) || (int) $value <= 0) {
            throw new InvalidArgumentException('Suchak growth identifier is invalid.');
        }

        return (int) $value;
    }

    private function money(mixed $value, string $message): string
    {
        if (! is_numeric($value) || (float) $value < 0) {
            throw new InvalidArgumentException($message);
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function currency(mixed $value): string
    {
        $currency = strtoupper(trim((string) ($value ?? 'INR')));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('Suchak growth reward currency is invalid.');
        }

        return $currency;
    }

    private function nullableDateTime(mixed $value, string $message): ?Carbon
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            throw new InvalidArgumentException($message);
        }
    }
}
