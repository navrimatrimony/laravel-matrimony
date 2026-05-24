<?php

namespace App\Http\Controllers;

use App\Models\MatrimonyProfile;
use App\Models\ProfileView;
use App\Services\FeatureUsageService;
use App\Services\ViewTrackingService;
use App\Services\WhoViewed\WhoViewedTeaserPolicy;
use App\Services\WhoViewed\WhoViewedTeaserPresenter;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class WhoViewedController extends Controller
{
    /** Hard cap on raw profile_view rows read per request (distinct viewers are derived in PHP). */
    private const PROFILE_VIEW_EVENTS_FETCH_CAP = 12000;

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
            $whoViewedRows = $this->buildLockedTeaserRows($profile, $teaserPolicy, null);

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
        $fifoPartialSlots = ! $hasFullAccess && $previewLimit > 0;
        $listOrder = (string) ($teaserPolicy['partial_plan_list_order'] ?? 'fifo_unlocked_first');
        if (! in_array($listOrder, WhoViewedTeaserPolicy::PARTIAL_PLAN_LIST_ORDERS, true)) {
            $listOrder = 'fifo_unlocked_first';
        }

        $fifoSlotIds = null;
        $uniqueByViewer = $this->uniqueViewerViewsForProfile(
            $profile,
            $since,
            null,
            $fifoPartialSlots,
            $fifoPartialSlots ? $previewLimit : 0,
            $listOrder,
            $fifoSlotIds,
        );
        $uniqueCount = $uniqueByViewer->count();

        if ($hasFullAccess) {
            $whoViewedRowsAll = $this->buildFullRows($uniqueByViewer);
            $whoViewedPartial = false;
            $lockedOverflowCount = 0;
        } else {
            $whoViewedRowsAll = $this->buildPartialRows(
                $profile,
                $uniqueByViewer,
                $previewLimit,
                $teaserPolicy,
                $since,
                $fifoSlotIds,
            );
            $lockedOverflowCount = count(array_filter($whoViewedRowsAll, static fn (array $r) => $r['display'] === 'teaser'));
            $whoViewedPartial = $previewLimit > 0 && $lockedOverflowCount > 0;
        }

        $perPage = (int) ($teaserPolicy['who_viewed_per_page'] ?? 15);
        $perPage = max(5, min(50, $perPage));
        $page = max(1, (int) $request->query('page', 1));
        $totalRows = count($whoViewedRowsAll);
        $whoViewedRowsPage = array_slice($whoViewedRowsAll, ($page - 1) * $perPage, $perPage);
        $whoViewedRows = new LengthAwarePaginator(
            $whoViewedRowsPage,
            $totalRows,
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
                'pageName' => 'page',
            ]
        );

        if ($request->wantsJson()) {
            $recentFull = array_values(array_filter(
                $whoViewedRowsPage,
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
                'rows' => $this->serializeWhoViewedRowsForJson($whoViewedRowsPage),
                'window_days' => $windowDays,
                'since' => $since?->toIso8601String(),
                'teaser_unique_count' => $uniqueCount,
                'pagination' => [
                    'current_page' => $whoViewedRows->currentPage(),
                    'per_page' => $whoViewedRows->perPage(),
                    'total' => $whoViewedRows->total(),
                    'last_page' => $whoViewedRows->lastPage(),
                ],
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
    private function buildLockedTeaserRows(MatrimonyProfile $profile, array $teaserPolicy, ?Carbon $sinceForCounts): array
    {
        $max = $this->maxViewerCardsFromPolicy($teaserPolicy);
        $fifoSlotIdsIgnored = null;
        $samples = $this->uniqueViewerViewsForProfile(
            $profile,
            null,
            $max,
            false,
            0,
            'fifo_unlocked_first',
            $fifoSlotIdsIgnored,
        );
        $this->eagerLoadWhoViewedViewerRelations($samples);
        $viewerIds = $samples->map(fn (ProfileView $v) => (int) $v->viewer_profile_id)->unique()->values()->all();
        $counts = $this->viewerProfileViewCounts($profile, $sinceForCounts, $viewerIds);
        $policyForTeaser = WhoViewedTeaserPolicy::forWhoViewedLockedTeasers($teaserPolicy);

        $rows = [];
        foreach ($samples as $view) {
            $rows[] = [
                'display' => 'teaser',
                'view' => $view,
                'teaser' => $this->whoViewedTeaserPresenter->present($view, $policyForTeaser, [
                    'owner_profile' => $profile,
                    'viewer_view_count' => (int) ($counts[(int) $view->viewer_profile_id] ?? 1),
                ]),
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
     * When the member has a partial plan, {@code $fifoSlotViewerIds} maps viewer_profile_id => true for FIFO “full row” slots.
     *
     * @param  array<int, true>|null  $fifoSlotViewerIds
     * @return list<array{display: string, view: ProfileView, teaser: ?array<string, mixed>}>
     */
    private function buildPartialRows(
        MatrimonyProfile $owner,
        Collection $orderedUnique,
        int $previewLimit,
        array $teaserPolicy,
        ?Carbon $since,
        ?array $fifoSlotViewerIds,
    ): array {
        $this->eagerLoadWhoViewedViewerRelations($orderedUnique);
        $teaserViews = $orderedUnique->filter(function (ProfileView $v) use ($fifoSlotViewerIds) {
            $vid = (int) $v->viewer_profile_id;
            if (is_array($fifoSlotViewerIds)) {
                return ! (($fifoSlotViewerIds[$vid] ?? false) === true);
            }

            return true;
        });
        $viewerIds = $teaserViews->map(fn (ProfileView $v) => (int) $v->viewer_profile_id)->unique()->values()->all();
        $counts = $this->viewerProfileViewCounts($owner, $since, $viewerIds);
        $policyForTeaser = WhoViewedTeaserPolicy::forWhoViewedLockedTeasers($teaserPolicy);

        $rows = [];
        $i = 0;
        foreach ($orderedUnique as $view) {
            $vid = (int) $view->viewer_profile_id;
            $isFull = is_array($fifoSlotViewerIds)
                ? (($fifoSlotViewerIds[$vid] ?? false) === true)
                : ($i < $previewLimit);
            if ($isFull) {
                $rows[] = ['display' => 'full', 'view' => $view, 'teaser' => null];
            } else {
                $rows[] = [
                    'display' => 'teaser',
                    'view' => $view,
                    'teaser' => $this->whoViewedTeaserPresenter->present($view, $policyForTeaser, [
                        'owner_profile' => $owner,
                        'viewer_view_count' => (int) ($counts[(int) $view->viewer_profile_id] ?? 1),
                    ]),
                ];
            }
            $i++;
        }

        return $rows;
    }

    /**
     * @param  list<int>  $viewerProfileIds
     * @return array<int, int>
     */
    private function viewerProfileViewCounts(MatrimonyProfile $owner, ?Carbon $since, array $viewerProfileIds): array
    {
        if ($viewerProfileIds === []) {
            return [];
        }
        $blockedIds = ViewTrackingService::getBlockedProfileIds($owner->id);
        $q = ProfileView::query()
            ->where('viewed_profile_id', $owner->id)
            ->whereIn('viewer_profile_id', $viewerProfileIds)
            ->whereNotIn('viewer_profile_id', $blockedIds);
        if ($since !== null) {
            $q->where('created_at', '>=', $since);
        }
        $rows = $q->selectRaw('viewer_profile_id, COUNT(*) as c')
            ->groupBy('viewer_profile_id')
            ->get();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->viewer_profile_id] = (int) $r->c;
        }

        return $out;
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
     * One row per distinct viewer (latest view row kept for display).
     *
     * @param  array<int, true>|null  $fifoSlotViewerIdsOut  When partial FIFO mode is on, receives viewer ids that keep a full row (first views in window).
     * @return Collection<int, ProfileView>
     */
    private function uniqueViewerViewsForProfile(
        MatrimonyProfile $profile,
        ?Carbon $since,
        ?int $distinctViewerLimit,
        bool $fifoPreviewSlots,
        int $previewLimitForFifo,
        string $partialListOrder,
        ?array &$fifoSlotViewerIdsOut,
    ): Collection {
        $fifoSlotViewerIdsOut = null;
        $blockedIds = ViewTrackingService::getBlockedProfileIds($profile->id);

        $query = ProfileView::query()
            ->where('viewed_profile_id', $profile->id)
            ->whereNotIn('viewer_profile_id', $blockedIds)
            ->with('viewerProfile.user')
            ->orderByDesc('created_at')
            ->limit(self::PROFILE_VIEW_EVENTS_FETCH_CAP);

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

        $perViewer = $rows->groupBy('viewer_profile_id')->map(function ($group) {
            $latest = $group->sortByDesc('created_at')->first();
            $firstAt = $group->min('created_at');

            return [
                'view' => $latest,
                'first_at' => $firstAt instanceof Carbon ? $firstAt : Carbon::parse((string) $firstAt),
                'last_at' => $latest->created_at instanceof Carbon ? $latest->created_at : Carbon::parse((string) $latest->created_at),
            ];
        });

        if (! $fifoPreviewSlots || $previewLimitForFifo < 1) {
            $out = $perViewer->sortByDesc(fn (array $meta) => $meta['last_at']->timestamp)
                ->map(fn (array $meta) => $meta['view'])
                ->values();

            $wrapped = $this->wrapProfileViewCollection($distinctViewerLimit !== null ? $out->take($distinctViewerLimit) : $out);

            return $wrapped;
        }

        $byFirst = $perViewer->sortBy(fn (array $meta) => [$meta['first_at']->timestamp, (int) $meta['view']->viewer_profile_id])->values();
        $fullViewerIds = $byFirst->take($previewLimitForFifo)
            ->map(fn (array $meta) => (int) $meta['view']->viewer_profile_id)
            ->all();
        $fullSet = array_fill_keys($fullViewerIds, true);
        $fifoSlotViewerIdsOut = $fullSet;

        if ($partialListOrder === 'recent_activity_first') {
            $out = $perViewer->sortByDesc(fn (array $meta) => $meta['last_at']->timestamp)
                ->map(fn (array $meta) => $meta['view'])
                ->values();
        } else {
            $fullPart = $byFirst->filter(fn (array $meta) => isset($fullSet[(int) $meta['view']->viewer_profile_id]))
                ->sortBy(fn (array $meta) => [$meta['first_at']->timestamp, (int) $meta['view']->viewer_profile_id])
                ->values();

            $teaserPart = $byFirst->filter(fn (array $meta) => ! isset($fullSet[(int) $meta['view']->viewer_profile_id]))
                ->sortByDesc(fn (array $meta) => $meta['last_at']->timestamp)
                ->values();

            $out = $fullPart->concat($teaserPart)->map(fn (array $meta) => $meta['view'])->values();
        }

        return $this->wrapProfileViewCollection($distinctViewerLimit !== null ? $out->take($distinctViewerLimit) : $out);
    }

    /**
     * @param  Collection<int, ProfileView>  $views
     * @return EloquentCollection<int, ProfileView>
     */
    private function wrapProfileViewCollection(Collection $views): EloquentCollection
    {
        return new EloquentCollection($views->all());
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
