<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\Plan;
use App\Models\ProfileView;
use App\Models\Subscription;
use App\Services\CouponService;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;

class PlansController extends Controller
{
    public function index(Request $request, SubscriptionService $subscriptions)
    {
        $user = $request->user();
        $user?->loadMissing('matrimonyProfile');

        $plans = Plan::query()
            ->where('is_active', true)
            ->with(['features', 'terms', 'quotaPolicies'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $catalogIncludesInactive = false;
        if ($plans->isEmpty()) {
            $fallback = Plan::query()
                ->with(['features', 'terms', 'quotaPolicies'])
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();
            if ($fallback->isNotEmpty()) {
                $plans = $fallback;
                $catalogIncludesInactive = true;
            }
        }

        $effectivePlan = $subscriptions->getEffectivePlan($user);

        $currentPlanDisplayName = null;
        if ($user !== null) {
            $activeSub = $subscriptions->getActiveSubscription($user);
            if ($activeSub instanceof Subscription) {
                $snap = $activeSub->checkoutSnapshot();
                $label = trim((string) ($snap['plan_name'] ?? ''));
                if ($label !== '') {
                    $currentPlanDisplayName = $label;
                }
            }
        }

        // All active non-free plans (admin-created tiers like "test" must appear). Order by sort_order + id.
        $pricingPlans = $plans
            ->filter(fn (Plan $p) => ! Plan::isFreeCatalogSlug((string) $p->slug))
            ->sortBy([
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ])
            ->values();

        $user?->loadMissing('matrimonyProfile.gender');
        $viewerGenderKey = strtolower(trim((string) ($user?->matrimonyProfile?->gender?->key ?? '')));
        $planMatchesViewerGender = static function (Plan $p) use ($viewerGenderKey): bool {
            $target = strtolower(trim((string) ($p->applies_to_gender ?? 'all')));
            if ($target === '' || $target === 'all') {
                return true;
            }
            if ($viewerGenderKey === '' || $viewerGenderKey === 'other') {
                return true;
            }

            return $target === $viewerGenderKey;
        };
        $plans = $plans->filter($planMatchesViewerGender)->values();
        $pricingPlans = $pricingPlans->filter($planMatchesViewerGender)->values();

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
            $m = 0;
            $visibleTerms = $p->terms->where('is_visible', true);
            if ($visibleTerms->isNotEmpty()) {
                foreach ($visibleTerms as $t) {
                    $d = (int) ($t->discount_percent ?? 0);
                    if ($d > 0) {
                        $m = max($m, $d);
                    } else {
                        $list = (float) $t->price;
                        $fin = (float) $t->final_price;
                        if ($list > $fin + 0.004) {
                            $m = max($m, (int) round(100 * (1 - $fin / $list)));
                        }
                    }
                }

                return $m;
            }

            $m = (int) ($p->discount_percent ?? 0);
            $list = (float) $p->price;
            $fin = (float) $p->final_price;
            if ($m === 0 && $list > $fin + 0.004) {
                $m = (int) round(100 * (1 - $fin / $list));
            }

            return $m;
        });

        return view('plans.index', [
            'plans' => $plans,
            'pricingPlans' => $pricingPlans,
            'effectivePlan' => $effectivePlan,
            'currentPlan' => $effectivePlan,
            'currentPlanDisplayName' => $currentPlanDisplayName,
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
            'plan_term_id' => ['nullable', 'integer'],
        ]);

        return response()->json($coupons->validatePreview(
            $data['code'],
            isset($data['plan_id']) ? (int) $data['plan_id'] : null,
            isset($data['plan_price_id']) ? (int) $data['plan_price_id'] : null,
            isset($data['plan_term_id']) ? (int) $data['plan_term_id'] : null,
        ));
    }
}
