<?php

namespace App\Services\WhoViewed;

use App\Models\MatrimonyProfile;
use App\Models\ProfileView;
use App\Services\ProfileLifecycleService;
use App\Services\ViewTrackingService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

final class WhoViewedRowsService
{
    private const PROFILE_VIEW_EVENTS_FETCH_CAP = 12000;

    public function __construct(
        private readonly WhoViewedTeaserPresenter $teaserPresenter,
    ) {}

    /**
     * @return array{rows: list<array{display: string, view: ProfileView, teaser: ?array<string, mixed>}>, unique_count: int, full_count: int, overflow_count: int}
     */
    public function fullRows(
        MatrimonyProfile $owner,
        ?CarbonInterface $since = null,
        ?int $limit = null,
        ?string $targetGender = null,
    ): array {
        $result = $this->orderedUniqueViews($owner, $since, false, 0, 'fifo_unlocked_first', $targetGender);
        $views = $this->limitViews($result['views'], $limit);
        $this->eagerLoadViewerRelations($views);

        $rows = [];
        foreach ($views as $view) {
            $rows[] = [
                'display' => 'full',
                'view' => $view,
                'teaser' => null,
            ];
        }

        return [
            'rows' => $rows,
            'unique_count' => (int) $result['unique_count'],
            'full_count' => count($rows),
            'overflow_count' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $teaserPolicy
     * @return array{rows: list<array{display: string, view: ProfileView, teaser: ?array<string, mixed>}>, unique_count: int, full_count: int, overflow_count: int}
     */
    public function lockedTeaserRows(
        MatrimonyProfile $owner,
        array $teaserPolicy,
        ?int $limit = null,
        ?string $targetGender = null,
    ): array {
        $max = $this->maxViewerCardsFromPolicy($teaserPolicy);
        if ($limit !== null) {
            $max = min($max, max(0, $limit));
        }

        $result = $this->orderedUniqueViews($owner, null, false, 0, 'fifo_unlocked_first', $targetGender);
        $views = $this->limitViews($result['views'], $max);
        $this->eagerLoadViewerRelations($views);

        $counts = $this->viewerProfileViewCounts(
            $owner,
            null,
            $views->map(fn (ProfileView $view): int => (int) $view->viewer_profile_id)->unique()->values()->all(),
        );
        $policyForTeaser = WhoViewedTeaserPolicy::forWhoViewedLockedTeasers($teaserPolicy);

        $rows = [];
        foreach ($views as $view) {
            $rows[] = [
                'display' => 'teaser',
                'view' => $view,
                'teaser' => $this->teaserPresenter->present($view, $policyForTeaser, [
                    'owner_profile' => $owner,
                    'viewer_view_count' => (int) ($counts[(int) $view->viewer_profile_id] ?? 1),
                ]),
            ];
        }

        return [
            'rows' => $rows,
            'unique_count' => (int) $result['unique_count'],
            'full_count' => 0,
            'overflow_count' => (int) $result['unique_count'],
        ];
    }

    /**
     * @param  array<string, mixed>  $teaserPolicy
     * @return array{rows: list<array{display: string, view: ProfileView, teaser: ?array<string, mixed>}>, unique_count: int, full_count: int, overflow_count: int}
     */
    public function partialRows(
        MatrimonyProfile $owner,
        int $previewLimit,
        array $teaserPolicy,
        ?CarbonInterface $since = null,
        ?int $limit = null,
        ?string $targetGender = null,
    ): array {
        $listOrder = (string) ($teaserPolicy['partial_plan_list_order'] ?? 'fifo_unlocked_first');
        if (! in_array($listOrder, WhoViewedTeaserPolicy::PARTIAL_PLAN_LIST_ORDERS, true)) {
            $listOrder = 'fifo_unlocked_first';
        }

        $fifoSlotViewerIds = null;
        $result = $this->orderedUniqueViews(
            $owner,
            $since,
            $previewLimit > 0,
            max(0, $previewLimit),
            $listOrder,
            $targetGender,
            $fifoSlotViewerIds,
        );

        $orderedViews = $result['views'];
        $views = $this->limitViews($orderedViews, $limit);
        $this->eagerLoadViewerRelations($views);

        $teaserViews = $views->filter(function (ProfileView $view) use ($fifoSlotViewerIds): bool {
            $viewerId = (int) $view->viewer_profile_id;

            return ! (is_array($fifoSlotViewerIds) && (($fifoSlotViewerIds[$viewerId] ?? false) === true));
        });
        $counts = $this->viewerProfileViewCounts(
            $owner,
            $since,
            $teaserViews->map(fn (ProfileView $view): int => (int) $view->viewer_profile_id)->unique()->values()->all(),
        );
        $policyForTeaser = WhoViewedTeaserPolicy::forWhoViewedLockedTeasers($teaserPolicy);

        $rows = [];
        foreach ($views as $view) {
            $viewerId = (int) $view->viewer_profile_id;
            $isFull = is_array($fifoSlotViewerIds) && (($fifoSlotViewerIds[$viewerId] ?? false) === true);
            if ($isFull) {
                $rows[] = [
                    'display' => 'full',
                    'view' => $view,
                    'teaser' => null,
                ];

                continue;
            }

            $rows[] = [
                'display' => 'teaser',
                'view' => $view,
                'teaser' => $this->teaserPresenter->present($view, $policyForTeaser, [
                    'owner_profile' => $owner,
                    'viewer_view_count' => (int) ($counts[(int) $view->viewer_profile_id] ?? 1),
                ]),
            ];
        }

        $fullCount = count(array_filter($rows, static fn (array $row): bool => ($row['display'] ?? null) === 'full'));
        $overflowCount = $orderedViews->filter(function (ProfileView $view) use ($fifoSlotViewerIds): bool {
            $viewerId = (int) $view->viewer_profile_id;

            return ! (is_array($fifoSlotViewerIds) && (($fifoSlotViewerIds[$viewerId] ?? false) === true));
        })->count();

        return [
            'rows' => $rows,
            'unique_count' => (int) $result['unique_count'],
            'full_count' => $fullCount,
            'overflow_count' => $overflowCount,
        ];
    }

    /**
     * @return array{views: Collection<int, ProfileView>, unique_count: int}
     */
    private function orderedUniqueViews(
        MatrimonyProfile $owner,
        ?CarbonInterface $since,
        bool $fifoPreviewSlots,
        int $previewLimitForFifo,
        string $partialListOrder,
        ?string $targetGender = null,
        ?array &$fifoSlotViewerIdsOut = null,
    ): array {
        $fifoSlotViewerIdsOut = null;
        if (! Schema::hasTable('profile_views')) {
            return ['views' => collect(), 'unique_count' => 0];
        }

        $blockedIds = ViewTrackingService::getBlockedProfileIds((int) $owner->id)
            ->map(fn ($id): int => (int) $id)
            ->all();

        $query = ProfileView::query()
            ->where('viewed_profile_id', $owner->id)
            ->where('viewer_profile_id', '!=', $owner->id)
            ->with(['viewerProfile.user', 'viewerProfile.gender'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::PROFILE_VIEW_EVENTS_FETCH_CAP);

        if ($blockedIds !== []) {
            $query->whereNotIn('viewer_profile_id', $blockedIds);
        }
        if ($since !== null) {
            $query->where('created_at', '>=', $since);
        }

        $rows = $query->get()
            ->filter(fn (ProfileView $view): bool => $this->eligibleViewer($owner, $view, $targetGender));

        $perViewer = $rows->groupBy('viewer_profile_id')->map(function (Collection $group): array {
            /** @var ProfileView $latest */
            $latest = $group->sortByDesc(fn (ProfileView $view): int => $this->timestamp($view->created_at))->first();
            $firstAt = $group->min('created_at');

            return [
                'view' => $latest,
                'first_at' => $this->carbon($firstAt),
                'last_at' => $this->carbon($latest->created_at),
            ];
        });

        if (! $fifoPreviewSlots || $previewLimitForFifo < 1) {
            $ordered = $perViewer
                ->sortByDesc(fn (array $meta): int => $this->timestamp($meta['last_at'] ?? null))
                ->map(fn (array $meta): ProfileView => $meta['view'])
                ->values();

            return ['views' => $ordered, 'unique_count' => $ordered->count()];
        }

        $byFirst = $perViewer
            ->sortBy(fn (array $meta): array => [
                $this->timestamp($meta['first_at'] ?? null),
                (int) $meta['view']->viewer_profile_id,
            ])
            ->values();

        $fullViewerIds = $byFirst->take($previewLimitForFifo)
            ->map(fn (array $meta): int => (int) $meta['view']->viewer_profile_id)
            ->all();
        $fullSet = array_fill_keys($fullViewerIds, true);
        $fifoSlotViewerIdsOut = $fullSet;

        if ($partialListOrder === 'recent_activity_first') {
            $ordered = $perViewer
                ->sortByDesc(fn (array $meta): int => $this->timestamp($meta['last_at'] ?? null))
                ->map(fn (array $meta): ProfileView => $meta['view'])
                ->values();
        } else {
            $fullPart = $byFirst
                ->filter(fn (array $meta): bool => isset($fullSet[(int) $meta['view']->viewer_profile_id]))
                ->sortBy(fn (array $meta): array => [
                    $this->timestamp($meta['first_at'] ?? null),
                    (int) $meta['view']->viewer_profile_id,
                ])
                ->values();
            $teaserPart = $byFirst
                ->filter(fn (array $meta): bool => ! isset($fullSet[(int) $meta['view']->viewer_profile_id]))
                ->sortByDesc(fn (array $meta): int => $this->timestamp($meta['last_at'] ?? null))
                ->values();
            $ordered = $fullPart->concat($teaserPart)
                ->map(fn (array $meta): ProfileView => $meta['view'])
                ->values();
        }

        return ['views' => $ordered, 'unique_count' => $ordered->count()];
    }

    private function eligibleViewer(MatrimonyProfile $owner, ProfileView $view, ?string $targetGender): bool
    {
        $viewerProfile = $view->viewerProfile;
        $viewerUser = $viewerProfile?->user;
        if (! $viewerProfile instanceof MatrimonyProfile || ! $viewerUser) {
            return false;
        }
        if ((int) $viewerProfile->id === (int) $owner->id) {
            return false;
        }
        if ($viewerUser->is_admin ?? false) {
            return false;
        }
        if ($viewerUser->admin_role ?? null) {
            return false;
        }
        if ($viewerProfile->is_suspended ?? false) {
            return false;
        }
        if ($viewerProfile->isShowcaseProfile()) {
            return false;
        }
        if (! ProfileLifecycleService::isVisibleToOthers($viewerProfile)) {
            return false;
        }

        $targetGender = $this->genderString($targetGender);
        if ($targetGender !== null && $this->genderKey($viewerProfile) !== $targetGender) {
            return false;
        }

        return true;
    }

    /**
     * @param  Collection<int, ProfileView>  $views
     * @return Collection<int, ProfileView>
     */
    private function limitViews(Collection $views, ?int $limit): Collection
    {
        if ($limit === null) {
            return $views->values();
        }

        return $views->take(max(0, $limit))->values();
    }

    /**
     * @param  Collection<int, ProfileView>  $views
     */
    private function eagerLoadViewerRelations(Collection $views): void
    {
        foreach ($views as $view) {
            $view->loadMissing([
                'viewerProfile.user',
                'viewerProfile.gender',
                'viewerProfile.occupationMaster',
                'viewerProfile.occupationCustom',
                'viewerProfile.maritalStatus',
                'viewerProfile.location',
            ]);
        }
    }

    /**
     * @param  list<int>  $viewerProfileIds
     * @return array<int, int>
     */
    private function viewerProfileViewCounts(MatrimonyProfile $owner, ?CarbonInterface $since, array $viewerProfileIds): array
    {
        $viewerProfileIds = array_values(array_unique(array_filter(array_map('intval', $viewerProfileIds))));
        if ($viewerProfileIds === [] || ! Schema::hasTable('profile_views')) {
            return [];
        }

        $blockedIds = ViewTrackingService::getBlockedProfileIds((int) $owner->id)
            ->map(fn ($id): int => (int) $id)
            ->all();

        $query = ProfileView::query()
            ->where('viewed_profile_id', $owner->id)
            ->whereIn('viewer_profile_id', $viewerProfileIds);

        if ($blockedIds !== []) {
            $query->whereNotIn('viewer_profile_id', $blockedIds);
        }
        if ($since !== null) {
            $query->where('created_at', '>=', $since);
        }

        $out = [];
        foreach ($query->selectRaw('viewer_profile_id, COUNT(*) as c')->groupBy('viewer_profile_id')->get() as $row) {
            $out[(int) $row->viewer_profile_id] = (int) $row->c;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $teaserPolicy
     */
    private function maxViewerCardsFromPolicy(array $teaserPolicy): int
    {
        $count = (int) ($teaserPolicy['locked_teaser_rows'] ?? 40);

        return max(1, min(60, $count));
    }

    private function genderKey(?MatrimonyProfile $profile): ?string
    {
        if (! $profile) {
            return null;
        }
        $profile->loadMissing(['gender', 'user']);

        return $this->genderString($profile->gender?->key ?? $profile->gender?->label ?? $profile->user?->gender ?? null);
    }

    private function genderString(mixed $gender): ?string
    {
        $value = mb_strtolower(trim((string) $gender));
        if ($value === '') {
            return null;
        }
        if (str_contains($value, 'female') || str_contains($value, 'स्त्री') || str_contains($value, 'महिला')) {
            return 'female';
        }
        if (str_contains($value, 'male') || str_contains($value, 'पुरुष')) {
            return 'male';
        }

        return null;
    }

    private function carbon(mixed $value): CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        return Carbon::parse((string) $value);
    }

    private function timestamp(mixed $value): int
    {
        try {
            return $this->carbon($value)->getTimestamp();
        } catch (\Throwable) {
            return 0;
        }
    }
}
