<?php

namespace App\Http\Controllers\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakActivityLog;
use App\Models\SuchakBiodataExport;
use App\Models\SuchakBiodataIntakeLink;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakConsent;
use App\Models\SuchakLedgerEntry;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakPlanPayment;
use App\Models\SuchakProfileNote;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakProfileUpdateSuggestion;
use App\Modules\Suchak\Services\SuchakAccessService;
use App\Modules\Suchak\Services\SuchakBillingCatalogService;
use App\Modules\Suchak\Services\SuchakCandidateMaskingService;
use App\Modules\Suchak\Services\SuchakCustomerListService;
use App\Modules\Suchak\Services\SuchakDailyOpportunityService;
use App\Modules\Suchak\Services\SuchakEntitlementService;
use App\Modules\Suchak\Services\SuchakIncomeAnalyticsService;
use App\Modules\Suchak\Services\SuchakPaymentStatusService;
use App\Modules\Suchak\Services\SuchakPolicyService;
use App\Modules\Suchak\Services\SuchakProfileUpdateSuggestionService;
use App\Modules\Suchak\Services\SuchakWhiteLabelSharingKitService;
use App\Modules\Suchak\Services\SuchakWorkflowAutomationService;
use App\Support\Suchak\SuchakOnboardingPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(
        Request $request,
        SuchakAccessService $accessService,
        SuchakBillingCatalogService $billingCatalog,
        SuchakCandidateMaskingService $maskingService,
        SuchakCustomerListService $customerListService,
        SuchakDailyOpportunityService $dailyOpportunityService,
        SuchakEntitlementService $entitlementService,
        SuchakIncomeAnalyticsService $incomeAnalyticsService,
        SuchakPaymentStatusService $paymentStatusService,
        SuchakPolicyService $policyService,
        SuchakProfileUpdateSuggestionService $suggestionService,
        SuchakWhiteLabelSharingKitService $sharingKitService,
        SuchakWorkflowAutomationService $workflowAutomationService,
        SuchakOnboardingPresenter $onboardingPresenter,
    ): View
    {
        $account = $request->user()
            ->suchakAccount()
            ->with(['user', 'verificationRecords' => fn ($query) => $query->latest('id')])
            ->firstOrFail();
        $businessRecordFilters = $this->businessRecordFilters($request);
        $accountCanOperate = $accessService->canOperate($account);

        $representations = $account->profileRepresentations()
            ->with([
                'matrimonyProfile.gender',
                'matrimonyProfile.maritalStatus',
                'matrimonyProfile.religion',
                'matrimonyProfile.caste',
                'matrimonyProfile.location.parent.parent.parent',
                'matrimonyProfile.occupationMaster',
                'consents.events.actorUser',
            ])
            ->latest()
            ->get();

        $representationCards = $representations->map(function (SuchakProfileRepresentation $representation) use ($account, $accountCanOperate, $maskingService, $businessRecordFilters): array {
            $summary = $representation->matrimonyProfile
                ? $maskingService->maskedSummary($representation->matrimonyProfile, $representation)
                : [];
            $crmNotes = $this->crmNotesForRepresentation(
                (int) $account->id,
                (int) $representation->matrimony_profile_id,
                $businessRecordFilters,
            );
            $ledgerEntries = $this->ledgerEntriesForRepresentation(
                (int) $account->id,
                (int) $representation->matrimony_profile_id,
                $businessRecordFilters,
            );

            $hasActionableConsent = $representation->representation_status === SuchakProfileRepresentation::STATUS_ACTIVE
                && $representation->hasValidConsent();
            $profileIsActive = $representation->matrimonyProfile !== null
                && ($representation->matrimonyProfile->lifecycle_state ?? null) === 'active'
                && ! (bool) ($representation->matrimonyProfile->is_suspended ?? false);
            $canOperate = $accountCanOperate;
            $consents = $representation->consents->sortByDesc('created_at')->values();
            $pendingConsent = $consents
                ->first(fn (SuchakConsent $consent): bool => in_array($consent->consent_status, SuchakConsent::PENDING_ACTION_STATUSES, true));
            $acceptedConsent = $consents
                ->first(fn (SuchakConsent $consent): bool => $consent->consent_status === SuchakConsent::STATUS_ACCEPTED && $consent->revoked_at === null);
            $latestConsent = $consents->first();
            $consentTimeline = $consents
                ->flatMap(fn (SuchakConsent $consent) => $consent->events)
                ->sortByDesc('created_at')
                ->take(6)
                ->values();

            return [
                'representation' => $representation,
                'summary' => $summary,
                'latest_consent' => $latestConsent,
                'pending_consent' => $pendingConsent,
                'accepted_consent' => $acceptedConsent,
                'consent_timeline' => $consentTimeline,
                'crm_notes' => $crmNotes,
                'ledger_entries' => $ledgerEntries,
                'can_export' => $canOperate && $hasActionableConsent,
                'can_request_consent' => $canOperate
                    && $pendingConsent === null
                    && in_array($representation->representation_status, [
                        SuchakProfileRepresentation::STATUS_PENDING,
                        SuchakProfileRepresentation::STATUS_CONSENT_PENDING,
                    ], true)
                    && $representation->revoked_at === null
                    && $representation->candidate_deactivated_at === null,
                'can_renew_consent' => $canOperate
                    && $pendingConsent === null
                    && $representation->representation_status === SuchakProfileRepresentation::STATUS_ACTIVE
                    && $representation->hasValidConsent(),
                'can_revoke_consent' => $canOperate && $acceptedConsent !== null && $representation->hasValidConsent(),
                'can_suggest_updates' => $canOperate && $hasActionableConsent && $profileIsActive,
            ];
        });

        $pendingCollaborations = SuchakCollaborationRequest::query()
            ->with([
                'requestingRepresentation.matrimonyProfile.gender',
                'targetRepresentation.matrimonyProfile.gender',
            ])
            ->where('target_suchak_account_id', $account->id)
            ->where('status', SuchakCollaborationRequest::STATUS_PENDING)
            ->latest('requested_at')
            ->limit(5)
            ->get();

        $recentSourceLinks = SuchakBiodataIntakeLink::query()
            ->with(['biodataIntake.profile', 'matrimonyProfile'])
            ->where('suchak_account_id', $account->id)
            ->latest()
            ->limit(10)
            ->get();

        $recentExports = SuchakBiodataExport::query()
            ->with(['qrTokens' => fn ($query) => $query->latest()])
            ->where('suchak_account_id', $account->id)
            ->latest()
            ->limit(5)
            ->get();

        $recentSuggestions = SuchakProfileUpdateSuggestion::query()
            ->where('suchak_account_id', $account->id)
            ->latest()
            ->limit(5)
            ->get();

        $activityLogs = SuchakActivityLog::query()
            ->where('suchak_account_id', $account->id)
            ->latest('occurred_at')
            ->limit(8)
            ->get();

        $activeSubscription = $accountCanOperate
            ? $paymentStatusService->activeSubscriptionFor($account)
            : null;

        $featureLimits = $activeSubscription
            ? $entitlementService->currentFeatureLimits($account)
            : [];

        $catalogPlans = $accountCanOperate
            ? $billingCatalog->visibleCatalogForSuchak($account, $request->user())
            : collect();

        $billingUsageSummary = $activeSubscription
            ? $billingCatalog->usageSummary($account)
            : [];

        $recentPlanPayments = SuchakPlanPayment::query()
            ->with(['invoice', 'suchakPlan'])
            ->where('suchak_account_id', $account->id)
            ->latest()
            ->limit(5)
            ->get();

        return view('suchak.dashboard', [
            'suchakAccount' => $account,
            'canOperate' => $accountCanOperate,
            'representationCards' => $representationCards,
            'customerListRows' => $customerListService->rowsForAccount($account),
            'pendingCollaborations' => $pendingCollaborations,
            'recentSourceLinks' => $recentSourceLinks,
            'recentExports' => $recentExports,
            'recentSuggestions' => $recentSuggestions,
            'activityLogs' => $activityLogs,
            'dailyOpportunities' => $dailyOpportunityService->dailyWorklist($account),
            'incomeAnalytics' => $incomeAnalyticsService->summary($account),
            'sharingKit' => $sharingKitService->assetsFor($account),
            'workflowReminders' => $workflowAutomationService->recentReminders($account),
            'workflowTimeline' => $workflowAutomationService->recentTimeline($account),
            'workflowTemplates' => $workflowAutomationService->whatsappTemplateCatalog(),
            'activeSubscription' => $activeSubscription,
            'featureLimits' => $featureLimits,
            'billingUsageSummary' => $billingUsageSummary,
            'paymentStatus' => $paymentStatusService->statusFor($account),
            'billingPolicySummary' => [
                'free_trial_days' => $policyService->freeTrialDays(),
                'grace_period_days' => $policyService->gracePeriodDays(),
                'pricing_mode' => $policyService->planPricingMode(),
                'payment_mode' => $policyService->paymentMode(),
            ],
            'catalogPlans' => $catalogPlans,
            'recentPlanPayments' => $recentPlanPayments,
            'allowedSuggestionFields' => $suggestionService->allowedCoreFieldKeys(),
            'consentChannelOptions' => SuchakConsent::CHANNELS,
            'consentTypeOptions' => SuchakConsent::TYPES,
            'noteTypeOptions' => SuchakProfileNote::TYPES,
            'ledgerTypeOptions' => SuchakLedgerEntry::TYPES,
            'ledgerStatusOptions' => SuchakLedgerEntry::STATUSES,
            'sourceOwnerOptions' => SuchakPaymentContext::SOURCE_OWNERS,
            'paymentCollectorOptions' => SuchakPaymentContext::PAYMENT_COLLECTORS,
            'businessRecordFilters' => $businessRecordFilters,
            'onboarding' => $onboardingPresenter->forAccount($account, $account->verificationRecords),
            'stats' => [
                'representations_total' => $account->profileRepresentations()->count(),
                'representations_active' => $account->profileRepresentations()->withValidConsent()->count(),
                'source_links' => $account->profileRepresentations()->whereNotNull('biodata_intake_id')->count()
                    + SuchakBiodataIntakeLink::query()->where('suchak_account_id', $account->id)->count(),
                'pending_collaborations' => $pendingCollaborations->count(),
            ],
        ]);
    }

    public function storeProfilePhoto(Request $request): RedirectResponse
    {
        $account = $request->user()
            ->suchakAccount()
            ->firstOrFail();

        $validated = $request->validate([
            'profile_photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        $path = $validated['profile_photo']->store('suchak/profile-photos/'.$account->id, 'public');

        if (! is_string($path) || $path === '') {
            return back()->with('error', 'Unable to upload Suchak profile photo.');
        }

        $account->forceFill([
            'profile_photo_path' => $path,
        ])->save();

        return back()->with('success', 'Suchak card photo updated.');
    }

    /**
     * @return array{business_q: string, note_type: ?string, ledger_status: ?string}
     */
    private function businessRecordFilters(Request $request): array
    {
        $search = trim((string) $request->query('business_q', ''));
        if (strlen($search) > 80) {
            $search = substr($search, 0, 80);
        }

        $noteType = trim((string) $request->query('note_type', ''));
        if (! in_array($noteType, SuchakProfileNote::TYPES, true)) {
            $noteType = null;
        }

        $ledgerStatus = trim((string) $request->query('ledger_status', ''));
        if (! in_array($ledgerStatus, SuchakLedgerEntry::STATUSES, true)) {
            $ledgerStatus = null;
        }

        return [
            'business_q' => $search,
            'note_type' => $noteType,
            'ledger_status' => $ledgerStatus,
        ];
    }

    /**
     * @param  array{business_q: string, note_type: ?string, ledger_status: ?string}  $filters
     */
    private function crmNotesForRepresentation(int $accountId, int $profileId, array $filters): Collection
    {
        return SuchakProfileNote::query()
            ->where('suchak_account_id', $accountId)
            ->where('matrimony_profile_id', $profileId)
            ->when($filters['note_type'], fn ($query, string $noteType) => $query->where('note_type', $noteType))
            ->when($filters['business_q'] !== '', function ($query) use ($filters): void {
                $query->where(function ($query) use ($filters): void {
                    $query
                        ->where('note_text', 'like', '%'.$filters['business_q'].'%')
                        ->orWhere('note_type', 'like', '%'.$filters['business_q'].'%');
                });
            })
            ->latest()
            ->limit(5)
            ->get();
    }

    /**
     * @param  array{business_q: string, note_type: ?string, ledger_status: ?string}  $filters
     */
    private function ledgerEntriesForRepresentation(int $accountId, int $profileId, array $filters): Collection
    {
        return SuchakLedgerEntry::query()
            ->where('suchak_account_id', $accountId)
            ->where('matrimony_profile_id', $profileId)
            ->when($filters['ledger_status'], fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['business_q'] !== '', function ($query) use ($filters): void {
                $query->where(function ($query) use ($filters): void {
                    $query
                        ->where('note', 'like', '%'.$filters['business_q'].'%')
                        ->orWhere('entry_type', 'like', '%'.$filters['business_q'].'%')
                        ->orWhere('status', 'like', '%'.$filters['business_q'].'%');
                });
            })
            ->latest()
            ->limit(5)
            ->get();
    }
}
