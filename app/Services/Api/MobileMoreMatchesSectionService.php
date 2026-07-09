<?php

namespace App\Services\Api;

use App\Models\MatrimonyProfile;
use App\Models\ProfileView;
use App\Models\User;
use App\Services\FeatureUsageService;
use App\Services\Matching\MatchingService;
use App\Services\ProfileLifecycleService;
use App\Services\ProfilePreferenceMatchService;
use App\Services\WhoViewed\WhoViewedRowsService;
use App\Services\WhoViewed\WhoViewedTeaserPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class MobileMoreMatchesSectionService
{
    private const SECTION_LIMIT = 12;

    private const CANDIDATE_POOL_LIMIT = 160;

    public function __construct(
        private readonly MobileProfileDisplayPresenter $presenter,
        private readonly MobileDiscoveryFilterService $discovery,
        private readonly MatchingService $matchingService,
        private readonly FeatureUsageService $featureUsage,
        private readonly WhoViewedRowsService $whoViewedRows,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forUser(User $viewer): array
    {
        $viewer->loadMissing('matrimonyProfile.gender');
        $viewerProfile = $viewer->matrimonyProfile;
        $context = $this->viewerContext($viewerProfile, $viewer);

        if (! $viewerProfile instanceof MatrimonyProfile || ! $this->discovery->viewerCanDiscover($viewer)) {
            return [
                'success' => true,
                'viewer_context' => $context,
                'sections' => $this->emptySections($context),
            ];
        }

        $viewerProfile->loadMissing(['gender', 'preferenceCriteria', 'user']);

        return [
            'success' => true,
            'viewer_context' => $context,
            'sections' => [
                $this->lookingForMeSection($viewerProfile, $viewer, $context),
                $this->recentlyViewedSection($viewerProfile, $viewer, $context),
                $this->matchingMyPreferenceSection($viewerProfile, $viewer, $context),
                $this->nearbySection($viewerProfile, $viewer, $context),
                $this->recentVisitorsSection($viewerProfile, $viewer, $context),
                $this->youMayLikeSection($viewerProfile, $viewer, $context),
            ],
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function viewerContext(?MatrimonyProfile $viewerProfile, User $viewer): array
    {
        $viewerGender = $this->genderKey($viewerProfile);
        $targetGender = match ($viewerGender) {
            'male' => 'female',
            'female' => 'male',
            default => null,
        };

        $labels = match ($targetGender) {
            'female' => ['Bride', 'Brides', 'वधू'],
            'male' => ['Groom', 'Grooms', 'वर'],
            default => ['Profile', 'Profiles', 'स्थळे'],
        };

        return [
            'viewer_gender' => $viewerGender,
            'target_gender' => $targetGender,
            'target_singular_en' => $labels[0],
            'target_plural_en' => $labels[1],
            'target_plural_mr' => $labels[2],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<array<string, mixed>>
     */
    private function emptySections(array $context): array
    {
        return [
            $this->section('looking_for_me', $context, 'looking_for_me', collect()),
            $this->section('recently_viewed', $context, 'recently_viewed', collect()),
            $this->section('matching_my_preference', $context, 'matching_my_preference', collect()),
            $this->section('nearby', $context, 'nearby', collect()),
            $this->section('recent_visitors', $context, 'recent_visitors', collect(), [
                'locked' => true,
                'requires_upgrade' => true,
                'teaser_count' => 0,
                'teasers' => [],
                'rows' => [],
            ]),
            $this->section('you_may_like', $context, 'you_may_like', collect()),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function lookingForMeSection(MatrimonyProfile $viewerProfile, User $viewer, array $context): array
    {
        $candidates = $this->candidateQuery($viewer)
            ->with($this->cardRelations())
            ->limit(self::CANDIDATE_POOL_LIMIT)
            ->get();

        $rows = collect();
        foreach ($candidates as $candidate) {
            try {
                $fit = ProfilePreferenceMatchService::build($viewerProfile, $candidate);
            } catch (\Throwable) {
                continue;
            }

            if (($fit['target_has_preferences'] ?? false) !== true) {
                continue;
            }
            $counts = is_array($fit['counts'] ?? null) ? $fit['counts'] : [];
            if ((int) ($counts[ProfilePreferenceMatchService::STATUS_NOT_MATCHED] ?? 0) > 0) {
                continue;
            }

            $score = ((int) ($counts[ProfilePreferenceMatchService::STATUS_MATCH] ?? 0) * 3)
                + ((int) ($counts[ProfilePreferenceMatchService::STATUS_FLEXIBLE] ?? 0));

            $rows->push([
                'profile' => $candidate,
                'section_score' => $score,
            ]);
        }

        $profiles = $rows
            ->sort($this->compareRowsByPhotoThenScore(...))
            ->take(self::SECTION_LIMIT)
            ->mapWithKeys(fn (array $row): array => [(int) $row['profile']->id => $row])
            ->values();

        return $this->sectionFromRows('looking_for_me', $context, $profiles, $viewer);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function recentlyViewedSection(MatrimonyProfile $viewerProfile, User $viewer, array $context): array
    {
        if (! Schema::hasTable('profile_views')) {
            return $this->section('recently_viewed', $context, 'recently_viewed', collect());
        }

        $views = $this->distinctProfileViews('viewer_profile_id', (int) $viewerProfile->id, 'viewed_profile_id');
        $profileIds = $views->pluck('profile_id')->all();
        $profiles = $this->profilesByOrderedIds($viewer, $profileIds);

        $rows = $profiles->map(function (MatrimonyProfile $profile) use ($views): array {
            $meta = $views->firstWhere('profile_id', (int) $profile->id);

            return [
                'profile' => $profile,
                'viewed_at' => $meta['viewed_at'] ?? null,
                'viewed_at_human' => $meta['viewed_at_human'] ?? null,
            ];
        });

        return $this->sectionFromRows('recently_viewed', $context, $rows, $viewer);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function matchingMyPreferenceSection(MatrimonyProfile $viewerProfile, User $viewer, array $context): array
    {
        $rows = $this->matchingService
            ->findMatchesForTab($viewerProfile, MatchingService::TAB_PERFECT, self::SECTION_LIMIT)
            ->map(fn (array $row): array => [
                'profile' => $row['profile'],
                'section_score' => (int) ($row['score'] ?? 0),
            ]);

        return $this->sectionFromRows('matching_my_preference', $context, $rows, $viewer);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function nearbySection(MatrimonyProfile $viewerProfile, User $viewer, array $context): array
    {
        $rows = $this->matchingService
            ->findMatchesForTab($viewerProfile, MatchingService::TAB_NEAR, self::SECTION_LIMIT)
            ->map(fn (array $row): array => [
                'profile' => $row['profile'],
                'section_score' => (int) ($row['score'] ?? 0),
            ]);

        return $this->sectionFromRows('nearby', $context, $rows, $viewer);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function recentVisitorsSection(MatrimonyProfile $viewerProfile, User $viewer, array $context): array
    {
        $targetGender = $this->genderString($context['target_gender'] ?? null);
        $excludedProfileIds = $this->discovery->excludedProfileIdsForViewer($viewer);
        $teaserPolicy = WhoViewedTeaserPolicy::normalized();
        $canSee = $this->canSeeRecentVisitors($viewer);
        if (! $canSee) {
            $whoViewed = $this->whoViewedRows->lockedTeaserRows(
                $viewerProfile,
                $teaserPolicy,
                self::SECTION_LIMIT,
                $targetGender,
                $excludedProfileIds,
            );

            return $this->recentVisitorsPayload($context, [], $whoViewed, [
                'locked' => true,
                'requires_upgrade' => true,
                'teaser_count' => (int) $whoViewed['unique_count'],
                'partial_mode' => false,
                'preview_limit' => 0,
                'unique_count' => (int) $whoViewed['unique_count'],
                'overflow_count' => (int) $whoViewed['overflow_count'],
            ]);
        }

        $hasFullAccess = false;
        $previewLimit = 0;
        $previewWindow = ['since' => null, 'window_days' => null];
        try {
            $hasFullAccess = $this->featureUsage->whoViewedMeHasFullViewerList($viewer);
            $previewWindow = $this->featureUsage->whoViewedMePreviewWindow($viewer);
            $previewLimit = $hasFullAccess ? self::SECTION_LIMIT : max(0, $this->featureUsage->getWhoViewedMePreviewLimit((int) $viewer->id));
        } catch (\Throwable) {
            $hasFullAccess = false;
            $previewLimit = 0;
        }
        if (! $hasFullAccess && $previewLimit < 1) {
            $whoViewed = $this->whoViewedRows->lockedTeaserRows(
                $viewerProfile,
                $teaserPolicy,
                self::SECTION_LIMIT,
                $targetGender,
                $excludedProfileIds,
            );

            return $this->recentVisitorsPayload($context, [], $whoViewed, [
                'locked' => true,
                'requires_upgrade' => true,
                'teaser_count' => (int) $whoViewed['unique_count'],
                'partial_mode' => false,
                'preview_limit' => 0,
                'unique_count' => (int) $whoViewed['unique_count'],
                'overflow_count' => (int) $whoViewed['overflow_count'],
            ]);
        }

        $whoViewed = $hasFullAccess
            ? $this->whoViewedRows->fullRows($viewerProfile, null, self::SECTION_LIMIT, $targetGender, $excludedProfileIds)
            : $this->whoViewedRows->partialRows(
                $viewerProfile,
                $previewLimit,
                $teaserPolicy,
                $previewWindow['since'] ?? null,
                self::SECTION_LIMIT,
                $targetGender,
                $excludedProfileIds,
            );
        $profileRows = $this->profileRowsFromWhoViewedRows($viewerProfile, $viewer, $whoViewed['rows']);
        $teaserRows = $this->teasersFromWhoViewedRows($whoViewed['rows']);

        return $this->recentVisitorsPayload($context, $profileRows, $whoViewed, [
            'locked' => false,
            'requires_upgrade' => $hasFullAccess ? false : $teaserRows !== [],
            'teaser_count' => $hasFullAccess ? null : (int) $whoViewed['unique_count'],
            'partial_mode' => ! $hasFullAccess && $teaserRows !== [],
            'preview_limit' => $hasFullAccess ? null : $previewLimit,
            'unique_count' => (int) $whoViewed['unique_count'],
            'overflow_count' => $hasFullAccess ? 0 : (int) $whoViewed['overflow_count'],
            'window_days' => $previewWindow['window_days'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function youMayLikeSection(MatrimonyProfile $viewerProfile, User $viewer, array $context): array
    {
        $dateKey = now()->toDateString();
        $profiles = $this->candidateQuery($viewer)
            ->with($this->cardRelations())
            ->limit(80)
            ->get()
            ->sort(function (MatrimonyProfile $a, MatrimonyProfile $b) use ($viewerProfile, $dateKey): int {
                $photo = $this->compareProfilesByPhoto($a, $b);
                if ($photo !== 0) {
                    return $photo;
                }

                return crc32($viewerProfile->id.'|'.$dateKey.'|you_may_like|'.$a->id)
                    <=> crc32($viewerProfile->id.'|'.$dateKey.'|you_may_like|'.$b->id);
            })
            ->take(self::SECTION_LIMIT)
            ->values();

        return $this->section('you_may_like', $context, 'you_may_like', $profiles, [], $viewer);
    }

    private function canSeeRecentVisitors(User $viewer): bool
    {
        try {
            return $this->featureUsage->canUse((int) $viewer->id, FeatureUsageService::FEATURE_WHO_VIEWED_ME_ACCESS);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return Builder<MatrimonyProfile>
     */
    private function candidateQuery(User $viewer): Builder
    {
        return $this->discovery
            ->applyCandidateQuery(MatrimonyProfile::query(), $viewer)
            ->orderByDesc('updated_at');
    }

    private function compareRowsByPhotoThenScore(array $a, array $b): int
    {
        $photo = $this->compareProfilesByPhoto($a['profile'], $b['profile']);
        if ($photo !== 0) {
            return $photo;
        }

        return (int) ($b['section_score'] ?? 0) <=> (int) ($a['section_score'] ?? 0);
    }

    private function compareProfilesByPhoto(MatrimonyProfile $a, MatrimonyProfile $b): int
    {
        return $this->approvedPhotoRank($b) <=> $this->approvedPhotoRank($a);
    }

    private function approvedPhotoRank(MatrimonyProfile $profile): int
    {
        return $profile->hasApprovedPublicPhoto() ? 1 : 0;
    }

    /**
     * @return list<string>
     */
    private function cardRelations(): array
    {
        $relations = [
            'user.activeSubscription.plan',
            'gender',
            'religion',
            'caste',
            'subCaste',
            'occupationMaster',
            'occupationCustom',
            'horoscope',
        ];
        if (Schema::hasTable('profile_photos')) {
            $relations[] = 'photos';
        }

        return $relations;
    }

    /**
     * @return Collection<int, array{profile_id: int, viewed_at: string|null, viewed_at_human: string|null}>
     */
    private function distinctProfileViews(string $ownerColumn, int $ownerProfileId, string $profileColumn, ?int $limit = null): Collection
    {
        if (! Schema::hasTable('profile_views')) {
            return collect();
        }

        $seen = [];
        $out = collect();
        $rows = ProfileView::query()
            ->where($ownerColumn, $ownerProfileId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(300)
            ->get([$profileColumn, 'created_at']);

        foreach ($rows as $row) {
            $profileId = (int) $row->{$profileColumn};
            if ($profileId <= 0 || isset($seen[$profileId])) {
                continue;
            }
            $seen[$profileId] = true;
            $out->push([
                'profile_id' => $profileId,
                'viewed_at' => $row->created_at?->toIso8601String(),
                'viewed_at_human' => $row->created_at?->diffForHumans(),
            ]);
            if ($limit !== null && $out->count() >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  list<int>  $profileIds
     * @return Collection<int, MatrimonyProfile>
     */
    private function profilesByOrderedIds(User $viewer, array $profileIds): Collection
    {
        $profileIds = array_values(array_unique(array_map('intval', $profileIds)));
        if ($profileIds === []) {
            return collect();
        }

        $profiles = $this->candidateQuery($viewer)
            ->whereIn('id', $profileIds)
            ->with($this->cardRelations())
            ->get()
            ->filter(fn (MatrimonyProfile $profile): bool => ProfileLifecycleService::isVisibleToOthers($profile))
            ->keyBy(fn (MatrimonyProfile $profile): int => (int) $profile->id);

        return collect($profileIds)
            ->map(fn (int $id) => $profiles->get($id))
            ->filter()
            ->take(self::SECTION_LIMIT)
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function sectionFromRows(string $key, array $context, Collection $rows, User $viewer, array $extra = []): array
    {
        $profiles = $rows->filter(function (array $row) use ($viewer): bool {
            $profile = $row['profile'] ?? null;

            return $profile instanceof MatrimonyProfile
                && $this->discovery->isAllowedTarget($viewer, $profile);
        })->mapWithKeys(function (array $row): array {
            $profile = $row['profile'] ?? null;

            return $profile instanceof MatrimonyProfile ? [(int) $profile->id => $row] : [];
        })->values()->take(self::SECTION_LIMIT);

        $profileModels = $profiles->map(fn (array $row) => $row['profile']);

        return $this->section($key, $context, $key, $profileModels, $extra, $viewer, $profiles);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  Collection<int, MatrimonyProfile>  $profiles
     * @param  array<string, mixed>  $extra
     * @param  Collection<int, array<string, mixed>>|null  $rowMeta
     * @return array<string, mixed>
     */
    private function section(
        string $key,
        array $context,
        string $labelKey,
        Collection $profiles,
        array $extra = [],
        ?User $viewer = null,
        ?Collection $rowMeta = null,
    ): array {
        return array_merge([
            'key' => $key,
            'title_en' => $this->title($labelKey, $context, 'en'),
            'title_mr' => $this->title($labelKey, $context, 'mr'),
            'subtitle_en' => $this->subtitle($labelKey, 'en'),
            'subtitle_mr' => $this->subtitle($labelKey, 'mr'),
            'locked' => false,
            'requires_upgrade' => false,
            'profiles' => $this->profileRows($profiles, $viewer, $rowMeta),
        ], $extra);
    }

    /**
     * @param  Collection<int, MatrimonyProfile>  $profiles
     * @param  Collection<int, array<string, mixed>>|null  $rowMeta
     * @return list<array<string, mixed>>
     */
    private function profileRows(Collection $profiles, ?User $viewer, ?Collection $rowMeta = null): array
    {
        $metaById = $rowMeta?->mapWithKeys(function (array $row): array {
            $profile = $row['profile'] ?? null;

            return $profile instanceof MatrimonyProfile ? [(int) $profile->id => $row] : [];
        }) ?? collect();

        return $profiles
            ->filter(fn (MatrimonyProfile $profile): bool => ProfileLifecycleService::isVisibleToOthers($profile))
            ->filter(fn (MatrimonyProfile $profile): bool => ! ($viewer instanceof User) || $this->discovery->isAllowedTarget($viewer, $profile))
            ->unique(fn (MatrimonyProfile $profile): int => (int) $profile->id)
            ->take(self::SECTION_LIMIT)
            ->map(function (MatrimonyProfile $profile) use ($viewer, $metaById): array {
                $row = $this->profileRowPayload($profile, $viewer);
                $meta = $metaById->get((int) $profile->id, []);
                foreach (['section_score', 'viewed_at', 'viewed_at_human'] as $key) {
                    if (array_key_exists($key, $meta) && $meta[$key] !== null) {
                        $row[$key] = $meta[$key];
                    }
                }

                return $row;
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<array{display: string, view: \App\Models\ProfileView, teaser: ?array<string, mixed>}>  $whoViewedRows
     * @return list<array<string, mixed>>
     */
    private function profileRowsFromWhoViewedRows(MatrimonyProfile $viewerProfile, User $viewer, array $whoViewedRows): array
    {
        $profileIds = collect($whoViewedRows)
            ->filter(static fn (array $row): bool => ($row['display'] ?? null) === 'full')
            ->map(static fn (array $row): int => (int) $row['view']->viewer_profile_id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        $profiles = $this->profilesByOrderedIds($viewer, $profileIds)
            ->keyBy(fn (MatrimonyProfile $profile): int => (int) $profile->id);

        $rows = [];
        foreach ($whoViewedRows as $row) {
            if (($row['display'] ?? null) !== 'full') {
                continue;
            }
            $view = $row['view'];
            $profile = $profiles->get((int) $view->viewer_profile_id);
            if (! $profile instanceof MatrimonyProfile) {
                continue;
            }

            $payload = $this->profileRowPayload($profile, $viewer);
            if ($view->created_at !== null) {
                $payload['viewed_at'] = $view->created_at->toIso8601String();
                $payload['viewed_at_human'] = $view->created_at->diffForHumans();
            }

            $rows[] = $payload;
        }

        return array_values($rows);
    }

    /**
     * @return array<string, mixed>
     */
    private function profileRowPayload(MatrimonyProfile $profile, ?User $viewer): array
    {
        $display = $this->presenter->forListCard($profile, $viewer);
        $primaryPhotoUrl = $display['card']['primary_photo_url']
            ?? $display['hero']['primary_photo_url']
            ?? $display['primary_photo_url']
            ?? null;

        return [
            'id' => (int) $profile->id,
            'primary_photo_url' => $primaryPhotoUrl,
            'profile_photo_url' => $primaryPhotoUrl,
            'approved_photo_url' => $primaryPhotoUrl,
            'display' => $display,
        ];
    }

    /**
     * @param  list<array{display: string, view: \App\Models\ProfileView, teaser: ?array<string, mixed>}>  $whoViewedRows
     * @return list<array<string, mixed>>
     */
    private function teasersFromWhoViewedRows(array $whoViewedRows): array
    {
        $teasers = [];
        foreach ($whoViewedRows as $row) {
            if (($row['display'] ?? null) !== 'teaser' || ! is_array($row['teaser'] ?? null)) {
                continue;
            }
            $teasers[] = $this->safeWhoViewedTeaser($row['teaser']);
        }

        return $teasers;
    }

    /**
     * @param  list<array<string, mixed>>  $profileRows
     * @param  array{rows: list<array{display: string, view: \App\Models\ProfileView, teaser: ?array<string, mixed>}>, unique_count: int, full_count: int, overflow_count: int}  $whoViewed
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function recentVisitorsPayload(array $context, array $profileRows, array $whoViewed, array $extra): array
    {
        $profilesById = [];
        foreach ($profileRows as $profileRow) {
            $id = (int) ($profileRow['id'] ?? 0);
            if ($id > 0) {
                $profilesById[$id] = $profileRow;
            }
        }

        $mixedRows = [];
        foreach ($whoViewed['rows'] as $row) {
            if (($row['display'] ?? null) === 'full') {
                $profileId = (int) $row['view']->viewer_profile_id;
                if (isset($profilesById[$profileId])) {
                    $mixedRows[] = [
                        'mode' => 'profile',
                        'profile' => $profilesById[$profileId],
                    ];
                }

                continue;
            }

            if (($row['display'] ?? null) === 'teaser' && is_array($row['teaser'] ?? null)) {
                $mixedRows[] = [
                    'mode' => 'teaser',
                    'teaser' => $this->safeWhoViewedTeaser($row['teaser']),
                ];
            }
        }

        return $this->section('recent_visitors', $context, 'recent_visitors', collect(), array_merge([
            'profiles' => array_values($profileRows),
            'teasers' => $this->teasersFromWhoViewedRows($whoViewed['rows']),
            'rows' => $mixedRows,
        ], $extra));
    }

    /**
     * @param  array<string, mixed>  $teaser
     * @return array<string, mixed>
     */
    private function safeWhoViewedTeaser(array $teaser): array
    {
        $safe = [];
        foreach ([
            'headline',
            'lines',
            'viewed_summary',
            'photo_url',
            'avatar_style',
            'blur_photo_class',
            'accent_line',
            'match_line',
            'interest_hint',
        ] as $key) {
            $safe[$key] = $teaser[$key] ?? ($key === 'lines' ? [] : null);
        }

        if (! is_array($safe['lines'])) {
            $safe['lines'] = [];
        }
        $safe['lines'] = array_values(array_map(static fn ($line): string => (string) $line, $safe['lines']));

        return $safe;
    }

    private function title(string $key, array $context, string $locale): string
    {
        $pluralEn = (string) ($context['target_plural_en'] ?? 'Profiles');
        $pluralMr = (string) ($context['target_plural_mr'] ?? 'स्थळे');
        $targetGender = $this->genderString($context['target_gender'] ?? null);
        $nearbyEn = match ($targetGender) {
            'female' => 'Nearby Brides',
            'male' => 'Nearby Grooms',
            default => 'Nearby profiles',
        };
        $nearbyMr = match ($targetGender) {
            'female' => 'जवळच्या वधू',
            'male' => 'जवळचे वर',
            default => 'जवळची स्थळे',
        };

        return match ($key.'|'.$locale) {
            'looking_for_me|en' => $pluralEn.' looking for me',
            'looking_for_me|mr' => 'माझ्या शोधात असलेल्या '.$pluralMr,
            'recently_viewed|en' => 'Recently viewed '.$pluralEn,
            'recently_viewed|mr' => 'अलीकडे पाहिलेल्या '.$pluralMr,
            'matching_my_preference|en' => $pluralEn.' matching my preference',
            'matching_my_preference|mr' => 'माझ्या पसंतीशी जुळणाऱ्या '.$pluralMr,
            'nearby|en' => $nearbyEn,
            'nearby|mr' => $nearbyMr,
            'recent_visitors|en' => 'Recent visitors',
            'recent_visitors|mr' => 'अलीकडील भेट देणाऱ्या '.$pluralMr,
            'you_may_like|en' => $pluralEn.' you may like',
            'you_may_like|mr' => 'तुम्हाला आवडू शकणाऱ्या '.$pluralMr,
            default => $pluralEn,
        };
    }

    private function subtitle(string $key, string $locale): string
    {
        return match ($key.'|'.$locale) {
            'looking_for_me|en' => 'Profiles whose preferences may match you',
            'looking_for_me|mr' => 'ज्यांच्या पसंतीशी तुमचे स्थळ जुळू शकते',
            'recently_viewed|en' => 'Profiles you opened recently',
            'recently_viewed|mr' => 'तुम्ही अलीकडे पाहिलेली स्थळे',
            'matching_my_preference|en' => 'Profiles matching your saved preferences',
            'matching_my_preference|mr' => 'तुमच्या सेव्ह केलेल्या पसंतीशी जुळणारी स्थळे',
            'nearby|en' => 'Profiles closer to your location',
            'nearby|mr' => 'तुमच्या ठिकाणाजवळील स्थळे',
            'recent_visitors|en' => 'See who viewed your profile',
            'recent_visitors|mr' => 'तुमचे प्रोफाइल कोणी पाहिले ते पहा',
            'you_may_like|en' => 'More profiles from your active search pool',
            'you_may_like|mr' => 'तुमच्या शोधातून आणखी योग्य स्थळे',
            default => '',
        };
    }

    private function genderKey(?MatrimonyProfile $profile): ?string
    {
        if (! $profile) {
            return null;
        }

        return $this->genderString($profile->gender?->key ?? $profile->gender?->label ?? null);
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
}
