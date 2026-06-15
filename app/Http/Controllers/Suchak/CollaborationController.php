<?php

namespace App\Http\Controllers\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakCommissionAgreement;
use App\Models\SuchakLedgerEntry;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakProfileRepresentation;
use App\Modules\Suchak\Services\SuchakCollaborationService;
use App\Modules\Suchak\Services\SuchakCrmLedgerService;
use App\Modules\Suchak\Services\SuchakCandidateMaskingService;
use App\Modules\Suchak\Services\SuchakLimitService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class CollaborationController extends Controller
{
    public function index(
        Request $request,
        SuchakLimitService $limitService,
        SuchakCollaborationService $collaborationService,
        SuchakCandidateMaskingService $maskingService,
    ): View {
        $account = $request->user()?->suchakAccount;
        if (! $account) {
            abort(403, 'Suchak account is required.');
        }

        $status = $request->query('status');
        $status = in_array($status, SuchakCollaborationRequest::STATUSES, true) ? $status : null;
        $direction = $request->query('direction');
        $direction = in_array($direction, ['incoming', 'outgoing'], true) ? $direction : null;
        $statusGroup = $request->query('status_group') === 'closed' ? 'closed' : null;
        $overdue = $request->boolean('overdue');
        if ($statusGroup !== null) {
            $status = null;
        }
        if ($overdue) {
            $status = null;
            $statusGroup = null;
        }

        $collaborationsQuery = SuchakCollaborationRequest::query()
            ->with([
                'requestingSuchakAccount.user',
                'targetSuchakAccount.user',
                'requestingRepresentation.matrimonyProfile.gender',
                'requestingRepresentation.matrimonyProfile.maritalStatus',
                'requestingRepresentation.matrimonyProfile.religion',
                'requestingRepresentation.matrimonyProfile.caste',
                'requestingRepresentation.matrimonyProfile.visibilitySetting',
                'requestingRepresentation.matrimonyProfile.location.parent.parent.parent',
                'requestingRepresentation.matrimonyProfile.occupationMaster',
                'targetRepresentation.matrimonyProfile.gender',
                'targetRepresentation.matrimonyProfile.maritalStatus',
                'targetRepresentation.matrimonyProfile.religion',
                'targetRepresentation.matrimonyProfile.caste',
                'targetRepresentation.matrimonyProfile.visibilitySetting',
                'targetRepresentation.matrimonyProfile.location.parent.parent.parent',
                'targetRepresentation.matrimonyProfile.occupationMaster',
                'commissionAgreement.collectorSuchakAccount',
                'ledgerEntries' => fn ($query) => $query
                    ->where('suchak_account_id', $account->id)
                    ->latest(),
            ])
            ->where(function ($query) use ($account): void {
                $query
                    ->where('requesting_suchak_account_id', $account->id)
                    ->orWhere('target_suchak_account_id', $account->id);
            })
            ->when($direction === 'incoming', fn ($query) => $query->where('target_suchak_account_id', $account->id))
            ->when($direction === 'outgoing', fn ($query) => $query->where('requesting_suchak_account_id', $account->id))
            ->when($statusGroup === 'closed', fn ($query) => $query->whereIn('status', [
                SuchakCollaborationRequest::STATUS_EXPIRED,
                SuchakCollaborationRequest::STATUS_REJECTED,
                SuchakCollaborationRequest::STATUS_CANCELLED,
            ]))
            ->when($overdue, fn ($query) => $query
                ->where('status', SuchakCollaborationRequest::STATUS_PENDING)
                ->where('expires_at', '<=', now()))
            ->when(! $overdue && $status, fn ($query) => $query->where('status', $status))
            ->latest('requested_at');

        $collaborations = $collaborationsQuery->paginate(20)->withQueryString();
        $collaborationSummaries = $collaborations->getCollection()
            ->mapWithKeys(function (SuchakCollaborationRequest $collaboration) use ($maskingService): array {
                $requestingProfile = $collaboration->requestingRepresentation?->matrimonyProfile;
                $targetProfile = $collaboration->targetRepresentation?->matrimonyProfile;

                return [
                    $collaboration->id => [
                        'requesting' => $requestingProfile
                            ? $maskingService->maskedSummary($requestingProfile, $collaboration->requestingRepresentation)
                            : [],
                        'target' => $targetProfile
                            ? $maskingService->maskedSummary($targetProfile, $collaboration->targetRepresentation)
                            : [],
                    ],
                ];
            });

        return view('suchak.collaborations.index', [
            'suchakAccount' => $account,
            'collaborations' => $collaborations,
            'collaborationSummaries' => $collaborationSummaries,
            'status' => $status,
            'direction' => $direction,
            'statusGroup' => $statusGroup,
            'overdue' => $overdue,
            'statuses' => SuchakCollaborationRequest::STATUSES,
            'splitTypes' => SuchakCommissionAgreement::SPLIT_TYPES,
            'ledgerTypeOptions' => SuchakLedgerEntry::TYPES,
            'ledgerStatusOptions' => SuchakLedgerEntry::STATUSES,
            'paymentCollectorOptions' => SuchakPaymentContext::PAYMENT_COLLECTORS,
            'suggestedOpportunities' => $collaborationService->suggestedOpportunities($account),
            'collaborationSlaDays' => $limitService->collaborationSlaDays(),
            'stats' => [
                'incoming_pending' => SuchakCollaborationRequest::query()
                    ->where('target_suchak_account_id', $account->id)
                    ->where('status', SuchakCollaborationRequest::STATUS_PENDING)
                    ->count(),
                'outgoing_pending' => SuchakCollaborationRequest::query()
                    ->where('requesting_suchak_account_id', $account->id)
                    ->where('status', SuchakCollaborationRequest::STATUS_PENDING)
                    ->count(),
                'accepted' => SuchakCollaborationRequest::query()
                    ->where(function ($query) use ($account): void {
                        $query
                            ->where('requesting_suchak_account_id', $account->id)
                            ->orWhere('target_suchak_account_id', $account->id);
                    })
                    ->where('status', SuchakCollaborationRequest::STATUS_ACCEPTED)
                    ->count(),
                'overdue' => SuchakCollaborationRequest::query()
                    ->where(function ($query) use ($account): void {
                        $query
                            ->where('requesting_suchak_account_id', $account->id)
                            ->orWhere('target_suchak_account_id', $account->id);
                    })
                    ->where('status', SuchakCollaborationRequest::STATUS_PENDING)
                    ->where('expires_at', '<=', now())
                    ->count(),
            ],
        ]);
    }

    public function store(Request $request, SuchakCollaborationService $collaborationService): RedirectResponse
    {
        $account = $request->user()?->suchakAccount;
        if (! $account) {
            abort(403, 'Suchak account is required.');
        }

        $validated = $request->validate([
            'requesting_representation_id' => ['required', 'integer', 'exists:suchak_profile_representations,id'],
            'target_representation_id' => ['required', 'integer', 'exists:suchak_profile_representations,id'],
            'message' => ['nullable', 'string', 'max:2000'],
            'commission_ack' => ['accepted'],
            'split_type' => ['nullable', 'string', Rule::in(SuchakCommissionAgreement::SPLIT_TYPES)],
            'groom_side_share' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'bride_side_share' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fixed_amount' => ['nullable', 'numeric', 'min:0.01', 'max:999999999.99'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        try {
            $collaborationService->createRequest(
                $account,
                $request->user(),
                SuchakProfileRepresentation::query()->findOrFail((int) $validated['requesting_representation_id']),
                SuchakProfileRepresentation::query()->findOrFail((int) $validated['target_representation_id']),
                array_merge(['message' => $validated['message'] ?? null], $this->commissionTerms($validated)),
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return back()
                ->withInput()
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('suchak.collaborations.index', [
                'direction' => 'outgoing',
                'status' => SuchakCollaborationRequest::STATUS_PENDING,
            ])
            ->with('success', 'Collaboration request sent. Track it in Outgoing pending; the target Suchak will see it in Incoming pending.');
    }

    public function updateCommission(
        Request $request,
        SuchakCollaborationRequest $collaborationRequest,
        SuchakCollaborationService $collaborationService,
    ): RedirectResponse {
        $account = $request->user()?->suchakAccount;
        if (! $account) {
            abort(403, 'Suchak account is required.');
        }

        $validated = $request->validate($this->commissionValidationRules(required: true));

        try {
            $collaborationService->updateCommissionTerms(
                $collaborationRequest,
                $account,
                $request->user(),
                $validated,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return back()
                ->withInput()
                ->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Commission agreement terms updated.');
    }

    public function accept(
        Request $request,
        SuchakCollaborationRequest $collaborationRequest,
        SuchakCollaborationService $collaborationService,
    ): RedirectResponse {
        $account = $request->user()?->suchakAccount;
        if (! $account) {
            abort(403, 'Suchak account is required.');
        }

        try {
            $collaborationService->acceptRequest(
                $collaborationRequest,
                $account,
                $request->user(),
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Collaboration request accepted.');
    }

    public function reject(
        Request $request,
        SuchakCollaborationRequest $collaborationRequest,
        SuchakCollaborationService $collaborationService,
    ): RedirectResponse {
        $account = $request->user()?->suchakAccount;
        if (! $account) {
            abort(403, 'Suchak account is required.');
        }

        try {
            $collaborationService->rejectRequest(
                $collaborationRequest,
                $account,
                $request->user(),
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Collaboration request rejected.');
    }

    public function expire(
        Request $request,
        SuchakCollaborationRequest $collaborationRequest,
        SuchakCollaborationService $collaborationService,
    ): RedirectResponse {
        $account = $request->user()?->suchakAccount;
        if (! $account) {
            abort(403, 'Suchak account is required.');
        }

        try {
            $collaborationService->expireForAccount(
                $collaborationRequest,
                $account,
                $request->user(),
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Overdue collaboration request expired.');
    }

    public function storeLedgerEntry(
        Request $request,
        SuchakCollaborationRequest $collaborationRequest,
        SuchakCollaborationService $collaborationService,
        SuchakCrmLedgerService $crmLedgerService,
    ): RedirectResponse {
        $account = $request->user()?->suchakAccount;
        if (! $account) {
            abort(403, 'Suchak account is required.');
        }

        $validated = $request->validate([
            'entry_type' => ['required', 'string', Rule::in(SuchakLedgerEntry::TYPES)],
            'payment_collector' => ['required', 'string', Rule::in(SuchakPaymentContext::PAYMENT_COLLECTORS)],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'currency' => ['required', 'string', 'size:3'],
            'status' => ['required', 'string', Rule::in(SuchakLedgerEntry::STATUSES)],
            'due_date' => ['nullable', 'date'],
            'paid_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
            'resolution_note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $collaborationService->assertCanRecordCollaborationIncome(
                $collaborationRequest,
                $account,
                $request->user(),
                $validated['payment_collector'],
            );
            $profile = ((int) $account->id === (int) $collaborationRequest->requesting_suchak_account_id)
                ? $collaborationRequest->targetMatrimonyProfile()->firstOrFail()
                : $collaborationRequest->requestingMatrimonyProfile()->firstOrFail();

            $crmLedgerService->createLedgerEntry(
                $account,
                $request->user(),
                $profile,
                array_merge($validated, [
                    'collaboration_request_id' => $collaborationRequest->id,
                    'source_owner' => SuchakPaymentContext::SOURCE_COLLABORATION,
                    'payment_collector' => SuchakPaymentContext::COLLECTOR_SUCHAK,
                ]),
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return back()
                ->withInput()
                ->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Collaboration ledger entry linked.');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function commissionValidationRules(bool $required): array
    {
        return [
            'split_type' => [$required ? 'required' : 'nullable', 'string', Rule::in(SuchakCommissionAgreement::SPLIT_TYPES)],
            'groom_side_share' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'bride_side_share' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fixed_amount' => ['nullable', 'numeric', 'min:0.01', 'max:999999999.99'],
            'currency' => [$required ? 'required' : 'nullable', 'string', 'size:3'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function commissionTerms(array $validated): array
    {
        return array_intersect_key($validated, array_flip([
            'split_type',
            'groom_side_share',
            'bride_side_share',
            'fixed_amount',
            'currency',
        ]));
    }
}
