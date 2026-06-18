<?php

namespace App\Services\Api;

use App\Models\Block;
use App\Models\HiddenProfile;
use App\Models\Interest;
use App\Models\MatrimonyProfile;
use App\Models\Plan;
use App\Models\ProfilePhoto;
use App\Models\Shortlist;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Image\ProfilePhotoUrlService;
use App\Services\IncomeEngineService;
use App\Services\ProfilePreferenceMatchService;
use App\Services\ProfileLifecycleService;
use App\Services\SiteIdentityService;
use App\Services\ViewTrackingService;
use App\Support\HeightDisplay;
use App\Support\ProfileDisplayCopy;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MobileProfileDisplayPresenter
{
    private const LABEL_KEYS = [
        'label_mr',
        'display_label',
        'label',
        'label_en',
        'name_mr',
        'name',
        'title',
        'code_mr',
        'code',
        'full_form',
        'raw_name',
        'key',
    ];

    public function forProfile(MatrimonyProfile $profile, ?User $viewer = null): array
    {
        $profile->loadMissing([
            'user',
            'gender',
            'religion',
            'caste',
            'subCaste',
            'maritalStatus',
            'motherTongue',
            'diet',
            'familyType',
            'incomeCurrency',
            'familyIncomeCurrency',
            'occupationMaster',
            'occupationCustom',
            'fatherOccupationMaster',
            'fatherOccupationCustom',
            'motherOccupationMaster',
            'motherOccupationCustom',
            'siblings',
            'children',
            'horoscope.rashi',
            'horoscope.nakshatra',
            'horoscope.mangalDoshType',
            'preferenceCriteria.preferredMaritalStatus',
            'preferenceCriteria.settledCity',
            'preferenceCriteria.marriageTypePreference',
            'preferredReligions',
            'preferredCastes',
            'preferredEducationDegrees',
            'preferredOccupationMasters',
        ]);

        $viewerProfile = $viewer?->matrimonyProfile;
        $isOwnProfile = $viewerProfile !== null && (int) $viewerProfile->id === (int) $profile->id;
        $age = $this->age($profile);
        $ageLabel = $age !== null ? $age.' years' : null;
        $heightLabel = $this->heightLabel($profile);
        $communityLabel = $this->communityLabel($profile);
        $occupationLabel = $this->occupationLabel($profile);
        $locationLabel = $this->cleanLocation(ProfileDisplayCopy::profileResidenceDisplayLine($profile));
        [$photoCount, $primaryPhotoUrl] = $this->visiblePhotoSummary($profile);
        $comparison = $this->comparisonPayload($profile, $viewerProfile);

        $sections = array_values(array_filter([
            $this->basicSection($profile, $isOwnProfile, $ageLabel, $heightLabel, $communityLabel, $locationLabel),
            $this->familySection($profile),
            $this->careerEducationSection($profile, $isOwnProfile),
            $this->astroSection($profile, $isOwnProfile),
            $this->comparisonSection($comparison),
            $this->partnerPreferenceSection($profile),
        ]));

        return [
            'version' => 1,
            'hero' => [
                'name' => $this->cleanString($profile->full_name),
                'age' => $age,
                'age_label' => $ageLabel,
                'height_label' => $heightLabel,
                'community_label' => $communityLabel,
                'occupation_label' => $occupationLabel,
                'location_label' => $locationLabel,
                'verified' => $this->isVerified($profile),
                'premium' => $this->isPremium($profile),
                'photo_count' => $photoCount,
                'primary_photo_url' => $primaryPhotoUrl,
            ],
            'about' => $this->about($profile),
            'chips' => $this->chips($profile, $viewerProfile, $photoCount),
            'sections' => $sections,
            'actions' => $this->actions($profile, $viewerProfile),
            'share' => $this->sharePayload($profile, $age, $communityLabel, $occupationLabel, $locationLabel),
            'comparison' => $comparison,
        ];
    }

    private function comparisonPayload(MatrimonyProfile $profile, ?MatrimonyProfile $viewerProfile): ?array
    {
        if ($viewerProfile === null || (int) $viewerProfile->id === (int) $profile->id) {
            return null;
        }

        try {
            $raw = ProfilePreferenceMatchService::build($viewerProfile, $profile);
        } catch (\Throwable) {
            return null;
        }

        if (($raw['target_has_preferences'] ?? false) !== true) {
            return null;
        }

        $items = [];
        foreach (($raw['rows'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $item = $this->comparisonItem($row, $viewerProfile);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        if ($items === []) {
            return null;
        }

        $matchedCount = count(array_filter($items, fn (array $item): bool => $item['matched'] === true));
        $totalCount = count(array_filter($items, fn (array $item): bool => $item['matched'] !== null));

        return [
            'title' => $this->comparisonLabel($profile),
            'summary' => $totalCount > 0 ? 'You match '.$matchedCount.'/'.$totalCount.' preferences' : null,
            'matched_count' => $matchedCount,
            'total_count' => $totalCount,
            'items' => $items,
        ];
    }

    private function comparisonItem(array $row, MatrimonyProfile $viewerProfile): ?array
    {
        $key = $this->cleanString($row['id'] ?? null);
        $label = $this->cleanString($row['label'] ?? null);
        $targetPreference = $this->cleanComparisonString($row['their_preference'] ?? null);
        $viewerValue = $this->cleanComparisonString($row['your_value'] ?? null);
        $status = $this->cleanString($row['status'] ?? null);

        if ($key === null || $label === null || $targetPreference === null || $viewerValue === null) {
            return null;
        }
        if ($this->isOpenComparisonPreference($targetPreference) || $this->isUnknownComparisonValue($viewerValue)) {
            return null;
        }
        if ($key === 'income' && (bool) ($viewerProfile->income_private ?? false)) {
            return null;
        }

        return [
            'key' => $key,
            'label' => $label,
            'target_preference' => $this->normalizeComparisonValue($key, $targetPreference),
            'viewer_value' => $this->normalizeComparisonValue($key, $viewerValue),
            'matched' => $this->comparisonMatched($status),
        ];
    }

    private function comparisonMatched(?string $status): ?bool
    {
        return match ($status) {
            ProfilePreferenceMatchService::STATUS_MATCH => true,
            ProfilePreferenceMatchService::STATUS_NOT_MATCHED => false,
            default => null,
        };
    }

    private function cleanComparisonString(mixed $value): ?string
    {
        $text = $this->cleanString($value);
        if ($text === null) {
            return null;
        }

        $text = preg_replace('/\s+/u', ' ', $text);
        $text = is_string($text) ? trim($text) : null;
        if ($text === null || $text === '' || $text === '—' || $text === '-') {
            return null;
        }
        if (mb_strlen($text) > 140) {
            $text = rtrim(mb_substr($text, 0, 137)).'...';
        }

        return $text;
    }

    private function isOpenComparisonPreference(string $value): bool
    {
        $normalized = mb_strtolower(trim($value));

        return $normalized === ''
            || str_contains($normalized, 'open to all')
            || str_contains($normalized, 'no preference')
            || str_contains($normalized, 'preference_match.open_to_all')
            || str_contains($normalized, 'preference_match.no_preference_set');
    }

    private function isUnknownComparisonValue(string $value): bool
    {
        $normalized = mb_strtolower(trim($value));

        return $normalized === ''
            || str_contains($normalized, 'unknown')
            || str_contains($normalized, 'not specified')
            || str_contains($normalized, 'value_unknown')
            || str_contains($normalized, 'preference_match.value_unknown');
    }

    private function normalizeComparisonValue(string $key, string $value): string
    {
        if ($key === 'age') {
            if (preg_match('/^\d+$/', $value) === 1) {
                return $value.' years';
            }

            return str_replace(' – ', ' to ', $value).(str_contains($value, 'year') ? '' : ' years');
        }

        return $value;
    }

    private function comparisonSection(?array $comparison): ?array
    {
        if ($comparison === null || empty($comparison['items']) || ! is_array($comparison['items'])) {
            return null;
        }

        $items = [];
        foreach ($comparison['items'] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $label = $this->cleanString($item['label'] ?? null);
            $viewerValue = $this->cleanString($item['viewer_value'] ?? null);
            if ($label === null || $viewerValue === null) {
                continue;
            }

            $matched = $item['matched'] ?? null;
            $status = $matched === true ? 'Match' : ($matched === false ? 'Not matched' : 'Review');
            $items[] = $this->item(
                $label,
                $viewerValue.' — '.$status,
                $this->comparisonIcon($this->cleanString($item['key'] ?? null))
            );
        }

        return $this->section('partner_match', (string) ($comparison['title'] ?? 'You & Profile'), $items);
    }

    private function comparisonIcon(?string $key): string
    {
        return match ($key) {
            'age' => 'age',
            'height' => 'height',
            'religion', 'caste' => 'community',
            'location' => 'location',
            'education' => 'education',
            'profession' => 'work',
            'income' => 'income',
            'marital_status' => 'heart',
            'diet' => 'diet',
            default => 'compare',
        };
    }

    private function sharePayload(
        MatrimonyProfile $profile,
        ?int $age,
        ?string $communityLabel,
        ?string $occupationLabel,
        ?string $locationLabel
    ): array {
        $siteName = trim((string) app(SiteIdentityService::class)->get('site_name_en', 'Navri Mile Navryala'));
        if ($siteName === '') {
            $siteName = 'Navri Mile Navryala';
        }

        $name = $this->cleanString($profile->full_name) ?? 'Profile';
        $title = $age !== null
            ? $name.', '.$age.' - '.$siteName
            : $name.' - '.$siteName;
        $description = $this->joinLabels([
            $communityLabel,
            $occupationLabel,
            $locationLabel,
        ], ' • ') ?? 'View this profile on '.$siteName.'.';
        $url = route('profile.share.public', ['id' => $profile->id]);

        return [
            'url' => $url,
            'title' => $title,
            'text' => trim($title."\n".$description."\n".$url),
        ];
    }

    private function basicSection(
        MatrimonyProfile $profile,
        bool $isOwnProfile,
        ?string $ageLabel,
        ?string $heightLabel,
        ?string $communityLabel,
        ?string $locationLabel
    ): ?array {
        $items = [
            $this->item('Profile ID', (string) $profile->id, 'id'),
            $this->item('Age', $ageLabel, 'age'),
            $this->item('Height', $heightLabel, 'height'),
            $isOwnProfile ? $this->item('Birth Date', $this->dateLabel($profile->date_of_birth), 'calendar') : null,
            $this->item('Marital Status', $this->labelFrom($profile->maritalStatus), 'heart'),
            $this->item('Lives in', $locationLabel, 'location'),
            $this->item('Religion', $this->labelFrom($profile->religion), 'community'),
            $this->item('Mother Tongue', $this->labelFrom($profile->motherTongue), 'language'),
            $this->item('Community', $communityLabel, 'community'),
            $this->item('Diet', $this->labelFrom($profile->diet), 'diet'),
        ];

        return $this->section('basic', 'Basic Details', $items);
    }

    private function familySection(MatrimonyProfile $profile): ?array
    {
        $items = [
            $this->item('Family Type', $this->labelFrom($profile->familyType), 'family'),
            $this->item('Parents Details', $this->parentsDetails($profile), 'parents'),
            $this->item('Siblings', $this->siblingsLabel($profile), 'siblings'),
            $this->familyIncomeItem($profile),
            $this->item('Property Details', $profile->property_details, 'property'),
            $this->item('Other Relatives', $profile->other_relatives_text, 'relatives'),
        ];

        return $this->section('family', 'Family Details', $items);
    }

    private function careerEducationSection(MatrimonyProfile $profile, bool $isOwnProfile): ?array
    {
        $items = [
            $this->item('Highest Education', $profile->highest_education, 'education'),
            $this->item('Occupation', $this->occupationLabel($profile), 'work'),
            $isOwnProfile ? $this->item('Company Name', $this->companyLabel($profile), 'company') : null,
            $this->item('Work Location', $this->cleanLocation($profile->workLocationDisplayLine()), 'location'),
            $this->incomeItem($profile),
        ];

        return $this->section('career_education', 'Career & Education', $items);
    }

    private function astroSection(MatrimonyProfile $profile, bool $isOwnProfile): ?array
    {
        $horoscope = $profile->horoscope;
        $items = [
            $this->item('Rashi', $this->labelFrom($horoscope?->rashi), 'astro'),
            $this->item('Nakshatra', $this->labelFrom($horoscope?->nakshatra), 'astro'),
            $this->item('Mangal Dosh', $this->labelFrom($horoscope?->mangalDoshType), 'astro'),
            $this->item('Gotra', $horoscope?->gotra, 'astro'),
            $this->item('Devak', $horoscope?->devak, 'astro'),
            $isOwnProfile ? $this->item('Birth Time', $profile->birth_time, 'time') : null,
            $isOwnProfile ? $this->item('Birth Place', $this->cleanLocation($profile->birthLocationDisplayLine()), 'location') : null,
        ];

        return $this->section('astro', 'Astro / Gunamilan', $items);
    }

    private function partnerPreferenceSection(MatrimonyProfile $profile): ?array
    {
        $criteria = $profile->preferenceCriteria;
        if ($criteria === null) {
            $items = [
                $this->item('Preferred Religions', $this->collectionLabels($profile->preferredReligions), 'community'),
                $this->item('Preferred Castes', $this->collectionLabels($profile->preferredCastes), 'community'),
                $this->item('Preferred Education', $this->collectionLabels($profile->preferredEducationDegrees), 'education'),
                $this->item('Preferred Occupation', $this->collectionLabels($profile->preferredOccupationMasters), 'work'),
            ];

            return $this->section('partner_preferences', 'Partner Preferences', $items);
        }

        $items = [
            $this->item('Age Preference', $this->rangeLabel($criteria->preferred_age_min, $criteria->preferred_age_max, ' years'), 'age'),
            $this->item('Height Preference', $this->heightRangeLabel($criteria->preferred_height_min_cm, $criteria->preferred_height_max_cm), 'height'),
            $this->item('Preferred Religions', $this->collectionLabels($profile->preferredReligions), 'community'),
            $this->item('Preferred Castes', $this->collectionLabels($profile->preferredCastes), 'community'),
            $this->item('Preferred Education', $this->collectionLabels($profile->preferredEducationDegrees) ?? $criteria->preferred_education, 'education'),
            $this->item('Preferred Occupation', $this->collectionLabels($profile->preferredOccupationMasters), 'work'),
            $this->item('Preferred City', $this->labelFrom($criteria->settledCity), 'location'),
            $this->item('Preferred Marital Status', $this->labelFrom($criteria->preferredMaritalStatus), 'heart'),
            $this->item('Marriage Type Preference', $this->labelFrom($criteria->marriageTypePreference), 'heart'),
            $this->item('Willing to Relocate', $criteria->willing_to_relocate === null ? null : ($criteria->willing_to_relocate ? 'Yes' : 'No'), 'location'),
            $this->item('Profile Managed By', $this->managedByLabel($criteria->preferred_profile_managed_by), 'profile'),
            $this->item('Partner with Children', $this->withChildrenLabel($criteria->partner_profile_with_children), 'family'),
            $this->item('Income Preference', $this->rangeLabel($criteria->preferred_income_min, $criteria->preferred_income_max), 'income'),
        ];

        return $this->section('partner_preferences', 'Partner Preferences', $items);
    }

    private function about(MatrimonyProfile $profile): array
    {
        $body = $this->aboutBody($profile) ?? ProfileDisplayCopy::introSentence($profile);
        $body = $this->cleanString($body);
        $name = $this->cleanString($profile->full_name);

        return [
            'title' => $name !== null ? 'About '.$name : null,
            'body' => $body,
        ];
    }

    private function chips(MatrimonyProfile $profile, ?MatrimonyProfile $viewerProfile, int $photoCount): array
    {
        $chips = [];
        if ($this->isVerified($profile)) {
            $chips[] = ['label' => 'Verified', 'icon' => 'verified', 'tone' => 'trust'];
        }
        if ($this->isPremium($profile)) {
            $chips[] = ['label' => 'Premium', 'icon' => 'premium', 'tone' => 'premium'];
        }
        if ($photoCount > 0) {
            $chips[] = ['label' => $photoCount.' photo'.($photoCount === 1 ? '' : 's'), 'icon' => 'photo', 'tone' => 'neutral'];
        }
        if ($viewerProfile !== null && (int) $viewerProfile->id !== (int) $profile->id) {
            $chips[] = ['label' => $this->comparisonLabel($profile), 'icon' => 'compare', 'tone' => 'dark'];
        }
        if ($profile->horoscope !== null) {
            $chips[] = ['label' => 'Astro', 'icon' => 'astro', 'tone' => 'warm'];
        }

        return $chips;
    }

    private function actions(MatrimonyProfile $profile, ?MatrimonyProfile $viewerProfile): array
    {
        $canInteract = $viewerProfile !== null && (int) $viewerProfile->id !== (int) $profile->id;
        $hasInterests = Schema::hasTable('interests');
        $hasShortlists = Schema::hasTable('shortlists');
        $hasHiddenProfiles = Schema::hasTable('hidden_profiles');
        $hasBlocks = Schema::hasTable('blocks');

        $alreadyInterested = false;
        if ($canInteract && $hasInterests) {
            $alreadyInterested = Interest::query()
                ->where('sender_profile_id', $viewerProfile->id)
                ->where('receiver_profile_id', $profile->id)
                ->exists();
        }

        $blockedEitherWay = $canInteract && $hasBlocks
            ? ViewTrackingService::isBlocked($viewerProfile->id, $profile->id)
            : false;
        $canAct = $canInteract
            && ! $blockedEitherWay
            && ProfileLifecycleService::canInitiateInteraction($viewerProfile)
            && ProfileLifecycleService::canReceiveInterest($profile);

        $isShortlisted = $canInteract && $hasShortlists
            ? Shortlist::query()
                ->where('owner_profile_id', $viewerProfile->id)
                ->where('shortlisted_profile_id', $profile->id)
                ->exists()
            : false;
        $isHidden = $canInteract && $hasHiddenProfiles
            ? HiddenProfile::query()
                ->where('owner_profile_id', $viewerProfile->id)
                ->where('hidden_profile_id', $profile->id)
                ->exists()
            : false;
        $isBlocked = $canInteract && $hasBlocks
            ? Block::query()
                ->where('blocker_profile_id', $viewerProfile->id)
                ->where('blocked_profile_id', $profile->id)
                ->exists()
            : false;

        return [
            'can_send_interest' => $canAct && ! $alreadyInterested,
            'can_report' => $canInteract && Schema::hasTable('abuse_reports'),
            'can_shortlist' => $canAct && $hasShortlists && ! $isShortlisted,
            'can_hide' => $canAct && $hasHiddenProfiles && ! $isHidden,
            'can_block' => $canAct && $hasBlocks && ! $isBlocked,
            'is_shortlisted' => $isShortlisted,
            'is_hidden' => $isHidden,
            'is_blocked' => $isBlocked,
        ];
    }

    private function section(string $key, string $title, array $items): ?array
    {
        $items = array_values(array_filter($items));
        if ($items === []) {
            return null;
        }

        return [
            'key' => $key,
            'title' => $title,
            'items' => $items,
        ];
    }

    private function item(string $label, mixed $value, ?string $icon = null, bool $locked = false): ?array
    {
        $displayValue = $locked ? $this->cleanString($value) : $this->cleanDisplayValue($value);
        if ($displayValue === null) {
            return null;
        }

        return [
            'label' => $label,
            'value' => $displayValue,
            'icon' => $icon,
            'locked' => $locked,
        ];
    }

    private function labelFrom(mixed $value): ?string
    {
        if ($value instanceof Model) {
            foreach (self::LABEL_KEYS as $key) {
                $label = $this->cleanString($value->getAttribute($key));
                if ($label !== null) {
                    return $label;
                }
            }

            return null;
        }

        if (is_array($value)) {
            foreach (self::LABEL_KEYS as $key) {
                if (! array_key_exists($key, $value)) {
                    continue;
                }
                $label = $this->cleanString($value[$key]);
                if ($label !== null) {
                    return $label;
                }
            }

            return null;
        }

        return $this->cleanDisplayValue($value);
    }

    private function cleanDisplayValue(mixed $value): ?string
    {
        if (is_array($value) || is_object($value)) {
            return $this->labelFrom($value);
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (is_int($value) || is_float($value)) {
            return null;
        }

        return $this->cleanString($value);
    }

    private function cleanString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_array($value) || is_object($value)) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (preg_match('/^[{\[].*[}\]]$/s', $text)) {
            return null;
        }
        if (str_contains($text, '=>') || preg_match('/^\{[^}]*\b(id|label|key|created_at)\s*:/i', $text)) {
            return null;
        }

        return $text;
    }

    private function cleanLocation(mixed $value): ?string
    {
        $label = $this->cleanString($value);
        if ($label === null) {
            return null;
        }
        if (preg_match('/^location\s+id\s*:/i', $label)) {
            return null;
        }

        return $label;
    }

    private function age(MatrimonyProfile $profile): ?int
    {
        if (empty($profile->date_of_birth)) {
            return null;
        }

        try {
            return Carbon::parse($profile->date_of_birth)->age;
        } catch (\Throwable) {
            return null;
        }
    }

    private function dateLabel(mixed $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        try {
            return Carbon::parse($date)->toDateString();
        } catch (\Throwable) {
            return $this->cleanString($date);
        }
    }

    private function heightLabel(MatrimonyProfile $profile): ?string
    {
        $cm = (int) ($profile->height_cm ?? 0);
        if ($cm <= 0) {
            return null;
        }

        return HeightDisplay::formatFeetInches($cm);
    }

    private function communityLabel(MatrimonyProfile $profile): ?string
    {
        return $this->joinLabels([
            $this->labelFrom($profile->religion),
            $this->labelFrom($profile->caste),
            $this->labelFrom($profile->subCaste),
        ]);
    }

    private function occupationLabel(MatrimonyProfile $profile): ?string
    {
        return $this->cleanString($profile->occupation_title)
            ?? $this->labelFrom($profile->occupationMaster)
            ?? $this->labelFrom($profile->occupationCustom)
            ?? $this->cleanString($profile->resolvedProfession()?->name ?? null);
    }

    private function companyLabel(MatrimonyProfile $profile): ?string
    {
        $company = $this->cleanString($profile->company_name);

        return $company !== null ? ProfileDisplayCopy::formatCompanyName($company) : null;
    }

    private function parentsDetails(MatrimonyProfile $profile): ?string
    {
        $father = $this->joinLabels([
            $this->cleanString($profile->father_name),
            $this->cleanString($profile->father_occupation)
                ?? $this->labelFrom($profile->fatherOccupationMaster)
                ?? $this->labelFrom($profile->fatherOccupationCustom),
            $this->cleanString($profile->father_extra_info),
        ]);
        $mother = $this->joinLabels([
            $this->cleanString($profile->mother_name),
            $this->cleanString($profile->mother_occupation)
                ?? $this->labelFrom($profile->motherOccupationMaster)
                ?? $this->labelFrom($profile->motherOccupationCustom),
            $this->cleanString($profile->mother_extra_info),
        ]);

        return $this->joinLabels([
            $father !== null ? 'Father: '.$father : null,
            $mother !== null ? 'Mother: '.$mother : null,
        ], '. ');
    }

    private function siblingsLabel(MatrimonyProfile $profile): ?string
    {
        $siblings = $profile->siblings;
        if ($siblings === null || $siblings->isEmpty()) {
            return null;
        }

        $brothers = $siblings->where('relation_type', 'brother')->count();
        $sisters = $siblings->where('relation_type', 'sister')->count();
        $others = max(0, $siblings->count() - $brothers - $sisters);

        return $this->joinLabels([
            $brothers > 0 ? $brothers.' Brother'.($brothers === 1 ? '' : 's') : null,
            $sisters > 0 ? $sisters.' Sister'.($sisters === 1 ? '' : 's') : null,
            $others > 0 ? $others.' Sibling'.($others === 1 ? '' : 's') : null,
        ]);
    }

    private function incomeItem(MatrimonyProfile $profile): ?array
    {
        if ((bool) ($profile->income_private ?? false)) {
            return $this->item('Annual Income', 'Hidden', 'income', true);
        }

        $display = app(IncomeEngineService::class)->formatForDisplay(
            $profile->toArray(),
            'income',
            $profile->incomeCurrency
        );

        return $this->item('Annual Income', $this->notDisclosedToNull($display), 'income');
    }

    private function familyIncomeItem(MatrimonyProfile $profile): ?array
    {
        if ((bool) ($profile->family_income_private ?? false)) {
            return $this->item('Family Income', 'Hidden', 'income', true);
        }

        $display = app(IncomeEngineService::class)->formatForDisplay(
            $profile->toArray(),
            'family_income',
            $profile->familyIncomeCurrency ?? $profile->incomeCurrency
        );

        return $this->item('Family Income', $this->notDisclosedToNull($display), 'income');
    }

    private function notDisclosedToNull(?string $value): ?string
    {
        $clean = $this->cleanString($value);
        if ($clean === null || strcasecmp($clean, 'Not disclosed') === 0) {
            return null;
        }

        return $clean;
    }

    private function aboutBody(MatrimonyProfile $profile): ?string
    {
        if (Schema::hasTable('profile_extended_attributes')) {
            $body = DB::table('profile_extended_attributes')
                ->where('profile_id', $profile->id)
                ->value('narrative_about_me');
            $clean = $this->cleanString($body);
            if ($clean !== null) {
                return $clean;
            }
        }

        if (Schema::hasTable('profile_extended_fields')) {
            $body = DB::table('profile_extended_fields')
                ->where('profile_id', $profile->id)
                ->where('field_key', 'narrative_about_me')
                ->value('field_value');
            $clean = $this->cleanString($body);
            if ($clean !== null) {
                return $clean;
            }
        }

        return null;
    }

    /**
     * @return array{0: int, 1: string|null}
     */
    private function visiblePhotoSummary(MatrimonyProfile $profile): array
    {
        $urls = [];
        if (Schema::hasTable('profile_photos')) {
            ProfilePhoto::query()
                ->where('profile_id', $profile->id)
                ->effectivelyApproved()
                ->ordered()
                ->get(['file_path'])
                ->each(function (ProfilePhoto $photo) use ($profile, &$urls): void {
                    $path = ltrim((string) $photo->file_path, '/');
                    if ($path === '' || ProfilePhotoUrlService::isPendingPlaceholder($path)) {
                        return;
                    }
                    $urls[] = app(ProfilePhotoUrlService::class)->publicUrl($path, $profile);
                });
        }

        $legacy = ltrim((string) ($profile->profile_photo ?? ''), '/');
        if ($legacy !== '' && $profile->photo_approved !== false && ! ProfilePhotoUrlService::isPendingPlaceholder($legacy)) {
            $urls[] = app(ProfilePhotoUrlService::class)->publicUrl($legacy, $profile);
        }

        $urls = array_values(array_unique(array_filter($urls)));

        return [count($urls), $urls[0] ?? null];
    }

    private function isVerified(MatrimonyProfile $profile): bool
    {
        if ($profile->user && ($profile->user->mobile_verified_at || $profile->user->email_verified_at)) {
            return true;
        }

        if (! Schema::hasTable('profile_verification_tag')) {
            return false;
        }

        return DB::table('profile_verification_tag')
            ->where('matrimony_profile_id', $profile->id)
            ->whereNull('deleted_at')
            ->exists();
    }

    private function isPremium(MatrimonyProfile $profile): bool
    {
        if (! $profile->user) {
            return false;
        }

        try {
            $subscription = Subscription::query()
                ->where('user_id', $profile->user->id)
                ->effectivelyActiveForAccess()
                ->with('plan')
                ->orderByDesc('starts_at')
                ->orderByDesc('id')
                ->first();
            if ($subscription === null) {
                return false;
            }
            $slug = $subscription->plan?->slug;

            return $slug !== null && ! Plan::isFreeCatalogSlug((string) $slug);
        } catch (\Throwable) {
            return false;
        }
    }

    private function comparisonLabel(MatrimonyProfile $profile): string
    {
        $gender = mb_strtolower(trim((string) ($this->labelFrom($profile->gender) ?? $profile->user?->gender ?? '')));

        if (str_contains($gender, 'female') || str_contains($gender, 'स्त्री') || str_contains($gender, 'महिला')) {
            return 'You & Her';
        }
        if (str_contains($gender, 'male') || str_contains($gender, 'पुरुष')) {
            return 'You & Him';
        }

        return 'You & Profile';
    }

    private function collectionLabels(mixed $collection): ?string
    {
        if (! $collection || ! is_iterable($collection)) {
            return null;
        }

        $labels = [];
        foreach ($collection as $item) {
            $label = $this->labelFrom($item);
            if ($label !== null) {
                $labels[] = $label;
            }
        }

        return $this->joinLabels($labels);
    }

    private function joinLabels(array $values, string $separator = ', '): ?string
    {
        $parts = [];
        foreach ($values as $value) {
            $clean = $this->cleanString($value);
            if ($clean !== null) {
                $parts[] = $clean;
            }
        }
        $parts = array_values(array_unique($parts));

        return $parts !== [] ? implode($separator, $parts) : null;
    }

    private function rangeLabel(mixed $min, mixed $max, string $suffix = ''): ?string
    {
        $min = $this->numericDisplay($min);
        $max = $this->numericDisplay($max);
        if ($min === null && $max === null) {
            return null;
        }
        if ($min !== null && $max !== null) {
            return $min.' - '.$max.$suffix;
        }
        if ($min !== null) {
            return $min.$suffix.' and above';
        }

        return 'Up to '.$max.$suffix;
    }

    private function heightRangeLabel(mixed $minCm, mixed $maxCm): ?string
    {
        $min = is_numeric($minCm) ? (int) $minCm : null;
        $max = is_numeric($maxCm) ? (int) $maxCm : null;
        if ($min === null && $max === null) {
            return null;
        }
        if ($min !== null && $max !== null) {
            return HeightDisplay::formatFeetInchesRange($min, $max);
        }
        if ($min !== null) {
            return HeightDisplay::formatFeetInches($min).' and above';
        }

        return 'Up to '.HeightDisplay::formatFeetInches((int) $max);
    }

    private function numericDisplay(mixed $value): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }

    private function managedByLabel(?string $value): ?string
    {
        return match ($value) {
            'self' => 'Self',
            'parent_guardian' => 'Parent / Guardian',
            'sibling' => 'Sibling',
            'relative' => 'Relative',
            'friend' => 'Friend',
            'other' => 'Other',
            default => $this->cleanString($value),
        };
    }

    private function withChildrenLabel(?string $value): ?string
    {
        return match ($value) {
            'no' => 'No',
            'yes_if_live_separate' => 'Yes, if living separately',
            'yes' => 'Yes',
            default => $this->cleanString($value),
        };
    }
}
