<?php

namespace App\Services;

/*
|--------------------------------------------------------------------------
| Phase-5 Hybrid Rule + AI Fallback Parser — Maharashtra biodata, SSOT aligned
|--------------------------------------------------------------------------
|
| Does NOT modify raw_ocr_text. AI used only when required. Default unmarried.
| Children only for divorce/widow. Property, legal, horoscope, preferences optional.
|
*/
class BiodataParserService
{
    private const CONF_DIRECT = 0.9;
    private const CONF_REGEX = 0.8;
    private const CONF_AI = 0.75;
    private const CONF_HEURISTIC = 0.7;
    private const CONF_MISSING = 0.0;
    private const CONF_THRESHOLD_NO_OVERWRITE = 0.8;

    /** Keywords to trigger optional entity extraction */
    private const PROPERTY_TRIGGER = ['शेती', 'एकर', 'प्लॉट', 'फ्लॅट', 'स्थावर', 'गुऱ्हे'];
    private const LEGAL_TRIGGER = ['केस', 'कोर्ट', 'प्रकरण', 'maintenance', 'divorce case'];
    private const HOROSCOPE_TRIGGER = ['राशी', 'नक्षत्र', 'गण', 'नाडी', 'लग्नराशी'];
    private const PREFERENCES_TRIGGER = ['अपेक्षा', 'Looking for', 'Bride should'];
    private const CHILDREN_DIVORCE_WIDOW = ['घटस्फोट', 'divorce', 'widow', 'विधवा'];

    /** Context: address-only markers — never assign to caste or name. */
    private const ADDRESS_MARKERS = ['मु.पो.', 'ता.', 'जि.'];

    /** full_name reject if value contains any of these. */
    private const NAME_NOISE = ['जन्म', 'उंची', 'शिक्षण', 'नोकरी', 'मु.पो.', 'वडील', 'आई', 'प्रसन्न', 'कुळी'];

    /** OCR garbage words to remove in cleanValue(). */
    private const OCR_BLACKLIST_WORDS = ['snp', 'pres', 'gone', 'ae', 'ora', 'ast'];

    /** Degree keywords for education validation. */
    private const DEGREE_KEYWORDS = ['B.A', 'B.Com', 'B.Sc', 'M.Sc', 'MSC', 'BE', 'BAMS', 'MBBS', 'B.E', 'B.Tech', 'M.Com', 'BA', 'BCom', 'BSc'];

    /** Occupation keywords for career validation. */
    private const OCCUPATION_KEYWORDS = ['नोकरी', 'व्यवसाय', 'वेतन', 'engineer', 'software', 'teacher', 'doctor', 'clerk', 'business', 'सरकारी', 'खाजगी'];

    /** Caste dictionary (exact match). */
    private const CASTE_DICTIONARY = ['मराठा', 'ब्राह्मण', 'वैश्य', 'धनगर', 'माळी', 'जैन', 'मुस्लिम'];

    public function parse(string $rawText): array
    {
        $text = $this->normalizeText($rawText);
        $text = $this->removeWatermarkNoise($text);
        $text = $this->sanitizeDocument($text);
        $lines = array_map('trim', explode("\n", $text));
        $sections = $this->detectSections($lines);
        $personalText = implode("\n", $sections['PERSONAL'] ?? []);
        $familyText = implode("\n", $sections['FAMILY'] ?? []);
        $horoscopeText = implode("\n", $sections['HOROSCOPE'] ?? []);
        $contactText = implode("\n", $sections['CONTACT'] ?? []);
        $confidence = [];

        // ——— CORE: section-aware → label → regex → dictionary (order per PART 4) ———
        $fullName = $this->extractFieldAfterLabels($personalText, ['नाव', 'मुलाचे नाव', 'मुलीचे नाव', 'Name']);
        $fullName = $fullName ?? $this->extractFieldAfterLabels($text, ['नाव', 'मुलाचे नाव', 'मुलीचे नाव', 'Name']);
        $fullName = $this->validateFullName($fullName);
        $confidence['full_name'] = $fullName !== null ? self::CONF_DIRECT : self::CONF_MISSING;

        $birthDateRaw = $this->extractFieldAfterLabels($personalText, ['जन्मतारीख', 'जन्म दिनांक', 'DOB']);
        $birthDateRaw = $birthDateRaw ?? $this->extractFieldAfterLabels($text, ['जन्मतारीख', 'जन्म दिनांक', 'DOB']);
        $dateOfBirth = $this->normalizeDate($birthDateRaw);
        if ($dateOfBirth === null && preg_match('/\b(\d{1,2}\/\d{1,2}\/\d{4})\b/', $text, $dateMatch)) {
            $dateOfBirth = $this->normalizeDate($dateMatch[1]);
        }
        $confidence['date_of_birth'] = $dateOfBirth !== null ? self::CONF_DIRECT : self::CONF_MISSING;

        $heightRaw = $this->extractFieldAfterLabels($personalText, ['उंची', 'Height']);
        $heightRaw = $heightRaw ?? $this->extractFieldAfterLabels($text, ['उंची', 'Height']);
        $heightCm = $this->normalizeHeight($heightRaw);
        if ($heightCm === null) {
            $heightCm = $this->extractHeightFromText($personalText) ?? $this->extractHeightFromText($text);
        }
        $confidence['height'] = $heightCm !== null || $heightRaw !== null ? self::CONF_DIRECT : self::CONF_MISSING;

        $education = $this->extractFieldAfterLabels($personalText, ['शिक्षण', 'Education']);
        $education = $education ?? $this->extractFieldAfterLabels($text, ['शिक्षण', 'Education']);
        $education = $this->validateEducation($education);
        $confidence['highest_education'] = $education !== null ? self::CONF_DIRECT : self::CONF_MISSING;

        $fatherName = $this->extractFieldAfterLabels($familyText, ['वडिलांचे नाव', 'पिता', 'Father']);
        $fatherName = $fatherName ?? $this->extractFieldAfterLabels($text, ['वडिलांचे नाव', 'पिता', 'Father']);
        if ($fatherName !== null) {
            $fatherName = trim(preg_replace('/\(.+\)/', '', $fatherName));
            $fatherName = $fatherName === '' ? null : $fatherName;
        }
        $fatherName = $this->validateFatherName($fatherName);
        $motherName = $this->extractFieldAfterLabels($familyText, ['आईचे नाव', 'माता', 'Mother']);
        $motherName = $motherName ?? $this->extractFieldAfterLabels($text, ['आईचे नाव', 'माता', 'Mother']);
        $motherName = $this->validateMotherName($motherName);
        $confidence['father_name'] = $fatherName !== null ? self::CONF_DIRECT : self::CONF_MISSING;
        $confidence['mother_name'] = $motherName !== null ? self::CONF_DIRECT : self::CONF_MISSING;

        $brotherCount = $this->extractCount($familyText, ['भाऊ', 'बंधू', 'Brother']) ?? $this->extractCount($text, ['भाऊ', 'बंधू', 'Brother']);
        $sisterCount = $this->extractCount($familyText, ['बहिण', 'Sister']) ?? $this->extractCount($text, ['बहिण', 'Sister']);
        $confidence['brother_count'] = $brotherCount !== null ? self::CONF_REGEX : self::CONF_MISSING;
        $confidence['sister_count'] = $sisterCount !== null ? self::CONF_REGEX : self::CONF_MISSING;

        $gender = $this->inferGender($text);
        $religion = $this->extractField($text, ['धर्म', 'Religion']);
        $caste = $this->extractFieldAfterLabels($horoscopeText, ['वर्ण', 'जात', 'Caste']);
        if ($caste === null) {
            $caste = $this->extractCasteByDictionary($horoscopeText);
        }
        $caste = $this->normalizeCasteValue($caste);
        $caste = $this->validateCaste($caste);
        $subCaste = $this->extractField($text, ['उपजात', 'Sub caste']);
        $maritalRaw = $this->extractField($text, ['वैवाहिक स्थिती', 'Marital status']);
        $maritalStatus = $this->normalizeMaritalStatus($maritalRaw);
        if ($maritalStatus === null && ! $this->hasExplicitMaritalLabel($text)) {
            $maritalStatus = 'unmarried';
            $confidence['marital_status'] = self::CONF_REGEX;
        } else {
            $confidence['marital_status'] = $maritalStatus !== null ? self::CONF_DIRECT : self::CONF_MISSING;
        }

        $salaryRaw = $this->extractField($text, ['पगार', 'वेतन', 'Package', 'वार्षिक उत्पन्न']);
        $salary = $this->normalizeSalary($salaryRaw);
        $annualIncome = null;
        if (! empty($salary['annual_lakh'])) {
            $annualIncome = (int) $salary['annual_lakh'] * 100000;
        } elseif (! empty($salary['annual_raw'])) {
            $annualIncome = (int) $salary['annual_raw'];
        } elseif (! empty($salary['monthly'])) {
            $annualIncome = (int) $salary['monthly'] * 12;
        } elseif (preg_match('/[\d,]+/', (string) $salaryRaw, $m)) {
            $annualIncome = (int) str_replace(',', '', $m[0]);
        }
        $confidence['annual_income'] = $annualIncome !== null ? self::CONF_DIRECT : self::CONF_MISSING;

        $primaryContact = $this->extractPrimaryContactNumber($contactText) ?? $this->extractPrimaryContactNumber($text);
        $confidence['primary_contact_number'] = $primaryContact !== null ? self::CONF_DIRECT : self::CONF_MISSING;

        $bloodGroup = $this->normalizeBloodGroup($this->extractField($text, ['रक्तगट', 'Blood group']));
        $confidence['blood_group'] = $bloodGroup !== null ? self::CONF_DIRECT : self::CONF_MISSING;

        $addressBlock = $this->extractAddressBlock($contactText) ?? $this->extractAddressBlock($text);
        $confidence['address'] = $addressBlock !== null ? self::CONF_REGEX : self::CONF_MISSING;

        $core = [
            'full_name' => $fullName,
            'date_of_birth' => $dateOfBirth,
            'gender' => $gender,
            'religion' => $religion,
            'caste' => $caste,
            'sub_caste' => $subCaste,
            'marital_status' => $maritalStatus,
            'annual_income' => $annualIncome,
            'family_income' => null,
            'primary_contact_number' => $primaryContact,
            'serious_intent_id' => null,
            'father_name' => $fatherName,
            'mother_name' => $motherName,
            'brother_count' => $brotherCount,
            'sister_count' => $sisterCount,
            'height_cm' => $heightCm,
            'highest_education' => $education,
        ];

        // ——— RELATIONS (with confidence) ———
        $relationsResult = $this->resolveRelations($text);
        $relationsConfidence = $relationsResult['confidence'] ?? [];

        // ——— CHILDREN (only for divorce/widow; default []) ———
        $children = [];
        $confidence['children'] = self::CONF_MISSING;
        if ($maritalStatus === 'unmarried') {
            $children = [];
        } elseif ($this->hasAnyKeyword($text, self::CHILDREN_DIVORCE_WIDOW)) {
            $fragment = $this->extractParagraphContaining($text, self::CHILDREN_DIVORCE_WIDOW);
            if ($fragment !== null && $fragment !== '') {
                $aiResult = $this->aiParseFragment($fragment);
                $children = $aiResult['children'] ?? [];
                if (! empty($children)) {
                    $confidence['children'] = self::CONF_AI;
                }
            }
        }

        // ——— CAREER (gender-sensitive) ———
        $profession = $this->extractField($text, ['नोकरी', 'व्यवसाय', 'Profession', 'Occupation']);
        $profession = $this->validateCareer($profession);
        $careerHistory = [];
        if ($profession !== null) {
            $careerHistory = [['role' => $profession, 'employer' => null, 'from' => null, 'to' => null]];
            $confidence['career'] = self::CONF_DIRECT;
        } elseif ($gender === 'female') {
            $careerHistory = [];
            $confidence['career'] = self::CONF_MISSING;
        } else {
            $confidence['career'] = self::CONF_MISSING;
        }

        // ——— EDUCATION HISTORY ———
        $educationHistory = [];
        if ($education !== null) {
            $educationHistory = [['degree' => $education, 'institution' => null, 'year' => null]];
        }

        // ——— CONTACTS ———
        $contacts = [];
        if ($primaryContact !== null) {
            $contacts[] = ['number' => $primaryContact, 'type' => 'primary'];
        }

        // ——— OPTIONAL: Property ———
        $propertySummary = null;
        $propertyAssets = [];
        if ($this->hasAnyKeyword($text, self::PROPERTY_TRIGGER)) {
            $propertySummary = $this->extractField($text, ['शेती', 'जमीन', 'Property']);
            $propertyAssets = [];
            $confidence['property'] = $propertySummary !== null ? self::CONF_REGEX : self::CONF_AI;
        } else {
            $confidence['property'] = self::CONF_MISSING;
        }

        // ——— OPTIONAL: Legal ———
        $legalCases = [];
        if ($this->hasAnyKeyword($text, self::LEGAL_TRIGGER)) {
            $aiResult = $this->aiParseFragment($text);
            $legalCases = $aiResult['legal_cases'] ?? [];
            $confidence['legal_cases'] = ! empty($legalCases) ? self::CONF_AI : self::CONF_MISSING;
        } else {
            $confidence['legal_cases'] = self::CONF_MISSING;
        }

        // ——— OPTIONAL: Horoscope ———
        $horoscope = null;
        if ($this->hasAnyKeyword($text, self::HOROSCOPE_TRIGGER)) {
            $horoscope = $this->extractField($horoscopeText, ['राशी', 'नक्षत्र', 'लग्नराशी']) ?? $this->extractField($text, ['राशी', 'नक्षत्र', 'लग्नराशी']);
            $confidence['horoscope'] = $horoscope !== null ? self::CONF_REGEX : self::CONF_AI;
        } else {
            $confidence['horoscope'] = self::CONF_MISSING;
        }

        // ——— OPTIONAL: Preferences ———
        $preferences = null;
        if ($this->hasAnyKeyword($text, self::PREFERENCES_TRIGGER)) {
            $prefBlock = $this->extractParagraphContaining($text, self::PREFERENCES_TRIGGER);
            if ($prefBlock !== null && $prefBlock !== '') {
                $aiResult = $this->aiParseFragment($prefBlock);
                $preferences = $aiResult['preferences'] ?? null;
                $confidence['preferences'] = $preferences !== null ? self::CONF_AI : self::CONF_MISSING;
            } else {
                $confidence['preferences'] = self::CONF_MISSING;
            }
        } else {
            $confidence['preferences'] = self::CONF_MISSING;
        }

        // ——— AI FALLBACK for low-confidence or ambiguous ———
        $coreKeys = ['full_name', 'date_of_birth', 'gender', 'religion', 'caste', 'sub_caste', 'marital_status', 'annual_income', 'family_income', 'primary_contact_number', 'serious_intent_id', 'father_name', 'mother_name', 'brother_count', 'sister_count', 'height_cm', 'highest_education'];
        foreach ($coreKeys as $k) {
            if (! array_key_exists($k, $confidence)) {
                $confidence[$k] = isset($core[$k]) && $core[$k] !== null && $core[$k] !== '' ? self::CONF_REGEX : self::CONF_MISSING;
            }
        }

        $needsAi = false;
        foreach ($coreKeys as $k) {
            if (($confidence[$k] ?? 0) < 0.6 && ($core[$k] ?? null) === null) {
                $needsAi = true;
                break;
            }
        }
        if ($needsAi) {
            $aiResult = $this->aiParseFragment($text);
            $aiCore = $aiResult['core'] ?? [];
            $aiConf = $aiResult['confidence_map'] ?? [];
            foreach ($coreKeys as $k) {
                $existingConf = (float) ($confidence[$k] ?? 0);
                if ($existingConf >= self::CONF_THRESHOLD_NO_OVERWRITE) {
                    continue;
                }
                $aiVal = $aiCore[$k] ?? null;
                $aiC = (float) ($aiConf[$k] ?? 0);
                if ($aiVal !== null && $aiVal !== '' && $aiC >= 0.75) {
                    $core[$k] = $aiVal;
                    $confidence[$k] = self::CONF_AI;
                }
            }
        }

        $confidenceMap = array_merge(
            array_map(fn ($v) => (float) $v, $confidence),
            $relationsConfidence
        );

        return [
            'core' => $core,
            'contacts' => $contacts,
            'children' => $children,
            'education_history' => $educationHistory,
            'career_history' => $careerHistory,
            'addresses' => $addressBlock !== null ? [['raw' => $addressBlock, 'type' => 'current']] : [],
            'property_summary' => $propertySummary,
            'property_assets' => $propertyAssets,
            'horoscope' => $horoscope,
            'legal_cases' => $legalCases,
            'preferences' => $preferences,
            'extended_narrative' => null,
            'confidence_map' => $confidenceMap,
        ];
    }

    /**
     * Deterministic preprocessing: Marathi digits, line-start bullets, spaces, newlines, OCR artifacts.
     */
    private function normalizeText(string $text): string
    {
        $marathiDigits = ['०', '१', '२', '३', '४', '५', '६', '७', '८', '९'];
        $englishDigits = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        $text = str_replace($marathiDigits, $englishDigits, $text);

        $text = str_replace(["\r\n", "\r"], "\n", $text);

        $artifactWords = ['snp', 'ast', 'Orel'];
        $lines = explode("\n", $text);
        $lines = array_filter($lines, function ($line) use ($artifactWords) {
            $t = trim($line);
            if ($t === '') {
                return true;
            }
            foreach ($artifactWords as $word) {
                if (preg_match('/^\s*' . preg_quote($word, '/') . '\s*:?\s*$/iu', $line)) {
                    return false;
                }
            }
            return true;
        });
        $text = implode("\n", $lines);

        $text = preg_replace('/^\s*[«*•]+\s*/mu', '', $text);
        $text = preg_replace('/^\s*[0-9]{1,2}[.\-]\s*/mu', '', $text);

        $text = preg_replace('/[ \t]+/', ' ', $text);

        $text = preg_replace('/\n\s*\n\s*\n+/', "\n\n", $text);

        return trim($text);
    }

    private function removeWatermarkNoise(string $text): string
    {
        $patterns = [
            '/वधुवर सूचक.*/u',
            '/संपर्क.*\d{10}/u',
        ];
        return preg_replace($patterns, '', $text);
    }

    /**
     * Header/footer filter: remove decorative and boilerplate lines before section detection.
     */
    private function sanitizeDocument(string $text): string
    {
        $lines = explode("\n", $text);
        $filtered = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                $filtered[] = $line;
                continue;
            }
            if (mb_strpos($line, 'प्रसन्न') !== false || mb_strpos($line, 'शुभ') !== false || mb_strpos($line, 'भवतु') !== false) {
                continue;
            }
            if (preg_match('/^[\s\|।]*$/u', $line) || $line === '||' || $line === '।।') {
                continue;
            }
            if (mb_substr_count($line, 'श्री') > 2) {
                continue;
            }
            if (preg_match('/^[\p{P}\s\-*·.—]+$/u', $line)) {
                continue;
            }
            $filtered[] = $line;
        }
        return implode("\n", $filtered);
    }

    /**
     * Section detection: group lines into PERSONAL, FAMILY, HOROSCOPE, CONTACT, OTHER.
     */
    private function detectSections(array $lines): array
    {
        $sections = [
            'PERSONAL' => [],
            'FAMILY' => [],
            'HOROSCOPE' => [],
            'CONTACT' => [],
            'OTHER' => [],
        ];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            if (mb_strpos($line, 'वडील') !== false || mb_strpos($line, 'आई') !== false || mb_strpos($line, 'भाऊ') !== false || mb_strpos($line, 'बहिण') !== false) {
                $sections['FAMILY'][] = $line;
            } elseif (mb_strpos($line, 'राशी') !== false || mb_strpos($line, 'नक्षत्र') !== false || mb_strpos($line, 'नाडी') !== false || mb_strpos($line, 'कुलस्वामी') !== false) {
                $sections['HOROSCOPE'][] = $line;
            } elseif (mb_strpos($line, 'मु.पो.') !== false || mb_strpos($line, 'ता.') !== false || mb_strpos($line, 'जि.') !== false || mb_strpos($line, 'संपर्क') !== false) {
                $sections['CONTACT'][] = $line;
            } elseif (mb_strpos($line, 'जन्म') !== false || mb_strpos($line, 'उंची') !== false || mb_strpos($line, 'शिक्षण') !== false) {
                $sections['PERSONAL'][] = $line;
            } else {
                $sections['OTHER'][] = $line;
            }
        }
        return $sections;
    }

    /**
     * Context-based line classification. Returns associative array grouped by type.
     */
    private function classifyLines(string $text): array
    {
        $lines = array_map('trim', explode("\n", $text));
        $grouped = [
            'NAME' => [],
            'DOB' => [],
            'HEIGHT' => [],
            'FATHER' => [],
            'MOTHER' => [],
            'CASTE' => [],
            'ADDRESS' => [],
            'CAREER' => [],
            'EDUCATION' => [],
            'HOROSCOPE' => [],
        ];
        $degreePattern = '/\b(B\.A|B\.Com|B\.Sc|M\.Sc|MSC|BE|BAMS|MBBS|B\.E|B\.Tech|M\.Com|BA|BCom|BSc)\b/i';
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            if (mb_strpos($line, 'नाव') !== false || preg_match('/^(चि\.|कु\.)/u', $line)) {
                $grouped['NAME'][] = $line;
            } elseif (mb_strpos($line, 'जन्म') !== false) {
                $grouped['DOB'][] = $line;
            } elseif (mb_strpos($line, 'उंची') !== false || mb_strpos($line, 'फूट') !== false) {
                $grouped['HEIGHT'][] = $line;
            } elseif (mb_strpos($line, 'वडील') !== false) {
                $grouped['FATHER'][] = $line;
            } elseif (mb_strpos($line, 'आई') !== false) {
                $grouped['MOTHER'][] = $line;
            } elseif (mb_strpos($line, 'जात') !== false || mb_strpos($line, 'वर्ण') !== false) {
                $grouped['CASTE'][] = $line;
            } elseif (mb_strpos($line, 'मु.पो.') !== false || mb_strpos($line, 'ता.') !== false || mb_strpos($line, 'जि.') !== false) {
                $grouped['ADDRESS'][] = $line;
            } elseif (mb_strpos($line, 'नोकरी') !== false || mb_strpos($line, 'व्यवसाय') !== false || mb_strpos($line, 'वेतन') !== false || stripos($line, 'package') !== false) {
                $grouped['CAREER'][] = $line;
            } elseif (mb_strpos($line, 'शिक्षण') !== false || preg_match($degreePattern, $line)) {
                $grouped['EDUCATION'][] = $line;
            } elseif (mb_strpos($line, 'राशी') !== false || mb_strpos($line, 'नक्षत्र') !== false || mb_strpos($line, 'नाडी') !== false) {
                $grouped['HOROSCOPE'][] = $line;
            }
        }
        return $grouped;
    }

    /** full_name: 2–4 Marathi words; reject प्रसन्न, कुळी, >4 words, punctuation-heavy. */
    private function validateFullName(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (mb_strpos($value, 'प्रसन्न') !== false || mb_strpos($value, 'कुळी') !== false) {
            return null;
        }
        foreach (self::NAME_NOISE as $noise) {
            if (mb_strpos($value, $noise) !== false) {
                return null;
            }
        }
        foreach (self::ADDRESS_MARKERS as $marker) {
            if (mb_strpos($value, $marker) !== false) {
                return null;
            }
        }
        $words = preg_split('/\s+/u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if (count($words) > 4) {
            return null;
        }
        $punctOnly = preg_replace('/[\p{L}\p{N}\s]/u', '', $value);
        if ($punctOnly !== '' && mb_strlen($punctOnly) >= 3) {
            return null;
        }
        $marathiCount = 0;
        foreach ($words as $w) {
            if (preg_match('/[\x{0900}-\x{097F}]/u', $w)) {
                $marathiCount++;
            }
        }
        if (preg_match('/[\x{0900}-\x{097F}]/u', $value) && ($marathiCount < 2 || $marathiCount > 4)) {
            return null;
        }
        if (count($words) < 2) {
            return null;
        }
        return $value;
    }

    /** Caste strict normalization: split by - : digits, return only dictionary word. */
    private function normalizeCasteValue(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $v = trim($value);
        if (preg_match('/[-:\d]/u', $v)) {
            $tokens = preg_split('/[\s\-:,\d]+/u', $v, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($tokens as $token) {
                $token = trim($token);
                foreach (self::CASTE_DICTIONARY as $word) {
                    if ($token === $word || mb_strpos($token, $word) !== false) {
                        return $word;
                    }
                }
            }
            return null;
        }
        foreach (self::CASTE_DICTIONARY as $word) {
            if ($v === $word) {
                return $word;
            }
        }
        foreach (self::CASTE_DICTIONARY as $word) {
            if (mb_strpos($value, $word) !== false) {
                return $word;
            }
        }
        return null;
    }

    /** Caste must match dictionary exactly; reject if contains address pattern. */
    private function validateCaste(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (mb_strpos($value, 'ता.') !== false || mb_strpos($value, 'जि.') !== false) {
            return null;
        }
        $v = trim($value);
        foreach (self::CASTE_DICTIONARY as $word) {
            if ($v === $word) {
                return $word;
            }
        }
        foreach (self::CASTE_DICTIONARY as $word) {
            if (mb_strpos($value, $word) !== false) {
                return $word;
            }
        }
        return null;
    }

    /** father_name: must contain श्री OR at least 2 words; reject if contains phone. */
    private function validateFatherName(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (preg_match('/\d{10}/', $value)) {
            return null;
        }
        $words = preg_split('/\s+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY);
        if (mb_strpos($value, 'श्री') !== false) {
            return $value;
        }
        if (count($words) >= 2) {
            return $value;
        }
        return null;
    }

    /** mother_name: must contain श्रीमती OR at least 2 words; never ता., जि., comma-heavy address. */
    private function validateMotherName(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (preg_match('/\d{10}/', $value)) {
            return null;
        }
        if (mb_strpos($value, 'ता.') !== false || mb_strpos($value, 'जि.') !== false) {
            return null;
        }
        if (substr_count($value, ',') >= 2) {
            return null;
        }
        $words = preg_split('/\s+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY);
        if (mb_strpos($value, 'श्रीमती') !== false) {
            return $value;
        }
        if (count($words) >= 2) {
            return $value;
        }
        return null;
    }

    /** Education must contain degree keywords. */
    private function validateEducation(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        foreach (self::DEGREE_KEYWORDS as $kw) {
            if (stripos($value, $kw) !== false) {
                return $value;
            }
        }
        return null;
    }

    /** Career must contain occupation keywords. */
    private function validateCareer(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        foreach (self::OCCUPATION_KEYWORDS as $kw) {
            if (mb_strpos($value, $kw) !== false || stripos($value, $kw) !== false) {
                return $value;
            }
        }
        return null;
    }

    /**
     * Extract value after label only. Stops at newline. Returns null if no match or value equals label.
     */
    private function extractAfterLabel(string $text, string $label): ?string
    {
        $pattern = '/' . preg_quote($label, '/') . '\s*[:\-]?\s*([^\n]+)/u';
        if (preg_match($pattern, $text, $match) && isset($match[1])) {
            $value = trim($match[1]);
            if ($value === '' || $value === trim($label)) {
                return null;
            }
            $cleaned = $this->cleanValue($value);
            return $cleaned === '' ? null : $cleaned;
        }
        return null;
    }

    private function cleanValue(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/^[\-\sx>:\.,]+/u', '', $value);
        $value = preg_replace('/\s*x\s*$/u', '', $value);
        foreach (self::OCR_BLACKLIST_WORDS as $word) {
            $value = preg_replace('/\b' . preg_quote($word, '/') . '\b/iu', ' ', $value);
        }
        $value = preg_replace('/\s+/u', ' ', trim($value));
        if (mb_strlen($value) < 3) {
            return '';
        }
        return $value;
    }

    private function extractFieldAfterLabels(string $text, array $labels): ?string
    {
        foreach ($labels as $label) {
            $value = $this->extractAfterLabel($text, $label);
            if ($value !== null) {
                return $value;
            }
        }
        return null;
    }

    private function extractPrimaryContactNumber(string $text): ?string
    {
        $contactStr = $this->extractFieldAfterLabels($text, ['संपर्क क्रमांक', 'Contact']);
        if ($contactStr !== null && preg_match('/\b([6-9]\d{9})\b/', $contactStr, $m)) {
            return $m[1];
        }
        return $this->extractFieldRegex($text, '/\b([6-9]\d{9})\b/');
    }

    private function extractField(string $text, array $labels): ?string
    {
        foreach ($labels as $label) {
            if (preg_match('/' . preg_quote($label, '/') . '\s*[:\-]?\s*(.+?)(?=\n|$)/us', $text, $match)) {
                return $this->cleanValue(trim($match[1]));
            }
        }
        return null;
    }

    private function extractFieldRegex(string $text, string $pattern): ?string
    {
        if (preg_match($pattern, $text, $m) && isset($m[1])) {
            $v = trim($m[1]);
            return $v === '' ? null : $v;
        }
        return null;
    }

    private function extractCount(string $text, array $labels): ?int
    {
        foreach ($labels as $label) {
            if (preg_match('/' . preg_quote($label, '/') . '\s*[:\-]?\s*(\d+)/u', $text, $m)) {
                return (int) $m[1];
            }
        }
        return null;
    }

    private function normalizeDate(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = preg_replace('/\s+/', '', $value);
        $value = str_replace('/', '-', $value);
        $parts = array_values(array_filter(explode('-', $value)));
        if (count($parts) !== 3) {
            return null;
        }
        $a = (int) $parts[0];
        $b = (int) $parts[1];
        $c = (int) $parts[2];
        $year = $month = $day = null;
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

    private function normalizeHeight(?string $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (preg_match('/(\d+)\s*फूट\s*(\d+)/u', $value, $m)) {
            return round((float) $m[1] * 30.48 + (float) $m[2] * 2.54, 2);
        }
        if (preg_match('/(\d+)\'(\d+)/', $value, $m)) {
            return round((float) $m[1] * 30.48 + (float) $m[2] * 2.54, 2);
        }
        if (preg_match('/(\d+)\s*cm/u', $value, $m)) {
            return (float) $m[1];
        }
        return null;
    }

    /** Fallback: search full text for feet/inches patterns and return height in cm. */
    private function extractHeightFromText(string $text): ?float
    {
        if (preg_match('/(\d)\s*फूट\s*(\d)/u', $text, $m)) {
            $totalInches = (int) $m[1] * 12 + (int) $m[2];
            return round($totalInches * 2.54, 2);
        }
        if (preg_match("/(\d)'\s*(\d)/", $text, $m)) {
            $totalInches = (int) $m[1] * 12 + (int) $m[2];
            return round($totalInches * 2.54, 2);
        }
        return null;
    }

    /** Fallback: assign caste if dictionary word appears in text. */
    private function extractCasteByDictionary(string $text): ?string
    {
        foreach (self::CASTE_DICTIONARY as $word) {
            if (mb_strpos($text, $word) !== false) {
                return $word;
            }
        }
        return null;
    }

    private function normalizeSalary(?string $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (preg_match('/(\d+)\s*लाख/u', $value, $m)) {
            return ['annual_lakh' => (int) $m[1]];
        }
        if (preg_match('/(\d+)[,\s]*वार्षिक/u', $value, $m)) {
            return ['annual_raw' => (int) $m[1]];
        }
        if (preg_match('/(\d+)/', $value, $m)) {
            return ['monthly' => (int) $m[1]];
        }
        return [];
    }

    private function normalizeBloodGroup(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = strtoupper(str_replace([' ', 'VE', 'POSITIVE'], '', $value));
        return $value;
    }

    private function normalizeMaritalStatus(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $t = mb_strtolower(trim($value));
        if (str_contains($t, 'अविवाहित') || $t === 'unmarried') {
            return 'unmarried';
        }
        if (str_contains($t, 'विवाहित') || $t === 'married') {
            return 'married';
        }
        return $value;
    }

    private function hasExplicitMaritalLabel(string $text): bool
    {
        return preg_match('/वैवाहिक\s*स्थिती|Marital\s*status/u', $text) === 1;
    }

    private function inferGender(string $text): ?string
    {
        if (preg_match('/मुलीचे\s*नाव|बायकी|Bride|Female/u', $text)) {
            return 'female';
        }
        if (preg_match('/मुलाचे\s*नाव|पुरुष|Groom|Male/u', $text)) {
            return 'male';
        }
        return null;
    }

    private function extractAddressBlock(string $text): ?string
    {
        if (preg_match('/पत्ता[:\-]?\s*(.+?)(?=\n\n|\n[अ-हA-Z]|$)/us', $text, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/Address[:\-]?\s*(.+?)(?=\n\n|\n[अ-हA-Z]|$)/us', $text, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    private function hasAnyKeyword(string $text, array $keywords): bool
    {
        foreach ($keywords as $kw) {
            if (mb_strpos($text, $kw) !== false) {
                return true;
            }
        }
        return false;
    }

    private function extractParagraphContaining(string $text, array $keywords): ?string
    {
        $paragraphs = preg_split('/\n\s*\n/u', $text);
        foreach ($paragraphs as $p) {
            foreach ($keywords as $kw) {
                if (mb_strpos($p, $kw) !== false) {
                    return trim($p);
                }
            }
        }
        return null;
    }

    /**
     * Call AIParsingService on a fragment only. Merge-friendly result; do not overwrite high-confidence.
     */
    private function aiParseFragment(string $fragmentText): array
    {
        if (trim($fragmentText) === '') {
            return $this->emptySsotSkeleton();
        }
        try {
            $ai = app(AIParsingService::class);
            return $ai->parse($fragmentText);
        } catch (\Throwable $e) {
            return $this->emptySsotSkeleton();
        }
    }

    private function emptySsotSkeleton(): array
    {
        return [
            'core' => [],
            'contacts' => [],
            'children' => [],
            'education_history' => [],
            'career_history' => [],
            'addresses' => [],
            'property_summary' => null,
            'property_assets' => [],
            'horoscope' => null,
            'legal_cases' => [],
            'preferences' => null,
            'extended_narrative' => null,
            'confidence_map' => [],
        ];
    }

    /**
     * Relation resolution: चुलते present → काका = mavshicha_navra; else काका = father's brother.
     * Confidence: explicit label 0.9, heuristic 0.7.
     */
    private function resolveRelations(string $text): array
    {
        $data = [
            'kaka' => [],
            'chulate' => [],
            'mama' => [],
            'mavshi' => [],
            'mavshicha_navra' => [],
        ];
        $confidence = [];

        $chulatePresent = preg_match('/चुलते\s*[:\-]?\s*(.+)/u', $text, $mChulate);
        if ($chulatePresent) {
            $data['chulate'][] = trim($mChulate[1]);
            $confidence['chulate'] = self::CONF_DIRECT;
        } else {
            $confidence['chulate'] = self::CONF_MISSING;
        }

        if (preg_match('/मामा\s*[:\-]?\s*(.+)/u', $text, $m)) {
            $data['mama'][] = trim($m[1]);
            $confidence['mama'] = self::CONF_DIRECT;
        } else {
            $confidence['mama'] = self::CONF_MISSING;
        }

        if (preg_match('/मावशी\s*[:\-]?\s*(.+)/u', $text, $m)) {
            $data['mavshi'][] = trim($m[1]);
            $confidence['mavshi'] = self::CONF_DIRECT;
        } else {
            $confidence['mavshi'] = self::CONF_MISSING;
        }

        if (preg_match('/काका\s*[:\-]?\s*(.+)/u', $text, $m)) {
            $kakaValue = trim($m[1]);
            if ($chulatePresent && ! empty($data['chulate'])) {
                $data['mavshicha_navra'][] = $kakaValue;
                $confidence['mavshicha_navra'] = self::CONF_HEURISTIC;
                $confidence['kaka'] = self::CONF_MISSING;
            } else {
                $data['kaka'][] = $kakaValue;
                $confidence['kaka'] = self::CONF_DIRECT;
                $confidence['mavshicha_navra'] = self::CONF_MISSING;
            }
        } else {
            $confidence['kaka'] = self::CONF_MISSING;
            if (! isset($confidence['mavshicha_navra'])) {
                $confidence['mavshicha_navra'] = self::CONF_MISSING;
            }
        }

        return [
            'data' => $data,
            'confidence' => array_map(fn ($v) => (float) $v, $confidence),
        ];
    }
}
