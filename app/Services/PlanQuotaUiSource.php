<?php

namespace App\Services;

use App\Exceptions\QuotaPolicySourceViolation;
use App\Models\Plan;
use App\Models\PlanQuotaPolicy;
use App\Models\Subscription;
use App\Models\User;
use App\Support\PlanFeatureKeys;
use App\Support\PlanQuotaPolicyKeys;
use App\Support\PlanQuotaRefreshRuntime;
use Illuminate\Support\Facades\Log;

/**
 * SSOT: existing paid subscription → {@code checkout_snapshot.quota_policies} only (fail closed).
 * Free tier / no subscription / catalog display → {@see Plan::$quotaPolicies}.
 */
final class PlanQuotaUiSource
{
    /**
     * @var array<string, true>
     */
    private static array $loggedMissingQuotaContract = [];

    /**
     * Ensures structural {@code policy_meta} keys exist so mirror strings never depend on legacy {@code plan_features}.
     * In-memory only (does not persist).
     *
     * @param  array<string, array<string, mixed>>  $payloads
     * @return array<string, array<string, mixed>>
     */
    public static function withStructuralPolicyMetaNormalized(array $payloads): array
    {
        $out = $payloads;
        $chatFk = PlanFeatureKeys::CHAT_SEND_LIMIT;
        if (isset($out[$chatFk]) && is_array($out[$chatFk])) {
            $p = $out[$chatFk];
            $m = isset($p['policy_meta']) && is_array($p['policy_meta']) ? $p['policy_meta'] : [];
            if (! array_key_exists('chat_initiate_new_chats_only', $m)) {
                $m['chat_initiate_new_chats_only'] = false;
            }
            $p['policy_meta'] = $m;
            $out[$chatFk] = $p;
        }

        return $out;
    }

    /**
     * @param  array<string, array<string, mixed>>  $payloads
     */
    public static function assertCompleteQuotaPayloads(array $payloads, string $context): void
    {
        foreach (PlanQuotaPolicyKeys::ordered() as $fk) {
            if (! isset($payloads[$fk]) || ! is_array($payloads[$fk])) {
                throw QuotaPolicySourceViolation::missingPolicyRow($context, $fk);
            }
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function policyPayloadsForUser(User $user): array
    {
        $sub = app(ActivePlanResolver::class)->getActiveSubscription($user);

        if ($sub instanceof Subscription) {
            $fromSnap = self::tryPayloadsFromCheckoutSnapshot($sub, 'user_id='.$user->id);
            if ($fromSnap !== null) {
                return $fromSnap;
            }

            if (self::subscriptionIsPaidContract($sub)) {
                self::failClosedMissingQuotaSnapshot($sub, 'policyPayloadsForUser.user_id='.$user->id);
            }
        }

        $plan = app(ActivePlanResolver::class)->get($user);

        return self::policyPayloadsFromPlan($plan, 'effective_plan.user_id='.$user->id);
    }

    /**
     * Resolves payloads for the subscription row being saved (not "latest active"), for entitlement bootstrap.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function policyPayloadsForSubscription(Subscription $subscription): array
    {
        $fromSnap = self::tryPayloadsFromCheckoutSnapshot(
            $subscription,
            'subscription_id='.(int) $subscription->id
        );
        if ($fromSnap !== null) {
            return $fromSnap;
        }

        if (self::subscriptionIsPaidContract($subscription)) {
            self::failClosedMissingQuotaSnapshot(
                $subscription,
                'policyPayloadsForSubscription.subscription_id='.(int) $subscription->id
            );
        }

        $subscription->loadMissing('plan');
        $plan = $subscription->plan;
        if (! $plan instanceof Plan) {
            throw QuotaPolicySourceViolation::incompletePayloads(
                'policyPayloadsForSubscription',
                'subscription missing plan_id='.(int) $subscription->id
            );
        }

        return self::policyPayloadsFromPlan($plan, 'plan_from_subscription.subscription_id='.(int) $subscription->id);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function policyPayloadsFromPlan(Plan $plan, string $context = 'plan'): array
    {
        $plan->loadMissing('quotaPolicies');
        $out = [];
        foreach ($plan->quotaPolicies as $pol) {
            if ($pol instanceof PlanQuotaPolicy) {
                $out[$pol->feature_key] = PlanQuotaPolicyMirror::payloadFromModel($pol);
            }
        }
        self::assertCompleteQuotaPayloads($out, $context);

        return self::withStructuralPolicyMetaNormalized($out);
    }

    /**
     * @param  array<string, array<string, mixed>>  $payloads
     * @return array<string, string>
     */
    public static function mirroredPlanFeatureStringsFromPolicyPayloads(array $payloads): array
    {
        $payloads = self::withStructuralPolicyMetaNormalized($payloads);
        self::assertCompleteQuotaPayloads($payloads, 'mirroredPlanFeatureStringsFromPolicyPayloads');
        $out = [];
        foreach (PlanQuotaPolicyKeys::ordered() as $fk) {
            $payload = $payloads[$fk];
            foreach (PlanQuotaPolicyMirror::mirroredFeatureRowsFromPolicyPayload($fk, $payload) as $row) {
                $out[$row['key']] = $row['value'];
            }
            if ($fk === PlanFeatureKeys::INTEREST_VIEW_LIMIT) {
                $p = strtolower(trim(PlanQuotaRefreshRuntime::interestViewResetPeriodTokenFromPayload($payload)));
                if (! in_array($p, ['weekly', 'monthly', 'quarterly', 'daily', 'lifetime'], true)) {
                    throw QuotaPolicySourceViolation::incompletePayloads(
                        'mirroredPlanFeatureStringsFromPolicyPayloads',
                        'interest_view_limit refresh_type maps to invalid reset token: '.$p
                    );
                }
                $out[PlanFeatureKeys::INTEREST_VIEW_RESET_PERIOD] = $p;
            }
        }

        $cs = $payloads[PlanFeatureKeys::CHAT_SEND_LIMIT];
        $m = $cs['policy_meta'] ?? null;
        if (! is_array($m) || ! array_key_exists('chat_initiate_new_chats_only', $m)) {
            throw QuotaPolicySourceViolation::incompletePayloads(
                'mirroredPlanFeatureStringsFromPolicyPayloads',
                'chat_send_limit.policy_meta must include chat_initiate_new_chats_only'
            );
        }
        $on = filter_var($m['chat_initiate_new_chats_only'], FILTER_VALIDATE_BOOLEAN)
            || $m['chat_initiate_new_chats_only'] === 1
            || $m['chat_initiate_new_chats_only'] === '1';
        $out[PlanFeatureKeys::CHAT_INITIATE_NEW_CHATS_ONLY] = $on ? '1' : '0';

        return $out;
    }

    /**
     * @return array<string, string>
     */
    public static function mirroredPlanFeatureStringsForPlan(Plan $plan, string $context = 'plan'): array
    {
        $payloads = self::policyPayloadsFromPlan($plan, $context);

        return self::mirroredPlanFeatureStringsFromPolicyPayloads($payloads);
    }

    /**
     * @return array<string, string>
     */
    public static function mirroredPlanFeatureStringsForUser(User $user): array
    {
        $payloads = self::policyPayloadsForUser($user);

        return self::mirroredPlanFeatureStringsFromPolicyPayloads($payloads);
    }

    public static function requirePolicyPayloadForUser(User $user, string $featureKey): array
    {
        $payloads = self::policyPayloadsForUser($user);
        if (! isset($payloads[$featureKey]) || ! is_array($payloads[$featureKey])) {
            throw QuotaPolicySourceViolation::missingPolicyRow('requirePolicyPayloadForUser', $featureKey);
        }

        return $payloads[$featureKey];
    }

    public static function chatInitiateNewChatsOnlyForUser(User $user): bool
    {
        $map = self::mirroredPlanFeatureStringsForUser($user);

        return $map[PlanFeatureKeys::CHAT_INITIATE_NEW_CHATS_ONLY] === '1';
    }

    /**
     * @return 'weekly'|'monthly'|'quarterly'
     */
    public static function requireInterestViewResetPeriodForUser(User $user): string
    {
        $payloads = self::policyPayloadsForUser($user);
        $payload = $payloads[PlanFeatureKeys::INTEREST_VIEW_LIMIT] ?? null;
        if (! is_array($payload)) {
            throw QuotaPolicySourceViolation::missingPolicyRow('requireInterestViewResetPeriodForUser', PlanFeatureKeys::INTEREST_VIEW_LIMIT);
        }
        $p = strtolower(trim(PlanQuotaRefreshRuntime::interestViewResetPeriodTokenFromPayload($payload)));
        if (! in_array($p, ['weekly', 'monthly', 'quarterly', 'daily', 'lifetime'], true)) {
            throw QuotaPolicySourceViolation::incompletePayloads(
                'requireInterestViewResetPeriodForUser',
                'interest_view_limit refresh_type maps to invalid reset token: '.$p
            );
        }

        return $p;
    }

    /**
     * @return array<string, array<string, mixed>>|null Null when snapshot has no usable quota_policies map.
     */
    private static function tryPayloadsFromCheckoutSnapshot(Subscription $subscription, string $contextSuffix): ?array
    {
        $snap = $subscription->checkoutSnapshot();
        if (! array_key_exists('quota_policies', $snap) || ! is_array($snap['quota_policies'])) {
            return null;
        }

        $qp = $snap['quota_policies'];
        self::assertCompleteQuotaPayloads($qp, 'checkout_snapshot.'.$contextSuffix);

        return self::withStructuralPolicyMetaNormalized($qp);
    }

    private static function subscriptionIsPaidContract(Subscription $subscription): bool
    {
        $subscription->loadMissing('plan');
        $plan = $subscription->plan;
        if (! $plan instanceof Plan) {
            return true;
        }

        return ! Plan::isFreeCatalogSlug((string) $plan->slug);
    }

    /**
     * Paid members must not silently fall back to live catalog when the frozen contract is missing.
     *
     * @return never
     */
    private static function failClosedMissingQuotaSnapshot(Subscription $subscription, string $context): void
    {
        $logKey = (int) $subscription->id.'|quota_policies_missing';
        if (! isset(self::$loggedMissingQuotaContract[$logKey])) {
            self::$loggedMissingQuotaContract[$logKey] = true;
            Log::warning('subscription_checkout_snapshot_missing_quota_policies', [
                'subscription_id' => (int) $subscription->id,
                'user_id' => (int) $subscription->user_id,
                'context' => $context,
                'fallback' => null,
            ]);
        }

        throw QuotaPolicySourceViolation::incompletePayloads(
            $context,
            'paid subscription missing/incomplete checkout_snapshot.quota_policies; run backfill'
        );
    }
}
