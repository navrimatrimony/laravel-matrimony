<?php

namespace App\Http\Controllers\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakCustomerAgreement;
use App\Modules\Suchak\Services\SuchakAgreementService;
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
            // The service speaks English by house convention; this page does not.
            // The state banner rebuilt below already names the real reason
            // (expired, spent, no longer pending), so only the failure itself is
            // reported here rather than the raw message.
            $message = 'हा स्वीकार आता नोंदवता आला नाही.';
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
        ];
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
