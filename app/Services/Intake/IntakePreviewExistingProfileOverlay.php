<?php

namespace App\Services\Intake;

use App\Models\MasterBloodGroup;
use App\Models\MatrimonyProfile;
use App\Services\ExtendedFieldService;
use App\Services\MutationService;
use App\Services\Parsing\IntakeControlledFieldNormalizer;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Intake preview (phase 1: core + wizard): profile-filled values stay in the form;
 * intake/OCR differences become optional suggestions (Apply in UI). Phase 2: all registry fields.
 */
class IntakePreviewExistingProfileOverlay
{
    /** Mirrors MutationService::FALLBACK_CORE_KEYS — wizard + core columns on matrimony_profiles. */
    private const WIZARD_CORE_KEYS = [
        'full_name', 'gender_id', 'date_of_birth', 'birth_time', 'marital_status_id', 'has_children', 'has_siblings', 'highest_education',
        'location', 'location_id', 'religion_id', 'caste_id', 'sub_caste_id', 'mother_tongue_id', 'height_cm', 'profile_photo',
        'complexion_id', 'physical_build_id', 'blood_group_id', 'diet_id', 'smoking_status_id', 'drinking_status_id', 'family_type_id', 'income_currency_id',
        'address_line', 'annual_income', 'family_income', 'income_private', 'family_income_private',
        'birth_place_text', 'birth_city_id', 'work_location_text',
        'occupation_master_id', 'occupation_custom_id', 'company_name',
        'father_name', 'father_occupation', 'father_occupation_master_id', 'father_occupation_custom_id', 'father_extra_info', 'father_contact_1', 'father_contact_2',
        'mother_name', 'mother_occupation', 'mother_occupation_master_id', 'mother_occupation_custom_id', 'mother_extra_info', 'mother_contact_1', 'mother_contact_2',
        'other_relatives_text',
        'spectacles_lens', 'physical_condition',
    ];

    /** Maps suggestionMap / OCR keys to core data keys. */
    private const SUGGESTION_KEY_TO_CORE = [
        'gender' => 'gender_id',
        'religion' => 'religion_id',
        'caste' => 'caste_id',
        'sub_caste' => 'sub_caste_id',
        'marital_status' => 'marital_status_id',
        'birth_place' => 'birth_place_text',
    ];

    public function __construct(
        private IntakeControlledFieldNormalizer $controlledFieldNormalizer,
        private IntakePreviewProfileHydrator $profileHydrator,
        private IntakePreviewMasterFieldEquivalence $fieldEquivalence,
        private IntakePreviewFieldDisplayFormatter $displayFormatter,
    ) {}

    /**
     * @param  array<string, mixed>  $coreData  Preview core (mutated)
     * @param  array<string, mixed>  $suggestionMap  OCR/suggestion map (mutated)
     * @param  array<string, mixed>  $intakeParsed  Full parsed_json (reference)
     * @param  array<string, mixed>  $snapshot  Working snapshot for form submit (mutated)
     * @param  array<string, mixed>  $sections  Mapped sections (mutated)
     * @return array{
     *   protected_core_keys: list<string>,
     *   field_suggestions: list<array<string, mixed>>
     * }
     */
    public function apply(
        MatrimonyProfile $profile,
        array &$coreData,
        array &$suggestionMap,
        array $intakeParsed,
        array &$snapshot,
        array &$sections,
    ): array {
        $profile->loadMissing(['religion', 'caste', 'subCaste']);

        $intakeProposedCore = is_array($intakeParsed['core'] ?? null) ? $intakeParsed['core'] : [];
        $normalizedIntake = $this->controlledFieldNormalizer->normalizeCore(
            $this->enrichParsedCommunityCore($intakeProposedCore)
        );
        $profileCore = $this->buildProfileCoreBaseline($profile);
        $normalizedProfile = $this->controlledFieldNormalizer->normalizeCore($profileCore);

        $protected = [];
        $fieldSuggestions = [];

        $coreKeys = $this->coreKeysForOverlay();
        foreach ($coreKeys as $coreKey) {
            $profileVal = $normalizedProfile[$coreKey] ?? $this->currentCoreValue($profile, $coreKey);
            if ($this->isEmptyExisting($coreKey, $profileVal)) {
                continue;
            }

            $intakeVal = $this->intakeValueForCoreKey($coreKey, $normalizedIntake);
            if ($this->isEmptyProposed($coreKey, $intakeVal)) {
                $this->setCoreField($coreData, $coreKey, $profileVal);

                continue;
            }

            if ($this->fieldEquivalence->valuesEqual(
                $coreKey,
                $profileVal,
                $intakeVal,
                $normalizedProfile,
                $normalizedIntake,
                $profile
            )) {
                $this->setCoreField($coreData, $coreKey, $profileVal);

                continue;
            }

            $this->setCoreField($coreData, $coreKey, $profileVal);
            $protected[] = $coreKey;

            $suggestionKey = $this->suggestionKeyForCore($coreKey);
            $intakeDisplay = $this->displayValue($coreKey, $intakeVal, $normalizedIntake, null);
            $profileDisplay = $this->displayValue($coreKey, $profileVal, $normalizedProfile, $profile);
            $applyPayload = $this->applyPayload($coreKey, $intakeVal, $normalizedIntake);

            if ($this->suggestionDisplaysAreSame($coreKey, $profileDisplay, $intakeDisplay, $profile, $normalizedProfile, $normalizedIntake, $profileVal, $intakeVal)) {
                continue;
            }

            $this->mergeSuggestionEntry($suggestionMap, $suggestionKey, $profileDisplay, $intakeDisplay, $applyPayload);

            $fieldSuggestions[] = $this->fieldSuggestionRow(
                $suggestionKey,
                $coreKey,
                $profileDisplay,
                $intakeDisplay,
                $applyPayload
            );
        }

        if (isset($sections['core']['data']) && is_array($sections['core']['data'])) {
            $sections['core']['data'] = array_merge($sections['core']['data'], $coreData);
        }
        if (! isset($snapshot['core']) || ! is_array($snapshot['core'])) {
            $snapshot['core'] = [];
        }
        $snapshot['core'] = array_merge($snapshot['core'], $coreData);

        $fieldSuggestions = array_merge(
            $fieldSuggestions,
            $this->applyExtended($profile, $intakeParsed, $snapshot, $suggestionMap)
        );

        $fieldSuggestions = array_merge(
            $fieldSuggestions,
            $this->applyHoroscope($profile, $intakeParsed, $snapshot, $sections, $suggestionMap)
        );

        $fieldSuggestions = array_merge(
            $fieldSuggestions,
            $this->applyRepeaterSection(
                'education_history',
                $this->profileHydrator->educationHistoryForProfile($profile),
                is_array($intakeParsed['education_history'] ?? null) ? $intakeParsed['education_history'] : [],
                $snapshot,
                $sections
            )
        );

        $fieldSuggestions = array_merge(
            $fieldSuggestions,
            $this->applyRepeaterSection(
                'career_history',
                $this->profileHydrator->careerHistoryForProfile($profile),
                is_array($intakeParsed['career_history'] ?? null) ? $intakeParsed['career_history'] : [],
                $snapshot,
                $sections
            )
        );

        $this->restoreProfileLocationFieldsForPreviewDisplay($profile, $coreData, $snapshot, $sections);

        return [
            'protected_core_keys' => array_values(array_unique($protected)),
            'field_suggestions' => $fieldSuggestions,
        ];
    }

    /**
     * Preview SSOT: biodata/parser must not replace what the member already saved on profile.
     * Biodata place Apply only updates {@code approval_snapshot_json}; the typeahead keeps profile text/ids until approve.
     *
     * @param  array<string, mixed>  $coreData
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $sections
     */
    private function restoreProfileLocationFieldsForPreviewDisplay(
        MatrimonyProfile $profile,
        array &$coreData,
        array &$snapshot,
        array &$sections,
    ): void {
        $profileBirthCityId = (int) ($profile->birth_city_id ?? 0);
        if ($profileBirthCityId > 0) {
            $this->setCoreField($coreData, 'birth_city_id', $profileBirthCityId);
        }
        $profileBirthText = trim((string) ($profile->birth_place_text ?? ''));
        if ($profileBirthText !== '') {
            $this->setCoreField($coreData, 'birth_place_text', $profileBirthText);
        }
        if ($profileBirthCityId > 0 || $profileBirthText !== '') {
            unset($coreData['birth_place']);
        }

        if (Schema::hasColumn($profile->getTable(), 'native_city_id')) {
            $nativeCityId = (int) ($profile->native_city_id ?? 0);
            if ($nativeCityId > 0) {
                $this->setCoreField($coreData, 'native_city_id', $nativeCityId);
            }
        }

        $workCityId = (int) ($profile->work_city_id ?? 0);
        if ($workCityId > 0) {
            $this->setCoreField($coreData, 'work_city_id', $workCityId);
        }
        $workText = trim((string) ($profile->work_location_text ?? ''));
        if ($workText !== '') {
            $this->setCoreField($coreData, 'work_location_text', $workText);
        }

        if (isset($sections['core']['data']) && is_array($sections['core']['data'])) {
            $sections['core']['data'] = array_merge($sections['core']['data'], $coreData);
        }
        if (! isset($snapshot['core']) || ! is_array($snapshot['core'])) {
            $snapshot['core'] = [];
        }
        $snapshot['core'] = array_merge($snapshot['core'], $coreData);
    }

    /**
     * @return list<string>
     */
    private function coreKeysForOverlay(): array
    {
        $textOnlyKeys = array_keys(self::SUGGESTION_KEY_TO_CORE);

        try {
            $fromRegistry = app(MutationService::class)->coreFieldKeysAllowedForIntakeSuggestionApply();
            if ($fromRegistry !== []) {
                $merged = array_values(array_unique(array_merge($fromRegistry, self::WIZARD_CORE_KEYS)));

                return array_values(array_filter(
                    $merged,
                    static fn (string $k): bool => ! in_array($k, $textOnlyKeys, true)
                ));
            }
        } catch (\Throwable) {
            // tests / missing registry
        }

        return array_values(array_filter(
            self::WIZARD_CORE_KEYS,
            static fn (string $k): bool => ! in_array($k, $textOnlyKeys, true)
        ));
    }

    /**
     * @param  array<string, mixed>  $normalizedIntake
     */
    private function intakeValueForCoreKey(string $coreKey, array $normalizedIntake): mixed
    {
        $intakeVal = $normalizedIntake[$coreKey] ?? null;
        if ($coreKey === 'birth_place_text' && $this->isEmptyProposed($coreKey, $intakeVal)) {
            $intakeVal = $normalizedIntake['birth_place'] ?? $intakeVal;
        }
        if ($coreKey === 'birth_city_id' && $this->isEmptyProposed($coreKey, $intakeVal)) {
            $intakeVal = $normalizedIntake['birth_city_id'] ?? $intakeVal;
        }
        foreach (self::SUGGESTION_KEY_TO_CORE as $textKey => $idKey) {
            if ($idKey !== $coreKey) {
                continue;
            }
            if (isset($normalizedIntake[$textKey]) && ! $this->isEmptyProposed($textKey, $normalizedIntake[$textKey])) {
                if ($this->isEmptyProposed($coreKey, $intakeVal)) {
                    return $normalizedIntake[$textKey];
                }
            }
        }

        return $intakeVal;
    }

    private function suggestionKeyForCore(string $coreKey): string
    {
        foreach (self::SUGGESTION_KEY_TO_CORE as $sk => $ck) {
            if ($ck === $coreKey) {
                return $sk;
            }
        }

        return $coreKey;
    }

    /**
     * Parser often leaves "96 कुळी" inside caste; mirror intake preview resolve so sub_caste_id compares correctly.
     *
     * @param  array<string, mixed>  $core
     * @return array<string, mixed>
     */
    private function enrichParsedCommunityCore(array $core): array
    {
        $caste = trim((string) ($core['caste'] ?? ''));
        $sub = trim((string) ($core['sub_caste'] ?? ''));
        if ($sub === '' && $caste !== ''
            && preg_match('/(९६|96)\s*[कक][ुू]ळी|96\s*kuli/iu', $caste, $m) === 1) {
            $core['sub_caste'] = trim((string) $m[0]);
            if (mb_stripos($caste, 'मराठा') !== false) {
                $core['caste'] = 'मराठा';
            }
        }

        return $core;
    }

    /**
     * @param  array<string, mixed>  $suggestionMap
     */
    private function mergeSuggestionEntry(
        array &$suggestionMap,
        string $suggestionKey,
        string $profileDisplay,
        string $intakeDisplay,
        mixed $applyPayload,
    ): void {
        $existing = is_array($suggestionMap[$suggestionKey] ?? null) ? $suggestionMap[$suggestionKey] : [];
        $candidates = is_array($existing['candidates'] ?? null) ? $existing['candidates'] : [];
        array_unshift($candidates, [
            'value' => $intakeDisplay,
            'confidence' => 1.0,
            'source' => 'intake_parse',
            'apply' => $applyPayload,
        ]);

        $suggestionMap[$suggestionKey] = array_merge($existing, [
            'field_key' => $suggestionKey,
            'current_value' => $profileDisplay,
            'selected_value' => $profileDisplay,
            'suggested_value' => $intakeDisplay,
            'corrected_value' => $intakeDisplay,
            'intake_display_value' => $intakeDisplay,
            'intake_apply' => $applyPayload,
            'profile_existing' => true,
            'prefill_reason' => 'profile_existing',
            'needs_review' => false,
            'required_missing' => false,
            'candidates' => array_slice($candidates, 0, 5),
            'can_revert' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function fieldSuggestionRow(
        string $suggestionKey,
        string $coreKey,
        string $profileDisplay,
        string $intakeDisplay,
        mixed $applyPayload,
    ): array {
        return [
            'key' => $suggestionKey,
            'core_key' => $coreKey,
            'form_name' => $this->formNameForCoreKey($coreKey),
            'profile_display' => $profileDisplay,
            'intake_display' => $intakeDisplay,
            'intake_apply' => $applyPayload,
            'section' => 'core',
        ];
    }

    private function formNameForCoreKey(string $coreKey): string
    {
        return 'snapshot[core]['.$coreKey.']';
    }

    /**
     * @return array<string, mixed>
     */
    private function buildProfileCoreBaseline(MatrimonyProfile $profile): array
    {
        $core = [];
        foreach ($this->coreKeysForOverlay() as $coreKey) {
            $v = $this->currentCoreValue($profile, $coreKey);
            if (! $this->isEmptyExisting($coreKey, $v)) {
                $core[$coreKey] = $v;
            }
        }

        if (! empty($profile->religion_id)) {
            $core['religion_id'] = $profile->religion_id;
            $core['religion'] = \App\Support\BilingualMasterLabel::preferred(
                $profile->religion?->label_mr,
                $profile->religion?->label_en,
                $profile->religion?->label
            );
        }
        if (! empty($profile->caste_id)) {
            $core['caste_id'] = $profile->caste_id;
            $core['caste'] = \App\Support\BilingualMasterLabel::preferred(
                $profile->caste?->label_mr,
                $profile->caste?->label_en,
                $profile->caste?->label
            );
        }
        if (! empty($profile->sub_caste_id)) {
            $core['sub_caste_id'] = $profile->sub_caste_id;
            $core['sub_caste'] = \App\Support\BilingualMasterLabel::preferred(
                $profile->subCaste?->label_mr,
                $profile->subCaste?->label_en,
                $profile->subCaste?->label
            );
        }

        return $core;
    }

    /**
     * @param  array<string, mixed>  $intakeParsed
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $suggestionMap
     * @return list<array<string, mixed>>
     */
    private function applyExtended(
        MatrimonyProfile $profile,
        array $intakeParsed,
        array &$snapshot,
        array &$suggestionMap,
    ): array {
        $profileExtended = ExtendedFieldService::getValuesForProfile($profile);
        $intakeExtended = is_array($intakeParsed['extended'] ?? null) ? $intakeParsed['extended'] : [];
        if ($profileExtended === [] && $intakeExtended === []) {
            return [];
        }

        if (! isset($snapshot['extended']) || ! is_array($snapshot['extended'])) {
            $snapshot['extended'] = [];
        }

        $out = [];
        foreach ($intakeExtended as $ek => $ev) {
            if (! is_string($ek)) {
                continue;
            }
            if ($this->isEmptyProposed($ek, $ev)) {
                continue;
            }
            $cur = $profileExtended[$ek] ?? null;
            if ($this->isEmptyExisting($ek, $cur)) {
                $snapshot['extended'][$ek] = $ev;

                continue;
            }
            if ($this->fieldEquivalence->valuesEqual($ek, $cur, $ev, [], [], null)) {
                $snapshot['extended'][$ek] = $cur;

                continue;
            }

            $snapshot['extended'][$ek] = $cur;
            $profileDisplay = is_scalar($cur) ? trim((string) $cur) : json_encode($cur);
            $intakeDisplay = is_scalar($ev) ? trim((string) $ev) : json_encode($ev);
            $suggestionMap['extended.'.$ek] = [
                'field_key' => 'extended.'.$ek,
                'profile_existing' => true,
                'intake_display_value' => $intakeDisplay,
                'intake_apply' => $ev,
                'current_value' => $profileDisplay,
                'selected_value' => $profileDisplay,
            ];
            $out[] = [
                'key' => 'extended.'.$ek,
                'core_key' => $ek,
                'form_name' => 'snapshot[extended]['.$ek.']',
                'profile_display' => $profileDisplay,
                'intake_display' => $intakeDisplay,
                'intake_apply' => $ev,
                'section' => 'extended',
            ];
        }

        foreach ($profileExtended as $ek => $cur) {
            if (! isset($snapshot['extended'][$ek])) {
                $snapshot['extended'][$ek] = $cur;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $intakeParsed
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $sections
     * @param  array<string, mixed>  $suggestionMap
     * @return list<array<string, mixed>>
     */
    private function applyHoroscope(
        MatrimonyProfile $profile,
        array $intakeParsed,
        array &$snapshot,
        array &$sections,
        array &$suggestionMap,
    ): array {
        $profileRow = $this->profileHydrator->horoscopeRowForProfile($profile);
        if ($profileRow === []) {
            return [];
        }

        $intakeHoroscope = $intakeParsed['horoscope'] ?? [];
        $intakeRow = is_array($intakeHoroscope) && isset($intakeHoroscope[0]) && is_array($intakeHoroscope[0])
            ? $intakeHoroscope[0]
            : (is_array($intakeHoroscope) ? $intakeHoroscope : []);

        $snapshot['horoscope'] = [$profileRow];
        $sections['horoscope']['data'] = [$profileRow];

        if ($intakeRow === [] || $this->rowsEquivalent($profileRow, $intakeRow)) {
            return [];
        }

        $suggestionMap['horoscope'] = [
            'field_key' => 'horoscope',
            'profile_existing' => true,
            'intake_display_value' => __('intake.preview_horoscope_differs'),
            'intake_apply' => $intakeRow,
        ];

        return [[
            'key' => 'horoscope',
            'core_key' => 'horoscope',
            'form_name' => 'snapshot[horoscope][0]',
            'profile_display' => __('intake.preview_horoscope_keep_profile'),
            'intake_display' => __('intake.preview_horoscope_differs'),
            'intake_apply' => $intakeRow,
            'section' => 'horoscope',
        ]];
    }

    /**
     * @param  list<array<string, mixed>>  $profileRows
     * @param  list<array<string, mixed>>|array<string, mixed>  $intakeRows
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $sections
     * @return list<array<string, mixed>>
     */
    private function applyRepeaterSection(
        string $sectionKey,
        array $profileRows,
        array $intakeRows,
        array &$snapshot,
        array &$sections,
    ): array {
        if ($profileRows === []) {
            return [];
        }

        $snapshot[$sectionKey] = $profileRows;
        if ($sectionKey === 'education_history') {
            if (! isset($sections['education_history'])) {
                $sections['education_history'] = ['data' => $profileRows];
            } else {
                $sections['education_history']['data'] = $profileRows;
            }
        }
        if ($sectionKey === 'career_history' && isset($sections['career'])) {
            $sections['career']['data'] = $profileRows;
        }

        if ($intakeRows === [] || json_encode($profileRows) === json_encode($intakeRows)) {
            return [];
        }

        $label = $sectionKey === 'education_history'
            ? __('intake.preview_education_differs')
            : __('intake.preview_career_differs');

        return [[
            'key' => $sectionKey,
            'core_key' => $sectionKey,
            'form_name' => 'snapshot['.$sectionKey.']',
            'profile_display' => __('intake.preview_repeater_keep_profile'),
            'intake_display' => $label,
            'intake_apply' => $intakeRows,
            'section' => $sectionKey,
        ]];
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function rowsEquivalent(array $a, array $b): bool
    {
        $keys = ['rashi_id', 'nakshatra_id', 'gan_id', 'nadi_id', 'yoni_id', 'mangal_dosh_type_id'];
        foreach ($keys as $k) {
            if (isset($a[$k]) || isset($b[$k])) {
                if ((int) ($a[$k] ?? 0) !== (int) ($b[$k] ?? 0)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $normalizedContext
     */
    private function suggestionDisplaysAreSame(
        string $coreKey,
        string $profileDisplay,
        string $intakeDisplay,
        MatrimonyProfile $profile,
        array $normalizedProfile,
        array $normalizedIntake,
        mixed $profileVal,
        mixed $intakeVal,
    ): bool {
        if (trim($profileDisplay) !== '' && trim($intakeDisplay) !== ''
            && trim($profileDisplay) === trim($intakeDisplay)) {
            return true;
        }

        return $this->fieldEquivalence->valuesEqual(
            $coreKey,
            $profileVal,
            $intakeVal,
            $normalizedProfile,
            $normalizedIntake,
            $profile
        );
    }

    /**
     * @param  array<string, mixed>  $normalizedContext
     */
    private function displayValue(string $coreKey, mixed $value, array $normalizedContext, ?MatrimonyProfile $profile = null): string
    {
        if ($coreKey === 'date_of_birth' && is_string($value) && $value !== '') {
            try {
                return \Carbon\Carbon::parse($value)->format('d-m-Y');
            } catch (\Throwable) {
                return trim($value);
            }
        }
        if (($coreKey === 'birth_city_id' || $coreKey === 'location_id') && (int) $value > 0) {
            return (string) (\App\Models\Location::query()->find((int) $value)?->localizedName() ?? $value);
        }
        if ($coreKey === 'birth_place_text') {
            return is_scalar($value) ? trim((string) $value) : '';
        }

        $formatted = $this->displayFormatter->format($coreKey, $value, $normalizedContext, $profile);
        if ($formatted !== '') {
            return $formatted;
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param  array<string, mixed>  $normalizedIntake
     */
    private function applyPayload(string $coreKey, mixed $value, array $normalizedIntake): mixed
    {
        if ($coreKey === 'height_cm') {
            return (int) round((float) $value);
        }
        if ($coreKey === 'blood_group_id') {
            $id = is_numeric($value) ? (int) $value : null;
            if ($id !== null && $id > 0) {
                return $id;
            }
            $text = trim((string) ($normalizedIntake['blood_group'] ?? ''));
            if ($text !== '') {
                $row = MasterBloodGroup::query()->where('label', $text)->orWhere('key', $text)->first();

                return $row?->id ?? $value;
            }

            return $value;
        }
        if ($coreKey === 'religion_id') {
            return [
                'religion_id' => (int) $value,
                'religion_label' => trim((string) ($normalizedIntake['religion'] ?? $this->displayValue($coreKey, $value, $normalizedIntake))),
            ];
        }
        if ($coreKey === 'caste_id') {
            return [
                'caste_id' => (int) $value,
                'caste_label' => trim((string) ($normalizedIntake['caste'] ?? $this->displayValue($coreKey, $value, $normalizedIntake))),
            ];
        }
        if ($coreKey === 'sub_caste_id') {
            $label = trim((string) ($normalizedIntake['sub_caste'] ?? ''));
            if (is_string($value) && trim($value) !== '' && ! is_numeric(trim($value))) {
                $label = trim($value);
                $value = null;
            }
            $id = is_numeric($value) && (int) $value > 0
                ? (int) $value
                : (is_numeric($normalizedIntake['sub_caste_id'] ?? null) ? (int) $normalizedIntake['sub_caste_id'] : 0);
            if ($id < 1 && $label !== '') {
                $probe = ['sub_caste' => $label];
                if (is_numeric($normalizedIntake['caste_id'] ?? null) && (int) $normalizedIntake['caste_id'] > 0) {
                    $probe['caste_id'] = (int) $normalizedIntake['caste_id'];
                }
                $resolved = $this->controlledFieldNormalizer->normalizeCore($probe);
                $id = is_numeric($resolved['sub_caste_id'] ?? null) ? (int) $resolved['sub_caste_id'] : 0;
            }
            if ($id < 1 && $label === '') {
                $label = trim((string) $this->displayValue($coreKey, $value, $normalizedIntake));
            }

            return [
                'sub_caste_id' => $id > 0 ? $id : null,
                'subcaste_label' => $label !== '' ? $label : trim((string) $this->displayValue($coreKey, $value, $normalizedIntake)),
            ];
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if ($coreKey === 'date_of_birth' && is_string($value)) {
            try {
                return \Carbon\Carbon::parse($value)->format('Y-m-d');
            } catch (\Throwable) {
                return $value;
            }
        }

        return $value;
    }

    private function setCoreField(array &$coreData, string $coreKey, mixed $profileVal): void
    {
        if ($profileVal instanceof \DateTimeInterface) {
            $coreData[$coreKey] = $profileVal->format('Y-m-d');

            return;
        }
        $coreData[$coreKey] = $profileVal;
    }

    private function currentCoreValue(MatrimonyProfile $profile, string $fieldKey): mixed
    {
        if ($fieldKey === 'gender_id') {
            return $profile->getAttribute('gender_id');
        }
        if ($fieldKey === 'primary_contact_number') {
            if (! Schema::hasTable('profile_contacts')) {
                return null;
            }

            return DB::table('profile_contacts')
                ->where('profile_id', $profile->id)
                ->where('is_primary', true)
                ->value('phone_number');
        }
        if ($fieldKey === 'location' || $fieldKey === 'location_id') {
            if (Schema::hasColumn($profile->getTable(), 'location_id')) {
                return $profile->getAttribute('location_id');
            }

            return $profile->exists
                ? ProfileCanonicalResidenceService::locationLeafId((int) $profile->id)
                : null;
        }
        if ($fieldKey === 'address_line') {
            if (Schema::hasColumn($profile->getTable(), 'address_line')) {
                return $profile->getAttribute('address_line');
            }

            return $profile->exists
                ? ProfileCanonicalResidenceService::addressLineRaw((int) $profile->id)
                : null;
        }
        if ($fieldKey === 'birth_place_text') {
            return $profile->getAttribute('birth_place_text');
        }

        return $profile->getAttribute($fieldKey);
    }

    private function isEmptyExisting(string $fieldKey, mixed $current): bool
    {
        if ($current instanceof \DateTimeInterface) {
            return false;
        }
        if ($current === null || $current === '') {
            return true;
        }
        if (is_string($current) && trim($current) === '') {
            return true;
        }
        if (str_ends_with($fieldKey, '_id') && (int) $current === 0) {
            return true;
        }

        return false;
    }

    private function isEmptyProposed(string $fieldKey, mixed $v): bool
    {
        if ($v === null || $v === '') {
            return true;
        }
        if (is_string($v) && trim($v) === '') {
            return true;
        }
        if (str_ends_with($fieldKey, '_id') && is_numeric($v) && (int) $v === 0) {
            return true;
        }

        return false;
    }

}
