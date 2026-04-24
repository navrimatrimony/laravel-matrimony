<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Services\QuotaEngineService;
use App\Services\RevenueSummaryService;
use App\Services\SubscriptionUpgradeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UserPlanController extends Controller
{
    public function show(
        Request $request,
        QuotaEngineService $quotaEngine,
        RevenueSummaryService $revenueSummary,
        SubscriptionUpgradeService $subscriptionUpgrade,
    ): View|RedirectResponse {
        $user = $request->user();
        if (! $user->matrimonyProfile) {
            return redirect()
                ->route('matrimony.profile.wizard.section', ['section' => 'basic-info'])
                ->with('warning', __('user_plan.profile_required'));
        }

        $quotaSummary = $quotaEngine->getUserQuotaSummary($user);

        return view('user.my-plan', [
            'quotaSummary' => $quotaSummary,
            'recentBenefits' => $revenueSummary->recentBenefitsForMember($user),
            'upgradeUi' => $subscriptionUpgrade->myPlanUiHints($user),
        ]);
    }

    public function history(Request $request): View
    {
        $subscriptions = Subscription::query()
            ->where('user_id', (int) $request->user()->id)
            ->with(['plan'])
            ->orderByDesc('starts_at')
            ->get();

        return view('user.plan-history', [
            'subscriptions' => $subscriptions,
        ]);
    }
}
