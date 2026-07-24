<?php

namespace App\Services\BiodataExport;

use App\Models\FieldRegistry;
use App\Models\MatrimonyProfile;
use App\Services\ExtendedFieldService;
use App\Services\Image\ProfilePhotoUrlService;
use App\Services\ProfileShowSnapshotService;
use App\Support\LocalizedText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class BiodataPayloadBuilder
{
    private ?string $photoDataUri = null;

    public function __construct(
        private ProfileShowSnapshotService $snapshotService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(MatrimonyProfile $profile): array
    {
        $profile->loadMissing(['user', 'gender', 'maritalStatus', 'city', 'district', 'state']);

        $extendedValues = Schema::hasTable('profile_extended_fields')
            ? ExtendedFieldService::getValuesForProfile($profile)
            : [];
        $extendedMeta = $this->extendedMeta($extendedValues);

        $sections = $this->snapshotService->build($profile, [
            'is_own_profile' => true,
            'date_of_birth_visible' => true,
            'marital_status_visible' => true,
            'education_visible' => true,
            'location_visible' => true,
            'caste_visible' => true,
            'height_visible' => true,
            'enable_relatives_section' => true,
            'preference_criteria' => $this->firstRow('profile_preference_criteria', (int) $profile->id),
            'preferred_religion_ids' => $this->pluckIds('profile_preferred_religions', 'religion_id', (int) $profile->id),
            'preferred_caste_ids' => $this->pluckIds('profile_preferred_castes', 'caste_id', (int) $profile->id),
            'preferred_district_ids' => $this->pluckIds('profile_preferred_districts', 'district_id', (int) $profile->id),
            'preferred_education_degree_ids' => $this->pluckIds('profile_preferred_education_degrees', 'education_degree_id', (int) $profile->id),
            'preferred_occupation_master_ids' => $this->pluckIds('profile_preferred_occupation_master', 'occupation_master_id', (int) $profile->id),
            'preferred_diet_ids' => $this->pluckIds('profile_preferred_diets', 'diet_id', (int) $profile->id),
            'preferred_marital_status_ids' => $this->pluckIds('profile_preferred_marital_statuses', 'marital_status_id', (int) $profile->id),
            'extended_attributes' => $this->firstRow('profile_extended_attributes', (int) $profile->id),
            'extended_values' => $extendedValues,
            'extended_meta' => $extendedMeta,
        ]);

        $this->photoDataUri = $this->photoDataUri($profile);

        $sections = $this->prepareSectionsForExport($sections, $profile);

        return [
            'profile_id' => (int) $profile->id,
            'title' => trim((string) ($profile->full_name ?? '')) ?: __('profile.biodata_export_title'),
            'headline' => $this->headline($profile),
            'generated_at' => $this->formatExportDateTime(now()),
            'sections' => $this->filterSections($sections),
            'photo' => [
                'available' => $this->photoDataUri !== null,
                'data_uri' => $this->photoDataUri,
                'alt' => trim((string) ($profile->full_name ?? 'Profile photo')),
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @return list<array<string, mixed>>
     */
    private function prepareSectionsForExport(array $sections, MatrimonyProfile $profile): array
    {
        return collect($sections)
            ->map(function (array $section) use ($profile): array {
                $sectionId = (string) ($section['id'] ?? '');

                if (isset($section['rows']) && is_array($section['rows'])) {
                    $section['rows'] = $this->prepareRowsForExport($sectionId, $section['rows'], $profile);
                }

                if (isset($section['groups']) && is_array($section['groups'])) {
                    $section['groups'] = collect($section['groups'])
                        ->map(fn ($group): array => [
                            'heading' => $this->localizeExportLabel((string) ($group['heading'] ?? '')),
                            'lines' => collect($group['lines'] ?? [])
                                ->map(fn ($line): string => $this->localizeInlineText((string) $line))
                                ->values()
                                ->all(),
                        ])
                        ->values()
                        ->all();
                }

                if (isset($section['marriage_blocks']) && is_array($section['marriage_blocks'])) {
                    $section['marriage_blocks'] = collect($section['marriage_blocks'])
                        ->map(fn ($block): array => collect($block)
                            ->map(fn ($line): string => $this->localizeInlineText($this->localizeLabelPrefix((string) $line)))
                            ->values()
                            ->all())
                        ->values()
                        ->all();
                }

                return $section;
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function prepareRowsForExport(string $sectionId, array $rows, MatrimonyProfile $profile): array
    {
        if ($sectionId === 'family') {
            return $this->prepareFamilyRowsForExport($rows, $profile);
        }

        return collect($rows)
            ->reject(fn ($row): bool => $this->shouldHideFromBiodata((string) ($row['label'] ?? '')))
            ->map(fn ($row): array => $this->localizeRowForExport($sectionId, $row, $profile))
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function prepareFamilyRowsForExport(array $rows, MatrimonyProfile $profile): array
    {
        $out = [];
        $childRowsInserted = false;
        $childRows = $this->childRowsForExport($profile);

        foreach ($rows as $row) {
            $label = (string) ($row['label'] ?? '');

            if ($this->isChildRowLabel($label)) {
                if (! $childRowsInserted) {
                    array_push($out, ...$childRows);
                    $childRowsInserted = true;
                }
                continue;
            }

            if ($this->shouldHideFromBiodata($label)) {
                continue;
            }

            $out[] = $this->localizeRowForExport('family', $row, $profile);
        }

        if (! $childRowsInserted && $childRows !== []) {
            array_push($out, ...$childRows);
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function childRowsForExport(MatrimonyProfile $profile): array
    {
        $children = $profile->children ?? collect();
        if ($children->isEmpty()) {
            return [];
        }

        $single = $children->count() === 1;
        $baseLabel = __('profile.biodata_child');

        return $children->values()->map(function ($child, int $index) use ($single, $baseLabel): array {
            $fallbackLabel = $single ? $baseLabel : $baseLabel.' '.($index + 1);
            $childName = trim((string) ($child->child_name ?? ''));
            $label = $childName !== '' ? $childName : $fallbackLabel;

            $parts = array_filter([
                ! empty($child->age) ? $child->age.' '.__('search.years_short') : null,
                ! empty($child->gender) ? $this->childGenderLabel((string) $child->gender) : null,
                $child->childLivingWith ? $this->localizeExportLabel('Living with').': '.$this->lookupLabel($child->childLivingWith) : null,
            ]);

            return [
                'label' => $label,
                'value' => implode(' · ', $parts),
                'locked' => false,
                'full' => true,
            ];
        })->values()->all();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function localizeRowForExport(string $sectionId, array $row, MatrimonyProfile $profile): array
    {
        $sourceLabel = (string) ($row['label'] ?? '');
        $row['label'] = $this->localizeExportLabel($sourceLabel);
        $row['value'] = $this->localizeRowValue($sectionId, $sourceLabel, (string) ($row['value'] ?? ''), $profile);

        return $row;
    }

    private function localizeRowValue(string $sectionId, string $label, string $value, MatrimonyProfile $profile): string
    {
        $localized = match ($sectionId) {
            'basic_info' => $this->basicInfoValue($label, $profile, $value),
            'physical' => $this->physicalValue($label, $profile, $value),
            'family' => $this->familyValue($label, $profile, $value),
            'horoscope' => $this->horoscopeValue($label, $profile, $value),
            default => $value,
        };

        return $this->localizeInlineText($localized);
    }

    private function basicInfoValue(string $label, MatrimonyProfile $profile, string $value): string
    {
        if ($this->labelMatches($label, ['Marital status', 'Marital Status', 'वैवाहिक स्थिती'])) {
            return $this->lookupLabel($profile->maritalStatus) ?: $value;
        }
        if ($this->labelMatches($label, ['Religion', 'धर्म'])) {
            return $this->lookupLabel($profile->religion) ?: $value;
        }
        if ($this->labelMatches($label, ['Caste', 'जात'])) {
            return $this->lookupLabel($profile->caste) ?: $value;
        }
        if ($this->labelMatches($label, ['Subcaste', 'Sub caste', 'उपजात'])) {
            return $this->lookupLabel($profile->subCaste) ?: $value;
        }
        if ($this->labelMatches($label, ['Date of birth', 'Date of Birth', 'जन्मतारीख'])) {
            return $this->localizeDateText($value);
        }

        return $value;
    }

    private function physicalValue(string $label, MatrimonyProfile $profile, string $value): string
    {
        if ($this->labelMatches($label, ['Complexion', 'वर्ण'])) {
            return $this->lookupLabel($profile->complexion) ?: $value;
        }
        if ($this->labelMatches($label, ['Blood Group', 'रक्तगट'])) {
            return $this->lookupLabel($profile->bloodGroup) ?: $value;
        }

        return $value;
    }

    private function familyValue(string $label, MatrimonyProfile $profile, string $value): string
    {
        if ($this->labelMatches($label, ['Siblings', 'भावंडे'])) {
            return $this->siblingCountLabel($profile) ?: $value;
        }

        return $value;
    }

    private function horoscopeValue(string $label, MatrimonyProfile $profile, string $value): string
    {
        $horoscope = $profile->horoscope;
        if (! $horoscope) {
            return $value;
        }

        if ($this->labelMatches($label, ['Rashi', 'राशी'])) {
            return $this->lookupLabel($horoscope->rashi) ?: $value;
        }
        if ($this->labelMatches($label, ['Nakshatra', 'नक्षत्र'])) {
            return $this->lookupLabel($horoscope->nakshatra) ?: $value;
        }
        if ($this->labelMatches($label, ['Gan', 'गण'])) {
            return $this->lookupLabel($horoscope->gan) ?: $value;
        }
        if ($this->labelMatches($label, ['Nadi', 'नाडी'])) {
            return $this->lookupLabel($horoscope->nadi) ?: $value;
        }
        if ($this->labelMatches($label, ['Mangal Dosh', 'मंगळ दोष'])) {
            return $this->lookupLabel($horoscope->mangalDoshType) ?: $value;
        }
        if ($this->labelMatches($label, ['Yoni', 'योनी'])) {
            return $this->lookupLabel($horoscope->yoni) ?: $value;
        }
        if ($this->labelMatches($label, ['Birth weekday', 'जन्मवार'])) {
            return $this->weekdayLabel($value);
        }

        return $value;
    }

    private function shouldHideFromBiodata(string $label): bool
    {
        return $this->labelMatches($label, [
            'Gender',
            'लिंग',
            'Mother tongue',
            'मातृभाषा',
            'Physical Build',
            'बांधा',
            'Family Type',
            'कुटुंब प्रकार',
        ]);
    }

    private function isChildRowLabel(string $label): bool
    {
        $label = trim($label);

        return (bool) preg_match('/^(Child|मूल)\s*\d+$/u', $label);
    }

    private function lookupLabel(mixed $model): string
    {
        if (! is_object($model)) {
            return '';
        }

        $preferred = LocalizedText::isMarathi()
            ? ['display_label', 'label_mr', 'name_mr', 'display_label_mr', 'label', 'name']
            : ['display_label', 'label_en', 'name_en', 'label', 'name'];

        foreach ($preferred as $field) {
            $value = trim((string) ($model->{$field} ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function siblingCountLabel(MatrimonyProfile $profile): string
    {
        $siblings = $profile->siblings ?? collect();
        $brothers = $siblings->where('relation_type', 'brother')->count();
        $sisters = $siblings->where('relation_type', 'sister')->count();

        if ($brothers === 0 && $sisters === 0) {
            return '';
        }

        $parts = [];
        if ($brothers > 0) {
            $parts[] = trans_choice('profile.biodata_siblings_brothers', $brothers, ['count' => $brothers]);
        }
        if ($sisters > 0) {
            $parts[] = trans_choice('profile.biodata_siblings_sisters', $sisters, ['count' => $sisters]);
        }

        return implode(', ', $parts);
    }

    private function childGenderLabel(string $gender): string
    {
        $gender = mb_strtolower(trim($gender));

        return match ($gender) {
            'male' => __('profile.biodata_child_male'),
            'female' => __('profile.biodata_child_female'),
            default => $gender,
        };
    }

    private function localizeExportLabel(string $label): string
    {
        $label = trim($label);
        if (! LocalizedText::isMarathi()) {
            return $label;
        }

        return $this->labelTranslations()[$label] ?? __($label);
    }

    private function localizeLabelPrefix(string $line): string
    {
        if (! str_contains($line, ':')) {
            return $line;
        }

        [$label, $value] = explode(':', $line, 2);

        return $this->localizeExportLabel(trim($label)).':'.ltrim($value);
    }

    private function localizeInlineText(string $text): string
    {
        if (! LocalizedText::isMarathi() || $text === '') {
            return $this->localizeDateText($text);
        }

        $text = $this->localizeDateText($text);
        $replacements = [
            'Living with' => 'सोबत',
            'With me' => 'माझ्याबरोबर',
            'With other parent' => 'दुसऱ्या पालकाबरोबर',
            'Never Married' => 'अविवाहित',
            'Divorced' => 'घटस्फोटित',
            'Separated' => 'वेगळे राहतात',
            'Widowed' => 'विधवा / विधुर',
            'Married' => 'विवाहित',
            'Unmarried' => 'अविवाहित',
            ' male' => ' मुलगा',
            ' female' => ' मुलगी',
            'Male' => 'पुरुष',
            'Female' => 'स्त्री',
        ];

        return strtr($text, $replacements);
    }

    private function weekdayLabel(string $value): string
    {
        if (! LocalizedText::isMarathi()) {
            return $value;
        }

        return $this->weekdayTranslations()[trim($value)] ?? $value;
    }

    private function formatExportDateTime(\DateTimeInterface $date): string
    {
        return $this->localizeDateText($date->format('j M Y, h:i A'));
    }

    private function localizeDateText(string $text): string
    {
        if (! LocalizedText::isMarathi()) {
            return $text;
        }

        return strtr($text, [
            'Jan' => 'जानेवारी',
            'Feb' => 'फेब्रुवारी',
            'Mar' => 'मार्च',
            'Apr' => 'एप्रिल',
            'May' => 'मे',
            'Jun' => 'जून',
            'Jul' => 'जुलै',
            'Aug' => 'ऑगस्ट',
            'Sep' => 'सप्टेंबर',
            'Oct' => 'ऑक्टोबर',
            'Nov' => 'नोव्हेंबर',
            'Dec' => 'डिसेंबर',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function labelTranslations(): array
    {
        return [
            'Date of birth' => 'जन्मतारीख',
            'Birth time' => 'जन्मवेळ',
            'Marital status' => 'वैवाहिक स्थिती',
            'Serious intent' => 'गंभीरता',
            'Religion' => 'धर्म',
            'Caste' => 'जात',
            'Subcaste' => 'उपजात',
            'Address' => 'पत्ता',
            'Location' => 'ठिकाण',
            'Birth Place' => 'जन्मठिकाण',
            'Native Place' => 'मूळ गाव',
            'Phone' => 'फोन',
            'Height' => 'उंची',
            'Weight' => 'वजन',
            'Complexion' => 'वर्ण',
            'Blood Group' => 'रक्तगट',
            'Education' => 'शिक्षण',
            'Occupation' => 'व्यवसाय',
            'Company' => 'कंपनी',
            'Income' => 'उत्पन्न',
            'Family Income' => 'कुटुंब उत्पन्न',
            'Income Currency' => 'उत्पन्न चलन',
            'Work Location' => 'कामाचे ठिकाण',
            'Father' => 'वडील',
            'Mother' => 'आई',
            'Siblings' => 'भावंडे',
            'Child' => 'मूल',
            'Living with' => 'सोबत',
            'Marriage details' => 'लग्न तपशील',
            'Marriage year' => 'लग्न वर्ष',
            'Divorce year' => 'घटस्फोट वर्ष',
            'Separation year' => 'विभक्ती वर्ष',
            'Spouse death year' => 'जोडीदार मृत्यू वर्ष',
            'Divorce status' => 'घटस्फोट स्थिती',
            'Remarriage reason' => 'पुनर्विवाहाचे कारण',
            'Notes' => 'नोंदी',
            'Brother' => 'भाऊ',
            'Sister' => 'बहीण',
            'Paternal Grandfather' => 'आजोबा',
            'Paternal Grandmother' => 'आजी',
            'Maternal Grandfather' => 'मातृ आजोबा',
            'Maternal Grandmother' => 'मातृ आजी',
            'Paternal Uncle' => 'चुलते',
            'Wife of Paternal Uncle' => 'चुलती',
            'Paternal Aunt' => 'आत्या',
            'Husband of Paternal Aunt' => 'आत्यांचे यजमान',
            'Maternal Uncle' => 'मामा',
            'Wife of Maternal Uncle' => 'मामी',
            'Maternal Aunt' => 'मावशी',
            'Husband of Maternal Aunt' => 'मावशीचे यजमान',
            'Cousin' => 'चुलत / मामे भाऊ-बहीण',
            'Uncle' => 'काका / मामा',
            'Aunt' => 'काकू / मावशी',
            'Grandfather' => 'आजोबा',
            'Grandmother' => 'आजी',
            'Other' => 'इतर',
            'Property' => 'मालमत्ता',
            'Rashi' => 'राशी',
            'Nakshatra' => 'नक्षत्र',
            'Gan' => 'गण',
            'Nadi' => 'नाडी',
            'Mangal Dosh' => 'मंगळ दोष',
            'Yoni' => 'योनी',
            'Charan' => 'चरण',
            'Devak' => 'देवक',
            'Kul' => 'कुळ',
            'Gotra' => 'गोत्र',
            'Navras name' => 'नवरस नाव',
            'Birth weekday' => 'जन्मवार',
            'Age' => 'वय',
            'City' => 'शहर',
            'Religions' => 'धर्म',
            'Castes' => 'जाती',
            'Districts' => 'जिल्हे',
            'Preferred income' => 'अपेक्षित उत्पन्न',
            'Preferred qualification' => 'अपेक्षित शिक्षण',
            'Preferred diet' => 'अपेक्षित आहार',
            'Preferred occupation' => 'अपेक्षित व्यवसाय',
            'Expectations' => 'अपेक्षा',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function weekdayTranslations(): array
    {
        return [
            'Monday' => 'सोमवार',
            'Tuesday' => 'मंगळवार',
            'Wednesday' => 'बुधवार',
            'Thursday' => 'गुरुवार',
            'Friday' => 'शुक्रवार',
            'Saturday' => 'शनिवार',
            'Sunday' => 'रविवार',
        ];
    }

    /**
     * @param  list<string>  $labels
     */
    private function labelMatches(string $label, array $labels): bool
    {
        $needle = mb_strtolower(trim($label));

        foreach ($labels as $candidate) {
            if ($needle === mb_strtolower(trim($candidate))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $extendedValues
     * @return array<string, string>
     */
    private function extendedMeta(array $extendedValues): array
    {
        if ($extendedValues === [] || ! Schema::hasTable('field_registry')) {
            return [];
        }

        return FieldRegistry::query()
            ->where('field_type', 'EXTENDED')
            ->whereIn('field_key', array_keys($extendedValues))
            ->pluck('display_label', 'field_key')
            ->map(fn ($label) => (string) $label)
            ->toArray();
    }

    private function firstRow(string $table, int $profileId): ?object
    {
        if (! Schema::hasTable($table)) {
            return null;
        }

        return DB::table($table)->where('profile_id', $profileId)->first();
    }

    /**
     * @return list<int>
     */
    private function pluckIds(string $table, string $column, int $profileId): array
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return [];
        }

        return DB::table($table)
            ->where('profile_id', $profileId)
            ->pluck($column)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @return list<array<string, mixed>>
     */
    private function filterSections(array $sections): array
    {
        $out = [];
        foreach ($sections as $section) {
            $rows = collect($section['rows'] ?? [])
                ->filter(fn ($row) => trim((string) ($row['value'] ?? '')) !== '' || (bool) ($row['locked'] ?? false))
                ->values()
                ->all();
            $groups = collect($section['groups'] ?? [])
                ->map(function ($group) {
                    $lines = collect($group['lines'] ?? [])
                        ->map(fn ($line) => trim((string) $line))
                        ->filter()
                        ->values()
                        ->all();

                    return [
                        'heading' => trim((string) ($group['heading'] ?? '')),
                        'lines' => $lines,
                    ];
                })
                ->filter(fn ($group) => $group['lines'] !== [])
                ->values()
                ->all();
            $marriageBlocks = collect($section['marriage_blocks'] ?? [])
                ->map(fn ($block) => collect($block)->map(fn ($line) => trim((string) $line))->filter()->values()->all())
                ->filter()
                ->values()
                ->all();

            if ($rows === [] && $groups === [] && $marriageBlocks === []) {
                continue;
            }

            $section['rows'] = $rows;
            $section['groups'] = $groups;
            if ($marriageBlocks !== []) {
                $section['marriage_blocks'] = $marriageBlocks;
            }
            $out[] = $section;
        }

        return $out;
    }

    private function headline(MatrimonyProfile $profile): string
    {
        $parts = array_filter([
            trim((string) ($profile->highest_education ?? '')),
            trim((string) ($profile->occupation_title ?? '')),
            trim(implode(', ', array_filter([
                $profile->city?->name,
                $profile->district?->name,
                $profile->state?->name,
            ]))),
        ], fn ($part) => $part !== '');

        return implode(' | ', array_slice(array_values($parts), 0, 3));
    }

    private function photoDataUri(MatrimonyProfile $profile): ?string
    {
        if (($profile->photo_approved ?? null) === false) {
            return null;
        }

        $absolutePath = null;
        $primary = trim((string) ($profile->profile_photo ?? ''));
        if ($primary !== '') {
            if (ProfilePhotoUrlService::isPendingPlaceholder($primary)) {
                $absolutePath = ProfilePhotoUrlService::resolvePendingTempAbsolutePath($primary)
                    ?? ProfilePhotoUrlService::resolvePendingFallbackFromPrimaryGallery($profile);
            } else {
                $absolutePath = ProfilePhotoUrlService::resolveStoredPublicAbsolutePath($primary);
            }
        }

        if ($absolutePath === null) {
            $galleryPath = ProfilePhotoUrlService::primaryNonPendingGalleryRelativePath($profile);
            if ($galleryPath !== null) {
                $absolutePath = ProfilePhotoUrlService::resolveStoredPublicAbsolutePath($galleryPath);
            }
        }

        if ($absolutePath === null || ! is_file($absolutePath)) {
            return null;
        }

        $bytes = @file_get_contents($absolutePath);
        if ($bytes === false || $bytes === '') {
            return null;
        }

        $mime = 'image/jpeg';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = finfo_file($finfo, $absolutePath);
                finfo_close($finfo);
                if (is_string($detected) && str_starts_with($detected, 'image/')) {
                    $mime = $detected;
                }
            }
        }

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }
}
