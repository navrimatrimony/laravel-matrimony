<?php

namespace App\Support\MasterData;

/**
 * Deterministic Latin → Devanagari for English master labels.
 * Uses ext-intl Latin-Devanagari when available; otherwise known maps + conservative token fallback.
 */
final class RomanToDevanagariApprox
{
    /** @var array<string, string> lowercase full-string or single-token overrides */
    private static array $extraMap = [];

    /**
     * Merge additional token/full-string overrides (e.g. from generated data).
     *
     * @param  array<string, string>  $map
     */
    public static function mergeOverrides(array $map): void
    {
        foreach ($map as $k => $v) {
            self::$extraMap[mb_strtolower(trim($k))] = $v;
        }
    }

    public static function containsDevanagari(string $s): bool
    {
        return preg_match('/\p{Devanagari}/u', $s) === 1;
    }

    public static function toDevanagari(string $english): string
    {
        $t = trim($english);
        if ($t === '') {
            return '';
        }
        if (self::containsDevanagari($t)) {
            return preg_replace('/\s+/u', ' ', $t) ?? $t;
        }

        $lower = mb_strtolower($t);
        if (isset(self::$extraMap[$lower])) {
            return self::$extraMap[$lower];
        }

        $known = self::knownFullLabels();
        if (isset($known[$lower])) {
            return $known[$lower];
        }

        // Phrase: map word-by-word + intl per token when possible
        $tokens = preg_split('/\s+/u', $t) ?: [];
        $out = [];
        foreach ($tokens as $token) {
            $token = trim($token, " \t\n\r\0\x0B/,-");
            if ($token === '') {
                continue;
            }
            $tl = mb_strtolower($token);
            if (isset(self::$extraMap[$tl])) {
                $out[] = self::$extraMap[$tl];
            } elseif (isset($known[$tl])) {
                $out[] = $known[$tl];
            } else {
                $out[] = self::tokenToDevanagari($token);
            }
        }

        return implode(' ', $out);
    }

    private static function tokenToDevanagari(string $token): string
    {
        $tl = mb_strtolower($token);
        $known = self::knownFullLabels();
        if (isset($known[$tl])) {
            return $known[$tl];
        }
        if (function_exists('transliterator_transliterate')) {
            $tr = @transliterator_transliterate('Latin-Devanagari', $token);
            if (is_string($tr) && $tr !== '' && self::containsDevanagari($tr)) {
                return $tr;
            }
        }

        // Last resort: keep ASCII token (tests / no-intl); import JSON should override via generator pass
        return $token;
    }

    /**
     * Common religion/caste/subcaste English → Marathi (established or conventional).
     *
     * @return array<string, string>
     */
    public static function knownFullLabels(): array
    {
        return [
            'hindu' => 'हिंदू',
            'muslim' => 'मुस्लिम',
            'islam' => 'इस्लाम',
            'christian' => 'ख्रिश्चन',
            'jain' => 'जैन',
            'sikh' => 'शीख',
            'bahai' => 'बहाई',
            'buddhist' => 'बौद्ध',
            'parsi' => 'पारशी',
            'no religion' => 'कोणताही धर्म नाही',
            'brahmin' => 'ब्राह्मण',
            'maratha' => 'मराठा',
            'rajput' => 'राजपूत',
            'dhangar' => 'धनगर',
            'mali' => 'माळी',
            'lingayat' => 'लिंगायत',
            'lingayath' => 'लिंगायत',
            'shaikh' => 'शेख',
            'sheikh' => 'शेख',
            'shekh' => 'शेख',
            'shia' => 'शिया',
            'sunni' => 'सुन्नी',
            'digambar' => 'दिगंबर',
            'shwetamber' => 'श्वेतांबर',
            'shwetambar' => 'श्वेतांबर',
            'ismaili' => 'इस्माईली',
            'neo-buddhist' => 'नव-बौद्ध',
            'anglo indian' => 'अँग्लो इंडियन',
            'mahar' => 'महार',
            'matang' => 'मातंग',
        ];
    }
}
