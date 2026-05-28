<?php

namespace App\Services\WhoViewed;

use App\Models\Location;
use App\Models\MatrimonyProfile;
use App\Models\ProfileView;
use App\Services\Image\ProfilePhotoUrlService;
use App\Services\Interest\ReceivedInterestTeaserPolicy;
use App\Services\Location\LocationService;
use App\Services\RuleEngineService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Builds privacy-safe teaser rows. Policy arrays come from {@see WhoViewedTeaserPolicy} (who viewed)
 * or {@see ReceivedInterestTeaserPolicy} (received interests) — same field keys where used.
 */
final class WhoViewedTeaserPresenter
{
    public function __construct(
        private LocationService $locationService,
        private ProfilePhotoUrlService $profilePhotoUrlService,
        private RuleEngineService $ruleEngine,
    ) {}

    /**
     * @param  array<string, mixed>  $policy  {@see WhoViewedTeaserPolicy::normalized()}
     * @param  array{owner_profile?: MatrimonyProfile|null, viewer_view_count?: int, teaser_time_line?: string}  $context
     * @return array{
     *   headline: string,
     *   lines: list<string>,
     *   viewed_summary: string,
     *   photo_url: ?string,
     *   avatar_style: string,
     *   blur_photo_class: string,
     *   accent_line: ?string,
     *   match_line: ?string,
     *   interest_hint: string,
     * }
     */
    public function present(ProfileView $view, array $policy, array $context = []): array
    {
        $avatarStyle = (string) ($policy['teaser_avatar_style'] ?? 'blur');
        if (! in_array($avatarStyle, WhoViewedTeaserPolicy::TEASER_AVATAR_STYLES, true)) {
            $avatarStyle = 'blur';
        }

        $blurPhotoClass = $this->blurTailwindClasses((string) ($policy['teaser_blur_strength'] ?? 'medium'));

        if ($view->viewerProfile === null) {
            return [
                'headline' => __('who_viewed.teaser_headline_anonymous'),
                'lines' => [],
                'viewed_summary' => $this->viewedSummary($view->created_at, $policy, (string) ($context['teaser_time_line'] ?? 'profile_view')),
                'photo_url' => null,
                'avatar_style' => $avatarStyle,
                'blur_photo_class' => $blurPhotoClass,
                'accent_line' => null,
                'match_line' => null,
                'interest_hint' => __('who_viewed.teaser_interest_hint_person'),
            ];
        }

        return $this->presentFromMatrimonyProfile($view->viewerProfile, $view->created_at, $policy, $context);
    }

    /**
     * Same teaser payload as {@see present} for a subject profile (who viewed viewer, or interest sender).
     *
     * @param  array<string, mixed>  $policy
     * @param  array{owner_profile?: MatrimonyProfile|null, viewer_view_count?: int, teaser_time_line?: string}  $context
     * @return array{
     *   headline: string,
     *   lines: list<string>,
     *   viewed_summary: string,
     *   photo_url: ?string,
     *   avatar_style: string,
     *   blur_photo_class: string,
     *   accent_line: ?string,
     *   match_line: ?string,
     *   interest_hint: string,
     * }
     */
    public function presentFromMatrimonyProfile(MatrimonyProfile $viewer, ?Carbon $relevantAt, array $policy, array $context = []): array
    {
        $avatarStyle = (string) ($policy['teaser_avatar_style'] ?? 'blur');
        if (! in_array($avatarStyle, WhoViewedTeaserPolicy::TEASER_AVATAR_STYLES, true)) {
            $avatarStyle = 'blur';
        }

        $blurPhotoClass = $this->blurTailwindClasses((string) ($policy['teaser_blur_strength'] ?? 'medium'));

        $viewer->loadMissing(['user', 'occupationMaster', 'maritalStatus', 'location']);

        $residenceLeaf = $this->resolveResidenceLeafLocation($viewer);
        $locationLine = $this->locationTeaserLine($viewer, (string) $policy['location_granularity'], $residenceLeaf);
        $nameMode = (string) $policy['name_display'];
        $nameLine = $this->displayNameLine($viewer, $nameMode, $policy);

        $lines = [];

        if ($nameMode === 'courtesy_from_place') {
            $who = $this->courtesyWhoPrefix($viewer);
            $place = $this->courtesyHeadlinePlace($residenceLeaf);
            if ($place !== null && $place !== '') {
                $headline = __('who_viewed.teaser_headline_courtesy_from_place', [
                    'who' => $who,
                    'place' => $place,
                ]);
            } else {
                $headline = $who;
            }
            if ($locationLine !== null && $locationLine !== '' && $locationLine !== $place) {
                $lines[] = $locationLine;
            }
        } elseif (in_array($nameMode, ['full', 'first_only', 'masked'], true)) {
            $headline = $nameLine;
            if ($locationLine !== null && $locationLine !== '') {
                $lines[] = $locationLine;
            }
        } else {
            if ($locationLine !== null && $locationLine !== '') {
                $headline = __('who_viewed.teaser_headline_anonymous_from', ['place' => $locationLine]);
            } else {
                $headline = __('who_viewed.teaser_headline_anonymous');
            }
        }

        $ageLine = $this->ageLine($viewer, (string) $policy['show_age_mode']);
        if ($ageLine !== null) {
            $lines[] = $ageLine;
        }

        if (! empty($policy['show_occupation'])) {
            $occ = $this->truncate($this->occupationLine($viewer));
            if ($occ !== '') {
                $lines[] = $occ;
            }
        }

        if (! empty($policy['show_education'])) {
            $edu = $this->truncate(trim((string) ($viewer->highest_education ?? '')));
            if ($edu !== '') {
                $lines[] = $edu;
            }
        }

        if (! empty($policy['show_marital_status'])) {
            $viewer->loadMissing(['maritalStatus']);
            $ms = trim((string) ($viewer->maritalStatus?->name ?? ''));
            if ($ms !== '') {
                $lines[] = $ms;
            }
        }

        $matchLine = $this->matchTeaserLine($viewer, $policy, $context);
        $lines = array_values(array_slice($lines, 0, 7));

        $photoUrl = null;
        if ($avatarStyle === 'blur') {
            $photoUrl = $this->viewerPhotoPublicUrl($viewer)
                ?? $this->blurAvatarFallbackPhotoUrl($viewer);
        }

        $at = $relevantAt ?? now();
        $timeLine = (string) ($context['teaser_time_line'] ?? 'profile_view');

        return [
            'headline' => $headline,
            'lines' => $lines,
            'viewed_summary' => $this->viewedSummary($at, $policy, $timeLine),
            'photo_url' => $photoUrl,
            'avatar_style' => $avatarStyle,
            'blur_photo_class' => $blurPhotoClass,
            'accent_line' => $this->repeatViewAccentLine($policy, $context),
            'match_line' => $matchLine,
            'interest_hint' => $this->interestHintLine($viewer),
        ];
    }

    /**
     * Headline place for “A girl from …”: prefer taluka, metro/city, or parent of suburb/village — not state-only.
     */
    private function courtesyHeadlinePlace(?Location $leaf): ?string
    {
        if ($leaf === null) {
            return null;
        }

        $leaf->loadMissing('parent');
        $this->locationService->ensureAncestorsLoaded($leaf);
        $h = $this->locationService->getFullHierarchy($leaf);
        $type = strtolower((string) ($leaf->type ?? ''));
        $cat = strtolower(trim((string) ($leaf->category ?? '')));

        $isMetroOrCapital = static function (?Location $loc): bool {
            if ($loc === null) {
                return false;
            }
            $c = strtolower(trim((string) ($loc->category ?? '')));

            return in_array($c, ['metro', 'capital'], true);
        };

        if ($type === 'state') {
            return null;
        }

        if (in_array($type, ['suburb', 'village'], true) || $cat === 'suburban') {
            $walk = $leaf->parent;
            $guard = 0;
            while ($walk !== null && $guard < 8) {
                $walk->loadMissing('parent');
                $wt = strtolower((string) ($walk->type ?? ''));
                if (in_array($wt, ['city', 'taluka', 'district'], true)) {
                    return $this->formatHeadlinePlaceByType($walk);
                }
                $walk = $walk->parent;
                $guard++;
            }
        }

        $cityNode = $h['city'];
        if ($cityNode !== null && $isMetroOrCapital($cityNode)) {
            return $cityNode->localizedName();
        }

        if ($h['taluka'] !== null) {
            return $this->formatHeadlinePlaceByType($h['taluka']);
        }

        if ($type === 'city') {
            return $leaf->localizedName();
        }

        if ($cityNode !== null) {
            return $cityNode->localizedName();
        }

        if ($isMetroOrCapital($leaf)) {
            return $leaf->localizedName();
        }

        if ($h['district'] !== null) {
            return $h['district']->localizedName();
        }

        if ($type === 'district') {
            return $leaf->localizedName();
        }

        return null;
    }

    private function formatHeadlinePlaceByType(Location $location): string
    {
        $label = trim((string) $location->localizedName());
        if ($label === '') {
            return $label;
        }

        $type = strtolower((string) ($location->type ?? ''));
        if ($type !== 'taluka') {
            return $label;
        }

        $suffix = trim((string) __('who_viewed.teaser_taluka_suffix'));
        if ($suffix === '') {
            return $label;
        }

        if (preg_match('/\b'.preg_quote($suffix, '/').'\b/iu', $label) === 1) {
            return $label;
        }

        return $label.' '.$suffix;
    }

    private function repeatViewAccentLine(array $policy, array $context): ?string
    {
        if (empty($policy['show_repeat_view_teaser'])) {
            return null;
        }
        $viewCount = max(1, (int) ($context['viewer_view_count'] ?? 1));
        if ($viewCount <= 1) {
            return null;
        }

        return __('who_viewed.teaser_viewed_profile_times', ['count' => $viewCount]);
    }

    /**
     * Strong-match hint when admin enables {@code show_match_teaser} (shown in accent color, not in gray lines).
     *
     * @param  array<string, mixed>  $policy
     * @param  array{owner_profile?: MatrimonyProfile|null, viewer_view_count?: int, teaser_time_line?: string}  $context
     */
    private function matchTeaserLine(MatrimonyProfile $viewer, array $policy, array $context): ?string
    {
        if (empty($policy['show_match_teaser'])) {
            return null;
        }
        $owner = $context['owner_profile'] ?? null;
        if (! $owner instanceof MatrimonyProfile) {
            return null;
        }
        $min = (int) ($policy['match_teaser_min_score'] ?? 75);
        $payload = $this->ruleEngine->getMatchResultForProfiles($owner, $viewer);
        $score = (int) ($payload['score'] ?? 0);
        if ($score < $min) {
            return null;
        }

        return __('who_viewed.teaser_match_score', ['score' => $score]);
    }

    /**
     * Marital + gender: never_married → girl/boy, otherwise woman/man.
     */
    private function courtesyWhoPrefix(MatrimonyProfile $viewer): string
    {
        $viewer->loadMissing(['maritalStatus', 'gender']);
        $mKey = strtolower(trim((string) ($viewer->maritalStatus?->key ?? '')));
        $gKey = strtolower(trim((string) ($viewer->gender?->key ?? '')));
        $never = ($mKey === 'never_married');
        if ($gKey === 'male') {
            return $never ? __('who_viewed.courtesy_a_boy') : __('who_viewed.courtesy_a_man');
        }

        return $never ? __('who_viewed.courtesy_a_girl') : __('who_viewed.courtesy_a_woman');
    }

    private function interestHintLine(MatrimonyProfile $viewer): string
    {
        $viewer->loadMissing(['maritalStatus', 'gender']);
        $mKey = strtolower(trim((string) ($viewer->maritalStatus?->key ?? '')));
        $gKey = strtolower(trim((string) ($viewer->gender?->key ?? '')));
        $never = ($mKey === 'never_married');

        if ($gKey === 'female') {
            return $never
                ? __('who_viewed.teaser_interest_hint_girl')
                : __('who_viewed.teaser_interest_hint_woman');
        }

        if ($gKey === 'male') {
            return $never
                ? __('who_viewed.teaser_interest_hint_boy')
                : __('who_viewed.teaser_interest_hint_man');
        }

        return __('who_viewed.teaser_interest_hint_person');
    }

    private function blurTailwindClasses(string $strength): string
    {
        return match ($strength) {
            'light' => 'blur-sm scale-105 opacity-95',
            'soft' => 'blur-[3px] scale-105 opacity-95',
            'gentle' => 'blur-[6px] scale-110 opacity-93',
            'medium' => 'blur-md scale-110 opacity-90',
            'strong' => 'blur-2xl scale-125 opacity-[0.88]',
            default => 'blur-md scale-110 opacity-90',
        };
    }

    private function viewerPhotoPublicUrl(MatrimonyProfile $viewer): ?string
    {
        $path = $viewer->profile_photo ?? null;
        if ($path === null || $path === '') {
            return null;
        }
        if ($viewer->photo_approved === false) {
            return null;
        }

        return $this->profilePhotoUrlService->publicUrl((string) $path);
    }

    /**
     * When blur style is on but there is no approved photo URL, still show a visible (blurred) image so cards never render an empty photo slot.
     */
    private function blurAvatarFallbackPhotoUrl(MatrimonyProfile $viewer): string
    {
        $viewer->loadMissing(['gender']);
        $gk = strtolower(trim((string) ($viewer->gender?->key ?? '')));
        if ($gk === 'male') {
            return asset('images/placeholders/male-profile.svg');
        }
        if ($gk === 'female') {
            return asset('images/placeholders/female-profile.svg');
        }

        return asset('images/placeholders/default-profile.svg');
    }

    /**
     * @param  array<string, mixed>  $policy
     */
    private function displayNameLine(MatrimonyProfile $viewer, string $mode, array $policy): string
    {
        $full = trim((string) ($viewer->full_name ?? ''));
        $userName = trim((string) ($viewer->user?->name ?? ''));

        return match ($mode) {
            'full' => $full !== '' ? $full : ($userName !== '' ? $userName : __('who_viewed.teaser_name_fallback')),
            'first_only' => $this->firstToken($full) ?? $this->firstToken($userName) ?? __('who_viewed.teaser_name_fallback'),
            'masked' => $this->maskedNameLine($full, $userName, (int) ($policy['masked_name_dots'] ?? 5)),
            'courtesy_from_place' => '',
            default => __('who_viewed.teaser_name_fallback'),
        };
    }

    private function maskedNameLine(string $full, string $userName, int $dots): string
    {
        $source = $full !== '' ? $full : $userName;
        if ($source === '') {
            return __('who_viewed.teaser_name_masked_fallback');
        }
        $first = mb_substr($source, 0, 1, 'UTF-8');
        $first = trim($first);
        if ($first === '') {
            return __('who_viewed.teaser_name_masked_fallback');
        }
        $dots = max(3, min(10, $dots));

        return $first.str_repeat('•', $dots);
    }

    private function firstToken(?string $s): ?string
    {
        if ($s === null || $s === '') {
            return null;
        }
        $parts = preg_split('/\s+/u', $s, 2, PREG_SPLIT_NO_EMPTY);

        return isset($parts[0]) && $parts[0] !== '' ? $parts[0] : null;
    }

    private function occupationLine(MatrimonyProfile $viewer): string
    {
        $viewer->loadMissing(['occupationMaster', 'occupationCustom']);
        $t = trim((string) ($viewer->occupation_title ?? ''));

        return $t;
    }

    private function ageYearsInt(MatrimonyProfile $viewer): ?int
    {
        $dob = $viewer->date_of_birth ?? null;
        if ($dob === null || $dob === '') {
            return null;
        }
        try {
            $born = $dob instanceof Carbon
                ? $dob->copy()->startOfDay()
                : Carbon::parse((string) $dob)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
        $years = (int) $born->diff(now()->startOfDay())->y;

        return $years < 1 ? null : $years;
    }

    private function ageLine(MatrimonyProfile $viewer, string $mode): ?string
    {
        if ($mode === 'off') {
            return null;
        }
        $years = $this->ageYearsInt($viewer);
        if ($years === null) {
            return null;
        }

        if ($mode === 'exact') {
            return __('who_viewed.teaser_age_years', ['age' => $years]);
        }

        $decadeStart = (int) (floor($years / 10) * 10);
        $decadeStart = max(18, $decadeStart);
        $decadeEnd = $decadeStart + 9;

        return __('who_viewed.teaser_age_decade', ['from' => $decadeStart, 'to' => $decadeEnd]);
    }

    /**
     * @param  array<string, mixed>  $policy
     * @param  string  $timeLine  {@code profile_view} (default) or {@code interest_received}
     */
    private function viewedSummary(?Carbon $at, array $policy, string $timeLine = 'profile_view'): string
    {
        $mode = (string) ($policy['teaser_viewed_time'] ?? 'human');
        $interest = $timeLine === 'interest_received';

        if ($mode === 'human' && $at !== null) {
            return $interest
                ? __('interests.teaser_interest_at', ['time' => $at->diffForHumans()])
                : __('who_viewed.viewed_at', ['time' => $at->diffForHumans()]);
        }

        if ($at === null) {
            return $interest
                ? __('interests.teaser_interest_earlier')
                : __('who_viewed.teaser_viewed_earlier');
        }
        $now = now();
        if ($at >= $now->copy()->subDay()) {
            return $interest
                ? __('interests.teaser_interest_recent')
                : __('who_viewed.teaser_viewed_recent');
        }
        if ($at >= $now->copy()->subDays(7)) {
            return $interest
                ? __('interests.teaser_interest_this_week')
                : __('who_viewed.teaser_viewed_this_week');
        }
        if ($at >= $now->copy()->subDays(30)) {
            return $interest
                ? __('interests.teaser_interest_this_month')
                : __('who_viewed.teaser_viewed_this_month');
        }

        return $interest
            ? __('interests.teaser_interest_earlier')
            : __('who_viewed.teaser_viewed_earlier');
    }

    private function locationTeaserLine(MatrimonyProfile $viewer, string $granularity, ?Location $leafPre = null): ?string
    {
        if (! Schema::hasTable(Location::geoTable())) {
            return null;
        }

        $leaf = $leafPre ?? $this->resolveResidenceLeafLocation($viewer);
        if ($leaf === null) {
            return null;
        }

        $this->locationService->ensureAncestorsLoaded($leaf);
        $h = $this->locationService->getFullHierarchy($leaf);

        return match ($granularity) {
            'state_only' => $h['state']?->localizedName(),
            'district_and_above' => $this->joinParts(array_filter([
                $h['district']?->localizedName(),
                $h['state']?->localizedName(),
            ])),
            'taluka_and_above' => $this->joinParts(array_filter([
                $h['taluka']?->localizedName(),
                $h['district']?->localizedName(),
                $h['state']?->localizedName(),
            ])),
            default => $this->joinParts(array_filter([
                $h['district']?->localizedName(),
                $h['state']?->localizedName(),
            ])),
        };
    }

    /**
     * Prefer {@see MatrimonyProfile::$location_id}; if unset, canonical self + current row in {@code profile_addresses}.
     */
    private function resolveResidenceLeafLocation(MatrimonyProfile $viewer): ?Location
    {
        $lid = $viewer->location_id ?? null;
        if ($lid !== null && (int) $lid > 0) {
            if ($viewer->relationLoaded('location') && $viewer->location instanceof Location) {
                return $viewer->location;
            }

            return Location::query()->find((int) $lid);
        }

        $fromAddresses = ProfileCanonicalResidenceService::locationLeafId((int) $viewer->id);
        if ($fromAddresses !== null && $fromAddresses > 0) {
            return Location::query()->find($fromAddresses);
        }

        return null;
    }

    /**
     * @param  list<string|null>  $parts
     */
    private function joinParts(array $parts): ?string
    {
        $clean = [];
        foreach ($parts as $p) {
            $p = trim((string) $p);
            if ($p !== '' && ! in_array($p, $clean, true)) {
                $clean[] = $p;
            }
        }

        if ($clean === []) {
            return null;
        }

        return implode(' / ', $clean);
    }

    private function truncate(string $s, int $max = 48): string
    {
        $s = trim($s);
        if (mb_strlen($s) <= $max) {
            return $s;
        }

        return rtrim(mb_substr($s, 0, $max - 1)).'…';
    }
}
