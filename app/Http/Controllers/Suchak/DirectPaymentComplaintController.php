<?php

namespace App\Http\Controllers\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakAccount;
use App\Models\SuchakDirectPaymentEvidence;
use App\Modules\Suchak\Services\SuchakSafetyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class DirectPaymentComplaintController extends Controller
{
    public function store(
        Request $request,
        SuchakSafetyService $safetyService,
    ): RedirectResponse {
        $validated = $request->validate([
            'suchak_account_id' => ['required', 'integer', 'exists:suchak_accounts,id'],
            'customer_context_id' => ['nullable', 'integer', 'exists:suchak_customer_contexts,id'],
            'payment_context_id' => ['required', 'integer', 'exists:suchak_payment_contexts,id'],
            'matrimony_profile_id' => ['nullable', 'integer', 'exists:matrimony_profiles,id'],
            'summary' => ['required', 'string', 'min:10', 'max:1000'],
            'evidence_type' => ['required', 'string', Rule::in(SuchakDirectPaymentEvidence::TYPES)],
            'evidence_reference' => ['nullable', 'string', 'max:500'],
            'evidence_note' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $account = SuchakAccount::query()->findOrFail((int) $validated['suchak_account_id']);

        try {
            $safetyService->openDirectPaymentComplaint(
                $account,
                $request->user(),
                $validated,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Direct payment complaint submitted for admin review.');
    }
}
