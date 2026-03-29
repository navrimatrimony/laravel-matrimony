<?php

namespace App\Services\Parsing;

/**
 * Recursively ensures all string leaves in parsed_json are valid UTF-8 before Eloquent JSON encoding.
 *
 * Laravel throws "Unable to encode attribute [parsed_json] ... Malformed UTF-8" when json_encode()
 * hits invalid byte sequences (common with OCR/vision output, broken surrogates, or mixed encodings).
 * This runs only at the persistence boundary: structure is preserved; only invalid bytes in strings
 * are stripped/repaired, never whole sections removed.
 */
final class IntakeParsedJsonUtf8Sanitizer
{
    /**
     * @param  array{strings_fixed?: int}|null  $stats  Optional; incremented when any string is modified.
     */
    public static function sanitize(mixed $value, ?array &$stats = null): mixed
    {
        if (is_string($value)) {
            $out = self::sanitizeString($value, $changed);
            if ($changed && $stats !== null) {
                $stats['strings_fixed'] = ($stats['strings_fixed'] ?? 0) + 1;
            }

            return $out;
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $keyChanged = false;
                $nk = is_string($k) ? self::sanitizeString($k, $keyChanged) : $k;
                if ($keyChanged && $stats !== null) {
                    $stats['strings_fixed'] = ($stats['strings_fixed'] ?? 0) + 1;
                }
                $out[$nk] = self::sanitize($v, $stats);
            }

            return $out;
        }

        return $value;
    }

    /**
     * @param -out bool  $changed  True if the string was altered.
     */
    public static function sanitizeString(string $s, ?bool &$changed = null): string
    {
        $changed = false;
        if ($s === '') {
            return $s;
        }

        if (mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }

        $changed = true;
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $s);
            if ($converted !== false) {
                return $converted;
            }
        }

        if (function_exists('mb_scrub')) {
            $t = mb_scrub($s, 'UTF-8');

            return is_string($t) ? $t : '';
        }

        $converted = mb_convert_encoding($s, 'UTF-8', 'UTF-8');

        return is_string($converted) ? $converted : '';
    }
}
