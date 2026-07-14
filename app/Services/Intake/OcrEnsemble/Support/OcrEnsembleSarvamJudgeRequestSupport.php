<?php

namespace App\Services\Intake\OcrEnsemble\Support;

use App\Services\Intake\OcrEnsemble\Data\FieldResolutionFieldRecord;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase3Constants;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase4Constants;

/**
 * Deterministic helpers for Sarvam judge request building / serialization.
 */
final class OcrEnsembleSarvamJudgeRequestSupport
{
    /**
     * @param  array<string, string>  $reasons
     * @return array<string, string>
     */
    public static function orderedTriggerReasons(array $reasons): array
    {
        $ordered = [];
        foreach (OcrEnsemblePhase4Constants::TRIGGER_FIELDS as $fieldKey) {
            if (! array_key_exists($fieldKey, $reasons)) {
                continue;
            }
            $ordered[$fieldKey] = (string) $reasons[$fieldKey];
        }

        return $ordered;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function encodeCanonicalJson(array $payload): string
    {
        return json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    /**
     * @param  array<string, string|null>  $map
     * @return array<string, string|null>
     */
    public static function sortedNullableStringMap(array $map): array
    {
        $clean = [];
        foreach ($map as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            if ($value === null) {
                $clean[$key] = null;

                continue;
            }
            if (! is_string($value)) {
                continue;
            }
            $clean[$key] = $value;
        }
        ksort($clean);

        return $clean;
    }

    /**
     * Prefer final value; else first non-empty normalized (sorted engine keys); else first candidate.
     */
    public static function pickNormalizedValue(?FieldResolutionFieldRecord $record): ?string
    {
        if ($record === null) {
            return null;
        }

        $final = self::stringOrNull($record->final);
        if ($final !== null) {
            return $final;
        }

        $normalized = self::sortedNullableStringMap($record->normalized);
        foreach ($normalized as $value) {
            $picked = self::stringOrNull($value);
            if ($picked !== null) {
                return $picked;
            }
        }

        $candidates = self::sortedNullableStringMap($record->candidates);
        foreach ($candidates as $value) {
            $picked = self::stringOrNull($value);
            if ($picked !== null) {
                return $picked;
            }
        }

        return null;
    }

    public static function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Collect OCR lines that match the field label OR contain a known candidate value.
     *
     * @param  list<string>|null  $candidateValues
     * @return list<string>
     */
    public static function extractOcrSnippets(string $fieldKey, string $primaryOcrText, ?array $candidateValues = null): array
    {
        $lines = OcrEnsembleFieldTextSupport::lines($primaryOcrText);
        if ($lines === []) {
            return [];
        }

        $pattern = OcrEnsembleParseInputAssemblySupport::BODY_LABEL_PATTERNS[$fieldKey] ?? null;
        $needles = [];
        foreach ($candidateValues ?? [] as $value) {
            $needle = self::stringOrNull($value);
            if ($needle !== null) {
                $needles[] = mb_strtolower($needle, 'UTF-8');
            }
        }
        $needles = array_values(array_unique($needles));

        $snippets = [];
        $seen = [];
        foreach ($lines as $line) {
            $include = false;
            if (is_string($pattern) && $pattern !== '' && preg_match('/'.$pattern.'/ui', $line) === 1) {
                $include = true;
            }
            if (! $include && $needles !== []) {
                $haystack = mb_strtolower($line, 'UTF-8');
                foreach ($needles as $needle) {
                    if (mb_strpos($haystack, $needle, 0, 'UTF-8') !== false) {
                        $include = true;
                        break;
                    }
                }
            }
            if (! $include) {
                continue;
            }
            if (isset($seen[$line])) {
                continue;
            }
            $seen[$line] = true;
            $snippets[] = $line;
        }

        return $snippets;
    }

    /**
     * @param  list<string>  $enginesPresent
     * @return array{
     *     winning_engine: string|null,
     *     candidate_engines: list<string>,
     *     engines_present: list<string>
     * }
     */
    public static function buildEngineMetadata(?FieldResolutionFieldRecord $record, array $enginesPresent): array
    {
        $candidateEngines = [];
        if ($record !== null) {
            foreach (array_keys(self::sortedNullableStringMap($record->candidates)) as $engine) {
                $candidateEngines[] = $engine;
            }
            foreach (array_keys(self::sortedNullableStringMap($record->normalized)) as $engine) {
                if (! in_array($engine, $candidateEngines, true)) {
                    $candidateEngines[] = $engine;
                }
            }
            sort($candidateEngines);
        }

        $present = [];
        foreach ($enginesPresent as $engine) {
            if (is_string($engine) && $engine !== '') {
                $present[] = $engine;
            }
        }
        $present = array_values(array_unique($present));
        sort($present);

        return [
            'winning_engine' => $record?->winningEngine,
            'candidate_engines' => $candidateEngines,
            'engines_present' => $present,
        ];
    }

    public static function missingFieldRecord(): FieldResolutionFieldRecord
    {
        return new FieldResolutionFieldRecord(
            final: null,
            status: OcrEnsemblePhase3Constants::FIELD_STATUS_MISSING,
            source: OcrEnsemblePhase3Constants::FIELD_SOURCE_MISSING,
            winningEngine: null,
            confidence: null,
            reason: 'missing_from_envelope',
            candidates: [],
            normalized: [],
            validator: [
                'passed' => false,
                'code' => 'missing',
                'detail' => null,
            ],
        );
    }
}
