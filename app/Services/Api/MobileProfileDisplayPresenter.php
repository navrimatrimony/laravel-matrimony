<?php

namespace App\Services\Api;

use App\Models\Block;
use App\Models\EducationDegree;
use App\Models\HiddenProfile;
use App\Models\Interest;
use App\Models\MatrimonyProfile;
use App\Models\Plan;
use App\Models\ProfilePhoto;
use App\Models\Shortlist;
use App\Models\Subscription;
use App\Models\User;
use App\Services\ContactAccessService;
use App\Services\Image\ProfilePhotoUrlService;
use App\Services\IncomeEngineService;
use App\Services\EducationService;
use App\Services\Gunamilan\GunamilanService;
use App\Services\Location\LocationService;
use App\Services\PartnerPreferenceSuggestionService;
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
        $contact = $this->contactPayload($profile, $viewerProfile, $viewer);

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

        return [
            'card' => [
                'name' => $this->cleanString($profile->full_name),
                'age' => $age,
                'age_label' => $age !== null ? $age.' years' : null,
                'height_label' => $this->heightLabel($profile),
                'community_label' => $this->communityLabel($profile),
                'education_label' => $this->cleanDisplayValue($profile->highest_education),
                'occupation_label' => $this->occupationLabel($profile),
                'location_label' => $this->cleanLocation(ProfileDisplayCopy::profileResidenceDisplayLine($profile)),
                'verified' => $this->isVerified($profile),
                'premium' => $this->isPremium($profile),
                'photo_count' => $photoCount,
                'primary_photo_url' => $primaryPhotoUrl,
                'comparison_label' => $this->comparisonLabel($profile),
                'has_astro' => $profile->horoscope !== null,
            ],
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
            'summary' => $matchedCount > 0 ? $matchedCount.' जुळणारे मुद्दे' : null,
            'viewer' => [
                'name' => 'You',
                'photo_url' => $viewerPhotoUrl,
            ],
            'target' => [
                'name' => $this->cleanString($profile->full_name) ?? 'Profile',
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
            'Age',
            $viewerAge !== null ? $viewerAge.' years' : null,
            $targetAge !== null ? $targetAge.' years' : null,
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

        return $this->comparisonRow('height', 'Height', $viewerHeight, $targetHeight, $status, $counted);
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

        return $this->comparisonRow('location', 'Location', $viewerLocation, $targetLocation, $status, $isCounted);
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

        return $this->comparisonRow('community', 'Religion / Caste', $viewerCommunity, $targetCommunity, $status, $counted);
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

        return $this->comparisonRow('same_sub_caste', 'Same sub-caste', $viewerSubCaste, $targetSubCaste, 'match', true);
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
            return $this->comparisonRow('education', 'Education', $viewerLabel, $targetLabel, 'match', true);
        }

        $viewerSort = $viewerDegree !== null ? $this->positiveInt($viewerDegree->sort_order ?? null) : null;
        $targetSort = $targetDegree !== null ? $this->positiveInt($targetDegree->sort_order ?? null) : null;
        if ($viewerSort !== null && $targetSort !== null && abs($viewerSort - $targetSort) <= 1) {
            return $this->comparisonRow('education', 'Education', $viewerLabel, $targetLabel, 'near', true);
        }

        if ($this->normalizeEducationText($viewerEducation) === $this->normalizeEducationText($targetEducation)) {
            return $this->comparisonRow('education', 'Education', $viewerEducation, $targetEducation, 'match', true);
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

        return $this->comparisonRow('income', 'Income', $viewerValue, $targetValue, 'match', true);
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

        return $this->comparisonRow('gunamilan', 'Gunamilan', $score, 'Compatible', 'match', true);
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
            return $minLabel.' and above';
        }
        if ($maxLabel !== null) {
            return 'Up to '.$maxLabel;
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

        return '₹'.$label.' L';
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
        $viewerValue = $this->cleanComparisonString($viewerValue) ?? 'माहिती नाही';
        $targetValue = $this->cleanComparisonString($targetValue) ?? 'माहिती नाही';
        if ($viewerValue === 'माहिती नाही' && $targetValue === 'माहिती नाही') {
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
        return match ($status) {
            'strong' => 'Strong',
            'match' => 'Match',
            'near' => 'Near',
            default => 'Basic',
        };
    }

    private function profileGenderKey(MatrimonyProfile $profile): ?string
    {
        $key = mb_strtolower(trim((string) ($profile->gender?->key ?? '')));
        if ($key === '') {
            $key = mb_strtolower(trim((string) ($profile->user?->gender ?? '')));
        }

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
                return $value.' years';
            }

            return str_replace(' – ', ' to ', $value).(str_contains($value, 'year') ? '' : ' years');
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
                $status = $matched === true ? 'Match' : ($matched === false ? 'Not matched' : 'Review');
            }
            $items[] = $this->item(
                $label,
                $status !== null ? $viewerValue.' — '.$status : $viewerValue,
                $this->comparisonIcon($this->cleanString($row['key'] ?? null))
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

    /**
     * @return array<string, mixed>
     */
    private function contactPayload(MatrimonyProfile $profile, ?MatrimonyProfile $viewerProfile, ?User $viewer): array
    {
        if ($viewer === null || $viewerProfile === null) {
            return $this->contactPayloadState(
                enabled: false,
                state: 'unavailable',
                message: 'Login and profile are required to view contact options.'
            );
        }

        if ((int) $viewerProfile->id === (int) $profile->id) {
            return $this->contactPayloadState(
                enabled: false,
                state: 'unavailable',
                message: 'Contact unlock is not available on your own profile.'
            );
        }

        try {
            $profile->loadMissing('user');

            $contactAccess = app(ContactAccessService::class)->resolveViewerContext(
                $viewer,
                $profile,
                $this->acceptedInterestExists($viewerProfile, $profile),
                $this->profileVisibilitySettings($profile),
                null,
            );
        } catch (Throwable) {
            return $this->contactPayloadState(
                enabled: false,
                state: 'unavailable',
                message: 'Contact information is not available right now.'
            );
        }

        return $this->contactPayloadFromAccess($contactAccess);
    }

    /**
     * @param  array<string, mixed>  $contactAccess
     * @return array<string, mixed>
     */
    private function contactPayloadFromAccess(array $contactAccess): array
    {
        $phone = $this->cleanString($contactAccess['paid_contact_phone'] ?? null);
        $email = $this->cleanString($contactAccess['paid_contact_email'] ?? null);
        $showMediator = ($contactAccess['show_mediator_cta'] ?? false) === true;

        if ($phone !== null || $email !== null) {
            return $this->contactPayloadState(
                enabled: true,
                state: 'revealed',
                message: 'Contact information is available.',
                phone: $phone,
                email: $email,
                whatsappVisible: $showMediator,
            );
        }

        if (($contactAccess['needs_upgrade'] ?? false) === true) {
            return $this->contactPayloadState(
                enabled: true,
                state: 'upgrade_required',
                message: 'Upgrade is required to view contact information.',
                primaryCta: $this->contactPrimaryCta('Upgrade to View Contact', 'primary', 'upgrade', false),
                whatsappVisible: $showMediator,
                whatsappMessage: $showMediator ? 'WhatsApp Response can be shown after eligible access.' : null,
            );
        }

        if (($contactAccess['show_paid_reveal_button'] ?? false) === true) {
            return $this->contactPayloadState(
                enabled: true,
                state: 'unlock_available',
                message: 'Contact unlock is available for this profile.',
                primaryCta: $this->contactPrimaryCta('View Contact', 'primary', 'view_contact', false),
                whatsappVisible: $showMediator,
            );
        }

        if ($showMediator) {
            return $this->contactPayloadState(
                enabled: true,
                state: 'whatsapp_response_available',
                message: 'WhatsApp Response is available for this profile.',
                whatsappVisible: true,
                whatsappMessage: 'You can request a WhatsApp Response when the mobile action is available.',
                whatsappEnabled: false,
            );
        }

        if (($contactAccess['paid_reveal_blocked_pending_matchmaking'] ?? false) === true
            || $this->cleanString($contactAccess['reveal_blocked_reason'] ?? null) !== null
            || ($contactAccess['blocked'] ?? false) === true) {
            return $this->contactPayloadState(
                enabled: true,
                state: 'locked',
                message: 'Contact information is currently locked.'
            );
        }

        return $this->contactPayloadState(
            enabled: true,
            state: 'unavailable',
            message: 'Contact information is not available for this profile.'
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
        ?string $email = null,
        ?array $primaryCta = null,
        bool $whatsappVisible = false,
        ?string $whatsappMessage = null,
        bool $whatsappEnabled = false,
    ): array {
        $state = in_array($state, [
            'revealed',
            'locked',
            'unlock_available',
            'upgrade_required',
            'whatsapp_response_available',
            'unavailable',
        ], true) ? $state : 'unavailable';

        return [
            'enabled' => $enabled,
            'title' => 'Contact Information',
            'state' => $state,
            'message' => $message,
            'phone' => $phone,
            'email' => $email,
            'primary_cta' => $primaryCta,
            'whatsapp_response' => [
                'visible' => $whatsappVisible,
                'label' => 'WhatsApp Response',
                'message' => $whatsappMessage,
                'enabled' => $whatsappEnabled,
            ],
        ];
    }

    /**
     * @return array{label: string, style: string, action: string, enabled: bool}
     */
    private function contactPrimaryCta(string $label, string $style, string $action, bool $enabled): array
    {
        $style = in_array($style, ['primary', 'secondary', 'disabled'], true) ? $style : 'disabled';
        $action = in_array($action, ['view_contact', 'upgrade', 'none'], true) ? $action : 'none';

        return [
            'label' => $label,
            'style' => $enabled ? $style : 'disabled',
            'action' => $action,
            'enabled' => $enabled,
        ];
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
        if ($profile->relationLoaded('photos')) {
            $profile->photos
                ->filter(fn (ProfilePhoto $photo): bool => $photo->effectiveApprovedStatus() === 'approved')
                ->each(function (ProfilePhoto $photo) use ($profile, &$urls): void {
                    $path = ltrim((string) $photo->file_path, '/');
                    if ($path === '' || ProfilePhotoUrlService::isPendingPlaceholder($path)) {
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
