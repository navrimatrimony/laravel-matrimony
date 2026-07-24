<?php

namespace App\Services\Api;

use App\Models\Block;
use App\Models\ContactGrant;
use App\Models\ContactRequest;
use App\Models\EducationDegree;
use App\Models\HiddenProfile;
use App\Models\Interest;
use App\Models\MatrimonyProfile;
use App\Models\Plan;
use App\Models\ProfileVisibilitySetting;
use App\Models\ProfilePhoto;
use App\Models\Shortlist;
use App\Models\SuchakProfileRepresentation;
use App\Models\Subscription;
use App\Models\User;
use App\Services\CommunicationPolicyService;
use App\Services\ContactAccessService;
use App\Services\ContactRequestService;
use App\Services\Chat\ChatConversationService;
use App\Services\Chat\ChatPolicyService;
use App\Services\Chat\PolicyDecision;
use App\Services\Image\ProfilePhotoUrlService;
use App\Services\IncomeEngineService;
use App\Services\EducationService;
use App\Services\Gunamilan\GunamilanService;
use App\Services\Location\LocationService;
use App\Services\PartnerPreferenceSuggestionService;
use App\Services\ProfilePreferenceMatchService;
use App\Services\ProfileLifecycleService;
use App\Services\ProfilePartnerCommunityFlagService;
use App\Services\ProfilePhotoAccessService;
use App\Services\SiteIdentityService;
use App\Services\ViewTrackingService;
use App\Support\HeightDisplay;
use App\Support\ProfileDisplayCopy;
use Carbon\Carbon;
use App\Support\LocalizedText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class MobileProfileDisplayPresenter
{
    private const LOCATION_NEARBY_RADIUS_KM = 25;

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

    /**
     * Label keys in the order this locale should prefer them.
     *
     * The fixed LABEL_KEYS list always put label_mr first, so English viewers
     * saw Marathi and the detail endpoint disagreed with the list endpoint —
     * the "profile shows Marathi then mixes" report. Marathi keeps the _mr
     * columns first; every other locale skips them and reads the base column,
     * matching App\Support\LocalizedText.
     */
    private function orderedLabelKeys(): array
    {
        if (LocalizedText::isMarathi()) {
            return self::LABEL_KEYS;
        }

        return array_values(array_filter(
            self::LABEL_KEYS,
            static fn (string $key): bool => ! str_ends_with($key, '_mr'),
        ));
    }

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
            'birthCity',
            'complexion',
            'bloodGroup',
            'physicalBuild',
            'diet',
            'smokingStatus',
            'drinkingStatus',
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
            'relatives',
            'allianceNetworks.city',
            'allianceNetworks.state',
            'allianceNetworks.district',
            'allianceNetworks.taluka',
            'children.childLivingWith',
            'marriages',
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
        $ageLabel = $age !== null ? $age.(LocalizedText::isMarathi() ? ' वर्षे' : ' years') : null;
        $heightLabel = $this->heightLabel($profile);
        $communityLabel = $this->communityLabel($profile);
        $occupationLabel = $this->occupationLabel($profile);
        $locationLabel = $this->cleanLocation(ProfileDisplayCopy::profileResidenceDisplayLine($profile));
        [$photoCount, $primaryPhotoUrl] = $this->visiblePhotoSummary($profile);
        $photoAlbum = $this->photoAlbumPayload($profile, $viewer, $isOwnProfile);
        $comparison = $this->comparisonPayload($profile, $viewerProfile);
        $contact = $this->contactPayload($profile, $viewerProfile, $viewer);
        $chat = $this->chatPayload($profile, $viewerProfile);
        $gunamilan = $this->gunamilanPayload($profile, $viewerProfile);

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
            'contact' => $contact,
            'chat' => $chat,
            'gunamilan' => $gunamilan,
            'photo_album' => $photoAlbum,
        ];
    }

    public function forListCard(MatrimonyProfile $profile, ?User $viewer = null): array
    {
        $profile->loadMissing([
            'user',
            'gender',
            'religion',
            'caste',
            'subCaste',
            'occupationMaster',
            'occupationCustom',
            'horoscope',
        ]);

        $viewerProfile = $viewer?->matrimonyProfile;
        $age = $this->age($profile);
        [$photoCount, $primaryPhotoUrl] = $this->visiblePhotoSummary($profile);
        $photoSummary = [
            'photo_count' => $photoCount,
            'primary_photo_url' => $primaryPhotoUrl,
        ];

        return [
            'card' => [
                'name' => $this->cleanString($profile->full_name),
                'age' => $age,
                'age_label' => $age !== null ? $age.(LocalizedText::isMarathi() ? ' वर्षे' : ' years') : null,
                'height_label' => $this->heightLabel($profile),
                'community_label' => $this->communityLabel($profile),
                'education_label' => $this->cleanDisplayValue($profile->highest_education),
                'occupation_label' => $this->occupationLabel($profile),
                'location_label' => $this->cleanLocation(ProfileDisplayCopy::profileResidenceDisplayLine($profile)),
                'verified' => $this->isVerified($profile),
                'premium' => $this->isPremium($profile),
                'photo_count' => $photoSummary['photo_count'],
                'primary_photo_url' => $photoSummary['primary_photo_url'],
                'comparison_label' => $this->comparisonLabel($profile),
                'has_astro' => $profile->horoscope !== null,
            ],
            'hero' => $photoSummary,
            'primary_photo_url' => $primaryPhotoUrl,
            'profile_photo_url' => $primaryPhotoUrl,
            'approved_photo_url' => $primaryPhotoUrl,
            'actions' => $this->actions($profile, $viewerProfile),
        ];
    }

    private function comparisonPayload(MatrimonyProfile $profile, ?MatrimonyProfile $viewerProfile): ?array
    {
        if ($viewerProfile === null || (int) $viewerProfile->id === (int) $profile->id) {
            return null;
        }

        $viewerProfile->loadMissing(['user', 'gender', 'religion', 'caste', 'subCaste', 'horoscope']);
        $profile->loadMissing(['user', 'gender', 'religion', 'caste', 'subCaste', 'preferenceCriteria', 'horoscope']);

        $rows = $this->basicComparisonRows($profile, $viewerProfile);

        if ($rows === []) {
            return null;
        }

        $matchedCount = count(array_filter($rows, fn (array $row): bool => ($row['is_counted'] ?? false) === true));
        [$viewerPhotoCount, $viewerPhotoUrl] = $this->visiblePhotoSummary($viewerProfile);
        [$targetPhotoCount, $targetPhotoUrl] = $this->visiblePhotoSummary($profile);

        unset($viewerPhotoCount, $targetPhotoCount);

        return [
            'enabled' => true,
            'title' => $this->comparisonLabel($profile),
            'summary' => $matchedCount > 0
                ? $matchedCount.(LocalizedText::isMarathi() ? ' जुळणारे मुद्दे' : ' matching points')
                : null,
            'viewer' => [
                'name' => $this->tr('You'),
                'photo_url' => $viewerPhotoUrl,
            ],
            'target' => [
                'name' => $this->cleanString($profile->full_name) ?? $this->tr('Profile'),
                'photo_url' => $targetPhotoUrl,
            ],
            'matched_count' => $matchedCount,
            'total_count' => count($rows),
            'rows' => $rows,
            'items' => $this->legacyComparisonItems($rows),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function basicComparisonRows(MatrimonyProfile $profile, MatrimonyProfile $viewerProfile): array
    {
        $rows = array_values(array_filter([
            $this->ageComparisonRow($profile, $viewerProfile),
            $this->heightComparisonRow($profile, $viewerProfile),
            $this->locationComparisonRow($profile, $viewerProfile),
            $this->communityComparisonRow($profile, $viewerProfile),
            $this->sameSubCasteComparisonRow($profile, $viewerProfile),
            $this->educationComparisonRow($profile, $viewerProfile),
            $this->incomeComparisonRow($profile, $viewerProfile),
            $this->gunamilanComparisonRow($profile, $viewerProfile),
        ]));

        return array_values($rows);
    }

    private function ageComparisonRow(MatrimonyProfile $profile, MatrimonyProfile $viewerProfile): ?array
    {
        $viewerAge = $this->age($viewerProfile);
        $targetAge = $this->age($profile);
        if ($viewerAge === null && $targetAge === null) {
            return null;
        }

        $status = 'neutral';
        $counted = false;
        if ($viewerAge !== null && $targetAge !== null && $this->agePairFits($viewerProfile, $profile, $viewerAge, $targetAge)) {
            $status = 'match';
            $counted = true;
        }

        return $this->comparisonRow(
            'age',
            $this->tr('Age'),
            $viewerAge !== null ? $viewerAge.(LocalizedText::isMarathi() ? ' वर्षे' : ' years') : null,
            $targetAge !== null ? $targetAge.(LocalizedText::isMarathi() ? ' वर्षे' : ' years') : null,
            $status,
            $counted
        );
    }

    private function agePairFits(MatrimonyProfile $viewerProfile, MatrimonyProfile $targetProfile, int $viewerAge, int $targetAge): bool
    {
        $viewerGender = $this->profileGenderKey($viewerProfile);
        $targetGender = $this->profileGenderKey($targetProfile);

        if ($viewerGender === 'male' && $targetGender === 'female') {
            $diff = $viewerAge - $targetAge;

            return $diff >= 0 && $diff <= 5;
        }
        if ($viewerGender === 'female' && $targetGender === 'male') {
            $diff = $targetAge - $viewerAge;

            return $diff >= 0 && $diff <= 5;
        }

        return abs($viewerAge - $targetAge) <= 5;
    }

    private function heightComparisonRow(MatrimonyProfile $profile, MatrimonyProfile $viewerProfile): ?array
    {
        $viewerHeight = $this->heightLabel($viewerProfile);
        $targetHeight = $this->heightLabel($profile);
        if ($viewerHeight === null && $targetHeight === null) {
            return null;
        }

        $status = 'neutral';
        $counted = false;
        $viewerCm = (int) ($viewerProfile->height_cm ?? 0);
        $targetCm = (int) ($profile->height_cm ?? 0);
        if ($viewerCm > 0 && $targetCm > 0 && $this->heightPairFits($viewerProfile, $profile, $viewerCm, $targetCm)) {
            $status = 'match';
            $counted = true;
        }

        return $this->comparisonRow('height', $this->tr('Height'), $viewerHeight, $targetHeight, $status, $counted);
    }

    private function heightPairFits(MatrimonyProfile $viewerProfile, MatrimonyProfile $targetProfile, int $viewerCm, int $targetCm): bool
    {
        $viewerGender = $this->profileGenderKey($viewerProfile);
        $targetGender = $this->profileGenderKey($targetProfile);
        $fourInchesCm = PartnerPreferenceSuggestionService::fourInchesCm();

        if ($viewerGender === 'male' && $targetGender === 'female') {
            $diff = $viewerCm - $targetCm;

            return $diff >= 0 && $diff <= $fourInchesCm;
        }
        if ($viewerGender === 'female' && $targetGender === 'male') {
            $diff = $targetCm - $viewerCm;

            return $diff >= 0 && $diff <= $fourInchesCm;
        }

        return abs($viewerCm - $targetCm) <= $fourInchesCm;
    }

    private function locationComparisonRow(MatrimonyProfile $profile, MatrimonyProfile $viewerProfile): ?array
    {
        $viewerLocation = $this->cleanLocation(ProfileDisplayCopy::profileResidenceDisplayLine($viewerProfile));
        $targetLocation = $this->cleanLocation(ProfileDisplayCopy::profileResidenceDisplayLine($profile));
        if ($viewerLocation === null && $targetLocation === null) {
            return null;
        }

        [$status, $isCounted] = $this->locationComparisonStatus($profile, $viewerProfile);

        return $this->comparisonRow('location', $this->tr('Location'), $viewerLocation, $targetLocation, $status, $isCounted);
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private function locationComparisonStatus(MatrimonyProfile $profile, MatrimonyProfile $viewerProfile): array
    {
        try {
            $viewerHints = $viewerProfile->residenceLocationHierarchyHints();
            $targetHints = $profile->residenceLocationHierarchyHints();

            $viewerTalukaId = $this->positiveInt($viewerHints['taluka_id'] ?? null);
            $targetTalukaId = $this->positiveInt($targetHints['taluka_id'] ?? null);
            if ($viewerTalukaId !== null && $targetTalukaId !== null) {
                if ($viewerTalukaId === $targetTalukaId) {
                    return ['strong', true];
                }
                if ($this->locationIsNearby($viewerTalukaId, $targetTalukaId, 'taluka')) {
                    return ['strong', true];
                }
            }

            $viewerDistrictId = $this->positiveInt($viewerHints['district_id'] ?? null);
            $targetDistrictId = $this->positiveInt($targetHints['district_id'] ?? null);
            if ($viewerDistrictId !== null && $targetDistrictId !== null) {
                if ($viewerDistrictId === $targetDistrictId) {
                    return ['match', true];
                }
                if ($this->locationIsNearby($viewerDistrictId, $targetDistrictId, 'district')) {
                    return ['near', true];
                }
            }
        } catch (\Throwable) {
            return ['neutral', false];
        }

        return ['neutral', false];
    }

    private function locationIsNearby(int $sourceLocationId, int $targetLocationId, string $hierarchy): bool
    {
        try {
            foreach (app(LocationService::class)->getNearbyLocations($sourceLocationId, self::LOCATION_NEARBY_RADIUS_KM, $hierarchy) as $row) {
                if ((int) ($row['id'] ?? 0) === $targetLocationId) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    private function positiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private function communityComparisonRow(MatrimonyProfile $profile, MatrimonyProfile $viewerProfile): ?array
    {
        $viewerCommunity = $this->joinLabels([
            $this->labelFrom($viewerProfile->religion),
            $this->labelFrom($viewerProfile->caste),
        ]);
        $targetCommunity = $this->joinLabels([
            $this->labelFrom($profile->religion),
            $this->labelFrom($profile->caste),
        ]);
        if ($viewerCommunity === null && $targetCommunity === null) {
            return null;
        }

        $status = 'neutral';
        $counted = false;
        $viewerCasteId = (int) ($viewerProfile->caste_id ?? 0);
        $targetCasteId = (int) ($profile->caste_id ?? 0);
        if ($viewerCasteId > 0 && $viewerCasteId === $targetCasteId) {
            $status = 'match';
            $counted = true;
        }

        return $this->comparisonRow('community', $this->tr('Religion / Caste'), $viewerCommunity, $targetCommunity, $status, $counted);
    }

    private function sameSubCasteComparisonRow(MatrimonyProfile $profile, MatrimonyProfile $viewerProfile): ?array
    {
        $viewerSubCasteId = $this->positiveInt($viewerProfile->sub_caste_id ?? null);
        $targetSubCasteId = $this->positiveInt($profile->sub_caste_id ?? null);
        if ($viewerSubCasteId === null || $targetSubCasteId === null || $viewerSubCasteId !== $targetSubCasteId) {
            return null;
        }

        $viewerSubCaste = $this->labelFrom($viewerProfile->subCaste);
        $targetSubCaste = $this->labelFrom($profile->subCaste);
        if ($viewerSubCaste === null || $targetSubCaste === null) {
            return null;
        }

        return $this->comparisonRow('same_sub_caste', $this->tr('Same sub-caste'), $viewerSubCaste, $targetSubCaste, 'match', true);
    }

    private function educationComparisonRow(MatrimonyProfile $profile, MatrimonyProfile $viewerProfile): ?array
    {
        $viewerEducation = $this->educationText($viewerProfile);
        $targetEducation = $this->educationText($profile);
        if ($viewerEducation === null || $targetEducation === null) {
            return null;
        }

        $viewerDegree = $this->profileEducationDegree($viewerEducation);
        $targetDegree = $this->profileEducationDegree($targetEducation);
        $viewerLabel = $this->educationDegreeLabel($viewerDegree);
        $targetLabel = $this->educationDegreeLabel($targetDegree);

        if ($viewerDegree !== null && $targetDegree !== null && $viewerLabel !== null && $targetLabel !== null && (int) $viewerDegree->id === (int) $targetDegree->id) {
            return $this->comparisonRow('education', $this->tr('Education'), $viewerLabel, $targetLabel, 'match', true);
        }

        $viewerSort = $viewerDegree !== null ? $this->positiveInt($viewerDegree->sort_order ?? null) : null;
        $targetSort = $targetDegree !== null ? $this->positiveInt($targetDegree->sort_order ?? null) : null;
        if ($viewerSort !== null && $targetSort !== null && abs($viewerSort - $targetSort) <= 1) {
            return $this->comparisonRow('education', $this->tr('Education'), $viewerLabel, $targetLabel, 'near', true);
        }

        if ($this->normalizeEducationText($viewerEducation) === $this->normalizeEducationText($targetEducation)) {
            return $this->comparisonRow('education', $this->tr('Education'), $viewerEducation, $targetEducation, 'match', true);
        }

        return null;
    }

    private function incomeComparisonRow(MatrimonyProfile $profile, MatrimonyProfile $viewerProfile): ?array
    {
        if ((bool) ($viewerProfile->income_private ?? false)) {
            return null;
        }

        $criteria = $profile->preferenceCriteria;
        $min = $criteria?->preferred_income_min ?? null;
        $max = $criteria?->preferred_income_max ?? null;
        if ($min === null && $max === null) {
            return null;
        }

        $viewerIncome = $this->profileAnnualIncomeRupees($viewerProfile);
        if ($viewerIncome === null) {
            return null;
        }

        $minValue = is_numeric($min) ? (float) $min : null;
        $maxValue = is_numeric($max) ? (float) $max : null;
        if ($minValue !== null && $viewerIncome < $minValue) {
            return null;
        }
        if ($maxValue !== null && $viewerIncome > $maxValue) {
            return null;
        }

        $viewerValue = $this->formatRupeesLakh($viewerIncome);
        $targetValue = $this->formatIncomeRange($minValue, $maxValue);
        if ($viewerValue === null || $targetValue === null) {
            return null;
        }

        return $this->comparisonRow('income', $this->tr('Income'), $viewerValue, $targetValue, 'match', true);
    }

    private function gunamilanComparisonRow(MatrimonyProfile $profile, MatrimonyProfile $viewerProfile): ?array
    {
        try {
            $result = app(GunamilanService::class)->calculate($viewerProfile, $profile);
        } catch (\Throwable) {
            return null;
        }

        if (($result['available'] ?? false) !== true) {
            return null;
        }

        $points = $result['total_points'] ?? null;
        if (! is_numeric($points) || (float) $points <= 18.0) {
            return null;
        }

        $max = is_numeric($result['max_points'] ?? null) ? (float) $result['max_points'] : 36.0;
        $score = $this->formatGunamilanScore((float) $points, $max);

        return $this->comparisonRow('gunamilan', $this->tr('Gunamilan'), $score, $this->tr('Compatible'), 'match', true);
    }

    /**
     * @return array<string, mixed>
     */
    private function gunamilanPayload(MatrimonyProfile $profile, ?MatrimonyProfile $viewerProfile): array
    {
        $base = [
            'available' => false,
            'status' => 'unavailable',
            'title' => $this->tr('Gunamilan / Horoscope Match'),
            'score' => null,
            'total_score' => null,
            'max_score' => 36.0,
            'summary_label' => null,
            'message' => $this->tr('Horoscope data is incomplete.'),
            'rows' => [],
            'missing_fields' => [],
            'disclaimer' => $this->tr('Gunamilan is only a compatibility reference. Families should make the final decision after discussion.'),
        ];

        if ($viewerProfile === null) {
            return array_merge($base, [
                'message' => $this->tr('Create your profile to view horoscope compatibility.'),
            ]);
        }

        if ((int) $viewerProfile->id === (int) $profile->id) {
            return array_merge($base, [
                'message' => $this->tr('Gunamilan is shown for another matched profile.'),
            ]);
        }

        try {
            $viewerProfile->loadMissing('horoscope');
            $profile->loadMissing('horoscope');
            $result = app(GunamilanService::class)->calculate($viewerProfile, $profile);
        } catch (Throwable) {
            return $base;
        }

        $maxScore = is_numeric($result['max_points'] ?? null) ? (float) $result['max_points'] : 36.0;
        $missingFields = $this->gunamilanMissingFields($result['missing_fields'] ?? []);
        $viewerMissingHoroscope = $viewerProfile->horoscope === null;
        $targetMissingHoroscope = $profile->horoscope === null;

        if (($result['available'] ?? false) !== true) {
            $status = match (true) {
                $viewerMissingHoroscope => 'missing_viewer_horoscope',
                $targetMissingHoroscope => 'missing_target_horoscope',
                default => 'unavailable',
            };

            return array_merge($base, [
                'status' => $status,
                'max_score' => $maxScore,
                'message' => $this->gunamilanUnavailableMessage($status),
                'missing_fields' => $missingFields,
            ]);
        }

        $totalScore = is_numeric($result['total_points'] ?? null) ? (float) $result['total_points'] : 0.0;

        return array_merge($base, [
            'available' => true,
            'status' => 'available',
            'score' => $totalScore,
            'total_score' => $totalScore,
            'max_score' => $maxScore,
            'summary_label' => $this->formatGunamilanScore($totalScore, $maxScore),
            'message' => null,
            'rows' => $this->gunamilanRows($result['sections'] ?? []),
            'missing_fields' => [],
        ]);
    }

    private function gunamilanUnavailableMessage(string $status): string
    {
        return $this->tr(match ($status) {
            'missing_viewer_horoscope' => 'Your horoscope data is incomplete.',
            'missing_target_horoscope' => 'This profile has incomplete horoscope data.',
            default => 'Horoscope data is incomplete.',
        });
    }

    /**
     * @param  mixed  $sections
     * @return array<int, array<string, mixed>>
     */
    private function gunamilanRows(mixed $sections): array
    {
        if (! is_array($sections)) {
            return [];
        }

        $rows = [];
        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }

            $label = $this->cleanString($section['label'] ?? null);
            if ($label === null) {
                continue;
            }

            $points = is_numeric($section['points'] ?? null) ? (float) $section['points'] : 0.0;
            $maxPoints = is_numeric($section['max_points'] ?? null) ? (float) $section['max_points'] : 0.0;

            $rows[] = [
                'key' => $this->cleanString($section['key'] ?? null),
                'guna_name' => $label,
                'label' => $label,
                'obtained' => $points,
                'points' => $points,
                'max' => $maxPoints,
                'max_points' => $maxPoints,
                'status' => $this->cleanString($section['status'] ?? null) ?? 'partial',
                'match_label' => $this->gunamilanRowMatchLabel($section),
                'note' => $this->cleanString($section['note'] ?? null),
                'bride_value' => $this->cleanString($section['bride_value'] ?? null),
                'groom_value' => $this->cleanString($section['groom_value'] ?? null),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $section
     */
    private function gunamilanRowMatchLabel(array $section): string
    {
        $status = $this->cleanString($section['status'] ?? null);

        return $this->tr(match ($status) {
            'full' => 'Full match',
            'missing' => 'Missing data',
            default => 'Partial match',
        });
    }

    /**
     * @param  mixed  $missingFields
     * @return array<int, array{side: string, label: string}>
     */
    private function gunamilanMissingFields(mixed $missingFields): array
    {
        if (! is_array($missingFields)) {
            return [];
        }

        $rows = [];
        foreach ($missingFields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $label = $this->cleanString($field['label'] ?? null);
            if ($label === null) {
                continue;
            }

            $rows[] = [
                'side' => $this->cleanString($field['side'] ?? null) ?? '',
                'label' => $label,
            ];
        }

        return $rows;
    }

    private function educationText(MatrimonyProfile $profile): ?string
    {
        $text = $this->cleanComparisonString($profile->highest_education);
        if ($text === null) {
            return null;
        }

        $normalized = mb_strtolower($text);
        if (in_array($normalized, ['not disclosed', 'unknown', 'n/a', 'na', 'माहिती नाही'], true)) {
            return null;
        }

        return $text;
    }

    private function profileEducationDegree(string $education): ?EducationDegree
    {
        try {
            return app(EducationService::class)->findDegreeMatch($education);
        } catch (\Throwable) {
            return null;
        }
    }

    private function educationDegreeLabel(?EducationDegree $degree): ?string
    {
        if ($degree === null) {
            return null;
        }

        return $this->cleanString($degree->shortDisplayLabel())
            ?? $this->cleanString($degree->full_form)
            ?? $this->cleanString($degree->code);
    }

    private function normalizeEducationText(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace(["\xc2\xa0", '.', ','], ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function profileAnnualIncomeRupees(MatrimonyProfile $profile): ?float
    {
        if ($profile->income_normalized_annual_amount !== null && $profile->income_normalized_annual_amount !== '') {
            return is_numeric($profile->income_normalized_annual_amount) ? (float) $profile->income_normalized_annual_amount : null;
        }
        if ($profile->annual_income !== null && $profile->annual_income !== '') {
            return is_numeric($profile->annual_income) ? (float) $profile->annual_income : null;
        }

        return null;
    }

    private function formatIncomeRange(?float $min, ?float $max): ?string
    {
        $minLabel = $min !== null ? $this->formatRupeesLakh($min) : null;
        $maxLabel = $max !== null ? $this->formatRupeesLakh($max) : null;
        if ($minLabel !== null && $maxLabel !== null) {
            return $minLabel.' - '.$maxLabel;
        }
        if ($minLabel !== null) {
            return $minLabel.(LocalizedText::isMarathi() ? ' व त्याहून अधिक' : ' and above');
        }
        if ($maxLabel !== null) {
            return LocalizedText::isMarathi() ? $maxLabel.' पर्यंत' : 'Up to '.$maxLabel;
        }

        return null;
    }

    private function formatRupeesLakh(float $rupees): ?string
    {
        if ($rupees <= 0) {
            return null;
        }

        $lakh = $rupees / 100000.0;
        $label = abs($lakh - round($lakh)) < 0.01
            ? (string) (int) round($lakh)
            : rtrim(rtrim(number_format($lakh, 1, '.', ''), '0'), '.');

        return '₹'.$label.(LocalizedText::isMarathi() ? ' लाख' : ' L');
    }

    private function formatGunamilanScore(float $points, float $max): string
    {
        $pointsLabel = abs($points - round($points)) < 0.01
            ? (string) (int) round($points)
            : rtrim(rtrim(number_format($points, 1, '.', ''), '0'), '.');
        $maxLabel = abs($max - round($max)) < 0.01
            ? (string) (int) round($max)
            : rtrim(rtrim(number_format($max, 1, '.', ''), '0'), '.');

        return $pointsLabel.'/'.$maxLabel;
    }

    private function comparisonRow(
        string $key,
        string $label,
        ?string $viewerValue,
        ?string $targetValue,
        string $status,
        bool $isCounted
    ): ?array {
        $notSpecified = LocalizedText::isMarathi() ? 'माहिती नाही' : 'Not specified';
        $viewerValue = $this->cleanComparisonString($viewerValue) ?? $notSpecified;
        $targetValue = $this->cleanComparisonString($targetValue) ?? $notSpecified;
        if ($viewerValue === $notSpecified && $targetValue === $notSpecified) {
            return null;
        }

        $status = in_array($status, ['strong', 'match', 'near', 'neutral'], true) ? $status : 'neutral';
        $positive = in_array($status, ['strong', 'match', 'near'], true);

        return [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'status_label' => $this->comparisonStatusLabel($status),
            'viewer_value' => $viewerValue,
            'target_value' => $targetValue,
            'is_counted' => $positive && $isCounted,
        ];
    }

    private function comparisonStatusLabel(string $status): string
    {
        return $this->tr(match ($status) {
            'strong' => 'Strong',
            'match' => 'Match',
            'near' => 'Near',
            default => 'Basic',
        });
    }

    private function profileGenderKey(MatrimonyProfile $profile): ?string
    {
        $key = mb_strtolower(trim((string) ($profile->gender?->key ?? '')));

        if (str_contains($key, 'female') || str_contains($key, 'स्त्री') || str_contains($key, 'महिला')) {
            return 'female';
        }
        if (str_contains($key, 'male') || str_contains($key, 'पुरुष')) {
            return 'male';
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function legacyComparisonItems(array $rows): array
    {
        return array_map(function (array $row): array {
            $status = (string) ($row['status'] ?? 'neutral');

            return [
                'key' => (string) ($row['key'] ?? ''),
                'label' => (string) ($row['label'] ?? ''),
                'target_preference' => (string) ($row['target_value'] ?? ''),
                'viewer_value' => (string) ($row['viewer_value'] ?? ''),
                'matched' => in_array($status, ['strong', 'match'], true) ? true : null,
            ];
        }, $rows);
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
                return $value.(LocalizedText::isMarathi() ? ' वर्षे' : ' years');
            }

            return str_replace(' – ', ' to ', $value).(str_contains($value, 'year') ? '' : (LocalizedText::isMarathi() ? ' वर्षे' : ' years'));
        }

        return $value;
    }

    private function comparisonSection(?array $comparison): ?array
    {
        if ($comparison === null) {
            return null;
        }

        $rows = $comparison['rows'] ?? $comparison['items'] ?? [];
        if (! is_array($rows) || $rows === []) {
            return null;
        }

        $items = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $label = $this->cleanString($row['label'] ?? null);
            $viewerValue = $this->cleanString($row['viewer_value'] ?? null);
            if ($label === null || $viewerValue === null) {
                continue;
            }

            $status = $this->cleanString($row['status_label'] ?? null);
            if ($status === null && array_key_exists('matched', $row)) {
                $matched = $row['matched'] ?? null;
                $status = $matched === true ? $this->tr('Match') : ($matched === false ? $this->tr('Not matched') : $this->tr('Review'));
            }
            $items[] = $this->item(
                $label,
                $status !== null ? $viewerValue.' — '.$status : $viewerValue,
                $this->comparisonIcon($this->cleanString($row['key'] ?? null))
            );
        }

        return $this->section('partner_match', (string) ($comparison['title'] ?? $this->tr('You & Profile')), $items);
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

        $name = $this->cleanString($profile->full_name) ?? $this->tr('Profile');
        $title = $age !== null
            ? $name.', '.$age.' - '.$siteName
            : $name.' - '.$siteName;
        $description = $this->joinLabels([
            $communityLabel,
            $occupationLabel,
            $locationLabel,
        ], ' • ') ?? (LocalizedText::isMarathi() ? 'हे प्रोफाइल '.$siteName.' वर पहा.' : 'View this profile on '.$siteName.'.');
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
            $this->item('Weight', $profile->weight_kg !== null ? $profile->weight_kg.(LocalizedText::isMarathi() ? ' किलो' : ' kg') : null, 'height'),
            $isOwnProfile ? $this->item('Birth Date', $this->dateLabel($profile->date_of_birth), 'calendar') : null,
            $this->item('Marital Status', $this->labelFrom($profile->maritalStatus), 'heart'),
            $this->item('Lives in', $locationLabel, 'location'),
            $isOwnProfile ? $this->item('Address Line', $profile->address_line, 'location') : null,
            $this->item('Religion', $this->labelFrom($profile->religion), 'community'),
            $this->item('Mother Tongue', $this->labelFrom($profile->motherTongue), 'language'),
            $this->item('Community', $communityLabel, 'community'),
            $this->item('Complexion', $this->labelFrom($profile->complexion), 'profile'),
            $this->item('Blood Group', $this->labelFrom($profile->bloodGroup), 'profile'),
            $this->item('Physical Build', $this->labelFrom($profile->physicalBuild), 'profile'),
            $this->item('Spectacles / Lens', $this->translatedOptionLabel('components.physical.spectacles_options', $profile->spectacles_lens), 'profile'),
            $this->item('Physical Condition', $this->translatedOptionLabel('components.physical.condition_options', $profile->physical_condition), 'profile'),
            $this->item('Diet', $this->labelFrom($profile->diet), 'diet'),
            $this->item('Smoking', $this->labelFrom($profile->smokingStatus), 'profile'),
            $this->item('Drinking', $this->labelFrom($profile->drinkingStatus), 'profile'),
        ];

        return $this->section('basic', 'Basic Details', $items);
    }

    private function familySection(MatrimonyProfile $profile): ?array
    {
        $maritalStatusKey = $profile->maritalStatus?->key;
        $showMarriageChildren = \App\Support\MaritalDependencyRules::requiresMarriageDetails($maritalStatusKey);
        $items = [
            $this->item('Family Type', $this->labelFrom($profile->familyType), 'family'),
            $this->item('Parents Details', $this->parentsDetails($profile), 'parents'),
            $this->item('Marriage History', $showMarriageChildren ? $this->marriageHistoryLabel($profile, $maritalStatusKey) : null, 'heart'),
            $this->item('Children', $showMarriageChildren && (bool) $profile->has_children ? $this->childrenLabel($profile) : null, 'family'),
            $this->item('Siblings', $this->siblingsLabel($profile), 'siblings'),
            $this->item('Relatives', $this->relativesLabel($profile), 'relatives'),
            $this->item('Alliance Network', $this->allianceNetworkLabel($profile), 'relatives'),
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
        $preferredMaritalStatusLabels = $this->partnerPreferencePivotLabels($profile, 'profile_preferred_marital_statuses', 'marital_status_id', 'master_marital_statuses');
        $preferredDietLabels = $this->partnerPreferencePivotLabels($profile, 'profile_preferred_diets', 'diet_id', 'master_diets');
        $intercasteLabel = ProfilePartnerCommunityFlagService::interestedInIntercaste((int) $profile->id) ? $this->tr('Open to intercaste') : null;
        $expectations = $this->extendedNarrativeExpectations($profile);
        if ($criteria === null) {
            $items = [
                $this->item('Preferred Religions', $this->collectionLabels($profile->preferredReligions), 'community'),
                $this->item('Preferred Castes', $this->collectionLabels($profile->preferredCastes), 'community'),
                $this->item('Intercaste', $intercasteLabel, 'community'),
                $this->item('Preferred Education', $this->collectionLabels($profile->preferredEducationDegrees), 'education'),
                $this->item('Preferred Occupation', $this->collectionLabels($profile->preferredOccupationMasters), 'work'),
                $this->item('Preferred Marital Status', $preferredMaritalStatusLabels, 'heart'),
                $this->item('Preferred Diet', $preferredDietLabels, 'lifestyle'),
                $this->item('Expectations', $expectations, 'heart'),
            ];

            return $this->section('partner_preferences', 'Partner Preferences', $items);
        }

        $items = [
            $this->item('Age Preference', $this->rangeLabel($criteria->preferred_age_min, $criteria->preferred_age_max, LocalizedText::isMarathi() ? ' वर्षे' : ' years'), 'age'),
            $this->item('Height Preference', $this->heightRangeLabel($criteria->preferred_height_min_cm, $criteria->preferred_height_max_cm), 'height'),
            $this->item('Preferred Religions', $this->collectionLabels($profile->preferredReligions), 'community'),
            $this->item('Preferred Castes', $this->collectionLabels($profile->preferredCastes), 'community'),
            $this->item('Intercaste', $intercasteLabel, 'community'),
            $this->item('Preferred Education', $this->collectionLabels($profile->preferredEducationDegrees) ?? $criteria->preferred_education, 'education'),
            $this->item('Preferred Occupation', $this->collectionLabels($profile->preferredOccupationMasters), 'work'),
            $this->item('Preferred City', $this->labelFrom($criteria->settledCity), 'location'),
            $this->item('Preferred Marital Status', $preferredMaritalStatusLabels ?? $this->labelFrom($criteria->preferredMaritalStatus), 'heart'),
            $this->item('Marriage Type Preference', $this->labelFrom($criteria->marriageTypePreference), 'heart'),
            $this->item('Willing to Relocate', $criteria->willing_to_relocate === null ? null : $this->tr($criteria->willing_to_relocate ? 'Yes' : 'No'), 'location'),
            $this->item('Profile Managed By', $this->managedByLabel($criteria->preferred_profile_managed_by), 'profile'),
            $this->item('Partner with Children', $this->withChildrenLabel($criteria->partner_profile_with_children), 'family'),
            $this->item('Preferred Diet', $preferredDietLabels, 'lifestyle'),
            $this->item('Income Preference', $this->rangeLabel($criteria->preferred_income_min, $criteria->preferred_income_max), 'income'),
            $this->item('Expectations', $expectations, 'heart'),
        ];

        return $this->section('partner_preferences', 'Partner Preferences', $items);
    }

    private function about(MatrimonyProfile $profile): array
    {
        $body = $this->aboutBody($profile) ?? ProfileDisplayCopy::introSentence($profile);
        $body = $this->cleanString($body);
        $name = $this->cleanString($profile->full_name);

        return [
            'title' => $name !== null
                ? (LocalizedText::isMarathi() ? $name.' बद्दल' : 'About '.$name)
                : null,
            'body' => $body,
        ];
    }

    private function chips(MatrimonyProfile $profile, ?MatrimonyProfile $viewerProfile, int $photoCount): array
    {
        $chips = [];
        $marathi = LocalizedText::isMarathi();
        if ($this->isVerified($profile)) {
            $chips[] = ['label' => $marathi ? 'पडताळणी झालेली' : 'Verified', 'icon' => 'verified', 'tone' => 'trust'];
        }
        if ($this->isPremium($profile)) {
            $chips[] = ['label' => $marathi ? 'प्रीमियम' : 'Premium', 'icon' => 'premium', 'tone' => 'premium'];
        }
        if ($photoCount > 0) {
            $chips[] = ['label' => $marathi ? $photoCount.' फोटो' : $photoCount.' photo'.($photoCount === 1 ? '' : 's'), 'icon' => 'photo', 'tone' => 'neutral'];
        }
        if ($viewerProfile !== null && (int) $viewerProfile->id !== (int) $profile->id) {
            $chips[] = ['label' => $this->comparisonLabel($profile), 'icon' => 'compare', 'tone' => 'dark'];
        }
        if ($profile->horoscope !== null) {
            $chips[] = ['label' => $marathi ? 'ज्योतिष' : 'Astro', 'icon' => 'astro', 'tone' => 'warm'];
        }

        return $chips;
    }

    /**
     * @return array<string, mixed>
     */
    private function contactPayload(MatrimonyProfile $profile, ?MatrimonyProfile $viewerProfile, ?User $viewer): array
    {
        if ($viewer === null || $viewerProfile === null) {
            return $this->contactPayloadState(
                enabled: false,
                state: 'unavailable',
                message: $this->tr('Login and profile are required to view contact options.')
            );
        }

        if ((int) $viewerProfile->id === (int) $profile->id) {
            return $this->contactPayloadState(
                enabled: false,
                state: 'unavailable',
                message: $this->tr('Contact unlock is not available on your own profile.')
            );
        }

        try {
            $profile->loadMissing('user');
            $contactRequestContext = $this->contactRequestContext($profile, $viewer);
            if ($this->isSuchakRoutedProfile($profile)) {
                return $this->contactPayloadState(
                    enabled: true,
                    state: 'unavailable',
                    message: $this->tr('Contact for this profile is handled outside the mobile contact request flow.')
                );
            }

            $contactAccess = app(ContactAccessService::class)->resolveViewerContext(
                $viewer,
                $profile,
                $this->acceptedInterestExists($viewerProfile, $profile),
                $this->profileVisibilitySettings($profile),
                $contactRequestContext['grant_reveal'],
            );
        } catch (Throwable) {
            return $this->contactPayloadState(
                enabled: false,
                state: 'unavailable',
                message: $this->tr('Contact information is not available right now.'),
                maskedPhone: $this->maskedPhoneForDisplay($profile->primary_contact_number),
            );
        }

        return $this->contactPayloadFromAccess($profile, $contactAccess, $contactRequestContext);
    }

    /**
     * @param  array<string, mixed>  $contactAccess
     * @param  array<string, mixed>|null  $contactRequestContext
     * @return array<string, mixed>
     */
    private function contactPayloadFromAccess(MatrimonyProfile $profile, array $contactAccess, ?array $contactRequestContext = null): array
    {
        $phone = $this->cleanString($contactAccess['paid_contact_phone'] ?? null);
        $email = $this->cleanString($contactAccess['paid_contact_email'] ?? null);
        $maskedPhone = $this->maskedPhoneForDisplay($profile->primary_contact_number);
        $showMediator = ($contactAccess['show_mediator_cta'] ?? false) === true;

        if ($phone !== null || $email !== null) {
            return $this->contactPayloadState(
                enabled: true,
                state: 'revealed',
                message: $this->tr('Contact information is available.'),
                phone: $phone,
                email: $email,
                whatsappVisible: $showMediator,
            );
        }

        if (($contactAccess['needs_upgrade'] ?? false) === true) {
            return $this->contactPayloadState(
                enabled: true,
                state: 'upgrade_required',
                message: $this->tr('Upgrade is required to view contact information.'),
                maskedPhone: $maskedPhone,
                primaryCta: $this->contactPrimaryCta($this->tr('Upgrade to View Contact'), 'primary', 'upgrade', false),
                whatsappVisible: $showMediator,
                whatsappMessage: $showMediator ? $this->tr('WhatsApp Response can be shown after eligible access.') : null,
            );
        }

        if (($contactAccess['show_paid_reveal_button'] ?? false) === true) {
            return $this->contactPayloadState(
                enabled: true,
                state: 'unlock_available',
                message: LocalizedText::isMarathi() ? 'या प्रोफाइलसाठी संपर्क माहिती पाहणे उपलब्ध आहे.' : 'Contact unlock is available for this profile.',
                maskedPhone: $maskedPhone,
                primaryCta: $this->contactPrimaryCta($this->tr('View Contact'), 'primary', 'view_contact', true),
                whatsappVisible: $showMediator,
            );
        }

        if (($contactAccess['show_contact_request_rail'] ?? false) === true) {
            $requestPayload = $this->contactRequestPayload($contactRequestContext, $maskedPhone);
            if ($requestPayload !== null) {
                return $requestPayload;
            }
        }

        if ($showMediator) {
            return $this->contactPayloadState(
                enabled: true,
                state: 'whatsapp_response_available',
                message: LocalizedText::isMarathi() ? 'या प्रोफाइलसाठी व्हॉट्सॲप प्रतिसाद उपलब्ध आहे.' : 'WhatsApp Response is available for this profile.',
                maskedPhone: $maskedPhone,
                whatsappVisible: true,
                whatsappMessage: $this->tr('You can request a WhatsApp Response when the mobile action is available.'),
                whatsappEnabled: false,
            );
        }

        if (($contactAccess['paid_reveal_blocked_pending_matchmaking'] ?? false) === true
            || $this->cleanString($contactAccess['reveal_blocked_reason'] ?? null) !== null
            || ($contactAccess['blocked'] ?? false) === true) {
            return $this->contactPayloadState(
                enabled: true,
                state: 'locked',
                message: $this->tr('Contact information is currently locked.'),
                maskedPhone: $maskedPhone,
            );
        }

        return $this->contactPayloadState(
            enabled: true,
            state: 'unavailable',
            message: $this->tr('Contact information is not available for this profile.'),
            maskedPhone: $maskedPhone,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function contactPayloadState(
        bool $enabled,
        string $state,
        ?string $message,
        ?string $phone = null,
        ?string $maskedPhone = null,
        ?string $email = null,
        ?array $primaryCta = null,
        bool $whatsappVisible = false,
        ?string $whatsappMessage = null,
        bool $whatsappEnabled = false,
        ?array $contactRequest = null,
        ?array $requestOptions = null,
    ): array {
        $state = in_array($state, [
            'revealed',
            'locked',
            'unlock_available',
            'upgrade_required',
            'whatsapp_response_available',
            'contact_request_available',
            'contact_request_pending',
            'contact_request_rejected',
            'contact_request_unavailable',
            'unavailable',
        ], true) ? $state : 'unavailable';

        return [
            'enabled' => $enabled,
            'title' => LocalizedText::isMarathi() ? 'संपर्क माहिती' : 'Contact Information',
            'state' => $state,
            'message' => $message,
            'phone' => $phone,
            'masked_phone' => $maskedPhone,
            'email' => $email,
            'primary_cta' => $primaryCta,
            'contact_request' => $contactRequest,
            'request_options' => $requestOptions,
            'whatsapp_response' => [
                'visible' => $whatsappVisible,
                'label' => LocalizedText::isMarathi() ? 'व्हॉट्सॲप प्रतिसाद' : 'WhatsApp Response',
                'message' => $whatsappMessage,
                'enabled' => $whatsappEnabled,
            ],
        ];
    }

    private function maskedPhoneForDisplay(mixed $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw);
        if ($digits === '') {
            return null;
        }

        if (strlen($digits) < 4) {
            return 'XXXX';
        }

        return substr($digits, 0, 4).'XXXX';
    }

    /**
     * @return array{label: string, style: string, action: string, enabled: bool}
     */
    private function contactPrimaryCta(string $label, string $style, string $action, bool $enabled): array
    {
        $style = in_array($style, ['primary', 'secondary', 'disabled'], true) ? $style : 'disabled';
        $action = in_array($action, ['view_contact', 'send_contact_request', 'upgrade', 'none'], true) ? $action : 'none';

        return [
            'label' => $label,
            'style' => $enabled ? $style : 'disabled',
            'action' => $action,
            'enabled' => $enabled,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function contactRequestContext(MatrimonyProfile $profile, User $viewer): array
    {
        $receiver = $profile->user;
        if (! $receiver instanceof User) {
            return [
                'disabled' => true,
                'state' => 'unavailable',
                'request' => null,
                'grant' => null,
                'cooldown_ends_at' => null,
                'can_send' => false,
                'grant_reveal' => null,
            ];
        }

        $service = app(ContactRequestService::class);
        if ($service->isContactRequestDisabled()) {
            return [
                'disabled' => true,
                'state' => 'disabled',
                'request' => null,
                'grant' => null,
                'cooldown_ends_at' => null,
                'can_send' => false,
                'grant_reveal' => null,
            ];
        }

        $senderState = $service->getSenderState($viewer, $receiver);
        $senderState['disabled'] = false;
        $senderState['can_send'] = $service->canSendContactRequest($viewer, $receiver);
        $senderState['grant_reveal'] = $this->contactGrantReveal($profile, $senderState);

        return $senderState;
    }

    /**
     * @param  array<string, mixed>  $contactRequestContext
     * @return array<string, string>|null
     */
    private function contactGrantReveal(MatrimonyProfile $profile, array $contactRequestContext): ?array
    {
        $grant = $contactRequestContext['grant'] ?? null;
        if (! $grant instanceof ContactGrant || ! $grant->isValid()) {
            return null;
        }

        $scopes = array_values(array_map('strval', $grant->granted_scopes ?? []));
        $payload = [];

        if (in_array('phone', $scopes, true) || in_array('whatsapp', $scopes, true)) {
            $phone = $this->cleanString($profile->primary_contact_number);
            if ($phone !== null) {
                $payload['phone'] = $phone;
            }
        }

        if (in_array('email', $scopes, true)) {
            $email = $this->cleanString($profile->user?->email);
            if ($email !== null && ! Str::endsWith(Str::lower($email), '@system.local')) {
                $payload['email'] = $email;
            }
        }

        return $payload === [] ? null : $payload;
    }

    /**
     * @param  array<string, mixed>|null  $contactRequestContext
     * @return array<string, mixed>|null
     */
    private function contactRequestPayload(?array $contactRequestContext, ?string $maskedPhone = null): ?array
    {
        if ($contactRequestContext === null || ($contactRequestContext['disabled'] ?? false) === true) {
            return null;
        }

        $state = $this->cleanString($contactRequestContext['state'] ?? null) ?? 'none';
        $canSend = ($contactRequestContext['can_send'] ?? false) === true;
        $request = $contactRequestContext['request'] ?? null;
        $cooldownEndsAt = $contactRequestContext['cooldown_ends_at'] ?? null;
        $requestMeta = $this->contactRequestMeta($state, $request, $cooldownEndsAt);

        if (in_array($state, ['none', 'expired', 'cancelled'], true) && $canSend) {
            $requestOptions = $this->contactRequestOptionsPayload();
            if (($requestOptions['scopes'] ?? []) === [] || ($requestOptions['reasons'] ?? []) === []) {
                return null;
            }

            return $this->contactPayloadState(
                enabled: true,
                state: 'contact_request_available',
                message: $this->tr('You can send a contact request for this profile.'),
                maskedPhone: $maskedPhone,
                primaryCta: $this->contactPrimaryCta($this->tr('Request Contact'), 'primary', 'send_contact_request', true),
                contactRequest: $requestMeta,
                requestOptions: $requestOptions,
            );
        }

        if ($state === 'pending') {
            return $this->contactPayloadState(
                enabled: true,
                state: 'contact_request_pending',
                message: $this->tr('Your contact request is pending.'),
                maskedPhone: $maskedPhone,
                primaryCta: $this->contactPrimaryCta($this->tr('Request Sent'), 'disabled', 'none', false),
                contactRequest: $requestMeta,
            );
        }

        if ($state === 'rejected') {
            $message = $this->tr('Your contact request was rejected.');
            if ($cooldownEndsAt instanceof \DateTimeInterface) {
                $message .= LocalizedText::isMarathi()
                    ? ' प्रतीक्षा कालावधी '.$cooldownEndsAt->format('M j, Y').' रोजी संपेल.'
                    : ' Cooling period ends on '.$cooldownEndsAt->format('M j, Y').'.';
            }

            return $this->contactPayloadState(
                enabled: true,
                state: 'contact_request_rejected',
                message: $message,
                maskedPhone: $maskedPhone,
                contactRequest: $requestMeta,
            );
        }

        if (in_array($state, ['revoked', 'accepted'], true)) {
            return $this->contactPayloadState(
                enabled: true,
                state: 'contact_request_unavailable',
                message: $this->tr('Contact request is not available for this profile.'),
                maskedPhone: $maskedPhone,
                contactRequest: $requestMeta,
            );
        }

        return $this->contactPayloadState(
            enabled: true,
            state: 'contact_request_unavailable',
            message: $this->tr('Contact request is available only after accepted interest.'),
            maskedPhone: $maskedPhone,
            contactRequest: $requestMeta,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function contactRequestMeta(string $state, mixed $request, mixed $cooldownEndsAt): array
    {
        return [
            'state' => $state,
            'id' => $request instanceof ContactRequest ? $request->id : null,
            'status' => $request instanceof ContactRequest ? $request->status : null,
            'expires_at' => $request instanceof ContactRequest ? $this->dateString($request->expires_at) : null,
            'cooldown_ends_at' => $this->dateString($cooldownEndsAt),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function contactRequestOptionsPayload(): array
    {
        return [
            'reasons' => $this->contactRequestReasonOptions(),
            'scopes' => $this->contactRequestScopeOptions(),
            'default_scopes' => [],
        ];
    }

    private function contactRequestReasonOptions(): array
    {
        $config = CommunicationPolicyService::getConfig();

        return collect($config['request_reasons'] ?? [])
            ->map(fn ($label, $key): array => [
                'key' => (string) $key,
                'label' => (string) $label,
            ])
            ->values()
            ->all();
    }

    private function contactRequestScopeOptions(): array
    {
        $config = CommunicationPolicyService::getConfig();
        $scopes = array_keys(array_filter($config['allowed_contact_scopes'] ?? []));

        return collect($scopes)
            ->map(fn (string $key): array => [
                'key' => $key,
                'label' => $this->tr(match ($key) {
                    'email' => 'Email',
                    'phone' => 'Phone',
                    'whatsapp' => 'WhatsApp',
                    default => Str::headline($key),
                }),
            ])
            ->values()
            ->all();
    }

    private function isSuchakRoutedProfile(MatrimonyProfile $profile): bool
    {
        if (! Schema::hasTable('suchak_profile_representations')) {
            return false;
        }

        $publiclyRoutableSuchakQuery = SuchakProfileRepresentation::query()
            ->publiclyRoutable()
            ->where('matrimony_profile_id', $profile->id);

        if ((clone $publiclyRoutableSuchakQuery)
            ->whereIn('representation_mode', SuchakProfileRepresentation::SUCHAK_CREATED_MODES)
            ->exists()) {
            return true;
        }

        if (! (clone $publiclyRoutableSuchakQuery)->exists()
            || ! Schema::hasTable('profile_visibility_settings')
            || ! Schema::hasColumn('profile_visibility_settings', 'contact_routing_mode')) {
            return false;
        }

        $mode = DB::table('profile_visibility_settings')
            ->where('profile_id', $profile->id)
            ->value('contact_routing_mode');

        return ProfileVisibilitySetting::normalizeContactRoutingMode(is_string($mode) ? $mode : null)
            === ProfileVisibilitySetting::CONTACT_ROUTING_SUCHAK_ONLY;
    }

    private function dateString(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return $this->cleanString($value);
    }

    private function acceptedInterestExists(MatrimonyProfile $viewerProfile, MatrimonyProfile $targetProfile): bool
    {
        if (! Schema::hasTable('interests')) {
            return false;
        }

        return Interest::query()
            ->where('status', 'accepted')
            ->where(function ($query) use ($viewerProfile, $targetProfile): void {
                $query->where(function ($inner) use ($viewerProfile, $targetProfile): void {
                    $inner->where('sender_profile_id', $viewerProfile->id)
                        ->where('receiver_profile_id', $targetProfile->id);
                })->orWhere(function ($inner) use ($viewerProfile, $targetProfile): void {
                    $inner->where('sender_profile_id', $targetProfile->id)
                        ->where('receiver_profile_id', $viewerProfile->id);
                });
            })
            ->exists();
    }

    private function profileVisibilitySettings(MatrimonyProfile $profile): ?object
    {
        if (! Schema::hasTable('profile_visibility_settings')) {
            return null;
        }

        return DB::table('profile_visibility_settings')
            ->where('profile_id', $profile->id)
            ->first();
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
            'interest_sent' => $alreadyInterested,
            'can_report' => $canInteract && Schema::hasTable('abuse_reports'),
            'can_shortlist' => $canAct && $hasShortlists && ! $isShortlisted,
            'can_hide' => $canAct && $hasHiddenProfiles && ! $isHidden,
            'can_block' => $canAct && $hasBlocks && ! $isBlocked,
            'is_shortlisted' => $isShortlisted,
            'is_hidden' => $isHidden,
            'is_blocked' => $isBlocked,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function chatPayload(MatrimonyProfile $profile, ?MatrimonyProfile $viewerProfile): array
    {
        if ($viewerProfile === null) {
            return $this->chatPayloadState(
                enabled: false,
                state: 'unavailable',
                message: $this->tr('Login and profile are required to use chat.')
            );
        }

        if ((int) $viewerProfile->id === (int) $profile->id) {
            return $this->chatPayloadState(
                enabled: false,
                state: 'unavailable',
                message: $this->tr('Chat is not available on your own profile.'),
                reason: 'same_profile'
            );
        }

        try {
            $conversation = app(ChatConversationService::class)
                ->findConversationBetweenProfiles((int) $viewerProfile->id, (int) $profile->id);
            $policy = app(ChatPolicyService::class);

            if ($conversation !== null) {
                $access = $policy->canAccessMessaging($viewerProfile, $profile);
                if (! $access->allowed) {
                    return $this->chatPayloadFromDecision($access);
                }

                return $this->chatPayloadState(
                    enabled: true,
                    state: 'available',
                    message: $this->tr('Chat is available.'),
                    action: [
                        'label' => LocalizedText::isMarathi() ? 'चॅट' : 'Chat',
                        'action' => 'open_chat',
                        'enabled' => true,
                    ],
                    conversationId: (int) $conversation->id,
                    canSend: $this->chatPolicyPayload($policy->canSendMessage($viewerProfile, $profile, $conversation))
                );
            }

            $decision = $policy->canStartConversation($viewerProfile, $profile);
            if (! $decision->allowed) {
                return $this->chatPayloadFromDecision($decision);
            }

            return $this->chatPayloadState(
                enabled: true,
                state: 'available',
                message: $this->tr('Chat is available.'),
                action: [
                    'label' => LocalizedText::isMarathi() ? 'चॅट' : 'Chat',
                    'action' => 'start_chat',
                    'enabled' => true,
                ],
                canSend: $this->chatPolicyPayload($decision)
            );
        } catch (Throwable) {
            return $this->chatPayloadState(
                enabled: false,
                state: 'unavailable',
                message: $this->tr('Chat is not available right now.')
            );
        }
    }

    private function chatPayloadFromDecision(PolicyDecision $decision): array
    {
        return $this->chatPayloadState(
            enabled: true,
            state: 'locked',
            message: $decision->humanMessage !== '' ? $decision->humanMessage : $this->tr('Chat is not available.'),
            action: [
                'label' => LocalizedText::isMarathi() ? 'चॅट' : 'Chat',
                'action' => 'chat_locked',
                'enabled' => false,
            ],
            reason: $decision->code,
            lockedUntil: $decision->lockedUntil,
            canSend: $this->chatPolicyPayload($decision)
        );
    }

    /**
     * @param  array<string, mixed>|null  $action
     * @param  array<string, mixed>|null  $canSend
     * @return array<string, mixed>
     */
    private function chatPayloadState(
        bool $enabled,
        string $state,
        ?string $message,
        ?array $action = null,
        ?int $conversationId = null,
        ?string $reason = null,
        ?\DateTimeInterface $lockedUntil = null,
        ?array $canSend = null,
    ): array {
        $state = in_array($state, ['available', 'locked', 'unavailable'], true) ? $state : 'unavailable';

        return [
            'enabled' => $enabled,
            'state' => $state,
            'message' => $message,
            'action' => $action,
            'conversation_id' => $conversationId,
            'reason' => $reason,
            'locked_until' => $lockedUntil?->format(DATE_ATOM),
            'can_send' => $canSend,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function chatPolicyPayload(PolicyDecision $decision): array
    {
        return [
            'allowed' => $decision->allowed,
            'code' => $decision->code,
            'message' => $this->cleanString($decision->humanMessage),
            'locked_until' => $decision->lockedUntil?->format(DATE_ATOM),
            'meta' => $decision->meta,
        ];
    }

    /**
     * Marathi titles for the fixed sections. The English literal at each call
     * site stays the base/fallback (always present); when the request locale is
     * Marathi and the section key is listed here, the Marathi title is used. A
     * key absent here — e.g. `partner_match`, whose title is already localized
     * upstream in comparisonLabel() — keeps whatever title the caller passed.
     */
    private const SECTION_TITLES_MR = [
        'basic' => 'मूलभूत माहिती',
        'family' => 'कौटुंबिक माहिती',
        'career_education' => 'करिअर आणि शिक्षण',
        'astro' => 'ज्योतिष / गुणमिलन',
        'partner_preferences' => 'जोडीदाराच्या अपेक्षा',
    ];

    private function section(string $key, string $title, array $items): ?array
    {
        $items = array_values(array_filter($items));
        if ($items === []) {
            return null;
        }

        if (LocalizedText::isMarathi() && isset(self::SECTION_TITLES_MR[$key])) {
            $title = self::SECTION_TITLES_MR[$key];
        }

        return [
            'key' => $key,
            'title' => $title,
            'items' => $items,
        ];
    }

    /**
     * Marathi for every fixed field label the detail response carries. The
     * English literal passed at each ->item() call site stays the base/fallback
     * (always present); when the request locale is Marathi and the label is
     * listed here, the Marathi label is used. A label absent here falls back to
     * its English — no blank, no crash — which is exactly the agreed rule.
     */
    private const ITEM_LABELS_MR = [
        'Address Line' => 'पत्ता',
        'Age' => 'वय',
        'Age Preference' => 'वयाची अपेक्षा',
        'Alliance Network' => 'सोयरिक नेटवर्क',
        'Annual Income' => 'वार्षिक उत्पन्न',
        'Birth Date' => 'जन्मतारीख',
        'Birth Place' => 'जन्मस्थान',
        'Birth Time' => 'जन्मवेळ',
        'Blood Group' => 'रक्तगट',
        'Children' => 'अपत्ये',
        'Community' => 'समाज',
        'Company Name' => 'कंपनीचे नाव',
        'Complexion' => 'वर्ण',
        'Devak' => 'देवक',
        'Diet' => 'आहार',
        'Drinking' => 'मद्यपान',
        'Expectations' => 'अपेक्षा',
        'Family Income' => 'कौटुंबिक उत्पन्न',
        'Family Type' => 'कुटुंब प्रकार',
        'Gotra' => 'गोत्र',
        'Height' => 'उंची',
        'Height Preference' => 'उंचीची अपेक्षा',
        'Highest Education' => 'सर्वोच्च शिक्षण',
        'Income Preference' => 'उत्पन्नाची अपेक्षा',
        'Intercaste' => 'आंतरजातीय',
        'Lives in' => 'राहण्याचे ठिकाण',
        'Mangal Dosh' => 'मंगळ दोष',
        'Marital Status' => 'वैवाहिक स्थिती',
        'Marriage History' => 'विवाह इतिहास',
        'Marriage Type Preference' => 'विवाह प्रकाराची अपेक्षा',
        'Mother Tongue' => 'मातृभाषा',
        'Nakshatra' => 'नक्षत्र',
        'Occupation' => 'व्यवसाय',
        'Other Relatives' => 'इतर नातेवाईक',
        'Parents Details' => 'पालकांची माहिती',
        'Partner with Children' => 'अपत्य असलेला जोडीदार',
        'Physical Build' => 'शरीरयष्टी',
        'Physical Condition' => 'शारीरिक स्थिती',
        'Preferred Castes' => 'पसंतीच्या जाती',
        'Preferred City' => 'पसंतीचे शहर',
        'Preferred Diet' => 'पसंतीचा आहार',
        'Preferred Education' => 'पसंतीचे शिक्षण',
        'Preferred Marital Status' => 'पसंतीची वैवाहिक स्थिती',
        'Preferred Occupation' => 'पसंतीचा व्यवसाय',
        'Preferred Religions' => 'पसंतीचे धर्म',
        'Profile ID' => 'प्रोफाइल आयडी',
        'Profile Managed By' => 'प्रोफाइल व्यवस्थापक',
        'Property Details' => 'मालमत्तेची माहिती',
        'Rashi' => 'राशी',
        'Relatives' => 'नातेवाईक',
        'Religion' => 'धर्म',
        'Siblings' => 'भावंडे',
        'Smoking' => 'धूम्रपान',
        'Spectacles / Lens' => 'चष्मा / लेन्स',
        'Weight' => 'वजन',
        'Willing to Relocate' => 'स्थलांतरास तयार',
        'Work Location' => 'कामाचे ठिकाण',
    ];

    /**
     * Marathi for every fixed, full-string user-visible display token the detail
     * response emits outside the label/section maps — comparison row labels and
     * values, gunamilan/contact/chat messages, CTA labels, status badges, and
     * match/relation/gender/managed-by enum labels. The English literal at each
     * emission site stays the base/fallback; tr() swaps in the Marathi only when
     * the request locale is Marathi and the exact string is listed here. Keys are
     * the exact English display strings, never internal enum/array keys.
     */
    private const TR_MR = [
        // Comparison row labels
        'Age' => 'वय',
        'Height' => 'उंची',
        'Location' => 'ठिकाण',
        'Religion / Caste' => 'धर्म / जात',
        'Same sub-caste' => 'समान पोटजात',
        'Education' => 'शिक्षण',
        'Income' => 'उत्पन्न',
        'Gunamilan' => 'गुणमिलन',
        'Compatible' => 'सुसंगत',
        // Comparison names & titles
        'You' => 'तुम्ही',
        'Profile' => 'प्रोफाइल',
        'You & Profile' => 'तुम्ही आणि प्रोफाइल',
        // Comparison status badges
        'Strong' => 'भक्कम',
        'Match' => 'जुळते',
        'Near' => 'जवळपास',
        'Basic' => 'मूलभूत',
        'Not matched' => 'जुळत नाही',
        'Review' => 'तपासा',
        // Gunamilan block
        'Gunamilan / Horoscope Match' => 'गुणमिलन / कुंडली जुळणी',
        'Horoscope data is incomplete.' => 'कुंडली माहिती अपूर्ण आहे.',
        'Gunamilan is only a compatibility reference. Families should make the final decision after discussion.' => 'गुणमिलन हा केवळ सुसंगततेचा संदर्भ आहे. कुटुंबांनी चर्चेनंतर अंतिम निर्णय घ्यावा.',
        'Create your profile to view horoscope compatibility.' => 'कुंडली सुसंगतता पाहण्यासाठी तुमचे प्रोफाइल तयार करा.',
        'Gunamilan is shown for another matched profile.' => 'गुणमिलन दुसऱ्या जुळलेल्या प्रोफाइलसाठी दाखवले जाते.',
        'Your horoscope data is incomplete.' => 'तुमची कुंडली माहिती अपूर्ण आहे.',
        'This profile has incomplete horoscope data.' => 'या प्रोफाइलची कुंडली माहिती अपूर्ण आहे.',
        'Full match' => 'पूर्ण जुळणी',
        'Missing data' => 'माहिती उपलब्ध नाही',
        'Partial match' => 'अर्धवट जुळणी',
        // Contact block
        'Login and profile are required to view contact options.' => 'संपर्क पर्याय पाहण्यासाठी लॉगिन व प्रोफाइल आवश्यक आहे.',
        'Contact unlock is not available on your own profile.' => 'स्वतःच्या प्रोफाइलवर संपर्क अनलॉक उपलब्ध नाही.',
        'Contact for this profile is handled outside the mobile contact request flow.' => 'या प्रोफाइलचा संपर्क मोबाईल संपर्क विनंती प्रक्रियेबाहेर हाताळला जातो.',
        'Contact information is not available right now.' => 'संपर्क माहिती सध्या उपलब्ध नाही.',
        'Contact information is available.' => 'संपर्क माहिती उपलब्ध आहे.',
        'Upgrade is required to view contact information.' => 'संपर्क माहिती पाहण्यासाठी अपग्रेड आवश्यक आहे.',
        'Upgrade to View Contact' => 'संपर्क पाहण्यासाठी अपग्रेड करा',
        'WhatsApp Response can be shown after eligible access.' => 'पात्र प्रवेशानंतर व्हॉट्सॲप प्रतिसाद दाखवता येईल.',
        'View Contact' => 'संपर्क पाहा',
        'You can request a WhatsApp Response when the mobile action is available.' => 'मोबाईल क्रिया उपलब्ध असल्यास तुम्ही व्हॉट्सॲप प्रतिसादाची विनंती करू शकता.',
        'Contact information is currently locked.' => 'संपर्क माहिती सध्या लॉक केलेली आहे.',
        'Contact information is not available for this profile.' => 'या प्रोफाइलसाठी संपर्क माहिती उपलब्ध नाही.',
        'You can send a contact request for this profile.' => 'तुम्ही या प्रोफाइलसाठी संपर्क विनंती पाठवू शकता.',
        'Request Contact' => 'संपर्काची विनंती करा',
        'Your contact request is pending.' => 'तुमची संपर्क विनंती प्रलंबित आहे.',
        'Request Sent' => 'विनंती पाठवली',
        'Your contact request was rejected.' => 'तुमची संपर्क विनंती नाकारली गेली.',
        'Contact request is not available for this profile.' => 'या प्रोफाइलसाठी संपर्क विनंती उपलब्ध नाही.',
        'Contact request is available only after accepted interest.' => 'स्वीकृत स्वारस्यानंतरच संपर्क विनंती उपलब्ध होते.',
        'Email' => 'ईमेल',
        'Phone' => 'फोन',
        // Chat block
        'Login and profile are required to use chat.' => 'चॅट वापरण्यासाठी लॉगिन व प्रोफाइल आवश्यक आहे.',
        'Chat is not available on your own profile.' => 'स्वतःच्या प्रोफाइलवर चॅट उपलब्ध नाही.',
        'Chat is available.' => 'चॅट उपलब्ध आहे.',
        'Chat is not available right now.' => 'चॅट सध्या उपलब्ध नाही.',
        'Chat is not available.' => 'चॅट उपलब्ध नाही.',
        // Item values
        'Open to intercaste' => 'आंतरजातीय विवाहास तयार',
        'Hidden' => 'लपवलेले',
        'Yes' => 'होय',
        'No' => 'नाही',
        // Relative relation labels
        'Paternal Grandfather' => 'आजोबा (वडिलांचे वडील)',
        'Paternal Grandmother' => 'आजी (वडिलांची आई)',
        'Paternal Uncle' => 'काका',
        'Wife of Paternal Uncle' => 'काकू',
        'Paternal Aunt' => 'आत्या',
        'Husband of Paternal Aunt' => 'आत्याचे पती',
        'Cousin' => 'चुलत/मामे भावंड',
        'Maternal address (Ajol)' => 'आजोळ',
        'Maternal Grandfather' => 'आजोबा (आईचे वडील)',
        'Maternal Grandmother' => 'आजी (आईची आई)',
        'Maternal Uncle' => 'मामा',
        "Maternal Uncle's wife" => 'मामी',
        'Maternal Aunt' => 'मावशी',
        'Husband of Maternal Aunt' => 'मावशीचे पती',
        // Marriage / divorce status labels
        'Pending' => 'प्रलंबित',
        'Finalized' => 'अंतिम',
        'Mutual' => 'परस्पर संमतीने',
        'Contested' => 'विवादित',
        // Child gender labels
        'Male' => 'पुरुष',
        'Female' => 'स्त्री',
        'Other' => 'इतर',
        'Prefer not to say' => 'सांगू इच्छित नाही',
        // Managed-by labels
        'Self' => 'स्वतः',
        'Parent / Guardian' => 'पालक',
        'Sibling' => 'भावंड',
        'Relative' => 'नातेवाईक',
        'Friend' => 'मित्र',
        // With-children labels
        'Yes, if living separately' => 'होय, वेगळे राहत असल्यास',
    ];

    private function tr(?string $en): ?string
    {
        return ($en !== null && LocalizedText::isMarathi() && isset(self::TR_MR[$en])) ? self::TR_MR[$en] : $en;
    }

    private function item(string $label, mixed $value, ?string $icon = null, bool $locked = false): ?array
    {
        $displayValue = $locked ? $this->cleanString($value) : $this->cleanDisplayValue($value);
        if ($displayValue === null) {
            return null;
        }

        if (LocalizedText::isMarathi() && isset(self::ITEM_LABELS_MR[$label])) {
            $label = self::ITEM_LABELS_MR[$label];
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
            foreach ($this->orderedLabelKeys() as $key) {
                $label = $this->cleanString($value->getAttribute($key));
                if ($label !== null) {
                    return $label;
                }
            }

            return null;
        }

        if (is_array($value)) {
            foreach ($this->orderedLabelKeys() as $key) {
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

    private function translatedOptionLabel(string $baseKey, mixed $value): ?string
    {
        $key = $this->cleanString($value);
        if ($key === null) {
            return null;
        }

        $translationKey = $baseKey.'.'.$key;
        $label = __($translationKey);
        if ($label !== $translationKey) {
            return $this->cleanString($label);
        }

        return Str::headline(str_replace('_', ' ', $key));
    }

    private function cleanDisplayValue(mixed $value): ?string
    {
        if (is_array($value) || is_object($value)) {
            return $this->labelFrom($value);
        }
        if (is_bool($value)) {
            return $this->tr($value ? 'Yes' : 'No');
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
            return $this->tr($value ? 'Yes' : 'No');
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
            $age = Carbon::parse($profile->date_of_birth)->age;

            return $age > 0 ? $age : null;
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
            $father !== null ? (LocalizedText::isMarathi() ? 'वडील: ' : 'Father: ').$father : null,
            $mother !== null ? (LocalizedText::isMarathi() ? 'आई: ' : 'Mother: ').$mother : null,
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
            $brothers > 0 ? (LocalizedText::isMarathi() ? $brothers.' भाऊ' : $brothers.' Brother'.($brothers === 1 ? '' : 's')) : null,
            $sisters > 0 ? (LocalizedText::isMarathi() ? $sisters.' बहीण' : $sisters.' Sister'.($sisters === 1 ? '' : 's')) : null,
            $others > 0 ? (LocalizedText::isMarathi() ? $others.' भावंड' : $others.' Sibling'.($others === 1 ? '' : 's')) : null,
        ]);
    }

    private function marriageHistoryLabel(MatrimonyProfile $profile, ?string $statusKey): ?string
    {
        $marriages = $profile->marriages;
        if ($marriages === null || $marriages->isEmpty()) {
            return null;
        }

        $rows = [];
        foreach ($marriages->sortByDesc('id')->take(1) as $marriage) {
            $row = $this->joinLabels([
                $marriage->marriage_year !== null ? (LocalizedText::isMarathi() ? 'विवाह ' : 'Marriage ').$marriage->marriage_year : null,
                $statusKey === 'separated' && $marriage->separation_year !== null ? (LocalizedText::isMarathi() ? 'विभक्त ' : 'Separated ').$marriage->separation_year : null,
                in_array($statusKey, ['divorced', 'annulled'], true) && $marriage->divorce_year !== null ? (LocalizedText::isMarathi() ? ($statusKey === 'annulled' ? 'विवाह रद्द ' : 'घटस्फोट ') : ($statusKey === 'annulled' ? 'Annulment ' : 'Divorce ')).$marriage->divorce_year : null,
                $statusKey === 'widowed' && $marriage->spouse_death_year !== null ? (LocalizedText::isMarathi() ? 'जोडीदाराचे निधन ' : 'Spouse death ').$marriage->spouse_death_year : null,
                in_array($statusKey, ['divorced', 'annulled', 'separated'], true) ? $this->marriageDivorceStatusLabel($marriage->divorce_status) : null,
            ], ' - ');
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows !== [] ? implode('; ', $rows) : null;
    }

    private function childrenLabel(MatrimonyProfile $profile): ?string
    {
        $children = $profile->children;
        if ($children === null || $children->isEmpty()) {
            return null;
        }

        $rows = [];
        foreach ($children->take(3) as $index => $child) {
            $row = $this->joinLabels([
                $this->cleanString($child->child_name) ?? (LocalizedText::isMarathi() ? 'अपत्य ' : 'Child ').($index + 1),
                $child->age !== null ? $child->age.(LocalizedText::isMarathi() ? ' वर्षे' : ' years') : null,
                $this->childGenderLabel($child->gender),
                $this->labelFrom($child->childLivingWith),
            ], ' - ');
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        $remaining = $children->count() - count($rows);
        if ($remaining > 0) {
            $rows[] = LocalizedText::isMarathi() ? 'आणखी '.$remaining : '+'.$remaining.' more';
        }

        return $rows !== [] ? implode('; ', $rows) : null;
    }

    private function relativesLabel(MatrimonyProfile $profile): ?string
    {
        $relatives = $profile->relatives;
        if ($relatives === null || $relatives->isEmpty()) {
            return null;
        }

        $rows = [];
        foreach ($relatives->take(3) as $relative) {
            $relation = $this->relativeRelationTypeLabel($relative->relation_type)
                ?? $this->cleanString($relative->relation_type);

            $row = $this->joinLabels([
                $relation,
                $this->cleanString($relative->relative_details),
            ], ' - ');
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        $remaining = $relatives->count() - count($rows);
        if ($remaining > 0) {
            $rows[] = LocalizedText::isMarathi() ? 'आणखी '.$remaining : '+'.$remaining.' more';
        }

        return $rows !== [] ? implode('; ', $rows) : null;
    }

    private function allianceNetworkLabel(MatrimonyProfile $profile): ?string
    {
        $allianceNetworks = $profile->allianceNetworks;
        if ($allianceNetworks === null || $allianceNetworks->isEmpty()) {
            return null;
        }

        $rows = [];
        foreach ($allianceNetworks->take(3) as $allianceNetwork) {
            $location = $this->joinLabels([
                $this->labelFrom($allianceNetwork->city),
                $this->labelFrom($allianceNetwork->taluka),
                $this->labelFrom($allianceNetwork->district),
                $this->labelFrom($allianceNetwork->state),
            ]);

            $row = $this->joinLabels([
                $this->cleanString($allianceNetwork->surname),
                $location,
            ], ' - ');
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        $remaining = $allianceNetworks->count() - count($rows);
        if ($remaining > 0) {
            $rows[] = LocalizedText::isMarathi() ? 'आणखी '.$remaining : '+'.$remaining.' more';
        }

        return $rows !== [] ? implode('; ', $rows) : null;
    }

    private function relativeRelationTypeLabel(?string $value): ?string
    {
        return $this->tr(match ($value) {
            'paternal_grandfather' => 'Paternal Grandfather',
            'paternal_grandmother' => 'Paternal Grandmother',
            'paternal_uncle' => 'Paternal Uncle',
            'wife_paternal_uncle' => 'Wife of Paternal Uncle',
            'paternal_aunt' => 'Paternal Aunt',
            'husband_paternal_aunt' => 'Husband of Paternal Aunt',
            'Cousin' => 'Cousin',
            'maternal_address_ajol' => 'Maternal address (Ajol)',
            'maternal_grandfather' => 'Maternal Grandfather',
            'maternal_grandmother' => 'Maternal Grandmother',
            'maternal_uncle' => 'Maternal Uncle',
            'wife_maternal_uncle' => "Maternal Uncle's wife",
            'maternal_aunt' => 'Maternal Aunt',
            'husband_maternal_aunt' => 'Husband of Maternal Aunt',
            'maternal_cousin' => 'Cousin',
            default => null,
        });
    }

    private function marriageDivorceStatusLabel(?string $value): ?string
    {
        return $this->tr(match ($value) {
            'pending' => 'Pending',
            'finalized' => 'Finalized',
            'mutual' => 'Mutual',
            'contested' => 'Contested',
            default => null,
        });
    }

    private function childGenderLabel(?string $value): ?string
    {
        return $this->tr(match ($value) {
            'male' => 'Male',
            'female' => 'Female',
            'other' => 'Other',
            'prefer_not_say' => 'Prefer not to say',
            default => null,
        });
    }

    private function incomeItem(MatrimonyProfile $profile): ?array
    {
        if ((bool) ($profile->income_private ?? false)) {
            return $this->item('Annual Income', $this->tr('Hidden'), 'income', true);
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
            return $this->item('Family Income', $this->tr('Hidden'), 'income', true);
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
        if ($profile->relationLoaded('photos')) {
            $profile->photos
                ->filter(fn (ProfilePhoto $photo): bool => $photo->effectiveApprovedStatus() === 'approved')
                ->each(function (ProfilePhoto $photo) use ($profile, &$urls): void {
                    $path = ProfilePhotoUrlService::normalizeMatrimonyPhotoPath((string) $photo->file_path);
                    if ($path === null || ProfilePhotoUrlService::isPendingPlaceholder($path)) {
                        return;
                    }
                    if (! ProfilePhotoUrlService::storedFileExistsForRelativePath($path)) {
                        return;
                    }
                    $urls[] = app(ProfilePhotoUrlService::class)->publicUrl($path, $profile);
                });
        } elseif (Schema::hasTable('profile_photos')) {
            ProfilePhoto::query()
                ->where('profile_id', $profile->id)
                ->effectivelyApproved()
                ->ordered()
                ->get(['file_path'])
                ->each(function (ProfilePhoto $photo) use ($profile, &$urls): void {
                    $path = ProfilePhotoUrlService::normalizeMatrimonyPhotoPath((string) $photo->file_path);
                    if ($path === null || ProfilePhotoUrlService::isPendingPlaceholder($path)) {
                        return;
                    }
                    if (! ProfilePhotoUrlService::storedFileExistsForRelativePath($path)) {
                        return;
                    }
                    $urls[] = app(ProfilePhotoUrlService::class)->publicUrl($path, $profile);
                });
        }

        $legacy = ProfilePhotoUrlService::normalizeMatrimonyPhotoPath((string) ($profile->profile_photo ?? ''));
        if (
            $legacy !== null
            && $profile->photo_approved !== false
            && ! ProfilePhotoUrlService::isPendingPlaceholder($legacy)
            && ProfilePhotoUrlService::storedFileExistsForRelativePath($legacy)
        ) {
            $urls[] = app(ProfilePhotoUrlService::class)->publicUrl($legacy, $profile);
        }

        $urls = array_values(array_unique(array_filter($urls)));

        return [count($urls), $urls[0] ?? null];
    }

    /**
     * @return array{
     *     slots: list<array{url: string, blur: bool}>,
     *     message_key: ?string,
     *     message: ?string,
     *     tier: string,
     *     photo_count: int,
     *     primary_photo_url: ?string,
     *     has_locked_photos: bool
     * }
     */
    private function photoAlbumPayload(MatrimonyProfile $profile, ?User $viewer, bool $isOwnProfile): array
    {
        $presentation = $viewer instanceof User
            ? app(ProfilePhotoAccessService::class)->buildAlbumPresentation(
                $viewer,
                $profile,
                $isOwnProfile,
                $this->approvedGalleryPhotoRows($profile)
            )
            : [
                'slots' => [],
                'message_key' => null,
                'tier' => 'guest',
            ];

        $slots = [];
        $seen = [];
        foreach (($presentation['slots'] ?? []) as $slot) {
            if (! is_array($slot)) {
                continue;
            }

            $url = trim((string) ($slot['url'] ?? ''));
            if ($url === '' || isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;
            $slots[] = [
                'url' => $url,
                'blur' => (bool) ($slot['blur'] ?? false),
            ];
        }

        $messageKey = is_string($presentation['message_key'] ?? null)
            ? $presentation['message_key']
            : null;

        return [
            'slots' => $slots,
            'message_key' => $messageKey,
            'message' => $messageKey !== null ? __($messageKey) : null,
            'tier' => (string) ($presentation['tier'] ?? 'guest'),
            'photo_count' => count($slots),
            'primary_photo_url' => $slots[0]['url'] ?? null,
            'has_locked_photos' => collect($slots)->contains(fn (array $slot): bool => (bool) $slot['blur']),
        ];
    }

    private function approvedGalleryPhotoRows(MatrimonyProfile $profile): \Illuminate\Support\Collection
    {
        if (! Schema::hasTable('profile_photos')) {
            return collect();
        }

        $query = ProfilePhoto::query()
            ->where('profile_id', $profile->id)
            ->effectivelyApproved();

        if (Schema::hasColumn('profile_photos', 'sort_order')) {
            $query->orderByDesc('is_primary')->orderBy('sort_order')->orderBy('id');
        } else {
            $query->orderByDesc('is_primary')->orderByDesc('created_at')->orderBy('id');
        }

        return $query->get([
            'id',
            'profile_id',
            'file_path',
            'is_primary',
        ]);
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
            $subscription = $profile->user->relationLoaded('activeSubscription')
                ? $profile->user->activeSubscription
                : Subscription::query()
                    ->where('user_id', $profile->user->id)
                    ->effectivelyActiveForAccess()
                    ->with('plan')
                    ->orderByDesc('starts_at')
                    ->orderByDesc('id')
                    ->first();
            if ($subscription === null) {
                return false;
            }
            $subscription->loadMissing('plan');
            $slug = $subscription->plan?->slug;

            return $slug !== null && ! Plan::isFreeCatalogSlug((string) $slug);
        } catch (\Throwable) {
            return false;
        }
    }

    private function comparisonLabel(MatrimonyProfile $profile): string
    {
        $gender = mb_strtolower(trim((string) ($this->labelFrom($profile->gender) ?? '')));
        $marathi = LocalizedText::isMarathi();

        if (str_contains($gender, 'female') || str_contains($gender, 'स्त्री') || str_contains($gender, 'महिला')) {
            return $marathi ? 'तुम्ही आणि ती' : 'You & Her';
        }
        if (str_contains($gender, 'male') || str_contains($gender, 'पुरुष')) {
            return $marathi ? 'तुम्ही आणि तो' : 'You & Him';
        }

        return $marathi ? 'तुम्ही आणि प्रोफाइल' : 'You & Profile';
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

    private function partnerPreferencePivotLabels(MatrimonyProfile $profile, string $pivotTable, string $pivotColumn, string $masterTable): ?string
    {
        if (! Schema::hasTable($pivotTable) || ! Schema::hasTable($masterTable)) {
            return null;
        }

        $ids = DB::table($pivotTable)
            ->where('profile_id', $profile->id)
            ->orderBy($pivotColumn)
            ->pluck($pivotColumn)
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        if ($ids === []) {
            return null;
        }

        $rows = DB::table($masterTable)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
        $labels = [];
        foreach ($ids as $id) {
            $row = $rows->get($id);
            if ($row === null) {
                continue;
            }
            foreach ($this->orderedLabelKeys() as $key) {
                if (property_exists($row, $key)) {
                    $value = $this->cleanString($row->{$key} ?? null);
                    if ($value !== null) {
                        $labels[] = $value;
                        break;
                    }
                }
            }
        }

        return $this->joinLabels($labels);
    }

    private function extendedNarrativeExpectations(MatrimonyProfile $profile): ?string
    {
        if (! Schema::hasTable('profile_extended_attributes')) {
            return null;
        }

        $value = DB::table('profile_extended_attributes')
            ->where('profile_id', $profile->id)
            ->value('narrative_expectations');

        return $this->cleanString($value);
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
            return $min.$suffix.(LocalizedText::isMarathi() ? ' व त्याहून अधिक' : ' and above');
        }

        return LocalizedText::isMarathi() ? $max.$suffix.' पर्यंत' : 'Up to '.$max.$suffix;
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
            return HeightDisplay::formatFeetInches($min).(LocalizedText::isMarathi() ? ' व त्याहून अधिक' : ' and above');
        }

        return LocalizedText::isMarathi()
            ? HeightDisplay::formatFeetInches((int) $max).' पर्यंत'
            : 'Up to '.HeightDisplay::formatFeetInches((int) $max);
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
        return $this->tr(match ($value) {
            'self' => 'Self',
            'parent_guardian' => 'Parent / Guardian',
            'sibling' => 'Sibling',
            'relative' => 'Relative',
            'friend' => 'Friend',
            'other' => 'Other',
            default => $this->cleanString($value),
        });
    }

    private function withChildrenLabel(?string $value): ?string
    {
        return $this->tr(match ($value) {
            'no' => 'No',
            'yes_if_live_separate' => 'Yes, if living separately',
            'yes' => 'Yes',
            default => $this->cleanString($value),
        });
    }
}
