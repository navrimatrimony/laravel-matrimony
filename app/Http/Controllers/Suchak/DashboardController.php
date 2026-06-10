<?php

namespace App\Http\Controllers\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakActivityLog;
use App\Models\SuchakBiodataExport;
use App\Models\SuchakBiodataIntakeLink;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakConsent;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakProfileUpdateSuggestion;
use App\Modules\Suchak\Services\SuchakBillingCatalogService;
use App\Modules\Suchak\Services\SuchakAccessService;
use App\Modules\Suchak\Services\SuchakCandidateMaskingService;
use App\Modules\Suchak\Services\SuchakEntitlementService;
use App\Modules\Suchak\Services\SuchakPaymentStatusService;
use App\Modules\Suchak\Services\SuchakProfileUpdateSuggestionService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(
        Request $request,
        SuchakAccessService $accessService,
        SuchakBillingCatalogService $billingCatalog,
        SuchakCandidateMaskingService $maskingService,
        SuchakEntitlementService $entitlementService,
        SuchakPaymentStatusService $paymentStatusService,
        SuchakProfileUpdateSuggestionService $suggestionService,
    ): View
    {
        $account = $request->user()
            ->suchakAccount()
            ->with('user')
            ->firstOrFail();

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
            ->limit(8)
            ->get();

        $representationCards = $representations->map(function (SuchakProfileRepresentation $representation) use ($account, $accessService, $maskingService): array {
            $summary = $representation->matrimonyProfile
                ? $maskingService->maskedSummary($representation->matrimonyProfile, $representation)
                : [];

            $hasActionableConsent = $representation->representation_status === SuchakProfileRepresentation::STATUS_ACTIVE
                && $representation->hasValidConsent();
            $canOperate = $accessService->canOperate($account);
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
                'can_suggest_updates' => $canOperate && $hasActionableConsent,
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
            ->with('biodataIntake')
            ->where('suchak_account_id', $account->id)
            ->latest()
            ->limit(5)
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

        $activeSubscription = $accessService->canOperate($account)
            ? $paymentStatusService->activeSubscriptionFor($account)
            : null;

        $featureLimits = $activeSubscription
            ? $entitlementService->currentFeatureLimits($account)
            : [];

        $catalogPlans = $accessService->canOperate($account)
            ? $billingCatalog->visibleCatalogForSuchak($account, $request->user())
            : collect();

        return view('suchak.dashboard', [
            'suchakAccount' => $account,
            'representationCards' => $representationCards,
            'pendingCollaborations' => $pendingCollaborations,
            'recentSourceLinks' => $recentSourceLinks,
            'recentExports' => $recentExports,
            'recentSuggestions' => $recentSuggestions,
            'activityLogs' => $activityLogs,
            'activeSubscription' => $activeSubscription,
            'featureLimits' => $featureLimits,
            'catalogPlans' => $catalogPlans,
            'allowedSuggestionFields' => $suggestionService->allowedCoreFieldKeys(),
            'consentChannelOptions' => SuchakConsent::CHANNELS,
            'consentTypeOptions' => SuchakConsent::TYPES,
            'stats' => [
                'representations_total' => $account->profileRepresentations()->count(),
                'representations_active' => $account->profileRepresentations()->withValidConsent()->count(),
                'source_links' => $account->profileRepresentations()->whereNotNull('biodata_intake_id')->count()
                    + SuchakBiodataIntakeLink::query()->where('suchak_account_id', $account->id)->count(),
                'pending_collaborations' => $pendingCollaborations->count(),
            ],
        ]);
    }
}
