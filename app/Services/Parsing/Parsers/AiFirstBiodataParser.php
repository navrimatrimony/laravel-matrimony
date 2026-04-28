<?php

namespace App\Services\Parsing\Parsers;

use App\Services\AiVisionExtractionService;
use App\Services\BiodataParserService;
use App\Services\ExternalAiParsingService;
use App\Services\Ocr\OcrHtmlTableFlattener;
use App\Services\Ocr\OcrNormalize;
use App\Services\Parsing\Contracts\BiodataParserInterface;
use App\Services\Parsing\IntakeControlledFieldNormalizer;
use App\Services\Parsing\IntakeParsedSnapshotSkeleton;
use App\Services\Parsing\ParsedJsonSsotNormalizer;
use App\Support\IntakeDobTrace;
use Illuminate\Support\Facades\Log;

/**
 * AI-first parser.
 *
 * Tries ExternalAiParsingService->parseToSsot() first.
 * If that fails or returns invalid shape, falls back to rules-only parser.
 */
class AiFirstBiodataParser implements BiodataParserInterface
{
    public function __construct(
        protected ExternalAiParsingService $ai,
        protected RulesOnlyBiodataParser $rulesParser,
        protected IntakeControlledFieldNormalizer $intakeControlledFieldNormalizer,
        protected IntakeParsedSnapshotSkeleton $skeleton,
    ) {}

    public function parse(string $rawText, array $context = []): array
    {
        $rawText = AiVisionExtractionService::sanitizeTransientParseInputText($rawText);
        if (stripos($rawText, '<table') !== false) {
            $rawText = OcrHtmlTableFlattener::flatten($rawText);
        }

        if (IntakeDobTrace::enabled((int) ($context['intake_id'] ?? 0))) {
            Log::info('DOB_TRACE_RAW', [
                'intake_id' => $context['intake_id'] ?? null,
                'parser_mode' => $context['parser_mode'] ?? null,
                'raw_dob_segment' => $this->extractJanmaTaarikhValueSegment($rawText),
                'raw_text_has_janma_taarikh' => preg_match('/जन्म\s*तारीख/u', $rawText) === 1,
            ]);
        }

        $parserMode = $context['parser_mode'] ?? 'ai_first_v1';
        // ai_vision_extract_v1 uses a vision/transcription step to get higher-quality text;
        // it should then use the same stricter schema prompt as ai_first_v2.
        $useV2 = in_array($parserMode, ['ai_first_v2', 'ai_vision_extract_v1'], true);

        // Attempt AI parse first (v1 or v2 based on admin setting).
        try {
            $aiResult = $useV2
                ? $this->ai->parseToSsotV2($rawText)
                : $this->ai->parseToSsot($rawText);
            // V1/V2 APIs may omit confidence_map; without this the entire rules-merge path was skipped
            // and preview fell through to rules-only only when an exception occurred — structured v2 often
            // returns core without confidence_map, so merge never ran.
            if (is_array($aiResult) && isset($aiResult['core'])) {
                if (! isset($aiResult['confidence_map']) || ! is_array($aiResult['confidence_map'])) {
                    $aiResult['confidence_map'] = [];
                }
                // Ensure minimal SSOT shape first.
                $aiResult = $this->ensureSsotShape($aiResult);

                // Phase-5 repair: even in ai_first_v1 mode we want
                // deterministic, high-quality rules fallback for critical
                // family core + primary contacts. Merge rules-only output
                // for those fields when AI either omits them or leaves null.
                $rules = null;
                try {
                    $rules = $this->rulesParser->parse($rawText, $context);
                } catch (\Throwable $e) {
                    // Rules parser is a deterministic enhancement, not a hard dependency for AI-first modes.
                    // If it fails (e.g. missing master-data tables in some environments), keep the AI output.
                    Log::warning('Rules-only fallback failed during AI-first parse; continuing with AI result', [
                        'error' => $e->getMessage(),
                        'intake_id' => $context['intake_id'] ?? null,
                        'parser_mode' => $parserMode,
                    ]);
                    $rules = null;
                }

                if (IntakeDobTrace::enabled((int) ($context['intake_id'] ?? 0))) {
                    Log::info('DOB_TRACE_RULES', [
                        'intake_id' => $context['intake_id'] ?? null,
                        'rules_dob' => is_array($rules) ? (($rules['core'] ?? [])['date_of_birth'] ?? null) : null,
                    ]);
                    Log::info('DOB_TRACE_AI', [
                        'intake_id' => $context['intake_id'] ?? null,
                        'ai_dob' => ($aiResult['core'] ?? [])['date_of_birth'] ?? null,
                    ]);
                }

                $aiCore = $aiResult['core'] ?? [];
                $rulesCore = is_array($rules) ? ($rules['core'] ?? []) : [];

                $fieldsToMerge = [
                    'birth_time',
                    'date_of_birth',
                    'birth_place',
                    'birth_place_text',
                    'address_line',
                    'highest_education',
                    'occupation_title',
                    'rashi',
                    'father_name',
                    'father_occupation',
                    'mother_name',
                    'mother_occupation',
                    'brother_count',
                    'sister_count',
                    'gender',
                    'marital_status',
                    'full_name',
                    'primary_contact_number',
                    'father_contact_1',
                    'height',
                    'height_cm',
                    'complexion',
                    'religion',
                    'caste',
                    'sub_caste',
                    'blood_group',
                    'other_relatives_text',
                ];

                foreach ($fieldsToMerge as $field) {
                    $aiVal = $aiCore[$field] ?? null;
                    $aiHas = array_key_exists($field, $aiCore) && $aiVal !== null && $aiVal !== '';
                    $rulesHas = array_key_exists($field, $rulesCore) && $rulesCore[$field] !== null && $rulesCore[$field] !== '';
                    $useRules = false;
                    if (! $aiHas && $rulesHas) {
                        $useRules = true;
                    }
                    // AI often outputs brother_count/sister_count = 0 while rules structured rows list 2+ brothers.
                    if (in_array($field, ['brother_count', 'sister_count'], true) && $rulesHas) {
                        $rv = (int) ($rulesCore[$field] ?? -1);
                        $av = array_key_exists($field, $aiCore) ? (int) $aiCore[$field] : -1;
                        if ($rv >= 0 && ($av < 0 || $rv > $av)) {
                            $useRules = true;
                        }
                    }
                    if ($field === 'date_of_birth' && $rulesHas && $this->isValidIsoDate((string) $rulesCore['date_of_birth'])) {
                        if (! $aiHas || ! $this->isValidIsoDate(is_string($aiVal) ? $aiVal : '')) {
                            $useRules = true;
                        }
                    }
                    if ($field === 'birth_time' && $rulesHas) {
                        $a = is_scalar($aiVal) ? trim((string) $aiVal) : '';
                        if ($a !== '' && ! $this->birthTimeLooksPlausible($a)) {
                            $useRules = true;
                        }
                    }
                    if ($field === 'rashi' && $rulesHas && $this->isJunkRashiOrHoroscope(($aiVal !== null && $aiVal !== '') ? (string) $aiVal : null)) {
                        $useRules = true;
                    }
                    if ($field === 'highest_education' && $aiHas && is_string($aiVal) && $this->educationFieldLooksPolluted($aiVal) && $rulesHas) {
                        $useRules = true;
                    }
                    if ($field === 'address_line' && $aiHas && is_string($aiVal) && $this->addressLineLooksLikeKulSwamiBleed($aiVal) && $rulesHas) {
                        $useRules = true;
                    }
                    if ($field === 'birth_place' && $rulesHas) {
                        if (! $aiHas) {
                            $useRules = true;
                        } elseif (is_string($aiVal) && $this->birthPlaceLooksPolluted($aiVal)) {
                            $useRules = true;
                        }
                    }
                    if ($field === 'birth_place_text' && $rulesHas) {
                        if (! $aiHas) {
                            $useRules = true;
                        } elseif (is_string($aiVal) && $this->birthPlaceLooksPolluted($aiVal)) {
                            $useRules = true;
                        }
                    }
                    if ($field === 'occupation_title' && $rulesHas) {
                        if (! $aiHas) {
                            $useRules = true;
                        } elseif (is_string($aiVal) && trim($aiVal) !== '' && $this->educationFieldLooksPolluted($aiVal)) {
                            $useRules = true;
                        }
                    }
                    if ($field === 'father_name' && $aiHas && is_string($aiVal)) {
                        if (mb_strpos($aiVal, 'आईचे') !== false || mb_strpos($aiVal, 'आईचे नांव') !== false || mb_strlen(trim($aiVal)) < 10) {
                            $useRules = true;
                        }
                        if ($this->isTruncatedFatherName($aiVal)) {
                            $useRules = $rulesHas;
                        }
                    }
                    if ($field === 'full_name' && $aiHas && is_string($aiVal) && $this->isInvalidAiFullName($aiVal) && $rulesHas) {
                        $useRules = true;
                    }
                    if ($field === 'blood_group' && $aiHas && is_string($aiVal) && $rulesHas) {
                        $rb = BiodataParserService::sanitizeBloodGroupValue($aiVal);
                        if ($rb === null || $rb === '') {
                            $useRules = true;
                        }
                    }
                    if ($field === 'height_cm' && $aiHas && is_numeric($aiVal) && (float) $aiVal > 220) {
                        $useRules = $rulesHas;
                    }
                    if ($useRules && $rulesHas) {
                        $aiCore[$field] = $rulesCore[$field];
                    }
                }

                $aiResult['core'] = $aiCore;

                $hardDob = $this->extractHardIsoDateOfBirthFromText($rawText);
                if ($hardDob !== null && $this->isValidIsoDate($hardDob)) {
                    $curDob = $aiCore['date_of_birth'] ?? null;
                    $curStr = is_string($curDob) ? $curDob : '';
                    if (! $this->isValidIsoDate($curStr)) {
                        $aiCore['date_of_birth'] = $hardDob;
                        $aiResult['core'] = $aiCore;
                    }
                }

                if (IntakeDobTrace::enabled((int) ($context['intake_id'] ?? 0))) {
                    Log::info('DOB_TRACE_MERGED_PRE_INTAKE_NORM', [
                        'intake_id' => $context['intake_id'] ?? null,
                        'merged_dob_after_rules_merge_loop' => $aiCore['date_of_birth'] ?? null,
                    ]);
                }

                $aiPrimary = $aiCore['primary_contact_number'] ?? null;
                $rulesPrimary = $rulesCore['primary_contact_number'] ?? null;
                $rulesFatherPh = $rulesCore['father_contact_1'] ?? null;
                if (($rulesPrimary === null || $rulesPrimary === '') && $aiPrimary !== null && $rulesFatherPh !== null
                    && (string) $aiPrimary === (string) $rulesFatherPh) {
                    $aiResult['core']['primary_contact_number'] = null;
                }
                if ($aiPrimary !== null && $aiPrimary !== '' && BiodataParserService::isPhoneExcludedSuchakHeaderStatic($rawText, (string) $aiPrimary)) {
                    $aiResult['core']['primary_contact_number'] = null;
                }

                $aiResult = $this->mergeHoroscopeFromRulesWhenAiJunk($aiResult, $rules);
                $aiResult = $this->mergeHoroscopeRashiWeekdayFromRules($aiResult, $rules);
                $aiResult = $this->mergeEducationHistoryFromRulesWhenPolluted($aiResult, $rules);
                $aiResult = $this->mergeAddressesFromRulesWhenWrong($aiResult, $rules);

                // Contacts: if AI left contacts empty, fall back to rules-only
                // contacts (which already place the primary number first and
                // mark it as type=primary).
                $aiContacts = $aiResult['contacts'] ?? [];
                $rulesContacts = is_array($rules) ? ($rules['contacts'] ?? []) : [];
                if ((! is_array($aiContacts) || count($aiContacts) === 0) && is_array($rulesContacts) && count($rulesContacts) > 0) {
                    $aiResult['contacts'] = $rulesContacts;
                }

                // Section-level fallback: use rules when AI section is empty or low-quality.
                $aiSiblings = $aiResult['siblings'] ?? null;
                $aiRelatives = $aiResult['relatives'] ?? null;
                $aiCareer = $aiResult['career_history'] ?? null;
                $aiHoroscope = $aiResult['horoscope'] ?? null;
                $rulesSiblings = is_array($rules) ? ($rules['siblings'] ?? []) : [];
                $rulesRelatives = is_array($rules) ? ($rules['relatives'] ?? []) : [];
                $rulesCareer = is_array($rules) ? ($rules['career_history'] ?? []) : [];
                $rulesHoroscope = is_array($rules) ? ($rules['horoscope'] ?? []) : [];

                $aiSibCount = is_array($aiSiblings) ? count($aiSiblings) : 0;
                $rulesSibCount = count($rulesSiblings);
                if (! empty($rulesSiblings)) {
                    if (! is_array($aiSiblings) || $aiSibCount === 0
                        || $this->hasInvalidAiSiblingRows(is_array($aiSiblings) ? $aiSiblings : [])
                        || ($rulesSibCount > $aiSibCount)) {
                        $aiResult['siblings'] = $rulesSiblings;
                    }
                }
                // Relatives: prefer rules parser only when AI produced no rows or low-quality relative rows.
                if (! empty($rulesRelatives)) {
                    if (! is_array($aiRelatives) || count($aiRelatives) === 0 || ! $this->isUsableRelatives($aiRelatives)
                        || $this->hasHeadingOnlyRelativeRows($aiRelatives)
                        || $this->hasAjolMamaJunkRelativeRows($aiRelatives)) {
                        $aiResult['relatives'] = $rulesRelatives;
                    }
                }
                if (! $this->isUsableCareerHistory($aiCareer) && ! empty($rulesCareer)) {
                    $aiResult['career_history'] = $rulesCareer;
                }
                if (! $this->isUsableHoroscope($aiHoroscope) && ! empty($rulesHoroscope)) {
                    $aiResult['horoscope'] = $rulesHoroscope;
                }

                $aiResult = $this->repairSiblingsAndRelativesFromRules($aiResult, $rules);
                $this->stripMislabeledPrimaryContactRows($aiResult);

                // Confidence map: merge AI + rules so AI-only fields keep scores and both contribute where present.
                if (is_array($rules) && isset($rules['confidence_map']) && is_array($rules['confidence_map'])) {
                    $aiCm = is_array($aiResult['confidence_map'] ?? null) ? $aiResult['confidence_map'] : [];
                    $aiResult['confidence_map'] = ParsedJsonSsotNormalizer::mergeConfidenceMaps($aiCm, $rules['confidence_map']);
                }

                // Final SSOT shape then deterministic intake controlled-field normalization.
                $result = $this->ensureSsotShape($aiResult);
                $suggestedByUserId = null;
                if (isset($context['suggested_by_user_id']) && is_numeric($context['suggested_by_user_id'])) {
                    $sid = (int) $context['suggested_by_user_id'];
                    $suggestedByUserId = $sid > 0 ? $sid : null;
                }
                $result = $this->intakeControlledFieldNormalizer->normalizeSnapshot($result, $suggestedByUserId);

                // Clear education institution / career location when AI mis-parsed horoscope terms (devak/gotra).
                $result['education_history'] = BiodataParserService::sanitizeEducationInstitutionFromDevakStatic($result['education_history'] ?? []);
                $result['career_history'] = BiodataParserService::sanitizeCareerLocationFromGotraStatic($result['career_history'] ?? []);

                // Final horoscope sanitization: ensure devak/kuldaivat/gotra never contain junk in ai_first output.
                $result['horoscope'] = $this->sanitizeHoroscopeRows($result['horoscope'] ?? []);

                if (IntakeDobTrace::enabled((int) ($context['intake_id'] ?? 0))) {
                    Log::info('DOB_TRACE_PRE_NORMALIZE', [
                        'intake_id' => $context['intake_id'] ?? null,
                        'merged_dob_before_normalize' => ($result['core'] ?? [])['date_of_birth'] ?? null,
                    ]);
                }

                $result = ParsedJsonSsotNormalizer::normalize($result);

                if (IntakeDobTrace::enabled((int) ($context['intake_id'] ?? 0))) {
                    Log::info('DOB_TRACE_POST_NORMALIZE', [
                        'intake_id' => $context['intake_id'] ?? null,
                        'merged_dob_after_ssot_normalize' => ($result['core'] ?? [])['date_of_birth'] ?? null,
                    ]);
                }

                $result = $this->reapplyRulesCoreOverridesAfterSsotNormalize($result, $rules);

                if (IntakeDobTrace::enabled((int) ($context['intake_id'] ?? 0))) {
                    Log::info('DOB_TRACE_POST_REAPPLY_RULES', [
                        'intake_id' => $context['intake_id'] ?? null,
                        'merged_dob_after_reapply_rules' => ($result['core'] ?? [])['date_of_birth'] ?? null,
                    ]);
                }

                $result = $this->promoteAjolUncleRowsToMamaBucketWhenEmpty($result);
                // CRITICAL: enforce full canonical skeleton AFTER all normalization.
                $result = $this->ensureSsotShape($result);
                $result = $this->applyDateOfBirthMergeFromRulesOrRawRecovery($result, $rules, $rawText, $context);

                if (IntakeDobTrace::enabled((int) ($context['intake_id'] ?? 0))) {
                    Log::info('DOB_TRACE_PARSER_RETURN', [
                        'intake_id' => $context['intake_id'] ?? null,
                        'final_core_date_of_birth' => ($result['core'] ?? [])['date_of_birth'] ?? null,
                    ]);
                }

                $this->logAiFirstMergeDebug($context, $result, $rules);

                return $result;
            }
        } catch (\Throwable $e) {
            Log::warning('AI-first biodata parse failed; falling back to rules-only', [
                'error' => $e->getMessage(),
                'intake_id' => $context['intake_id'] ?? null,
            ]);
        }

        // Fallback: rules-only parser.
        return $this->rulesParser->parse($rawText, $context);
    }

    /**
     * Non-empty career field value (rejects literal "null" strings and whitespace).
     */
    private function careerTokenNonempty(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if (! is_string($value)) {
            return true;
        }
        if (ParsedJsonSsotNormalizer::isNullLikeString($value)) {
            return false;
        }

        return trim($value) !== '';
    }

    /**
     * True if career_history has at least one row with a meaningful job_title, company, or location.
     */
    private function isUsableCareerHistory(mixed $career): bool
    {
        if (! is_array($career) || count($career) === 0) {
            return false;
        }
        foreach ($career as $row) {
            if (! is_array($row)) {
                continue;
            }
            $job = $row['job_title'] ?? $row['role'] ?? null;
            $company = $row['company'] ?? $row['employer'] ?? null;
            $loc = $row['location'] ?? null;
            if ($this->careerTokenNonempty($job) || $this->careerTokenNonempty($company) || $this->careerTokenNonempty($loc)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True if horoscope has at least one row with meaningful astrological text (blood_group is core-only, not scanned here).
     */
    private function isUsableHoroscope(mixed $horoscope): bool
    {
        if (! is_array($horoscope) || count($horoscope) === 0) {
            return false;
        }
        foreach ($horoscope as $row) {
            if (! is_array($row)) {
                continue;
            }
            foreach (['rashi', 'nakshatra', 'nadi', 'gan', 'yoni', 'devak', 'kuldaivat', 'gotra', 'charan', 'navras_name', 'birth_weekday', 'mangal_dosh_type'] as $k) {
                $v = $row[$k] ?? null;
                if ($v === null || $v === '') {
                    continue;
                }
                if (is_string($v) && trim($v) === '') {
                    continue;
                }

                return true;
            }
        }

        return false;
    }

    /**
     * True if relatives is a non-empty array of structured rows (not just note blobs).
     */
    private function isUsableRelatives(mixed $relatives): bool
    {
        if (! is_array($relatives) || count($relatives) === 0) {
            return false;
        }
        $meaningful = 0;
        $goodAddress = 0;
        foreach ($relatives as $row) {
            if (! is_array($row)) {
                continue;
            }
            $rel = trim((string) ($row['relation_type'] ?? $row['relation'] ?? ''));
            if ($rel === '') {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $addr = trim((string) ($row['address_line'] ?? $row['location'] ?? ''));
            if ($name === '' && $addr === '') {
                continue;
            }
            $meaningful++;

            // "Good" address = full-ish Marathi address fragment (has taluka/district OR long comma-separated location).
            if (
                $addr !== '' &&
                (
                    mb_strpos($addr, 'ता.') !== false ||
                    mb_strpos($addr, 'जि.') !== false ||
                    mb_strlen($addr) >= 18
                )
            ) {
                $goodAddress++;
            }
        }

        if ($meaningful === 0) {
            return false;
        }

        // If AI gave mostly short addresses (only village), treat as low-quality so rules parser can supply full address_line.
        return ($goodAddress / $meaningful) >= 0.5;
    }

    /**
     * ParsedJsonSsotNormalizer + row sanitizers can null out or leave AI junk; re-assert rules for critical fields.
     */
    private function reapplyRulesCoreOverridesAfterSsotNormalize(array $result, ?array $rules): array
    {
        if (! is_array($rules) || empty($rules['core']) || ! is_array($rules['core'])) {
            return $result;
        }
        $rc = $rules['core'];
        $core = is_array($result['core'] ?? null) ? $result['core'] : [];

        foreach (['date_of_birth', 'birth_time', 'birth_place', 'birth_place_text', 'address_line', 'highest_education', 'occupation_title', 'rashi', 'brother_count', 'sister_count'] as $k) {
            if (! array_key_exists($k, $rc) || $rc[$k] === null || $rc[$k] === '') {
                continue;
            }
            $cur = $core[$k] ?? null;
            $apply = false;
            if ($k === 'date_of_birth' && $this->isValidIsoDate((string) $rc[$k]) && ! $this->isValidIsoDate(is_string($cur) ? $cur : '')) {
                $apply = true;
            }
            if (in_array($k, ['birth_place', 'birth_place_text'], true) && ($cur === null || $cur === '')) {
                $apply = true;
            }
            if ($k === 'birth_time') {
                $curS = is_scalar($cur) ? trim((string) $cur) : '';
                if ($curS === '' || ! $this->birthTimeLooksPlausible($curS)) {
                    $apply = true;
                }
            }
            if ($k === 'highest_education' && $this->educationFieldLooksPolluted((string) ($cur ?? ''))) {
                $apply = true;
            }
            if ($k === 'occupation_title' && ($cur === null || $cur === '' || $this->educationFieldLooksPolluted((string) $cur))) {
                $apply = true;
            }
            if ($k === 'address_line' && is_string($cur) && $this->addressLineLooksLikeKulSwamiBleed($cur)) {
                $apply = true;
            }
            if ($k === 'rashi' && $this->isJunkRashiOrHoroscope(is_string($cur) ? $cur : null)) {
                $apply = true;
            }
            if (in_array($k, ['brother_count', 'sister_count'], true)) {
                $rv = (int) $rc[$k];
                $cv = ($cur === null || $cur === '') ? -1 : (int) $cur;
                if ($rv >= 0 && ($cv < 0 || $rv > $cv)) {
                    $apply = true;
                }
            }
            if ($apply) {
                $core[$k] = $rc[$k];
            }
        }
        $result['core'] = $core;

        $result = $this->mergeHoroscopeRashiWeekdayFromRules($result, $rules);
        if (is_array($rules['education_history'] ?? null) && ! empty($rules['education_history'])) {
            $deg = (string) (($result['education_history'][0] ?? [])['degree'] ?? '');
            $hc = (string) ($core['highest_education'] ?? '');
            if ($this->educationFieldLooksPolluted($deg) || $this->educationFieldLooksPolluted($hc)) {
                $result['education_history'] = $rules['education_history'];
            }
        }
        if (is_array($rules['addresses'] ?? null) && ! empty($rules['addresses'])) {
            $al = (string) (($result['addresses'][0] ?? [])['address_line'] ?? '');
            if ($al === '' || $this->addressLineLooksLikeKulSwamiBleed($al)) {
                $result['addresses'] = $rules['addresses'];
            }
        }
        if ((! $this->isUsableCareerHistory($result['career_history'] ?? null)) && ! empty($rules['career_history'])) {
            $result['career_history'] = $rules['career_history'];
        }

        return $result;
    }

    /**
     * Last-chance DOB: rules merge + ParsedJsonSsotNormalizer can still leave null when rules OCR missed Marathi month
     * variants (e.g. ऑगस्ट nukta) or AI sent an invalid placeholder. Prefer valid ISO from rules, else parse the
     * जन्म तारीख segment from flattened table OCR text.
     *
     * @param  array<string, mixed>  $context
     */
    private function applyDateOfBirthMergeFromRulesOrRawRecovery(array $result, ?array $rules, string $rawText, array $context): array
    {
        $core = is_array($result['core'] ?? null) ? $result['core'] : [];
        $before = $core['date_of_birth'] ?? null;
        if ($this->isValidIsoDate(is_string($before) ? $before : '')) {
            $this->logDobTrace($context, $rules, $rawText, null, $before, $before, $before, 'already_iso');

            return $result;
        }

        $rulesDob = null;
        if (is_array($rules) && array_key_exists('date_of_birth', $rules['core'] ?? [])) {
            $rd = $rules['core']['date_of_birth'];
            if ($rd !== null && $rd !== '' && $this->isValidIsoDate((string) $rd)) {
                $rulesDob = (string) $rd;
            }
        }
        if ($rulesDob !== null) {
            $core['date_of_birth'] = $rulesDob;
            $result['core'] = $core;
            $this->logDobTrace($context, $rules, $rawText, null, $before, $rulesDob, $rulesDob, 'from_rules_core');

            return $result;
        }

        $flat = BiodataParserService::flattenHtmlTableForBiodata($rawText);
        $forScan = OcrNormalize::normalizeRawTextForParsing(OcrNormalize::normalizeRawText($flat));
        $rawLine = null;
        if (preg_match('/जन्म\s*तारीख\s*[:\-]+\s*([^\n]+)/u', $forScan, $dm)) {
            $rawLine = trim($dm[1]);
        }
        $iso = null;
        if ($rawLine !== null && $rawLine !== '') {
            $n = OcrNormalize::normalizeDate($rawLine);
            if ($n !== null && $n !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $n)) {
                $iso = OcrNormalize::applyBaselinePatterns('date_of_birth', (string) $n);
            }
        }
        if ($iso !== null && $this->isValidIsoDate($iso)) {
            $core['date_of_birth'] = $iso;
            $result['core'] = $core;
            $this->logDobTrace($context, $rules, $rawText, $rawLine, $before, $rulesDob, $iso, 'from_raw_line');

            return $result;
        }

        $recovered = $this->rulesParser->recoverDateOfBirthFromNormalizedBiodataText($forScan);
        if ($recovered !== null && $this->isValidIsoDate($recovered)) {
            $core['date_of_birth'] = $recovered;
            $result['core'] = $core;
            $this->logDobTrace($context, $rules, $rawText, $rawLine, $before, $rulesDob, $recovered, 'from_full_text_recovery');

            return $result;
        }

        $this->logDobTrace($context, $rules, $rawText, $rawLine, $before, $rulesDob, $before, 'still_null');

        return $result;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logDobTrace(
        array $context,
        ?array $rules,
        string $rawText,
        ?string $rawDobLine,
        mixed $mergedBeforeRecovery,
        ?string $rulesDob,
        mixed $mergedAfterRecovery,
        string $reason,
    ): void {
        if (! config('app.debug')) {
            return;
        }
        Log::debug('ai_first_dob_trace', [
            'intake_id' => $context['intake_id'] ?? null,
            'parser_mode' => $context['parser_mode'] ?? null,
            'reason' => $reason,
            'raw_dob_segment' => $rawDobLine,
            'rules_core_date_of_birth' => is_array($rules) ? ($rules['core']['date_of_birth'] ?? null) : null,
            'merged_core_date_of_birth_before_recovery' => $mergedBeforeRecovery,
            'merged_core_date_of_birth_after_recovery' => $mergedAfterRecovery,
            'raw_text_has_janma_taarikh' => preg_match('/जन्म\s*तारीख/u', $rawText) === 1,
        ]);
    }

    /**
     * When आजोळ line holds enumerated मामा uncles but relation_type is plain "आजोळ", rows land in ajol; promote to mama for preview.
     */
    private function promoteAjolUncleRowsToMamaBucketWhenEmpty(array $result): array
    {
        if (! isset($result['relatives_sectioned']['maternal']) || ! is_array($result['relatives_sectioned']['maternal'])) {
            return $result;
        }
        $maternal = &$result['relatives_sectioned']['maternal'];
        $mama = $maternal['mama'] ?? [];
        $ajol = $maternal['ajol'] ?? [];
        if (! is_array($mama) || ! is_array($ajol) || count($mama) > 0) {
            return $result;
        }
        $promote = [];
        $keep = [];
        foreach ($ajol as $row) {
            if (! is_array($row)) {
                $keep[] = $row;

                continue;
            }
            $notes = trim((string) ($row['notes'] ?? $row['raw_note'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            $rel = trim((string) ($row['relation_type'] ?? ''));
            $blob = $name.' '.$notes.' '.$rel;
            if (preg_match('/आजोळ\s*\(\s*मामा\s*\)/u', $blob)
                || (preg_match_all('/श्री\./u', $blob) >= 2 && preg_match('/जाधव|मामा/u', $blob))) {
                $row['relation_type'] = 'मामा';
                $promote[] = $row;
            } else {
                $keep[] = $row;
            }
        }
        if ($promote !== []) {
            $maternal['ajol'] = $keep;
            $maternal['mama'] = array_merge($mama, $promote);
        }

        return $result;
    }

    private function logAiFirstMergeDebug(array $context, array $result, ?array $rules): void
    {
        if (! config('app.debug')) {
            return;
        }
        $keys = ['date_of_birth', 'birth_time', 'birth_place', 'birth_place_text', 'highest_education', 'occupation_title', 'address_line', 'rashi', 'brother_count', 'sister_count'];
        $slice = [];
        foreach ($keys as $k) {
            $slice[$k] = ($result['core'] ?? [])[$k] ?? null;
        }
        $rulesSlice = null;
        if (is_array($rules) && isset($rules['core'])) {
            $rulesSlice = [];
            foreach ($keys as $k) {
                $rulesSlice[$k] = $rules['core'][$k] ?? null;
            }
        }
        Log::debug('ai_first_merge_post_normalize', [
            'intake_id' => $context['intake_id'] ?? null,
            'parser_mode' => $context['parser_mode'] ?? null,
            'core' => $slice,
            'rules_core' => $rulesSlice,
            'horoscope0' => ($result['horoscope'][0] ?? null),
            'education0_degree' => ($result['education_history'][0]['degree'] ?? null),
            'address0' => ($result['addresses'][0]['address_line'] ?? null),
            'siblings_count' => is_countable($result['siblings'] ?? []) ? count($result['siblings']) : 0,
            'maternal_mama_count' => is_countable($result['relatives_sectioned']['maternal']['mama'] ?? []) ? count($result['relatives_sectioned']['maternal']['mama']) : 0,
            'maternal_ajol_count' => is_countable($result['relatives_sectioned']['maternal']['ajol'] ?? []) ? count($result['relatives_sectioned']['maternal']['ajol']) : 0,
        ]);
    }

    private function mergeHoroscopeFromRulesWhenAiJunk(array $aiResult, ?array $rules): array
    {
        if (! is_array($rules) || empty($rules['horoscope'][0]) || ! is_array($rules['horoscope'][0])) {
            return $aiResult;
        }
        $r0 = $rules['horoscope'][0];
        $aiH = $aiResult['horoscope'] ?? null;
        if (! is_array($aiH) || ! isset($aiH[0]) || ! is_array($aiH[0])) {
            $aiResult['horoscope'] = $rules['horoscope'];

            return $aiResult;
        }
        $a0 = &$aiResult['horoscope'][0];
        $aiGan = $a0['gan'] ?? null;
        $sanGan = is_string($aiGan) ? BiodataParserService::sanitizeGanValue($aiGan) : null;
        if (($sanGan === null || $sanGan === '') && ! empty($r0['gan'])) {
            $a0['gan'] = $r0['gan'];
        }

        return $aiResult;
    }

    /**
     * Vision/AI often mis-reads रास as कुलस्वामी; rules parser is deterministic on label lines.
     */
    private function mergeHoroscopeRashiWeekdayFromRules(array $aiResult, ?array $rules): array
    {
        if (! is_array($rules) || empty($rules['horoscope'][0]) || ! is_array($rules['horoscope'][0])) {
            return $aiResult;
        }
        $r0 = $rules['horoscope'][0];
        $aiH = $aiResult['horoscope'] ?? null;
        if (! is_array($aiH) || ! isset($aiH[0]) || ! is_array($aiH[0])) {
            return $aiResult;
        }
        $a0 = &$aiResult['horoscope'][0];
        $aiRashi = $a0['rashi'] ?? null;
        if ($this->isJunkRashiOrHoroscope(is_string($aiRashi) ? $aiRashi : null) && ! empty($r0['rashi'])) {
            $a0['rashi'] = $r0['rashi'];
        }
        $aiBw = $a0['birth_weekday'] ?? null;
        if (($aiBw === null || $aiBw === '') && ! empty($r0['birth_weekday'])) {
            $a0['birth_weekday'] = $r0['birth_weekday'];
        }

        return $aiResult;
    }

    private function mergeEducationHistoryFromRulesWhenPolluted(array $aiResult, ?array $rules): array
    {
        if (! is_array($rules) || empty($rules['education_history'])) {
            return $aiResult;
        }
        $aiEd = $aiResult['education_history'] ?? [];
        $first = is_array($aiEd) && isset($aiEd[0]) && is_array($aiEd[0]) ? $aiEd[0] : null;
        $deg = is_array($first) ? (string) ($first['degree'] ?? '') : '';
        $coreEdu = (string) (($aiResult['core'] ?? [])['highest_education'] ?? '');
        if (($deg !== '' && $this->educationFieldLooksPolluted($deg))
            || ($coreEdu !== '' && $this->educationFieldLooksPolluted($coreEdu))) {
            $aiResult['education_history'] = $rules['education_history'];
        }

        return $aiResult;
    }

    private function mergeAddressesFromRulesWhenWrong(array $aiResult, ?array $rules): array
    {
        if (! is_array($rules) || empty($rules['addresses'])) {
            return $aiResult;
        }
        $aiAddr = $aiResult['addresses'] ?? [];
        $firstLine = '';
        if (is_array($aiAddr) && isset($aiAddr[0]) && is_array($aiAddr[0])) {
            $firstLine = (string) ($aiAddr[0]['address_line'] ?? '');
        }
        if ($firstLine === '' || $this->addressLineLooksLikeKulSwamiBleed($firstLine)) {
            $aiResult['addresses'] = $rules['addresses'];
            $rl = $rules['core']['address_line'] ?? null;
            if (is_string($rl) && $rl !== '') {
                if (! isset($aiResult['core']) || ! is_array($aiResult['core'])) {
                    $aiResult['core'] = [];
                }
                $aiResult['core']['address_line'] = $rl;
            }
        }

        return $aiResult;
    }

    /**
     * Deterministic DOB when जन्म तारीख is present; fills gaps after AI/rules merge if ISO is still missing.
     */
    private function extractHardIsoDateOfBirthFromText(string $rawText): ?string
    {
        if (! preg_match('/जन्म/u', $rawText)) {
            return null;
        }
        foreach (preg_split('/\R/u', $rawText) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || ! preg_match('/जन्म\s*तारीख/u', $line)) {
                continue;
            }
            if (! preg_match('/जन्म\s*तारीख\s*[:\-]+\s*(.+)$/us', $line, $m)) {
                continue;
            }
            $frag = $this->truncateDobFragmentAtNextLabels(trim($m[1]));
            if ($frag === '') {
                continue;
            }
            $iso = OcrNormalize::normalizeDate($frag);
            if ($iso !== null && $this->isValidIsoDate((string) $iso)) {
                $this->logDobExtractionTrace($line, $frag, $iso);

                return $iso;
            }
        }
        foreach (preg_split('/\R/u', $rawText) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || ! preg_match('/जन्म/u', $line)) {
                continue;
            }
            if (preg_match('/जन्म\s*तारीख/u', $line)) {
                continue;
            }
            if (preg_match('/जन्म\s*(?:ठिकाण|स्थळ|स्थान|वार|वेळ|वेळे|दिनांक)|जन्मवार|जन्मवेळ/u', $line)) {
                continue;
            }
            if (! preg_match('/[:\-–—]+\s*(.+)$/u', $line, $m)) {
                continue;
            }
            $frag = $this->truncateDobFragmentAtNextLabels(trim($m[1]));
            if ($frag === '' || ! preg_match('/\d{1,2}\s+\S+\s+\d{4}|\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{2,4}/u', $frag)) {
                continue;
            }
            $iso = OcrNormalize::normalizeDate($frag);
            if ($iso !== null && $this->isValidIsoDate((string) $iso)) {
                $this->logDobExtractionTrace($line, $frag, $iso);

                return $iso;
            }
        }

        return null;
    }

    private function truncateDobFragmentAtNextLabels(string $frag): string
    {
        if ($frag === '') {
            return '';
        }
        $parts = preg_split('/\s+(?:शिक्षण|व्यवसाय|पत्ता|उंची|ऊंची|वर्ण|रास|गोत्र|कुलस्वामी|नाडी|जन्म\s+वार|जन्म\s+वेळ)\s*[:\-]/u', $frag, 2);

        return trim($parts[0] ?? $frag);
    }

    private function logDobExtractionTrace(string $rawLine, string $valueFragment, ?string $extractedDate): void
    {
        if (! config('intake.dob_extraction_trace')) {
            return;
        }
        $norm = OcrNormalize::normalizeMarathiMonthWordsToEnglish(OcrNormalize::normalizeDigits($valueFragment));
        Log::info('DOB_EXTRACTION_TRACE', [
            'raw_line' => $rawLine,
            'normalized_line' => $norm,
            'extracted_date' => $extractedDate,
        ]);
    }

    private function isValidIsoDate(string $v): bool
    {
        return $v !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1;
    }

    /** @var list<string> */
    private const KNOWN_RASHI_NAMES = ['मेष', 'वृषभ', 'मिथुन', 'कर्क', 'सिंह', 'कन्या', 'तुला', 'वृश्चिक', 'धनु', 'मकर', 'कुंभ', 'मीन'];

    private function isLikelyRashi(?string $v): bool
    {
        if ($v === null || trim($v) === '') {
            return false;
        }
        $t = trim($v);
        foreach (self::KNOWN_RASHI_NAMES as $k) {
            if (mb_strpos($t, $k) !== false) {
                return true;
            }
        }

        return false;
    }

    private function isJunkRashiOrHoroscope(?string $v): bool
    {
        if ($v === null || trim($v) === '') {
            return true;
        }
        $t = trim($v);
        if (preg_match('/कुलस्वामी|कुलदेव|कुलस्वामीनी/u', $t)) {
            return true;
        }
        if (preg_match('/^स्वामी$/u', $t)) {
            return true;
        }
        if ($this->isLikelyRashi($t)) {
            return false;
        }
        if (mb_strlen($t) <= 5 && ! preg_match('/[०-९0-9]/u', $t)) {
            return true;
        }

        return false;
    }

    private function educationFieldLooksPolluted(string $v): bool
    {
        if ($v === '') {
            return false;
        }

        // OCR/AI mixes Latin and Devanagari (e.g. "kश्यप", "gotra"); table bleed puts नाडी/कुल into education.
        return preg_match('/गोत्र|gotra|GOTRA|देवक|devak|पुरशी|कौशिक|कश्यप|kश्यप|kश्य|नाडी|नाड\b|कुलस्वामी|कुळस्वामी|कुलदैवत|(?:^|\s)कुल\b/u', $v) === 1
            || (preg_match('/:\s*-\s*/u', $v) === 1 && preg_match('/गोत्र|gotra|कश्यप|kश्य|नाडी|कुलस्वामी/u', $v) === 1);
    }

    private function addressLineLooksLikeKulSwamiBleed(string $v): bool
    {
        if (preg_match('/माणकेश्वर|कुलस्वामी\s*\(|श्री\.\s*माणकेश्वर|गोत्र\s*[:\-]|नाडी\s*[:\-]|देवक\s*[:\-]/u', $v)) {
            return true;
        }

        return false;
    }

    private function birthPlaceLooksPolluted(string $v): bool
    {
        return preg_match('/वर्ण|गोत्र|कुलस्वामी|कुलदेव/u', $v) === 1;
    }

    /** True when value already looks like a normalized time or raw Marathi time phrase from biodata. */
    private function birthTimeLooksPlausible(string $s): bool
    {
        if ($s === '') {
            return false;
        }
        if (preg_match('/^\d{1,2}:\d{2}$/', $s)) {
            return true;
        }
        if (preg_match('/[०-९0-9]\s*:\s*[०-९0-9]|दुपारी|सकाळी|सकाळ|रात्री|पहाटे|वा\.|मि\.|फक्त|शुक्रवार|सोमवार|मंगळवार|बुधवार|गुरुवार|शनिवार|रविवार/u', $s)) {
            return true;
        }

        return false;
    }

    private function hasAjolMamaJunkRelativeRows(array $rows): bool
    {
        foreach ($rows as $r) {
            if (! is_array($r)) {
                continue;
            }
            $name = trim((string) ($r['name'] ?? ''));
            $rel = trim((string) ($r['relation_type'] ?? $r['relation'] ?? ''));
            if (preg_match('/आजोळ\s*\(\s*मामा\s*\)/u', $name) || preg_match('/आजोळ\s*\(\s*मामा\s*\)/u', $rel)) {
                return true;
            }
            if ($name === '' && preg_match('/^आजोळ/u', $rel)) {
                return true;
            }
        }

        return false;
    }

    private function repairSiblingsAndRelativesFromRules(array $aiResult, ?array $rules): array
    {
        if (! is_array($rules)) {
            return $aiResult;
        }
        $aiSib = $aiResult['siblings'] ?? [];
        if (is_array($aiSib) && $this->hasInvalidAiSiblingRows($aiSib)) {
            $aiResult['siblings'] = is_array($rules['siblings'] ?? null) ? $rules['siblings'] : [];
        }
        $aiRel = $aiResult['relatives'] ?? [];
        if (is_array($aiRel) && ($this->hasHeadingOnlyRelativeRows($aiRel) || $this->hasAjolMamaJunkRelativeRows($aiRel))) {
            $rulesRel = is_array($rules['relatives'] ?? null) ? $rules['relatives'] : [];
            if (! empty($rulesRel)) {
                $aiResult['relatives'] = $rulesRel;
            } else {
                $aiResult['relatives'] = array_values(array_filter($aiRel, function ($r) {
                    if (! is_array($r)) {
                        return false;
                    }
                    $name = trim((string) ($r['name'] ?? ''));
                    $raw = trim((string) ($r['raw_note'] ?? $r['notes'] ?? ''));
                    if ($name !== '') {
                        return true;
                    }

                    return ! ($raw !== '' && preg_match('/^(?:मुलीचे|मुलाचे|मुलीच्या)\s+(?:चुलते|मामा|मावशी)/u', $raw));
                }));
            }
        }

        return $aiResult;
    }

    private function hasInvalidAiSiblingRows(array $rows): bool
    {
        foreach ($rows as $r) {
            if (! is_array($r)) {
                continue;
            }
            $n = trim((string) ($r['name'] ?? ''));
            if ($n === '') {
                continue;
            }
            if (preg_match('/भाऊ\s*\/\s*बहिण|भाऊ\/बहिण/u', $n) && preg_match('/अविवाहित|अविवाहीत/u', $n)) {
                return true;
            }
            if (preg_match('/^बहिण\s*[:\-–—\.]+\s*अविवाहित/u', $n) || preg_match('/^बहीण\s*[:\-–—\.]+\s*अविवाहित/u', $n)) {
                return true;
            }
            if (preg_match('/^अविवाहित$/u', $n) || preg_match('/^अविवाहीत$/u', $n)) {
                return true;
            }
            if (preg_match('/^बहिण\s+एक\s+अविवाहीत/u', $n) || preg_match('/^बहिण\s+एक\s+अविवाहित/u', $n)) {
                return true;
            }
            if (preg_match('/^बहिण\s+एक\s+/u', $n) && preg_match('/\s+कु\s*$/u', $n)) {
                return true;
            }
        }

        return false;
    }

    private function hasHeadingOnlyRelativeRows(array $rows): bool
    {
        foreach ($rows as $r) {
            if (! is_array($r)) {
                continue;
            }
            $name = trim((string) ($r['name'] ?? ''));
            $raw = trim((string) ($r['raw_note'] ?? $r['notes'] ?? ''));
            if ($name === '' && $raw !== '' && preg_match('/^(?:मुलीचे|मुलाचे|मुलीच्या)\s+(?:चुलते|मामा|मावशी)/u', $raw)) {
                return true;
            }
        }

        return false;
    }

    private function stripMislabeledPrimaryContactRows(array &$aiResult): void
    {
        $fc = $aiResult['core']['father_contact_1'] ?? null;
        if ($fc === null || $fc === '' || ! is_array($aiResult['contacts'] ?? null)) {
            return;
        }
        if (! empty($aiResult['core']['primary_contact_number'] ?? null)) {
            return;
        }
        $fcDigits = preg_replace('/\D/', '', (string) $fc);
        if (strlen($fcDigits) >= 10) {
            $fcDigits = substr($fcDigits, -10);
        }
        $out = [];
        foreach ($aiResult['contacts'] as $c) {
            if (! is_array($c)) {
                $out[] = $c;

                continue;
            }
            $num = preg_replace('/\D/', '', (string) ($c['number'] ?? $c['phone_number'] ?? $c['phone'] ?? ''));
            if (strlen($num) >= 10) {
                $num = substr($num, -10);
            }
            if ($num === $fcDigits && (($c['label'] ?? '') === 'self' || ($c['is_primary'] ?? false) || ($c['type'] ?? '') === 'primary')) {
                continue;
            }
            $out[] = $c;
        }
        $aiResult['contacts'] = $out;
    }

    private function isTruncatedFatherName(string $v): bool
    {
        return (bool) preg_match('/,\s*मो\.?\s*$/u', trim($v));
    }

    private function isInvalidAiFullName(string $v): bool
    {
        $t = trim($v);

        return $t === '' || mb_strlen($t) < 4;
    }

    /**
     * Apply horoscope field sanitization to every row so devak/kuldaivat/gotra never contain junk.
     */
    private function sanitizeHoroscopeRows(array $rows): array
    {
        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            foreach (['devak', 'kuldaivat', 'gotra'] as $field) {
                $val = $row[$field] ?? null;
                if ($val !== null && $val !== '') {
                    $rows[$i][$field] = BiodataParserService::sanitizeHoroscopeValue(is_string($val) ? $val : (string) $val);
                }
            }
            $rows[$i]['gan'] = BiodataParserService::sanitizeGanValue($row['gan'] ?? null);
            unset($rows[$i]['blood_group']);
        }

        return $rows;
    }

    /**
     * Ensure AI output has the same guarantees as rules-only parser:
     * - all sections present
     * - extended_narrative normalized
     * - confidence_map is an array
     */
    private function ensureSsotShape(array $parsed): array
    {
        return $this->skeleton->ensure($parsed);
    }

    /**
     * Same जन्म तारीख value capture as applyDateOfBirthMergeFromRulesOrRawRecovery (for tracing).
     */
    private function extractJanmaTaarikhValueSegment(string $rawText): ?string
    {
        $flat = BiodataParserService::flattenHtmlTableForBiodata($rawText);
        $forScan = OcrNormalize::normalizeRawTextForParsing(OcrNormalize::normalizeRawText($flat));
        if (preg_match('/जन्म\s*तारीख\s*[:\-]+\s*([^\n]+)/u', $forScan, $dm)) {
            return trim($dm[1]);
        }

        return null;
    }
}
