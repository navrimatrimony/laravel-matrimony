<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PlansController extends Controller
{
    public function index(Request $request, SubscriptionService $subscriptions)
    {
        $user = $request->user();
        $plans = Plan::query()
            ->where('is_active', true)
            ->with('features')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $effectivePlan = $subscriptions->getEffectivePlan($user);

        return view('plans.index', [
            'plans' => $plans,
            'effectivePlan' => $effectivePlan,
        ]);
    }

    public function subscribe(Request $request, Plan $plan, SubscriptionService $subscriptions)
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        try {
            $subscriptions->subscribe($user, $plan);
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
