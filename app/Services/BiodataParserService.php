<?php

namespace App\Services;

use App\Models\AdminSetting;

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
        // Strip BOM if present; rest of the flow expects UTF-8 text and relies on upstream normalization.
        $rawText = preg_replace('/^\x{FEFF}/u', '', $rawText);
        // SSOT Day-27: Apply baseline normalization (Devanagari digits + noise removal)
        $text = \App\Services\Ocr\OcrNormalize::normalizeRawText($rawText);
        $text = $this->normalizeText($text);
        $text = $this->removeWatermarkNoise($text);
        $text = $this->sanitizeDocument($text);
        $text = (\function_exists('normalizer_normalize') && normalizer_normalize($text, \Normalizer::FORM_C) !== false)
            ? normalizer_normalize($text, \Normalizer::FORM_C) : $text;
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

        $heightRaw = $this->extractFieldAfterLabels($personalText, ['उंची', 'ऊंची', 'Height']);
        $heightRaw = $heightRaw ?? $this->extractFieldAfterLabels($text, ['उंची', 'ऊंची', 'Height']);
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

        // Parent names: prefer FAMILY section first, then full text; UTF-8 Marathi labels only.
        $fatherLabels = ['वडिलांचे नाव', 'वडिलांचे नांव', 'वडीलांचे नाव', 'पित्याचे नाव', 'पिता', 'Father'];
        $motherLabels = ['आईचे नाव', 'आईचं नाव', 'मातेचे नाव', 'माता', 'Mother'];

        $fatherName = $this->extractFieldAfterLabels($familyText, $fatherLabels);
        $fatherName = $fatherName ?? $this->extractFieldAfterLabels($text, $fatherLabels);
        $fatherName = $fatherName ?? $this->extractAfterLabelNextLine($text, 'वडिलांचे नाव');
        $fatherName = $fatherName ?? $this->extractAfterLabelNextLine($text, 'वडिलांचे नांव');
        $fatherName = $fatherName ?? $this->extractFatherNameFromKaiOrDoctor($text);
        $fatherName = $fatherName ?? $this->extractField($familyText, $fatherLabels);
        $fatherName = $fatherName ?? (isset($romanized['father_name']) ? $this->validateFatherName($this->cleanRomanizedName($romanized['father_name'])) : null);
        $fatherName = $this->rejectIfLabelNoise($fatherName);
        if ($fatherName !== null) {
            $fatherName = trim(preg_replace('/\(.+\)/', '', $fatherName));
            $fatherName = $fatherName === '' ? null : $fatherName;
        }
        $fatherName = $this->validateFatherName($fatherName);

        $motherName = $this->extractFieldAfterLabels($familyText, $motherLabels);
        $motherName = $motherName ?? $this->extractFieldAfterLabels($text, $motherLabels);
        $motherName = $motherName ?? $this->extractAfterLabelNextLine($text, 'आईचे नाव');
        $motherName = $motherName ?? $this->extractField($familyText, $motherLabels);
        $motherName = $motherName ?? (isset($romanized['mother_name']) ? $this->validateMotherName($this->cleanRomanizedName($romanized['mother_name'])) : null);
        $motherName = $this->rejectIfLabelNoise($motherName);
        $motherName = $this->validateMotherName($motherName);
        $confidence['father_name'] = $fatherName !== null ? self::CONF_DIRECT : self::CONF_MISSING;
        $confidence['mother_name'] = $motherName !== null ? self::CONF_DIRECT : self::CONF_MISSING;

        $brotherCount = $this->extractCount($familyText, ['भाऊ', 'बंधू', 'Brother']) ?? $this->extractCount($text, ['भाऊ', 'बंधू', 'Brother']);
        $sisterCount = $this->extractCount($familyText, ['बहिण', 'बहीण', 'Sister']) ?? $this->extractCount($text, ['बहिण', 'बहीण', 'Sister']);
        $confidence['brother_count'] = $brotherCount !== null ? self::CONF_REGEX : self::CONF_MISSING;
        $confidence['sister_count'] = $sisterCount !== null ? self::CONF_REGEX : self::CONF_MISSING;

        // Focused Marathi family-core override pass: infer missing father/mother + counts from free-text context.
        $familyCoreOverride = $this->extractFamilyCore($text);
        if (($familyCoreOverride['father_name'] ?? null) !== null && $fatherName === null) {
            $fatherName = $familyCoreOverride['father_name'];
            $confidence['father_name'] = self::CONF_REGEX;
        }
        if (($familyCoreOverride['mother_name'] ?? null) !== null && $motherName === null) {
            $motherName = $familyCoreOverride['mother_name'];
            $confidence['mother_name'] = self::CONF_REGEX;
        }
        if (($familyCoreOverride['father_occupation'] ?? null) !== null) {
            $fatherOccupation = $fatherOccupation ?? $familyCoreOverride['father_occupation'];
        }
        if (($familyCoreOverride['mother_occupation'] ?? null) !== null) {
            $motherOccupation = $motherOccupation ?? $familyCoreOverride['mother_occupation'];
        }
        if (($familyCoreOverride['brothers_count'] ?? null) !== null && $brotherCount === null) {
            $brotherCount = $familyCoreOverride['brothers_count'];
            $confidence['brother_count'] = self::CONF_REGEX;
        }
        if (($familyCoreOverride['sisters_count'] ?? null) !== null && $sisterCount === null) {
            $sisterCount = $familyCoreOverride['sisters_count'];
            $confidence['sister_count'] = self::CONF_REGEX;
        }

        // SSOT Day-30: extended biodata fields (additive); reject values that are known labels (wrong assignment)
        $birthTimeRaw = $this->rejectIfLabelNoise($this->extractField($personalText, ['जन्म वेळ', 'Birth time']) ?? $this->extractField($text, ['जन्म वेळ', 'Birth time']));
        $birthTime = $this->normalizeBirthTime($birthTimeRaw);
        $birthPlace = $this->rejectIfLabelNoise($this->extractField($personalText, ['जन्म स्थळ', 'Birth place']) ?? $this->extractField($text, ['जन्म स्थळ', 'Birth place']));
        $gotra = $this->rejectIfLabelNoise(
            $this->extractFieldAfterLabels($personalText, ['गोत्र']) ?? $this->extractField($personalText, ['गोत्र']) ??
            $this->extractFieldAfterLabels($text, ['गोत्र']) ?? $this->extractField($text, ['गोत्र'])
        );
        $kuldaivat = $this->rejectIfLabelNoise(
            $this->extractFieldAfterLabels($personalText, ['कुलस्वामी', 'कुल दैवत', 'कुलदैवत']) ?? $this->extractField($personalText, ['कुल दैवत', 'कुलदैवत']) ??
            $this->extractFieldAfterLabels($text, ['कुलस्वामी', 'कुल दैवत', 'कुलदैवत']) ?? $this->extractField($text, ['कुल दैवत', 'कुलदैवत'])
        );
        $rashi = $this->rejectIfLabelNoise($this->extractFieldAfterLabels($horoscopeText, ['रास', 'राशी']) ?? $this->extractField($horoscopeText, ['रास', 'टाशी', 'राशी']) ?? $this->extractField($text, ['रास', 'टाशी', 'राशी']));
        $nakshatra = $this->rejectIfLabelNoise($this->extractFieldAfterLabels($horoscopeText, ['नक्षत्र']) ?? $this->extractFieldAfterLabels($text, ['नक्षत्र']) ?? $this->extractField($horoscopeText, ['नक्षत्र']) ?? $this->extractField($text, ['नक्षत्र']));
        $nadiRaw = $this->rejectIfLabelNoise($this->extractField($horoscopeText, ['नाडी', 'नाड २', 'नाड']) ?? $this->extractField($text, ['नाडी', 'नाड २', 'नाड']));
        $nadi = $nadiRaw !== null ? $this->normalizeNadiValue($nadiRaw) : null;
        $yoni = $this->rejectIfLabelNoise($this->extractFieldAfterLabels($horoscopeText, ['योनी']) ?? $this->extractFieldAfterLabels($text, ['योनी']) ?? $this->extractField($horoscopeText, ['योनी']) ?? $this->extractField($text, ['योनी']));
        // Prefer label-style "गण :- value" so we don't capture text after "गण" in "गणपती" (मामा line)
        $gan = $this->rejectIfLabelNoise($this->extractFieldAfterLabels($horoscopeText, ['गण']) ?? $this->extractFieldAfterLabels($text, ['गण']) ?? $this->extractField($horoscopeText, ['गण']) ?? $this->extractField($text, ['गण']));
        $mangalik = $this->rejectIfLabelNoise($this->extractField($text, ['मांगलिक']));
        // "वर्ण" ओळीतला raw value दोन use-cases साठी वापरतो:
        // 1) Physical complexion (raw 그대로, e.g. "गोरा")
        // 2) Horoscope varna (canonical keys via validateVarna)
        $varnaRaw = $this->extractFieldAfterLabels($personalText, ['वर्ण']) ?? $this->extractField($personalText, ['वर्ण'])
            ?? $this->extractFieldAfterLabels($text, ['वर्ण']) ?? $this->extractField($text, ['वर्ण']);
        $complexion = $varnaRaw !== null ? trim((string) $varnaRaw) : null;
        // New structured fields for Phase-5: Kul / Devak / Navras / Birth weekday.
        $kulName = $this->rejectIfLabelNoise(
            $this->extractField($familyText, ['कुळ', 'कुल']) ?? $this->extractField($text, ['कुळ', 'कुल'])
        );
        $devak = $this->rejectIfLabelNoise(
            $this->extractFieldAfterLabels($horoscopeText, ['देवक']) ?? $this->extractFieldAfterLabels($text, ['देवक']) ??
            $this->extractField($familyText, ['देवक', 'कुल देवता', 'कुलदेवता']) ??
            $this->extractField($text, ['देवक', 'कुल देवता', 'कुलदेवता'])
        );
        if ($devak !== null && trim((string) $devak) === 'देव') {
            $devak = null;
        }
        if ($devak === null) {
            $devak = $kuldaivat;
        }
        $gotra = $this->rejectHoroscopeJunk($gotra);
        $kuldaivat = $this->rejectHoroscopeJunk($kuldaivat);
        $kulName = $this->rejectHoroscopeJunk($kulName);
        $devak = $this->rejectHoroscopeJunk($devak);
        $navrasName = $this->rejectIfLabelNoise(
            $this->extractField($horoscopeText, ['नावरस नाव', 'नवरस नाव', 'Navras']) ??
            $this->extractField($text, ['नावरस नाव', 'नवरस नाव', 'Navras'])
        );
        $birthWeekday = $this->rejectIfLabelNoise(
            $this->extractField($horoscopeText, ['जन्मवार', 'जन्म वार', 'Birth day']) ??
            $this->extractField($text, ['जन्मवार', 'जन्म वार', 'Birth day'])
        );
        // Horoscope varna साठी फक्त canonical values ठेवतो; physical complexion साठी वरचं $complexion raw जतन केले आहे.
        $varna = $this->validateVarna($varnaRaw);

        // Additive fallback: parse combined horoscope lines when individual labels missed pieces.
        $this->applyCombinedHoroscopeFallbacks(
            $horoscopeText,
            $text,
            $rashi,
            $nadi,
            $gan
        );
        $motherOccupation = $this->extractField($familyText, ['आईचा व्यवसाय', 'माता व्यवसाय']) ?? $this->extractField($text, ['आईचा व्यवसाय']);
        $fatherOccupation = $this->extractField($familyText, ['वडिलांचे व्यवसाय', 'पिता व्यवसाय']) ?? $this->extractField($text, ['वडिलांचे व्यवसाय']);
        $mama = $this->rejectIfLabelNoise($this->extractField($familyText, ['मामा']) ?? $this->extractField($text, ['मामा']));
        $relatives = $this->rejectIfLabelNoise($this->extractField($familyText, ['इतर पाहुणे', 'नातेसंबंध']) ?? $this->extractField($text, ['इतर पाहुणे', 'नातेसंबंध']));

        $gender = $this->inferGender($text);
        $gender = $gender ?? $romanized['gender'] ?? null;
        // Religion / caste / sub-caste: support combined patterns like "जात २- हिंदु- 96 कुळी मराठा"
        $religion = $this->extractField($text, ['धर्म', 'Religion']);
        $religion = $religion ?? $romanized['religion'] ?? null;
        $casteRaw = $this->extractFieldAfterLabels($text, ['जात', 'Caste', 'Community']);
        $casteRaw = $casteRaw ?? $this->extractAfterLabelNextLine($text, 'जात');
        $casteRaw = $casteRaw ?? $romanized['caste'] ?? null;
        if ($casteRaw !== null) {
            $casteRaw = $this->cleanOcrNoiseFromFieldValue($casteRaw);
        }
        $kuliPattern = '(?:कुळी|क्‌ळी|क[\x{094D}\x{200C}\s]*ळी|कळी)';
        if ($casteRaw !== null && $casteRaw !== '') {
            // Pattern: "हिंदु- 96 कुळी मराठा" or "हिंदू- ९६ क्‌ळी मराठा" (क्‌ळी may include ZWNJ).
            if (preg_match('/हिंद[ुू]\s*-\s*([0-9०-९]+\s*' . $kuliPattern . ')\s*(मराठा)?/u', $casteRaw, $m)) {
                $subCaste = trim(preg_replace('/(?:क्‌ळी|क[\x{094D}\x{200C}\s]*ळी|कळी)/u', 'कुळी', $m[1]));
                $caste = 'मराठा';
                $religion = $religion ?? 'हिंदु';
            } elseif (preg_match('/हिंद[ुू]\s*[-]?\s*([0-9०-९]+\s*' . $kuliPattern . ')\s*मराठा/u', $casteRaw, $m)) {
                $subCaste = trim(preg_replace('/(?:क्‌ळी|क[\x{094D}\x{200C}\s]*ळी|कळी)/u', 'कुळी', $m[1]));
                $caste = 'मराठा';
                $religion = $religion ?? 'हिंदु';
            } else {
                $subCaste = $this->extractField($text, ['उपजात', 'Sub caste']);
                $caste = $this->normalizeCasteValue($casteRaw);
            }
        } else {
            $subCaste = $this->extractField($text, ['उपजात', 'Sub caste']);
            $caste = $this->extractCasteByDictionary($text);
            $caste = $this->normalizeCasteValue($caste);
        }

        // Fallback when जात line is broken/missing: full text contains हिंदु - मराठा (९६ कुळी / ९६ क्‌ळी)
        if (($religion === null || $caste === null) && preg_match('/हिंद[ुू]\s*[-–]\s*मराठा\s*\(([०-९0-9]+)\s*(?:कुळी|क्‌ळी|कळी)\)/u', $text, $m)) {
            $religion = $religion ?? 'हिंदू';
            $caste = $caste ?? 'मराठा';
            $subCaste = $subCaste ?? ($m[1] . ' कुळी');
        }
        if (($religion === null || $caste === null) && (preg_match('/हिंद[ुू]\s*[-–]?\s*(?:[०-९0-9]+\s*क[\x{094D}\x{200C}\s]*(?:ुळी|ळी)\s*)?मराठा/u', $text) || preg_match('/हिंद[ुू]\s*[-–]?\s*मराठा/u', $text) || (mb_strpos($text, 'हिंदु') !== false && mb_strpos($text, 'मराठा') !== false))) {
            $religion = $religion ?? 'हिंदू';
            $caste = $caste ?? 'मराठा';
            if ($subCaste === null && preg_match('/([०-९0-9]+)\s*(?:कुळी|क्‌ळी|क[\x{094D}\x{200C}\s]*ळी|कळी)/u', $text, $m)) {
                $subCaste = $m[1] . ' कुळी';
            }
        }

        // When जात line is missing in OCR: infer from कुलस्वामी/माणकेश्वर/तुळजाभवानी + Maratha surname (भोसले).
        if (($religion === null || $caste === null) && (
            mb_strpos($text, 'कुलस्वामी') !== false || mb_strpos($text, 'माणकेश्वर') !== false || mb_strpos($text, 'तुळजाभवानी') !== false || mb_strpos($text, 'तुळनापूर') !== false
        ) && (mb_strpos($text, 'भोसले') !== false || mb_strpos($text, 'मराठा') !== false)) {
            $religion = $religion ?? 'हिंदू';
            $caste = $caste ?? 'मराठा';
            if ($subCaste === null && preg_match('/([०-९0-9]+)\s*(?:कुळी|क्‌ळी|कळी)/u', $text, $m)) {
                $subCaste = $m[1] . ' कुळी';
            }
        }

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

        if ($caste !== null && preg_match('/^हिंदू\s*[-]?\s*(.+)$/u', $caste, $m)) {
            $caste = trim($m[1]);
        }
        if ($caste !== null && preg_match('/^हिंदूमटाठा$/u', $caste)) {
            $caste = 'मराठा';
        }
        $caste = $this->validateCaste($caste);
        $maritalRaw = $this->extractField($text, ['वैवाहिक स्थिती', 'Marital status']);
        $maritalStatus = $this->normalizeMaritalStatus($maritalRaw);
        if ($maritalStatus === null && ! $this->hasExplicitMaritalLabel($text)) {
            $maritalStatus = 'unmarried';
            $confidence['marital_status'] = self::CONF_REGEX;
        } else {
            $confidence['marital_status'] = $maritalStatus !== null ? self::CONF_DIRECT : self::CONF_MISSING;
        }

        $salaryRaw = $this->extractField($text, ['मासिक वेतन', 'पगार', 'वेतन', 'वेतन/उत्पन्न', 'Package', 'वार्षिक उत्पन्न']);
        $salaryRaw = $salaryRaw ?? $this->extractFieldAfterLabels($text, ['Annual Package', 'Annual package', 'Package']);
        if ($salaryRaw === null && preg_match('/Annual\s*Package\s*\(?\s*(\d+(?:\.\d+)?)\s*Lacs?\.?/ui', $text, $m)) {
            $salaryRaw = $m[1] . ' Lac';
        }
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

        $bloodGroupRaw = $this->extractFieldAfterLabels($text, ['रक्तगट', 'रक्‍त गट', 'रक्त गट', 'Blood group'])
            ?? $this->extractField($text, ['रक्तगट', 'रक्‍त गट', 'रक्त गट', 'Blood group']);
        if ($bloodGroupRaw === null && preg_match('/([ABO])\s*[\+\-]?\s*(?:\+ve|\+|\-ve|-)/ui', $text, $m)) {
            $bloodGroupRaw = $m[0];
        }
        // SSOT Day-27: Apply baseline normalization + patterns
        $bloodGroup = \App\Services\Ocr\OcrNormalize::normalizeBloodGroup($bloodGroupRaw);
        if ($bloodGroup) {
            $bloodGroup = \App\Services\Ocr\OcrNormalize::applyBaselinePatterns('blood_group', $bloodGroup);
        }
        // Fallback to existing normalizeBloodGroup if OcrNormalize returns original value
        if ($bloodGroup === $bloodGroupRaw) {
            $bloodGroup = $this->normalizeBloodGroup($bloodGroupRaw);
        }
        // Reject invalid blood_group (e.g. numeric garbage "84४७"); allow only A+, A-, B+, B-, AB+, AB-, O+, O-
        $bloodGroup = $this->validateBloodGroupStrict($bloodGroup);
        $confidence['blood_group'] = $bloodGroup !== null ? self::CONF_DIRECT : self::CONF_MISSING;

        $addressBlock = $this->extractAddressBlock($contactText) ?? $this->extractAddressBlock($text);
        $confidence['address'] = $addressBlock !== null ? self::CONF_REGEX : self::CONF_MISSING;

        // Post-process mother name to split inline occupation like "(गृहिणी)" into a dedicated field.
        if ($motherName !== null && $motherOccupation === null && preg_match('/\((.+?)\)/u', $motherName, $mOcc)) {
            $maybeOcc = $this->cleanValue($mOcc[1]);
            if ($maybeOcc !== '') {
                $motherOccupation = $maybeOcc;
            }
            $motherName = trim(preg_replace('/\(.+?\)/u', '', $motherName));
        }

        // Strip honorifics from stored parent names for core snapshot.
        $fatherName = $fatherName !== null ? $this->cleanPersonName($fatherName) : null;
        $motherName = $motherName !== null ? $this->cleanPersonName($motherName) : null;

        // Remove occupation label artifacts (नोकरी :-, नोकरी >, etc.) from father_occupation
        $fatherOccupation = $fatherOccupation !== null ? \App\Services\AIParsingService::cleanOccupationLabel($fatherOccupation) : null;

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
            // Physical complexion raw text (e.g. "गोरा"); preview/wizard मध्ये MasterComplexion शी map होते.
            'complexion' => $complexion,
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

        // ——— Legal section removed (matrimony: no benefit). No legal_cases in snapshot. ———

        // ——— OPTIONAL: Horoscope ———
        // Phase-5: structured array-of-rows, aligned with ProfileHoroscopeData / horoscope-engine.
        $horoscopeRows = [];
        $hasAnyHoroscopeText = $rashi !== null
            || $nakshatra !== null
            || $nadi !== null
            || $gan !== null
            || $yoni !== null
            || $mangalik !== null
            || $varna !== null
            || $gotra !== null
            || $kuldaivat !== null
            || $devak !== null
            || $navrasName !== null
            || $birthWeekday !== null;

        if ($hasAnyHoroscopeText) {
            $row = [
                // ID fields are intentionally left null here; ControlledOptionNormalizer +
                // MutationService will resolve canonical keys / IDs later in the pipeline.
                'rashi_id' => null,
                'nakshatra_id' => null,
                'charan' => null,
                'gan_id' => null,
                'nadi_id' => null,
                'yoni_id' => null,
                'mangal_dosh_type_id' => null,
                // Preserve sanitized free-text so intake snapshot normalizer can map safely.
                'rashi' => $this->rejectHoroscopeJunk($rashi),
                'nakshatra' => $this->rejectHoroscopeJunk($nakshatra),
                'nadi' => $this->rejectHoroscopeJunk($nadi),
                'gan' => $this->rejectHoroscopeJunk($gan),
                'yoni' => $this->rejectHoroscopeJunk($yoni),
                // Textual attributes — kuldaivat = कुलदैवत / kuldevta / कुलदेवता / कुलस्वामी / कूळस्वामी.
                'devak' => $devak ?? $kuldaivat,
                'kuldaivat' => $kuldaivat,
                'gotra' => $gotra,
                'navras_name' => $navrasName,
                'birth_weekday' => $birthWeekday,
            ];

            // Only push if at least one non-null/non-empty textual field is present.
            $nonNull = array_filter($row, fn ($v) => $v !== null && $v !== '');
            if (! empty($nonNull)) {
                $horoscopeRows[] = $row;
                $confidence['horoscope'] = self::CONF_REGEX;
            } else {
                $confidence['horoscope'] = self::CONF_MISSING;
            }
        } else {
            $confidence['horoscope'] = self::CONF_MISSING;
        }

        $horoscope = $horoscopeRows;

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

        // ——— FAMILY STRUCTURES: siblings, relatives, and refined career roles ———
        $familyStructures = $this->extractFamilyStructures($text);

        // Prefer more precise sibling counts derived from structured rows when available.
        $siblings = $familyStructures['siblings'] ?? [];
        if (! empty($siblings)) {
            $brothers = array_filter($siblings, fn ($row) => ($row['relation_type'] ?? null) === 'brother');
            $sisters  = array_filter($siblings, fn ($row) => ($row['relation_type'] ?? null) === 'sister');
            $brotherCount = count($brothers);
            $sisterCount  = count($sisters);
            $core['brother_count'] = $brotherCount;
            $core['sister_count']  = $sisterCount;
        }

        // Father occupation from family structures if not already filled.
        if (($familyStructures['core_overrides']['father_occupation'] ?? null) !== null && ($core['father_occupation'] ?? null) === null) {
            $core['father_occupation'] = $familyStructures['core_overrides']['father_occupation'];
        }

        // Mother occupation/name refined from family structures if provided.
        if (($familyStructures['core_overrides']['mother_occupation'] ?? null) !== null && ($core['mother_occupation'] ?? null) === null) {
            $core['mother_occupation'] = $familyStructures['core_overrides']['mother_occupation'];
        }
        if (($familyStructures['core_overrides']['mother_name'] ?? null) !== null) {
            $core['mother_name'] = $familyStructures['core_overrides']['mother_name'];
        }

        // Career split: if we have a candidate-only career history from familyStructures, prefer it.
        if (! empty($familyStructures['career_history'] ?? [])) {
            $careerHistory = $familyStructures['career_history'];
        }

        $relativesRows = $familyStructures['relatives'] ?? [];

        if (! empty($familyStructures['other_relatives_text'] ?? null) && is_string($familyStructures['other_relatives_text'])) {
            $core['other_relatives_text'] = $familyStructures['other_relatives_text'];
        }

        // Marathi-safe caste/sub_caste: "NN कुळी/क्‌ळी मराठा" → sub_caste = "NN कुळी", caste = "मराठा". No mojibake.
        if (($core['sub_caste'] ?? null) === null && isset($core['caste']) && is_string($core['caste'])) {
            if (preg_match('/([0-9०-९]+\s*(?:कुळी|क्‌ळी|क[\x{094D}\x{200C}\s]*ळी|कळी))/u', $core['caste'], $m) && mb_strpos($core['caste'], 'मराठा') !== false) {
                $normalized = preg_replace('/(?:क्‌ळी|क[\x{094D}\x{200C}\s]*ळी|कळी)/u', 'कुळी', $m[1]);
                $core['sub_caste'] = trim($normalized);
                $core['caste'] = 'मराठा';
            }
        }

        // Build simple path-based confidence map for key fields.
        $confidenceMap = [];
        $addConf = function (string $path, float $value) use (&$confidenceMap): void {
            $confidenceMap[$path] = $value;
        };

        if ($fullName !== null) {
            $addConf('core.full_name', (float) ($confidence['full_name'] ?? self::CONF_DIRECT));
        }
        if ($dateOfBirth !== null) {
            $addConf('core.date_of_birth', (float) ($confidence['date_of_birth'] ?? self::CONF_DIRECT));
        }
        if ($birthTime !== null) {
            $addConf('core.birth_time', (float) ($confidence['birth_time'] ?? self::CONF_REGEX));
        }
        if ($religion !== null) {
            $addConf('core.religion', (float) ($confidence['religion'] ?? self::CONF_DIRECT));
        }
        if ($caste !== null) {
            $addConf('core.caste', (float) ($confidence['caste'] ?? self::CONF_DIRECT));
        }
        if ($subCaste !== null) {
            $addConf('core.sub_caste', (float) ($confidence['sub_caste'] ?? self::CONF_DIRECT));
        }
        if ($fatherName !== null) {
            $addConf('core.father_name', (float) ($confidence['father_name'] ?? self::CONF_DIRECT));
        }
        if ($fatherOccupation !== null) {
            $addConf('core.father_occupation', (float) ($confidence['father_occupation'] ?? self::CONF_REGEX));
        }
        if ($motherName !== null) {
            $addConf('core.mother_name', (float) ($confidence['mother_name'] ?? self::CONF_DIRECT));
        }
        if ($motherOccupation !== null) {
            $addConf('core.mother_occupation', (float) ($confidence['mother_occupation'] ?? self::CONF_REGEX));
        }
        if ($primaryContact !== null && ! empty($contacts)) {
            $addConf('contacts.0.number', (float) ($confidence['primary_contact_number'] ?? self::CONF_DIRECT));
        }
        if (! empty($siblings)) {
            foreach ($siblings as $idx => $s) {
                if (! empty($s['name'] ?? null)) {
                    $addConf("siblings.$idx.name", self::CONF_REGEX);
                }
            }
        }
        if (! empty($relativesRows)) {
            foreach ($relativesRows as $idx => $r) {
                if (! empty($r['relation_type'] ?? null)) {
                    $addConf("relatives.$idx.relation_type", self::CONF_REGEX);
                }
            }
        }
        if (! empty($careerHistory)) {
            $addConf('career_history.0.company', self::CONF_REGEX);
        }

        // Merge with existing scalar confidence values and relation confidences.
        $confidenceMap = array_merge(
            $confidenceMap,
            array_map(fn ($v) => (float) $v, $confidence),
            $relationsConfidence
        );

        $educationHistory = $this->sanitizeEducationInstitutionFromDevak($educationHistory);
        $careerHistory = $this->sanitizeCareerLocationFromGotra($careerHistory);

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
            'preferences' => $preferences,
            'extended_narrative' => null,
            'confidence_map' => $confidenceMap,
            'relatives' => $relativesRows,
            'siblings' => $siblings,
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

    /**
     * Normalize Marathi birth time phrases to HH:MM (24-hour).
     */
    private function normalizeBirthTime(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = trim($value);
        if ($v === '') {
            return null;
        }

        $lower = $v;
        $isNight = mb_strpos($lower, 'रात्री') !== false;
        $isEvening = mb_strpos($lower, 'संध्याकाळ') !== false;
        $isAfternoon = mb_strpos($lower, 'दुपारी') !== false;
        $isMorning = mb_strpos($lower, 'सकाळी') !== false;

        if (!preg_match('/(\d{1,2})\s*वा\.?\s*(\d{1,2})\s*मि/u', $lower, $m)) {
            return null;
        }

        $hour = (int) $m[1];
        $minute = (int) $m[2];

        if ($hour < 0 || $hour > 12 || $minute < 0 || $minute > 59) {
            return null;
        }

        // Convert to 24h using rough Marathi period hints.
        if ($isNight || $isEvening) {
            if ($hour < 12) {
                $hour += 12;
            }
        } elseif ($isAfternoon) {
            if ($hour < 12 && $hour >= 1) {
                $hour += 12;
            }
        }

        if ($hour === 24) {
            $hour = 0;
        }

        return sprintf('%02d:%02d', $hour, $minute);
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
            if (mb_substr_count($line, 'श्री') > 2 && !$this->isRelativeLine($line)) {
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
     * Whether the line appears to be a relatives/family line (relation keyword present).
     * Used to avoid dropping valid relative lines that contain multiple "श्री" during sanitization.
     */
    private function isRelativeLine(string $line): bool
    {
        $keywords = [
            'मामा', 'चुलते', 'चुलती', 'काका', 'काकू', 'मावशी', 'आत्या', 'दाजी',
            'नातेवाईक', 'भाऊ', 'बहीण', 'बहिण',
        ];
        foreach ($keywords as $kw) {
            if (mb_strpos($line, $kw) !== false) {
                return true;
            }
        }
        return false;
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
            if (mb_strpos($line, 'वडील') !== false || mb_strpos($line, 'वडिलांचे') !== false || mb_strpos($line, 'आई') !== false || mb_strpos($line, 'भाऊ') !== false || mb_strpos($line, 'बहिण') !== false || mb_strpos($line, 'बहीण') !== false) {
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
        if (mb_strpos($value, 'आईचे') !== false || mb_strpos($value, 'आईचं') !== false) {
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
        $pattern = '/' . preg_quote($label, '/') . '\s*[:\-\s]{0,15}\s*([^\n]+)/u';
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

    /**
     * Fallback when father name is on same line after वडिलांचे नांव or appears as कै. डॉ. <name> before आईचे.
     */
    private function extractFatherNameFromKaiOrDoctor(string $text): ?string
    {
        // Same line: वडिलांचे नांव : कै. डॉ. शहाजी विष्णू भोसले
        if (preg_match('/वडिलांचे\s*नांव\s*[:\-]?\s*(कै\.?\s*डॉ\.?\s*[^\n]+?)(?=\s*आईचे|\n)/u', $text, $m)) {
            $name = trim(preg_replace('/\s+/u', ' ', $m[1]));
            if (mb_strlen($name) > 5 && $this->rejectIfLabelNoise($name) !== null) {
                return $this->validateFatherName($name);
            }
        }
        // Between वडिलांचे and आईचे: capture line containing कै. or डॉ. and a surname-like word
        if (preg_match('/(कै\.?\s*डॉ\.?\s*[\p{L}\s.]+?)(?=\s*आईचे|\n\s*आईचे)/u', $text, $m)) {
            $name = trim(preg_replace('/\s+/u', ' ', $m[1]));
            if (mb_strlen($name) > 8 && preg_match('/[\p{L}]{2,}/u', $name)) {
                return $this->validateFatherName($this->rejectIfLabelNoise($name) ?? $name);
            }
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
            if ((mb_strpos($label, 'वडिलांचे') !== false || mb_strpos($label, 'वडीलांचे') !== false) && (mb_strpos($value, 'आईचे') !== false || mb_strpos($value, 'आईचं') !== false)) {
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
        // Support additional labels like "Contact.No.-" used in many Marathi biodatas.
        $contactStr = $this->extractFieldAfterLabels(
            $text,
            ['संपर्क क्रमांक', 'मोबाईल नं', 'मोबाईल नंबर', 'Contact.No.', 'Contact', 'Mobile']
        );
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

    /**
     * Strip OCR noise from start of field value: leading digits (Marathi/English), +, *, २- style prefixes.
     * E.g. "२- हिंदु- 96 कुळी मराठा" -> "हिंदु- 96 कुळी मराठा", "भाऊ +" -> "भाऊ +" (no change if no leading noise).
     */
    private function cleanOcrNoiseFromFieldValue(string $value): string
    {
        $v = trim($value);
        if ($v === '') {
            return $v;
        }
        // Strip one or more blocks: optional spaces + digits (०-९ or 0-9) + optional [+*.\-] + optional spaces
        $prev = '';
        while ($prev !== $v) {
            $prev = $v;
            $v = preg_replace('/^\s*[०-९0-9]+\s*[+\-*\.]?\s*/u', '', $v);
            $v = trim($v);
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
            // Fallback: if label appears with some non-empty text (and not "नाही"), treat as at least one.
            if (preg_match('/' . preg_quote($label, '/') . '.*\S/um', $text, $m) && mb_strpos($m[0], 'नाही') === false) {
                return 1;
            }
        }
        return null;
    }

    /**
     * Focused helper for Marathi biodata family core:
     * extracts father/mother names and occupations plus sibling counts from contextual lines.
     */
    private function extractFamilyCore(string $text): array
    {
        $result = [
            'father_name' => null,
            'father_occupation' => null,
            'mother_name' => null,
            'mother_occupation' => null,
            'brothers_count' => null,
            'sisters_count' => null,
        ];

        $lines = preg_split('/\R/u', $text) ?: [];
        $lineCount = count($lines);

        for ($i = 0; $i < $lineCount; $i++) {
            $line = trim($lines[$i]);
            if ($line === '') {
                continue;
            }

            // Father name + nearby occupation (e.g. वडिलांचे नाव :- श्री. राजेंद्र भाऊराव पाटील ...)
            if (mb_strpos($line, 'वडिलांचे नाव') !== false && $result['father_name'] === null) {
                if (preg_match('/वडिलांचे नाव\s*[:\-]?\s*(.+)$/u', $line, $m)) {
                    $name = $this->cleanPersonName($m[1]);
                    if ($name !== null) {
                        $result['father_name'] = $name;
                    }
                } elseif ($i + 1 < $lineCount) {
                    $name = $this->cleanPersonName($lines[$i + 1]);
                    if ($name !== null) {
                        $result['father_name'] = $name;
                    }
                }

                // Look ahead a few lines for a likely occupation line (नोकरी / फॅक्टरी / कंपनी / काम)
                if ($result['father_occupation'] === null) {
                    for ($j = $i + 1; $j < min($i + 5, $lineCount); $j++) {
                        $candidate = trim($lines[$j]);
                        if ($candidate === '') {
                            continue;
                        }
                        if (
                            mb_strpos($candidate, 'नोकरी') !== false ||
                            mb_strpos($candidate, 'फॅक्टरी') !== false ||
                            mb_strpos($candidate, 'कंपनी') !== false ||
                            stripos($candidate, 'Factory') !== false
                        ) {
                            $result['father_occupation'] = $this->cleanValue($candidate);
                            break;
                        }
                    }
                }
            }

            // Mother name + inline occupation from parentheses (e.g. आईचे नाव :- सौ. अनिता ... (गृहिणी))
            if (mb_strpos($line, 'आईचे नाव') !== false && $result['mother_name'] === null) {
                $valueLine = $line;
                if (!preg_match('/आईचे नाव\s*[:\-]?\s*(.+)$/u', $line, $m) && $i + 1 < $lineCount) {
                    $valueLine = $lines[$i + 1];
                } elseif (isset($m[1])) {
                    $valueLine = $m[1];
                }

                $valueLine = trim($valueLine);
                if ($valueLine !== '') {
                    $occupation = null;
                    if (preg_match('/\((.+?)\)/u', $valueLine, $occMatch)) {
                        $occupation = $this->cleanValue($occMatch[1]);
                        $valueLine = preg_replace('/\(.+?\)/u', '', $valueLine);
                    }
                    $name = $this->cleanPersonName($valueLine);
                    if ($name !== null) {
                        $result['mother_name'] = $name;
                    }
                    if ($occupation !== null) {
                        $result['mother_occupation'] = $occupation;
                    }
                }
            }

            // Very conservative sibling count fallback:
            // if a clear "भाऊ" or "बहिण/बहीण" line without नाही appears, treat as at least one.
            if ($result['brothers_count'] === null && mb_strpos($line, 'भाऊ') !== false && mb_strpos($line, 'नाही') === false) {
                $result['brothers_count'] = 1;
            }
            if ($result['sisters_count'] === null && (mb_strpos($line, 'बहिण') !== false || mb_strpos($line, 'बहीण') !== false) && mb_strpos($line, 'नाही') === false) {
                $result['sisters_count'] = 1;
            }
        }

        return $result;
    }

    /**
     * Remove common Marathi honorifics, trailing relation tokens, and extra
     * punctuation/whitespace from a person name.
     */
    private function cleanPersonName(string $value): ?string
    {
        $v = trim($value);
        if ($v === '') {
            return null;
        }

        // 1) Strip common honorific prefixes (including ch./ku. variants) first so we
        //    don't lose the actual name when a dot appears immediately after the honorific.
        $v = preg_replace(
            '/^(कु\.?|कुं\.?|चि\.?|श्री\.?|श्रीमती\.?|श्रीमती|सौ\.?|सौं\.?)\s*/u',
            '',
            $v
        );

        // 2) Stop at the first sentence boundary (dot or Marathi danda).
        $parts = preg_split('/[\.।]/u', $v);
        if (! empty($parts)) {
            $v = trim($parts[0]);
        }

        // 3) Cut off at the start of any obvious relation token that may have
        //    bled into this line from the next field.
        $parts = preg_split('/\b(दाजी|चुलते|मामा|काका|मावशी|इतर\s+नातेवाईक|इतर)\b/u', $v);
        if (! empty($parts)) {
            $v = trim($parts[0]);
        }

        // 4) Normalize whitespace.
        $v = preg_replace('/\s+/u', ' ', $v);

        $v = trim($v);
        return $v === '' ? null : $v;
    }

    /**
     * Extract structured siblings, relatives, and a cleaner career split from raw text.
     * This pass is intentionally conservative and tuned for common Marathi biodata
     * patterns (including intake 191).
     *
     * @return array{
     *   siblings: array<int, array<string, mixed>>,
     *   relatives: array<int, array<string, mixed>>,
     *   core_overrides: array<string, mixed>,
     *   career_history: array<int, array<string, mixed>>,
     *   other_relatives_text: string|null,
     * }
     */
    private function extractFamilyStructures(string $text): array
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), fn ($l) => $l !== ''));

        $siblings = [];
        $relatives = [];
        $coreOverrides = [
            'father_occupation' => null,
            'mother_name' => null,
            'mother_occupation' => null,
        ];
        $careerHistory = [];

        // --- SIBLINGS ---
        foreach ($lines as $idx => $line) {
            // Brother: lines starting with "भाऊ" (e.g. "भाऊ + श्री. समर्थ राजेंद्र पाटील (9145206745)")
            if (preg_match('/^भाऊ\b/u', $line)) {
                $name = null;
                $phone = null;

                // Expect patterns like: "भाऊ + श्री. समर्थ राजेंद्र पाटील (9145206745)"
                if (preg_match('/^भाऊ[^\p{L}]*(.+?)(\(\s*([6-9]\d{9})\s*\))?$/u', $line, $m)) {
                    $namePart = trim($m[1]);
                    $name = $this->cleanPersonName($namePart);
                    if (isset($m[3]) && preg_match('/^[6-9]\d{9}$/', $m[3])) {
                        $phone = $m[3];
                    }
                }

                if ($name !== null && $this->isMeaningfulSiblingName($name, 'brother')) {
                    $siblings[] = [
                        'relation_type' => 'brother',
                        'name' => $name,
                        'contact_number' => $phone,
                        'occupation' => null,
                    ];
                }
            }

            // Brother: "चि." (Chiranjeevi) prefix without explicit "भाऊ" (e.g. "चि. प्रज्योत सुभाष पानसरे (B.SC Horti)")
            if (preg_match('/^चि\.\s+(.+?)(?:\s*\(([^)]+)\))?\s*$/u', $line, $m)) {
                $namePart = trim($m[1]);
                $occupation = isset($m[2]) ? trim($m[2]) : null;
                $name = $this->cleanPersonName($namePart);
                if ($name !== null && $this->isMeaningfulSiblingName($name, 'brother')) {
                    $siblings[] = [
                        'relation_type' => 'brother',
                        'name' => $name,
                        'contact_number' => null,
                        'occupation' => $occupation,
                    ];
                }
            }

            // Sister: lines containing "बहिण/बहीण", possibly split across two OCR lines:
            // "बहीण २ सौ." / "पुजा नवनाथ कन्हेरे."
            if (mb_strpos($line, 'बहिण') !== false || mb_strpos($line, 'बहीण') !== false) {
                $combined = $line;
                if (isset($lines[$idx + 1])) {
                    $combined .= ' ' . trim($lines[$idx + 1]);
                }

                $combined = trim($combined);

                // Remove leading "बहीण NN" with Marathi or Arabic digits.
                $namePart = preg_replace('/^\s*ब[ही]ण\s*[0-9०-९]*\s*/u', '', $combined);

                // Stop at the start of any new relative heading (दाजी/चुलते/मामा/इतर नातेवाईक/इतर पाहुणे)
                $parts = preg_split('/\s+(दाजी|चुलते|मामा|इतर\s+नातेवाईक|इतर\s+पाहुणे)\b/u', $namePart);
                $namePart = $parts[0] ?? $namePart;

                $name = $this->cleanPersonName($namePart);

                if ($name !== null && $this->isMeaningfulSiblingName($name, 'sister')) {
                    $siblings[] = [
                        'relation_type' => 'sister',
                        'name' => $name,
                        'contact_number' => null,
                        'occupation' => null,
                    ];
                }
            }
        }

        // --- RELATIVES (grouped summary rows) + Other Relatives (आडनाव/गाव) → other_relatives_text ---
        $currentType = null;
        $buffer = [];
        $otherRelativesBuffer = [];
        $inOtherRelatives = false;
        $flushRelative = function () use (&$relatives, &$currentType, &$buffer) {
            if ($currentType !== null && ! empty($buffer)) {
                $relatives[] = [
                    'relation_type' => $currentType,
                    'notes' => implode(' ', $buffer),
                ];
            }
            $currentType = null;
            $buffer = [];
        };

        foreach ($lines as $line) {
            if (mb_strpos($line, 'दाजी') !== false) {
                $inOtherRelatives = false;
                $flushRelative();
                $currentType = 'दाजी';
                $buffer[] = $line;
            } elseif (mb_strpos($line, 'चुलते') !== false) {
                $inOtherRelatives = false;
                $flushRelative();
                $currentType = 'चुलते';
                $buffer[] = $line;
            } elseif (mb_strpos($line, 'आत्या') !== false) {
                // Separate block for paternal aunt (आत्या) and तिचा नवरा.
                $inOtherRelatives = false;
                $flushRelative();
                $currentType = 'आत्या';
                $buffer[] = $line;
            } elseif (mb_strpos($line, 'मामा') !== false) {
                $inOtherRelatives = false;
                $flushRelative();
                $currentType = 'मामा';
                $buffer[] = $line;
            } elseif (mb_strpos($line, 'इतर नातेवाईक') !== false || mb_strpos($line, 'इतर पाहुणे') !== false) {
                $flushRelative();
                $inOtherRelatives = true;
                $firstLine = preg_replace('/^.*?इतर\s+(?:नातेवाईक|पाहुणे)\s*[:-]\s*/u', '', $line);
                $otherRelativesBuffer = [trim($firstLine)];
            } elseif ($inOtherRelatives && (preg_match('/^\s*Contact\.?\s*No\.?/ui', $line) || preg_match('/मोबाइल|Mobile\s*[:.-]/ui', $line))) {
                $inOtherRelatives = false;
            } else {
                if ($currentType !== null) {
                    $buffer[] = $line;
                } elseif ($inOtherRelatives) {
                    $otherRelativesBuffer[] = trim($line);
                }
            }
        }
        $flushRelative();

        $otherRelativesText = null;
        if (! empty($otherRelativesBuffer)) {
            $joined = implode(', ', array_filter(array_map('trim', $otherRelativesBuffer)));
            $otherRelativesText = preg_replace('/\s*,\s*,/', ',', $joined);
            $otherRelativesText = trim(preg_replace('/\s+/u', ' ', $otherRelativesText));
            if ($otherRelativesText === '') {
                $otherRelativesText = null;
            }
        }

        $relatives = $this->splitRelativeRowsByShri($relatives);
        $relatives = array_values(array_filter($relatives, function ($r) {
            return $this->isMeaningfulRelativeNote((string) ($r['notes'] ?? ''));
        }));
        $relatives = $this->structureRelativeRows($relatives);

        // --- CAREER SPLIT (candidate vs father vs brother) ---
        // Use "नोकरी" lines heuristically.
        $careerLines = [];
        foreach ($lines as $i => $line) {
            if (mb_strpos($line, 'नोकरी') !== false) {
                $careerLines[] = ['idx' => $i, 'line' => $line];
            }
        }

        $candidateJob = null;
        $fatherJob = null;
        $brotherJob = null;

        foreach ($careerLines as $cl) {
            $line = $cl['line'];

            // Candidate: often Amdocs / IT company, before siblings section.
            if ($candidateJob === null && (stripos($line, 'Amdocs') !== false || stripos($line, 'Company') !== false)) {
                $candidateJob = \App\Services\AIParsingService::cleanOccupationLabel($this->cleanValue($line));
                continue;
            }

            // Father: sugar factory / factory wording.
            if ($fatherJob === null && (mb_strpos($line, 'शुगर फॅक्टरी') !== false || stripos($line, 'Factory') !== false)) {
                $fatherJob = \App\Services\AIParsingService::cleanOccupationLabel($this->cleanValue($line));
                continue;
            }

            // Brother: remaining "नोकरी" line (e.g. Bharat Forge)
            if ($brotherJob === null) {
                $brotherJob = \App\Services\AIParsingService::cleanOccupationLabel($this->cleanValue($line));
            }
        }

        if ($candidateJob !== null) {
            $company = $candidateJob;
            $location = null;
            if (stripos($candidateJob, 'Amdocs') !== false) {
                if (preg_match('/^(Amdocs\s+Company)\s+(.+)$/iu', trim($candidateJob), $am)) {
                    $company = trim($am[1]);
                    $location = preg_replace('/\s*,\s*/u', ', ', trim(preg_replace('/\s+/u', ' ', $am[2])));
                } else {
                    $company = 'Amdocs';
                    $rest = trim(preg_replace('/^Amdocs\s*(?:Company\s*)?/i', '', $candidateJob));
                    if ($rest !== '') {
                        $location = preg_replace('/\s*,\s*/u', ', ', preg_replace('/\s+/u', ' ', $rest));
                    }
                }
            } elseif (preg_match('/^([^,]+)[,\s]\s*(.+)$/u', $candidateJob, $cm)) {
                $company = trim($cm[1]);
                $location = preg_replace('/\s*,\s*/u', ', ', trim($cm[2]));
            }
            $careerHistory[] = [
                'job_title' => null,
                'company' => $company,
                'location' => $location,
            ];
        }

        if ($fatherJob !== null) {
            $coreOverrides['father_occupation'] = $fatherJob;
        }

        // Attach brother occupation to brother sibling row if both present.
        if ($brotherJob !== null && ! empty($siblings)) {
            foreach ($siblings as $i => $s) {
                if (($s['relation_type'] ?? null) === 'brother') {
                    $siblings[$i]['occupation'] = $brotherJob;
                    break;
                }
            }
        }

        return [
            'siblings' => $siblings,
            'relatives' => $relatives,
            'core_overrides' => $coreOverrides,
            'career_history' => $careerHistory,
            'other_relatives_text' => $otherRelativesText,
        ];
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
        // फूट or फुट, optional space after digit (५फुट ६इंच). OCR often reads ५ as ७५ (5 feet → 75 feet).
        if (preg_match('/(\d{1,2})\s*फ[ुू]ट\s*(\d{1,2})\s*(इंच|inch|bap)?/u', $value, $m)) {
            $feet = (int) $m[1];
            $inches = (int) $m[2];
            if ($feet >= 10) {
                $feet = $feet % 10;
            }
            if ($feet > 7) {
                $feet = 5;
            }
            return round((float) $feet * 30.48 + (float) $inches * 2.54, 2);
        }
        if (preg_match('/(\d+)\'(\d+)/', $value, $m)) {
            $feet = (int) $m[1];
            $inches = (int) $m[2];
            if ($feet >= 10) {
                $feet = $feet % 10;
            }
            if ($feet > 7) {
                $feet = 5;
            }
            return round((float) $feet * 30.48 + (float) $inches * 2.54, 2);
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
            $feet = (int) $m[1];
            $inches = (int) $m[2];
            if ($feet >= 10) {
                $feet = $feet % 10;
            }
            if ($feet > 7) {
                $feet = 5;
            }
            $totalInches = $feet * 12 + $inches;
            return round($totalInches * 2.54, 2);
        }
        if (preg_match("/(\d{1,2})'\s*(\d{1,2})/", $text, $m)) {
            $feet = (int) $m[1];
            $inches = (int) $m[2];
            if ($feet >= 10) {
                $feet = $feet % 10;
            }
            if ($feet > 7) {
                $feet = 5;
            }
            $totalInches = $feet * 12 + $inches;
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
     * Additive extractor for combined horoscope lines like:
     * - "नाड २ आध्य गण :- राक्षस. चरण :- ४"
     * - "रास :- वृश्चिक नक्षत्र :- मृग"
     * - "देवक + ... रक्त गट :- B+ve"
     *
     * Never overwrites already extracted values; only fills when target is null.
     */
    private function applyCombinedHoroscopeFallbacks(
        string $horoscopeText,
        string $fullText,
        ?string &$rashi,
        ?string &$nadi,
        ?string &$gan
    ): void {
        $text = $horoscopeText !== '' ? $horoscopeText : $fullText;

        // नाड २ आध्य गण :- राक्षस. चरण :- ४
        if ($nadi === null || $gan === null) {
            if (preg_match('/नाड[ी]?\s*[:\-]?\s*([^\.\n]+)\.\s*गण\s*[:\-]?\s*([^\.\n]+)/u', $text, $m)) {
                $nadiRaw = trim($m[1]);
                $ganRaw  = trim($m[2]);

                if ($nadi === null) {
                    $nadi = $this->normalizeNadiValue($nadiRaw);
                }
                if ($gan === null) {
                    $gan = $this->rejectIfLabelNoise($ganRaw);
                }
            }
        }

        // रास :- वृश्चिक नक्षत्र :- मृग
        if ($rashi === null) {
            if (preg_match('/रास\s*[:\-]\s*([^\.\n]+?)(?:\s+नक्षत्र\s*[:\-]\s*([^\.\n]+))?/u', $text, $m)) {
                $rashiRaw = trim($m[1]);
                if ($rashiRaw !== '') {
                    $rashi = $this->rejectIfLabelNoise($rashiRaw);
                }
            }
        }
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
        // 3.25 Lac / 3.25 Lacs. / 9 Lacs. / 2 LAC / 3.25 Lakh / 3.25 लाख -> annual in lakh (float)
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:\n\s*)?(?:Lacs?\.?|Lac\.?|LAC|Lakh|लाख)/ui', $value, $m)) {
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

    /** Allowed blood group values; invalid/garbage (e.g. "84४७") becomes null. */
    private const VALID_BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    /**
     * Public blood-group sanitizer. Accepts only A+, A-, B+, B-, AB+, AB-, O+, O-.
     * Normalizes case and whitespace; returns canonical value or null (e.g. "84४७" => null, "ab +" => "AB+").
     */
    public static function sanitizeBloodGroupValue(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $v = trim($value);
        // Reject OCR garbage: "84४७" (B+ve misread as digits), or any value that is only digits/punctuation
        if (preg_match('/^[0-9०-९\s\-\.]+$/u', $v)) {
            return null;
        }
        $v = preg_replace('/\s+/u', '', $v);
        $v = strtoupper(str_replace(['VE', 'POSITIVE', 'NEGATIVE', 'NEG'], '', $v));
        return in_array($v, self::VALID_BLOOD_GROUPS, true) ? $v : null;
    }

    private function validateBloodGroupStrict(?string $value): ?string
    {
        return self::sanitizeBloodGroupValue($value);
    }

    /**
     * Split relative rows when one note line contains multiple persons (e.g. "मामा + श्री. A ... श्री. B").
     * Creates one row per person (split by श्री. or सौ.) with same relation_type; preserves notes per row.
     */
    private function splitRelativeRowsByShri(array $relatives): array
    {
        $out = [];
        foreach ($relatives as $row) {
            $type = $row['relation_type'] ?? null;
            $notes = $row['notes'] ?? '';
            if (! is_string($notes) || $notes === '') {
                $out[] = $row;
                continue;
            }
            $parts = preg_split('/(?=श्री\.|सौ\.)/u', $notes, -1, PREG_SPLIT_NO_EMPTY);
            $parts = array_map('trim', array_filter($parts, fn ($p) => trim($p) !== ''));
            if (count($parts) <= 1) {
                $out[] = $row;
                continue;
            }
            foreach ($parts as $segment) {
                if ($segment === '') {
                    continue;
                }
                $out[] = [
                    'relation_type' => $type,
                    'notes' => $segment,
                ];
            }
        }
        return $out;
    }

    /**
     * Whether a relative row's notes contain meaningful person/address content.
     * Discard marker-only rows like "मामा +", "चुलते 2-", "दाजी *".
     */
    private function isMeaningfulRelativeNote(string $note): bool
    {
        $t = trim($note);
        if ($t === '') {
            return false;
        }
        $keywords = [
            'मामा', 'चुलते', 'चुलती', 'दाजी', 'काका', 'काकू', 'मावशी', 'आत्या',
            'मावस भाऊ', 'मावस बहीण',
        ];
        $kwPattern = implode('|', array_map('preg_quote', $keywords));
        if (preg_match('/^\s*(' . $kwPattern . ')\s*[+\-*\.\s०-९0-9]*$/u', $t)) {
            return false;
        }
        return true;
    }

    /**
     * Convert relative rows with notes into structured objects: name, location, raw_note.
     * Extracts name after श्री./सौ. and location from (city) pattern.
     */
    private function structureRelativeRows(array $relatives): array
    {
        $out = [];
        foreach ($relatives as $row) {
            $notes = (string) ($row['notes'] ?? '');
            $name = null;
            $location = null;
            $addressLine = null;
            $phone = null;
            $occupation = null;
            if (preg_match('/श्री\.?\s*([^(]+?)\s*(?:\(([^)]+)\))/u', $notes, $m)) {
                $name = trim($m[1]);
                $paren = isset($m[2]) ? trim($m[2]) : null;
                if ($paren !== null && $paren !== '') {
                    if (preg_match('/रा\.|मु\.?\s*पो\.?|ता\.|जि\.|[6-9]\d{9}|मो\.|Mobile|फोन|Ph\./u', $paren)) {
                        $location = $paren;
                    } else {
                        $occupation = $paren;
                    }
                }
            } elseif (preg_match('/सौ\.?\s*([^(]+?)\s*(?:\(([^)]+)\))/u', $notes, $m)) {
                $name = trim($m[1]);
                $paren = isset($m[2]) ? trim($m[2]) : null;
                if ($paren !== null && $paren !== '') {
                    if (preg_match('/रा\.|मु\.?\s*पो\.?|ता\.|जि\.|[6-9]\d{9}|मो\.|Mobile|फोन|Ph\./u', $paren)) {
                        $location = $paren;
                    } else {
                        $occupation = $paren;
                    }
                }
            } elseif (preg_match('/श्री\.?\s*(.+)/u', $notes, $m)) {
                $name = trim($m[1]);
                if (preg_match('/\(([^)]+)\)/u', $notes, $locM)) {
                    $paren = trim($locM[1]);
                    if ($paren !== '') {
                        if (preg_match('/रा\.|मु\.?\s*पो\.?|ता\.|जि\.|[6-9]\d{9}|मो\.|Mobile|फोन|Ph\./u', $paren)) {
                            $location = $paren;
                        } else {
                            $occupation = $paren;
                        }
                    }
                    $name = trim(preg_replace('/\s*\([^)]+\)\s*$/u', '', $name));
                }
            } elseif (preg_match('/सौ\.?\s*(.+)/u', $notes, $m)) {
                $name = trim($m[1]);
                if (preg_match('/\(([^)]+)\)/u', $notes, $locM)) {
                    $paren = trim($locM[1]);
                    if ($paren !== '') {
                        if (preg_match('/रा\.|मु\.?\s*पो\.?|ता\.|जि\.|[6-9]\d{9}|मो\.|Mobile|फोन|Ph\./u', $paren)) {
                            $location = $paren;
                        } else {
                            $occupation = $paren;
                        }
                    }
                    $name = trim(preg_replace('/\s*\([^)]+\)\s*$/u', '', $name));
                }
            }
            if ($location === null && $occupation === null && preg_match('/\(([^)]+)\)/u', $notes, $locM)) {
                $paren = trim($locM[1]);
                if ($paren !== '') {
                    if (preg_match('/रा\.|मु\.?\s*पो\.?|ता\.|जि\.|[6-9]\d{9}|मो\.|Mobile|फोन|Ph\./u', $paren)) {
                        $location = $paren;
                    } else {
                        $occupation = $paren;
                    }
                }
            }
            // Extract first 10-digit Indian mobile if present.
            if (preg_match('/\b([6-9]\d{9})\b/u', $notes, $pm)) {
                $phone = $pm[1];
            }
            // Prefer full address blocks when present: take everything from "रा." / "मु.पो." until phone/next section.
            // This deliberately keeps taluka/district so users don't have to re-type them.
            $addrCandidates = [];
            if (preg_match('/(रा\.[^\n]*)/u', $notes, $am)) {
                $addrCandidates[] = trim($am[1]);
            }
            if (preg_match('/(मु\.?\s*पो\.?[^\n]*)/u', $notes, $bm)) {
                $addrCandidates[] = trim($bm[1]);
            }
            if (! empty($addrCandidates)) {
                // Choose the longest candidate (most complete address), strip obvious trailing phone fragments.
                usort($addrCandidates, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
                $chosen = $addrCandidates[0];
                // Remove trailing phone / "मो." / "Mobile" parts.
                $chosen = preg_replace('/\s*(मो\.|Mobile|फोन|Ph\.).*$/u', '', $chosen);
                $chosen = trim(preg_replace('/\s+/u', ' ', (string) $chosen));
                $addressLine = $chosen !== '' ? $chosen : null;
            }
            // If no explicit full address, keep a shorter "रा." location fragment (at least village/city).
            if ($addressLine === null && preg_match('/रा\.\s*([^\n]+)/u', $notes, $rm)) {
                $location = trim(preg_replace('/\s+/u', ' ', $rm[0]));
            }
            $name = $name !== null && $name !== '' ? $this->cleanPersonName($name) : null;
            $out[] = [
                'relation_type' => $row['relation_type'] ?? null,
                'name' => $name,
                // Provide both keys: some UI expects address_line; older code may still read location.
                'address_line' => $addressLine,
                'location' => $addressLine ?? ($location !== null && $location !== '' ? $location : null),
                'occupation' => $occupation,
                'contact_number' => $phone,
                'raw_note' => $notes,
            ];
        }
        return $out;
    }

    /**
     * Whether an extracted sibling name is meaningful (not just relation/count/honorific).
     */
    private function isMeaningfulSiblingName(string $name, string $relationType): bool
    {
        $t = trim($name);
        if ($t === '' || mb_strlen($t) < 2) {
            return false;
        }
        $stripped = preg_replace('/^(भाऊ|बहिण|बहीण)\s*[0-9०-९\s]*/u', '', $t);
        $stripped = preg_replace('/^(कु\.?|चि\.?|श्री\.?|श्रीमती\.?|सौ\.?)\s*/u', '', $stripped);
        $stripped = trim($stripped);
        if ($stripped === '' || mb_strlen($stripped) < 2) {
            return false;
        }
        if (preg_match('/^(भाऊ|बहिण|बहीण|सौ|श्री)$/u', $stripped)) {
            return false;
        }
        $noiseWords = ['Contact', 'No', 'Mobile', 'Phone', 'संपर्क', 'नं'];
        if (in_array($stripped, $noiseWords, true)) {
            return false;
        }
        return (bool) preg_match('/\p{L}/u', $stripped);
    }

    /**
     * Public helper for horoscope field sanitization. Used by rules parser and AI-first parser.
     * Rejects blood/group keywords (including split/ZWJ variants), high digit ratio, leading symbols, label fragments.
     */
    public static function sanitizeHoroscopeValue(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $v = trim($value);

        // Remove common label fragments before validation
        $v = preg_replace('/^\s*(?:देवक|कुल|गोत्र)\s*[:\-]*\s*/u', '', $v);
        $v = preg_replace('/\s*[:\-]\s*$/u', '', $v);
        $v = preg_replace('/^\s*[:\-]+\s*/u', '', $v);
        $v = trim($v, " \t\n\r\0\x0B.,;:+-*");
        if ($v === '') {
            return null;
        }

        // Normalize internal whitespace and ZWJ so keyword checks catch split/OCR variants
        $v = preg_replace('/\x{200D}/u', '', $v);
        $v = preg_replace('/\s+/u', ' ', $v);
        $v = trim($v);

        // Forbidden blood-group patterns anywhere in value => return null
        $forbidden = [
            'रक्त',
            'रक्तगट',
            'रक्त गट',
            'रक्‍त',
            'रक्‍त गट',
            'blood',
            'bloodgroup',
            'blood group',
            'group',
        ];
        $vLower = mb_strtolower($v);
        foreach ($forbidden as $pattern) {
            if ($pattern === 'group' || str_starts_with($pattern, 'blood')) {
                if (mb_strpos($vLower, mb_strtolower($pattern)) !== false) {
                    return null;
                }
            } else {
                if (mb_strpos($v, $pattern) !== false) {
                    return null;
                }
            }
        }
        // Return null if value starts with symbols or non-letter (e.g. + - * : ; . or stray matra ी)
        if (preg_match('/^[\+\-\*:;\.]/u', $v) || !preg_match('/^\p{L}/u', $v)) {
            return null;
        }
        // Return null if length < 3 after trimming
        if (mb_strlen($v) < 3) {
            return null;
        }
        // Return null if more than 40% digits (OCR garbage)
        $len = mb_strlen($v);
        $digitCount = preg_match_all('/[0-9\x{0966}-\x{096F}]/u', $v);
        if ($len > 0 && $digitCount / $len > 0.4) {
            return null;
        }
        // Already-only-digits / pure punctuation
        if (preg_match('/^[०-९0-9\s\-\.]+$/u', $v) || preg_match('/^[ABO][+-]?$/i', $v)) {
            return null;
        }
        if ($len > 120) {
            return null;
        }
        return $v;
    }

    /**
     * Normalize nadi value: "२ आध्य" -> "आध्य"; "आध्य गण :- राक्षस" -> "आध्य" (first word only when line has गण).
     */
    private function normalizeNadiValue(string $value): ?string
    {
        $v = trim($value);
        if ($v === '') {
            return null;
        }
        $v = preg_replace('/^[०-९0-9\s]+/u', '', $v);
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        // When value contains "गण" (e.g. "आध्य गण :- राक्षस"), nadi is the first word only
        if (mb_strpos($v, 'गण') !== false && preg_match('/^([^\s]+)/u', $v, $m)) {
            return trim($m[1]);
        }
        return $v;
    }

    /**
     * Reject values that are clearly not valid horoscope fields (delegates to sanitizeHoroscopeValue).
     */
    private function rejectHoroscopeJunk(?string $value): ?string
    {
        return self::sanitizeHoroscopeValue($value);
    }

    /** Clear education institution when it is actually devak/horoscope term (e.g. पंचपल्लव). Public for use from AiFirstBiodataParser. */
    public function sanitizeEducationInstitutionFromDevak(array $educationHistory): array
    {
        return self::sanitizeEducationInstitutionFromDevakStatic($educationHistory);
    }

    /** Static so AiFirstBiodataParser can call without parser instance. */
    public static function sanitizeEducationInstitutionFromDevakStatic(array $educationHistory): array
    {
        $devakLike = ['पंचपल्लव', 'देव', 'माणकेश्वर', 'तुळजाभवानी', 'कुलस्वामी', 'कुलस्वामीनी', 'देवक', 'नक्षत्र', 'हस्त', 'गण', 'नाडी', 'आद्य', 'योनी', 'महिषा', 'कन्या', 'रास'];
        foreach ($educationHistory as $i => $row) {
            $inst = $row['institution'] ?? null;
            if ($inst !== null && $inst !== '') {
                $trimmed = trim((string) $inst);
                foreach ($devakLike as $term) {
                    if (mb_strpos($trimmed, $term) !== false || $trimmed === $term) {
                        $educationHistory[$i]['institution'] = null;
                        break;
                    }
                }
            }
        }
        return $educationHistory;
    }

    /** Clear career location when it is actually gotra (e.g. कश्यप पुरशी). Public for use from AiFirstBiodataParser. */
    public function sanitizeCareerLocationFromGotra(array $careerHistory): array
    {
        return self::sanitizeCareerLocationFromGotraStatic($careerHistory);
    }

    /** Static so AiFirstBiodataParser can call without parser instance. */
    public static function sanitizeCareerLocationFromGotraStatic(array $careerHistory): array
    {
        foreach ($careerHistory as $i => $row) {
            $loc = $row['location'] ?? null;
            if ($loc !== null && $loc !== '') {
                $trimmed = trim((string) $loc);
                if (preg_match('/पुरशी|गोत्र|कौशिक|कश्यप\s/u', $trimmed) || mb_strpos($trimmed, 'गोत्र') !== false) {
                    $careerHistory[$i]['location'] = null;
                }
            }
        }
        return $careerHistory;
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

        // Honorifics: कु. = Kumari (female), चि. = Chiranjeevi (male), कुमारी = female, श्री = male (before name)
        if (preg_match('/\bकु\./u', $text) || preg_match('/[:\-]\s*कु\./u', $text)) {
            return 'female';
        }
        if (preg_match('/\bकुमारी\b/u', $text)) {
            return 'female';
        }
        if (preg_match('/\bचि\./u', $text) || preg_match('/[:\-]\s*चि\./u', $text)) {
            return 'male';
        }
        // श्री before name (e.g. "नाव :- श्री. राजेंद्र") = male; avoid matching only in female context
        if (preg_match('/नाव\s*[:\-]\s*श्री\./u', $text) || preg_match('/मुलाचे\s*नाव.*श्री/u', $text)) {
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
