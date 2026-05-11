<?php

namespace App\Services\WhoViewed;

use App\Models\Location;
use App\Models\MatrimonyProfile;
use App\Models\ProfileView;
use App\Services\Image\ProfilePhotoUrlService;
use App\Services\Location\LocationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Builds privacy-safe teaser rows for locked "who viewed me" (no profile links; time granularity is admin-tunable).
 */
final class WhoViewedTeaserPresenter
{
    public function __construct(
        private LocationService $locationService,
        private ProfilePhotoUrlService $profilePhotoUrlService,
    ) {}

    /**
     * @param  array<string, mixed>  $policy  {@see WhoViewedTeaserPolicy::normalized()}
     * @return array{headline: string, lines: list<string>, viewed_summary: string, photo_url: ?string, avatar_style: string}
     */
    public function present(ProfileView $view, array $policy): array
    {
        $viewer = $view->viewerProfile;
        $avatarStyle = (string) ($policy['teaser_avatar_style'] ?? 'blur');
        if (! in_array($avatarStyle, WhoViewedTeaserPolicy::TEASER_AVATAR_STYLES, true)) {
            $avatarStyle = 'blur';
        }

        if ($viewer === null) {
            return [
                'headline' => __('who_viewed.teaser_headline_anonymous'),
                'lines' => [],
                'viewed_summary' => $this->viewedSummary($view->created_at, $policy),
                'photo_url' => null,
                'avatar_style' => $avatarStyle,
            ];
        }

        $viewer->loadMissing(['user', 'occupationMaster', 'maritalStatus', 'location']);

        $locationLine = $this->locationTeaserLine($viewer, (string) $policy['location_granularity']);
        $nameLine = $this->displayNameLine($viewer, (string) $policy['name_display']);

        $lines = [];

        if ($policy['name_display'] !== 'hidden') {
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

        $photoUrl = null;
        if ($avatarStyle === 'blur') {
            $photoUrl = $this->viewerPhotoPublicUrl($viewer);
        }

        return [
            'headline' => $headline,
            'lines' => array_values(array_slice($lines, 0, 5)),
            'viewed_summary' => $this->viewedSummary($view->created_at, $policy),
            'photo_url' => $photoUrl,
            'avatar_style' => $avatarStyle,
        ];
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

    private function displayNameLine(MatrimonyProfile $viewer, string $mode): string
    {
        $full = trim((string) ($viewer->full_name ?? ''));
        $userName = trim((string) ($viewer->user?->name ?? ''));

        return match ($mode) {
            'full' => $full !== '' ? $full : ($userName !== '' ? $userName : __('who_viewed.teaser_name_fallback')),
            'first_only' => $this->firstToken($full) ?? $this->firstToken($userName) ?? __('who_viewed.teaser_name_fallback'),
            default => __('who_viewed.teaser_name_fallback'),
        };
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

    private function ageLine(MatrimonyProfile $viewer, string $mode): ?string
    {
        if ($mode === 'off') {
            return null;
        }
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
        $age = $born->diffInYears(now()->startOfDay());
        if ($age < 1) {
            return null;
        }

        if ($mode === 'exact') {
            return __('who_viewed.teaser_age_exact', ['age' => $age]);
        }

        $decadeStart = (int) (floor($age / 10) * 10);
        $decadeStart = max(18, $decadeStart);

        return __('who_viewed.teaser_age_decade', ['from' => $decadeStart, 'to' => $decadeStart + 9]);
    }

    /**
     * @param  array<string, mixed>  $policy
     */
    private function viewedSummary(?Carbon $at, array $policy): string
    {
        $mode = (string) ($policy['teaser_viewed_time'] ?? 'human');
        if ($mode === 'human' && $at !== null) {
            return __('who_viewed.viewed_at', ['time' => $at->diffForHumans()]);
        }

        if ($at === null) {
            return __('who_viewed.teaser_viewed_earlier');
        }
        $now = now();
        if ($at >= $now->copy()->subDay()) {
            return __('who_viewed.teaser_viewed_recent');
        }
        if ($at >= $now->copy()->subDays(7)) {
            return __('who_viewed.teaser_viewed_this_week');
        }
        if ($at >= $now->copy()->subDays(30)) {
            return __('who_viewed.teaser_viewed_this_month');
        }

        return __('who_viewed.teaser_viewed_earlier');
    }

    private function locationTeaserLine(MatrimonyProfile $viewer, string $granularity): ?string
    {
        if (! Schema::hasColumn($viewer->getTable(), 'location_id')) {
            return null;
        }
        $lid = $viewer->location_id ?? null;
        if (! $lid) {
            return null;
        }

        $leaf = $viewer->relationLoaded('location') && $viewer->location
            ? $viewer->location
            : Location::query()->find((int) $lid);
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
