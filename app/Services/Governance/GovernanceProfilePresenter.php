<?php

namespace App\Services\Governance;

use App\Services\Location\LocationFormatterService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Builds human-facing labels and structures for the governance profile admin UI (Phase-7).
 * Does not replace engines; only presents existing comparison/snapshot payloads.
 */
class GovernanceProfilePresenter
{
    /**
     * Cache of resolved labels per (field, raw value) so repeated lookups
     * across many lineage cards don't hit the DB more than once.
     *
     * @var array<string, ?string>
     */
    private array $labelResolutionCache = [];

    /**
     * @return array{en: string, mr: string}
     */
    public static function repeaterLabel(string $repeaterKey): array
    {
        return self::REPEATER_LABELS[$repeaterKey] ?? ['en' => ucfirst(str_replace('_', ' ', $repeaterKey)), 'mr' => ''];
    }

    /** @var array<string, array{en: string, mr: string}> */
    private const FIELD_LABELS = [
        'full_name' => ['en' => 'Full name', 'mr' => 'पूर्ण नाव'],
        'gender' => ['en' => 'Gender', 'mr' => 'लिंग'],
        'date_of_birth' => ['en' => 'Date of birth', 'mr' => 'जन्मतारीख'],
        'height_cm' => ['en' => 'Height', 'mr' => 'उंची'],
        'religion' => ['en' => 'Religion', 'mr' => 'धर्म'],
        'caste' => ['en' => 'Caste', 'mr' => 'जात'],
        'education' => ['en' => 'Education', 'mr' => 'शिक्षण'],
        'occupation' => ['en' => 'Occupation', 'mr' => 'व्यवसाय'],
        'annual_income' => ['en' => 'Annual income', 'mr' => 'वार्षिक उत्पन्न'],
        'city' => ['en' => 'City / location', 'mr' => 'शहर / ठिकाण'],
        'state' => ['en' => 'State', 'mr' => 'राज्य'],
        'mother_tongue' => ['en' => 'Mother tongue', 'mr' => 'मातृभाषा'],
        'marital_status' => ['en' => 'Marital status', 'mr' => 'वैवाहिक स्थिती'],
        'family_type' => ['en' => 'Family type', 'mr' => 'कुटुंब प्रकार'],
        'complexion' => ['en' => 'Complexion', 'mr' => 'वर्ण'],
        'blood_group' => ['en' => 'Blood group', 'mr' => 'रक्त गट'],
        'nakshatra' => ['en' => 'Nakshatra', 'mr' => 'नक्षत्र'],
        'rashi' => ['en' => 'Rashi', 'mr' => 'राशी'],
        'mangal_dosh' => ['en' => 'Mangal dosh', 'mr' => 'मंगळ दोष'],
        'income_range' => ['en' => 'Income range', 'mr' => 'उत्पन्न श्रेणी'],
        'professions' => ['en' => 'Profession', 'mr' => 'व्यवसाय'],
        'partner_preferences' => ['en' => 'Partner preferences', 'mr' => 'जोडीदार पसंती'],
        'religion_id' => ['en' => 'Religion', 'mr' => 'धर्म'],
        'mother_tongue_id' => ['en' => 'Mother tongue', 'mr' => 'मातृभाषा'],
        'caste_id' => ['en' => 'Caste', 'mr' => 'जात'],
        'family_annual_income' => ['en' => 'Family annual income', 'mr' => 'कुटुंबाचे वार्षिक उत्पन्न'],
        'brothers_count' => ['en' => 'Brothers count', 'mr' => 'भावांची संख्या'],
        'sisters_count' => ['en' => 'Sisters count', 'mr' => 'बहिणींची संख्या'],
        'father_name' => ['en' => 'Father name', 'mr' => 'वडिलांचे नाव'],
        'father_occupation' => ['en' => 'Father occupation', 'mr' => 'वडिलांचा व्यवसाय'],
        'father_extra_info' => ['en' => 'Father extra info', 'mr' => 'वडिलांविषयी टीप'],
        'father_contact_1' => ['en' => 'Father contact', 'mr' => 'वडिलांचा संपर्क'],
        'mother_name' => ['en' => 'Mother name', 'mr' => 'आईचे नाव'],
        'mother_occupation' => ['en' => 'Mother occupation', 'mr' => 'आईचा व्यवसाय'],
        'mother_extra_info' => ['en' => 'Mother extra info', 'mr' => 'आईविषयी टीप'],
        'mother_contact_1' => ['en' => 'Mother contact', 'mr' => 'आईचा संपर्क'],
        'address_line' => ['en' => 'Address line', 'mr' => 'पत्ता'],
        'birth_place_text' => ['en' => 'Birth place', 'mr' => 'जन्मस्थान'],
        'work_location_text' => ['en' => 'Work location', 'mr' => 'कामाचे ठिकाण'],
        'company_name' => ['en' => 'Company name', 'mr' => 'कंपनीचे नाव'],
        'has_children' => ['en' => 'Has children', 'mr' => 'मुले आहेत का'],
        'has_siblings' => ['en' => 'Has siblings', 'mr' => 'भाऊ-बहिणी आहेत का'],
        // About me narrative (lives in `profile_extended_attributes`).
        'extended.narrative_about_me' => ['en' => 'About me — Narrative', 'mr' => 'माझ्याविषयी — वर्णन'],
        'extended.narrative_expectations' => ['en' => 'About me — Partner expectations', 'mr' => 'माझ्याविषयी — जोडीदाराकडून अपेक्षा'],
        'extended.additional_notes' => ['en' => 'About me — Additional notes', 'mr' => 'माझ्याविषयी — इतर टीप'],
        // Horoscope detailed fields (lives in `profile_horoscope_data`).
        'horoscope.charan' => ['en' => 'Horoscope — Charan', 'mr' => 'पत्रिका — चरण'],
        'horoscope.gan_id' => ['en' => 'Horoscope — Gan', 'mr' => 'पत्रिका — गण'],
        'horoscope.nadi_id' => ['en' => 'Horoscope — Nadi', 'mr' => 'पत्रिका — नाडी'],
        'horoscope.yoni_id' => ['en' => 'Horoscope — Yoni', 'mr' => 'पत्रिका — योनी'],
        'horoscope.varna_id' => ['en' => 'Horoscope — Varna', 'mr' => 'पत्रिका — वर्ण'],
        'horoscope.vashya_id' => ['en' => 'Horoscope — Vashya', 'mr' => 'पत्रिका — वश्य'],
        'horoscope.rashi_lord_id' => ['en' => 'Horoscope — Rashi lord', 'mr' => 'पत्रिका — राशी स्वामी'],
        'horoscope.devak' => ['en' => 'Horoscope — Devak', 'mr' => 'पत्रिका — देवक'],
        'horoscope.kul' => ['en' => 'Horoscope — Kul', 'mr' => 'पत्रिका — कुळ'],
        'horoscope.gotra' => ['en' => 'Horoscope — Gotra', 'mr' => 'पत्रिका — गोत्र'],
        'horoscope.navras_name' => ['en' => 'Horoscope — Navras name', 'mr' => 'पत्रिका — नवरस नाव'],
        'horoscope.birth_weekday' => ['en' => 'Horoscope — Birth weekday', 'mr' => 'पत्रिका — जन्मवार'],
        // Partner preference range filters (`profile_preference_criteria`).
        'partner_pref.preferred_age_min' => ['en' => 'Partner preference — Min age', 'mr' => 'जोडीदार पसंती — किमान वय'],
        'partner_pref.preferred_age_max' => ['en' => 'Partner preference — Max age', 'mr' => 'जोडीदार पसंती — कमाल वय'],
        'partner_pref.preferred_height_min_cm' => ['en' => 'Partner preference — Min height (cm)', 'mr' => 'जोडीदार पसंती — किमान उंची (सें.मी.)'],
        'partner_pref.preferred_height_max_cm' => ['en' => 'Partner preference — Max height (cm)', 'mr' => 'जोडीदार पसंती — कमाल उंची (सें.मी.)'],
        'partner_pref.preferred_income_min' => ['en' => 'Partner preference — Min income', 'mr' => 'जोडीदार पसंती — किमान उत्पन्न'],
        'partner_pref.preferred_income_max' => ['en' => 'Partner preference — Max income', 'mr' => 'जोडीदार पसंती — कमाल उत्पन्न'],
        'partner_pref.willing_to_relocate' => ['en' => 'Partner preference — Willing to relocate', 'mr' => 'जोडीदार पसंती — स्थलांतर मान्य'],
        'partner_pref.partner_profile_with_children' => ['en' => 'Partner preference — Children acceptance', 'mr' => 'जोडीदार पसंती — मुले स्वीकार'],
        'partner_pref.marriage_type_preference_id' => ['en' => 'Partner preference — Marriage type', 'mr' => 'जोडीदार पसंती — विवाह प्रकार'],
        'partner_pref.preferred_marital_status_id' => ['en' => 'Partner preference — Marital status', 'mr' => 'जोडीदार पसंती — वैवाहिक स्थिती'],
        'partner_pref.preferred_education' => ['en' => 'Partner preference — Education', 'mr' => 'जोडीदार पसंती — शिक्षण'],
        'partner_pref.preferred_city_id' => ['en' => 'Partner preference — City', 'mr' => 'जोडीदार पसंती — शहर'],
        'partner_pref.settled_city_preference_id' => ['en' => 'Partner preference — Settled city', 'mr' => 'जोडीदार पसंती — स्थायिक शहर'],
        'partner_pref.preferred_profile_managed_by' => ['en' => 'Partner preference — Managed by', 'mr' => 'जोडीदार पसंती — प्रोफाइल कोणाद्वारे'],
    ];

    /** Plain-language labels for comparison engine codes (admin UI only). */
    private const COMPARISON_TYPE_LABELS_EN = [
        'api_drift' => 'API issue — saved value and app API response differ',
        'missing_render' => 'Public profile issue — detail not showing on the public page',
        'cross_layer_inconsistency' => 'Profile inconsistency — saved data and displayed text differ',
        'null_propagation' => 'Missing information — saved in database but not reaching API or public page',
        'normalized_match' => 'OK after normalization',
        'semantic_mismatch' => 'Profile inconsistency — wording or format differs between layers',
        'missing_row' => 'Duplicate or incomplete rows — one layer has fewer entries',
        'row_count_mismatch' => 'Duplicate entries or row count mismatch between layers',
    ];

    /** @var array<string, array{en: string, mr: string}> */
    private const REPEATER_LABELS = [
        'siblings' => ['en' => 'Siblings', 'mr' => 'भावंडे'],
        'children' => ['en' => 'Children', 'mr' => 'मुले'],
        'relatives' => ['en' => 'Relatives', 'mr' => 'नातेवाईक'],
        'property_assets' => ['en' => 'Property', 'mr' => 'मालमत्ता'],
        'contacts' => ['en' => 'Contacts', 'mr' => 'संपर्क'],
    ];

    /**
     * @param  array<string,mixed>  $comparison
     * @param  array<string,mixed>  $snapshot
     * @param  array<string,mixed>  $comparisonTruth
     * @param  list<array<string,mixed>>  $repeaterFieldDiffs
     * @param  array<string,mixed>  $repeaterGovernance
     * @param  array{core?: array<string,mixed>, repeaters?: array<string,list<array<string,mixed>>>}  $liveProfile
     *                                                                                                               Live DB ground-truth (matrimony_profiles row + repeater rows) so that
     *                                                                                                               lineage + section panels can stay accurate even when snapshot is stale.
     * @return array<string,mixed>
     */
    public function buildViewModel(
        int $profileId,
        array $comparison,
        array $snapshot,
        array $comparisonTruth,
        array $repeaterFieldDiffs,
        array $repeaterGovernance,
        array $liveProfile = [],
    ): array {
        $issueCards = $this->buildIssueCards($profileId, $comparison, $snapshot, $comparisonTruth, $repeaterFieldDiffs);
        $silentLossRows = $this->buildSilentLossRows($comparisonTruth, $comparison);

        return [
            'issue_cards' => $issueCards,
            'repeater_tables' => $this->buildRepeaterTables($repeaterFieldDiffs, $snapshot),
            'lineage_visuals' => $this->buildLineageVisuals($comparison, $snapshot, $liveProfile),
            'api_parity' => $this->buildApiParityRows($comparisonTruth, $snapshot),
            'api_tab' => $this->buildApiTabPresentation($comparisonTruth, $snapshot),
            'repeater_structure_alerts' => $this->humanizeRepeaterMismatches($comparison['repeater_mismatches'] ?? []),
            'overview_counters' => $this->buildOverviewCounters($comparisonTruth, $comparison, $repeaterFieldDiffs),
            'health_cards' => $this->buildHealthCards($snapshot, $comparison, $comparisonTruth, $repeaterFieldDiffs, $silentLossRows),
            'issue_timeline' => $this->buildIssueTimeline($issueCards, $comparison),
            'silent_loss_rows' => $silentLossRows,
            'repeater_panels' => $this->buildRepeaterPanels($snapshot, $comparison, $repeaterFieldDiffs, $liveProfile),
            'comparison_generated_at' => (string) ($comparison['generated_at'] ?? ''),
        ];
    }

    /**
     * Human-readable label for a canonical/API field key (admin copy).
     *
     * @return array{en: string, mr: string}
     */
    public static function fieldLabelPair(string $field): array
    {
        if (isset(self::FIELD_LABELS[$field])) {
            return self::FIELD_LABELS[$field];
        }
        // Auxiliary tables fold into core with a dotted prefix
        // (e.g. `extended.narrative_about_me`, `horoscope.charan`,
        // `partner_pref.preferred_age_min`). Render a humanised group label
        // so admins immediately see "About me — Narrative about me" instead
        // of the raw key with a dot in it.
        if (str_contains($field, '.')) {
            [$ns, $rest] = explode('.', $field, 2);
            if ($ns === 'derived') {
                $parts = explode('.', $rest, 2);
                $scope = $parts[0] ?? '';
                $leafKey = $parts[1] ?? $rest;
                $humanLeaf = ucfirst(str_replace('_', ' ', preg_replace('/_id$/', '', $leafKey) ?? $leafKey));
                $scopeEn = match ($scope) {
                    'residence' => 'Current residence (derived)',
                    'birth' => 'Birth place (derived)',
                    'native' => 'Native place (derived)',
                    'work' => 'Work location (derived)',
                    default => 'Derived',
                };
                $scopeMr = match ($scope) {
                    'residence' => 'सध्याचे राहणीस्थान (उत्पन्न)',
                    'birth' => 'जन्मस्थान (उत्पन्न)',
                    'native' => 'मूळ ठिकाण (उत्पन्न)',
                    'work' => 'कामाचे ठिकाण (उत्पन्न)',
                    default => '',
                };

                return [
                    'en' => $scopeEn.' — '.$humanLeaf,
                    'mr' => $scopeMr,
                ];
            }
            $restStripped = preg_replace('/_id$/', '', $rest) ?? $rest;
            $human = ucfirst(str_replace('_', ' ', $restStripped));
            $groupEn = match ($ns) {
                'extended' => 'About me',
                'horoscope' => 'Horoscope',
                'partner_pref' => 'Partner preference',
                default => ucfirst(str_replace('_', ' ', $ns)),
            };
            $groupMr = match ($ns) {
                'extended' => 'माझ्याविषयी',
                'horoscope' => 'पत्रिका',
                'partner_pref' => 'जोडीदार पसंती',
                default => '',
            };
            // Allow exact override via FIELD_LABELS keyed on the full path.
            $direct = self::FIELD_LABELS[$field] ?? null;
            if (is_array($direct)) {
                return $direct;
            }

            return [
                'en' => $groupEn.' — '.$human,
                'mr' => $groupMr,
            ];
        }
        $stripped = preg_replace('/_id$/', '', $field) ?? $field;

        return self::FIELD_LABELS[$stripped] ?? [
            'en' => ucfirst(str_replace('_', ' ', $field)),
            'mr' => '',
        ];
    }

    /**
     * Map internal comparison codes to admin-facing explanations (never shown as raw codes).
     */
    public static function humanizeComparisonType(?string $ctype): string
    {
        $c = (string) $ctype;

        return self::COMPARISON_TYPE_LABELS_EN[$c] ?? (str_replace('_', ' ', $c) !== '' ? 'Needs review — '.str_replace('_', ' ', $c) : 'Needs review');
    }

    /**
     * @param  array<string,mixed>  $comparisonTruth
     * @param  array<string,mixed>  $snapshot
     * @return array<string,mixed>
     */
    private function buildApiTabPresentation(array $comparisonTruth, array $snapshot): array
    {
        $api = $snapshot['api']['profile'] ?? [];
        $api = is_array($api) ? $api : [];
        $missingRaw = [];
        foreach ($comparisonTruth['api_missing_fields'] ?? [] as $f) {
            if (is_string($f) && $f !== '') {
                $missingRaw[] = $f;
            }
        }
        $lines = [];
        foreach ($comparisonTruth['compared_fields'] ?? [] as $field) {
            if (! is_string($field)) {
                continue;
            }
            $fl = self::fieldLabelPair($field);
            $isMissing = in_array($field, $missingRaw, true);
            $lines[] = [
                'field' => $field,
                'label_en' => $fl['en'],
                'label_mr' => $fl['mr'],
                'ok' => ! $isMissing,
            ];
        }
        $missingLabelsEn = [];
        foreach ($missingRaw as $f) {
            $missingLabelsEn[] = self::fieldLabelPair($f)['en'];
        }

        $nestedCards = [];
        foreach (['partner_preferences'] as $nestedKey) {
            if (! array_key_exists($nestedKey, $api)) {
                continue;
            }
            $val = $api[$nestedKey];
            $fl = self::fieldLabelPair($nestedKey);
            if (is_array($val) && $val !== []) {
                $nestedCards[] = [
                    'title_en' => $fl['en'],
                    'title_mr' => $fl['mr'],
                    'ok' => true,
                    'rows' => self::flattenAssociativeForAdmin($val, 0, 12),
                ];
            } elseif ($val !== null && $val !== '') {
                $nestedCards[] = [
                    'title_en' => $fl['en'],
                    'title_mr' => $fl['mr'],
                    'ok' => true,
                    'rows' => [['label_en' => 'Value', 'label_mr' => 'मूल्य', 'value_en' => is_scalar($val) ? (string) $val : 'Present']],
                ];
            }
        }

        $checkedAt = (string) ($snapshot['captured_at'] ?? '');

        return [
            'ok' => $missingRaw === [],
            'lines' => $lines,
            'missing_fields_raw' => $missingRaw,
            'missing_labels_en' => $missingLabelsEn,
            'nested_cards' => $nestedCards,
            'checked_at' => $checkedAt,
            'failure_title_en' => 'Missing in app API',
            'failure_title_mr' => 'अ‍ॅप API मध्ये गहाळ',
            'failure_body_en' => count($missingLabelsEn) > 0
                ? 'Some information exists on the saved profile but was not found in the latest captured API response. Members using the app may see incomplete details.'
                : '',
            'failure_body_mr' => count($missingLabelsEn) > 0
                ? 'प्रोफाइलवर माहिती आहे पण कॅप्चर केलेल्या API मध्ये सापडली नाही.'
                : '',
        ];
    }

    /**
     * Short plain summary for nested preference blobs (avoid raw JSON in admin UI).
     */
    private static function summarizeArrayValueForAdmin(array $v): string
    {
        if ($v === []) {
            return '—';
        }
        $parts = [];
        foreach ($v as $ik => $iv) {
            if (count($parts) >= 6) {
                $parts[] = '…';

                break;
            }
            $ikLabel = is_string($ik) ? str_replace('_', ' ', $ik) : (string) $ik;
            if (is_array($iv)) {
                $scalarKids = array_filter($iv, fn ($x) => is_scalar($x) || $x === null);
                if (count($scalarKids) === count($iv) && $iv !== []) {
                    $parts[] = $ikLabel.': '.implode(', ', array_map(fn ($x) => (string) $x, $iv));
                } else {
                    $parts[] = $ikLabel.': (nested — see Developer diagnostics)';
                }
            } else {
                $parts[] = $ikLabel.': '.($iv === null || $iv === '' ? '—' : (string) $iv);
            }
        }

        return implode('; ', $parts);
    }

    /**
     * @return list<array{label_en: string, label_mr: string, value_en: string}>
     */
    private static function flattenAssociativeForAdmin(array $data, int $depth, int $maxRows): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if (count($out) >= $maxRows) {
                $out[] = ['label_en' => '…', 'label_mr' => '…', 'value_en' => 'More detail — open Developer diagnostics if needed'];

                break;
            }
            $keyLabel = is_string($k) ? ucfirst(str_replace('_', ' ', $k)) : (string) $k;
            if (is_array($v)) {
                $out[] = ['label_en' => $keyLabel, 'label_mr' => '', 'value_en' => self::summarizeArrayValueForAdmin($v)];
            } else {
                $out[] = ['label_en' => $keyLabel, 'label_mr' => '', 'value_en' => $v === null || $v === '' ? '—' : (string) $v];
            }
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @param  array<string,mixed>  $comparison
     * @return array<string,mixed>
     */
    public function buildLiveBadges(array $snapshot, array $comparison, array $comparisonTruth, array $repeaterFieldDiffs): array
    {
        $comparedFieldCount = count(array_filter($comparisonTruth['compared_fields'] ?? [], fn ($x) => is_string($x) && $x !== ''));
        if ($comparedFieldCount === 0) {
            return [
                ['key' => 'snapshot', 'state' => 'warn', 'label' => 'Verification incomplete'],
                ['key' => 'api_parity', 'state' => 'warn', 'label' => 'API check not ready'],
                ['key' => 'repeaters', 'state' => 'warn', 'label' => 'Section checks not ready'],
                ['key' => 'public_profile', 'state' => 'warn', 'label' => 'Public check not ready'],
            ];
        }

        $snapOk = ($snapshot['render_capture_completed'] ?? false) === true
            && ($snapshot['comparison_eligible'] ?? false) === true;
        $apiMissing = count($comparisonTruth['api_missing_fields'] ?? []);
        $snapMissing = count($comparisonTruth['snapshot_missing_fields'] ?? []);
        $apiOk = $apiMissing === 0;
        $repeaterIssues = count($repeaterFieldDiffs) > 0 || $this->repeaterMismatchHasHigh($comparison['repeater_mismatches'] ?? []);
        $publicOk = $snapMissing === 0;

        return [
            ['key' => 'snapshot', 'state' => $snapOk ? 'ok' : 'warn', 'label' => $snapOk ? 'Snapshot complete' : 'Snapshot incomplete'],
            ['key' => 'api_parity', 'state' => $apiOk ? 'ok' : 'bad', 'label' => $apiOk ? 'API matches saved data' : 'API missing some saved fields'],
            ['key' => 'repeaters', 'state' => $repeaterIssues ? 'warn' : 'ok', 'label' => $repeaterIssues ? 'Section differences found' : 'Sections look consistent'],
            ['key' => 'public_profile', 'state' => $publicOk ? 'ok' : 'bad', 'label' => $publicOk ? 'Public page shows saved details' : 'Public page missing some details'],
        ];
    }

    /**
     * @param  array<string,mixed>  $comparisonTruth
     * @param  array<string,mixed>  $comparison
     * @param  list<array<string,mixed>>  $repeaterFieldDiffs
     * @return array<string,int>
     */
    private function buildOverviewCounters(array $comparisonTruth, array $comparison, array $repeaterFieldDiffs): array
    {
        $total = count(array_filter($comparisonTruth['compared_fields'] ?? [], fn ($x) => is_string($x)));
        $mismatch = (int) (($comparison['summary']['mismatch_count'] ?? 0));
        $apiMissing = count(array_filter($comparisonTruth['api_missing_fields'] ?? [], fn ($x) => is_string($x)));
        $publicMissing = count(array_filter($comparisonTruth['snapshot_missing_fields'] ?? [], fn ($x) => is_string($x)));
        $repeaterIssues = count($repeaterFieldDiffs);
        $matched = max(0, $total - $mismatch);
        $unsupported = max(0, $mismatch - $apiMissing - $publicMissing);

        return [
            'total_governed_fields' => $total,
            'matched_fields' => $matched,
            'mismatched_fields' => $mismatch,
            'missing_in_api' => $apiMissing,
            'missing_publicly' => $publicMissing,
            'repeater_issues' => $repeaterIssues,
            'unsupported_fields' => $unsupported,
        ];
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @param  array<string,mixed>  $comparison
     * @param  array<string,mixed>  $comparisonTruth
     * @param  list<array<string,mixed>>  $repeaterFieldDiffs
     * @param  list<array<string,mixed>>  $silentLossRows
     * @return list<array<string,string|int>>
     */
    private function buildHealthCards(array $snapshot, array $comparison, array $comparisonTruth, array $repeaterFieldDiffs, array $silentLossRows): array
    {
        $snapComplete = (($snapshot['render_capture_completed'] ?? false) === true) && (($snapshot['comparison_eligible'] ?? false) === true);
        $comparedFieldCount = count(array_filter($comparisonTruth['compared_fields'] ?? [], fn ($x) => is_string($x) && $x !== ''));
        if ($comparedFieldCount === 0) {
            return [
                [
                    'title' => 'Verification status',
                    'state' => 'CRITICAL',
                    'evidence' => 'Verification incomplete: this profile has no compared items yet. Run snapshot and comparison before trusting healthy status.',
                ],
            ];
        }

        $mismatchCount = (int) ($comparison['summary']['mismatch_count'] ?? 0);
        $apiMissing = count($comparisonTruth['api_missing_fields'] ?? []);
        $publicMissing = count($comparisonTruth['snapshot_missing_fields'] ?? []);
        $repeaterIssueCount = count($repeaterFieldDiffs);
        $silentLossCount = count($silentLossRows);

        return [
            [
                'title' => 'Snapshot status',
                'state' => $snapComplete ? 'HEALTHY' : 'WARNING',
                'evidence' => $snapComplete ? 'Latest snapshot captured required sources successfully.' : 'Snapshot is incomplete or not yet comparison-ready.',
            ],
            [
                'title' => 'Comparison status',
                'state' => $mismatchCount === 0 ? 'HEALTHY' : ($mismatchCount >= 5 ? 'CRITICAL' : 'WARNING'),
                'evidence' => $mismatchCount === 0 ? 'No field mismatches in latest comparison.' : $mismatchCount.' mismatched field checks need review.',
            ],
            [
                'title' => 'API parity status',
                'state' => $apiMissing === 0 ? 'HEALTHY' : ($apiMissing >= 2 ? 'CRITICAL' : 'WARNING'),
                'evidence' => $apiMissing === 0 ? 'App API includes all checked fields.' : $apiMissing.' saved fields are missing in API response.',
            ],
            [
                'title' => 'Repeater integrity',
                'state' => $repeaterIssueCount === 0 ? 'HEALTHY' : ($repeaterIssueCount >= 5 ? 'CRITICAL' : 'WARNING'),
                'evidence' => $repeaterIssueCount === 0 ? 'Repeater sections are aligned in latest check.' : $repeaterIssueCount.' repeater cell differences were detected.',
            ],
            [
                'title' => 'Public profile integrity',
                'state' => $publicMissing === 0 ? 'HEALTHY' : ($publicMissing >= 2 ? 'CRITICAL' : 'WARNING'),
                'evidence' => $publicMissing === 0 ? 'Public profile shows all checked fields.' : $publicMissing.' fields are saved but not visible publicly.',
            ],
            [
                'title' => 'Silent data loss risk',
                'state' => $silentLossCount === 0 ? 'HEALTHY' : ($silentLossCount >= 2 ? 'CRITICAL' : 'WARNING'),
                'evidence' => $silentLossCount === 0 ? 'No silent data loss detected in latest run.' : $silentLossCount.' field(s) exist in DB but are missing in downstream layers.',
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $mismatches
     */
    private function repeaterMismatchHasHigh(array $mismatches): bool
    {
        foreach ($mismatches as $m) {
            if (is_array($m) && ($m['severity'] ?? '') === 'high') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string,mixed>>  $mismatches
     * @return list<array<string,mixed>>
     */
    private function humanizeRepeaterMismatches(array $mismatches): array
    {
        $out = [];
        foreach ($mismatches as $m) {
            if (! is_array($m)) {
                continue;
            }
            $rep = (string) ($m['repeater'] ?? '');
            $type = (string) ($m['type'] ?? '');
            $lbl = self::REPEATER_LABELS[$rep] ?? ['en' => ucfirst(str_replace('_', ' ', $rep)), 'mr' => ''];
            $human = match ($type) {
                'row_count_mismatch' => [
                    'en' => 'Duplicate entries or row count mismatch in '.$lbl['en'].' — needs review.',
                    'mr' => $lbl['mr'] !== '' ? 'डुप्लिकेट किंवा ओळींची संख्या जुळत नाही — तपासणी करा.' : '',
                ],
                'reordered_rows_tolerated' => [
                    'en' => 'Row order differs in '.$lbl['en'].' (usually safe).',
                    'mr' => $lbl['mr'] !== '' ? 'ओळींचा क्रम वेगळा आहे (सामान्यपणे ठीक).' : '',
                ],
                'partial_row_corruption' => [
                    'en' => 'Some rows in '.$lbl['en'].' could not be read cleanly.',
                    'mr' => $lbl['mr'] !== '' ? 'काही ओळी नीट वाचता आल्या नाहीत.' : '',
                ],
                default => [
                    'en' => 'Data inconsistency in '.$lbl['en'].'.',
                    'mr' => $lbl['mr'] !== '' ? 'डेटामध्ये तफावत.' : '',
                ],
            };
            $out[] = [
                'repeater' => $rep,
                'title_en' => $lbl['en'],
                'title_mr' => $lbl['mr'],
                'severity' => (string) ($m['severity'] ?? 'low'),
                'what_en' => $human['en'],
                'what_mr' => $human['mr'],
            ];
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $comparison
     * @param  array<string,mixed>  $snapshot
     * @param  array<string,mixed>  $comparisonTruth
     * @param  list<array<string,mixed>>  $repeaterFieldDiffs
     * @return list<array<string,mixed>>
     */
    private function buildIssueCards(
        int $profileId,
        array $comparison,
        array $snapshot,
        array $comparisonTruth,
        array $repeaterFieldDiffs,
    ): array {
        $cards = [];
        foreach ($comparison['comparisons'] ?? [] as $row) {
            if (! is_array($row) || ($row['status'] ?? '') !== 'fail') {
                continue;
            }
            $field = (string) ($row['field'] ?? '');
            $ctype = (string) ($row['comparison_type'] ?? '');
            $fl = self::fieldLabelPair($field);
            $expl = $this->explainComparisonFailure($field, $ctype, $row);
            $eff = strtolower((string) ($row['effective_severity'] ?? $row['severity'] ?? 'medium'));
            $sev = $this->normalizeSeverity($eff, $ctype);
            $cards[] = [
                'id' => 'scalar_'.$field.'_'.$ctype,
                'severity' => $sev,
                'title_en' => $expl['title_en'],
                'title_mr' => $expl['title_mr'],
                'profile_id' => $profileId,
                'layer_en' => $expl['layer_en'],
                'layer_mr' => $expl['layer_mr'],
                'what_en' => $expl['what_en'],
                'what_mr' => $expl['what_mr'],
                'impact_en' => $expl['impact_en'],
                'impact_mr' => $expl['impact_mr'],
                'affected_label_en' => $fl['en'],
                'affected_label_mr' => $fl['mr'],
            ];
        }
        foreach ($comparisonTruth['api_missing_fields'] ?? [] as $field) {
            if (! is_string($field)) {
                continue;
            }
            $fl = self::fieldLabelPair($field);
            $cards[] = [
                'id' => 'api_missing_'.$field,
                'severity' => 'high',
                'title_en' => $fl['en'].' not returned in the app API',
                'title_mr' => $fl['mr'] !== '' ? $fl['mr'].' API मध्ये दिसत नाही' : 'API मध्ये फील्ड दिसत नाही',
                'profile_id' => $profileId,
                'layer_en' => 'Saved profile → API response',
                'layer_mr' => 'जतन केलेला प्रोफाइल → API प्रतिसाद',
                'what_en' => 'The value exists on the saved profile, but the mobile/API response does not include this field.',
                'what_mr' => 'मूल्य प्रोफाइलवर आहे पण API प्रतिसादात हे फील्ड नाही.',
                'impact_en' => 'Members using the app may see incomplete information.',
                'impact_mr' => 'अ‍ॅप वापरणाऱ्या सदस्यांना अपूर्ण माहिती दिसू शकते.',
                'affected_label_en' => $fl['en'],
                'affected_label_mr' => $fl['mr'],
            ];
        }
        foreach ($comparisonTruth['snapshot_missing_fields'] ?? [] as $field) {
            if (! is_string($field)) {
                continue;
            }
            $fl = self::fieldLabelPair($field);
            $cards[] = [
                'id' => 'public_missing_'.$field,
                'severity' => 'high',
                'title_en' => $fl['en'].' not visible on the public profile page',
                'title_mr' => $fl['mr'] !== '' ? $fl['mr'].' सार्वजनिक पृष्ठावर दिसत नाही' : 'सार्वजनिक पृष्ठावर दिसत नाही',
                'profile_id' => $profileId,
                'layer_en' => 'Saved profile → Public profile page',
                'layer_mr' => 'जतन केलेला प्रोफाइल → सार्वजनिक पृष्ठ',
                'what_en' => 'Data is saved, but the public profile page did not show this value when we checked.',
                'what_mr' => 'डेटा जतन आहे पण सार्वजनिक पृष्ठावर हे मूल्य दिसले नाही.',
                'impact_en' => 'Visitors may think this detail is missing.',
                'impact_mr' => 'भेट देणाऱ्यांना तपशील गहाळ वाटू शकतो.',
                'affected_label_en' => $fl['en'],
                'affected_label_mr' => $fl['mr'],
            ];
        }
        $byRepeater = [];
        foreach ($repeaterFieldDiffs as $d) {
            if (! is_array($d)) {
                continue;
            }
            $rep = (string) ($d['repeater'] ?? '');
            $byRepeater[$rep] = ($byRepeater[$rep] ?? 0) + 1;
        }
        foreach ($byRepeater as $rep => $cnt) {
            if ($cnt === 0) {
                continue;
            }
            $lbl = self::REPEATER_LABELS[$rep] ?? ['en' => ucfirst(str_replace('_', ' ', $rep)), 'mr' => ''];
            $cards[] = [
                'id' => 'repeater_diff_'.$rep,
                'severity' => 'medium',
                'title_en' => $lbl['en'].' differs between wizard and public page',
                'title_mr' => $lbl['mr'] !== '' ? $lbl['mr'].' मध्ये विझार्ड आणि सार्वजनिक पृष्ठ यात तफावत' : 'तपशीलात तफावत',
                'profile_id' => $profileId,
                'layer_en' => 'Wizard page → Public profile page',
                'layer_mr' => 'विझार्ड पृष्ठ → सार्वजनिक पृष्ठ',
                'what_en' => 'We found '.$cnt.' cell difference(s) when comparing how this section appears in the full wizard versus the public profile.',
                'what_mr' => 'पूर्ण विझार्ड आणि सार्वजनिक पृष्ठ यात '.$cnt.' फरक आढळले.',
                'impact_en' => 'Visitors may see different details than what was entered in the long form.',
                'impact_mr' => 'भेट देणाऱ्यांना लांब फॉर्ममधील माहितीपेक्षा वेगळे दिसू शकते.',
                'affected_label_en' => $lbl['en'],
                'affected_label_mr' => $lbl['mr'],
            ];
        }

        $rank = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        usort($cards, fn ($a, $b) => ($rank[$a['severity']] ?? 9) <=> ($rank[$b['severity']] ?? 9));

        return $cards;
    }

    /**
     * Prefer engine-provided severity (critical/high/medium/low); fall back to comparison-type rules.
     */
    private function normalizeSeverity(string $effective, string $ctype): string
    {
        if (in_array($effective, ['critical', 'high', 'medium', 'low'], true)) {
            return $effective;
        }

        return $this->mapSeverity($effective, $ctype);
    }

    /**
     * @param  list<array<string,mixed>>  $repeaterFieldDiffs
     * @param  array<string,mixed>  $snapshot
     * @return array<string, list<array<string,mixed>>>
     */
    private function buildRepeaterTables(array $repeaterFieldDiffs, array $snapshot): array
    {
        $grouped = [];
        foreach ($repeaterFieldDiffs as $d) {
            if (! is_array($d)) {
                continue;
            }
            $rep = (string) ($d['repeater'] ?? 'other');
            $pub = $d['public_profile'] ?? ($d['normalized']['public_render'] ?? null);
            $wiz = $d['wizard'] ?? null;
            $grouped[$rep][] = [
                'row' => (int) ($d['row'] ?? 0),
                'field' => (string) ($d['field'] ?? ''),
                'wizard' => $wiz,
                'api' => $d['api'] ?? null,
                'public' => $pub,
                'ok' => $this->repeaterCellsMatch($wiz, $pub, $d['api'] ?? null),
            ];
        }
        foreach ($grouped as $rep => &$rows) {
            usort($rows, fn ($a, $b) => [$a['row'], $a['field']] <=> [$b['row'], $b['field']]);
        }

        return $grouped;
    }

    private function repeaterCellsMatch(mixed $wizard, mixed $public, mixed $api): bool
    {
        if ($wizard === null && $public === null) {
            return true;
        }

        $wiz = $this->normalizeCellValue($wizard);
        $pub = $this->normalizeCellValue($public);
        $apiNorm = $this->normalizeCellValue($api);

        return $wiz === $pub && ($api === null || $wiz === $apiNorm);
    }

    private function normalizeCellValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        if (is_array($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return $encoded !== false ? $encoded : '';
        }

        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Build a flow card per scalar field across **all** layers we have data for —
     * not just a hardcoded shortlist. We union together:
     *   - every `field` in `comparison['comparisons']`
     *   - every wizard rendered key in `snapshot['rendered']['fields_by_source']['wizard']`
     *   - every scalar key in `snapshot['db']`
     *   - every scalar column on the *live* `matrimony_profiles` row (when supplied)
     * so that if a member filled 200 fields, the admin sees 200 mini flows in
     * the “How data flows” tab — even when the snapshot is stale or partial.
     * Audit/internal columns are filtered out.
     *
     * @param  array<string,mixed>  $comparison
     * @param  array<string,mixed>  $snapshot
     * @param  array{core?: array<string,mixed>, repeaters?: array<string,list<array<string,mixed>>>}  $liveProfile
     * @return list<array<string,mixed>>
     */
    private function buildLineageVisuals(array $comparison, array $snapshot, array $liveProfile = []): array
    {
        $rowsByField = [];
        foreach (($comparison['comparisons'] ?? []) as $row) {
            if (! is_array($row) || ! is_string($row['field'] ?? null) || $row['field'] === '') {
                continue;
            }
            $rowsByField[(string) $row['field']] = $row;
        }
        $wizardMap = is_array($snapshot['rendered']['fields_by_source']['wizard'] ?? null)
            ? $snapshot['rendered']['fields_by_source']['wizard']
            : [];
        $dbMap = is_array($snapshot['db'] ?? null) ? $snapshot['db'] : [];
        $liveCore = is_array($liveProfile['core'] ?? null) ? $liveProfile['core'] : [];

        // Internal / audit / system columns the admin should NOT see in the
        // human-facing "How data flows" tab. They aren't part of the member
        // biodata SSOT. (Repeaters and arrays live in the Profile sections tab.)
        $internalSkip = [
            'id', 'user_id', 'created_at', 'updated_at', 'deleted_at',
            'edited_at', 'edited_by', 'edit_reason', 'edited_source',
            'admin_edited_fields', 'pending_intake_suggestions_json',
            'photo_moderation_snapshot', 'safety_defaults_applied',
            'card_onboarding_resume_step', 'photo_rejection_reason',
            'photo_rejected_at', 'visibility_override', 'visibility_override_reason',
            'is_suspended', 'lifecycle_state', 'is_showcase',
        ];

        // Column alias map: live `matrimony_profiles` column name → canonical
        // snapshot/comparison field key. Without this we end up showing two
        // separate flow cards for the same logical field — e.g. "education"
        // (the snapshot key, populated from `highest_education`) and
        // "highest_education" (the live column itself). Admins read that as
        // a duplicated field; it is not. We always fold the column name into
        // its snapshot key so each biodata field appears exactly once.
        $columnAlias = $this->columnAliasMap();

        // Comparison rows can sometimes appear under a raw column name (e.g.
        // `highest_education`) and again under the snapshot alias
        // (`education`) on the same lineage tab — collapse them through the
        // alias map so we expose one canonical key per logical field.
        // We also keep the alias-collapsed key on `$rowsByField` so the
        // downstream lookup `$rowsByField[$field]` finds it.
        $rowsByCanon = [];
        foreach ($rowsByField as $f => $rowData) {
            $canon = $columnAlias[$f] ?? $f;
            $rowsByCanon[$canon] = $rowData;
        }
        $rowsByField = $rowsByCanon;

        $allFields = [];
        foreach (array_keys($rowsByField) as $f) {
            if (! in_array($f, $internalSkip, true)) {
                $allFields[$f] = true;
            }
        }
        foreach (array_keys($wizardMap) as $f) {
            if (is_string($f) && $f !== '' && ! in_array($f, $internalSkip, true)) {
                $allFields[$columnAlias[$f] ?? $f] = true;
            }
        }
        foreach ($dbMap as $f => $v) {
            if (! is_string($f) || $f === '' || in_array($f, $internalSkip, true)) {
                continue;
            }
            // Skip nested arrays/objects (repeaters & sub-sections live in
            // the Profile sections tab; lineage is for scalar fields).
            if (is_array($v)) {
                continue;
            }
            $allFields[$columnAlias[$f] ?? $f] = true;
        }
        // Live DB row is the ground-truth column list — include every scalar
        // column we read from `matrimony_profiles` directly, so the universe
        // matches the "Filled in DB" counter (which uses the same row).
        // De-dupe: if the live column maps to a snapshot key (e.g.
        // `highest_education` → `education`), register the snapshot key
        // only — never both, otherwise the same biodata field shows twice.
        foreach ($liveCore as $f => $v) {
            if (! is_string($f) || $f === '' || in_array($f, $internalSkip, true)) {
                continue;
            }
            if (is_array($v)) {
                continue;
            }
            $allFields[$columnAlias[$f] ?? $f] = true;
        }

        // Stable ordering: high-priority biodata first, then alphabetic by key
        // so the same admin sees the same layout across profiles. We use the
        // canonical snapshot keys here (post-alias collapse) so an entry like
        // `highest_education` doesn't appear separately from `education`.
        $priority = [
            'full_name', 'date_of_birth', 'gender', 'mother_tongue',
            'religion', 'caste', 'sub_caste', 'height_cm', 'weight_kg',
            'marital_status', 'has_children', 'education',
            'occupation', 'company_name',
            'annual_income', 'family_annual_income', 'income_range',
            'city', 'state', 'address_line', 'birth_place_text', 'work_location_text',
            'father_name', 'father_occupation', 'father_extra_info', 'father_contact_1',
            'mother_name', 'mother_occupation', 'mother_extra_info', 'mother_contact_1',
            'family_type',
            'complexion', 'physical_build', 'blood_group',
        ];
        $orderedPrio = [];
        foreach ($priority as $p) {
            if (isset($allFields[$p])) {
                $orderedPrio[] = $p;
                unset($allFields[$p]);
            }
        }
        $rest = array_keys($allFields);
        sort($rest);
        $finalOrder = array_merge($orderedPrio, $rest);

        // Reverse alias: snapshot key → list of live column names that store
        // the same field. Used so we can read live DB value for canonical keys
        // like "education" by falling back to the actual `highest_education`
        // column on the matrimony_profiles row.
        $aliasReverse = [];
        foreach ($columnAlias as $col => $canon) {
            $aliasReverse[$canon][] = $col;
        }

        $liveRepeaters = is_array($liveProfile['repeaters'] ?? null) ? $liveProfile['repeaters'] : [];

        $out = [];
        foreach ($finalOrder as $field) {
            $row = $rowsByField[$field] ?? [];
            $fl = self::fieldLabelPair($field);
            // Wizard rendered map can be keyed by either the snapshot alias
            // (e.g. `education`) or the underlying form input name
            // (`highest_education`). Try the canonical key first, then walk
            // the reverse alias chain so we pick whichever was captured.
            $wizRaw = is_array($wizardMap[$field] ?? null)
                ? ($wizardMap[$field]['raw_rendered'] ?? null)
                : null;
            if ($wizRaw === null && isset($aliasReverse[$field])) {
                foreach ($aliasReverse[$field] as $col) {
                    $cand = is_array($wizardMap[$col] ?? null)
                        ? ($wizardMap[$col]['raw_rendered'] ?? null)
                        : null;
                    if ($cand !== null && $cand !== '') {
                        $wizRaw = $cand;
                        break;
                    }
                }
            }
            // Prefer comparison row, then snapshot.db, then live DB row, then
            // — for canonical keys like "education" / "city" / "religion" /
            // "occupation" — walk the alias list. Each alias entry might be
            // either a live `matrimony_profiles` column (`profession_id`,
            // `highest_education`) OR another snapshot.db key (`professions`)
            // that holds the same logical concept. We try the snapshot first
            // (so we honour what the python engine captured) then the live
            // row. This keeps DB values accurate even when the snapshot is
            // stale, AND keeps a single canonical card showing the value
            // wherever the member actually filled it.
            $db = $row['db'] ?? ($dbMap[$field] ?? ($liveCore[$field] ?? null));
            if (! $this->isFilled($db) && isset($aliasReverse[$field])) {
                foreach ($aliasReverse[$field] as $alias) {
                    if (array_key_exists($alias, $dbMap) && $this->isFilled($dbMap[$alias])) {
                        $db = $dbMap[$alias];
                        break;
                    }
                    if (array_key_exists($alias, $liveCore) && $this->isFilled($liveCore[$alias])) {
                        $db = $liveCore[$alias];
                        break;
                    }
                }
            }
            $db = $this->enrichLineageDbValue($field, $db, $liveCore, $liveRepeaters);
            $api = $row['api'] ?? null;
            $ren = $row['rendered'] ?? null;
            $status = (string) ($row['status'] ?? '');
            $ctype = (string) ($row['comparison_type'] ?? '');
            $hasComparison = $row !== [];

            $wizFilled = $this->isFilled($wizRaw);
            $dbFilled = $this->isFilled($db);
            $apiFilled = $this->isFilled($api);
            $renFilled = $this->isFilled($ren);

            // Resolve a human label for FK-style numeric values. Used for both
            // display (e.g. "37648 (= Kadegaon, Sangli 415304)") and for the
            // semantic-equivalence check below — so an admin sees that the DB
            // ID and the public profile string mean the same place.
            $dbResolved = $dbFilled ? $this->resolveLabel($field, $db) : null;
            $apiResolved = $apiFilled ? $this->resolveLabel($field, $api) : null;
            $wizResolved = $wizFilled ? $this->resolveLabel($field, $wizRaw) : null;
            $renResolved = $renFilled ? $this->resolveLabel($field, $ren) : null;

            // Semantic equivalence vs DB — covers date format ("9 Feb 1982" ≡
            // "1982-02-09") and FK↔label ("37648" ≡ "Kadegaon, Sangli 415304").
            $wizEquiv = $wizFilled && $dbFilled && $this->semanticallyEquivalent($field, $wizRaw, $db);
            $apiEquiv = $apiFilled && $dbFilled && $this->semanticallyEquivalent($field, $api, $db);
            $renEquiv = $renFilled && $dbFilled && $this->semanticallyEquivalent($field, $ren, $db);

            // Per-step state: ok | equivalent | stale | mismatch | missing
            // - ok         : value present, exact match (or no comparison verdict)
            // - equivalent : value present and *semantically* matches DB (date
            //                format, FK id↔label) — engine flagged a mismatch
            //                but values mean the same thing
            // - stale      : layer's snapshot value is empty BUT live DB has the
            //                value (snapshot capture missed it)
            // - mismatch   : the comparison engine flagged this layer wrong AND
            //                values do NOT semantically match
            // - missing    : the value really is empty in this layer
            $apiFlagged = $status === 'fail' && $ctype === 'api_drift';
            $publicFlagged = $status === 'fail' && in_array($ctype, ['missing_render', 'cross_layer_inconsistency'], true);

            $wizState = $wizFilled
                ? ($wizEquiv && (string) $wizRaw !== (string) $db ? 'equivalent' : 'ok')
                : ($dbFilled ? 'stale' : 'missing');
            $dbState = $dbFilled ? 'ok' : 'missing';
            // For API + public layers we *cannot* prove a real mismatch when
            // the layer's snapshot value is empty — the engine flagged the
            // field, but the snapshot may simply be older than the live DB
            // row (admin just edited the field, member just filled wizard).
            // Treat "engine flagged, but layer value is blank, and DB has the
            // value" as `stale` (snapshot pending) instead of `mismatch`.
            // This keeps behaviour consistent with the wizard layer and
            // prevents false MISMATCH ❌ badges when the live public profile
            // page actually does render the value (admin can verify with
            // "Open public profile"). A genuine mismatch only fires when the
            // layer captured a different non-empty value than the DB.
            if ($apiFilled) {
                $apiState = $apiFlagged
                    ? ($apiEquiv ? 'equivalent' : 'mismatch')
                    : (($apiEquiv && (string) $api !== (string) $db) ? 'equivalent' : 'ok');
            } else {
                $apiState = $dbFilled ? 'stale' : 'missing';
            }
            if ($renFilled) {
                $publicState = $publicFlagged
                    ? ($renEquiv ? 'equivalent' : 'mismatch')
                    : (($renEquiv && (string) $ren !== (string) $db) ? 'equivalent' : 'ok');
            } else {
                $publicState = $dbFilled ? 'stale' : 'missing';
            }

            $steps = [
                [
                    'key' => 'wizard',
                    'label_en' => 'Wizard',
                    'label_mr' => 'विझार्ड',
                    'value' => $wizFilled ? $wizRaw : ($wizState === 'stale' ? $db : null),
                    'resolved_label' => $wizResolved,
                    'ok' => in_array($wizState, ['ok', 'equivalent', 'stale'], true),
                    'state' => $wizState,
                ],
                [
                    'key' => 'db',
                    'label_en' => 'Database',
                    'label_mr' => 'डेटाबेस',
                    'value' => $db,
                    'resolved_label' => $dbResolved,
                    'ok' => $dbState === 'ok',
                    'state' => $dbState,
                ],
                [
                    'key' => 'api',
                    'label_en' => 'API',
                    'label_mr' => 'API',
                    'value' => $apiFilled ? $api : ($apiState === 'stale' ? $db : null),
                    'resolved_label' => $apiResolved,
                    'ok' => in_array($apiState, ['ok', 'equivalent', 'stale'], true),
                    'state' => $apiState,
                ],
                [
                    'key' => 'public',
                    'label_en' => 'Public profile',
                    'label_mr' => 'सार्वजनिक प्रोफाइल',
                    'value' => $renFilled ? $ren : ($publicState === 'stale' ? $db : null),
                    'resolved_label' => $renResolved,
                    'ok' => in_array($publicState, ['ok', 'equivalent', 'stale'], true),
                    'state' => $publicState,
                ],
            ];

            // Overall status calculation.
            $stateList = [$wizState, $dbState, $apiState, $publicState];
            $hasMismatch = in_array('mismatch', $stateList, true);
            $hasMissing = in_array('missing', $stateList, true);
            $hasStale = in_array('stale', $stateList, true);
            $hasEquivalent = in_array('equivalent', $stateList, true);
            $allOkOrEquivalent = count(array_filter($stateList, static fn ($s) => $s === 'ok' || $s === 'equivalent')) === 4;
            $allEmptyOrMissing = $stateList === ['missing', 'missing', 'missing', 'missing'];

            $root = null;
            if ($hasMismatch) {
                $root = match ($ctype) {
                    'api_drift' => ['en' => 'Saved profile and API response disagree for this field.', 'mr' => 'जतन डेटा आणि API मूल्य जुळत नाही.'],
                    'missing_render' => ['en' => 'Public profile rendering did not include this value.', 'mr' => 'सार्वजनिक रेंडरिंगमध्ये हे मूल्य आले नाही.'],
                    'cross_layer_inconsistency' => ['en' => 'Public profile shows a value that does not match the saved data — review the wording or formatting.', 'mr' => 'सार्वजनिक पृष्ठावरील मूल्य आणि जतन डेटा जुळत नाही — मजकूर तपासा.'],
                    default => ['en' => 'Profile inconsistency needs review.', 'mr' => 'प्रोफाइल तफावत तपासा.'],
                };
            } elseif ($hasEquivalent) {
                $root = [
                    'en' => 'Different formats but the saved value, API and public page all mean the same thing.',
                    'mr' => 'फॉरमॅट वेगळा आहे पण जतन, API आणि सार्वजनिक पृष्ठ — तिन्ही ठिकाणी अर्थ एकच आहे.',
                ];
            } elseif ($hasStale && $dbFilled) {
                $root = [
                    'en' => 'Database has this value but the snapshot did not capture some layers — rebuild snapshot then re-run comparison to verify.',
                    'mr' => 'डेटाबेसमध्ये हे मूल्य आहे पण snapshot मध्ये काही layers कॅप्चर झाले नाहीत — snapshot rebuild करून comparison पुन्हा चालवा.',
                ];
            }

            // Overall status — used for color coding + sort priority.
            //  fail       = a layer has a real mismatch (and not just format)
            //  stale      = no fail/mismatch, but a layer is "snapshot pending"
            //  equivalent = no fail, but at least one layer differs only in format / FK
            //  pass       = every layer ok
            //  empty      = nothing in any layer (true blank field)
            //  partial    = some layers ok, no DB value, no real fail
            if ($hasMismatch) {
                $overall = 'fail';
            } elseif ($allOkOrEquivalent && $hasEquivalent && ! $hasStale) {
                $overall = 'equivalent';
            } elseif ($allOkOrEquivalent && ! $hasStale) {
                $overall = 'pass';
            } elseif ($allEmptyOrMissing) {
                $overall = 'empty';
            } elseif ($dbFilled && $hasMissing && ! $hasStale) {
                $overall = 'fail';
            } elseif ($hasStale) {
                $overall = 'stale';
            } else {
                $overall = 'partial';
            }

            $out[] = [
                'field' => $field,
                'title_en' => $fl['en'],
                'title_mr' => $fl['mr'],
                'overall_ok' => $overall === 'pass',
                'overall_status' => $overall,
                'has_comparison' => $hasComparison,
                'steps' => $steps,
                'root_cause' => $root,
            ];
        }

        // Sort: fails first → stale → partial → equivalent → empty → pass;
        // preserve priority/alpha order within each bucket so the page is
        // stable for repeat audits.
        $rank = ['fail' => 0, 'stale' => 1, 'partial' => 2, 'equivalent' => 3, 'empty' => 4, 'pass' => 5];
        $indexed = [];
        foreach ($out as $i => $r) {
            $indexed[] = [$rank[$r['overall_status']] ?? 5, $i, $r];
        }
        usort($indexed, fn ($a, $b) => $a[0] === $b[0] ? $a[1] <=> $b[1] : $a[0] <=> $b[0]);

        return array_map(static fn (array $t): array => $t[2], $indexed);
    }

    private function isFilled(mixed $v): bool
    {
        if ($v === null || $v === '') {
            return false;
        }
        if (is_array($v)) {
            return $v !== [];
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $comparisonTruth
     * @param  array<string,mixed>  $snapshot
     * @return array{rows: list<array<string,mixed>>, missing: list<string>, checked_at: string, ok: bool}
     */
    private function buildApiParityRows(array $comparisonTruth, array $snapshot): array
    {
        $api = $snapshot['api']['profile'] ?? [];
        $api = is_array($api) ? $api : [];
        $missing = [];
        foreach ($comparisonTruth['api_missing_fields'] ?? [] as $f) {
            if (is_string($f) && $f !== '') {
                $missing[] = $f;
            }
        }
        $rows = [];
        foreach ($comparisonTruth['compared_fields'] ?? [] as $field) {
            if (! is_string($field)) {
                continue;
            }
            $fl = self::fieldLabelPair($field);
            $isMissing = in_array($field, $missing, true);
            $rows[] = [
                'field' => $field,
                'label_en' => $fl['en'],
                'ok' => ! $isMissing,
            ];
        }

        return [
            'rows' => $rows,
            'missing' => $missing,
            'checked_at' => (string) ($snapshot['captured_at'] ?? ''),
            'ok' => $missing === [],
        ];
    }

    /**
     * @param  array<string,mixed>  $comparisonTruth
     * @param  array<string,mixed>  $comparison
     * @return list<array<string,mixed>>
     */
    private function buildSilentLossRows(array $comparisonTruth, array $comparison): array
    {
        $rows = [];
        $apiMissing = array_values(array_filter($comparisonTruth['api_missing_fields'] ?? [], fn ($x) => is_string($x)));
        $publicMissing = array_values(array_filter($comparisonTruth['snapshot_missing_fields'] ?? [], fn ($x) => is_string($x)));
        foreach (($comparison['comparisons'] ?? []) as $r) {
            if (! is_array($r) || ! is_string($r['field'] ?? null)) {
                continue;
            }
            $field = (string) $r['field'];
            $dbValue = $r['db'] ?? null;
            if (! $this->isFilled($dbValue)) {
                continue;
            }
            $apiMiss = in_array($field, $apiMissing, true) || ! $this->isFilled($r['api'] ?? null);
            $pubMiss = in_array($field, $publicMissing, true) || ! $this->isFilled($r['rendered'] ?? null);
            if (! $apiMiss && ! $pubMiss) {
                continue;
            }
            $fl = self::fieldLabelPair($field);
            $rows[] = [
                'field' => $field,
                'label_en' => $fl['en'],
                'db_value' => $dbValue,
                'api_missing' => $apiMiss,
                'public_missing' => $pubMiss,
                'severity' => ($apiMiss && $pubMiss) ? 'critical' : 'warning',
                'probable_failure_point' => $apiMiss ? 'API serializer or API response mapping' : 'Public profile rendering layer',
            ];
        }
        usort($rows, fn ($a, $b) => (($a['severity'] === 'critical') ? 0 : 1) <=> (($b['severity'] === 'critical') ? 0 : 1));

        return $rows;
    }

    /**
     * @param  list<array<string,mixed>>  $issueCards
     * @param  array<string,mixed>  $comparison
     * @return list<array<string,string>>
     */
    private function buildIssueTimeline(array $issueCards, array $comparison): array
    {
        $timeline = [];
        foreach (array_slice($issueCards, 0, 12) as $card) {
            $timeline[] = [
                'severity' => strtoupper((string) ($card['severity'] ?? 'warning')),
                'layer' => (string) ($card['layer_en'] ?? 'Profile data layers'),
                'field' => (string) ($card['affected_label_en'] ?? ''),
                'message' => (string) ($card['what_en'] ?? ''),
                'action' => 'Recommended action: Rebuild snapshot, re-run comparison, then review affected profile layer.',
            ];
        }
        if (($comparison['generated_at'] ?? '') !== '') {
            $timeline[] = [
                'severity' => 'INFO',
                'layer' => 'Snapshot and comparison run',
                'field' => 'System',
                'message' => 'Latest comparison completed at '.(string) $comparison['generated_at'].'.',
                'action' => 'Recommended action: Review warning/critical items first.',
            ];
        }

        return $timeline;
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @param  array<string,mixed>  $comparison
     * @param  list<array<string,mixed>>  $repeaterFieldDiffs
     * @param  array{core?: array<string,mixed>, repeaters?: array<string,list<array<string,mixed>>>}  $liveProfile
     * @return list<array<string,mixed>>
     */
    private function buildRepeaterPanels(array $snapshot, array $comparison, array $repeaterFieldDiffs, array $liveProfile = []): array
    {
        $byRep = [];
        foreach ($repeaterFieldDiffs as $d) {
            if (! is_array($d)) {
                continue;
            }
            $rep = (string) ($d['repeater'] ?? 'other');
            if (! isset($byRep[$rep])) {
                $byRep[$rep] = ['mismatched_rows' => 0, 'duplicate_rows' => 0, 'missing_rows' => 0];
            }
            $status = (string) ($d['status'] ?? '');
            $cmpType = (string) ($d['comparison_type'] ?? '');
            if ($status === 'missing_row' || $cmpType === 'missing_row') {
                $byRep[$rep]['missing_rows']++;
            } elseif ($status === 'mismatch') {
                $byRep[$rep]['mismatched_rows']++;
            }
            if ($cmpType === 'row_count_mismatch') {
                $byRep[$rep]['duplicate_rows']++;
            }
        }

        $rgByRepeater = is_array($snapshot['repeater_governance']['by_repeater'] ?? null)
            ? $snapshot['repeater_governance']['by_repeater']
            : [];
        $liveRepeaters = is_array($liveProfile['repeaters'] ?? null) ? $liveProfile['repeaters'] : [];
        $allRepeaterKeys = array_values(array_unique(array_merge(
            array_keys(self::REPEATER_LABELS),
            array_keys($rgByRepeater),
            array_keys($byRep),
            array_keys($liveRepeaters),
        )));
        $panels = [];
        foreach ($allRepeaterKeys as $rep) {
            $lbl = self::repeaterLabel($rep);
            $gov = is_array($rgByRepeater[$rep] ?? null) ? $rgByRepeater[$rep] : [];
            $snapshotDbRows = (int) ($gov['wizard_row_count'] ?? 0);
            $publicRows = (int) ($gov['public_row_count'] ?? 0);
            $liveRows = is_array($liveRepeaters[$rep] ?? null) ? count($liveRepeaters[$rep]) : 0;

            // Live DB is the source of truth; snapshot count is shown as a
            // secondary signal and triggers a "snapshot stale" hint when it
            // disagrees with the live row count.
            $dbRows = $liveRows > 0 ? $liveRows : $snapshotDbRows;
            $snapshotStale = ($liveRows > 0 || $snapshotDbRows > 0) && $liveRows !== $snapshotDbRows;

            $m = $byRep[$rep] ?? ['mismatched_rows' => 0, 'duplicate_rows' => 0, 'missing_rows' => 0];
            $state = 'HEALTHY';
            if (($m['missing_rows'] ?? 0) > 0 || $dbRows !== $publicRows || $snapshotStale) {
                $state = 'WARNING';
            }
            if (($m['duplicate_rows'] ?? 0) > 0) {
                $state = 'CRITICAL';
            }

            $guidance = $state === 'HEALTHY'
                ? 'No row-level action needed now.'
                : 'Recommended action: Review repeater mapping, rebuild snapshot, then re-run section check.';
            if ($snapshotStale) {
                $guidance = 'Snapshot is out of sync with live database (live rows: '.$liveRows.', snapshot rows: '.$snapshotDbRows.'). Rebuild snapshot and re-run section check.';
            }

            $panels[] = [
                'repeater' => $rep,
                'title_en' => $lbl['en'],
                'title_mr' => $lbl['mr'],
                'db_rows' => $dbRows,
                'live_db_rows' => $liveRows,
                'snapshot_db_rows' => $snapshotDbRows,
                'snapshot_stale' => $snapshotStale,
                'api_rows' => $dbRows,
                'public_rows' => $publicRows,
                'missing_rows' => (int) ($m['missing_rows'] ?? 0),
                'mismatched_rows' => (int) ($m['mismatched_rows'] ?? 0),
                'duplicate_rows' => (int) ($m['duplicate_rows'] ?? 0),
                'status' => $state,
                'guidance' => $guidance,
            ];
        }

        return $panels;
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string, string>
     */
    private function explainComparisonFailure(string $field, string $ctype, array $row): array
    {
        $fl = self::fieldLabelPair($field);

        return match ($ctype) {
            'api_drift' => [
                'title_en' => $fl['en'].' differs between saved profile and API',
                'title_mr' => $fl['mr'] !== '' ? $fl['mr'].' — जतन आणि API यात फरक' : 'जतन आणि API यात फरक',
                'layer_en' => 'Saved profile → API response',
                'layer_mr' => 'जतन → API',
                'what_en' => 'The value stored on the profile does not match what the API returns to the app.',
                'what_mr' => 'जतन केलेले मूल्य आणि API मधील मूल्य जुळत नाही.',
                'impact_en' => 'App users can see wrong or outdated values.',
                'impact_mr' => 'अ‍ॅप वापरकर्त्यांना चुकीचे मूल्य दिसू शकते.',
            ],
            'missing_render' => [
                'title_en' => $fl['en'].' missing on the public profile page',
                'title_mr' => $fl['mr'] !== '' ? $fl['mr'].' सार्वजनिक पृष्ठावर नाही' : 'सार्वजनिक पृष्ठावर नाही',
                'layer_en' => 'Saved profile → Public profile page',
                'layer_mr' => 'जतन → सार्वजनिक पृष्ठ',
                'what_en' => 'The value is saved, but it could not be found on the public profile view we tested.',
                'what_mr' => 'मूल्य जतन आहे पण सार्वजनिक पृष्ठावर सापडले नाही.',
                'impact_en' => 'Visitors may think this detail was never added.',
                'impact_mr' => 'भेट देणाऱ्यांना वाटेल की माहिती भरलीच नाही.',
            ],
            'cross_layer_inconsistency' => [
                'title_en' => $fl['en'].' looks different on the page than in saved data',
                'title_mr' => $fl['mr'] !== '' ? $fl['mr'].' — जतन आणि पृष्ठ यात तफावत' : 'जतन आणि पृष्ठ यात तफावत',
                'layer_en' => 'Saved profile → Displayed text',
                'layer_mr' => 'जतन → दर्शन',
                'what_en' => 'Saved data and the text shown on the profile page do not line up after normalization.',
                'what_mr' => 'जतन आणि दर्शित मजकूर जुळत नाही.',
                'impact_en' => 'Trust issues: people see different answers in different places.',
                'impact_mr' => 'वेगवेगळ्या ठिकाणी वेगळे उत्तर दिसू शकतात.',
            ],
            'null_propagation' => [
                'title_en' => $fl['en'].' is saved but disappears in API and public view',
                'title_mr' => $fl['mr'] !== '' ? $fl['mr'].' जतन आहे पण API/पृष्ठावर नाही' : 'जतन आहे पण दिसत नाही',
                'layer_en' => 'Saved profile → API & public page',
                'layer_mr' => 'जतन → API व पृष्ठ',
                'what_en' => 'Data exists in the database layer but is not reaching the API or the public page.',
                'what_mr' => 'डेटाबेसमध्ये आहे पण API किंवा पृष्ठापर्यंत पोहोचत नाही.',
                'impact_en' => 'Serious visibility gap for this field.',
                'impact_mr' => 'हे फील्ड सदस्यांना दिसणार नाही.',
            ],
            default => [
                'title_en' => 'Issue with '.$fl['en'],
                'title_mr' => $fl['mr'] !== '' ? $fl['mr'].' समस्य' : 'समस्या',
                'layer_en' => 'Saved profile, app API, and public page',
                'layer_mr' => 'जतन, API आणि सार्वजनिक पृष्ठ',
                'what_en' => 'A consistency check failed for this item.',
                'what_mr' => 'तपासणी अयशस्वी.',
                'impact_en' => 'Review recommended.',
                'impact_mr' => 'पुनर्क्षेत्र तपासणी करा.',
            ],
        };
    }

    private function mapSeverity(string $sev, string $ctype): string
    {
        if ($sev === 'critical') {
            return 'critical';
        }
        if (in_array($ctype, ['missing_render', 'null_propagation', 'api_drift'], true)) {
            return $ctype === 'null_propagation' ? 'high' : ($ctype === 'missing_render' ? 'high' : 'medium');
        }

        return $sev === 'high' ? 'high' : ($sev === 'medium' ? 'medium' : 'low');
    }

    /**
     * Live `matrimony_profiles` column name → canonical lineage field key.
     *
     * The python data engine stores some scalar fields under shorter snapshot
     * keys (e.g. captures `highest_education` as `education`, `location_id` as
     * `city`). When we union the snapshot keys with the raw column names from
     * the live DB row, those pairs would otherwise show up as two duplicate
     * lineage cards for the same biodata field. This map collapses each known
     * column into its single canonical key so admins see exactly one card per
     * field. New fields without a snapshot alias just stay as-is (column ===
     * canonical key).
     *
     * @return array<string, string>
     */
    private function columnAliasMap(): array
    {
        return [
            'highest_education' => 'education',
            // Occupation is what the member does for a living. The DB stores
            // this concept across THREE columns (and the snapshot also uses
            // two different keys for them):
            //   - occupation_title       free-text note (legacy)
            //   - profession_id          FK → professions.name (current UI)
            //   - occupation_master_id   FK → master_occupations.name
            // Admins read the public profile line "Occupation: Admin
            // Professional" and expect to find one lineage card called
            // "Occupation" — they should not have to know that profession_id
            // and occupation_title are different columns. Collapse them all
            // into the single canonical `occupation` card.
            'occupation_title' => 'occupation',
            'profession_id' => 'occupation',
            'occupation_master_id' => 'occupation',
            'professions' => 'occupation', // snapshot.db key → canonical
            'location_id' => 'city',
            'state_id' => 'state',
            'residence_state_id' => 'state',
            'religion_id' => 'religion',
            'caste_id' => 'caste',
            'sub_caste_id' => 'sub_caste',
            'mother_tongue_id' => 'mother_tongue',
            'marital_status_id' => 'marital_status',
            'family_type_id' => 'family_type',
            'complexion_id' => 'complexion',
            'physical_build_id' => 'physical_build',
            'blood_group_id' => 'blood_group',
            'gender_id' => 'gender',
            // Personal income: legacy `annual_income` column is often empty while
            // the wizard income engine writes `income_amount` +
            // `income_normalized_annual_amount`. Members see "₹ 3,00,000" from
            // those columns — admins must not read `annual_income` alone as empty.
            'income_normalized_annual_amount' => 'annual_income',
            'income_amount' => 'annual_income',
            // Family income: same split across family_* columns.
            'family_income_normalized_annual_amount' => 'family_annual_income',
            'family_income_amount' => 'family_annual_income',
            'family_income' => 'family_annual_income',
            // Birth place: members pick a hierarchy row (`birth_city_id` →
            // addresses); free-text fallback lives in `birth_place_text`. The
            // wizard shows "Kadegaon, Sangli 415304" from the resolved address
            // even when `birth_place_text` stayed empty — merge into one card.
            'birth_city_id' => 'birth_place_text',
            // Father / mother occupation: same logic as own occupation. The
            // wizard "Father Occupation" / "Mother Occupation" select stores
            // the chosen master row in `*_occupation_master_id`; the free
            // text mirror lives in `*_occupation` (text). Custom fallback
            // is `*_occupation_custom_id` (rarely used). All three should
            // show as ONE canonical card so an admin who saw "Poultry
            // Farmer" on the wizard does not see a separate "Father
            // occupation custom id — EMPTY" lineage row.
            'father_occupation_master_id' => 'father_occupation',
            'father_occupation_custom_id' => 'father_occupation',
            'father_occupation_custom' => 'father_occupation',
            'mother_occupation_master_id' => 'mother_occupation',
            'mother_occupation_custom_id' => 'mother_occupation',
            'mother_occupation_custom' => 'mother_occupation',
            'income_range_id' => 'income_range',
            'income_currency_id' => 'income_currency',
            'serious_intent_id' => 'serious_intent',
            'nakshatra_id' => 'nakshatra',
            'rashi_id' => 'rashi',
            'mangal_dosh_type_id' => 'mangal_dosh',
            'mangal_status_id' => 'mangal_dosh',
        ];
    }

    /**
     * Fill lineage DB values that are stored in alternate columns or only in
     * repeater tables — without inventing data.
     *
     * @param  array<string,mixed>  $liveCore
     * @param  array<string,list<array<string,mixed>>>  $liveRepeaters
     */
    private function enrichLineageDbValue(string $field, mixed $db, array $liveCore, array $liveRepeaters): mixed
    {
        if ($field === 'brothers_count' || $field === 'sisters_count') {
            if ($this->isFilled($db)) {
                return $db;
            }
            $want = $field === 'brothers_count' ? 'brother' : 'sister';
            $n = $this->countSiblingRepeaterRows($liveRepeaters, $want);

            return $n > 0 ? $n : $db;
        }

        return $db;
    }

    /**
     * Count active sibling rows by relation type (soft-delete aware).
     *
     * @param  array<string,list<array<string,mixed>>>  $liveRepeaters
     */
    private function countSiblingRepeaterRows(array $liveRepeaters, string $relationType): int
    {
        $rows = $liveRepeaters['siblings'] ?? [];
        if (! is_array($rows)) {
            return 0;
        }
        $n = 0;
        foreach ($rows as $r) {
            if (! is_array($r)) {
                continue;
            }
            if (! empty($r['deleted_at'])) {
                continue;
            }
            if (($r['relation_type'] ?? null) !== $relationType) {
                continue;
            }
            $n++;
        }

        return $n;
    }

    /**
     * Master-table label resolvers for ID-typed scalar fields. The lineage tab
     * uses these to display "37648 (= Kadegaon, Sangli 415304)" alongside the
     * raw foreign key, so admins can verify equivalence at a glance instead of
     * mistakenly reading an FK as a mismatch.
     *
     * Keys are *canonical field names* used by the comparison engine
     * (`comparisons[].field`), so both `religion_id` and `religion` map here.
     *
     * @return array<string, array{table: string, columns: list<string>}|callable>
     */
    private function labelResolverMap(): array
    {
        return [
            // Geo: walk the addresses hierarchy via LocationFormatterService.
            'city' => fn (int $id): ?string => $this->resolveCityLabel($id),
            'location' => fn (int $id): ?string => $this->resolveCityLabel($id),
            'location_id' => fn (int $id): ?string => $this->resolveCityLabel($id),
            'birth_city' => fn (int $id): ?string => $this->resolveCityLabel($id),
            'birth_city_id' => fn (int $id): ?string => $this->resolveCityLabel($id),
            // Same FK as birth_city_id — lineage canonical key is often `birth_place_text`.
            'birth_place_text' => fn (int $id): ?string => $this->resolveCityLabel($id),
            'native_city_id' => fn (int $id): ?string => $this->resolveCityLabel($id),
            'work_city_id' => fn (int $id): ?string => $this->resolveCityLabel($id),

            // Religion / caste / sub-caste.
            'religion' => ['table' => 'master_religions', 'columns' => ['label_en', 'label', 'name']],
            'religion_id' => ['table' => 'master_religions', 'columns' => ['label_en', 'label', 'name']],
            'caste' => ['table' => 'master_castes', 'columns' => ['label_en', 'label', 'name']],
            'caste_id' => ['table' => 'master_castes', 'columns' => ['label_en', 'label', 'name']],
            'sub_caste' => ['table' => 'master_sub_castes', 'columns' => ['label_en', 'label', 'name']],
            'sub_caste_id' => ['table' => 'master_sub_castes', 'columns' => ['label_en', 'label', 'name']],

            // master_*.label tables.
            'gender' => ['table' => 'master_genders', 'columns' => ['label']],
            'gender_id' => ['table' => 'master_genders', 'columns' => ['label']],
            'marital_status' => ['table' => 'master_marital_statuses', 'columns' => ['label']],
            'marital_status_id' => ['table' => 'master_marital_statuses', 'columns' => ['label']],
            'mother_tongue' => ['table' => 'master_mother_tongues', 'columns' => ['label']],
            'mother_tongue_id' => ['table' => 'master_mother_tongues', 'columns' => ['label']],
            'complexion' => ['table' => 'master_complexions', 'columns' => ['label']],
            'complexion_id' => ['table' => 'master_complexions', 'columns' => ['label']],
            'physical_build' => ['table' => 'master_physical_builds', 'columns' => ['label']],
            'physical_build_id' => ['table' => 'master_physical_builds', 'columns' => ['label']],
            'blood_group' => ['table' => 'master_blood_groups', 'columns' => ['label']],
            'blood_group_id' => ['table' => 'master_blood_groups', 'columns' => ['label']],
            'family_type' => ['table' => 'master_family_types', 'columns' => ['label']],
            'family_type_id' => ['table' => 'master_family_types', 'columns' => ['label']],
            'nakshatra' => ['table' => 'master_nakshatras', 'columns' => ['label']],
            'nakshatra_id' => ['table' => 'master_nakshatras', 'columns' => ['label']],
            'rashi' => ['table' => 'master_rashis', 'columns' => ['label']],
            'rashi_id' => ['table' => 'master_rashis', 'columns' => ['label']],
            'mangal_dosh' => ['table' => 'master_mangal_dosh_types', 'columns' => ['label']],
            'mangal_dosh_id' => ['table' => 'master_mangal_dosh_types', 'columns' => ['label']],
            'income_currency' => ['table' => 'master_income_currencies', 'columns' => ['label']],
            'income_currency_id' => ['table' => 'master_income_currencies', 'columns' => ['label']],
            'serious_intent' => ['table' => 'serious_intents', 'columns' => ['label']],
            'serious_intent_id' => ['table' => 'serious_intents', 'columns' => ['label']],
            // Occupation is unified across 3 source columns. When the value
            // is a numeric ID it is most often a `professions.id` (used by
            // the wizard "Working as" select); fall back to legacy
            // `master_occupations.name` / `name_mr` for the unified occupation engine.
            // We try legacy `professions` first, then `master_occupations`, so the lineage
            // card shows e.g. "17 (= Admin Professional)" regardless of
            // which FK column the value actually came from.
            'occupation' => fn (int $id): ?string => $this->resolveOccupationLabel($id),
            'profession_id' => fn (int $id): ?string => $this->resolveOccupationLabel($id),
            'occupation_master_id' => ['table' => 'master_occupations', 'columns' => ['name', 'name_mr']],
            // Father / mother occupation share the same lookup tables as the
            // member's own occupation — wizard select uses `professions.name`
            // first, with `master_occupations` as fallback for
            // older intakes. Resolving the FK gives admins the friendly
            // "Poultry Farmer" / "Homemaker" label alongside the raw id.
            'father_occupation' => fn (int $id): ?string => $this->resolveOccupationLabel($id),
            'father_occupation_master_id' => fn (int $id): ?string => $this->resolveOccupationLabel($id),
            'mother_occupation' => fn (int $id): ?string => $this->resolveOccupationLabel($id),
            'mother_occupation_master_id' => fn (int $id): ?string => $this->resolveOccupationLabel($id),
            'employment_status_id' => ['table' => 'master_employment_statuses', 'columns' => ['label']],
        ];
    }

    private function resolveCityLabel(int $id): ?string
    {
        if ($id < 1 || ! Schema::hasTable('addresses')) {
            return null;
        }
        try {
            $formatter = app(LocationFormatterService::class);
            $label = $formatter->formatLocation($id);

            return $label !== '' ? $label : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Resolve an occupation/profession FK to a human label by walking the
     * known tables in priority order. This is needed because the lineage
     * "Occupation" card collapses both `profession_id` (FK to `professions`)
     * and `occupation_master_id` (FK to `master_occupations`) into one
     * canonical entry. The first table that has a row for the given id wins.
     */
    private function resolveOccupationLabel(int $id): ?string
    {
        if ($id < 1) {
            return null;
        }
        $candidates = [
            ['table' => 'professions', 'columns' => ['name', 'label_en', 'label']],
            ['table' => 'master_occupations', 'columns' => ['name', 'name_mr']],
        ];
        foreach ($candidates as $cand) {
            $table = $cand['table'];
            if (! Schema::hasTable($table)) {
                continue;
            }
            try {
                $row = DB::table($table)->where('id', $id)->first();
            } catch (Throwable) {
                continue;
            }
            if ($row === null) {
                continue;
            }
            foreach ($cand['columns'] as $col) {
                $val = $row->{$col} ?? null;
                if (is_string($val) && trim($val) !== '') {
                    return trim($val);
                }
            }
        }

        return null;
    }

    /**
     * Resolve a foreign-key style numeric value to a human label using a
     * known master/lookup table. Returns null when there's no mapping or the
     * value is not a positive integer.
     */
    private function resolveLabel(string $field, mixed $value): ?string
    {
        if (! is_scalar($value) || (string) $value === '') {
            return null;
        }
        $raw = (string) $value;
        if (! ctype_digit($raw)) {
            return null;
        }
        $id = (int) $raw;
        if ($id < 1) {
            return null;
        }
        $cacheKey = $field.'#'.$id;
        if (array_key_exists($cacheKey, $this->labelResolutionCache)) {
            return $this->labelResolutionCache[$cacheKey];
        }

        if (preg_match('/^derived\.(residence|birth|native|work)\.(country|state|district|taluka|location)_id$/', $field) === 1) {
            $label = $this->resolveCityLabel($id);
            $this->labelResolutionCache[$cacheKey] = $label;

            return $label;
        }

        $map = $this->labelResolverMap();
        $resolver = $map[$field] ?? null;
        if ($resolver === null) {
            // Auto-fallback: `xxx_id` → try unstripped key too, and try a
            // master_<plural>(_table) heuristic before giving up.
            $stripped = preg_replace('/_id$/', '', $field) ?? $field;
            $resolver = $map[$stripped] ?? null;
        }
        $label = null;
        if (is_callable($resolver)) {
            try {
                $label = $resolver($id);
            } catch (Throwable) {
                $label = null;
            }
        } elseif (is_array($resolver)) {
            $table = (string) ($resolver['table'] ?? '');
            $cols = is_array($resolver['columns'] ?? null) ? $resolver['columns'] : ['label'];
            if ($table !== '' && Schema::hasTable($table)) {
                try {
                    $row = DB::table($table)->where('id', $id)->first();
                    if ($row !== null) {
                        foreach ($cols as $c) {
                            $candidate = $row->{$c} ?? null;
                            if (is_string($candidate) && trim($candidate) !== '') {
                                $label = $candidate;
                                break;
                            }
                        }
                    }
                } catch (Throwable) {
                    $label = null;
                }
            }
        }
        $this->labelResolutionCache[$cacheKey] = $label;

        return $label;
    }

    /**
     * Build a normalized comparable token for two values to detect semantic
     * equivalence. Returns lowercase ASCII slug with all punctuation/spaces
     * collapsed; date-like strings are flattened to ISO Y-m-d; FK IDs are
     * resolved to their master-table label first.
     */
    private function canonicalizeForCompare(string $field, mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            return '';
        }

        // FK ID → label (so "37648" canonicalizes to the same token as
        // "Kadegaon Sangli 415304").
        if (ctype_digit($raw)) {
            $resolved = $this->resolveLabel($field, $raw);
            if (is_string($resolved) && $resolved !== '') {
                $raw = $resolved;
            }
        }

        // Date-like → ISO Y-m-d (handles "1982-02-09", "9 Feb 1982",
        // "09/02/1982", "1982-02-09T00:00:00Z" all the same).
        if ($this->looksLikeDate($raw)) {
            try {
                $iso = Carbon::parse($raw)->toDateString();
                if ($iso !== '') {
                    return 'date:'.$iso;
                }
            } catch (Throwable) {
                // fall through to text normalization
            }
        }

        // Boolean-ish.
        $lc = mb_strtolower($raw);
        if (in_array($lc, ['true', 'false', '1', '0', 'yes', 'no', 'y', 'n'], true)) {
            return 'bool:'.($lc === 'true' || $lc === '1' || $lc === 'yes' || $lc === 'y' ? '1' : '0');
        }

        // Numeric (currency/measure) — compare as float.
        if (is_numeric($raw)) {
            return 'num:'.((string) (float) $raw);
        }

        // Generic text — lowercase, strip non-alphanumeric, collapse spaces.
        $token = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $lc) ?? $lc;
        $token = preg_replace('/\s+/u', ' ', $token) ?? $token;

        return 'txt:'.trim($token);
    }

    private function looksLikeDate(string $s): bool
    {
        if ($s === '' || strlen($s) > 40) {
            return false;
        }

        // Common patterns: ISO, "9 Feb 1982", "Feb 9, 1982", "09/02/1982", "9-Feb-1982".
        return (bool) preg_match(
            '/^(\d{4}-\d{1,2}-\d{1,2}(T\d{2}:\d{2}.*)?|\d{1,2}\s+[A-Za-z]{3,9}\s+\d{4}|[A-Za-z]{3,9}\s+\d{1,2},?\s+\d{4}|\d{1,2}[\/\-\.][\d\w]{1,9}[\/\-\.]\d{2,4})$/',
            $s
        );
    }

    /**
     * True when both values, after canonicalization for the given field,
     * mean the same thing (date format, FK id↔label, casing, punctuation).
     */
    private function semanticallyEquivalent(string $field, mixed $a, mixed $b): bool
    {
        $ca = $this->canonicalizeForCompare($field, $a);
        $cb = $this->canonicalizeForCompare($field, $b);
        if ($ca === '' || $cb === '') {
            return false;
        }

        return $ca === $cb;
    }
}
