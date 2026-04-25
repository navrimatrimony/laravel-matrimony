<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\PlanPrice;
use App\Models\PlanTerm;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CouponService
{
    public function findActiveByCode(string $code): ?Coupon
    {
        $normalized = strtoupper(trim($code));
        if ($normalized === '') {
            return null;
        }

        return Coupon::query()
            ->whereRaw('UPPER(code) = ?', [$normalized])
            ->first();
    }

    /**
     * @return array{
     *     valid: bool,
     *     message?: string,
     *     type?: string,
     *     value?: float,
     *     plan_ids?: array<int>|null,
     *     duration_types?: array<string>|null,
     *     base_amount?: float,
     *     final_amount?: float,
     *     savings?: float
     * }
     */
    public function validatePreview(string $code, ?int $planId = null, ?int $planPriceId = null, ?int $planTermId = null): array
    {
        $coupon = $this->findActiveByCode($code);
        if (! $coupon) {
            return ['valid' => false, 'message' => __('subscriptions.coupon_not_found')];
        }
        if (! $coupon->isUsableNow()) {
            return ['valid' => false, 'message' => __('subscriptions.coupon_inactive')];
        }

        $out = [
            'valid' => true,
            'type' => $coupon->type,
            'value' => (float) $coupon->value,
            'plan_ids' => $coupon->applicable_plan_ids,
            'duration_types' => $coupon->applicable_duration_types,
        ];

        if ($planTermId !== null) {
            $term = PlanTerm::query()->find($planTermId);
            if (! $term || ! $term->is_visible) {
                return ['valid' => false, 'message' => __('subscriptions.coupon_invalid_context')];
            }
            $resolvedPlanId = (int) $term->plan_id;
            if ($planId !== null && (int) $planId !== $resolvedPlanId) {
                return ['valid' => false, 'message' => __('subscriptions.coupon_invalid_context')];
            }
            if (! $coupon->appliesToPlan($resolvedPlanId)) {
                return ['valid' => false, 'message' => __('subscriptions.coupon_plan_excluded')];
            }
            if (! $coupon->appliesToDurationType((string) $term->billing_key)) {
                return ['valid' => false, 'message' => __('subscriptions.coupon_duration_excluded')];
            }
            $base = (float) $term->final_price;
            if ($coupon->min_purchase_amount !== null && $base < (float) $coupon->min_purchase_amount - 0.004) {
                return ['valid' => false, 'message' => __('subscriptions.coupon_min_not_met')];
            }
            $final = $this->amountAfterCoupon($coupon, $base);
            $out['base_amount'] = round($base, 2);
            $out['final_amount'] = $final;
            $out['savings'] = round(max(0, $base - $final), 2);
            if ($coupon->type === Coupon::TYPE_DAYS) {
                $out['extra_duration_days'] = max(0, (int) round((float) $coupon->value));
            }
            if ($coupon->type === Coupon::TYPE_FEATURE) {
                $out['feature_coupon'] = true;
                $out['feature_payload'] = $coupon->feature_payload;
            }
        } elseif ($planPriceId !== null) {
            $row = PlanPrice::query()->find($planPriceId);
            if (! $row) {
                return ['valid' => false, 'message' => __('subscriptions.coupon_invalid_context')];
            }
            $resolvedPlanId = (int) $row->plan_id;
            if ($planId !== null && (int) $planId !== $resolvedPlanId) {
                return ['valid' => false, 'message' => __('subscriptions.coupon_invalid_context')];
            }
            if (! $coupon->appliesToPlan($resolvedPlanId)) {
                return ['valid' => false, 'message' => __('subscriptions.coupon_plan_excluded')];
            }
            if (! $coupon->appliesToDurationType((string) $row->duration_type)) {
                return ['valid' => false, 'message' => __('subscriptions.coupon_duration_excluded')];
            }
            $base = (float) $row->final_price;
            if ($coupon->min_purchase_amount !== null && $base < (float) $coupon->min_purchase_amount - 0.004) {
                return ['valid' => false, 'message' => __('subscriptions.coupon_min_not_met')];
            }
            $final = $this->amountAfterCoupon($coupon, $base);
            $out['base_amount'] = round($base, 2);
            $out['final_amount'] = $final;
            $out['savings'] = round(max(0, $base - $final), 2);
            if ($coupon->type === Coupon::TYPE_DAYS) {
                $out['extra_duration_days'] = max(0, (int) round((float) $coupon->value));
            }
            if ($coupon->type === Coupon::TYPE_FEATURE) {
                $out['feature_coupon'] = true;
                $out['feature_payload'] = $coupon->feature_payload;
            }
        } elseif ($planId !== null && ! $coupon->appliesToPlan($planId)) {
            return ['valid' => false, 'message' => __('subscriptions.coupon_plan_excluded')];
        }

        return $out;
    }

    public function amountAfterCoupon(Coupon $coupon, float $baseAmount): float
    {
        $baseAmount = max(0, round($baseAmount, 2));
        if ($baseAmount <= 0) {
            return 0.0;
        }

        return match ($coupon->type) {
            Coupon::TYPE_PERCENT => $this->applyPercent($coupon, $baseAmount),
            Coupon::TYPE_FIXED, Coupon::TYPE_FLAT => $this->applyFixed($coupon, $baseAmount),
            Coupon::TYPE_DAYS, Coupon::TYPE_FEATURE => $baseAmount,
            default => $baseAmount,
        };
    }

    /**
     * @throws HttpException
     */
    public function assertLockedCouponForCheckout(
        Coupon $coupon,
        int $planId,
        float $baseAmount,
        ?string $durationType = null,
    ): void {
        if (! $coupon->isUsableNow()) {
            throw new HttpException(422, __('subscriptions.coupon_inactive'));
        }
        if (! $coupon->appliesToPlan($planId)) {
            throw new HttpException(422, __('subscriptions.coupon_plan_excluded'));
        }
        if ($durationType !== null && $durationType !== '' && ! $coupon->appliesToDurationType($durationType)) {
            throw new HttpException(422, __('subscriptions.coupon_duration_excluded'));
        }
        if ($coupon->min_purchase_amount !== null && $baseAmount < (float) $coupon->min_purchase_amount - 0.004) {
            throw new HttpException(422, __('subscriptions.coupon_min_not_met'));
        }
    }

    /**
     * Lock coupon row (call inside an open transaction).
     */
    public function lockCouponByCode(string $code): ?Coupon
    {
        $normalized = strtoupper(trim($code));
        if ($normalized === '') {
            return null;
        }

        return Coupon::query()
            ->whereRaw('UPPER(code) = ?', [$normalized])
            ->lockForUpdate()
            ->first();
    }

    public function incrementRedemption(Coupon $coupon): void
    {
        $coupon->increment('redemptions_count');
    }

    private function applyPercent(Coupon $coupon, float $base): float
    {
        $pct = (float) $coupon->value;
        $pct = min(100, max(0, $pct));
        $off = round($base * ($pct / 100), 2);

        return round(max(0, $base - $off), 2);
    }

    private function applyFixed(Coupon $coupon, float $base): float
    {
        $off = min($base, max(0, round((float) $coupon->value, 2)));

        return round($base - $off, 2);
    }
}
