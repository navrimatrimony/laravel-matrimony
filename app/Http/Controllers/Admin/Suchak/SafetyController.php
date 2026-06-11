<?php

namespace App\Http\Controllers\Admin\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakAccount;
use App\Models\SuchakDispute;
use App\Models\SuchakPaymentFeatureFreeze;
use App\Models\SuchakPayoutHold;
use App\Models\SuchakProfileRepresentation;
use App\Modules\Suchak\Services\SuchakAccountLifecycleService;
use App\Modules\Suchak\Services\SuchakRiskComplianceCenterService;
use App\Modules\Suchak\Services\SuchakSafetyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class SafetyController extends Controller
{
    public function index(Request $request, SuchakRiskComplianceCenterService $riskComplianceCenterService): View
    {
        $status = $request->query('status');
        $status = in_array($status, SuchakDispute::STATUSES, true) ? $status : null;

        $type = $request->query('dispute_type');
        $type = in_array($type, SuchakDispute::TYPES, true) ? $type : null;

        $disputes = SuchakDispute::query()
            ->with(['suchakAccount.user', 'representation', 'paymentFeatureFreezes', 'payoutHolds'])
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($type, fn ($query) => $query->where('dispute_type', $type))
            ->latest('opened_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.suchak.safety.index', [
            'disputes' => $disputes,
            'status' => $status,
            'type' => $type,
            'disputeTypes' => SuchakDispute::TYPES,
            'disputeStatuses' => SuchakDispute::STATUSES,
            'closingStatuses' => SuchakDispute::CLOSING_STATUSES,
            'priorities' => SuchakDispute::PRIORITIES,
            'stats' => $this->stats(),
            'riskComplianceCenter' => $riskComplianceCenterService->summary(),
            'accounts' => SuchakAccount::query()
                ->with('user')
                ->withCount(['disputes', 'profileRepresentations'])
                ->latest()
                ->limit(20)
                ->get(),
            'representations' => SuchakProfileRepresentation::query()
                ->with(['suchakAccount.user', 'matrimonyProfile'])
                ->whereIn('representation_status', [
                    SuchakProfileRepresentation::STATUS_PENDING,
                    SuchakProfileRepresentation::STATUS_CONSENT_PENDING,
                    SuchakProfileRepresentation::STATUS_ACTIVE,
                    SuchakProfileRepresentation::STATUS_SUSPENDED,
                ])
                ->latest()
                ->limit(20)
                ->get(),
        ]);
    }

    public function storeDispute(
        Request $request,
        SuchakSafetyService $safetyService
    ): RedirectResponse {
        $validated = $request->validate([
            'suchak_account_id' => ['required', 'integer', 'exists:suchak_accounts,id'],
            'representation_id' => ['nullable', 'integer', 'exists:suchak_profile_representations,id'],
            'dispute_type' => ['required', 'string', Rule::in(SuchakDispute::TYPES)],
            'priority' => ['required', 'string', Rule::in(SuchakDispute::PRIORITIES)],
            'summary' => ['required', 'string', 'min:10', 'max:1000'],
            'evidence_summary' => ['nullable', 'string', 'max:2000'],
        ]);

        $account = SuchakAccount::query()->findOrFail((int) $validated['suchak_account_id']);

        return $this->runSafetyAction(
            fn () => $safetyService->openDispute(
                $account,
                $request->user(),
                $validated,
                $request->ip(),
                $this->userAgent($request),
            ),
            'Suchak dispute opened.'
        );
    }

    public function reviewDispute(
        Request $request,
        SuchakDispute $dispute,
        SuchakSafetyService $safetyService
    ): RedirectResponse {
        $validated = $this->validateReason($request, 'review_note');

        return $this->runSafetyAction(
            fn () => $safetyService->startReview(
                $dispute,
                $request->user(),
                $validated['review_note'],
                $request->ip(),
                $this->userAgent($request),
            ),
            'Suchak dispute moved under review.'
        );
    }

    public function closeDispute(
        Request $request,
        SuchakDispute $dispute,
        SuchakSafetyService $safetyService
    ): RedirectResponse {
        $validated = $request->validate([
            'resolution_status' => ['required', 'string', Rule::in(SuchakDispute::CLOSING_STATUSES)],
            'resolution_note' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        return $this->runSafetyAction(
            fn () => $safetyService->closeDispute(
                $dispute,
                $request->user(),
                $validated['resolution_status'],
                $validated['resolution_note'],
                $request->ip(),
                $this->userAgent($request),
            ),
            'Suchak dispute closed.'
        );
    }

    public function freezePaymentAbility(
        Request $request,
        SuchakDispute $dispute,
        SuchakSafetyService $safetyService
    ): RedirectResponse {
        $validated = $this->validateReason($request, 'freeze_reason');

        return $this->runSafetyAction(
            fn () => $safetyService->freezeDirectPaymentAbility(
                $dispute,
                $request->user(),
                $validated['freeze_reason'],
                $request->ip(),
                $this->userAgent($request),
            ),
            'Suchak payment ability frozen.'
        );
    }

    public function freezeAccount(
        Request $request,
        SuchakAccount $suchakAccount,
        SuchakAccountLifecycleService $lifecycleService
    ): RedirectResponse {
        $validated = $this->validateReason($request);

        return $this->runSafetyAction(
            fn () => $lifecycleService->suspend(
                $suchakAccount,
                $request->user(),
                $validated['reason'],
                $request->ip(),
                $this->userAgent($request),
            ),
            'Suchak account frozen.'
        );
    }

    public function unfreezeAccount(
        Request $request,
        SuchakAccount $suchakAccount,
        SuchakAccountLifecycleService $lifecycleService
    ): RedirectResponse {
        $validated = $this->validateReason($request);

        return $this->runSafetyAction(
            fn () => $lifecycleService->reactivate(
                $suchakAccount,
                $request->user(),
                $validated['reason'],
                $request->ip(),
                $this->userAgent($request),
            ),
            'Suchak account unfrozen.'
        );
    }

    public function pauseAccount(
        Request $request,
        SuchakAccount $suchakAccount,
        SuchakAccountLifecycleService $lifecycleService
    ): RedirectResponse {
        $validated = $this->validateReason($request);

        return $this->runSafetyAction(
            fn () => $lifecycleService->updatePublicStatus(
                $suchakAccount,
                $request->user(),
                SuchakAccount::PUBLIC_INACTIVE,
                $validated['reason'],
                $request->ip(),
                $this->userAgent($request),
            ),
            'Suchak public routing paused.'
        );
    }

    public function resumeAccount(
        Request $request,
        SuchakAccount $suchakAccount,
        SuchakAccountLifecycleService $lifecycleService
    ): RedirectResponse {
        $validated = $this->validateReason($request);

        return $this->runSafetyAction(
            fn () => $lifecycleService->updatePublicStatus(
                $suchakAccount,
                $request->user(),
                SuchakAccount::PUBLIC_ACTIVE,
                $validated['reason'],
                $request->ip(),
                $this->userAgent($request),
            ),
            'Suchak public routing resumed.'
        );
    }

    public function revokeRepresentation(
        Request $request,
        SuchakProfileRepresentation $representation,
        SuchakSafetyService $safetyService
    ): RedirectResponse {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'dispute_id' => ['nullable', 'integer', 'exists:suchak_disputes,id'],
        ]);

        return $this->runSafetyAction(
            fn () => $safetyService->revokeRepresentation(
                $representation,
                $request->user(),
                $validated['reason'],
                isset($validated['dispute_id']) ? (int) $validated['dispute_id'] : null,
                $request->ip(),
                $this->userAgent($request),
            ),
            'Suchak representation revoked.'
        );
    }

    /**
     * @return array<string, int>
     */
    private function stats(): array
    {
        return [
            'open_disputes' => SuchakDispute::query()->where('status', SuchakDispute::STATUS_OPEN)->count(),
            'under_review' => SuchakDispute::query()->where('status', SuchakDispute::STATUS_UNDER_REVIEW)->count(),
            'abuse_reports' => SuchakDispute::query()->where('dispute_type', SuchakDispute::TYPE_ABUSE_REPORT)->count(),
            'direct_payment_complaints' => SuchakDispute::query()->where('dispute_type', SuchakDispute::TYPE_DIRECT_PAYMENT_REQUEST)->count(),
            'active_payment_freezes' => SuchakPaymentFeatureFreeze::query()->where('freeze_status', SuchakPaymentFeatureFreeze::STATUS_ACTIVE)->count(),
            'active_payout_holds' => SuchakPayoutHold::query()->where('hold_status', SuchakPayoutHold::STATUS_ACTIVE)->count(),
            'frozen_accounts' => SuchakAccount::query()->where('verification_status', SuchakAccount::VERIFICATION_SUSPENDED)->count(),
            'paused_public_accounts' => SuchakAccount::query()
                ->where('verification_status', SuchakAccount::VERIFICATION_VERIFIED)
                ->where('public_status', SuchakAccount::PUBLIC_INACTIVE)
                ->count(),
            'revokable_representations' => SuchakProfileRepresentation::query()
                ->whereIn('representation_status', [
                    SuchakProfileRepresentation::STATUS_PENDING,
                    SuchakProfileRepresentation::STATUS_CONSENT_PENDING,
                    SuchakProfileRepresentation::STATUS_ACTIVE,
                    SuchakProfileRepresentation::STATUS_SUSPENDED,
                ])
                ->count(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function validateReason(Request $request, string $key = 'reason'): array
    {
        return $request->validate([
            $key => ['required', 'string', 'min:10', 'max:500'],
        ]);
    }

    private function runSafetyAction(callable $callback, string $successMessage): RedirectResponse
    {
        try {
            $callback();
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.suchak.safety.index')
            ->with('success', $successMessage);
    }

    private function userAgent(Request $request): string
    {
        return Str::limit((string) $request->userAgent(), 512, '');
    }
}
