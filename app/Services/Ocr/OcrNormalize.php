<?php

namespace App\Services\Ocr;

/**
 * SSOT Day-27: OCR Normalization Helper
 * Normalizes extracted values before parsing/storage.
 * Used both in parsing pipeline and preview layer.
 */
class OcrNormalize
{
    /**
     * Convert Devanagari digits (०-९) to Arabic (0-9).
     */
    public static function normalizeDigits(string $text): string
    {
        $devanagari = ['०', '१', '२', '३', '४', '५', '६', '७', '८', '९'];
        $arabic = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        return str_replace($devanagari, $arabic, $text);
    }

    /**
     * Normalize blood group values.
     * Examples: "O Positive", "O+ve", "O +", "A+ve" => "O+", "A+"
     */
    public static function normalizeBloodGroup(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $value = strtoupper($value);

        // Remove spaces and common suffixes
        $value = str_replace([' ', 'VE', 'POSITIVE', 'NEGATIVE', 'NEG'], '', $value);

        // Normalize common patterns
        $patterns = [
            '/^O\+?$/i' => 'O+',
            '/^A\+?$/i' => 'A+',
            '/^B\+?$/i' => 'B+',
            '/^AB\+?$/i' => 'AB+',
            '/^O\-?$/i' => 'O-',
            '/^A\-?$/i' => 'A-',
            '/^B\-?$/i' => 'B-',
            '/^AB\-?$/i' => 'AB-',
        ];

        foreach ($patterns as $pattern => $replacement) {
            if (preg_match($pattern, $value)) {
                return $replacement;
            }
        }

        // If already in correct format, return as-is
        if (preg_match('/^[ABO]\+?\-?$/i', $value)) {
            return strtoupper($value);
        }

        return $value;
    }

    /**
     * Normalize height values to standard format "5'7\"".
     * Examples: "5.7 inch", "5 à¤«à¥à¤Ÿ 7 à¤‡à¤‚à¤š", "5' 7\"", "5 ft 7 in"
     */
    public static function normalizeHeight(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $value = self::normalizeDigits($value);

        // Pattern: "5 à¤«à¥à¤Ÿ 7 à¤‡à¤‚à¤š" or "5 à¤«à¥‚à¤Ÿ 7 à¤‡à¤‚à¤š"
        if (preg_match('/(\d+)\s*[à¤«à¤«à¥‚]à¥?à¤Ÿ\s*(\d+)\s*à¤‡à¤‚à¤š/u', $value, $m)) {
            return $m[1] . "'" . $m[2] . '"';
        }

        // Pattern: "5' 7\"" or "5'7\""
        if (preg_match("/(\d+)['']\s*(\d+)[\"]/", $value, $m)) {
            return $m[1] . "'" . $m[2] . '"';
        }

        // Pattern: "5 ft 7 in" or "5ft 7in"
        if (preg_match('/(\d+)\s*ft\s*(\d+)\s*in/i', $value, $m)) {
            return $m[1] . "'" . $m[2] . '"';
        }

        // Pattern: "5.7 inch" or "5.7inch"
        if (preg_match('/(\d+)\.(\d+)\s*inch/i', $value, $m)) {
            return $m[1] . "'" . $m[2] . '"';
        }

        // If already in correct format, return as-is
        if (preg_match("/^\d+['']\d+[\"]$/", $value)) {
            return $value;
        }

        return $value;
    }

    /**
     * Normalize date values to YYYY-MM-DD format.
     * Examples: "08-Aug 1983", "29-06-1992", "à¥¨à¥ª/à¥§à¥¦/à¥§à¥¯à¥¯à¥®", "13/03/2001"
     */
    public static function normalizeDate(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $value = self::normalizeDigits($value);

        // Pattern: "8 ऑगस्ट 1997" / "08 ऑगस्ट 1997" (Marathi month name, Latin script)
        $marathiMonthToNum = [
            'जानेवारी' => '01', 'फेब्रुवारी' => '02', 'मार्च' => '03', 'एप्रिल' => '04',
            'मे' => '05', 'जून' => '06', 'जुलै' => '07', 'ऑगस्ट' => '08',
            'सप्टेंबर' => '09', 'ऑक्टोबर' => '10', 'नोव्हेंबर' => '11', 'डिसेंबर' => '12',
        ];
        $monthAlt = implode('|', array_map(static fn (string $k): string => preg_quote($k, '/'), array_keys($marathiMonthToNum)));
        if (preg_match('/(\d{1,2})\s+('.$monthAlt.')\s+(\d{4})/u', $value, $m)) {
            $month = $marathiMonthToNum[$m[2]] ?? null;
            if ($month !== null) {
                $day = str_pad($m[1], 2, '0', STR_PAD_LEFT);

                return $m[3].'-'.$month.'-'.$day;
            }
        }

        // Pattern: "08-Aug 1983" or "08-Aug-1983"
        if (preg_match('/(\d{1,2})[\-\s]+([A-Za-z]{3})[\-\s]+(\d{4})/i', $value, $m)) {
            $monthMap = [
                'jan' => '01', 'feb' => '02', 'mar' => '03', 'apr' => '04',
                'may' => '05', 'jun' => '06', 'jul' => '07', 'aug' => '08',
                'sep' => '09', 'oct' => '10', 'nov' => '11', 'dec' => '12',
            ];
            $month = strtolower($m[2]);
            if (isset($monthMap[$month])) {
                $day = str_pad($m[1], 2, '0', STR_PAD_LEFT);
                return $m[3] . '-' . $monthMap[$month] . '-' . $day;
            }
        }

        // Pattern: "29-06-1992" (DD-MM-YYYY)
        if (preg_match('/(\d{1,2})[\-\/](\d{1,2})[\-\/](\d{4})/', $value, $m)) {
            $day = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $month = str_pad($m[2], 2, '0', STR_PAD_LEFT);
            $year = $m[3];
            // Heuristic: if day > 12, assume DD-MM-YYYY; else try both
            if ((int) $m[1] > 12) {
                return $year . '-' . $month . '-' . $day;
            }
            // Ambiguous: prefer DD-MM-YYYY for Indian format
            return $year . '-' . $month . '-' . $day;
        }

        // Pattern: "13/03/2001" (DD/MM/YYYY)
        if (preg_match('/(\d{1,2})\/(\d{1,2})\/(\d{4})/', $value, $m)) {
            $day = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $month = str_pad($m[2], 2, '0', STR_PAD_LEFT);
            $year = $m[3];
            return $year . '-' . $month . '-' . $day;
        }

        // Pattern: "à¥¨à¥ª/à¥§à¥¦/à¥§à¥¯à¥¯à¥®" already normalized by normalizeDigits
        if (preg_match('/(\d{1,2})\/(\d{1,2})\/(\d{4})/', $value, $m)) {
            $day = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $month = str_pad($m[2], 2, '0', STR_PAD_LEFT);
            $year = $m[3];
            return $year . '-' . $month . '-' . $day;
        }

        return $value;
    }

    /**
     * Normalize gender values to canonical format.
     * Examples: "male", "MALE", "M", "à¤ªà¥à¤°à¥à¤·" => "Male"; "female", "FEMALE", "F", "à¤¸à¥à¤¤à¥à¤°à¥€" => "Female"
     */
    public static function normalizeGender(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $valueLower = mb_strtolower($value);

        // Marathi: पुरुष -> Male
        if (mb_strpos($value, "\u{092A}\u{0941}\u{0930}\u{0941}\u{0937}") !== false || $valueLower === "\u{092A}\u{0941}\u{0930}\u{0941}\u{0937}") {
            return 'Male';
        }

        // Marathi: स्त्री -> Female
        if (mb_strpos($value, "\u{0938}\u{094D}\u{0924}\u{094D}\u{0930}\u{0940}") !== false || $valueLower === "\u{0938}\u{094D}\u{0924}\u{094D}\u{0930}\u{0940}") {
            return 'Female';
        }

        // English: male/MALE/M -> Male
        if (in_array($valueLower, ['male', 'm', 'masculine'])) {
            return 'Male';
        }

        // English: female/FEMALE/F -> Female
        if (in_array($valueLower, ['female', 'f', 'feminine'])) {
            return 'Female';
        }

        // If already in correct format, return as-is
        if (in_array($value, ['Male', 'Female'])) {
            return $value;
        }

        return $value;
    }

    /**
     * Normalize phone numbers to 10-digit Indian mobile format.
     * Examples: "+91 98765 43210", "98765-43210" => "9876543210"
     */
    public static function normalizePhone(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $value = self::normalizeDigits($value);

        // Remove all non-digit characters
        $digits = preg_replace('/\D/', '', $value);

        // If starts with country code (91), remove it
        if (strlen($digits) > 10 && substr($digits, 0, 2) === '91') {
            $digits = substr($digits, 2);
        }

        // Return last 10 digits if longer
        if (strlen($digits) > 10) {
            $digits = substr($digits, -10);
        }

        // Must be exactly 10 digits
        if (strlen($digits) === 10 && preg_match('/^[6-9]\d{9}$/', $digits)) {
            return $digits;
        }

        return $value;
    }

    /**
     * Apply baseline pattern corrections from ocr_correction_patterns.
     * Checks for exact matches in frequency_rule patterns.
     */
    public static function applyBaselinePatterns(string $fieldKey, string $value): string
    {
        $pattern = \App\Models\OcrCorrectionPattern::where('field_key', $fieldKey)
            ->where('wrong_pattern', $value)
            ->where('source', 'frequency_rule')
            ->where('is_active', true)
            ->orderByDesc('rule_version')
            ->first();

        if ($pattern) {
            return $pattern->corrected_value;
        }

        return $value;
    }

    /**
     * Normalize raw OCR text before parsing (Devanagari digits + noise removal).
     */
    public static function normalizeRawText(string $rawText): string
    {
        // Convert Devanagari digits to Arabic
        $text = self::normalizeDigits($rawText);

        // Remove obvious noise lines (conservative: only if not attached to a known label)
        $noisePatterns = [
            '/^.*à¤ªà¥…à¤•à¥‡à¤œ.*$/u',
            '/^.*à¤œà¥‰à¤¬.*$/u',
        ];

        $lines = explode("\n", $text);
        $cleaned = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            // Skip noise lines only if they don't contain known labels
            $hasLabel = false;
            $knownLabels = [
                'à¤¨à¤¾à¤µ', 'à¤œà¤¨à¥à¤®', 'à¤‰à¤‚à¤šà¥€', 'à¤¶à¤¿à¤•à¥à¤·à¤£', 'à¤¨à¥‹à¤•à¤°à¥€', 'à¤µà¥à¤¯à¤µà¤¸à¤¾à¤¯',
                'à¤œà¤¾à¤¤', 'à¤ªà¤¤à¥à¤¤à¤¾', 'à¤®à¥‹à¤¬à¤¾à¤ˆà¤²', 'à¤¸à¤‚à¤ªà¤°à¥à¤•', 'à¤°à¤•à¥à¤¤à¤—à¤Ÿ',
                'Name', 'DOB', 'Height', 'Education', 'Job', 'Address', 'Contact', 'Blood Group',
            ];

            foreach ($knownLabels as $label) {
                if (stripos($trimmed, $label) !== false) {
                    $hasLabel = true;
                    break;
                }
            }

            if (!$hasLabel) {
                $isNoise = false;
                foreach ($noisePatterns as $pattern) {
                    if (preg_match($pattern, $trimmed)) {
                        $isNoise = true;
                        break;
                    }
                }
                if ($isNoise) {
                    continue;
                }
            }

            $cleaned[] = $line;
        }

        return implode("\n", $cleaned);
    }

    /**
     * Sanity check for learned pattern corrected_value before activating.
     * Returns true if the value is safe to use as a pattern correction.
     */
    public static function sanityCheckLearnedValue(string $fieldKey, string $correctedValue): bool
    {
        $v = trim($correctedValue);
        if ($v === '') {
            return false;
        }

        switch ($fieldKey) {
            case 'full_name':
                // No parenthesized content (occupation/notes)
                if (preg_match('/\([^)]+\)/u', $v)) {
                    return false;
                }
                // Block latin noise (2+ consecutive latin letters except common prefixes)
                $withoutDevanagari = preg_replace('/[\p{Devanagari}\s\.\-]/u', '', $v);
                if (strlen($withoutDevanagari) >= 2) {
                    return false;
                }
                return mb_strlen($v) >= 2 && mb_strlen($v) <= 200;

            case 'date_of_birth':
                $v = self::normalizeDigits($v);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                    $y = (int) substr($v, 0, 4);
                    return $y >= 1950 && $y <= 2015;
                }
                return strlen($v) <= 20;

            case 'primary_contact_number':
                $digits = preg_replace('/\D/', '', self::normalizeDigits($v));
                return strlen($digits) === 10 && preg_match('/^[6-9]\d{9}$/', $digits);

            case 'marital_status':
                $allowed = ['Single', 'Married', 'Divorced', 'Widowed', 'Unmarried', 'Never Married', 'à¤…à¤µà¤¿à¤µà¤¾à¤¹à¤¿à¤¤', 'à¤µà¤¿à¤µà¤¾à¤¹à¤¿à¤¤'];
                $vLower = mb_strtolower($v);
                foreach ($allowed as $a) {
                    if (mb_strtolower($a) === $vLower) {
                        return true;
                    }
                }
                return mb_strlen($v) <= 50;

            case 'religion':
            case 'caste':
            case 'sub_caste':
                if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $v)) {
                    return false;
                }
                return mb_strlen($v) >= 1 && mb_strlen($v) <= 200;

            case 'gender':
                return in_array($v, ['Male', 'Female'], true);

            default:
                return mb_strlen($v) <= 500;
        }
    }

    /**
     * Normalise raw OCR text before parsing so both AI and rules get corrected input.
     * Fixes common OCR errors: ७५ फुट (75 feet) → ५ फुट (5 feet).
     * Does not modify stored raw_ocr_text; use the returned string only for parse().
     */
    public static function normalizeRawTextForParsing(string $rawText): string
    {
        if ($rawText === '') {
            return $rawText;
        }
        // OCR often reads Devanagari ५ (5) as ७५ (75) in height context. Fix "७५ फुट" → "५ फुट".
        $out = preg_replace('/७५\s*फ[ुू]ट/u', '५ फुट', $rawText);
        return $out !== null ? $out : $rawText;
    }
}

