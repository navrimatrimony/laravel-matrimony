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
    private const PROPERTY_TRIGGER = ['शेती', 'एकर', 'प्लॉट', 'फ्लॅट', 'स्थावर', 'गुऱ्हे', 'स्थायिक', 'मालमत्ता'];
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
    private const OCCUPATION_KEYWORDS = ['नोकरी', 'व्यवसाय', 'वेतन', 'शेती', 'engineer', 'software', 'teacher', 'doctor', 'clerk', 'business', 'सरकारी', 'खाजगी', 'Limited', 'BPO', 'operator', 'Field', 'Home', 'I.T', 'Co ', 'Forge'];

    /** Caste dictionary (exact match). */
    private const CASTE_DICTIONARY = [
    'मराठा',
    'ब्राह्मण',
    'देशस्थ',
    'लिंगायत',
    'धनगर',
    'माळी',
    'जैन',
    'मुस्लिम'
];

    public function parse(string $rawText): array
    {
        // SSOT Day-27: Apply baseline normalization (Devanagari digits + noise removal)
        $text = \App\Services\Ocr\OcrNormalize::normalizeRawText($rawText);
        $text = $this->normalizeText($text);
        $text = $this->removeWatermarkNoise($text);
        $text = $this->sanitizeDocument($text);
        $lines = array_map('trim', explode("\n", $text));
        $sections = $this->detectSections($lines);
        $personalText = implode("\n", $sections['PERSONAL'] ?? []);
        $familyText = implode("\n", $sections['FAMILY'] ?? []);
        $horoscopeText = implode("\n", $sections['HOROSCOPE'] ?? []);
        $contactText = implode("\n", $sections['CONTACT'] ?? []);
        $confidence = [];

        // ——— Romanized/garbled OCR fallback (e.g. PDF font exports मुलीचे नाव as eqykps ukao) ———
        $romanized = $this->extractFromRomanizedLabels($text);

        // ——— CORE: section-aware → label → regex → dictionary (order per PART 4) ———
        // Prefer name from line that STARTS with नाव/मुलाचे नाव/मुलीचे नाव so we don't take नांवटस नाव (nakshatra) as full name
        $fullName = $this->extractFullNameFromLineStart($text);
        $fullName = $fullName ?? $this->extractAfterLabelNextLine($text, 'मुलीचे नाव') ?? $this->extractAfterLabelNextLine($text, 'मुलाचे नाव');
        $fullName = $fullName ?? $this->extractFieldAfterLabels($personalText, ['मुलाचे नाव', 'मुलीचे नाव', 'नाव', 'Name', 'Full name', 'Full Name']);
        $fullName = $fullName ?? $this->extractFieldAfterLabels($text, ['मुलाचे नाव', 'मुलीचे नाव', 'नाव', 'Name', 'Full name', 'Full Name']);
        $fullName = $fullName ?? (isset($romanized['full_name']) ? $this->validateFullName($this->cleanRomanizedName($romanized['full_name'])) : null);
        $fullName = $this->validateFullName($fullName);
        $confidence['full_name'] = $fullName !== null ? self::CONF_DIRECT : self::CONF_MISSING;

        $birthDateRaw = $this->extractFieldAfterLabels($personalText, ['जन्मतारीख', 'जन्म दिनांक', 'DOB', 'Date of Birth', 'Birth Date', 'Date of birth']);
        $birthDateRaw = $birthDateRaw ?? $this->extractFieldAfterLabels($text, ['जन्मतारीख', 'जन्म दिनांक', 'DOB', 'Date of Birth', 'Birth Date', 'Date of birth']);
        $birthDateRaw = $birthDateRaw ?? $romanized['date_of_birth_raw'] ?? null;
        // SSOT Day-27: Apply baseline normalization + patterns
        $dateOfBirth = \App\Services\Ocr\OcrNormalize::normalizeDate($birthDateRaw);
        if ($dateOfBirth) {
            $dateOfBirth = \App\Services\Ocr\OcrNormalize::applyBaselinePatterns('date_of_birth', $dateOfBirth);
        }
        // Fallback to existing normalizeDate if OcrNormalize returns original value
        if ($dateOfBirth === $birthDateRaw) {
            $dateOfBirth = $this->normalizeDate($birthDateRaw);
        }
        if ($dateOfBirth === null &&
    preg_match('/\b(\d{1,2}[\/\.\-@]\d{1,2}[\/\.\-@]\d{2,4})\b/u', $text, $dateMatch)) {
    $dateOfBirth = $this->normalizeDate(str_replace('@', '-', $dateMatch[1]));
}
        $confidence['date_of_birth'] = $dateOfBirth !== null ? self::CONF_DIRECT : self::CONF_MISSING;

        $heightRaw = $this->extractFieldAfterLabels($personalText, ['उंची', 'Height']);
        $heightRaw = $heightRaw ?? $this->extractFieldAfterLabels($text, ['उंची', 'Height']);
        // SSOT Day-27: Apply baseline normalization + patterns
        $heightNormalized = \App\Services\Ocr\OcrNormalize::normalizeHeight($heightRaw);
        if ($heightNormalized && $heightNormalized !== $heightRaw) {
            $heightNormalized = \App\Services\Ocr\OcrNormalize::applyBaselinePatterns('height', $heightNormalized);
        }
        // Convert normalized height string to cm for storage (existing logic)
        $heightCm = $this->normalizeHeight($heightNormalized ?? $heightRaw);
        if ($heightCm === null) {
            $heightCm = $this->extractHeightFromText($personalText) ?? $this->extractHeightFromText($text);
        }
        if ($heightCm === null && isset($romanized['height_cm'])) {
            $heightCm = $romanized['height_cm'];
        }
        $confidence['height'] = $heightCm !== null || $heightRaw !== null ? self::CONF_DIRECT : self::CONF_MISSING;

        $educationRaw = $this->extractAfterLabelMultiline($personalText, 'शिक्षण') ?? $this->extractAfterLabelMultiline($text, 'शिक्षण');
        $educationRaw = $educationRaw ?? $this->extractFieldAfterLabels($personalText, ['शिक्षण', 'Education']);
        $educationRaw = $educationRaw ?? $this->extractFieldAfterLabels($text, ['शिक्षण', 'Education']);
        $educationRaw = $educationRaw ?? $romanized['highest_education'] ?? null;
        $education = $this->validateEducation($educationRaw);
        if ($education === null && $educationRaw !== null && mb_strlen(trim($educationRaw)) >= 5) {
            $education = trim($educationRaw);
        }
        $confidence['highest_education'] = $education !== null ? self::CONF_DIRECT : self::CONF_MISSING;

        $fatherName = $this->extractFieldAfterLabels($familyText, ['वडिलांचे नाव', 'पिता', 'Father']);
        $fatherName = $fatherName ?? $this->extractFieldAfterLabels($text, ['वडिलांचे नाव', 'पिता', 'Father']);
        $fatherName = $fatherName ?? $this->extractAfterLabelNextLine($text, 'वडिलांचे नाव');
        $fatherName = $fatherName ?? (isset($romanized['father_name']) ? $this->validateFatherName($this->cleanRomanizedName($romanized['father_name'])) : null);
        $fatherName = $this->rejectIfLabelNoise($fatherName);
        if ($fatherName !== null) {
            $fatherName = trim(preg_replace('/\(.+\)/', '', $fatherName));
            $fatherName = $fatherName === '' ? null : $fatherName;
        }
        $fatherName = $this->validateFatherName($fatherName);
        $motherName = $this->extractFieldAfterLabels($familyText, ['आईचे नाव', 'माता', 'Mother']);
        $motherName = $motherName ?? $this->extractFieldAfterLabels($text, ['आईचे नाव', 'माता', 'Mother']);
        $motherName = $motherName ?? $this->extractAfterLabelNextLine($text, 'आईचे नाव');
        $motherName = $motherName ?? (isset($romanized['mother_name']) ? $this->validateMotherName($this->cleanRomanizedName($romanized['mother_name'])) : null);
        $motherName = $this->rejectIfLabelNoise($motherName);
        $motherName = $this->validateMotherName($motherName);
        $confidence['father_name'] = $fatherName !== null ? self::CONF_DIRECT : self::CONF_MISSING;
        $confidence['mother_name'] = $motherName !== null ? self::CONF_DIRECT : self::CONF_MISSING;

        $brotherCount = $this->extractCount($familyText, ['भाऊ', 'बंधू', 'Brother']) ?? $this->extractCount($text, ['भाऊ', 'बंधू', 'Brother']);
        $sisterCount = $this->extractCount($familyText, ['बहिण', 'बहीण', 'Sister']) ?? $this->extractCount($text, ['बहिण', 'बहीण', 'Sister']);
        $confidence['brother_count'] = $brotherCount !== null ? self::CONF_REGEX : self::CONF_MISSING;
        $confidence['sister_count'] = $sisterCount !== null ? self::CONF_REGEX : self::CONF_MISSING;

        // SSOT Day-30: extended biodata fields (additive); reject values that are known labels (wrong assignment)
        $birthTime = $this->rejectIfLabelNoise($this->extractField($personalText, ['जन्म वेळ', 'Birth time']) ?? $this->extractField($text, ['जन्म वेळ', 'Birth time']));
        $birthPlace = $this->rejectIfLabelNoise($this->extractField($personalText, ['जन्म स्थळ', 'Birth place']) ?? $this->extractField($text, ['जन्म स्थळ', 'Birth place']));
        $gotra = $this->rejectIfLabelNoise($this->extractField($personalText, ['गोत्र']) ?? $this->extractField($text, ['गोत्र']));
        $kuldaivat = $this->rejectIfLabelNoise($this->extractField($personalText, ['कुल दैवत', 'कुलदैवत']) ?? $this->extractField($text, ['कुल दैवत', 'कुलदैवत']));
        $rashi = $this->rejectIfLabelNoise($this->extractField($horoscopeText, ['रास', 'टाशी', 'राशी']) ?? $this->extractField($text, ['रास', 'टाशी', 'राशी']));
        $nadi = $this->rejectIfLabelNoise($this->extractField($horoscopeText, ['नाडी']) ?? $this->extractField($text, ['नाडी']));
        // Prefer label-style "गण :- value" so we don't capture text after "गण" in "गणपती" (मामा line)
        $gan = $this->rejectIfLabelNoise($this->extractFieldAfterLabels($horoscopeText, ['गण']) ?? $this->extractFieldAfterLabels($text, ['गण']) ?? $this->extractField($horoscopeText, ['गण']) ?? $this->extractField($text, ['गण']));
        $mangalik = $this->rejectIfLabelNoise($this->extractField($text, ['मांगलिक']));
        $varna = $this->extractField($personalText, ['वर्ण']) ?? $this->extractField($text, ['वर्ण']);
        $varna = $this->validateVarna($varna);
        $motherOccupation = $this->extractField($familyText, ['आईचा व्यवसाय', 'माता व्यवसाय']) ?? $this->extractField($text, ['आईचा व्यवसाय']);
        $fatherOccupation = $this->extractField($familyText, ['वडिलांचे व्यवसाय', 'पिता व्यवसाय']) ?? $this->extractField($text, ['वडिलांचे व्यवसाय']);
        $mama = $this->rejectIfLabelNoise($this->extractField($familyText, ['मामा']) ?? $this->extractField($text, ['मामा']));
        $relatives = $this->rejectIfLabelNoise($this->extractField($familyText, ['इतर पाहुणे', 'नातेसंबंध']) ?? $this->extractField($text, ['इतर पाहुणे', 'नातेसंबंध']));

        $gender = $this->inferGender($text);
        $gender = $gender ?? $romanized['gender'] ?? null;
        $religion = $this->extractField($text, ['धर्म', 'Religion']);
        $religion = $religion ?? $romanized['religion'] ?? null;
        if ($religion === null) {
            if (preg_match('/जात\s*[:\-]?\s*हिंदु/u', $text) || preg_match('/जात\s*[:\-]?\s*हिंदू/u', $text)) {
                $religion = 'हिंदू';
            } elseif (preg_match('/हिंदू\s*[-]?\s*मराठा|हिंदूमटाठा|हिंदु\s*[-]?\s*मराठा/u', $text)) {
                $religion = 'हिंदू';
            } elseif (preg_match('/जात\s*[:\-]?\s*मुस्लिम/u', $text)) {
                $religion = 'Muslim';
            } elseif (preg_match('/जात\s*[:\-]?\s*जैन/u', $text)) {
                $religion = 'Jain';
            }
        }
        $caste = $this->extractFieldAfterLabels($text, ['जात', 'Caste', 'Community']);
        $caste = $caste ?? $romanized['caste'] ?? null;
        if ($caste === null) {
            $caste = $this->extractCasteByDictionary($text);
        }
        $caste = $this->normalizeCasteValue($caste);
        if ($caste !== null && preg_match('/^हिंदू\s*[-]?\s*(.+)$/u', $caste, $m)) {
            $caste = trim($m[1]);
        }
        if ($caste !== null && preg_match('/^हिंदूमटाठा$/u', $caste)) {
            $caste = 'मराठा';
        }
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

        $salaryRaw = $this->extractField($text, ['मासिक वेतन', 'पगार', 'वेतन', 'वेतन/उत्पन्न', 'Package', 'वार्षिक उत्पन्न']);
        $salary = $this->normalizeSalary($salaryRaw);
        $annualIncome = null;
        if (isset($salary['annual_lakh_float']) && $salary['annual_lakh_float'] > 0) {
            $annualIncome = (int) round($salary['annual_lakh_float'] * 100000);
        } elseif (! empty($salary['annual_lakh'])) {
            $annualIncome = (int) $salary['annual_lakh'] * 100000;
        } elseif (! empty($salary['annual_raw'])) {
            $annualIncome = (int) $salary['annual_raw'];
        } elseif (! empty($salary['monthly'])) {
            $annualIncome = (int) $salary['monthly'] * 12;
        } elseif (preg_match('/[\d,]+/', (string) $salaryRaw, $m)) {
            $annualIncome = (int) str_replace(',', '', $m[0]);
        }
        $confidence['annual_income'] = $annualIncome !== null ? self::CONF_DIRECT : self::CONF_MISSING;

        $primaryContactRaw = $this->extractPrimaryContactNumber($contactText) ?? $this->extractPrimaryContactNumber($text);
        // SSOT Day-27: Apply baseline normalization + patterns
        $primaryContact = \App\Services\Ocr\OcrNormalize::normalizePhone($primaryContactRaw);
        if ($primaryContact && $primaryContact !== $primaryContactRaw) {
            $primaryContact = \App\Services\Ocr\OcrNormalize::applyBaselinePatterns('primary_contact_number', $primaryContact);
        }
        $confidence['primary_contact_number'] = $primaryContact !== null ? self::CONF_DIRECT : self::CONF_MISSING;

        $bloodGroupRaw = $this->extractField($text, ['रक्तगट', 'Blood group']);
        // SSOT Day-27: Apply baseline normalization + patterns
        $bloodGroup = \App\Services\Ocr\OcrNormalize::normalizeBloodGroup($bloodGroupRaw);
        if ($bloodGroup) {
            $bloodGroup = \App\Services\Ocr\OcrNormalize::applyBaselinePatterns('blood_group', $bloodGroup);
        }
        // Fallback to existing normalizeBloodGroup if OcrNormalize returns original value
        if ($bloodGroup === $bloodGroupRaw) {
            $bloodGroup = $this->normalizeBloodGroup($bloodGroupRaw);
        }
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
            'birth_time' => $birthTime,
            'birth_place' => $birthPlace,
            'gotra' => $gotra,
            'kuldaivat' => $kuldaivat,
            'rashi' => $rashi,
            'nadi' => $nadi,
            'gan' => $gan,
            'mangalik' => $mangalik,
            'varna' => $varna,
            'mother_occupation' => $motherOccupation,
            'father_occupation' => $fatherOccupation,
            'mama' => $mama,
            'relatives' => $relatives,
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
        $profession = $this->extractField($text, ['नोकरी/व्यवसाय', 'नोकटी/व्यवसाय', 'नोकरी', 'व्यवसाय', 'Profession', 'Occupation']);
        $profession = $profession ?? $romanized['career'] ?? null;
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

        // ——— CONTACTS (primary first, then all 10-digit numbers from text) ———
        $contacts = [];
        if ($primaryContact !== null) {
            $contacts[] = ['number' => $primaryContact, 'type' => 'primary'];
        }
        if (preg_match_all('/\b([6-9]\d{9})\b/', $text, $allMatches)) {
            $seen = array_fill_keys(array_column($contacts, 'number'), true);
            foreach (array_unique($allMatches[1]) as $num) {
                $normalized = \App\Services\Ocr\OcrNormalize::normalizePhone($num);
                if ($normalized !== null && ! isset($seen[$normalized])) {
                    $seen[$normalized] = true;
                    $contacts[] = ['number' => $normalized, 'type' => count($contacts) === 0 ? 'primary' : 'alternate'];
                }
            }
            if ($primaryContact !== null && ! empty($contacts)) {
                foreach ($contacts as $i => $c) {
                    $contacts[$i]['type'] = $c['number'] === $primaryContact ? 'primary' : 'alternate';
                }
            }
        }

        // ——— OPTIONAL: Property ———
        $propertySummary = null;
        $propertyAssets = [];
        if ($this->hasAnyKeyword($text, self::PROPERTY_TRIGGER)) {
            $propertySummary = $this->extractPropertySummaryBlock($text);
            $propertySummary = $propertySummary ?? $this->extractField($text, ['स्थायिक मालमत्ता', 'शेती', 'जमीन', 'Property']);
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
                if ($preferences === null || (is_array($preferences) && empty($preferences))) {
                    $prefRaw = $this->extractField($text, ['अपेक्षा']) ?? $prefBlock;
                    if ($prefRaw !== null && trim($prefRaw) !== '') {
                        $preferences = is_array($prefRaw) ? $prefRaw : [['text' => trim($prefRaw)]];
                        if (isset($preferences[0]) && ! is_array($preferences[0])) {
                            $preferences = [['text' => (string) $preferences[0]]];
                        }
                    }
                }
                $confidence['preferences'] = $preferences !== null && ! (is_array($preferences) && empty($preferences)) ? self::CONF_REGEX : self::CONF_MISSING;
            } else {
                $confidence['preferences'] = self::CONF_MISSING;
            }
        } else {
            $confidence['preferences'] = self::CONF_MISSING;
        }

        // ——— AI FALLBACK for low-confidence or ambiguous ———
$coreKeys = [
    'sub_caste',
    'marital_status',
    'annual_income',
    'family_income',
    'primary_contact_number',
    'serious_intent_id',
    'father_name',
    'mother_name',
    'brother_count',
    'sister_count',
    'height_cm',
    'highest_education'
];
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
            // Skip decorative headers/footers; do NOT skip lines where शुभ is part of a name (e.g. शुभम)
            if (mb_strpos($line, 'प्रसन्न') !== false || mb_strpos($line, 'भवतु') !== false) {
                continue;
            }
            if (preg_match('/शुभ\s*भवतु/u', $line)) {
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
            if (mb_strpos($line, 'वडील') !== false || mb_strpos($line, 'आई') !== false || mb_strpos($line, 'भाऊ') !== false || mb_strpos($line, 'बहिण') !== false || mb_strpos($line, 'बहीण') !== false) {
                $sections['FAMILY'][] = $line;
            } elseif (mb_strpos($line, 'रास') !== false || mb_strpos($line, 'राशी') !== false || mb_strpos($line, 'टाशी') !== false || mb_strpos($line, 'नक्षत्र') !== false || mb_strpos($line, 'नाडी') !== false || mb_strpos($line, 'गण') !== false || mb_strpos($line, 'कुलस्वामी') !== false || mb_strpos($line, 'मांगलिक') !== false) {
                $sections['HOROSCOPE'][] = $line;
            } elseif (mb_strpos($line, 'मु.पो.') !== false || mb_strpos($line, 'ता.') !== false || mb_strpos($line, 'जि.') !== false || mb_strpos($line, 'संपर्क') !== false) {
                $sections['CONTACT'][] = $line;
            } elseif (mb_strpos($line, 'जन्म') !== false || mb_strpos($line, 'उंची') !== false || mb_strpos($line, 'शिक्षण') !== false || mb_strpos($line, 'गोत्र') !== false || mb_strpos($line, 'कुलदैवत') !== false || mb_strpos($line, 'वर्ण') !== false) {
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
            } elseif (mb_strpos($line, 'रास') !== false || mb_strpos($line, 'राशी') !== false || mb_strpos($line, 'नक्षत्र') !== false || mb_strpos($line, 'नाडी') !== false) {
                $grouped['HOROSCOPE'][] = $line;
            }
        }
        return $grouped;
    }

    /** full_name: 2–4 Marathi words; reject प्रसन्न, कुळी, नावरस नाव, >5 words, punctuation-heavy. */
    private function validateFullName(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = trim($value);
        if ($value === '' || $value === 'नावरस नाव' || $value === 'नांवटस नाव') {
            return null;
        }
        $words = preg_split('/\s+/u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if (count($words) < 2) {
            return null;
        }
        if (count($words) > 5) {
            return null;
        }
        if (preg_match('/^कु+\.|^चि\./u', $value) && count($words) >= 2) {
            if (mb_strpos($value, 'प्रसन्न') === false && mb_strpos($value, 'जन्म') === false && mb_strpos($value, 'उंची') === false) {
                return $value;
            }
        }
        if (mb_strpos($value, 'प्रसन्न') !== false) {
            return null;
        }
        if (mb_strpos($value, 'कुळी') !== false) {
            if (preg_match('/^कु+\.|^चि\./u', $value)) {
                return $value;
            }
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
        if (count($words) > 5) {
            return null;
        }
        $punctOnly = preg_replace('/[\p{L}\p{N}\s]/u', '', $value);
        if ($punctOnly !== '' && mb_strlen($punctOnly) >= 3) {
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
        // OCR variant: मटाठा -> मराठा
        if (mb_strpos($v, 'मटाठा') !== false) {
            $v = str_replace('मटाठा', 'मराठा', $v);
        }
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
     * When label is "नाव", reject match if it is part of "नावरस नाव" or "नांवटस नाव" (nakshatra label).
     */
    private function extractAfterLabel(string $text, string $label): ?string
    {
        $pattern = '/' . preg_quote($label, '/') . '\s*[:\-]{0,5}\s*([^\n]+)/u';
        if (preg_match($pattern, $text, $match) && isset($match[1])) {
            $value = trim($match[1]);
            if ($value === '' || $value === trim($label)) {
                return null;
            }
            if (($label === 'नाव' || $label === 'मुलाचे नाव' || $label === 'मुलीचे नाव') && (mb_strpos($match[0], 'नावरस') !== false || mb_strpos($match[0], 'नांवटस') !== false)) {
                return null;
            }
            $cleaned = $this->cleanValue($value);
            return $cleaned === '' ? null : $cleaned;
        }
        return null;
    }

    /** Extract value when it appears on the next line after label (e.g. "मुलीचे नाव\nकु. प्राजक्ता..."). */
    private function extractAfterLabelNextLine(string $text, string $label): ?string
    {
        $pattern = '/' . preg_quote($label, '/') . '\s*[:\-]?\s*\n\s*[:\-]?\s*([^\n]+)/u';
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

    /** Extract value after label, allowing value to span until next double newline OR next label line (for शिक्षण). */
    private function extractAfterLabelMultiline(string $text, string $label): ?string
    {
        $pattern = '/' . preg_quote($label, '/') . '\s*[:\->\s]{0,5}\s*(.+?)(?=\n\s*\n|\n\s*(?:वर्ण|रास\s|कुलदैवत|उंची|गोत्र|नाडी|गण\s*[:\-]|जात\s|नोकरी|वेतन|पत्ता|मुलाचे|मुलीचे|जन्म\s|रक्त|कौटुंबिक|वडिलांचे|आईचे|चुलते|भाऊ|बहिण|बहीण|मामा|नातेसंबंध|संपर्क|अपेक्षा)|$)/us';
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
        $value = preg_replace('/^[\-\sx>:\.,_]+/u', '', $value);
        $value = preg_replace('/\s*x\s*$/u', '', $value);
        foreach (self::OCR_BLACKLIST_WORDS as $word) {
            $value = preg_replace('/\b' . preg_quote($word, '/') . '\b/iu', ' ', $value);
        }
        $value = preg_replace('/\s+/u', ' ', trim($value));
        if (mb_strlen($value) < 3) {
            // Allow short degree codes (BA, B.A, M.Sc, etc.)
            if (preg_match('/^[A-Za-z\.]+$/u', $value) && mb_strlen($value) >= 2) {
                return $value;
            }
            return '';
        }
        return $value;
    }

    /**
     * Extract full name from a line that STARTS with नाव / मुलाचे नाव / मुलीचे नाव.
     * Avoids matching नांवटस नाव or नावरस नाव (nakshatra name) which appears later in the doc.
     * Old rules kept: still falls back to extractFieldAfterLabels if this returns null.
     */
    private function extractFullNameFromLineStart(string $text): ?string
    {
        $lines = explode("\n", $text);
        // 1) First line that starts with "नाव" (not नांवटस/नावरस) and has ": value" — use regex for robustness.
        //    Allow optional leading digits + space (e.g. "4 नाव :- ...") so OCR line numbers don't break extraction.
        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '' || mb_strpos($t, 'नांवटस') !== false || mb_strpos($t, 'नावरस') !== false) {
                continue;
            }
            if (preg_match('/^\s*नाव\s*[:\-：]\s*(.+)$/u', $t, $mm)) {
                $val = $this->cleanValue(trim($mm[1]));
                if ($val !== '') {
                    $valid = $this->validateFullName($val);
                    if ($valid !== null) {
                        return $valid;
                    }
                }
            } elseif (preg_match('/^\s*[0-9]+\s*नाव\s*[:\-：]\s*(.+)$/u', $t, $mm)) {
                $val = $this->cleanValue(trim($mm[1]));
                if ($val !== '') {
                    $valid = $this->validateFullName($val);
                    if ($valid !== null) {
                        return $valid;
                    }
                }
            }
        }
        // 2) Same for मुलाचे नाव / मुलीचे नाव (old rule)
        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '') {
                continue;
            }
            if (mb_strpos($t, 'नावरस') !== false || mb_strpos($t, 'नांवटस') !== false) {
                continue;
            }
            foreach (['मुलाचे नाव', 'मुलीचे नाव'] as $label) {
                $len = mb_strlen($label);
                if (mb_strlen($t) >= $len + 2 && mb_substr($t, 0, $len) === $label) {
                    $after = mb_substr($t, $len);
                    if (preg_match('/^\s*[:\-]\s*(.+)$/u', $after, $mm)) {
                        $val = $this->cleanValue(trim($mm[1]));
                        if ($val !== '') {
                            $valid = $this->validateFullName($val);
                            if ($valid !== null) {
                                return $valid;
                            }
                        }
                    }
                }
            }
        }
        // 3) Regex fallback (old rule)
        $lineStartPatterns = [
            '/^(?:[^\n]*\n)*\s*मुलाचे\s+नाव\s*[:\-]\s*([^\n]+)/u',
            '/^(?:[^\n]*\n)*\s*मुलीचे\s+नाव\s*[:\-]\s*([^\n]+)/u',
            '/^(?:[^\n]*\n)*\s*नाव\s*[:\-]\s*([^\n]+)/u',
        ];
        foreach ($lineStartPatterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $candidate = trim($m[1]);
                if (mb_strpos($candidate, 'नावरस') !== false || mb_strpos($candidate, 'नांवटस') !== false) {
                    continue;
                }
                $value = $this->cleanValue($candidate);
                if ($value !== '' && $this->validateFullName($value) !== null) {
                    return $this->validateFullName($value);
                }
            }
        }
        // 4) Line-by-line regex (old rule)
        $nameLabels = ['मुलाचे नाव', 'मुलीचे नाव', 'नाव'];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || mb_strpos($line, 'नावरस') !== false || mb_strpos($line, 'नांवटस') !== false) {
                continue;
            }
            foreach ($nameLabels as $label) {
                $pattern = '/^\s*' . preg_quote($label, '/') . '\s*[:\-]{0,5}\s*(.+)$/u';
                if (preg_match($pattern, $line, $m)) {
                    $value = $this->cleanValue(trim($m[1]));
                    if ($value !== '' && $this->validateFullName($value) !== null) {
                        return $this->validateFullName($value);
                    }
                }
            }
        }
        return null;
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
        $contactStr = $this->extractFieldAfterLabels($text, ['संपर्क क्रमांक', 'मोबाईल नं', 'मोबाईल नंबर', 'Contact', 'Mobile']);
        if ($contactStr !== null) {
            $normalized = \App\Services\Ocr\OcrNormalize::normalizePhone(preg_replace('/\s+/', '', $contactStr));
            if ($normalized !== null) {
                return $normalized;
            }
            if (preg_match('/\b([6-9]\d{9})\b/', $contactStr, $m)) {
                return $m[1];
            }
        }
        if (preg_match('/\b([6-9]\d{9})\b/', $text, $m)) {
            return $m[1];
        }
        // 5+5 digits with space/slash (e.g. 73509 53384, 96733 50078)
        if (preg_match('/\b([6-9]\d{4})\s*\/?\s*(\d{5})\b/', $text, $m)) {
            $combined = $m[1] . $m[2];
            if (preg_match('/^[6-9]\d{9}$/', $combined)) {
                return $combined;
            }
        }
        return null;
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

    /** Reject varna if it looks like another field (contains नोकरी, phone, or too long). */
    private function validateVarna(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $v = trim($value);
        if (mb_strlen($v) > 40 || mb_strpos($v, 'नोकरी') !== false || preg_match('/\d{10}/', $v)) {
            return null;
        }
        return $v;
    }

    /** Known labels that must not be captured as field values (wrong assignment). */
    private const LABEL_NOISE = [
        'जन्म वेळ', 'जन्म स्थळ', 'वर्ण', 'शिक्षण', 'शिक्षिण', 'आईचे नाव', 'वडिलांचे नाव',
        'नावरस नाव', 'नांवटस नाव', 'जात', 'धर्म', 'उंची', 'गोत्र', 'कुलदैवत', 'नाडी', 'गण',
        'सध्याचा पत्ता', 'मोबाईल नं', 'कौटुंबिक माहिती', 'संपर्क',
    ];

    private function rejectIfLabelNoise(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $v = trim($value);
        foreach (self::LABEL_NOISE as $label) {
            if ($v === $label || $v === trim($label)) {
                return null;
            }
        }
        return $v;
    }

    private function extractCount(string $text, array $labels): ?int
    {
        foreach ($labels as $label) {
            if (preg_match('/' . preg_quote($label, '/') . '\s*[:\-]?\s*(\d+)/u', $text, $m)) {
                return (int) $m[1];
            }
            // एक / १ → 1 (e.g. "भाऊ :- एक अविवाहित")
            if (preg_match('/' . preg_quote($label, '/') . '\s*[:\s_\-]*(एक|१)\b/um', $text, $m)) {
                return 1;
            }
            // नाही / None / No → 0 (allow optional : _ - and spaces; don't require end-of-line)
            if (preg_match('/' . preg_quote($label, '/') . '\s*[:\s_\-]*(नाही|None|No\b|०|0)\b/um', $text, $m)) {
                return 0;
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
        $value = str_replace(['/', '@'], '-', $value);
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
        // फूट or फुट, optional space after digit (५फुट ६इंच)
        if (preg_match('/(\d+)\s*फ[ुू]ट\s*(\d+)/u', $value, $m)) {
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
        if (preg_match('/(\d{1,2})\s*फ[ुू]ट\s*(\d{1,2})/u', $text, $m)) {
            $totalInches = (int) $m[1] * 12 + (int) $m[2];
            return round($totalInches * 2.54, 2);
        }
        if (preg_match("/(\d{1,2})'\s*(\d{1,2})/", $text, $m)) {
            $totalInches = (int) $m[1] * 12 + (int) $m[2];
            return round($totalInches * 2.54, 2);
        }
        // Romanized/garbled: "5 QqV 8 bap" (feet/inch)
        if (preg_match('/(\d{1,2})\s*QqV\s*(\d{1,2})\s*bap/i', $text, $m)) {
            $totalInches = (int) $m[1] * 12 + (int) $m[2];
            return round($totalInches * 2.54, 2);
        }
        if (preg_match('/(\d{1,2})\s+\S+\s+(\d{1,2})\s+(?:bap|inch)/i', $text, $m)) {
            $totalInches = (int) $m[1] * 12 + (int) $m[2];
            return round($totalInches * 2.54, 2);
        }
        return null;
    }

    /**
     * Extract from romanized/garbled OCR (e.g. PDF font exports Devanagari as Latin: eqykps ukao = मुलीचे नाव).
     * Returns array with keys: full_name, date_of_birth_raw, gender, height_cm, father_name, mother_name, religion, caste, highest_education, career.
     */
    private function extractFromRomanizedLabels(string $text): array
    {
        $out = [];
        // Name: eqykps ukao %& ... (girl) or eqykaps ukao %& ... (boy)
        if (preg_match('/(?:eqykps|eqykaps)\s+ukao\s*%&\s*([^\n\r]+)/u', $text, $m)) {
            $out['full_name'] = trim($m[1]);
            $out['gender'] = strpos($m[0], 'eqykps') !== false ? 'female' : 'male';
        }
        // DOB: tUefnukad %& 11@04@1998
        if (preg_match('/tUefnukad\s*%&\s*([^\n\r]+)/u', $text, $m)) {
            $out['date_of_birth_raw'] = trim($m[1]);
        }
        // Height: maph %& 5 QqV 8 bap
        if (preg_match('/maph\s*%&\s*(\d{1,2})\s*QqV\s*(\d{1,2})\s*bap/i', $text, $m)) {
            $totalInches = (int) $m[1] * 12 + (int) $m[2];
            $out['height_cm'] = round($totalInches * 2.54, 2);
        }
        // Caste/Religion: tkr %& fganq&ejkBk (Hindu&Maratha)
        if (preg_match('/tkr\s*%&\s*([^\n\r]+)/u', $text, $m)) {
            $val = trim($m[1]);
            $parts = preg_split('/[&\-\/]/u', $val, 2);
            $part0 = isset($parts[0]) ? trim($parts[0]) : '';
            $part1 = isset($parts[1]) ? trim($parts[1]) : '';
            $romReligion = ['fganq' => 'Hindu', 'eq[kk' => 'Muslim', 'tkfj' => 'Jain', 'Hindu' => 'Hindu', 'Muslim' => 'Muslim', 'Jain' => 'Jain'];
            $romCaste = ['ejkBk' => 'Maratha', 'Maratha' => 'Maratha', 'xzkg' => 'Brahmin', 'Brahmin' => 'Brahmin'];
            foreach ($romReligion as $key => $rel) {
                if (stripos($part0, $key) !== false || stripos($val, $key) !== false) {
                    $out['religion'] = $rel;
                    break;
                }
            }
            if ($part0 !== '' && ! isset($out['religion'])) {
                $out['religion'] = $part0;
            }
            foreach ($romCaste as $key => $cst) {
                if (stripos($part1, $key) !== false || stripos($val, $key) !== false) {
                    $out['caste'] = $cst;
                    break;
                }
            }
            if ($part1 !== '' && ! isset($out['caste'])) {
                $out['caste'] = $part1;
            }
        }
        // Father: ofMykaps ukao %&
        if (preg_match('/ofMykaps\s+ukao\s*%&\s*([^\n\r]+)/u', $text, $m)) {
            $out['father_name'] = trim($m[1]);
        }
        // Mother: vkbZps ukao %&
        if (preg_match('/vkbZps\s+ukao\s*%&\s*([^\n\r]+)/u', $text, $m)) {
            $out['mother_name'] = trim($m[1]);
        }
        // Education: f'k{k.k %& B.Com (allow font variants of the middle chars)
        if (preg_match("/f'k\{k\.k\s*%&\s*([^\n\r]+)/u", $text, $m)) {
            $out['highest_education'] = trim($m[1]);
        } elseif (preg_match("/f'k.?k.?k\s*%&\s*([^\n\r]+)/u", $text, $m)) {
            $out['highest_education'] = trim($m[1]);
        }
        // Career: O;olk; %&
        if (preg_match('/O;olk;\s*%&\s*([^\n\r]+)/u', $text, $m)) {
            $out['career'] = trim($m[1]);
        }
        return $out;
    }

    /** Clean name from romanized OCR (remove leading fp-/Jh- type prefixes, normalize spaces). */
    private function cleanRomanizedName(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/^[fp\-Jh\-lkS\-]+/u', '', $value);
        $value = preg_replace('/\s+/u', ' ', trim($value));
        return $value;
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
        // 3.25 Lac / 3.25 Lac. / 2 LAC / 3.25 Lakh / 3.25 लाख -> annual in lakh (float); allow newline between number and LAC
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:\n\s*)?(?:Lac\.?|LAC|Lakh|लाख)/ui', $value, $m)) {
            return ['annual_lakh_float' => (float) $m[1]];
        }
        if (preg_match('/(\d+)\s*लाख/u', $value, $m)) {
            return ['annual_lakh' => (int) $m[1]];
        }
        if (preg_match('/वार्षिक\s*उत्पन्न\s*(\d+(?:\.\d+)?)\s*LAC/ui', $value, $m)) {
            return ['annual_lakh_float' => (float) $m[1]];
        }
        if (preg_match('/(\d+)[,\s]*वार्षिक/u', $value, $m)) {
            return ['annual_raw' => (int) $m[1]];
        }
        // Only treat as monthly if no "Lac/Lakh/lakh/per year" etc. Strip commas so "35,000" -> 35000.
        $valueNoComma = str_replace(',', '', $value);
        if (!preg_match('/Lac|Lakh|लाख|per\s*year|वार्षिक/ui', $value) && preg_match('/(\d+)/', $valueNoComma, $m)) {
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
        if (preg_match('/मुलीचे\s*नाव/u', $text)) {
            return 'female';
        }

        if (preg_match('/मुलाचे\s*नाव/u', $text)) {
            return 'male';
        }

        // मुलाचे बालपण / मुलाचे चुलते etc. -> boy's biodata
        if (preg_match('/मुलाचे\s*(बालपण|चुलते|मामा|भाऊ)/u', $text)) {
            return 'male';
        }
        if (preg_match('/मुलीचे\s*(बालपण|चुलते|मामा|भाऊ)/u', $text)) {
            return 'female';
        }

        if (preg_match('/\bकु\./u', $text)) {
            return 'female';
        }

        if (preg_match('/\bचि\./u', $text)) {
            return 'male';
        }

        // English label: Gender: Female / Sex: Male
        if (preg_match('/(?:Gender|Sex)\s*[:\-]*\s*(\w+)/ui', $text, $m)) {
            $g = trim($m[1] ?? '');
            if (stripos($g, 'female') !== false || $g === 'F') {
                return 'female';
            }
            if (stripos($g, 'male') !== false || $g === 'M') {
                return 'male';
            }
        }

        // Standalone English / Marathi words (case-insensitive for English)
        if (preg_match('/\bfemale\b/ui', $text)) {
            return 'female';
        }
        if (preg_match('/\bmale\b/ui', $text)) {
            return 'male';
        }
        if (preg_match('/\bस्त्री\b/u', $text)) {
            return 'female';
        }
        if (preg_match('/\bपुरुष\b/u', $text)) {
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
        // संपर्क पत्ता / संपर्क seal (OCR) — require पत्ता|seal so we don't capture "seal" as address; capture until मोबाईल so multiline address included
        if (preg_match('/(?:संपर्क\s*(?:पत्ता|seal)\s*[:\-]?\s*|oa\s*[:\-]?\s*)(.+?)(?=मोबाईल|\d{10}|$)/us', $text, $m)) {
            $addr = trim(preg_replace('/\s+/', ' ', $m[1]));
            if ($addr !== '' && $addr !== 'seal') {
                return $addr;
            }
        }
        // Lines containing address markers (वॉर्ड, गल्ली, पेठ, बाजार) — join consecutive such lines
        $lines = explode("\n", $text);
        $collect = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/वॉर्ड|गल्ली|पेठ|बाजार|बाजाटगेट|हाऊस|House|Ward|nagar/u', $line)) {
                $collect[] = $line;
            }
        }
        if (! empty($collect)) {
            return trim(implode(', ', $collect));
        }
        return null;
    }

    /** Extract multi-line block after "स्थायिक मालमत्ता" (or similar) until next section. */
    private function extractPropertySummaryBlock(string $text): ?string
    {
        if (preg_match('/स्थायिक\s*मालमत्ता\s*[:\-]?\s*(.+?)(?=\n\s*\n|\nकौटुंबिक|\nअपेक्षा|\nसंपर्क|$)/us', $text, $m)) {
            return trim(preg_replace('/\s+/', ' ', $m[1]));
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

    // चुलते
    if (preg_match('/चुलते\s*[:\-]?\s*(.+)/u', $text, $m)) {
        $data['chulate'][] = trim($m[1]);
        $confidence['chulate'] = self::CONF_DIRECT;
    } else {
        $confidence['chulate'] = self::CONF_MISSING;
    }

    // मामा
    if (preg_match('/मामा\s*[:\-]?\s*(.+)/u', $text, $m)) {
        $data['mama'][] = trim($m[1]);
        $confidence['mama'] = self::CONF_DIRECT;
    } else {
        $confidence['mama'] = self::CONF_MISSING;
    }

    // मावशी
    if (preg_match('/मावशी\s*[:\-]?\s*(.+)/u', $text, $m)) {
        $data['mavshi'][] = trim($m[1]);
        $confidence['mavshi'] = self::CONF_DIRECT;
    } else {
        $confidence['mavshi'] = self::CONF_MISSING;
    }

    // काका (direct only — no heuristic)
    if (preg_match('/काका\s*[:\-]?\s*(.+)/u', $text, $m)) {
        $data['kaka'][] = trim($m[1]);
        $confidence['kaka'] = self::CONF_DIRECT;
    } else {
        $confidence['kaka'] = self::CONF_MISSING;
    }

    $confidence['mavshicha_navra'] = self::CONF_MISSING;

    return [
        'data' => $data,
        'confidence' => array_map(fn($v) => (float)$v, $confidence),
    ];
}
}
