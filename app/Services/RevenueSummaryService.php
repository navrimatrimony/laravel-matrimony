<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\ReferralRewardLedger;
use App\Models\Subscription;
use App\Models\User;
use App\Support\PlanFeatureLabel;

/**
 * Read-only presentation of monetization line items for checkout and history.
 * Prefer obtaining {@code $resolved} from {@see RevenueOrchestratorService::prepareCheckout} so checkout stays coordinated.
 * Numeric rules stay in {@see SubscriptionService}, {@see CouponService}, {@see ReferralService}, {@see UserWalletService}.
 */
class RevenueSummaryService
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * Pre–PayU disclosure: same amounts as {@see SubscriptionService::buildPayuPendingPayload} (single resolution path).
     *
     * @param  array<string, mixed>  $resolved  From {@see SubscriptionService::resolvePaidPlanCheckout}
     * @return array<string, mixed>
     */
    public function forSubscriptionResolvedCheckout(User $user, Plan $plan, array $resolved): array
    {
        $final = round((float) ($resolved['final_amount'] ?? 0), 2);
        $amountStr = number_format($final, 2, '.', '');
        $pending = $this->subscriptions->buildPayuPendingPayload($user, $plan, $resolved, $amountStr);

        return $this->mapPendingToSummary($pending, includePreviewExtras: true);
    }

    /**
     * Post-success receipt: amounts from locked pending + persisted subscription meta / ledger (read-only).
     *
     * @param  array<string, mixed>  $pending  PayU pending payload (same shape as cache)
     * @return array<string, mixed>
     */
    public function forCompletedSubscriptionPayu(Subscription $subscription, array $pending): array
    {
        $base = $this->mapPendingToSummary($pending, includePreviewExtras: true);
        $buyerId = (int) ($pending['user_id'] ?? $subscription->user_id);
        if ($subscription->wasRecentlyCreated) {
            $base['bonus_quota_added'] = $this->carryQuotaLinesFromSubscriptionMeta($subscription);
            $base['coupon_applied_meta'] = $this->couponAppliedSnippetFromSubscription($subscription);
        } else {
            // Idempotent replay: amounts still match locked pending; avoid showing another row's meta.
            $base['bonus_quota_added'] = [];
            $base['coupon_applied_meta'] = null;
        }
        $base['referral_purchase_ledger'] = $this->referralLedgerLineForBuyerPurchase($buyerId);

        return $base;
    }

    /**
     * @return array<string, mixed>
     */
    public function recentBenefitsForMember(User $user, int $subscriptionLimit = 6, int $referralLimit = 6): array
    {
        $subs = Subscription::query()
            ->where('user_id', (int) $user->id)
            ->with('plan')
            ->orderByDesc('starts_at')
            ->limit($subscriptionLimit)
            ->get();

        $subscriptionRows = $subs->map(function (Subscription $sub): array {
            $snap = $sub->checkoutSnapshot();
            $meta = is_array($sub->meta) ? $sub->meta : [];
            $couponCode = isset($snap['coupon_code']) && is_string($snap['coupon_code']) && trim($snap['coupon_code']) !== ''
                ? trim($snap['coupon_code'])
                : null;
            $couponDisc = round((float) ($snap['coupon_discount'] ?? 0), 2);
            $planName = trim((string) ($snap['plan_name'] ?? ''));
            if ($planName === '') {
                $planName = (string) ($sub->plan?->name ?? '');
            }

            return [
                'starts_at' => $sub->starts_at?->toIso8601String(),
                'starts_at_display' => $sub->starts_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? '',
                'plan_name' => $planName !== '' ? $planName : '—',
                'coupon_code' => $couponCode,
                'coupon_discount_display' => $this->formatInrSigned(-$couponDisc, negativeIsDiscount: true),
                'carry_quota_lines' => $this->carryQuotaLinesFromSubscriptionMeta($sub),
                'coupon_applied_snippet' => $this->couponAppliedSnippetFromSubscription($sub),
            ];
        })->values()->all();

        $referralRows = ReferralRewardLedger::query()
            ->where('referrer_id', (int) $user->id)
            ->whereIn('action_type', ['auto_applied', 'auto_skipped_cap'])
            ->orderByDesc('id')
            ->limit($referralLimit)
            ->get()
            ->map(function (ReferralRewardLedger $row): array {
                return [
                    'created_at_display' => $row->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? '',
                    'action_type' => (string) $row->action_type,
                    'bonus_days' => (int) $row->bonus_days,
                    'feature_bonus' => is_array($row->feature_bonus) ? $row->feature_bonus : [],
                    'reason' => (string) ($row->reason ?? ''),
                    'meta' => is_array($row->meta) ? $row->meta : [],
                ];
            })->values()->all();

        return [
            'subscription_purchases' => $subscriptionRows,
            'referral_rewards_as_referrer' => $referralRows,
        ];
    }

    /**
     * @param  array<string, mixed>  $pending
     * @return array<string, mixed>
     */
    private function mapPendingToSummary(array $pending, bool $includePreviewExtras): array
    {
        $base = round((float) ($pending['base_amount'] ?? 0), 2);
        $disc = round((float) ($pending['coupon_discount'] ?? 0), 2);
        $final = round((float) ($pending['final_amount_after_coupon'] ?? $pending['final_amount'] ?? 0), 2);
        $rawCode = $pending['coupon_code'] ?? null;
        $couponCode = is_string($rawCode) && trim($rawCode) !== '' ? strtoupper(trim($rawCode)) : null;

        $preview = $pending['subscription_meta_preview'] ?? [];
        if (! is_array($preview)) {
            $preview = [];
        }

        $extras = [];
        if ($includePreviewExtras) {
            $extras = $this->previewCouponExtrasLines($pending, $preview);
        }

        return [
            'base_plan_price' => $base,
            'base_plan_price_display' => $this->formatInrPlain($base),
            'discount_amount' => $disc,
            'discount_amount_display' => $this->formatInrSigned(-$disc, negativeIsDiscount: true),
            'final_price' => $final,
            'final_price_display' => $this->formatInrPlain($final),
            'coupon_code' => $couponCode,
            'wallet_used_rupees' => 0.0,
            'wallet_used_display' => $this->formatInrPlain(0.0),
            'subscription_checkout_uses_wallet' => false,
            'referral_bonus' => null,
            'referral_bonus_display' => null,
            /** Coupon / duration extras from locked checkout preview (not quota engine). */
            'coupon_checkout_extras' => $extras,
            'extra_duration_days' => (int) ($pending['extra_duration_days'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $pending
     * @param  array<string, mixed>  $preview
     * @return list<array{kind: string, display: string}>
     */
    private function previewCouponExtrasLines(array $pending, array $preview): array
    {
        $lines = [];
        $extraDays = (int) ($pending['extra_duration_days'] ?? 0);
        if ($extraDays > 0) {
            $lines[] = [
                'kind' => 'coupon_extra_days',
                'display' => __('revenue_summary.coupon_extra_days_line', ['days' => $extraDays]),
            ];
        }
        $applied = $preview['coupon_applied'] ?? null;
        if (is_array($applied)) {
            $type = (string) ($applied['type'] ?? '');
            if ($type !== '') {
                $lines[] = [
                    'kind' => 'coupon_applied_type',
                    'display' => __('revenue_summary.coupon_type_line', ['type' => $type]),
                ];
            }
            if (! empty($applied['feature_payload'])) {
                $payload = $applied['feature_payload'];
                $payloadStr = is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_UNICODE);
                $lines[] = [
                    'kind' => 'coupon_feature_payload',
                    'display' => __('revenue_summary.coupon_feature_payload_note', [
                        'payload' => is_string($payloadStr) ? $payloadStr : '',
                    ]),
                ];
            }
        }

        return $lines;
    }

    /**
     * @return list<array{feature_key: string, feature_label: string, units: int, display: string}>
     */
    private function carryQuotaLinesFromSubscriptionMeta(Subscription $subscription): array
    {
        $meta = is_array($subscription->meta) ? $subscription->meta : [];
        $carry = $meta['carry_quota'] ?? null;
        if (! is_array($carry) || $carry === []) {
            return [];
        }
        $out = [];
        foreach ($carry as $key => $raw) {
            if (! is_string($key) || $key === '') {
                continue;
            }
            $units = (int) $raw;
            if ($units === 0) {
                continue;
            }
            $label = PlanFeatureLabel::label($key);
            $out[] = [
                'feature_key' => $key,
                'feature_label' => $label,
                'units' => $units,
                'display' => __('revenue_summary.carry_quota_line', ['label' => $label, 'units' => $units]),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, string>|null
     */
    private function couponAppliedSnippetFromSubscription(Subscription $subscription): ?array
    {
        $meta = is_array($subscription->meta) ? $subscription->meta : [];
        $applied = $meta['coupon_applied'] ?? null;
        if (! is_array($applied)) {
            return null;
        }
        $code = isset($applied['code']) ? (string) $applied['code'] : '';
        $type = isset($applied['type']) ? (string) $applied['type'] : '';
        $extraDays = (int) ($applied['extra_days'] ?? 0);

        return [
            'code' => $code,
            'type' => $type,
            'extra_days' => (string) $extraDays,
            'display' => __('revenue_summary.coupon_applied_compact', [
                'code' => $code !== '' ? $code : '—',
                'type' => $type !== '' ? $type : '—',
                'days' => $extraDays,
            ]),
        ];
    }

    /**
     * Ledger row when this user's purchase triggered a referral reward (referrer credited).
     *
     * @return array<string, mixed>|null
     */
    private function referralLedgerLineForBuyerPurchase(int $buyerUserId): ?array
    {
        $row = ReferralRewardLedger::query()
            ->where('referred_user_id', $buyerUserId)
            ->whereIn('action_type', ['auto_applied', 'auto_skipped_cap'])
            ->orderByDesc('id')
            ->first();
        if ($row === null) {
            return null;
        }

        $featureBonus = is_array($row->feature_bonus) ? $row->feature_bonus : [];
        $planName = '';
        $meta = is_array($row->meta) ? $row->meta : [];
        if (isset($meta['plan_name'])) {
            $planName = trim((string) $meta['plan_name']);
        }

        return [
            'action_type' => (string) $row->action_type,
            'bonus_days' => (int) $row->bonus_days,
            'feature_bonus' => $featureBonus,
            'plan_name' => $planName,
            'display' => __('revenue_summary.referral_ledger_buyer_line', [
                'action' => (string) $row->action_type,
                'days' => (int) $row->bonus_days,
                'plan' => $planName !== '' ? $planName : '—',
            ]),
        ];
    }

    private function formatInrPlain(float $rupees): string
    {
        $rupees = round($rupees, 2);

        return '₹'.number_format($rupees, $rupees === floor($rupees) ? 0 : 2, '.', ',');
    }

    private function formatInrSigned(float $signedRupees, bool $negativeIsDiscount): string
    {
        $abs = round(abs($signedRupees), 2);
        $fmt = '₹'.number_format($abs, $abs === floor($abs) ? 0 : 2, '.', ',');
        if ($abs < 0.005) {
            return '—';
        }
        if ($signedRupees < 0 && $negativeIsDiscount) {
            return '-'.$fmt;
        }
        if ($signedRupees > 0) {
            return '+'.$fmt;
        }

        return $fmt;
    }
}
