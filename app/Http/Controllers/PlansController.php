<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\Plan;
use App\Models\ProfileView;
use App\Services\CouponService;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PlansController extends Controller
{
    public function index(Request $request, SubscriptionService $subscriptions)
    {
        $user = $request->user();
        $user?->loadMissing('matrimonyProfile');

        $plans = Plan::query()
            ->where('is_active', true)
            ->with(['features', 'terms', 'visiblePlanPrices'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $catalogIncludesInactive = false;
        if ($plans->isEmpty()) {
            $fallback = Plan::query()
                ->with(['features', 'terms', 'visiblePlanPrices'])
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();
            if ($fallback->isNotEmpty()) {
                $plans = $fallback;
                $catalogIncludesInactive = true;
            }
        }

        $effectivePlan = $subscriptions->getEffectivePlan($user);

        $pricingSlugs = ['silver', 'gold', 'platinum'];
        $pricingPlans = $plans
            ->filter(fn (Plan $p) => in_array(strtolower((string) $p->slug), $pricingSlugs, true))
            ->sortBy(function (Plan $p) use ($pricingSlugs) {
                return array_search(strtolower((string) $p->slug), $pricingSlugs, true);
            })
            ->values();

        $unreadMessagesCount = 0;
        $profileViewersCount = 0;
        if ($user?->matrimonyProfile) {
            $pid = (int) $user->matrimonyProfile->id;
            $unreadMessagesCount = (int) Message::query()
                ->where('receiver_profile_id', $pid)
                ->whereNull('read_at')
                ->count();
            $profileViewersCount = (int) ProfileView::query()
                ->where('viewed_profile_id', $pid)
                ->where('created_at', '>=', now()->subDays(30))
                ->pluck('viewer_profile_id')
                ->unique()
                ->count();
        }

        $maxDiscountPercent = (int) $pricingPlans->max(function (Plan $p) {
            $m = (int) ($p->discount_percent ?? 0);
            foreach ($p->visiblePlanPrices as $pp) {
                $m = max($m, (int) ($pp->discount_percent ?? 0));
                $strike = $pp->strike_list_price;
                $final = (float) $pp->final_price;
                if ($strike !== null && (float) $strike > $final + 0.004) {
                    $m = max($m, (int) round(100 * (1 - $final / (float) $strike)));
                }
            }

            return $m;
        });

        return view('plans.index', [
            'plans' => $plans,
            'pricingPlans' => $pricingPlans,
            'effectivePlan' => $effectivePlan,
            'currentPlan' => $effectivePlan,
            'catalogIncludesInactive' => $catalogIncludesInactive,
            'maxDiscountPercent' => $maxDiscountPercent,
            'unreadMessagesCount' => $unreadMessagesCount,
            'profileViewersCount' => $profileViewersCount,
        ]);
    }

    public function validateCoupon(Request $request, CouponService $coupons)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'plan_id' => ['nullable', 'integer'],
            'plan_price_id' => ['nullable', 'integer'],
        ]);

        return response()->json($coupons->validatePreview(
            $data['code'],
            isset($data['plan_id']) ? (int) $data['plan_id'] : null,
            isset($data['plan_price_id']) ? (int) $data['plan_price_id'] : null,
        ));
    }

    public function subscribe(Request $request, Plan $plan, SubscriptionService $subscriptions)
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        $rawTerm = $request->input('plan_term_id');
        $planTermId = ($rawTerm === null || $rawTerm === '') ? null : (int) $rawTerm;
        $rawPrice = $request->input('plan_price_id');
        $planPriceId = ($rawPrice === null || $rawPrice === '') ? null : (int) $rawPrice;
        $couponCode = $request->input('coupon_code');

        try {
            $subscriptions->subscribe($user, $plan, $planTermId, $planPriceId, is_string($couponCode) ? $couponCode : null);
        } catch (HttpException $e) {
            return redirect()
                ->route('plans.index')
                ->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->route('plans.index')
                ->with('error', __('subscriptions.subscribe_failed'));
        }

        return redirect()
            ->route('plans.index')
            ->with('success', __('subscriptions.subscribe_success', ['plan' => $plan->name]));
    }
}
