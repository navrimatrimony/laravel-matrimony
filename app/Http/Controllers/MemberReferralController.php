<?php

namespace App\Http\Controllers;

use App\Services\ReferralService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MemberReferralController extends Controller
{
    public function __construct(
        private readonly ReferralService $referralService,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $summary = $this->referralService->summaryForReferrer($user);
        $entries = $this->referralService->listEntriesForReferrer($user);

        $referralShareTools = $this->referralService->shareToolsForReferrer($user);

        return view('referrals.index', [
            'summary' => $summary,
            'entries' => $entries,
            'referralShareTools' => $referralShareTools,
            'referralShareUrl' => $referralShareTools['share_url'] ?? null,
            'referralCode' => $referralShareTools['referral_code'] ?? $user->referral_code,
            'referralPendingClaimCount' => (int) ($summary['pending_claim'] ?? 0),
            'referralRules' => $this->referralService->memberRulesContext(),
            'referredRegistrationWelcome' => $this->referralService->registrationWelcomeBanner($user),
            'referralBonusProof' => $this->referralService->activeReferralBonusProofForReferrer($user),
        ]);
    }

    public function dismissRegistrationWelcome(Request $request): RedirectResponse
    {
        $this->referralService->dismissRegistrationWelcome();

        return back();
    }
}
