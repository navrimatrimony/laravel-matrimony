<?php

namespace App\Services\Parsing;

use App\Services\BiodataParserService;

/**
 * Schema-aware cleanup for AI-produced SSOT-shaped parsed_json.
 *
 * - Converts JSON null sentinels ("null", "nil", …) to real null.
 * - Trims / collapses whitespace for string fields.
 * - Strips obvious formatting noise on caste/sub_caste (e.g. leading "%").
 * - Rejects label-only / header-like strings for horoscope and a few core fields
 *   using the same intent as BiodataParserService::rejectIfLabelNoise (subset).
 *
 * Does not infer missing data or rewrite names for “correctness”.
 */
final class ParsedJsonSsotNormalizer
{
    /** Exact label tokens that must never be stored as real field values (aligned with rules parser intent). */
    private const LABEL_NOISE_EXACT = [
        'जन्म वेळ', 'जन्म स्थळ', 'जन्म तारीख', 'जन्मतारीख', 'जन्मवार', 'जन्मवार व वेळ', 'वर्ण', 'शिक्षण', 'शिक्षिण',
        'आईचे नाव', 'वडिलांचे नाव', 'नाव', 'मुलीचे नाव', 'मुलाचे नाव', 'वधूचे नाव', 'नावरस नाव', 'नांवटस नाव', 'नवरस नाव',
        'जात', 'धर्म', 'उंची', 'गोत्र', 'कुलदैवत', 'नाडी', 'गण', 'रास', 'राशी', 'सध्याचा पत्ता', 'मोबाईल नं',
        'कौटुंबिक माहिती', 'संपर्क', 'वैवाहिक स्थिती', 'नक्षत्र', 'horoscope',
    ];

    /**
     * Merge AI and rules confidence maps without dropping AI-only keys.
     * When both provide a score for the same field, keep the higher value.
     *
     * @param  array<string, float|int>  $aiMap
     * @param  array<string, float|int>  $rulesMap
     * @return array<string, float>
     */
    public static function mergeConfidenceMaps(array $aiMap, array $rulesMap): array
    {
        $merged = [];
        $keys = array_unique(array_merge(array_keys($aiMap), array_keys($rulesMap)));
        foreach ($keys as $k) {
            $a = $aiMap[$k] ?? null;
            $r = $rulesMap[$k] ?? null;
            if ($a === null && $r === null) {
                continue;
            }
            if ($a === null) {
                $merged[$k] = (float) $r;

                continue;
            }
            if ($r === null) {
                $merged[$k] = (float) $a;

                continue;
            }
            $merged[$k] = max((float) $a, (float) $r);
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    public static function normalize(array $parsed): array
    {
        $nulledCore = [];
        $parsed['core'] = self::normalizeCore($parsed['core'] ?? [], $nulledCore);

        $parsed['contacts'] = self::normalizeContactRows($parsed['contacts'] ?? []);
        $parsed['children'] = self::normalizeChildRows($parsed['children'] ?? []);
        $parsed['education_history'] = self::normalizeEducationRows($parsed['education_history'] ?? []);
        $parsed['career_history'] = self::normalizeCareerRows($parsed['career_history'] ?? []);
        $parsed['addresses'] = self::normalizeAddressRows($parsed['addresses'] ?? []);
        $parsed['siblings'] = self::normalizeSiblingRows($parsed['siblings'] ?? []);
        $parsed['relatives'] = self::normalizeRelativeRows($parsed['relatives'] ?? []);
        $parsed['horoscope'] = self::normalizeHoroscopeRows($parsed['horoscope'] ?? []);

        if (isset($parsed['extended_narrative']) && is_array($parsed['extended_narrative'])) {
            $en = $parsed['extended_narrative'];
            foreach (['narrative_about_me', 'narrative_expectations', 'additional_notes'] as $ek) {
                if (array_key_exists($ek, $en)) {
                    $en[$ek] = self::normalizeNullableString($en[$ek], false);
                }
            }
            $parsed['extended_narrative'] = $en;
        }

        if (isset($parsed['confidence_map']) && is_array($parsed['confidence_map'])) {
            foreach ($nulledCore as $field) {
                $k = (string) $field;
                if (array_key_exists($k, $parsed['confidence_map'])) {
                    $parsed['confidence_map'][$k] = min((float) $parsed['confidence_map'][$k], 0.15);
                }
            }
        }

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $core
     * @param  array<int, string>  $nulledCoreFieldNames
     * @return array<string, mixed>
     */
    private static function normalizeCore(array $core, array &$nulledCoreFieldNames): array
    {
        $stringRejectLabel = [
            'full_name', 'father_name', 'mother_name', 'birth_place', 'birth_time',
            'religion', 'caste', 'sub_caste', 'marital_status', 'complexion', 'primary_contact_number',
            'father_occupation', 'mother_occupation', 'other_relatives_text',
        ];

        foreach ($core as $key => $value) {
            $before = $value;
            if (in_array($key, ['brother_count', 'sister_count', 'annual_income', 'family_income', 'height_cm', 'serious_intent_id'], true)) {
                $core[$key] = self::normalizeNumericOrNull($value);
            } else {
                $reject = in_array((string) $key, $stringRejectLabel, true);
                $core[$key] = self::normalizeNullableString($value, $reject);
                if (in_array((string) $key, ['caste', 'sub_caste'], true) && is_string($core[$key])) {
                    $core[$key] = self::stripCasteFormattingNoise($core[$key]);
                }
            }
            if ($before !== null && $before !== '' && ($core[$key] === null || $core[$key] === '')) {
                $nulledCoreFieldNames[] = (string) $key;
            }
        }

        return $core;
    }

    private static function normalizeContactRows(array $rows): array
    {
        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            foreach (['type', 'number', 'label'] as $f) {
                if (array_key_exists($f, $row)) {
                    $rows[$i][$f] = self::normalizeNullableString($row[$f], false);
                }
            }
        }

        return $rows;
    }

    private static function normalizeChildRows(array $rows): array
    {
        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            foreach (['name', 'gender'] as $f) {
                if (array_key_exists($f, $row)) {
                    $rows[$i][$f] = self::normalizeNullableString($row[$f], false);
                }
            }
            if (array_key_exists('birth_year', $row)) {
                $rows[$i]['birth_year'] = self::normalizeNumericOrNull($row['birth_year']);
            }
        }

        return $rows;
    }

    private static function normalizeEducationRows(array $rows): array
    {
        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            foreach (['degree', 'specialization', 'institution'] as $f) {
                if (array_key_exists($f, $row)) {
                    $rows[$i][$f] = self::normalizeNullableString($row[$f], false);
                }
            }
            if (array_key_exists('year', $row)) {
                $rows[$i]['year'] = self::normalizeNumericOrNull($row['year']);
            }
        }

        return $rows;
    }

    private static function normalizeCareerRows(array $rows): array
    {
        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            foreach (['job_title', 'role', 'company', 'employer', 'location'] as $f) {
                if (array_key_exists($f, $row)) {
                    $rows[$i][$f] = self::normalizeNullableString($row[$f], false);
                }
            }
        }

        return $rows;
    }

    private static function normalizeAddressRows(array $rows): array
    {
        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            foreach (['type', 'address_line', 'city', 'district'] as $f) {
                if (array_key_exists($f, $row)) {
                    $rows[$i][$f] = self::normalizeNullableString($row[$f], $f === 'address_line');
                }
            }
        }

        return $rows;
    }

    private static function normalizeSiblingRows(array $rows): array
    {
        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            foreach (['relation_type', 'name', 'occupation', 'address_line', 'contact_number', 'notes'] as $f) {
                if (array_key_exists($f, $row)) {
                    $rows[$i][$f] = self::normalizeNullableString($row[$f], false);
                }
            }
            if (isset($row['spouse']) && is_array($row['spouse'])) {
                $sp = $row['spouse'];
                foreach (['name', 'address_line', 'occupation_title', 'contact_number'] as $sf) {
                    if (array_key_exists($sf, $sp)) {
                        $sp[$sf] = self::normalizeNullableString($sp[$sf], false);
                    }
                }
                $rows[$i]['spouse'] = $sp;
            }
        }

        return $rows;
    }

    private static function normalizeRelativeRows(array $rows): array
    {
        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            foreach (['relation_type', 'name', 'occupation', 'address_line', 'contact_number', 'notes', 'raw_note'] as $f) {
                if (array_key_exists($f, $row)) {
                    $rows[$i][$f] = self::normalizeNullableString($row[$f], false);
                }
            }
        }

        return $rows;
    }

    private static function normalizeHoroscopeRows(array $rows): array
    {
        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            foreach (['rashi', 'nakshatra', 'nadi', 'gan', 'devak', 'kuldaivat', 'gotra'] as $f) {
                if (! array_key_exists($f, $row)) {
                    continue;
                }
                $v = self::normalizeNullableString($row[$f], true);
                if (is_string($v)) {
                    $v = BiodataParserService::sanitizeHoroscopeValue($v) ?? $v;
                }
                $rows[$i][$f] = $v;
            }
            if (array_key_exists('charan', $row)) {
                $rows[$i]['charan'] = self::normalizeNumericOrNull($row['charan']);
            }
            if (array_key_exists('blood_group', $row)) {
                $rows[$i]['blood_group'] = BiodataParserService::sanitizeBloodGroupValue($row['blood_group']);
            }
        }

        return $rows;
    }

    private static function normalizeNullableString(mixed $value, bool $rejectLabelOnly): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (! is_string($value)) {
            return is_bool($value) ? null : null;
        }
        if (self::isNullLikeString($value)) {
            return null;
        }
        $t = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if ($t === '') {
            return null;
        }
        if ($rejectLabelOnly && self::isLikelyLabelOnlyValue($t)) {
            return null;
        }

        return $t;
    }

    private static function normalizeNumericOrNull(mixed $value): int|float|null
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value) && self::isNullLikeString($value)) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_string($value)) {
            $t = trim($value);
            if ($t === '' || self::isNullLikeString($t)) {
                return null;
            }
            if (preg_match('/^-?\d+(\.\d+)?$/', $t)) {
                return str_contains($t, '.') ? (float) $t : (int) $t;
            }
        }

        return null;
    }

    public static function isNullLikeString(string $value): bool
    {
        $t = trim($value);
        if ($t === '') {
            return true;
        }
        $lower = mb_strtolower($t);
        foreach (['null', 'nil', 'n/a', 'na', 'none', 'undefined', '-', '—', '--', 'na.', 'n.a.'] as $s) {
            if ($lower === $s) {
                return true;
            }
        }
        // Romanized Marathi "nahi"
        if ($lower === 'nahi' || $t === 'नाही') {
            return true;
        }

        return false;
    }

    private static function stripCasteFormattingNoise(string $value): ?string
    {
        $v = trim($value);
        if ($v === '') {
            return null;
        }
        $v = preg_replace('/^[%#*_:\s]+/u', '', $v) ?? $v;
        $v = preg_replace('/[%#*_:\s]+$/u', '', $v) ?? $v;
        $v = trim($v);

        return $v === '' ? null : $v;
    }

    private static function isLikelyLabelOnlyValue(string $value): bool
    {
        $v = preg_replace('/\s+/u', ' ', trim($value));
        if ($v === '') {
            return true;
        }
        foreach (self::LABEL_NOISE_EXACT as $p) {
            if ($v === $p) {
                return true;
            }
        }
        $candidates = array_merge(self::LABEL_NOISE_EXACT, [
            'जन्मस्थळ', 'Birth place', 'Date of birth', 'Full name', 'Name', 'Occupation', 'नोकरी',
        ]);
        foreach ($candidates as $p) {
            if ($v === $p) {
                return true;
            }
        }
        $lower = mb_strtolower($v);
        foreach (['date of birth', 'full name', 'birth date'] as $en) {
            if ($lower === $en) {
                return true;
            }
        }
        if (preg_match('/^(?:नाव|जन्म|रास|नाडी|गण|धर्म|जात|उंची|शिक्षण|नक्षत्र)(?:\s*[:\-।.|]+)?$/u', $v)) {
            return true;
        }

        return false;
    }
}
