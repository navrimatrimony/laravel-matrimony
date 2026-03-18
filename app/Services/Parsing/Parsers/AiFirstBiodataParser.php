<?php

namespace App\Services\Parsing\Parsers;

use App\Services\BiodataParserService;
use App\Services\ExternalAiParsingService;
use App\Services\Parsing\Contracts\BiodataParserInterface;
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
    ) {
    }

    public function parse(string $rawText, array $context = []): array
    {
        $parserMode = $context['parser_mode'] ?? 'ai_first_v1';
        $useV2 = $parserMode === 'ai_first_v2';

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
                $rules = $this->rulesParser->parse($rawText, $context);

                $aiCore = $aiResult['core'] ?? [];
                $rulesCore = $rules['core'] ?? [];

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
                    // Physical section – pull from high-precision rules parser when AI misses it
                    'height_cm',
                    'complexion',
                    'other_relatives_text', // इतर नातेवाईक (आडनाव/गाव) from rules extractFamilyStructures
                ];

                foreach ($fieldsToMerge as $field) {
                    $aiVal = $aiCore[$field] ?? null;
                    $aiHas = array_key_exists($field, $aiCore) && $aiVal !== null && $aiVal !== '';
                    $rulesHas = array_key_exists($field, $rulesCore) && $rulesCore[$field] !== null && $rulesCore[$field] !== '';
                    if (! $aiHas && $rulesHas) {
                        $aiCore[$field] = $rulesCore[$field];
                    }
                }

                $aiResult['core'] = $aiCore;

                // Contacts: if AI left contacts empty, fall back to rules-only
                // contacts (which already place the primary number first and
                // mark it as type=primary).
                $aiContacts = $aiResult['contacts'] ?? [];
                $rulesContacts = $rules['contacts'] ?? [];
                if ((! is_array($aiContacts) || count($aiContacts) === 0) && is_array($rulesContacts) && count($rulesContacts) > 0) {
                    $aiResult['contacts'] = $rulesContacts;
                }

                // Section-level fallback: use rules when AI section is empty or low-quality.
                $aiSiblings = $aiResult['siblings'] ?? null;
                $aiRelatives = $aiResult['relatives'] ?? null;
                $aiCareer = $aiResult['career_history'] ?? null;
                $aiHoroscope = $aiResult['horoscope'] ?? null;
                $rulesSiblings = $rules['siblings'] ?? [];
                $rulesRelatives = $rules['relatives'] ?? [];
                $rulesCareer = $rules['career_history'] ?? [];
                $rulesHoroscope = $rules['horoscope'] ?? [];

                if (! is_array($aiSiblings) || count($aiSiblings) === 0) {
                    if (! empty($rulesSiblings)) {
                        $aiResult['siblings'] = $rulesSiblings;
                    }
                }
                if (! $this->isUsableRelatives($aiRelatives) && ! empty($rulesRelatives)) {
                    $aiResult['relatives'] = $rulesRelatives;
                }
                if (! $this->isUsableCareerHistory($aiCareer) && ! empty($rulesCareer)) {
                    $aiResult['career_history'] = $rulesCareer;
                }
                if (! $this->isUsableHoroscope($aiHoroscope) && ! empty($rulesHoroscope)) {
                    $aiResult['horoscope'] = $rulesHoroscope;
                }

                // Confidence map: prefer the richer, path-based rules confidence map
                // over the placeholder AI confidence map.
                if (isset($rules['confidence_map']) && is_array($rules['confidence_map'])) {
                    $aiResult['confidence_map'] = $rules['confidence_map'];
                }

                // Final SSOT shape and LAST-MILE caste/sub_caste split.
                $result = $this->ensureSsotShape($aiResult);

                $core = $result['core'] ?? [];
                if (
                    isset($core['caste']) &&
                    is_string($core['caste']) &&
                    mb_strpos($core['caste'], 'मराठा') !== false &&
                    preg_match('/([0-9०-९]+\s*कुळी)/u', $core['caste'], $m)
                ) {
                    $core['sub_caste'] = trim($m[1]);
                    $core['caste'] = 'मराठा';
                }
                $result['core'] = $core;

                // Final horoscope sanitization: ensure devak/kuldaivat/gotra never contain junk in ai_first output.
                $result['horoscope'] = $this->sanitizeHoroscopeRows($result['horoscope'] ?? []);

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
            if ((is_string($job) && trim($job) !== '') || (is_string($company) && trim($company) !== '') || (is_string($loc) && trim($loc) !== '')) {
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
        foreach ($relatives as $row) {
            if (is_array($row) && ! empty($row['relation_type'] ?? null)) {
                return true;
            }
        }
        return false;
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
        $defaults = [
            'core' => [],
            'contacts' => [],
            'children' => [],
            'education_history' => [],
            'career_history' => [],
            'addresses' => [],
            'relatives' => [],
            'siblings' => [],
            'property_summary' => [],
            'property_assets' => [],
            'horoscope' => [],
            'preferences' => [],
            'extended_narrative' => [
                'narrative_about_me' => null,
                'narrative_expectations' => null,
                'additional_notes' => null,
            ],
            'confidence_map' => [],
        ];

        foreach ($defaults as $key => $default) {
            if (!array_key_exists($key, $parsed) || $parsed[$key] === null) {
                $parsed[$key] = $default;
            }
        }

        if (!is_array($parsed['extended_narrative'])) {
            $parsed['extended_narrative'] = $defaults['extended_narrative'];
        } else {
            $parsed['extended_narrative'] = array_merge(
                $defaults['extended_narrative'],
                $parsed['extended_narrative']
            );
        }

        if (!is_array($parsed['confidence_map'])) {
            $parsed['confidence_map'] = [];
        }

        return $parsed;
    }
}


