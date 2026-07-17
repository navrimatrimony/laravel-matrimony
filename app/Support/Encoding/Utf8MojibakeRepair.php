<?php

namespace App\Support\Encoding;

/**
 * Reverse classic "UTF-8 bytes misread as Windows-1252, then stored as UTF-8" mojibake.
 *
 * Example: Devanagari क (bytes E0 A4 95) stored as à¤• and repaired back to क.
 * Also handles Gujarati (àª… / à«…) and repeated (double/triple) encoding passes.
 */
final class Utf8MojibakeRepair
{
    /**
     * @var array<int, int>|null Unicode codepoint => original CP1252 byte
     */
    private static ?array $cp1252Reverse = null;

    public static function looksLikeMojibake(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        // Indic UTF-8 lead byte E0 with trail A4–B5, misread as CP1252, becomes:
        // à¤ à¥ (Devanagari) … àª à« (Gujarati) … à° à± (Telugu) … à´ àµ (Malayalam).
        if (preg_match('/à[\x{00A4}-\x{00B5}]/u', $value) === 1) {
            return true;
        }

        // Triple-encoding residue often looks like ÃÂ¤ / ÃÂ¥ (UTF-8 of à¤ mis-decoded again).
        return str_contains($value, 'ÃÂ¤')
            || str_contains($value, 'ÃÂ¥')
            || str_contains($value, 'Ã Â¤');
    }

    /**
     * Return repaired UTF-8 text, or null when the value should not be changed.
     */
    public static function repair(string $value): ?string
    {
        if (! self::looksLikeMojibake($value)) {
            return null;
        }

        $current = $value;
        $repaired = null;

        for ($pass = 0; $pass < 4; $pass++) {
            $next = self::decodeOnce($current);
            if ($next === null || $next === $current) {
                break;
            }

            $repaired = $next;
            $current = $next;

            if (self::isCleanIndic($repaired) && ! self::looksLikeMojibake($repaired)) {
                break;
            }
        }

        if ($repaired === null || $repaired === $value) {
            return null;
        }

        if (! mb_check_encoding($repaired, 'UTF-8')) {
            return null;
        }

        if (! self::isCleanIndic($repaired)) {
            return null;
        }

        if (self::looksLikeMojibake($repaired)) {
            return null;
        }

        return $repaired;
    }

    private static function isCleanIndic(string $value): bool
    {
        // Major Indic blocks used in Indian address/master data.
        return preg_match('/[\x{0900}-\x{0D7F}]/u', $value) === 1;
    }

    private static function decodeOnce(string $value): ?string
    {
        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false || $chars === []) {
            return null;
        }

        $bytes = '';
        foreach ($chars as $ch) {
            $cp = mb_ord($ch, 'UTF-8');
            if ($cp === false) {
                return null;
            }

            $byte = self::cp1252ByteForCodepoint($cp);
            if ($byte === null) {
                return null;
            }

            $bytes .= chr($byte);
        }

        if ($bytes === '' || ! mb_check_encoding($bytes, 'UTF-8')) {
            return null;
        }

        return $bytes;
    }

    private static function cp1252ByteForCodepoint(int $cp): ?int
    {
        self::bootReverseMap();

        return self::$cp1252Reverse[$cp] ?? null;
    }

    private static function bootReverseMap(): void
    {
        if (self::$cp1252Reverse !== null) {
            return;
        }

        $map = [];

        for ($i = 0; $i <= 0xFF; $i++) {
            if ($i < 0x80 || $i >= 0xA0) {
                $map[$i] = $i;
            }
        }

        for ($i = 0; $i <= 0x9F; $i++) {
            $map[$i] = $i;
        }

        $special = [
            0x80 => 0x20AC,
            0x82 => 0x201A,
            0x83 => 0x0192,
            0x84 => 0x201E,
            0x85 => 0x2026,
            0x86 => 0x2020,
            0x87 => 0x2021,
            0x88 => 0x02C6,
            0x89 => 0x2030,
            0x8A => 0x0160,
            0x8B => 0x2039,
            0x8C => 0x0152,
            0x8E => 0x017D,
            0x91 => 0x2018,
            0x92 => 0x2019,
            0x93 => 0x201C,
            0x94 => 0x201D,
            0x95 => 0x2022,
            0x96 => 0x2013,
            0x97 => 0x2014,
            0x98 => 0x02DC,
            0x99 => 0x2122,
            0x9A => 0x0161,
            0x9B => 0x203A,
            0x9C => 0x0153,
            0x9E => 0x017E,
            0x9F => 0x0178,
        ];

        foreach ($special as $byte => $unicode) {
            $map[$unicode] = $byte;
        }

        self::$cp1252Reverse = $map;
    }
}
