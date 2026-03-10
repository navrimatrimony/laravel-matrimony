<?php

namespace App\Services\Parsing\Parsers;

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
        // Attempt AI parse first.
        try {
            $aiResult = $this->ai->parseToSsot($rawText);
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
                ];

                foreach ($fieldsToMerge as $field) {
                    $aiHas = array_key_exists($field, $aiCore) && $aiCore[$field] !== null;
                    $rulesHas = array_key_exists($field, $rulesCore) && $rulesCore[$field] !== null;
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

                // Prefer structured siblings/relatives and cleaned career history
                // from rules parser when present.
                if (! empty($rules['siblings'] ?? [])) {
                    $aiResult['siblings'] = $rules['siblings'];
                }
                if (! empty($rules['relatives'] ?? [])) {
                    $aiResult['relatives'] = $rules['relatives'];
                }
                if (! empty($rules['career_history'] ?? [])) {
                    $aiResult['career_history'] = $rules['career_history'];
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


