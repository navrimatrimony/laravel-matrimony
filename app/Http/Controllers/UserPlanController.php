<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Models\Payment;
use App\Services\QuotaEngineService;
use App\Services\RevenueSummaryService;
use App\Services\SubscriptionUpgradeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UserPlanController extends Controller
{
    public function show(Request $request): RedirectResponse
    {
        return redirect()->route('user.settings.my-plan', ['tab' => 'overview']);
    }

    public function history(Request $request): RedirectResponse
    {
        return redirect()->route('user.settings.my-plan', ['tab' => 'history']);
    }

    public function settingsHub(
        Request $request,
        QuotaEngineService $quotaEngine,
        RevenueSummaryService $revenueSummary,
        SubscriptionUpgradeService $subscriptionUpgrade,
    ): View|RedirectResponse {
        $tab = $request->query('tab', 'overview');
        if (! in_array($tab, ['overview', 'history'], true)) {
            $tab = 'overview';
        }

        $user = $request->user();
        if ($tab === 'overview' && ! $user->matrimonyProfile) {
            return redirect()
                ->route('matrimony.profile.wizard.section', ['section' => 'basic-info'])
                ->with('warning', __('user_plan.profile_required'));
        }

        $subscriptions = Subscription::query()
            ->where('user_id', (int) $user->id)
            ->with(['plan'])
            ->orderByDesc('starts_at')
            ->get();
        $payments = Payment::query()
            ->where('user_id', (int) $user->id)
            ->whereIn('payment_status', ['success', 'refunded'])
            ->with(['plan:id,name'])
            ->orderByDesc('id')
            ->get();

        $quotaSummary = null;
        $recentBenefits = [];
        $upgradeUi = [];
        if ($user->matrimonyProfile) {
            $quotaSummary = $quotaEngine->getUserQuotaSummary($user);
            $recentBenefits = $revenueSummary->recentBenefitsForMember($user);
            $upgradeUi = $subscriptionUpgrade->myPlanUiHints($user);
        }

        return view('user.settings-my-plan', [
            'tab' => $tab,
            'subscriptions' => $subscriptions,
            'payments' => $payments,
            'quotaSummary' => $quotaSummary,
            'recentBenefits' => $recentBenefits,
            'upgradeUi' => $upgradeUi,
        ]);
    }
}
