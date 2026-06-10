<?php

namespace App\Http\Controllers\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakActivityLog;
use App\Models\SuchakBiodataExport;
use App\Models\SuchakBiodataIntakeLink;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakProfileUpdateSuggestion;
use App\Modules\Suchak\Services\SuchakBillingCatalogService;
use App\Modules\Suchak\Services\SuchakCandidateMaskingService;
use App\Modules\Suchak\Services\SuchakProfileUpdateSuggestionService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(
        Request $request,
        SuchakBillingCatalogService $billingCatalog,
        SuchakCandidateMaskingService $maskingService,
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
            ])
            ->latest()
            ->limit(8)
            ->get();

        $representationCards = $representations->map(function (SuchakProfileRepresentation $representation) use ($account, $maskingService): array {
            $summary = $representation->matrimonyProfile
                ? $maskingService->maskedSummary($representation->matrimonyProfile, $representation)
                : [];

            $hasActionableConsent = $representation->representation_status === SuchakProfileRepresentation::STATUS_ACTIVE
                && $representation->hasValidConsent();

            return [
                'representation' => $representation,
                'summary' => $summary,
                'can_export' => $account->isVerified() && $hasActionableConsent,
                'can_suggest_updates' => $account->isVerified() && $hasActionableConsent,
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

        $activeSubscription = $account->isVerified()
            ? $billingCatalog->activeSubscriptionFor($account)
            : null;

        $featureLimits = $activeSubscription
            ? $billingCatalog->currentFeatureLimits($account)
            : [];

        $catalogPlans = $account->isVerified()
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
