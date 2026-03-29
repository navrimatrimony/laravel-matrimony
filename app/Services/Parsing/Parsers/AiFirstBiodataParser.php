<?php

namespace App\Services\Parsing\Parsers;

use App\Services\AiVisionExtractionService;
use App\Services\BiodataParserService;
use App\Services\ExternalAiParsingService;
use App\Services\Parsing\IntakeParsedSnapshotSkeleton;
use App\Services\Parsing\IntakeControlledFieldNormalizer;
use App\Services\Parsing\Contracts\BiodataParserInterface;
use App\Services\Parsing\ParsedJsonSsotNormalizer;
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
    ) {
    }

    public function parse(string $rawText, array $context = []): array
    {
        $rawText = AiVisionExtractionService::sanitizeTransientParseInputText($rawText);

        $parserMode = $context['parser_mode'] ?? 'ai_first_v1';
        // ai_vision_extract_v1 uses a vision/transcription step to get higher-quality text;
        // it should then use the same stricter schema prompt as ai_first_v2.
        $useV2 = in_array($parserMode, ['ai_first_v2', 'ai_vision_extract_v1'], true);

        // Attempt AI parse first (v1 or v2 based on admin setting).
        try {
            $aiResult = $useV2
                ? $this->ai->parseToSsotV2($rawText)
                : $this->ai->parseToSsot($rawText);
            if (is_array($aiResult) && isset($aiResult['core'], $aiResult['confidence_map'])) {
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

                $aiCore = $aiResult['core'] ?? [];
                $rulesCore = is_array($rules) ? ($rules['core'] ?? []) : [];

                $fieldsToMerge = [
                    'birth_time',
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

                if (! is_array($aiSiblings) || count($aiSiblings) === 0) {
                    if (! empty($rulesSiblings)) {
                        $aiResult['siblings'] = $rulesSiblings;
                    }
                }
                // Relatives: prefer rules parser only when AI produced no rows or low-quality relative rows.
                if (! empty($rulesRelatives)) {
                    if (! is_array($aiRelatives) || count($aiRelatives) === 0 || ! $this->isUsableRelatives($aiRelatives)) {
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
                $result = $this->intakeControlledFieldNormalizer->normalizeSnapshot($result);

                // Clear education institution / career location when AI mis-parsed horoscope terms (devak/gotra).
                $result['education_history'] = BiodataParserService::sanitizeEducationInstitutionFromDevakStatic($result['education_history'] ?? []);
                $result['career_history'] = BiodataParserService::sanitizeCareerLocationFromGotraStatic($result['career_history'] ?? []);

                // Final horoscope sanitization: ensure devak/kuldaivat/gotra never contain junk in ai_first output.
                $result['horoscope'] = $this->sanitizeHoroscopeRows($result['horoscope'] ?? []);

                $result = ParsedJsonSsotNormalizer::normalize($result);
                // CRITICAL: enforce full canonical skeleton AFTER all normalization.
                $result = $this->ensureSsotShape($result);

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

    /** Allowed blood group values; rows with invalid blood_group are treated as low-quality. */
    private const VALID_BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

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
     * True if horoscope has at least one row without invalid blood_group (e.g. numeric garbage).
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
            $bg = $row['blood_group'] ?? null;
            if ($bg !== null && $bg !== '') {
                $norm = strtoupper(trim(str_replace([' ', 'VE', 'POSITIVE', 'NEGATIVE'], '', (string) $bg)));
                if (! in_array($norm, self::VALID_BLOOD_GROUPS, true)) {
                    return false;
                }
            }
        }
        return true;
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
        $aiBg = $a0['blood_group'] ?? null;
        $sanBg = BiodataParserService::sanitizeBloodGroupValue($aiBg);
        if (($sanBg === null || $sanBg === '') && ! empty($r0['blood_group'])) {
            $a0['blood_group'] = $r0['blood_group'];
        }

        return $aiResult;
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
        if (is_array($aiRel) && $this->hasHeadingOnlyRelativeRows($aiRel)) {
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
            $rows[$i]['blood_group'] = BiodataParserService::sanitizeBloodGroupValue($row['blood_group'] ?? null);
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
}


