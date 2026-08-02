<?php

namespace App\Http\Controllers\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakCollaborationStageEvent;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerPlan;
use App\Models\SuchakServicePackage;
use App\Models\SuchakSuccessFeeTranche;
use App\Modules\Suchak\Services\SuchakAgreementService;
use App\Modules\Suchak\Services\SuchakSuccessFeeTrancheService;
use App\Support\LocalizedText;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class PublicAgreementController extends Controller
{
    public function show(
        string $token,
        SuchakAgreementService $agreementService,
    ): View {
        $agreement = $agreementService->resolvePublicAcceptanceToken($token);

        return view('suchak.agreements.public', [
            'agreement' => $agreement,
            'token' => $token,
            'suchak' => $this->suchakFor($agreement),
            'terms' => $this->termsFor($agreement),
            'state' => $this->stateFor($agreement),
            'message' => null,
        ]);
    }

    public function decision(
        Request $request,
        string $token,
        SuchakAgreementService $agreementService,
    ): View {
        $validated = $request->validate([
            'accepted_by_name' => ['required', 'string', 'max:160'],
        ]);

        $agreement = $agreementService->resolvePublicAcceptanceToken($token);
        if ($agreement === null) {
            return view('suchak.agreements.public', [
                'agreement' => null,
                'token' => $token,
                'suchak' => [],
                'terms' => [],
                'state' => 'invalid',
                'message' => null,
            ]);
        }

        $message = null;

        try {
            $agreement = $agreementService->recordPublicAcceptance(
                $agreement,
                (string) $validated['accepted_by_name'],
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException) {
            // The service speaks English by house convention; this page answers
            // in whatever language the family asked for. The state banner
            // rebuilt below already names the real reason (expired, spent, no
            // longer pending), so only the failure itself is reported here
            // rather than the raw message.
            $message = __('suchak.public_pages.agreement.acceptance_failed');
            $agreement = $agreement->fresh(['suchakAccount', 'servicePackage']);
        }

        return view('suchak.agreements.public', [
            'agreement' => $agreement,
            'token' => $token,
            'suchak' => $this->suchakFor($agreement),
            'terms' => $this->termsFor($agreement),
            'state' => $this->stateFor($agreement),
            'message' => $message,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function suchakFor(?SuchakCustomerAgreement $agreement): array
    {
        $account = $agreement?->suchakAccount;
        if ($account === null) {
            return [];
        }

        return [
            'name' => LocalizedText::column($account, 'suchak_name'),
            'office_name' => LocalizedText::column($account, 'office_name'),
            'photo_path' => trim((string) ($account->profile_photo_path ?? '')),
        ];
    }

    /**
     * The four money figures the customer is being asked to freeze.
     *
     * price_amount comes off the agreement because that is the snapshot already
     * taken for this customer. The meeting and post-marriage fees live only on
     * the package, so they are read there — safe to do because acceptance itself
     * refuses to proceed unless the package still matches the snapshot hash.
     *
     * @return array<string, mixed>
     */
    private function termsFor(?SuchakCustomerAgreement $agreement): array
    {
        if ($agreement === null) {
            return [];
        }

        $package = $agreement->servicePackage;

        return [
            'currency' => $agreement->currency ?: ($package?->currency ?: 'INR'),
            'registration_fee' => $agreement->price_amount,
            'meeting_offline_fee' => $package?->per_meeting_fee_amount,
            'meeting_online_fee' => $package?->per_meeting_online_fee_amount,
            'success_fee_mode' => $package?->post_marriage_fee_mode,
            'success_fee_amount' => $package?->post_marriage_fee_amount,
            'success_fee_tranches' => $this->trancheRowsFor($agreement, $package),
        ];
    }

    /**
     * The split the customer is actually agreeing to.
     *
     * The tranche plan is inside the snapshot digest this page freezes, so a
     * customer who accepts without seeing it has agreed to a schedule nobody
     * showed them — the exact failure the freeze exists to prevent. Each row
     * carries its own rupee figure, because "10%" of a figure printed elsewhere
     * on the page is arithmetic we are asking a family to do under pressure.
     *
     * @return list<array{label: string, share: ?string, amount: ?string}>
     */
    private function trancheRowsFor(
        SuchakCustomerAgreement $agreement,
        ?SuchakServicePackage $package,
    ): array {
        $tranches = $agreement->successFeeTranches()->orderBy('sort_order')->get();
        if ($tranches->isEmpty()) {
            return [];
        }

        // One arithmetic owner: the same service that validated the plan computes
        // the money, so the page can never quote a figure the rules did not produce.
        $amounts = $package?->post_marriage_fee_mode === SuchakCustomerPlan::MODE_FIXED
            ? app(SuchakSuccessFeeTrancheService::class)
                ->amounts($package->post_marriage_fee_amount, $tranches)
            : [];

        return $tranches->values()->map(static fn (SuchakSuccessFeeTranche $tranche, int $index): array => [
            'label' => SuchakCollaborationStageEvent::stageLabel((string) $tranche->trigger_stage_key),
            // The final tranche is the remainder, never a percentage (T2), so it
            // says so rather than printing a number the rules do not define.
            // Same key the fee vocabulary already owns — this was a Marathi
            // literal, so an English page printed Devanagari in a money column.
            'share' => $tranche->is_final_tranche
                ? __('suchak.fees.final_tranche_remainder')
                : rtrim(rtrim(number_format((float) $tranche->share_percent, 2, '.', ''), '0'), '.').'%',
            'amount' => $amounts[$index] ?? null,
        ])->all();
    }

    private function stateFor(?SuchakCustomerAgreement $agreement): string
    {
        if ($agreement === null || $agreement->acceptance_token_hash === null) {
            return 'invalid';
        }

        if ($agreement->terms_status === SuchakCustomerAgreement::TERMS_ACCEPTED
            || $agreement->acceptance_token_used_at !== null) {
            return 'accepted';
        }

        if ($agreement->terms_status !== SuchakCustomerAgreement::TERMS_PENDING) {
            return 'inactive';
        }

        if ($agreement->isAcceptanceTokenExpired()) {
            return 'expired';
        }

        return 'open';
    }
}
