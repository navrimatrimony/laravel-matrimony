<?php

namespace App\Http\Controllers;

use App\Models\MatrimonyProfile;
use App\Models\ProfileView;
use App\Services\FeatureUsageService;
use App\Services\ViewTrackingService;
use App\Services\WhoViewed\WhoViewedTeaserPolicy;
use App\Services\WhoViewed\WhoViewedTeaserPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class WhoViewedController extends Controller
{
    public function __construct(
        protected FeatureUsageService $featureUsage,
        protected WhoViewedTeaserPresenter $whoViewedTeaserPresenter,
    ) {}

    public function index(Request $request): View|JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->matrimonyProfile) {
            abort(403);
        }

        $profile = $user->matrimonyProfile;
        $userId = (int) $user->id;
        $previewLimit = $this->featureUsage->getWhoViewedMePreviewLimit($userId);
        $hasFullAccess = $this->featureUsage->whoViewedMeHasFullViewerList($user);
        $previewWindow = $this->featureUsage->whoViewedMePreviewWindow($user);
        $windowDays = $previewWindow['window_days'];
        $whoViewedEmptyUsesMonth = (bool) ($previewWindow['uses_month_copy'] ?? false);
        $teaserUniqueCount = ViewTrackingService::countEligibleDistinctViewersForTeaser((int) $profile->id);
        $plansUrl = route('plans.index');
        $teaserPolicy = WhoViewedTeaserPolicy::normalized();

        if (! $this->featureUsage->canUse($userId, FeatureUsageService::FEATURE_WHO_VIEWED_ME_ACCESS)) {
            $whoViewedRows = $this->buildLockedTeaserRows($profile, $teaserPolicy);

            $teaserCards = array_map(
                static fn (array $row) => $row['teaser'],
                array_values(array_filter($whoViewedRows, static fn (array $r) => $r['display'] === 'teaser'))
            );

            if ($request->wantsJson()) {
                return response()->json([
                    'locked' => true,
                    'message' => __('who_viewed.locked_json_message'),
                    'teaser_unique_count' => $teaserUniqueCount,
                    'teaser_cards' => $teaserCards,
                    'rows' => $this->serializeWhoViewedRowsForJson($whoViewedRows),
                ]);
            }

            return view('who-viewed.index', [
                'profile' => $profile,
                'uniqueCount' => 0,
                'since' => null,
                'whoViewedLocked' => true,
                'windowDays' => null,
                'teaserUniqueCount' => $teaserUniqueCount,
                'whoViewedRows' => $whoViewedRows,
                'teaserPolicy' => $teaserPolicy,
                'plansUrl' => $plansUrl,
                'whoViewedPartial' => false,
                'lockedOverflowCount' => 0,
                'previewLimit' => 0,
                'whoViewedEmptyUsesMonth' => $whoViewedEmptyUsesMonth,
                'hasFullWhoViewedAccess' => false,
            ]);
        }

        $since = $previewWindow['since'];
        $maxCards = $this->effectiveMaxViewerCards($teaserPolicy, $hasFullAccess, $previewLimit);
        $uniqueByViewer = $this->uniqueViewerViewsForProfile($profile, $since, $maxCards);
        $uniqueCount = $this->uniqueViewerViewsForProfile($profile, $since, null)->count();

        if ($hasFullAccess) {
            $whoViewedRows = $this->buildFullRows($uniqueByViewer);
            $whoViewedPartial = false;
            $lockedOverflowCount = 0;
        } else {
            $whoViewedRows = $this->buildPartialRows($uniqueByViewer, $previewLimit, $teaserPolicy);
            $lockedOverflowCount = count(array_filter($whoViewedRows, static fn (array $r) => $r['display'] === 'teaser'));
            $whoViewedPartial = $previewLimit > 0 && $lockedOverflowCount > 0;
        }

        if ($request->wantsJson()) {
            $recentFull = array_values(array_filter(
                $whoViewedRows,
                static fn (array $r) => $r['display'] === 'full'
            ));

            return response()->json([
                'locked' => false,
                'partial_mode' => $whoViewedPartial,
                'preview_limit' => $hasFullAccess ? null : $previewLimit,
                'unique_count' => $uniqueCount,
                'overflow_count' => $lockedOverflowCount,
                'recent' => array_map(
                    fn (array $r) => $this->serializeOneRecent($r['view']),
                    $recentFull
                ),
                'rows' => $this->serializeWhoViewedRowsForJson($whoViewedRows),
                'window_days' => $windowDays,
                'since' => $since?->toIso8601String(),
                'teaser_unique_count' => $uniqueCount,
            ]);
        }

        return view('who-viewed.index', [
            'profile' => $profile,
            'uniqueCount' => $uniqueCount,
            'since' => $since,
            'whoViewedLocked' => false,
            'windowDays' => $windowDays,
            'teaserUniqueCount' => null,
            'whoViewedRows' => $whoViewedRows,
            'teaserPolicy' => $teaserPolicy,
            'plansUrl' => $plansUrl,
            'whoViewedPartial' => $whoViewedPartial,
            'lockedOverflowCount' => $lockedOverflowCount,
            'previewLimit' => $previewLimit,
            'hasFullWhoViewedAccess' => $hasFullAccess,
            'whoViewedEmptyUsesMonth' => ! $hasFullAccess && $whoViewedEmptyUsesMonth,
        ]);
    }

    /**
     * @return list<array{display: string, view: ProfileView, teaser: ?array<string, mixed>}>
     */
    private function buildLockedTeaserRows(MatrimonyProfile $profile, array $teaserPolicy): array
    {
        $max = $this->maxViewerCardsFromPolicy($teaserPolicy);
        $samples = $this->uniqueViewerViewsForProfile($profile, null, $max);
        $this->eagerLoadWhoViewedViewerRelations($samples);

        $rows = [];
        foreach ($samples as $view) {
            $rows[] = [
                'display' => 'teaser',
                'view' => $view,
                'teaser' => $this->whoViewedTeaserPresenter->present($view, $teaserPolicy),
            ];
        }

        return $rows;
    }

    /**
     * @param  Collection<int, ProfileView>  $views
     * @return list<array{display: string, view: ProfileView, teaser: ?array<string, mixed>}>
     */
    private function buildFullRows(Collection $views): array
    {
        $this->eagerLoadWhoViewedViewerRelations($views);
        $rows = [];
        foreach ($views as $view) {
            $rows[] = ['display' => 'full', 'view' => $view, 'teaser' => null];
        }

        return $rows;
    }

    /**
     * @param  Collection<int, ProfileView>  $orderedUnique
     * @return list<array{display: string, view: ProfileView, teaser: ?array<string, mixed>}>
     */
    private function buildPartialRows(Collection $orderedUnique, int $previewLimit, array $teaserPolicy): array
    {
        $this->eagerLoadWhoViewedViewerRelations($orderedUnique);
        $rows = [];
        $i = 0;
        foreach ($orderedUnique as $view) {
            if ($i < $previewLimit) {
                $rows[] = ['display' => 'full', 'view' => $view, 'teaser' => null];
            } else {
                $rows[] = [
                    'display' => 'teaser',
                    'view' => $view,
                    'teaser' => $this->whoViewedTeaserPresenter->present($view, $teaserPolicy),
                ];
            }
            $i++;
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $teaserPolicy
     */
    private function maxViewerCardsFromPolicy(array $teaserPolicy): int
    {
        $n = (int) ($teaserPolicy['locked_teaser_rows'] ?? 40);

        return max(1, min(60, $n));
    }

    /**
     * When preview is limited, fetch enough rows for “N full + per-card teasers” up to the global cap.
     *
     * @param  array<string, mixed>  $teaserPolicy
     */
    private function effectiveMaxViewerCards(array $teaserPolicy, bool $hasFullAccess, int $previewLimit): int
    {
        $policyMax = $this->maxViewerCardsFromPolicy($teaserPolicy);
        if ($hasFullAccess) {
            return $policyMax;
        }
        if ($previewLimit > 0) {
            return min(60, max($policyMax, $previewLimit + 25));
        }

        return $policyMax;
    }

    /**
     * @param  Collection<int, ProfileView>  $views
     */
    private function eagerLoadWhoViewedViewerRelations(Collection $views): void
    {
        $views->loadMissing([
            'viewerProfile.user',
            'viewerProfile.occupationMaster',
            'viewerProfile.occupationCustom',
            'viewerProfile.maritalStatus',
            'viewerProfile.location',
        ]);
    }

    /**
     * @param  list<array{display: string, view: ProfileView, teaser: ?array<string, mixed>}>  $rows
     * @return list<array<string, mixed>>
     */
    private function serializeWhoViewedRowsForJson(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if ($row['display'] === 'full') {
                $out[] = array_merge(['mode' => 'full'], $this->serializeOneRecent($row['view']));
            } else {
                $out[] = ['mode' => 'teaser', 'teaser' => $row['teaser']];
            }
        }

        return $out;
    }

    /**
     * @return Collection<int, ProfileView> One row per distinct viewer, most recent view first.
     */
    private function uniqueViewerViewsForProfile(MatrimonyProfile $profile, ?Carbon $since, ?int $limit): Collection
    {
        $blockedIds = ViewTrackingService::getBlockedProfileIds($profile->id);

        $query = ProfileView::query()
            ->where('viewed_profile_id', $profile->id)
            ->whereNotIn('viewer_profile_id', $blockedIds)
            ->with('viewerProfile.user')
            ->orderByDesc('created_at');

        if ($since !== null) {
            $query->where('created_at', '>=', $since);
        }

        $rows = $query->get()->filter(function (ProfileView $view) {
            $viewerProfile = $view->viewerProfile;
            $viewerUser = $viewerProfile?->user;
            if (! $viewerUser) {
                return false;
            }
            if ($viewerUser->is_admin ?? false) {
                return false;
            }
            if ($viewerProfile->is_suspended ?? false) {
                return false;
            }

            return true;
        });

        $out = $rows->groupBy('viewer_profile_id')
            ->map(function ($group) {
                return $group->sortByDesc('created_at')->first();
            })
            ->sortByDesc('created_at')
            ->values();

        return $limit !== null ? $out->take($limit) : $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeOneRecent(ProfileView $view): array
    {
        $viewerProfile = $view->viewerProfile;
        $viewerUser = $viewerProfile?->user;

        return [
            'viewer_profile_id' => $view->viewer_profile_id,
            'name' => $viewerProfile->full_name ?? $viewerUser?->name ?? 'Member',
            'profile_url' => $viewerProfile
                ? route('matrimony.profile.show', $viewerProfile->id)
                : null,
            'viewed_at' => $view->created_at->toIso8601String(),
            'viewed_at_human' => $view->created_at->diffForHumans(),
        ];
    }
}
