<?php

namespace App\Support;

/**
 * Fixes UTF-8 text that was stored after each original byte was misinterpreted as a Windows-1252
 * (or ISO-8859-1) code unit — classic mojibake.
 *
 * Examples:
 * - "राधा" → "à¤°à¤¾à¤§à¤¾" (Latin-1 style: all U+00xx)
 * - "राणी" → "à¤°à¤¾à¤£à¥€" where the last byte 0x80 became U+20AC (€) under Windows-1252
 */
final class Utf8MojibakeRepair
{
    /**
     * Unicode scalar → single byte for characters that differ between Windows-1252 and ISO-8859-1
     * in the 0x80–0x9F range (reverse of CP1252 decoder).
     *
     * @var array<int, int>
     */
    private const WIN1252_UNICODE_TO_BYTE = [
        0x20AC => 0x80,
        0x201A => 0x82,
        0x0192 => 0x83,
        0x201E => 0x84,
        0x2026 => 0x85,
        0x2020 => 0x86,
        0x2021 => 0x87,
        0x02C6 => 0x88,
        0x2030 => 0x89,
        0x0160 => 0x8A,
        0x2039 => 0x8B,
        0x0152 => 0x8C,
        0x017D => 0x8E,
        0x2018 => 0x91,
        0x2019 => 0x92,
        0x201C => 0x93,
        0x201D => 0x94,
        0x2022 => 0x95,
        // Note: do not map U+2013/U+2014 here — they are valid UTF-8 punctuation; mapping breaks correct labels like "Bachelor of Arts".
        0x02DC => 0x98,
        0x2122 => 0x99,
        0x0161 => 0x9A,
        0x203A => 0x9B,
        0x0153 => 0x9C,
        0x017E => 0x9E,
        0x0178 => 0x9F,
    ];

    private static function unicodeToLikelyOriginalByte(int $cp): ?int
    {
        if ($cp >= 0 && $cp <= 255) {
            return $cp;
        }

        return self::WIN1252_UNICODE_TO_BYTE[$cp] ?? null;
    }

    /**
     * UTF-8 en/em dash (and similar) misread as Windows-1252 code units: â + € + smart quote.
     */
    private static function replaceMisdecodedUtf8DashMojibake(string $s): string
    {
        $en = "\u{2013}";
        $em = "\u{2014}";
        if (! str_contains($s, 'â') && ! str_contains($s, "\xC3\xA2")) {
            return $s;
        }

        $out = preg_replace('/\x{00E2}\x{20AC}[\x{201C}\x{0022}]/u', $en, $s) ?? $s;
        $out = preg_replace('/\x{00E2}\x{20AC}\x{201D}/u', $em, $out) ?? $out;

        return $out;
    }

    public static function repair(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $value = self::replaceMisdecodedUtf8DashMojibake($value);

        // Already-correct UTF-8 with real en/em dash: do not run per-byte reconstruction (would corrupt).
        if (preg_match('/\x{2013}|\x{2014}/u', $value)) {
            return $value;
        }

        if (preg_match('/\p{Devanagari}/u', $value)
            || preg_match('/\p{Tamil}/u', $value)
            || preg_match('/\p{Bengali}/u', $value)
            || preg_match('/\p{Gujarati}/u', $value)
            || preg_match('/\p{Gurmukhi}/u', $value)) {
            return $value;
        }

        $len = mb_strlen($value, 'UTF-8');
        $bytes = '';
        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($value, $i, 1, 'UTF-8');
            $cp = mb_ord($ch, 'UTF-8');
            $b = self::unicodeToLikelyOriginalByte($cp);
            if ($b === null) {
                return $value;
            }
            $bytes .= \chr($b);
        }

        if ($bytes === '' || ! mb_check_encoding($bytes, 'UTF-8')) {
            return $value;
        }

        if (preg_match('/\p{Devanagari}/u', $bytes)
            || preg_match('/\p{Tamil}/u', $bytes)
            || preg_match('/\p{Bengali}/u', $bytes)
            || preg_match('/\p{Gujarati}/u', $bytes)
            || preg_match('/\p{Gurmukhi}/u', $bytes)) {
            return $bytes;
        }

        return $value;
    }
}
