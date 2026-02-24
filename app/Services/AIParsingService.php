<?php

namespace App\Services;

/*
|--------------------------------------------------------------------------
| Phase-5 Day-18 AI structured parsing engine — full SSOT schema
|--------------------------------------------------------------------------
|
| Deterministic Marathi + English extraction via regex. No DB write. No OpenAI.
| Returns EXACT SSOT structure. All keys exist even if empty.
| confidence_map: 0.9 if regex matched, 0 otherwise.
|
*/
class AIParsingService
{
    /** SSOT top-level keys. All must exist in parse() return. */
    private const SSOT_KEYS = [
        'core',
        'contacts',
        'children',
        'education_history',
        'career_history',
        'addresses',
        'property_summary',
        'property_assets',
        'horoscope',
        'legal_cases',
        'preferences',
        'extended_narrative',
        'confidence_map',
    ];

    /**
     * First capture group of pattern in text, trimmed; null if no match.
     */
    private function extract(string $pattern, string $text): ?string
    {
        if (preg_match($pattern, $text, $m) && isset($m[1])) {
            $v = trim($m[1]);
            return $v === '' ? null : $v;
        }
        return null;
    }

    /**
     * Normalize date string to Y-m-d. Accepts d-m-y, d/m/y, Y-m-d, etc.
     */
    private function normalizeDate(?string $date): ?string
    {
        if ($date === null || trim($date) === '') {
            return null;
        }
        $date = preg_replace('/\s+/', '', $date);
        $date = str_replace('/', '-', $date);
        $parts = array_values(array_filter(explode('-', $date)));
        if (count($parts) !== 3) {
            return null;
        }
        $a = (int) $parts[0];
        $b = (int) $parts[1];
        $c = (int) $parts[2];
        $year = null;
        $month = null;
        $day = null;
        if ($a >= 1000) {
            $year = $a;
            $month = $b;
            $day = $c;
        } elseif ($c >= 1000) {
            $day = $a;
            $month = $b;
            $year = $c;
        } else {
            $day = $a;
            $month = $b;
            $year = $c < 100 ? $c + ($c < 50 ? 2000 : 1900) : $c;
        }
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31 || $year < 1900 || $year > 2100) {
            return null;
        }
        if (! checkdate($month, $day, $year)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * Parse raw text into exact Phase-5 SSOT structure (deterministic regex only).
     * No DB write. No OpenAI. All keys present; confidence_map 0.9 if matched, 0 else.
     *
     * @return array{core: array, contacts: array, children: array, education_history: array, career_history: array, addresses: array, property_summary: array, property_assets: array, horoscope: array, legal_cases: array, preferences: array, extended_narrative: array, confidence_map: array<string, float>}
     */
    public function parse(string $text): array
    {
        $confidenceMap = [];

        $fullName = $this->extract('/नाव[:\-]?\s*(.+)/u', $text)
            ?? $this->extract('/Name[:\-]?\s*(.+)/u', $text);
        $confidenceMap['full_name'] = $fullName !== null ? 0.9 : 0.0;

        $dobRaw = $this->extract('/जन्मतारीख[:\-]?\s*([\d\-\/]+)/u', $text)
            ?? $this->extract('/DOB[:\-]?\s*([\d\-\/]+)/u', $text);
        $dateOfBirth = $this->normalizeDate($dobRaw);
        $confidenceMap['date_of_birth'] = $dateOfBirth !== null ? 0.9 : 0.0;

        $religion = $this->extract('/धर्म[:\-]?\s*(.+)/u', $text);
        $confidenceMap['religion'] = $religion !== null ? 0.9 : 0.0;

        $caste = $this->extract('/जात[:\-]?\s*(.+)/u', $text);
        $confidenceMap['caste'] = $caste !== null ? 0.9 : 0.0;

        $maritalRaw = $this->extract('/वैवाहिक स्थिती[:\-]?\s*(.+)/u', $text);
        $maritalStatus = null;
        if ($maritalRaw !== null) {
            $t = trim(mb_strtolower($maritalRaw));
            if (str_contains($t, 'अविवाहित') || $t === 'unmarried') {
                $maritalStatus = 'unmarried';
            } elseif (str_contains($t, 'विवाहित') || $t === 'married') {
                $maritalStatus = 'married';
            } else {
                $maritalStatus = $maritalRaw;
            }
        }
        $confidenceMap['marital_status'] = $maritalStatus !== null ? 0.9 : 0.0;

        $incomeRaw = $this->extract('/वार्षिक उत्पन्न[:\-]?\s*([\d,]+)/u', $text);
        $annualIncome = null;
        if ($incomeRaw !== null) {
            $annualIncome = (int) str_replace(',', '', $incomeRaw);
        }
        $confidenceMap['annual_income'] = $annualIncome !== null ? 0.9 : 0.0;

        $primaryContact = $this->extract('/संपर्क क्रमांक[:\-]?\s*(\d{10})/u', $text);
        $confidenceMap['primary_contact_number'] = $primaryContact !== null ? 0.9 : 0.0;

        $core = [
            'full_name' => $fullName,
            'date_of_birth' => $dateOfBirth,
            'gender' => null,
            'religion' => $religion,
            'caste' => $caste,
            'sub_caste' => null,
            'marital_status' => $maritalStatus,
            'annual_income' => $annualIncome,
            'family_income' => null,
            'primary_contact_number' => $primaryContact,
            'serious_intent_id' => null,
        ];

        $confidenceMap = array_map(fn ($v) => (float) $v, $confidenceMap);

        $result = [
            'core' => $core,
            'contacts' => [],
            'children' => [],
            'education_history' => [],
            'career_history' => [],
            'addresses' => [],
            'property_summary' => [],
            'property_assets' => [],
            'horoscope' => [],
            'legal_cases' => [],
            'preferences' => [],
            'extended_narrative' => [],
            'confidence_map' => $confidenceMap,
        ];

        foreach (self::SSOT_KEYS as $key) {
            if (! array_key_exists($key, $result)) {
                $result[$key] = $key === 'core' ? $core : ($key === 'confidence_map' ? [] : []);
            }
        }

        return $result;
    }
}
