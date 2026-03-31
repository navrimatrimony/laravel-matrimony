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

    private const HOROSCOPE_TRIGGER = ['राशी', 'नक्षत्र', 'गण', 'नाडी', 'लग्नराशी'];

    private const PREFERENCES_TRIGGER = ['अपेक्षा', 'Looking for', 'Bride should'];

    private const CHILDREN_DIVORCE_WIDOW = ['घटस्फोट', 'divorce', 'widow', 'विधवा'];

    /** Context: address-only markers — never assign to caste or name. */
    private const ADDRESS_MARKERS = ['मु.पो.', 'ता.', 'जि.'];

    /** full_name reject if value contains any of these (substring). */
    private const NAME_NOISE = [
        'जन्म', 'उंची', 'शिक्षण', 'नोकरी', 'मु.पो.', 'वडील', 'आई', 'प्रसन्न', 'कुळी',
        'जन्म तारीख', 'जन्मतारीख', 'जन्मस्थळ', 'कौटुंबिक', 'वैवाहिक', 'रास', 'नाडी', 'नावरस', 'नांवटस',
    ];

    /** Marital-status words: must never be stored as mother/father occupation. */
    private const MARITAL_STATUS_OCCUPATION_REJECT = [
        'विवाहीत', 'अविवाहित', 'अविवाहीत', 'घटस्फोटित', 'घटस्फोट', 'विधवा', 'विधुर',
        'unmarried', 'married', 'divorce', 'widow', 'widower',
    ];

    /** OCR garbage words to remove in cleanValue(). */
    private const OCR_BLACKLIST_WORDS = ['snp', 'pres', 'gone', 'ae', 'ora', 'ast'];

    /** Degree keywords for education validation. */
    private const DEGREE_KEYWORDS = ['B.A', 'LL.B', 'B.Com', 'B.Sc', 'M.Sc', 'MSC', 'BE', 'BAMS', 'MBBS', 'B.E', 'B.Tech', 'M.Com', 'BA', 'BCom', 'BSc'];

    /** Occupation keywords for career validation. */
    private const OCCUPATION_KEYWORDS = ['नोकरी', 'व्यवसाय', 'वेतन', 'शेती', 'वकील', 'engineer', 'software', 'teacher', 'doctor', 'clerk', 'business', 'सरकारी', 'खाजगी', 'Limited', 'BPO', 'operator', 'Field', 'Home', 'I.T', 'Co ', 'Forge'];

    /** Caste dictionary (exact match). */
    private const CASTE_DICTIONARY = [
        'मराठा',
        'ब्राह्मण',
        'देशस्थ',
        'लिंगायत',
        'धनगर',
        'माळी',
        'जैन',
        'मुस्लिम',
    ];

    public function parse(string $rawText): array
    {
        // Strip BOM if present; rest of the flow expects UTF-8 text and relies on upstream normalization.
        $rawText = preg_replace('/^\x{FEFF}/u', '', $rawText);
        $tableStructuredHints = [];
        if (stripos($rawText, '<table') !== false) {
            $tableStructuredHints = \App\Services\Parsing\HtmlMarathiBiodataTableExtractor::extract($rawText);
            $rawText = self::flattenHtmlTableForBiodata($rawText);
        }
        $rawText = self::stripIntakeHtmlNoise($rawText);
        // SSOT Day-27: Apply baseline normalization (Devanagari digits + noise removal)
        $text = \App\Services\Ocr\OcrNormalize::normalizeRawText($rawText);
        $text = $this->normalizeText($text);
        $text = $this->removeWatermarkNoise($text);
        $text = $this->sanitizeDocument($text);
        $text = $this->normalizeTableOcrSplitCells($text);
        $text = $this->mergeOrphanSiblingEnumeratorContinuations($text);
        $text = $this->expandBulletedCompoundFieldLines($text);
        $text = (\function_exists('normalizer_normalize') && normalizer_normalize($text, \Normalizer::FORM_C) !== false)
            ? normalizer_normalize($text, \Normalizer::FORM_C) : $text;

        // Modern matrimony-profile biodata (section-based, English-heavy). Keep logic isolated to avoid breaking Marathi flows.
        if ($this->isModernProfileStyleBiodata($rawText, $text)) {
            return $this->parseModernProfileStyleBiodata($rawText, $text);
        }

        $lines = array_map('trim', explode("\n", $text));
        $separatedLayoutHints = \App\Services\Parsing\MarathiSeparatedLabelValueExtractor::extract($lines);
        $sections = $this->detectSections($lines);
        $personalText = implode("\n", $sections['PERSONAL'] ?? []);
        $familyText = implode("\n", $sections['FAMILY'] ?? []);
        $horoscopeText = implode("\n", $sections['HOROSCOPE'] ?? []);
        $confidence = [];

        // ——— Romanized/garbled OCR fallback (e.g. PDF font exports मुलीचे नाव as eqykps ukao) ———
        $romanized = $this->extractFromRomanizedLabels($text);

        // ——— CORE: section-aware → label → regex → dictionary (order per PART 4) ———
        // Prefer name from line that STARTS with नाव/मुलाचे नाव/मुलीचे नाव so we don't take नांवटस नाव (nakshatra) as full name
        $fullName = $this->extractFullNameFromLineStart($text);
        $fullName = $fullName ?? $this->extractAfterLabelNextLine($text, 'मुलीचे नाव') ?? $this->extractAfterLabelNextLine($text, 'मुलाचे नाव');
        $fullName = $fullName ?? $this->extractAfterLabelNextLine($text, 'मुलीचे नांव') ?? $this->extractAfterLabelNextLine($text, 'मुलाचे नांव');
        $fullName = $fullName ?? $this->extractAfterLabelNextLine($text, 'वधूचे नाव') ?? $this->extractAfterLabelNextLine($text, 'वधूचे नांव');
        $fullName = $fullName ?? $this->extractFieldAfterLabels($personalText, ['मुलाचे नाव', 'मुलाचे नांव', 'मुलीचे नाव', 'मुलीचे नांव', 'वधूचे नाव', 'वधूचे नांव', 'नाव', 'नांव', 'Name', 'Full name', 'Full Name']);
        $fullName = $fullName ?? $this->extractFieldAfterLabels($text, ['मुलाचे नाव', 'मुलाचे नांव', 'मुलीचे नाव', 'मुलीचे नांव', 'वधूचे नाव', 'वधूचे नांव', 'नाव', 'नांव', 'Name', 'Full name', 'Full Name']);
        $fullName = $fullName ?? (isset($romanized['full_name']) ? $this->validateFullName($this->cleanRomanizedName($romanized['full_name'])) : null);
        $fullName = $this->validateFullName($fullName);
        $confidence['full_name'] = $fullName !== null ? self::CONF_DIRECT : self::CONF_MISSING;

        $dobLabels = [
            'जन्म तारीख', 'जन्मतारीख', 'जन्म दिनांक', 'जन्मतारिख',
            'DOB', 'Date of Birth', 'Birth Date', 'Date of birth',
        ];
        $birthDateRaw = $this->extractFieldAfterLabels($personalText, $dobLabels);
        $birthDateRaw = $birthDateRaw ?? $this->extractFieldAfterLabels($text, $dobLabels);
        foreach (['जन्म तारीख', 'जन्मतारीख', 'जन्म दिनांक', 'जन्मतारिख'] as $dobLineLabel) {
            if ($birthDateRaw !== null) {
                break;
            }
            $birthDateRaw = $this->extractAfterLabelNextLine($personalText, $dobLineLabel)
                ?? $this->extractAfterLabelNextLine($text, $dobLineLabel);
        }
        $birthDateRaw = $birthDateRaw ?? $romanized['date_of_birth_raw'] ?? null;
        $dobDebugExtractedRaw = $birthDateRaw;
        $birthDateRaw = $this->truncateDobRawAtBirthTimeLabel($birthDateRaw);
        $birthDateRaw = $this->rejectIfLabelNoise($birthDateRaw);
        $birthDateRaw = $this->rejectBirthDateRawWithoutDigits($birthDateRaw);
        // SSOT Day-27: Apply baseline normalization + patterns
        $dateOfBirth = \App\Services\Ocr\OcrNormalize::normalizeDate($birthDateRaw);
        if ($dateOfBirth) {
            $dateOfBirth = \App\Services\Ocr\OcrNormalize::applyBaselinePatterns('date_of_birth', $dateOfBirth);
        }
        // Fallback to existing normalizeDate if OcrNormalize returns original value
        if ($dateOfBirth === $birthDateRaw) {
            $dateOfBirth = $this->normalizeDate($birthDateRaw);
        }
        if ($dateOfBirth === null
            && preg_match('/\b(\d{1,2}[\/\.\-@]\d{1,2}[\/\.\-@]\d{2,4})\b/u', $text, $dateMatch)) {
            $dateOfBirth = $this->normalizeDate(str_replace('@', '-', $dateMatch[1]));
        }
        // Table OCR: label / ":- " / value on separate lines — extractAfterLabel misses the date (birthDateRaw empty).
        if (($dateOfBirth === null || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $dateOfBirth))
            && preg_match('/जन्म\s*तारीख\s*[:\-]+\s*([^\n]+)/u', $text, $dobLine)) {
            $rawTry = $this->truncateDobRawAtBirthTimeLabel(trim($dobLine[1]));
            $rawTry = $this->rejectIfLabelNoise($rawTry);
            $rawTry = $this->rejectBirthDateRawWithoutDigits($rawTry);
            $tryDob = \App\Services\Ocr\OcrNormalize::normalizeDate($rawTry);
            if ($tryDob && $tryDob !== $rawTry) {
                $tryDob = \App\Services\Ocr\OcrNormalize::applyBaselinePatterns('date_of_birth', $tryDob);
            }
            if ($tryDob === $rawTry) {
                $tryDob = $this->normalizeDate($rawTry);
            }
            if ($tryDob !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $tryDob)) {
                $dateOfBirth = $tryDob;
            }
        }
        $dobAfterPrimaryPipeline = $dateOfBirth;
        if ($dateOfBirth === null || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $dateOfBirth)) {
            $recovered = $this->recoverDateOfBirthFromNormalizedBiodataText($text);
            if ($recovered !== null) {
                $dateOfBirth = $recovered;
            }
        }
        if (config('intake.dob_parse_debug')) {
            \Illuminate\Support\Facades\Log::info('DOB_RULES_PIPELINE', [
                'extracted_raw_before_truncate' => $dobDebugExtractedRaw,
                'extracted_raw_after_truncate' => $birthDateRaw,
                'after_primary_normalize' => $dobAfterPrimaryPipeline,
                'after_full_text_recovery' => $dateOfBirth,
            ]);
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
        $heightDisplay = $this->formatHeightFeetInchesDisplay($heightCm, $heightRaw, $heightNormalized);
        $confidence['height'] = $heightCm !== null || $heightRaw !== null ? self::CONF_DIRECT : self::CONF_MISSING;

        $educationRaw = $this->extractAfterLabelMultiline($personalText, 'शिक्षण') ?? $this->extractAfterLabelMultiline($text, 'शिक्षण');
        $educationRaw = $educationRaw ?? $this->extractFieldAfterLabels($personalText, ['शिक्षण', 'Education']);
        $educationRaw = $educationRaw ?? $this->extractFieldAfterLabels($text, ['शिक्षण', 'Education']);
        $educationRaw = $educationRaw ?? $romanized['highest_education'] ?? null;
        $educationRaw = $this->truncateFieldBeforeInlineSectionLabels($educationRaw, ['उंची', 'ऊंची', 'वर्ण', 'जात', 'रक्त', 'देवक', 'रास', 'नक्षत्र', 'नाड', 'नाडी', 'गण', 'गोत्र', 'जन्म']);
        if ($educationRaw !== null) {
            $educationRaw = preg_replace('/\s*\.$/u', '', trim($educationRaw));
        }
        if ($educationRaw !== null && $this->valueSmellsLikeHoroscopeOrGotraLeak($educationRaw)) {
            $educationRaw = null;
        }
        $education = $this->validateEducation($educationRaw);
        if ($education === null && $educationRaw !== null && mb_strlen(trim($educationRaw)) >= 5) {
            $education = trim($educationRaw);
        }
        $education = $this->stripTrailingEducationNoise($education);
        // Same-row adjacent cells: "शिक्षण :- B.A. LL.B देवक :- … गोत्र :- …" — truncate may miss if education matched wrong line.
        if ($education !== null && (mb_strpos((string) $education, 'गोत्र') !== false || mb_strpos((string) $education, 'देवक') !== false)) {
            if (preg_match('/शिक्षण\s*[:\-]+\s*(.+?)(?=\s+(?:गोत्र|देवक|वर्ण|रास|कुलस्वामी)\s*[:\-]|\R{2}|$)/us', $text, $eduFix)) {
                $education = $this->validateEducation(trim($eduFix[1])) ?? trim($eduFix[1]);
                $education = $this->stripTrailingEducationNoise($education);
            }
        }
        $confidence['highest_education'] = $education !== null ? self::CONF_DIRECT : self::CONF_MISSING;

        $parentsFromCombinedLine = $this->findCombinedVadilAaiParents($text);

        // Parent names: prefer FAMILY section first, then full text; UTF-8 Marathi labels only.
        $fatherLabels = [
            'वडिलांचे नाव', 'वडिलांचे नांव', 'वडीलांचे नाव', 'वडीलांचे नांव',
            'वडिलाचे नाव', 'वडिलाचे नांव', 'वडील नाव', 'वडील नांव',
            'पित्याचे नाव', 'पित्याचे नांव', 'पिता', 'Father',
            'वडील',
        ];
        $motherLabels = [
            'आईचे नाव', 'आईचे नांव', 'आईचं नाव', 'आईचं नांव',
            'मातेचे नाव', 'मातेचे नांव', 'माता', 'आई नाव', 'आई नांव', 'Mother',
            'आई',
        ];

        $fatherOccFromParen = null;
        $fatherPhoneFromParen = null;
        $fatherName = null;
        $motherName = null;
        $motherOccupationFromCombined = null;
        $motherPhoneFromParen = null;

        if ($parentsFromCombinedLine !== null) {
            $fatherName = $parentsFromCombinedLine['father_name'] ?? null;
            $motherName = $parentsFromCombinedLine['mother_name'] ?? null;
            $fatherOccFromParen = $parentsFromCombinedLine['father_occupation'] ?? null;
            $motherOccupationFromCombined = $parentsFromCombinedLine['mother_occupation'] ?? null;
        }

        if ($parentsFromCombinedLine === null) {
            $fatherName = $this->extractFieldAfterLabels($familyText, $fatherLabels);
            $fatherName = $fatherName ?? $this->extractFieldAfterLabels($text, $fatherLabels);
            $fatherName = $fatherName ?? $this->extractAfterLabelNextLine($text, 'वडिलांचे नाव');
            $fatherName = $fatherName ?? $this->extractAfterLabelNextLine($text, 'वडिलांचे नांव');
            $fatherName = $fatherName ?? $this->extractAfterLabelNextLine($text, 'वडिलाचे नाव');
            $fatherName = $fatherName ?? $this->extractAfterLabelNextLine($text, 'वडिलाचे नांव');
            $fatherName = $fatherName ?? $this->extractFatherNameFromKaiOrDoctor($text);
            $fatherName = $fatherName ?? $this->extractField($familyText, $fatherLabels);
            $fatherName = $fatherName ?? (isset($romanized['father_name']) ? $this->validateFatherName($this->cleanRomanizedName($romanized['father_name'])) : null);
            $fatherName = $this->rejectIfLabelNoise($fatherName);
            if ($fatherName !== null) {
                $fn = trim($fatherName);
                if (preg_match('/^(.+?)\s*\((.+?)\)\s*$/us', $fn, $fm)) {
                    $inner = $this->cleanValue(trim($fm[2]));
                    if ($inner !== '' && ! $this->isMaritalStatusOccupationLeak($inner)) {
                        if (preg_match('/^नोकरी\s*[\-–:]\s*([6-9]\d{9})$/u', $inner, $nokPh)) {
                            $fatherOccFromParen = 'नोकरी';
                            $fatherPhoneFromParen = $nokPh[1];
                        } elseif (preg_match('/^[6-9]\d{9}$/', preg_replace('/\D/u', '', $inner))) {
                            $fatherOccFromParen = null;
                            $fatherPhoneFromParen = preg_replace('/\D/u', '', $inner);
                        } else {
                            $fatherOccFromParen = $inner;
                        }
                    }
                    $fatherName = trim($fm[1]);
                } else {
                    $fatherName = trim(preg_replace('/\(.+\)/', '', $fn));
                }
                $fatherName = $fatherName === '' ? null : $fatherName;
            }
            $fatherName = $this->stripTrailingMobileFragmentFromPersonLine($fatherName);

            $motherName = $this->extractFieldAfterLabels($familyText, $motherLabels);
            $motherName = $motherName ?? $this->extractFieldAfterLabels($text, $motherLabels);
            $motherName = $motherName ?? $this->extractAfterLabelNextLine($text, 'आईचे नाव');
            $motherName = $motherName ?? $this->extractAfterLabelNextLine($text, 'आईचे नांव');
            $motherName = $motherName ?? $this->extractField($familyText, $motherLabels);
            $motherName = $motherName ?? (isset($romanized['mother_name']) ? $this->validateMotherName($this->cleanRomanizedName($romanized['mother_name'])) : null);
            $motherName = $this->rejectIfLabelNoise($motherName);
            $motherOccFromMotherLine = null;
            if ($motherName !== null && preg_match('/\(([^)]+)\)\s*$/u', trim($motherName), $momPm)) {
                $inner = $this->cleanValue(trim($momPm[1]));
                if ($inner !== '' && ! $this->isMaritalStatusOccupationLeak($inner)) {
                    if (preg_match('/^नोकरी\s*[\-–:]\s*([6-9]\d{9})$/u', $inner, $nokPhM)) {
                        $motherOccFromMotherLine = 'नोकरी';
                        $motherPhoneFromParen = $nokPhM[1];
                    } elseif (preg_match('/^[6-9]\d{9}$/', preg_replace('/\D/u', '', $inner))) {
                        $motherOccFromMotherLine = null;
                        $motherPhoneFromParen = preg_replace('/\D/u', '', $inner);
                    } else {
                        $motherOccFromMotherLine = $inner;
                    }
                }
                $motherName = trim(preg_replace('/\s*\([^)]+\)\s*$/u', '', trim($motherName)));
            }
            $motherName = $this->validateMotherName($motherName);
        }
        $confidence['father_name'] = $fatherName !== null ? self::CONF_DIRECT : self::CONF_MISSING;
        $confidence['mother_name'] = $motherName !== null ? self::CONF_DIRECT : self::CONF_MISSING;

        $brotherCount = $this->extractCount($familyText, ['भाऊ', 'बंधू', 'Brother', 'मुलाचा भाऊ', 'मुलाचे भाऊ'])
            ?? $this->extractCount($text, ['भाऊ', 'बंधू', 'Brother', 'मुलाचा भाऊ', 'मुलाचे भाऊ']);
        $sisterCount = $this->extractCount($familyText, ['बहिण', 'बहीण', 'Sister', 'मुलाची बहिण', 'मुलाची बहीण'])
            ?? $this->extractCount($text, ['बहिण', 'बहीण', 'Sister', 'मुलाची बहिण', 'मुलाची बहीण']);
        if ($sisterCount === null && preg_match('/मुलाची\s+(?:बहिण|बहीण)\s*[:\s_\-]*(?:नाही|No\b|None|०|0)\b/um', $text)) {
            $sisterCount = 0;
        }
        $confidence['brother_count'] = $brotherCount !== null ? self::CONF_REGEX : self::CONF_MISSING;
        $confidence['sister_count'] = $sisterCount !== null ? self::CONF_REGEX : self::CONF_MISSING;

        $fatherOccupation = null;
        $motherOccupation = $motherOccupationFromCombined;
        if (isset($motherOccFromMotherLine) && $motherOccFromMotherLine !== null) {
            $motherOccupation = $motherOccupation ?? $motherOccFromMotherLine;
        }

        // Focused Marathi family-core override pass: infer missing father/mother + counts from free-text context.
        $familyCoreOverride = $this->extractFamilyCore($text);
        if ($parentsFromCombinedLine === null && ($familyCoreOverride['father_name'] ?? null) !== null && $fatherName === null) {
            $fatherName = $familyCoreOverride['father_name'];
            $confidence['father_name'] = self::CONF_REGEX;
        }
        if ($parentsFromCombinedLine === null && ($familyCoreOverride['mother_name'] ?? null) !== null && $motherName === null) {
            $motherName = $familyCoreOverride['mother_name'];
            $confidence['mother_name'] = self::CONF_REGEX;
        }
        if ($parentsFromCombinedLine === null && ($familyCoreOverride['father_occupation'] ?? null) !== null) {
            $fatherOccupation = $fatherOccupation ?? \App\Services\AIParsingService::cleanOccupationLabel($familyCoreOverride['father_occupation']);
        }
        if ($parentsFromCombinedLine === null && ($familyCoreOverride['mother_occupation'] ?? null) !== null) {
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

        $siblingAvivahitAmbiguous = $this->isAmbiguousSiblingAvivahitLine($text);
        $siblingCountsLocked = $siblingAvivahitAmbiguous;
        if ($siblingCountsLocked) {
            // If the document explicitly declares counts (including "नाही"), keep those numeric signals.
            $explicitSiblingCountSignal = (bool) preg_match(
                '/(?:भाऊ|बहिण|बहीण|मुलाची\s+बहिण|मुलाची\s+बहीण|मुलाचा\s+भाऊ|मुलाचे\s+भाऊ)\s*[:\s_\-]*(?:\d+|एक|१|दोन|२|तीन|३|चार|४|पाच|५|सहा|६|नाही|०|0|None|No\b)/um',
                $text
            );
            if (! $explicitSiblingCountSignal) {
                $brotherCount = null;
                $sisterCount = null;
                $confidence['brother_count'] = self::CONF_MISSING;
                $confidence['sister_count'] = self::CONF_MISSING;
            }
        }
        // Explicit "बहिण :- नाही" style lines should map to zero even when sibling-count locking is enabled.
        if ($sisterCount === null && preg_match('/(?:मुलाची\s+)?(?:बहिण|बहीण)\s*[:\s_\-]*(?:नाही|None|No\b|०|0)\b/um', $text)) {
            $sisterCount = 0;
            $confidence['sister_count'] = self::CONF_REGEX;
        }

        $fatherName = $this->stripTrailingMobileFragmentFromPersonLine($fatherName);
        if ($parentsFromCombinedLine === null) {
            $fatherName = $this->takeFatherNameBeforeCommaMo($fatherName);
        }
        [$fatherFromLine, $fatherPhoneFromVadilLine] = $this->extractFatherNameAndPhoneFromVadilancheNaavLine($text);
        if ($parentsFromCombinedLine === null && $fatherFromLine !== null) {
            $fatherName = $fatherFromLine;
        }
        $fatherName = $this->validateFatherName($fatherName);

        // SSOT Day-30: extended biodata fields (additive); reject values that are known labels (wrong assignment)
        $birthTimeRaw = $this->rejectIfLabelNoise(
            $this->extractFieldAfterLabels($personalText, ['जन्म वार व वेळ', 'जन्मवार आणि वेळ', 'जन्म वार आणि वेळ', 'जन्मवेळ', 'जन्म वेळ', 'Birth time'])
                ?? $this->extractFieldAfterLabels($text, ['जन्म वार व वेळ', 'जन्मवार आणि वेळ', 'जन्म वार आणि वेळ', 'जन्मवेळ', 'जन्म वेळ', 'Birth time'])
                ?? $this->extractField($personalText, ['जन्म वार व वेळ', 'जन्मवार आणि वेळ', 'जन्म वार आणि वेळ', 'जन्म वेळ', 'जन्मवेळ', 'Birth time'])
                ?? $this->extractField($text, ['जन्म वार व वेळ', 'जन्मवार आणि वेळ', 'जन्म वार आणि वेळ', 'जन्म वेळ', 'जन्मवेळ', 'Birth time'])
        );
        // OCR sometimes keeps leading punctuation: ":- १ वा. २० मि." → normalize should see the time phrase only.
        if ($birthTimeRaw !== null) {
            $birthTimeRaw = trim((string) (preg_replace('/^[\s:;\-–—\.]+/u', '', (string) $birthTimeRaw) ?? $birthTimeRaw));
        }
        $birthTimeNorm = $this->normalizeBirthTime($birthTimeRaw);
        $birthTime = $birthTimeNorm ?? ($birthTimeRaw !== null && $this->looksLikeMarathiBirthTimePhrase($birthTimeRaw) ? trim($birthTimeRaw) : null);
        $birthPlace = $this->rejectIfLabelNoise(
            $this->extractField($personalText, ['जन्मस्थळ', 'जन्म स्थळ', 'जन्म ठिकाण', 'Birth place'])
                ?? $this->extractField($text, ['जन्मस्थळ', 'जन्म स्थळ', 'जन्म ठिकाण', 'Birth place'])
        );
        if ($birthPlace !== null && trim((string) $birthPlace) !== '') {
            $birthPlace = $this->truncateFieldBeforeInlineSectionLabels($birthPlace, [
                'वर्ण', 'गोत्र', 'शिक्षण', 'जन्म वार', 'जन्मवार', 'जन्म वेळ', 'जन्मवेळ', 'कुलस्वामी', 'कुलस्वामीनी', 'कुळस्वामी', 'नोकरी', 'व्यवसाय', 'पत्ता', 'रास', 'नक्षत्र',
            ]);
            $birthPlace = $this->rejectIfLabelNoise($birthPlace);
        }
        // Table row bleed: value cell empty → next column "वर्ण :- गोरा" wrongly captured as जन्म ठिकाण.
        if ($birthPlace !== null && preg_match('/^(?:वर्ण|गोत्र)\s*[:\-]/u', trim((string) $birthPlace))) {
            $birthPlace = null;
        }
        if ($birthPlace === null || trim((string) $birthPlace) === '') {
            if (preg_match('/जन्म\s*(?:ठिकाण|स्थळ)\s*[:\-]+\s*(.+?)(?=\s+(?:वर्ण|गोत्र|शिक्षण|जन्म|रास|नक्षत्र)\s*[:\-]|\R|$)/us', $text, $jbp)) {
                $birthPlace = $this->rejectIfLabelNoise($this->cleanValue(trim($jbp[1])));
            }
        }
        $gotra = $this->rejectIfLabelNoise(
            $this->extractFieldAfterLabels($personalText, ['गोत्र']) ?? $this->extractField($personalText, ['गोत्र']) ??
            $this->extractFieldAfterLabels($text, ['गोत्र']) ?? $this->extractField($text, ['गोत्र'])
        );
        $kuldaivat = $this->rejectIfLabelNoise(
            $this->extractFieldAfterLabels($personalText, ['कुलस्वामी', 'कुळस्वामी', 'कूळस्वामी', 'कुल दैवत', 'कुलदैवत', 'कलदैवत']) ?? $this->extractField($personalText, ['कुल दैवत', 'कुलदैवत', 'कुळस्वामी', 'कलदैवत']) ??
            $this->extractFieldAfterLabels($text, ['कुलस्वामी', 'कुळस्वामी', 'कूळस्वामी', 'कुल दैवत', 'कुलदैवत', 'कलदैवत']) ?? $this->extractField($text, ['कुल दैवत', 'कुलदैवत', 'कुळस्वामी', 'कलदैवत'])
        );
        if ($kuldaivat !== null && trim((string) $kuldaivat) !== '') {
            $kuldaivat = $this->truncateFieldBeforeInlineSectionLabels($kuldaivat, [
                'नक्षत्र', 'नाडी', 'रास', 'गण', 'गोत्र', 'योनी', 'वर्ण', 'रक्त', 'रक्तगट', 'रक्त गट',
            ]);
        }
        $rashi = $this->rejectIfLabelNoise($this->extractFieldAfterLabels($horoscopeText, ['रास', 'राशी']) ?? $this->extractField($horoscopeText, ['रास', 'टाशी', 'राशी']) ?? $this->extractField($text, ['रास', 'टाशी', 'राशी']));
        $rashi = $this->truncateFieldBeforeInlineSectionLabels($rashi, ['वर्ण', 'गोत्र', 'नक्षत्र', 'योनी', 'गण', 'कुलस्वामी', 'कुलस्वामीनी', 'नाड', 'देवक', 'रक्त']);
        $rashi = $rashi !== null && trim((string) $rashi) !== '' ? self::sanitizeRashiDisplayText($rashi) : null;
        $nakshatra = $this->rejectIfLabelNoise($this->extractFieldAfterLabels($horoscopeText, ['नक्षत्र']) ?? $this->extractFieldAfterLabels($text, ['नक्षत्र']) ?? $this->extractField($horoscopeText, ['नक्षत्र']) ?? $this->extractField($text, ['नक्षत्र']));
        $nakshatra = $this->truncateFieldBeforeInlineSectionLabels($nakshatra, ['वर्ण', 'गोत्र', 'योनी', 'गण', 'कुलस्वामी', 'रास', 'नाड', 'देवक']);
        $nadiRaw = $this->rejectIfLabelNoise($this->extractField($horoscopeText, ['नाडी', 'नाड २', 'नाड']) ?? $this->extractField($text, ['नाडी', 'नाड २', 'नाड']));
        $charan = $this->rejectIfLabelNoise(
            $this->extractFieldAfterLabels($horoscopeText, ['चरण']) ?? $this->extractFieldAfterLabels($text, ['चरण'])
                ?? $this->extractField($horoscopeText, ['चरण']) ?? $this->extractField($text, ['चरण'])
        );
        if (is_string($charan) && $charan !== '') {
            // Keep common Marathi display form for charan ordinal (e.g. "१ ले", "२ रे") even after digit normalization.
            $charan = preg_replace_callback('/^([1-4])\s*/u', function ($m) {
                return match ($m[1]) {
                    '1' => '१ ',
                    '2' => '२ ',
                    '3' => '३ ',
                    '4' => '४ ',
                    default => $m[0],
                };
            }, trim($charan)) ?? $charan;
            $charan = trim((string) $charan);
        }
        $nadi = $nadiRaw !== null ? $this->normalizeNadiValue($nadiRaw) : null;
        $yoni = $this->rejectIfLabelNoise($this->extractFieldAfterLabels($horoscopeText, ['योनी']) ?? $this->extractFieldAfterLabels($text, ['योनी']) ?? $this->extractField($horoscopeText, ['योनी']) ?? $this->extractField($text, ['योनी']));
        $this->applyRashiYoniCompositeSplit($rashi, $yoni);
        // Prefer label-style "गण :- value" so we don't capture text after "गण" in "गणपती" (मामा line)
        $gan = $this->rejectIfLabelNoise($this->extractFieldAfterLabels($horoscopeText, ['गण']) ?? $this->extractFieldAfterLabels($text, ['गण']) ?? $this->extractField($horoscopeText, ['गण']) ?? $this->extractField($text, ['गण']));
        $gan = self::sanitizeGanValue($gan);
        $mangalik = $this->rejectIfLabelNoise($this->extractField($text, ['मांगलिक']));
        // "वर्ण" ओळीतला raw value दोन use-cases साठी वापरतो:
        // 1) Physical complexion (raw 그대로, e.g. "गोरा")
        // 2) Horoscope varna (canonical keys via validateVarna)
        $varnaRaw = $this->extractFieldAfterLabels($personalText, ['वर्ण']) ?? $this->extractField($personalText, ['वर्ण'])
            ?? $this->extractFieldAfterLabels($text, ['वर्ण']) ?? $this->extractField($text, ['वर्ण']);
        $complexion = $varnaRaw !== null ? trim((string) $varnaRaw) : null;
        if ($complexion !== null && $complexion !== '') {
            $complexion = trim(preg_replace('/,\s*$/u', '', $complexion) ?? '');
        }
        $complexion = $this->rejectBleededPhysicalComplexion($complexion);
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
        $gotra = $this->balanceTrailingParenthesesInGotra($gotra);
        $kuldaivat = $this->rejectHoroscopeJunk($kuldaivat);
        $kuldaivat = $this->balanceTrailingParenthesesInGotra($kuldaivat);
        $kulName = $this->rejectHoroscopeJunk($kulName);
        $devak = $this->rejectHoroscopeJunk($devak);
        $navrasName = $this->rejectIfLabelNoise(
            $this->extractField($horoscopeText, ['नावरसनांव', 'नावरस नाव', 'नवरस नाव', 'नांवटस नाव', 'Navras']) ??
            $this->extractField($text, ['नावरसनांव', 'नावरस नाव', 'नवरस नाव', 'नांवटस नाव', 'Navras'])
        );
        // Navras name can be very short (e.g. "पे"); extractField()->cleanValue() may drop <3-char Marathi tokens.
        if (($navrasName === null || trim((string) $navrasName) === '')
            && preg_match('/(?:नावरसनांव|नावरस\s+नाव|नवरस\s+नाव|Navras)\s*[:\-]+\s*([^\n]{1,30})/ui', $text, $nm)) {
            $cand = trim((string) ($nm[1] ?? ''));
            $cand = preg_split('/\s+(?:वर्ण|रास|राशी|नक्षत्र|योनी|नाडी|गण)\s*[:\-]/u', $cand, 2)[0] ?? $cand;
            $cand = trim((string) $cand);
            $navrasName = $cand !== '' ? $cand : null;
        }
        if (is_string($navrasName) && $navrasName !== '') {
            $navrasName = self::stripResidualHtmlTagsFromString($navrasName);
            $navrasName = $navrasName === '' ? null : $navrasName;
        }
        [$navrasName, $rashiFromNavrasLine] = $this->refineNavrasAndRashiFromComposite($navrasName);
        if (($rashi === null || trim((string) $rashi) === '') && $rashiFromNavrasLine !== null) {
            $rashi = $rashiFromNavrasLine;
        }
        $birthWeekday = $this->rejectIfLabelNoise(
            $this->extractField($horoscopeText, ['जन्मवार', 'जन्म वार', 'Birth day']) ??
            $this->extractField($text, ['जन्मवार', 'जन्म वार', 'Birth day'])
        );
        [$birthWeekday, $birthTime] = $this->refineBirthWeekdayTimeComposite($text, $birthWeekday, $birthTime);
        if (is_string($birthTime) && $birthTime !== '') {
            $birthTime = trim((string) preg_replace('/^[\s\-–—•*]+/u', '', $birthTime));
        }
        $birthWeekday = $this->sanitizeBirthWeekdayField($birthWeekday);
        // Horoscope varna साठी फक्त canonical values ठेवतो; physical complexion साठी वरचं $complexion raw जतन केले आहे.
        // Prefer horoscope block "वर्ण" over personal complexion.
        $horoscopeVarnaRaw = $this->extractFieldAfterLabels($horoscopeText, ['वर्ण']) ?? $this->extractField($horoscopeText, ['वर्ण'])
            ?? $this->extractFieldAfterLabels($text, ['वर्ण']) ?? $this->extractField($text, ['वर्ण']);
        $varnaCandidate = $horoscopeVarnaRaw !== null ? trim(preg_replace('/,\s*$/u', '', trim((string) $horoscopeVarnaRaw)) ?? '') : null;
        $varna = $this->validateVarna($varnaCandidate === '' ? null : $varnaCandidate);
        if ($varna === null && $varnaRaw !== null) {
            $fallbackVarnaCandidate = trim(preg_replace('/,\s*$/u', '', trim((string) $varnaRaw)) ?? '');
            $varna = $this->validateVarna($fallbackVarnaCandidate === '' ? null : $fallbackVarnaCandidate);
        }
        if ($varna === null && preg_match('/वर्ण\s*[:\-]+\s*([^\n]+)/u', $text, $vm)) {
            $chunk = trim((string) (preg_split('/\s+(?:रास|नक्षत्र|योनी|नाडी|गण|चरण|वश्य|रक्त|इतर\s+नातेवाईक)\s*[:\-]/u', trim($vm[1]))[0] ?? ''));
            $chunk = trim((string) preg_replace('/,\s*$/u', '', $chunk));
            $varna = $this->validateVarna($chunk !== '' ? $chunk : null);
        }

        // Additive fallback: parse combined horoscope lines when individual labels missed pieces.
        $this->applyCombinedHoroscopeFallbacks(
            $horoscopeText,
            $text,
            $rashi,
            $nadi,
            $gan
        );
        $bloodGroupRawComposite = null;
        $this->applyCompositeHoroscopeSegmentOverrides($text, $devak, $rashi, $nakshatra, $nadi, $gan, $charan, $bloodGroupRawComposite);
        $gan = self::sanitizeGanValue($gan);
        if ($parentsFromCombinedLine === null || $motherOccupation === null) {
            $motherOccupation = $motherOccupation ?? $this->extractField($familyText, ['आईचा व्यवसाय', 'माता व्यवसाय']) ?? $this->extractField($text, ['आईचा व्यवसाय']);
        }
        $fatherOccFromField = $this->extractField($familyText, ['वडिलांचे व्यवसाय', 'पिता व्यवसाय']) ?? $this->extractField($text, ['वडिलांचे व्यवसाय']);
        if ($fatherOccFromField !== null && trim((string) $fatherOccFromField) !== '') {
            $fatherOccupation = \App\Services\AIParsingService::cleanOccupationLabel($fatherOccFromField);
        }
        if (($fatherOccupation === null || trim((string) $fatherOccupation) === '') && $fatherOccFromParen !== null) {
            $fatherOccupation = $fatherOccFromParen;
        }
        $mama = $this->extractAfterLabelMultiline($familyText, 'मामा')
            ?? $this->extractAfterLabelMultiline($text, 'मामा')
            ?? $this->extractField($familyText, ['मामा']) ?? $this->extractField($text, ['मामा']);
        $mama = $this->rejectIfLabelNoise($mama);
        $mama = $this->sanitizeCoreMamaField($mama);
        $relatives = $this->rejectIfLabelNoise($this->extractField($familyText, ['इतर पाहुणे', 'नातेसंबंध']) ?? $this->extractField($text, ['इतर पाहुणे', 'नातेसंबंध']));

        $gender = $this->extractExplicitGender($text) ?? ($romanized['gender'] ?? null);
        if ($gender === null) {
            $gender = $this->inferGender($text);
        }
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
            if (preg_match('/हिंद[ुू]\s*-\s*([0-9०-९]+\s*'.$kuliPattern.')\s*(मराठा)?/u', $casteRaw, $m)) {
                $subCaste = trim(preg_replace('/(?:क्‌ळी|क[\x{094D}\x{200C}\s]*ळी|कळी)/u', 'कुळी', $m[1]));
                $caste = 'मराठा';
                $religion = $religion ?? 'हिंदु';
            } elseif (preg_match('/हिंद[ुू]\s*[-]?\s*([0-9०-९]+\s*'.$kuliPattern.')\s*मराठा/u', $casteRaw, $m)) {
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
            $subCaste = $subCaste ?? ($m[1].' कुळी');
        }
        if (($religion === null || $caste === null) && (preg_match('/हिंद[ुू]\s*[-–]?\s*(?:[०-९0-9]+\s*क[\x{094D}\x{200C}\s]*(?:ुळी|ळी)\s*)?मराठा/u', $text) || preg_match('/हिंद[ुू]\s*[-–]?\s*मराठा/u', $text) || (mb_strpos($text, 'हिंदु') !== false && mb_strpos($text, 'मराठा') !== false))) {
            $religion = $religion ?? 'हिंदू';
            $caste = $caste ?? 'मराठा';
            if ($subCaste === null && preg_match('/([०-९0-9]+)\s*(?:कुळी|क्‌ळी|क[\x{094D}\x{200C}\s]*ळी|कळी)/u', $text, $m)) {
                $subCaste = $m[1].' कुळी';
            }
        }

        // When जात line is missing in OCR: infer from कुलस्वामी/माणकेश्वर/तुळजाभवानी + Maratha surname (भोसले).
        if (($religion === null || $caste === null) && (
            mb_strpos($text, 'कुलस्वामी') !== false || mb_strpos($text, 'माणकेश्वर') !== false || mb_strpos($text, 'तुळजाभवानी') !== false || mb_strpos($text, 'तुळनापूर') !== false
        ) && (mb_strpos($text, 'भोसले') !== false || mb_strpos($text, 'मराठा') !== false)) {
            $religion = $religion ?? 'हिंदू';
            $caste = $caste ?? 'मराठा';
            if ($subCaste === null && preg_match('/([०-९0-9]+)\s*(?:कुळी|क्‌ळी|कळी)/u', $text, $m)) {
                $subCaste = $m[1].' कुळी';
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
            } elseif (preg_match('/कु(?:लदेवत|ळदेवत|दुलंदेवल|देवल)/u', $text)) {
                // Common Marathi biodata line (OCR often garbles "कुलदेवता"); Hindu context, no caste inference.
                $religion = 'हिंदू';
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
            $salaryRaw = $m[1].' Lac';
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
        if ($annualIncome === null) {
            $nokariLineForSalary = $this->extractFieldAfterLabels($text, ['नोकरी/व्यवसाय', 'नोकरी', 'व्यवसाय'])
                ?? $this->extractAfterLabelMultiline($text, 'नोकरी');
            $nokSal = $this->splitNokariLineCompanyAndSalary($nokariLineForSalary);
            if ($nokSal['salary_paren'] !== null) {
                $salaryFromNok = $this->normalizeSalary($nokSal['salary_paren']);
                if (isset($salaryFromNok['annual_lakh_float']) && $salaryFromNok['annual_lakh_float'] > 0) {
                    $annualIncome = (int) round($salaryFromNok['annual_lakh_float'] * 100000);
                } elseif (! empty($salaryFromNok['annual_lakh'])) {
                    $annualIncome = (int) $salaryFromNok['annual_lakh'] * 100000;
                } elseif (! empty($salaryFromNok['annual_raw'])) {
                    $annualIncome = (int) $salaryFromNok['annual_raw'];
                } elseif (! empty($salaryFromNok['monthly'])) {
                    $annualIncome = (int) $salaryFromNok['monthly'] * 12;
                }
            }
        }
        $confidence['annual_income'] = $annualIncome !== null ? self::CONF_DIRECT : self::CONF_MISSING;

        $relativePhoneExclude = array_flip($this->collectRelativeEmbeddedPhones($text));
        $fatherContactPhone = $this->extractFatherContactNumberFromText($text);
        if ($fatherPhoneFromVadilLine !== null) {
            $fatherContactPhone = $fatherContactPhone ?? $fatherPhoneFromVadilLine;
        }
        $fatherContactPhone2 = null;
        $this->applyMobaLinePhonesToFatherContactSlots($text, $fatherContactPhone, $fatherContactPhone2);
        if ($fatherContactPhone !== null) {
            $relativePhoneExclude[$fatherContactPhone] = true;
        }
        if ($fatherContactPhone2 !== null) {
            $relativePhoneExclude[$fatherContactPhone2] = true;
        }
        foreach (array_keys($this->collectSuchakHeaderPhonesToExclude($text)) as $sp) {
            $relativePhoneExclude[$sp] = true;
        }
        // Explicit contact label: safe to capture without guessing (do not use footer-only numbers).
        $primaryContact = null;
        $confidence['primary_contact_number'] = self::CONF_MISSING;
        // Keep this narrow: only set biodata primary when an explicit "संपर्क नंबर" label exists.
        $contactRaw = $this->extractAfterLabelMultiline($text, 'संपर्क नंबर')
            ?? $this->extractFieldAfterLabels($text, ['संपर्क नंबर']);
        if (is_string($contactRaw) && trim($contactRaw) !== '') {
            $contactRaw = $this->truncateFieldBeforeInlineSectionLabels($contactRaw, [
                'शिक्षण', 'रक्त', 'वर्ण', 'गोत्र', 'रास', 'नक्षत्र', 'नाडी', 'गण', 'जन्म', 'पत्ता', 'स्थावर', 'स्थायिक', 'अपेक्षा',
            ]);
            $candidateDigits = \App\Services\Ocr\OcrNormalize::normalizeDigits($contactRaw) ?? $contactRaw;
            if (preg_match('/\b([6-9]\d{9})\b/u', (string) $candidateDigits, $cm)) {
                $digits10 = $cm[1];
                if (! $this->phoneDigitsAppearOnlyOnFooterOrShopLines($text, $digits10)) {
                    $primaryContact = $digits10;
                    $confidence['primary_contact_number'] = self::CONF_DIRECT;
                    $relativePhoneExclude[$digits10] = true;
                }
            }
        }

        $bloodGroupRaw = $this->extractFieldAfterLabels($text, ['रक्तगट', 'रक्‍त गट', 'रक्त गट', 'Blood group'])
            ?? $this->extractField($text, ['रक्तगट', 'रक्‍त गट', 'रक्त गट', 'Blood group']);
        // OCR often outputs "रक्तगट" (no space) on its own line; tight extractField can still miss.
        if (($bloodGroupRaw === null || trim((string) $bloodGroupRaw) === '')
            && preg_match('/रक्तगट\s*[:\-]+\s*([^\n]+)/u', $text, $bgGlue)) {
            $bloodGroupRaw = $this->cleanValue(trim($bgGlue[1]));
        }
        if ($bloodGroupRawComposite !== null && trim((string) $bloodGroupRawComposite) !== '') {
            $bloodGroupRaw = $bloodGroupRawComposite;
        }
        if (($bloodGroupRaw === null || trim((string) $bloodGroupRaw) === '')
            && preg_match('/(?:रक्त\s*गट|रक्तगट|रक[\x{094D}\x{200C}\s]*त\s*गट)\s*[:\-]+\s*([^\n]+)/u', $text, $bgm)) {
            $bloodGroupRaw = $this->cleanValue(trim($bgm[1]));
        }
        // Line-level fallback when full-text match is polluted by table OCR / duplicate labels.
        if (($bloodGroupRaw === null || trim((string) $bloodGroupRaw) === '')) {
            foreach (preg_split('/\R/u', $text) ?: [] as $ln) {
                $ln = trim((string) $ln);
                if ($ln === '' || mb_strpos($ln, 'रक्त') === false) {
                    continue;
                }
                if (preg_match('/(?:रक्त\s*गट|रक्तगट|रक[\x{094D}\x{200C}\s]*त\s*गट)\s*[:\-]+\s*(.+)$/u', $ln, $lm)) {
                    $bloodGroupRaw = $this->cleanValue(trim($lm[1]));
                    break;
                }
            }
        }
        // Table OCR: label and "O+ve" may not bind cleanly to extractField; scan near "रक्त" on the same line.
        if (($bloodGroupRaw === null || trim((string) $bloodGroupRaw) === '')
            && preg_match('/रक्त[^\n]{0,80}?(O\s*\+(?:ve)?|A\s*\+(?:ve)?|B\s*\+(?:ve)?|AB\s*\+(?:ve)?|O\s*\-|A\s*\-|B\s*\-|AB\s*\-)/ui', $text, $bn)) {
            $bloodGroupRaw = trim($bn[1]);
        }
        if ($bloodGroupRaw === null && preg_match('/([ABO])\s*[\+\-]?\s*(?:\+ve|\+|\-ve|-)/ui', $text, $m)) {
            $bloodGroupRaw = $m[0];
        }
        // 4-column table rows: "रक्तगट :- O+ve नाडी :- …" — strip tail labels before normalize
        if (is_string($bloodGroupRaw) && $bloodGroupRaw !== '') {
            $bloodGroupRaw = trim((string) preg_replace(
                '/\s+(?:नाडी|रास|नक्षत्र|गण|योनी|गोत्र|कुलस्वामी|कुलस्वामीनी|वर्ण|उंची|ऊंची)\s*[:\-].*$/u',
                '',
                $bloodGroupRaw
            ));
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

        $addressBlock = $this->extractAddressBlock($text, $text);
        $residentialAddressLine = $this->extractResidentialAddressFromText($text);
        if ($residentialAddressLine !== null && $addressBlock !== null
            && trim(preg_replace('/\s+/u', ' ', $residentialAddressLine)) === trim(preg_replace('/\s+/u', ' ', $addressBlock))) {
            $residentialAddressLine = null;
        }
        $nativePlaceRaw = $this->extractFieldAfterLabels($text, ['मूळचा पत्ता', 'मुळचा पत्ता'])
            ?? $this->extractField($text, ['मूळचा पत्ता', 'मुळचा पत्ता']);
        $nativePlaceRaw = $this->truncateFieldBeforeInlineSectionLabels($nativePlaceRaw, [
            'इतर नातेवाईक', 'नक्षत्र', 'रास', 'चरण', 'वर्ण', 'संपर्क', 'स्थावर', 'स्थायिक', 'अपेक्षा', 'प्रिंट',
        ]);
        $nativePlaceRaw = $nativePlaceRaw !== null && trim((string) $nativePlaceRaw) !== ''
            ? $this->finalizeAddressBlockCandidate(trim((string) $nativePlaceRaw)) : null;
        $confidence['address'] = $addressBlock !== null ? self::CONF_REGEX : self::CONF_MISSING;

        // Post-process mother name to split inline occupation like "(गृहिणी)" into a dedicated field.
        if ($motherName !== null && $motherOccupation === null && preg_match('/\((.+?)\)/u', $motherName, $mOcc)) {
            $maybeOcc = $this->cleanValue($mOcc[1]);
            if ($maybeOcc !== '' && ! $this->isMaritalStatusOccupationLeak($maybeOcc)) {
                $motherOccupation = $maybeOcc;
            }
            $motherName = trim(preg_replace('/\(.+?\)/u', '', $motherName));
        }

        // Strip honorifics from stored parent names for core snapshot.
        $fatherName = $fatherName !== null ? $this->cleanPersonName($fatherName) : null;
        $motherName = $motherName !== null ? $this->cleanPersonName($motherName) : null;

        // Remove occupation label artifacts (नोकरी :-, नोकरी >, etc.) from father_occupation
        $fatherOccupation = $fatherOccupation !== null ? \App\Services\AIParsingService::cleanOccupationLabel($fatherOccupation) : null;
        $motherOccupation = $this->sanitizeCoreOccupation($motherOccupation);
        $fatherOccupation = $this->sanitizeCoreOccupation($fatherOccupation);

        $fatherMuPoAddressLine = $this->extractFatherMuPoAddressLineAfterFatherName($text);

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
            'father_contact_1' => $fatherContactPhone,
            'father_contact_2' => $fatherContactPhone2,
            'father_extra_info' => $fatherMuPoAddressLine,
            'company_name' => null,
            'work_location_text' => null,
            'occupation_title' => null,
            'serious_intent_id' => null,
            'father_name' => $fatherName,
            'mother_name' => $motherName,
            'brother_count' => $brotherCount,
            'sister_count' => $sisterCount,
            'has_siblings' => null,
            'height' => $heightDisplay,
            'height_cm' => $heightCm,
            // Physical complexion raw text (e.g. "गोरा"); preview/wizard मध्ये MasterComplexion शी map होते.
            'complexion' => $complexion,
            'highest_education' => $education,
            'birth_time' => $birthTime,
            'blood_group' => $bloodGroup,
            'birth_place' => $birthPlace,
            'birth_place_text' => $birthPlace,
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
        // Explicit "बहिण :- नाही" should always map to 0 (do not leave null).
        if (($core['sister_count'] ?? null) === null
            && preg_match('/(?:मुलाची\s+)?(?:बहिण|बहीण)\s*[:\s_\-]*(?:नाही|None|No\b|०|0)\b/um', $text)) {
            $core['sister_count'] = 0;
        }
        if ($addressBlock !== null && ($core['address_line'] ?? null) === null) {
            $core['address_line'] = $addressBlock;
        }

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
        $nokariLineCareer = $this->extractFieldAfterLabels($text, ['नोकरी/व्यवसाय', 'नोकटी/व्यवसाय'])
            ?? $this->extractCareerFieldPreferFirstDocumentLine($text)
            ?? $this->extractField($text, ['नोकरी/व्यवसाय', 'नोकटी/व्यवसाय', 'व्यवसाय', 'नोकरी', 'Profession', 'Occupation']);
        $nokariLineCareer = $this->truncateFieldBeforeInlineSectionLabels($nokariLineCareer, [
            'गोत्र', 'वर्ण', 'रास', 'नक्षत्र', 'कुलस्वामी', 'कुलस्वामीनी', 'शिक्षण', 'जन्म', 'पत्ता',
        ]);
        // Same-line bleed: "व्यवसाय :- वकील गोत्र :- …" when truncate misses tight spacing.
        if (is_string($nokariLineCareer) && preg_match('/गोत्र\s*[:\-]/u', $nokariLineCareer)) {
            $nokariLineCareer = trim((string) (preg_replace('/\s+गोत्र\s*[:\-].*$/us', '', $nokariLineCareer) ?? $nokariLineCareer));
            $nokariLineCareer = $nokariLineCareer !== '' ? $nokariLineCareer : null;
        }
        $nokCareerSplit = $this->splitNokariLineCompanyAndSalary($nokariLineCareer);
        $profession = $nokCareerSplit['role_title'] ?? null;
        $companyNameFromNokari = $nokCareerSplit['company_name'];
        $workLocationFromNokari = $nokCareerSplit['location'] ?? null;
        $profession = $profession ?? $romanized['career'] ?? null;
        $profession = $this->validateCareer($profession);
        if ($profession !== null && $this->valueSmellsLikeHoroscopeOrGotraLeak($profession)) {
            $profession = null;
        }
        if ($profession === null && $companyNameFromNokari !== null) {
            $profession = $this->validateCareer($companyNameFromNokari);
        }
        $careerHistory = [];
        if ($profession !== null) {
            $careerHistory = [[
                'role' => $profession,
                'occupation_title' => $profession,
                'job_title' => $profession,
                'employer' => $companyNameFromNokari,
                'company_name' => $companyNameFromNokari,
                'location' => is_string($workLocationFromNokari) && trim($workLocationFromNokari) !== '' ? trim($workLocationFromNokari) : null,
                'from' => null,
                'to' => null,
            ]];
            $confidence['career'] = self::CONF_DIRECT;
        } elseif ($companyNameFromNokari !== null && trim((string) $companyNameFromNokari) !== '') {
            // Employer/company explicit without a separate job title — keep company + location only (no fake role).
            $careerHistory = [[
                'role' => null,
                'occupation_title' => null,
                'job_title' => null,
                'employer' => $companyNameFromNokari,
                'company_name' => $companyNameFromNokari,
                'location' => is_string($workLocationFromNokari) && trim($workLocationFromNokari) !== '' ? trim($workLocationFromNokari) : null,
                'from' => null,
                'to' => null,
            ]];
            $confidence['career'] = self::CONF_REGEX;
        } elseif ($gender === 'female') {
            $careerHistory = [];
            $confidence['career'] = self::CONF_MISSING;
        } else {
            $confidence['career'] = self::CONF_MISSING;
        }

        // ——— EDUCATION HISTORY ———
        $educationHistory = [];
        if ($education !== null) {
            $educationHistory = [['degree' => $this->stripTrailingEducationNoise($education), 'institution' => null, 'year' => null]];
        }

        // ——— CONTACTS (no biodata primary; numbers only as non-primary alternates for reference) ———
        $contacts = [];
        foreach (array_filter([$fatherPhoneFromParen ?? null, $motherPhoneFromParen ?? null]) as $parenPhone) {
            $norm = \App\Services\Ocr\OcrNormalize::normalizePhone((string) $parenPhone);
            if ($norm !== null && ! isset($relativePhoneExclude[$norm])) {
                $contacts[] = [
                    'type' => 'alternate',
                    'label' => 'parent',
                    'number' => $norm,
                    'phone_number' => $norm,
                    'relation_type' => '',
                    'contact_name' => '',
                    'is_primary' => false,
                ];
                $relativePhoneExclude[$norm] = true;
            }
        }
        if (preg_match_all('/\b([6-9]\d{9})\b/u', $text, $allMatches)) {
            $seen = [];
            foreach (array_unique($allMatches[1]) as $num) {
                $normKey = \App\Services\Ocr\OcrNormalize::normalizePhone($num) ?? $num;
                if (isset($relativePhoneExclude[$normKey])) {
                    continue;
                }
                if ($this->phoneDigitsAppearOnlyOnFooterOrShopLines($text, (string) $num)) {
                    continue;
                }
                $normalized = \App\Services\Ocr\OcrNormalize::normalizePhone($num);
                if ($normalized !== null && ! isset($seen[$normalized])) {
                    $seen[$normalized] = true;
                    $contacts[] = [
                        'type' => 'alternate',
                        'label' => 'other',
                        'number' => $normalized,
                        'phone_number' => $normalized,
                        'relation_type' => '',
                        'contact_name' => '',
                        'is_primary' => false,
                    ];
                }
            }
        }
        // If we have an explicit primary contact from biodata, include it as a primary self contact.
        if ($primaryContact !== null) {
            $seen = [];
            foreach ($contacts as $c) {
                $k = \App\Services\Ocr\OcrNormalize::normalizePhone((string) ($c['number'] ?? $c['phone_number'] ?? '')) ?? '';
                if ($k !== '') {
                    $seen[$k] = true;
                }
            }
            if (! isset($seen[$primaryContact])) {
                array_unshift($contacts, [
                    'type' => 'self',
                    'label' => 'self',
                    'number' => $primaryContact,
                    'phone_number' => $primaryContact,
                    'relation_type' => '',
                    'contact_name' => '',
                    'is_primary' => true,
                ]);
            }
        }

        // If no dedicated mobile row exists, but an address/family line contains a valid mobile, keep it as a contact.
        if (! isset($tableStructuredHints['primary_contact']) || trim((string) ($tableStructuredHints['primary_contact'] ?? '')) === '') {
            $addrPhone = $this->extractSelfPhoneFromAddressContext($text);
            if ($addrPhone !== null && ! $this->phoneDigitsAppearOnlyOnFooterOrShopLines($text, $addrPhone)) {
                $seen = [];
                foreach ($contacts as $c) {
                    $k = \App\Services\Ocr\OcrNormalize::normalizePhone((string) ($c['number'] ?? $c['phone_number'] ?? '')) ?? '';
                    if ($k !== '') {
                        $seen[$k] = true;
                    }
                }
                if (! isset($seen[$addrPhone])) {
                    $contacts[] = [
                        'type' => 'alternate',
                        'label' => 'self',
                        'number' => $addrPhone,
                        'phone_number' => $addrPhone,
                        'relation_type' => '',
                        'contact_name' => '',
                        'is_primary' => false,
                    ];
                }
            }
        }

        // ——— OPTIONAL: Property ———
        $propertySummary = null;
        $propertyAssets = [];
        if ($this->hasAnyKeyword($text, self::PROPERTY_TRIGGER)) {
            $propertySummary = $this->extractPropertySummaryBlock($text);
            $propertySummary = $propertySummary ?? $this->extractField($text, ['स्थावर मिळकत', 'स्थायिक मालमत्ता', 'शेती', 'जमीन', 'Property']);
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
            || $birthWeekday !== null
            || ($charan !== null && trim((string) $charan) !== '');

        if ($hasAnyHoroscopeText) {
            $row = [
                // ID fields are intentionally left null here; ControlledOptionNormalizer +
                // MutationService will resolve canonical keys / IDs later in the pipeline.
                'rashi_id' => null,
                'nakshatra_id' => null,
                'gan_id' => null,
                'nadi_id' => null,
                'yoni_id' => null,
                'mangal_dosh_type_id' => null,
                // Preserve sanitized free-text so intake snapshot normalizer can map safely.
                'rashi' => $this->rejectHoroscopeJunk($rashi),
                'nakshatra' => $this->rejectHoroscopeJunk($nakshatra),
                'charan' => $charan !== null && trim((string) $charan) !== '' ? trim((string) $charan) : null,
                'nadi' => $this->rejectHoroscopeJunk($nadi),
                'gan' => self::sanitizeGanValue($gan),
                'yoni' => $this->rejectHoroscopeJunk($yoni),
                // Textual attributes — kuldaivat = कुलदैवत / kuldevta / कुलदेवता / कुलस्वामी / कूळस्वामी.
                'devak' => $devak ?? $kuldaivat,
                'kuldaivat' => $kuldaivat,
                'gotra' => $gotra,
                'navras_name' => $navrasName,
                'birth_weekday' => $birthWeekday,
                'varna' => $varna,
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

        // ——— FAMILY STRUCTURES: siblings, relatives, and refined career roles ———
        $tableHasOtherRelativesRow = isset($tableStructuredHints['other_relatives_text'])
            && trim((string) $tableStructuredHints['other_relatives_text']) !== '';
        $familyStructures = $this->extractFamilyStructures(
            $this->expandMamaChulteOnSameLine($text),
            $siblingCountsLocked,
            $tableHasOtherRelativesRow
        );

        // Prefer more precise sibling counts derived from structured rows when available (unless locked).
        $siblings = $familyStructures['siblings'] ?? [];
        if ($siblingCountsLocked) {
            $siblings = [];
        }
        if (! $siblingCountsLocked && ! empty($siblings)) {
            $brothers = array_filter($siblings, fn ($row) => ($row['relation_type'] ?? null) === 'brother');
            $sisters = array_filter($siblings, fn ($row) => ($row['relation_type'] ?? null) === 'sister');
            $brotherCount = count($brothers);
            $sisterCount = count($sisters);
            $core['brother_count'] = $brotherCount;
            $core['sister_count'] = $sisterCount;
        }

        if ($siblingCountsLocked) {
            $core['brother_count'] = null;
            $core['sister_count'] = null;
            $core['has_siblings'] = null;
        } elseif (! empty($siblings)) {
            $core['has_siblings'] = true;
        }

        // Father occupation from family structures if not already filled.
        if (($familyStructures['core_overrides']['father_occupation'] ?? null) !== null && ($core['father_occupation'] ?? null) === null) {
            $core['father_occupation'] = $this->sanitizeCoreOccupation($familyStructures['core_overrides']['father_occupation']);
        }

        // Mother occupation/name refined from family structures if provided.
        if (($familyStructures['core_overrides']['mother_occupation'] ?? null) !== null && ($core['mother_occupation'] ?? null) === null) {
            $core['mother_occupation'] = $this->sanitizeCoreOccupation($familyStructures['core_overrides']['mother_occupation']);
        }
        $htmlWillSupplyMotherName = isset($tableStructuredHints['mother_name'])
            && trim((string) $tableStructuredHints['mother_name']) !== '';
        if (! $htmlWillSupplyMotherName && ($familyStructures['core_overrides']['mother_name'] ?? null) !== null) {
            $core['mother_name'] = $familyStructures['core_overrides']['mother_name'];
        }

        // Career split: if we have a candidate-only career history from familyStructures, prefer it.
        if (! empty($familyStructures['career_history'] ?? [])) {
            $careerHistory = $familyStructures['career_history'];
        }

        if (! empty($careerHistory)) {
            if (! empty($careerHistory[0]['company_name'])) {
                $core['company_name'] = $careerHistory[0]['company_name'];
            } elseif (! empty($careerHistory[0]['company'])) {
                $core['company_name'] = $careerHistory[0]['company'];
            }
            if (empty($core['work_location_text']) && ! empty($careerHistory[0]['location'])) {
                $core['work_location_text'] = is_string($careerHistory[0]['location']) ? trim((string) $careerHistory[0]['location']) : null;
            } elseif (empty($core['work_location_text']) && ! empty($careerHistory[0]['work_location_text'])) {
                $core['work_location_text'] = is_string($careerHistory[0]['work_location_text']) ? trim((string) $careerHistory[0]['work_location_text']) : null;
            }
            $core['occupation_title'] = $careerHistory[0]['occupation_title']
                ?? $careerHistory[0]['job_title']
                ?? $careerHistory[0]['role']
                ?? null;
        }

        $relativesRows = $familyStructures['relatives'] ?? [];
        // Legacy core.relatives is a noisy summary string; when structured relatives[] exist, drop core.relatives.
        if (! empty($relativesRows)) {
            $core['relatives'] = null;
        }

        if (! $tableHasOtherRelativesRow
            && ! empty($familyStructures['other_relatives_text'] ?? null)
            && is_string($familyStructures['other_relatives_text'])) {
            $core['other_relatives_text'] = $familyStructures['other_relatives_text'];
        }

        $htmlStructuredFieldLocks = [];

        // Structured-first: HTML table is final truth; separated layout fills only gaps (respect locks).
        $this->mergeHtmlTableStructuredHints(
            $core,
            $tableStructuredHints,
            $contacts,
            $careerHistory,
            $relativesRows,
            $educationHistory,
            $propertySummary,
            $addressBlock,
            $residentialAddressLine,
            $text,
            $siblings,
            $horoscope,
            $htmlStructuredFieldLocks
        );

        $this->mergeMarathiSeparatedLayoutHints(
            $core,
            $separatedLayoutHints,
            $contacts,
            $careerHistory,
            $relativesRows,
            $educationHistory,
            $htmlStructuredFieldLocks
        );

        if (! empty($htmlStructuredFieldLocks['contacts'])
            && isset($tableStructuredHints['primary_contact'])
            && trim((string) $tableStructuredHints['primary_contact']) !== '') {
            $this->applyHtmlTablePrimaryContactAsAuthoritative(
                $contacts,
                (string) $tableStructuredHints['primary_contact'],
                $text
            );
        }

        $this->applyGenderFromCandidateFullNameHonorific($core);

        // AI fallback only for unlocked fields (never overwrites HTML/table extractions).
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
            'highest_education',
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
                if ($k === 'primary_contact_number') {
                    continue;
                }
                if (isset($htmlStructuredFieldLocks[$k]) && $htmlStructuredFieldLocks[$k]) {
                    continue;
                }
                if ($siblingCountsLocked && ($k === 'brother_count' || $k === 'sister_count')) {
                    continue;
                }
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
        if ($siblingCountsLocked) {
            $core['brother_count'] = null;
            $core['sister_count'] = null;
        }

        // Marathi-safe caste/sub_caste: "NN कुळी/क्‌ळी मराठा" → sub_caste = "NN कुळी", caste = "मराठा". No mojibake.
        if (! isset($htmlStructuredFieldLocks['caste']) && ($core['sub_caste'] ?? null) === null && isset($core['caste']) && is_string($core['caste'])) {
            if (preg_match('/([0-9०-९]+\s*(?:कुळी|क्‌ळी|क[\x{094D}\x{200C}\s]*ळी|कळी))/u', $core['caste'], $m) && mb_strpos($core['caste'], 'मराठा') !== false) {
                $normalized = preg_replace('/(?:क्‌ळी|क[\x{094D}\x{200C}\s]*ळी|कळी)/u', 'कुळी', $m[1]);
                $core['sub_caste'] = trim($normalized);
                $core['caste'] = 'मराठा';
            }
        }

        // Last pass: HTML table snapshot wins over any late OCR/body/footer bleed (addresses, other_relatives, horoscope, contacts).
        if (\App\Services\Parsing\HtmlMarathiBiodataTableExtractor::isStructuredTableHints($tableStructuredHints)) {
            $this->finalizeStructuredHtmlTableSnapshot(
                $tableStructuredHints,
                $core,
                $addressBlock,
                $residentialAddressLine,
                $contacts,
                $horoscope,
                $text
            );
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
        if (! empty($contacts)) {
            $addConf('contacts.0.number', self::CONF_REGEX);
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
        $careerHistory = $this->normalizeCareerHistoryCanonicalKeys($careerHistory);

        $coreOut = $core;
        $siblingsOut = $siblings;
        $explicitSiblingCountSignal = (bool) preg_match(
            '/(?:भाऊ|बहिण|बहीण|मुलाची\s+बहिण|मुलाची\s+बहीण|मुलाचा\s+भाऊ|मुलाचे\s+भाऊ)\s*[:\s_\-]*(?:\d+|एक|१|दोन|२|तीन|३|चार|४|पाच|५|सहा|६|नाही|०|0|None|No\b)/um',
            $text
        );
        if ($siblingCountsLocked && ! $explicitSiblingCountSignal) {
            $coreOut['brother_count'] = null;
            $coreOut['sister_count'] = null;
            $coreOut['has_siblings'] = null;
            $siblingsOut = [];
        }

        $this->filterContactsRemoveFooterShopNumbers($contacts, $text);
        $relativesRows = $this->filterRelativeRowsDropSectionPseudoHeaders($relativesRows);

        return [
            'core' => $coreOut,
            'contacts' => $contacts,
            'children' => $children,
            'education_history' => $educationHistory,
            'career_history' => $careerHistory,
            'addresses' => $this->buildAddressesArray($addressBlock, $residentialAddressLine ?? null, $nativePlaceRaw ?? null),
            'birth_place' => (($coreOut['birth_place_text'] ?? $coreOut['birth_place'] ?? null) !== null && trim((string) ($coreOut['birth_place_text'] ?? $coreOut['birth_place'])) !== '')
                ? [
                    'address_line' => (string) ($coreOut['birth_place_text'] ?? $coreOut['birth_place']),
                    'raw' => (string) ($coreOut['birth_place_text'] ?? $coreOut['birth_place']),
                ]
                : null,
            'native_place' => ($nativePlaceRaw !== null && trim((string) $nativePlaceRaw) !== '')
                ? [
                    'address_line' => trim((string) $nativePlaceRaw),
                    'raw' => trim((string) $nativePlaceRaw),
                ]
                : null,
            'property_summary' => $propertySummary,
            'property_assets' => $propertyAssets,
            'horoscope' => $horoscope,
            'preferences' => $preferences,
            'extended_narrative' => null,
            'confidence_map' => $confidenceMap,
            'relatives' => $relativesRows,
            'relatives_sectioned' => self::buildRelativesSectionedFromRows($relativesRows),
            'siblings' => $siblingsOut,
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
                if (preg_match('/^\s*'.preg_quote($word, '/').'\s*:?\s*$/iu', $line)) {
                    return false;
                }
            }

            return true;
        });
        $text = implode("\n", $lines);

        $text = preg_replace('/^\s*[«*•]+\s*/mu', '', $text);
        // Line-start list markers "1. ", "2- "; do not strip "5.7 इंच" / decimal measurements (digit after the dot).
        $text = preg_replace('/^\s*[0-9]{1,2}[.\-](?!\d)\s*/mu', '', $text);

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

        $lower = \App\Services\Ocr\OcrNormalize::normalizeDigits($v);
        // "१:४० मि." (colon between hour/minute; table OCR) — वा. pattern is used on other biodata.
        if (preg_match('/(\d{1,2})\s*:\s*(\d{1,2})\s*(?:मि|मी)\.?/u', $lower, $cm)) {
            $hour = (int) $cm[1];
            $minute = (int) $cm[2];
            $isNight = mb_strpos($lower, 'रात्री') !== false;
            $isEvening = mb_strpos($lower, 'संध्याकाळ') !== false;
            $isAfternoon = mb_strpos($lower, 'दुपारी') !== false;
            $isMorning = mb_strpos($lower, 'सकाळी') !== false || mb_strpos($lower, 'पहाटे') !== false;
            if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
                return null;
            }
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

        $isNight = mb_strpos($lower, 'रात्री') !== false;
        $isEvening = mb_strpos($lower, 'संध्याकाळ') !== false;
        $isAfternoon = mb_strpos($lower, 'दुपारी') !== false;
        $isMorning = mb_strpos($lower, 'सकाळी') !== false || mb_strpos($lower, 'पहाटे') !== false;

        // OCR often writes "मी." (minutes) instead of "मि."
        if (! preg_match('/(\d{1,2})\s*वा\.?\s*(\d{1,2})\s*(?:मि|मी)\.?/u', $lower, $m)) {
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

    private function looksLikeMarathiBirthTimePhrase(string $s): bool
    {
        $s = trim($s);
        if ($s === '') {
            return false;
        }

        return (bool) preg_match('/(?:वा\.?|वा)\s*\d|पहाटे|रात्री|संध्याकाळ|दुपारी|सकाळी|मि\.|मि\b|मी\.|मी\b|[०-९0-9]\s*:\s*[०-९0-9]|:[०-९0-9]{1,2}\s*(?:मि|मी)|[०-९0-9]\s*वा/u', $s);
    }

    /**
     * Strip trailing ", मो." / mobile fragments fused into a parent-name line.
     */
    private function stripTrailingMobileFragmentFromPersonLine(?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return null;
        }
        $n = trim($name);
        $n = preg_replace('/[,，]\s*मो\.?\s*[0-9०-९\s\/-]*$/u', '', $n) ?? $n;
        $n = preg_replace('/\s+मो\.?\s*[0-9०-९\s]{5,}$/u', '', $n) ?? $n;

        return trim($n) === '' ? null : trim($n);
    }

    private function extractFatherContactNumberFromText(string $text): ?string
    {
        foreach (explode("\n", $text) as $line) {
            $t = trim($line);
            if ($t === '' || ! preg_match('/वडिलांचे|वडिलाचे|वडीलांचे/u', $t)) {
                continue;
            }
            if (preg_match('/\(\s*मो[\.।]?\s*([6-9]\d{9})\s*\)/u', $t, $m)) {
                return $m[1];
            }
            if (preg_match('/मो[\.।]\s*([6-9][\d\s०-९]{9,})/u', $t, $m2)) {
                $digits = preg_replace('/\D/u', '', $m2[1]) ?? '';

                return strlen($digits) >= 10 ? substr($digits, -10) : null;
            }
        }

        return null;
    }

    /**
     * "मोबा. : …" lines (father mobile shorthand): up to two 10-digit Indian mobiles for core.father_contact_*.
     * Does not overwrite non-null slots (stronger वडिलांचे-line extraction wins for father_contact_1).
     */
    private function applyMobaLinePhonesToFatherContactSlots(string $text, ?string &$fatherContact1, ?string &$fatherContact2): void
    {
        $nums = $this->extractIndianMobilesFromMobaLine($text);
        if ($nums === []) {
            return;
        }
        foreach ($nums as $n) {
            if ($fatherContact1 === null) {
                $fatherContact1 = $n;

                continue;
            }
            if ($n !== $fatherContact1 && $fatherContact2 === null) {
                $fatherContact2 = $n;
                break;
            }
        }
    }

    /**
     * @return list<string>
     */
    private function extractIndianMobilesFromMobaLine(string $text): array
    {
        $all = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $t = trim((string) $line);
            if ($t === '' || ! preg_match('/^मोबा\.?\s*[:：]/u', $t)) {
                continue;
            }
            $digits = preg_replace('/\D/u', '', \App\Services\Ocr\OcrNormalize::normalizeDigits($t)) ?? '';
            if ($digits === '') {
                continue;
            }
            for ($i = 0; $i <= strlen($digits) - 10; $i++) {
                $chunk = substr($digits, $i, 10);
                if (preg_match('/^[6-9]\d{9}$/', $chunk)) {
                    $all[] = $chunk;
                    $i += 9;
                }
            }
        }

        return array_values(array_unique($all));
    }

    /**
     * First "मु. पो. : …" line after वडिलांचे नाव (father village / post); not birth_place / native_place.
     */
    private function extractFatherMuPoAddressLineAfterFatherName(string $text): ?string
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        $afterFather = false;
        foreach ($lines as $line) {
            $t = trim((string) $line);
            if ($t === '') {
                continue;
            }
            if (preg_match('/वडिलांचे\s*नाव|वडिलाचे\s*नाव|वडीलांचे\s*नाव/u', $t)) {
                $afterFather = true;

                continue;
            }
            if (! $afterFather) {
                continue;
            }
            if (preg_match('/^(?:आईचे|मातेचे|माता|मुलाचे|मुलीचे|भाऊ|बहीण|बहिण|आत्या|मामा|मावशी|संपर्क|इतर\s+नातेवाईक|इतर\s+पाहुणे|पाहुणे\s*[-–—])/u', $t)) {
                break;
            }
            if (preg_match('/^\s*मु\.?\s*पो\.?\s*[:：]\s*(.+)$/u', $t, $m)) {
                $addr = trim(preg_replace('/\s+/u', ' ', $m[1]) ?? '');
                if ($addr === '') {
                    return null;
                }
                if (mb_strlen($addr) > 255) {
                    return mb_substr($addr, 0, 255);
                }

                return $addr;
            }
        }

        return null;
    }

    private function removeWatermarkNoise(string $text): string
    {
        // Line-based only: avoid stripping whole documents when "संपर्क" and a phone appear in valid sections.
        $lines = explode("\n", $text);
        $kept = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*वधुवर\s*सूचक/u', trim($line))) {
                continue;
            }
            $kept[] = $line;
        }

        return implode("\n", $kept);
    }

    /**
     * Strip HTML/table noise from OCR or PDF-derived biodata before rule extraction.
     */
    public static function stripIntakeHtmlNoise(string $text): string
    {
        if ($text === '') {
            return $text;
        }
        $t = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace('/<br\s*\/?>/iu', "\n", $t) ?? $t;
        $t = preg_replace('/<\/?(?:tr|div|p|li|table|tbody|thead|tfoot|colgroup|col)\b[^>]*>/iu', "\n", $t) ?? $t;
        $t = preg_replace('/<\/?(?:td|th)\b[^>]*>/iu', ' ', $t) ?? $t;
        // Plain-text OCR sometimes keeps closing tags as literals after broken HTML.
        $t = preg_replace('/<\/?(?:td|th|tr|tbody|thead|tfoot|table|div|span)\b[^>]*>/iu', ' ', $t) ?? $t;
        $t = preg_replace('/&nbsp;|&#160;/iu', ' ', $t) ?? $t;
        $t = strip_tags($t);
        // Collapse horizontal whitespace only: \v in PCRE includes LF, which must not be stripped (line-based parsing).
        $t = preg_replace('/\h+/u', ' ', $t) ?? $t;
        $t = preg_replace('/\n{3,}/u', "\n\n", $t) ?? $t;

        return trim($t);
    }

    /**
     * Join OCR/table lines where label, ":-", and value were split across rows (common when &lt;td&gt;…&lt;/td&gt;
     * is normalized to newlines). Without this, extractAfterLabel() captures only ":- " on the label line and
     * date_of_birth / father_name stay null.
     */
    private function normalizeTableOcrSplitCells(string $text): string
    {
        if ($text === '') {
            return $text;
        }
        // Pattern: "जन्म तारीख\n:-\n०८ ऑगस्ट …" or "जन्म तारीख\n:- ०८ …"
        $text = preg_replace(
            '/जन्म\s*तारीख\s*\R+\s*[:\-–—]+\s*\R+\s*/u',
            "जन्म तारीख :- ",
            $text
        );
        $text = preg_replace(
            '/जन्म\s*तारीख\s*\R+\s*[:\-–—]+\s*([०-९0-9])/u',
            'जन्म तारीख :- $1',
            $text
        );
        // वडिलांचे नांव split across cells (father_name null)
        $text = preg_replace(
            '/वडिलांचे\s*नांव\s*\R+\s*[:\-–—]+\s*\R+\s*/u',
            'वडिलांचे नांव :- ',
            $text
        );
        $text = preg_replace(
            '/वडिलांचे\s*नांव\s*\R+\s*[:\-–—]+\s*(कै\.|डॉ\.|श्री\.)/u',
            'वडिलांचे नांव :- $1',
            $text
        );
        // जन्म ठिकाण / जन्म वार व वेळ / शिक्षण / व्यवसाय (side-cell bleed)
        foreach (['जन्म ठिकाण', 'जन्म वार व वेळ', 'शिक्षण', 'व्यवसाय'] as $lab) {
            $q = preg_quote($lab, '/');
            $text = preg_replace('/'.$q.'\s*\R+\s*[:\-–—]+\s*\R+\s*/u', $lab.' :- ', $text);
        }

        return $text;
    }

    /**
     * HTML <br/> inside a table cell becomes a newline; "२) चि. …" may land on the next line without "भाऊ :-".
     * Reattach those enumerator continuations so sibling splitting sees one logical भाऊ/बहिण value.
     */
    private function mergeOrphanSiblingEnumeratorContinuations(string $text): string
    {
        if ($text === '') {
            return $text;
        }
        $lines = preg_split('/\R/u', $text) ?: [];
        $out = [];
        foreach ($lines as $ln) {
            $trim = trim($ln);
            if ($trim !== '' && preg_match('/^\s*[\d०-९0-9]{1,2}\s*\)\s*(?:(?:अविवाहित|अविवाहीत)[\-–—]?\s*)?(?:चि\.|कु\.|श्री\.|डॉ\.)/u', $trim)) {
                for ($j = count($out) - 1; $j >= 0; $j--) {
                    $prev = trim($out[$j]);
                    if ($prev === '') {
                        continue;
                    }
                    if (preg_match('/^(?:\s*[\-–—•*]+\s*)?(?:भाऊ|बहिण|बहीण)\s*[:\-]/u', $prev)) {
                        $out[$j] = rtrim($out[$j]).' '.$trim;

                        continue 2;
                    }

                    break;
                }
            }
            $out[] = $ln;
        }

        return implode("\n", $out);
    }

    /**
     * Vision/ OCR often merges two labeled fields on one row using a middle dot. Split into logical lines
     * so extractField / horoscope hooks see one label per line (कुलस्वामी • नक्षत्र, रक्तगट • नाडी, …).
     */
    private function expandBulletedCompoundFieldLines(string $text): string
    {
        if ($text === '' || ! preg_match('/[•·∙]/u', $text)) {
            return $text;
        }
        $lines = preg_split('/\R/u', $text) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '' || ! preg_match('/[•·∙]/u', $t)) {
                $out[] = $line;

                continue;
            }
            $pieces = preg_split('/\s*[•·∙]\s+/u', $t);
            $pieces = array_values(array_filter(array_map('trim', $pieces), fn ($p) => $p !== ''));
            if (count($pieces) < 2) {
                $out[] = $line;

                continue;
            }
            $labelValue = '/[:\-–—]\s*\S/u';
            $hits = 0;
            foreach ($pieces as $p) {
                if (preg_match($labelValue, $p)) {
                    $hits++;
                }
            }
            if ($hits < 2) {
                $out[] = $line;

                continue;
            }
            foreach ($pieces as $p) {
                $out[] = $p;
            }
        }

        return implode("\n", $out);
    }

    /**
     * Flatten HTML tables so label/value cells become readable lines (e.g. वडिलांचे नांव : श्री. … , मो. …).
     */
    public static function flattenHtmlTableForBiodata(string $html): string
    {
        return \App\Services\Ocr\OcrHtmlTableFlattener::flatten($html);
    }

    /**
     * @return array{0: ?string, 1: ?string} [father_name, father_phone_10_digits]
     */
    private function extractFatherNameAndPhoneFromVadilancheNaavLine(string $text): array
    {
        if (! preg_match('/वडिलांचे\s*(?:नाव|नांव)\s*[:\-]?\s*(.+)/us', $text, $m)) {
            return [null, null];
        }
        $rest = trim($m[1]);
        $rest = trim(preg_replace('/\s+/u', ' ', $rest) ?? $rest);
        $rest = preg_replace('/^[:\-–—]\s*/u', '', $rest);
        $rest = trim($rest);
        $phone = null;
        if (preg_match('/मो[\.।]\s*([0-9०-९\s]{10,})/u', $rest, $pm)) {
            $digits = preg_replace('/\D/u', '', \App\Services\Ocr\OcrNormalize::normalizeDigits($pm[1]));
            if (strlen($digits) >= 10) {
                $phone = substr($digits, -10);
            }
        }
        $rest = preg_replace('/\s*,\s*मो\.?\s*[0-9०-९\s\/-]+/u', '', $rest);
        $rest = trim($rest);
        if ($rest === '') {
            return [null, $phone];
        }
        $name = $this->validateFatherName($this->cleanPersonName($rest));

        return [$name, $phone];
    }

    /**
     * Strip residual HTML/table tokens from a single extracted field (notes, addresses, horoscope fragments).
     * Preserves line breaks for multiline notes; collapses horizontal spaces per line.
     */
    public static function stripResidualHtmlTagsFromString(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $v = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $v = preg_replace('/<br\s*\/?>/iu', "\n", $v) ?? $v;
        $v = preg_replace('/<\/?(?:td|th|tr|tbody|thead|tfoot|table|div|p|span|li)\b[^>]*>/iu', ' ', $v) ?? $v;
        $v = strip_tags($v);
        $lines = preg_split('/\R/u', $v) ?: [];
        $lines = array_map(static function (string $ln): string {
            $ln = preg_replace('/\h+/u', ' ', trim($ln)) ?? $ln;

            return trim($ln);
        }, $lines);
        $lines = array_values(array_filter($lines, static fn (string $ln): bool => $ln !== ''));

        return trim(implode("\n", $lines));
    }

    /** Valid Marathi horoscope गण; rejects image-caption / transcription junk. */
    public static function sanitizeGanValue(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $v = trim(self::stripResidualHtmlTagsFromString($value));
        if ($v === '') {
            return null;
        }
        if (preg_match('/आहे|चित्र|फोटो|भगवान|गणेश|देवी|देवत|watermark|image/ui', $v)) {
            return null;
        }
        if (preg_match('/^[\x{0940}-\x{094F}]/u', $v)) {
            return null;
        }
        $v = preg_replace('/\s+/u', ' ', $v);
        // ASCII-only trim(): a UTF-8 danda in the char list byte-corrupts Devanagari (PHP trim is not multibyte-safe).
        $v = trim($v, " \t.:;,*");
        $v = preg_replace('/^[।]+|[।]+$/u', '', $v) ?? $v;
        $v = trim($v);
        if ($v === '' || mb_strlen($v) > 24) {
            return null;
        }
        $known = ['मनुष्य', 'देव', 'राक्षस', 'राक्षसी', 'व्याघ्र', 'वानर', 'सर्प', 'गज'];
        foreach ($known as $k) {
            if ($v === $k || mb_strpos($v, $k) !== false) {
                return $k;
            }
        }
        if (preg_match('/^[\x{0900}-\x{097F}]{2,15}$/u', $v)) {
            return $v;
        }

        return null;
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
            // Skip decorative headers/footers; do NOT drop labeled biodata rows (e.g. कुलस्वामी : … प्रसन्न …).
            if (mb_strpos($line, 'भवतु') !== false) {
                continue;
            }
            if (mb_strpos($line, 'प्रसन्न') !== false && ! preg_match('/[:\-–—]\s*\S/u', $line)) {
                continue;
            }
            if (preg_match('/शुभ\s*भवतु/u', $line)) {
                continue;
            }
            if (preg_match('/^[\s\|।]*$/u', $line) || $line === '||' || $line === '।।') {
                continue;
            }
            if (mb_substr_count($line, 'श्री') > 2 && ! $this->isRelativeLine($line)) {
                continue;
            }
            if (preg_match('/^[\p{P}\s\-*·.—]+$/u', $line)) {
                continue;
            }
            if (preg_match('/^\*एका\s+व्यक्तीचे/u', $line)) {
                continue;
            }
            if (mb_strlen($line) > 150 && ! preg_match('/[:\-–—]\s*\S/u', $line)
                && ! preg_match('/(?:जन्म|उंची|शिक्षण|नोकरी|वडिल|आई|भाऊ|बहिण|संपर्क|मो\.|जात|धर्म|रास|नक्षत्र|कुल|गोत्र)/u', $line)) {
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
            } elseif (mb_strpos($line, 'रास') !== false || mb_strpos($line, 'राशी') !== false || mb_strpos($line, 'टाशी') !== false || mb_strpos($line, 'नक्षत्र') !== false || mb_strpos($line, 'नाडी') !== false || mb_strpos($line, 'गण') !== false || mb_strpos($line, 'कुलस्वामी') !== false || mb_strpos($line, 'कुळस्वामी') !== false || mb_strpos($line, 'कूळस्वामी') !== false || mb_strpos($line, 'मांगलिक') !== false) {
                $sections['HOROSCOPE'][] = $line;
            } elseif (mb_strpos($line, 'मु.पो.') !== false || mb_strpos($line, 'ता.') !== false || mb_strpos($line, 'जि.') !== false || mb_strpos($line, 'संपर्क') !== false) {
                $sections['CONTACT'][] = $line;
            } elseif (mb_strpos($line, 'वर्ण') !== false) {
                // Horoscope varna (वैश्य/क्षत्रिय/…) must stay in HOROSCOPE scope; complexion "वर्ण: गोरा" stays PERSONAL.
                if (preg_match('/वर्ण\s*[:\-]+\s*(.+)$/u', $line, $vm)) {
                    $vOnly = trim((string) preg_replace('/\s+(?:रास|नक्षत्र|योनी|नाडी|गण|चरण|वश्य|रक्त)\s*[:\-].*$/u', '', trim($vm[1])));
                    $vOnly = trim((string) preg_replace('/,\s*$/u', '', $vOnly));
                    if ($this->validateVarna($vOnly !== '' ? $vOnly : null) !== null) {
                        $sections['HOROSCOPE'][] = $line;

                        continue;
                    }
                }
                $sections['PERSONAL'][] = $line;
            } elseif (mb_strpos($line, 'जन्म') !== false || mb_strpos($line, 'उंची') !== false || mb_strpos($line, 'शिक्षण') !== false || mb_strpos($line, 'गोत्र') !== false || mb_strpos($line, 'कुलदैवत') !== false || mb_strpos($line, 'कुळस्वामी') !== false || mb_strpos($line, 'कुलस्वामी') !== false) {
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
        if ($value === '' || $this->isLikelyLabelOnlyValue($value)) {
            return null;
        }
        if ($value === 'नावरस नाव' || $value === 'नांवटस नाव') {
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
        // Include \p{M} (combining marks / matras) so well-formed Devanagari names are not rejected as "punctuation-heavy".
        $punctOnly = preg_replace('/[\p{L}\p{N}\s\p{M}]/u', '', $value);
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

    /**
     * Reject values that are horoscope/gotra/kul lines mis-assigned to education, career, or address.
     */
    private function valueSmellsLikeHoroscopeOrGotraLeak(?string $v): bool
    {
        if ($v === null || $v === '') {
            return false;
        }

        return preg_match('/गोत्र\s*[:\-]|^गोत्र\b|देवक\s*[:\-]|नाडी\s*[:\-]|कुलस्वामी|कुळस्वामी|कुलस्वामीनी|माणकेश्वर|(?:^|[\s,])कुल(?:दैवत|स्वामी)?\b/u', $v) === 1;
    }

    /** Drop residence candidates that are only kul-deity / gotra lines mistaken for पत्ता. */
    private function finalizeAddressBlockCandidate(?string $addr): ?string
    {
        if ($addr === null || trim($addr) === '') {
            return null;
        }
        $addr = $this->trimCandidateAddressBlockAtFamilySections(trim($addr));
        $addr = $this->stripResidentialAddressFromPrimaryBlock($addr);
        if ($addr === '') {
            return null;
        }
        $hasAddrAnchor = preg_match('/मु\.?\s*पो\.|ता\.\s*\S|जि\.\s*\S|^[रर]ा\.\s*\S|गाव\s*[:\-]|उरूण|इस्लामपूर|Flat|House|Ward|वॉर्ड/u', $addr) === 1;
        if ($this->valueSmellsLikeHoroscopeOrGotraLeak($addr) && ! $hasAddrAnchor) {
            return null;
        }

        return $this->stripMultilineAddressJoinArtifacts($addr);
    }

    /**
     * Narrow cleanup for OCR line-join artifacts in address blocks only (e.g. comma line + next line "- गाव").
     */
    private function stripMultilineAddressJoinArtifacts(string $addr): string
    {
        $addr = preg_replace('/,\s*-\s+/u', ', ', $addr) ?? $addr;

        return trim(preg_replace('/\s+/u', ' ', $addr) ?? '');
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
        $quoted = preg_quote($label, '/');
        if ($label === 'आई') {
            $quoted = 'आई(?!\s*चे)';
        }
        // "अपेक्षा :- खानदानी, नोकरी, उच्चशिक्षीत." — inline "नोकरी," is not a job label
        if ($label === 'नोकरी') {
            $quoted = 'नोकरी(?!\s*,)';
        }
        $pattern = '/'.$quoted.'\s*[:\-\s]{0,15}\s*([^\n]+)/u';
        if (preg_match($pattern, $text, $match) && isset($match[1])) {
            $value = trim($match[1]);
            if ($value === '' || $value === trim($label)) {
                return null;
            }
            if (($label === 'नाव' || $label === 'मुलाचे नाव' || $label === 'मुलीचे नाव') && (mb_strpos($match[0], 'नावरस') !== false || mb_strpos($match[0], 'नांवटस') !== false)) {
                return null;
            }
            $cleaned = $this->cleanValue($value);
            if ($cleaned === '') {
                return null;
            }
            if ($this->labelExpectsPersonName($label) && $this->isLikelyLabelOnlyValue($cleaned)) {
                return null;
            }

            return $cleaned;
        }

        return null;
    }

    /**
     * Fallback when father name is on same line after वडिलांचे नांव or appears as कै. डॉ. <name> before आईचे.
     */
    private function extractFatherNameFromKaiOrDoctor(string $text): ?string
    {
        // Same line: वडिलांचे नांव : कै. डॉ. शहाजी विष्णू भोसले (नाव / नांव)
        if (preg_match('/वडिलांचे\s*नां?व\s*[:\-]?\s*(कै\.?\s*डॉ\.?\s*[^\n]+?)(?=\s*आईचे|\n)/u', $text, $m)) {
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
        $pattern = '/'.preg_quote($label, '/').'\s*[:\-]?\s*\n\s*[:\-]?\s*([^\n]+)/u';
        if (preg_match($pattern, $text, $match) && isset($match[1])) {
            $value = trim($match[1]);
            if ($value === '' || $value === trim($label)) {
                return null;
            }
            if ((mb_strpos($label, 'वडिलांचे') !== false || mb_strpos($label, 'वडीलांचे') !== false) && (mb_strpos($value, 'आईचे') !== false || mb_strpos($value, 'आईचं') !== false)) {
                return null;
            }
            $cleaned = $this->cleanValue($value);
            if ($cleaned === '') {
                return null;
            }
            if ($this->labelExpectsPersonName($label) && $this->isLikelyLabelOnlyValue($cleaned)) {
                return null;
            }

            return $cleaned;
        }

        return null;
    }

    /** Extract value after label, allowing value to span until next double newline OR next label line (for शिक्षण). */
    private function extractAfterLabelMultiline(string $text, string $label): ?string
    {
        $pattern = '/'.preg_quote($label, '/').'\s*[:\->\s]{0,5}\s*(.+?)(?=\s+(?:उंची|ऊंची|वर्ण)\s*[:\-]|\n\s*\n|\n\s*(?:वर्ण|रास\s|कुलदैवत|उंची|ऊंची|गोत्र|कुलस्वामी|कुलस्वामीनी|नाडी|गण\s*[:\-]|जात\s|नोकरी|वेतन|पत्ता|मुलाचे|मुलीचे|जन्म\s|रक्त|कौटुंबिक|वडिलांचे|आईचे|चुलते|भाऊ|बहिण|बहीण|मामा|नातेसंबंध|संपर्क|अपेक्षा)|$)/us';
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

    /**
     * Stop education (and similar) fields when the next section label starts on the same line.
     *
     * @param  array<int, string>  $nextLabels
     */
    private function truncateFieldBeforeInlineSectionLabels(?string $value, array $nextLabels): ?string
    {
        if ($value === null || trim($value) === '') {
            return $value;
        }
        $alt = implode('|', array_map(static fn (string $l): string => preg_quote($l, '/'), $nextLabels));
        if (preg_match('/^(.+?)\s+(?:'.$alt.')\s*[:\-]/u', $value, $m)) {
            return trim($m[1]);
        }

        return $value;
    }

    private function cleanValue(string $value): string
    {
        $value = trim($value);
        $value = self::stripResidualHtmlTagsFromString($value);
        $value = preg_replace('/^[\-\sx>:\.,_]+/u', '', $value);
        $value = preg_replace('/\s*x\s*$/u', '', $value);
        foreach (self::OCR_BLACKLIST_WORDS as $word) {
            $value = preg_replace('/\b'.preg_quote($word, '/').'\b/iu', ' ', $value);
        }
        $value = preg_replace('/\s+/u', ' ', trim($value));
        if (mb_strlen($value) < 3) {
            // Allow short degree codes (BA, B.A, M.Sc, etc.)
            if (preg_match('/^[A-Za-z\.]+$/u', $value) && mb_strlen($value) >= 2) {
                return $value;
            }
            // Blood group tokens like A+, O-, B+
            $bgCompact = str_replace(' ', '', $value);
            if (preg_match('/^(?:AB|[ABO])[+\-]$/iu', $bgCompact)) {
                return strtoupper($bgCompact);
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
            if (preg_match('/^\s*नाव\s*(?::\s*-\s*|:\s+)\s*(.+)$/u', $t, $mm)) {
                $val = $this->cleanValue(trim($mm[1]));
                if ($val !== '') {
                    $valid = $this->validateFullName($val);
                    if ($valid !== null) {
                        return $valid;
                    }
                }
            } elseif (preg_match('/^\s*[0-9]+\s*नाव\s*(?::\s*-\s*|:\s+)\s*(.+)$/u', $t, $mm)) {
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
            foreach (['मुलाचे नाव', 'मुलाचे नांव', 'मुलीचे नाव', 'मुलीचे नांव', 'वधूचे नाव', 'वधूचे नांव'] as $label) {
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
            '/^(?:[^\n]*\n)*\s*मुलाचे\s+नांव\s*[:\-]\s*([^\n]+)/u',
            '/^(?:[^\n]*\n)*\s*मुलीचे\s+नाव\s*[:\-]\s*([^\n]+)/u',
            '/^(?:[^\n]*\n)*\s*मुलीचे\s+नांव\s*[:\-]\s*([^\n]+)/u',
            '/^(?:[^\n]*\n)*\s*वधूचे\s+नाव\s*[:\-]\s*([^\n]+)/u',
            '/^(?:[^\n]*\n)*\s*वधूचे\s+नांव\s*[:\-]\s*([^\n]+)/u',
            '/^(?:[^\n]*\n)*\s*नाव\s*(?::\s*-\s*|:\s*[:\-]{0,2}\s*|\s*-\s+)\s*([^\n]+)/u',
            '/^(?:[^\n]*\n)*\s*नांव\s*[:\-]\s*([^\n]+)/u',
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
        $nameLabels = ['मुलाचे नाव', 'मुलाचे नांव', 'मुलीचे नाव', 'मुलीचे नांव', 'वधूचे नाव', 'वधूचे नांव', 'नाव', 'नांव'];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || mb_strpos($line, 'नावरस') !== false || mb_strpos($line, 'नांवटस') !== false) {
                continue;
            }
            foreach ($nameLabels as $label) {
                $pattern = '/^\s*'.preg_quote($label, '/').'\s*(?::\s*-\s*|:\s*[:\-]{0,4}\s*|\s*[:\-]{0,5}\s+)\s*(.+)$/u';
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

    /**
     * @param  array<string, bool>  $excludePhonesFlip  Keys are 10-digit normalized phones to never treat as candidate primary.
     */
    private function extractPrimaryContactNumber(string $text, array $excludePhonesFlip = []): ?string
    {
        // Markdown-style headers (## Name ९७३०४४०१०३): prefer first valid number in top contact blocks over later family lines.
        foreach (explode("\n", $text) as $line) {
            $t = trim($line);
            if ($t === '' || ! preg_match('/^#{1,6}\s+\S/u', $t)) {
                continue;
            }
            if (preg_match('/वडिलांचे|चुलते|मामा|भाऊ\s*\/|बहिण|बहीण|काका|काकू/u', $t) && ! preg_match('/मो\.|मोबाइल|Mobile|संपर्क/u', $t)) {
                continue;
            }
            $digitLine = \App\Services\Ocr\OcrNormalize::normalizeDigits($t);
            if (preg_match_all('/\b([6-9]\d{9})\b/', $digitLine, $mm)) {
                foreach ($mm[1] as $cand) {
                    if (! isset($excludePhonesFlip[$cand])) {
                        return $cand;
                    }
                }
            }
        }

        $selfLabels = ['मुलीचा संपर्क', 'मुलाचा संपर्क', 'स्वतःचा संपर्क', 'मुलीचा मोबाईल', 'मुलाचा मोबाईल', 'मुलीचा मोबाइल', 'मुलाचा मोबाइल'];
        $selfStr = $this->extractFieldAfterLabels($text, $selfLabels);
        if ($selfStr !== null) {
            $normalized = \App\Services\Ocr\OcrNormalize::normalizePhone(preg_replace('/\s+/', '', $selfStr));
            if ($normalized !== null && ! isset($excludePhonesFlip[$normalized])) {
                return $normalized;
            }
            if (preg_match('/\b([6-9]\d{9})\b/', $selfStr, $sm) && ! isset($excludePhonesFlip[$sm[1]])) {
                return $sm[1];
            }
        }

        $labelLinePattern = '/❖\s*संपर्क|संपर्क\s*क्रमांक|संपर्क\s*[:\-]|मोबाईल\s*नं|मोबाईल\s*नंबर|मोबाइल|मो\.|Contact\.?\s*No|(?<![\p{L}])Contact(?![\p{L}])|Mobile/ui';
        foreach (explode("\n", $text) as $line) {
            $t = trim($line);
            if ($t === '') {
                continue;
            }
            if (preg_match('/वडिलांचे|वडिलाचे|वडीलांचे|वध[ूु]वर|विवाह\s*सूचक|सूचक\s*केंद्र|लग्न\s*सूचक|ब्युरो|bureau|suchak/ui', $t)) {
                continue;
            }
            if (preg_match('/वध[ूु]वर/u', $t) || preg_match('/सूचक\s*केंद्र/u', $t)) {
                continue;
            }
            $hasContactCue = (bool) preg_match($labelLinePattern, $t);
            if ($this->isRelativeLine($t) && ! $hasContactCue) {
                continue;
            }
            if (! $hasContactCue) {
                continue;
            }
            if (preg_match_all('/\b([6-9]\d{9})\b/', $t, $mm)) {
                foreach ($mm[1] as $cand) {
                    $n = \App\Services\Ocr\OcrNormalize::normalizePhone($cand) ?? $cand;
                    if (! isset($excludePhonesFlip[$n])) {
                        return $n;
                    }
                }
            }
            if (preg_match('/\b([6-9]\d{4})\s*\/?\s*(\d{5})\b/', $t, $split)) {
                $combined = $split[1].$split[2];
                if (preg_match('/^[6-9]\d{9}$/', $combined) && ! isset($excludePhonesFlip[$combined])) {
                    return $combined;
                }
            }
        }

        $contactStr = $this->extractFieldAfterLabels(
            $text,
            ['संपर्क क्रमांक', 'मोबाईल नं', 'मोबाईल नंबर', 'Contact.No.', 'Contact', 'Mobile']
        );
        if ($contactStr !== null) {
            $normalized = \App\Services\Ocr\OcrNormalize::normalizePhone(preg_replace('/\s+/', '', $contactStr));
            if ($normalized !== null && ! isset($excludePhonesFlip[$normalized])) {
                return $normalized;
            }
            if (preg_match('/\b([6-9]\d{9})\b/', $contactStr, $m) && ! isset($excludePhonesFlip[$m[1]])) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * Phones embedded in relative lines or "(मो. …)" — must not become candidate primary contact.
     *
     * @return array<int, string>
     */
    private function collectRelativeEmbeddedPhones(string $text): array
    {
        $phones = [];
        foreach (explode("\n", $text) as $line) {
            $t = trim($line);
            if ($t === '') {
                continue;
            }
            $rel = $this->isRelativeLine($t);
            $moParen = (bool) preg_match('/\(मो/u', $t);
            if (! $rel && ! $moParen) {
                continue;
            }
            if (preg_match_all('/\(\s*मो[\.।]?\s*([6-9]\d{9})\s*\)/u', $t, $m)) {
                foreach ($m[1] as $p) {
                    $phones[$p] = true;
                }
            }
            if (preg_match_all('/मो[\.।]\s*([6-9]\d{9})\b/u', $t, $m2)) {
                foreach ($m2[1] as $p) {
                    $phones[$p] = true;
                }
            }
            if ($rel && preg_match_all('/\b([6-9]\d{9})\b/', $t, $m3)) {
                foreach ($m3[1] as $p) {
                    $phones[$p] = true;
                }
            }
        }

        $normalizedFlip = [];
        foreach (array_keys($phones) as $p) {
            $n = \App\Services\Ocr\OcrNormalize::normalizePhone($p) ?? $p;
            $normalizedFlip[$n] = true;
        }

        return array_keys($normalizedFlip);
    }

    /**
     * @return array{0: ?string, 1: ?string} [navras_name, rashi_from_paren]
     */
    private function refineNavrasAndRashiFromComposite(?string $navras): array
    {
        if ($navras === null || trim($navras) === '') {
            return [null, null];
        }
        $n = self::stripResidualHtmlTagsFromString(trim($navras));
        if ($n === '') {
            return [null, null];
        }
        $rashiExtra = null;
        if (preg_match('/\((?:रास|राशी)\s*[-–:\s]*\s*([^)]+)\)/u', $n, $m)) {
            $rashiExtra = trim($m[1]);
            $rashiExtra = trim(preg_replace('/^[\)\]"\'\s]+|[\)\]"\'\s]+$/u', '', $rashiExtra) ?? '');
            $n = trim(preg_replace('/\s*\((?:रास|राशी)\s*[-–:\s]*\s*[^)]+\)\s*$/u', '', $n) ?? '');
        }
        $n = trim(preg_replace('/^(?:नावरस|नवरस|नांवटस)\s+नाव\s*[:\-]\s*/u', '', $n) ?? '');

        return [
            $n !== '' ? $n : null,
            ($rashiExtra !== null && $rashiExtra !== '') ? $rashiExtra : null,
        ];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    /**
     * Remove Marathi weekday tokens from a time phrase; inflected forms (गुरुवारी) must run before stems (गुरुवार).
     */
    private function stripMarathiWeekdayWordsForBirthTimePhrase(string $rest): string
    {
        $timeRaw = $rest;
        $ordered = [
            'रविवारी', 'सोमवारी', 'मंगळवारी', 'मंगलवारी', 'बुधवारी', 'गुरुवारी', 'शुक्रवारी', 'शनिवारी',
            'रविवार', 'सोमवार', 'मंगळवार', 'मंगलवार', 'बुधवार', 'गुरुवार', 'शुक्रवार', 'शनिवार',
        ];
        foreach ($ordered as $d) {
            $timeRaw = str_replace($d, ' ', $timeRaw);
        }

        return trim(preg_replace('/\s+/u', ' ', $timeRaw) ?? '');
    }

    private function refineBirthWeekdayTimeComposite(string $text, ?string $birthWeekday, ?string $birthTime): array
    {
        // Table OCR: "जन्म वार व वेळ :- शुक्रवार दुपारी १:४० मि." (weekday + time on one line; व between वार and वेळ).
        if (preg_match('/जन्म\s*वार\s*व\s*वेळ\s*[:\-：]\s*(.+)$/um', $text, $jw)) {
            $rest = trim($jw[1]);
            $days = ['रविवार', 'सोमवार', 'मंगळवार', 'मंगलवार', 'बुधवार', 'गुरुवार', 'शुक्रवार', 'शनिवार'];
            $wd = $birthWeekday;
            foreach ($days as $d) {
                if (mb_strpos($rest, $d) !== false) {
                    $wd = $d;
                    break;
                }
            }
            $timeRaw = $this->stripMarathiWeekdayWordsForBirthTimePhrase($rest);
            // Keep दुपारी/सकाळी/… in $timeRaw — normalizeBirthTime() uses them for 12h conversion (esp. "१:४० मि." + दुपारी).
            $timeRaw = preg_replace('/,\s*|，/u', ' ', $timeRaw) ?? $timeRaw;
            $timeRaw = trim(preg_replace('/\s+/u', ' ', $timeRaw) ?? '');
            $newTime = $this->normalizeBirthTime($timeRaw);
            if ($newTime !== null) {
                return [$wd, $newTime];
            }
            if ($this->looksLikeMarathiBirthTimePhrase($rest)) {
                return [$wd, trim($rest)];
            }
        }
        if (preg_match('/जन्मवेळ\s*[:\-：]\s*(.+)$/um', $text, $jm)) {
            $rest = trim($jm[1]);
            $days = ['रविवार', 'सोमवार', 'मंगळवार', 'मंगलवार', 'बुधवार', 'गुरुवार', 'शुक्रवार', 'शनिवार'];
            $wd = $birthWeekday;
            foreach ($days as $d) {
                if (mb_strpos($rest, $d) !== false) {
                    $wd = $d;
                    break;
                }
            }
            $timeRaw = $this->stripMarathiWeekdayWordsForBirthTimePhrase($rest);
            $timeRaw = preg_replace('/,\s*|，/u', ' ', $timeRaw) ?? $timeRaw;
            $timeRaw = trim(preg_replace('/\s+/u', ' ', $timeRaw) ?? '');
            $newTime = $this->normalizeBirthTime($timeRaw);
            if ($newTime !== null) {
                return [$wd, $newTime];
            }
            if ($this->looksLikeMarathiBirthTimePhrase($rest)) {
                return [$wd, trim($rest)];
            }
        }
        $m = [];
        if (! preg_match('/जन्म\s*वार\s*[,，]?\s*वेळ\s*[:\-：]\s*(.+)$/um', $text, $m)
            && ! preg_match('/जन्मवार\s*[,，]?\s*वेळ\s*[:\-：]\s*(.+)$/um', $text, $m)
            && ! preg_match('/(?:जन्म\s*वार|जन्मवार)\s+आणि\s+वेळ\s*[:\-：]\s*(.+)$/um', $text, $m)) {
            return [$birthWeekday, $birthTime];
        }
        $rest = trim($m[1]);
        $days = ['रविवार', 'सोमवार', 'मंगळवार', 'मंगलवार', 'बुधवार', 'गुरुवार', 'शुक्रवार', 'शनिवार'];
        $wd = $birthWeekday;
        foreach ($days as $d) {
            if (mb_strpos($rest, $d) !== false) {
                $wd = $d;
                break;
            }
        }
        $timeRaw = $this->stripMarathiWeekdayWordsForBirthTimePhrase($rest);
        $timeRaw = preg_replace('/,\s*|，/u', ' ', $timeRaw) ?? $timeRaw;
        $timeRaw = trim(preg_replace('/\s+/u', ' ', $timeRaw) ?? '');
        $newTime = $this->normalizeBirthTime($timeRaw);
        if ($newTime !== null) {
            $birthTime = $newTime;
        }

        return [$wd, $birthTime];
    }

    /**
     * Drop label-only weekday values (e.g. OCR capturing "वेळ" as जन्म वार).
     */
    private function sanitizeBirthWeekdayField(?string $weekday): ?string
    {
        if ($weekday === null || trim($weekday) === '') {
            return null;
        }
        $t = trim(preg_replace('/\s+/u', ' ', $weekday) ?? '');
        if ($t === 'वेळ' || $t === 'जन्म वेळ' || $t === 'व वेळ' || preg_match('/^व\s+वेळ$/u', $t)) {
            return null;
        }
        if (preg_match('/^(?:जन्म\s*वार|जन्मवार)\s*[,，]?\s*वेळ\b/u', $t)) {
            return null;
        }
        $t = trim((string) preg_replace('/^आणि\s+वेळ\s*[:\-：]\s*/u', '', $t));
        $days = ['रविवार', 'सोमवार', 'मंगळवार', 'मंगलवार', 'बुधवार', 'गुरुवार', 'शुक्रवार', 'शनिवार'];
        foreach ($days as $d) {
            if (mb_strpos($t, $d) !== false) {
                return $d;
            }
        }
        $inflected = [
            'रविवारी' => 'रविवार', 'सोमवारी' => 'सोमवार', 'मंगळवारी' => 'मंगळवार', 'मंगलवारी' => 'मंगलवार',
            'बुधवारी' => 'बुधवार', 'गुरुवारी' => 'गुरुवार', 'शुक्रवारी' => 'शुक्रवार', 'शनिवारी' => 'शनिवार',
        ];
        foreach ($inflected as $from => $to) {
            if (mb_strpos($t, $from) !== false) {
                return $to;
            }
        }

        return $t;
    }

    /**
     * Split one relative note into multiple rows when enumerators (१) 2) etc.) separate people.
     *
     * @param  array<int, array<string, mixed>>  $relatives
     * @return array<int, array<string, mixed>>
     */
    private function splitRelativeRowsByEnumerators(array $relatives): array
    {
        $multiTypes = 'आत्या|मावशी|चुलते|चुलती|मामा|काका|दाजी|आजोळ';
        $out = [];
        foreach ($relatives as $row) {
            $type = (string) ($row['relation_type'] ?? '');
            $notes = $row['notes'] ?? '';
            if (! is_string($notes) || trim($notes) === '') {
                $out[] = $row;

                continue;
            }
            if (! preg_match('/^(?:'.$multiTypes.')/u', $type) && ! preg_match('/(?:'.$multiTypes.')/u', $notes)) {
                $out[] = $row;

                continue;
            }
            // Require enumerator + honorific (e.g. "2) सौ.") — do not split on "…413)" inside mobiles like (मो. 9284040413).
            $parts = preg_split(
                '/(?=\s*[0-9०-९]{1,2}\s*\)\s*(?:श्री\.|सौ\.|कै\.|चि\.|कु\.|डॉ\.))|(?=\s*[0-9०-९]{1,2}\s*\.\s*(?:श्री\.|सौ\.|कै\.))/u',
                $notes,
                -1,
                PREG_SPLIT_NO_EMPTY
            );
            $parts = array_values(array_filter(array_map('trim', $parts), fn ($p) => $p !== ''));
            // Multiline: relation line + following lines that are clearly additional people (श्री./सौ.).
            if (count($parts) <= 1 && preg_match('/\R/u', $notes)) {
                $nl = preg_split('/\R+/u', $notes, -1, PREG_SPLIT_NO_EMPTY);
                $nl = array_values(array_filter(array_map('trim', $nl), fn ($p) => $p !== ''));
                if (count($nl) >= 2) {
                    $onlyShriTail = true;
                    foreach (array_slice($nl, 1) as $seg) {
                        if (! preg_match('/^(?:श्री\.|सौ\.)/u', $seg)) {
                            $onlyShriTail = false;
                            break;
                        }
                    }
                    if ($onlyShriTail) {
                        $parts = $nl;
                    }
                }
            }
            if (count($parts) <= 1) {
                $out[] = $row;

                continue;
            }
            foreach ($parts as $segment) {
                if (! $this->isMeaningfulRelativeNote($segment)) {
                    continue;
                }
                $out[] = [
                    'relation_type' => $type !== '' ? $type : ($row['relation_type'] ?? null),
                    'notes' => $segment,
                ];
            }
        }

        return $out;
    }

    private function isModernProfileStyleBiodata(string $rawText, string $normalizedText): bool
    {
        // Score-based gate: HTML tables alone must NOT flip Marathi table-OCR samples.
        $t = $normalizedText;
        $score = 0;

        if (stripos($rawText, '<table') !== false || stripos($rawText, '</tr>') !== false) {
            $score += 1;
        }
        // Strong signals: modern section headers.
        if (preg_match('/\bBasic\s+Details\b/i', $t)) {
            $score += 2;
        }
        if (preg_match('/\bReligious\s+Background\b/i', $t)) {
            $score += 2;
        }
        if (preg_match('/\bLocation,\s*Education\s*&\s*Career\b/i', $t)) {
            $score += 2;
        }
        // Key-value English fields common in profiles.
        if (preg_match('/\bMarital\s+Status\s*[:\-]/i', $t)) {
            $score += 1;
        }
        if (preg_match('/\bCommunity\s*[:\-]/i', $t) || preg_match('/\bSub-Community\s*[:\-]/i', $t)) {
            $score += 1;
        }
        if (preg_match('/\bHighest\s+Qualification\s*[:\-]/i', $t)) {
            $score += 1;
        }
        if (preg_match('/\bLiving\s+in\s*[:\-]/i', $t) || preg_match('/\bNative\s+Place\s*[:\-]/i', $t)) {
            $score += 1;
        }
        if (preg_match('/\bContact\s+No\.?\s*[:\-]/i', $t) || preg_match('/\bContact\s+Number\s*[:\-]/i', $t)) {
            $score += 1;
        }
        // Markdown full-name heading (English): "## Sourabh Dharmadhikari"
        if (preg_match('/^\s*##\s*[A-Za-z][A-Za-z\'\-\. ]{2,}$/m', $t)) {
            $score += 1;
        }

        return $score >= 3;
    }

    private function parseModernProfileStyleBiodata(string $rawText, string $normalizedText): array
    {
        $out = $this->emptySsotSkeleton();
        $core = [];

        $tableKvs = $this->extractModernProfileHtmlTableKeyValues($rawText);

        $t = $normalizedText;
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace('/\s+/u', ' ', (string) $t) ?? $t;
        $t = trim((string) $t);

        // 1) Full name from markdown heading or html heading.
        $fullName = null;
        if (preg_match('/^\s*##\s*(.+)$/m', $normalizedText, $hm)) {
            $cand = trim($hm[1]);
            $cand = preg_replace('/\s+#+\s*$/u', '', (string) $cand) ?? $cand;
            $cand = trim((string) $cand);
            if ($cand !== '' && preg_match('/[A-Za-z]/', $cand)) {
                $fullName = $cand;
            }
        }
        if ($fullName === null && preg_match('/<h[1-3][^>]*>\s*([^<]+)\s*<\/h[1-3]>/i', $rawText, $hh)) {
            $cand = trim(strip_tags($hh[1]));
            if ($cand !== '' && preg_match('/[A-Za-z]/', $cand)) {
                $fullName = $cand;
            }
        }
        if ($fullName !== null) {
            $core['full_name'] = $fullName;
        }

        // Date of birth (English profile key).
        $dobRaw = $tableKvs['Date of Birth'] ?? $this->extractModernProfileValue($normalizedText, ['Date of Birth', 'Birth Date', 'DOB']);
        if (is_string($dobRaw) && trim($dobRaw) !== '') {
            $iso = $this->normalizeModernProfileDateOfBirth(trim($dobRaw));
            if ($iso !== null) {
                $core['date_of_birth'] = $iso;
            }
        }

        // Height (profile key like 5' 4")
        $heightRaw = $tableKvs['Height'] ?? $this->extractModernProfileValue($normalizedText, ['Height']);
        if (is_string($heightRaw) && trim($heightRaw) !== '') {
            $heightParsed = $this->normalizeModernProfileHeight(trim($heightRaw));
            if ($heightParsed['height_cm'] !== null) {
                $core['height_cm'] = $heightParsed['height_cm'];
                $core['height'] = $heightParsed['height'];
            }
        }

        // 2) Marital status (English).
        $marital = $tableKvs['Marital Status'] ?? $this->extractModernProfileValue($normalizedText, ['Marital Status']);
        if (is_string($marital) && $marital !== '') {
            $m = strtolower(trim($marital));
            $map = [
                'divorced' => 'divorced',
                'unmarried' => 'unmarried',
                'never married' => 'unmarried',
                'married' => 'married',
                'widow' => 'widow',
                'widower' => 'widower',
                'separated' => 'separated',
            ];
            if (isset($map[$m])) {
                $core['marital_status'] = $map[$m];
            }
        }

        // Religion
        $religion = $tableKvs['Religion'] ?? $this->extractModernProfileValue($normalizedText, ['Religion']);
        if (is_string($religion) && trim($religion) !== '') {
            $core['religion'] = $this->cleanModernProfileLooseValue(trim($religion));
        }

        // 3) Caste / sub-caste split.
        $community = $tableKvs['Community'] ?? $this->extractModernProfileValue($normalizedText, ['Community']);
        if (is_string($community) && $community !== '') {
            $comm = trim($community);
            $parts = preg_split('/\s*-\s*/u', $comm, 2);
            $caste = trim((string) ($parts[0] ?? ''));
            $sub = trim((string) ($parts[1] ?? ''));
            if ($caste !== '') {
                $core['caste'] = $caste;
            }
            if ($sub !== '') {
                $core['sub_caste'] = $sub;
            }
        }

        // 4) Education.
        $hq = $tableKvs['Highest Qualification'] ?? $this->extractModernProfileValue($normalizedText, ['Highest Qualification', 'Highest Qualification & Career']);
        if (is_string($hq) && $hq !== '') {
            $v = $this->cleanModernProfileLooseValue(trim($hq));
            $v = preg_replace('/\s*&\s*Career\s*$/i', '', $v) ?? $v;
            // Table variants: ": B.E / B.Tech - Bachelor of Engineering / Bachelor of Technology" → keep only the degree head.
            $v = ltrim((string) $v, " \t\n\r\0\x0B:-：");
            $v = trim((string) $v);
            if (preg_match('/B\.?\s*E\.?/i', $v) && preg_match('/B\.?\s*Tech/i', $v)) {
                $v = 'B.E / B.Tech';
            } elseif (preg_match('/^(B\.?\s*E\.?\s*\/\s*B\.?\s*Tech(?:\s*\.?)?)\b/i', $v, $mDeg)) {
                $v = preg_replace('/\s+/u', ' ', (string) $mDeg[1]) ?? (string) $mDeg[1];
                $v = trim((string) $v);
            } elseif (preg_match('/^([A-Za-z0-9\.\s\/&\-\(\)]+?)(?:\s*-\s*[A-Za-z].*)$/u', $v, $mHead)) {
                $v = trim((string) $mHead[1]);
            }
            $v = trim((string) $v);
            if ($v !== '') {
                $core['highest_education'] = $v;
            }
        }

        // 5) Family status: explicitly ignore for father/mother names (no schema here).
        // (We still extract but do not store into core.*name)
        $fatherStatus = $this->extractModernProfileValue($normalizedText, ["Father's Status"]);
        $motherStatus = $this->extractModernProfileValue($normalizedText, ["Mother's Status"]);
        unset($fatherStatus, $motherStatus);

        // 6) Remove AI hallucinations: if not present in raw, keep null.
        $core['blood_group'] = null;

        // 7) Address build.
        $livingIn = $tableKvs['Living in'] ?? $this->extractModernProfileValue($normalizedText, ['Living in']);
        if (is_string($livingIn) && trim($livingIn) !== '') {
            $core['address_line'] = $this->cleanModernProfileLooseValue((string) $livingIn);
        }

        // 8) Contacts.
        $contactRaw = $tableKvs['Contact No.'] ?? $tableKvs['Contact No'] ?? $tableKvs['Contact Number']
            ?? $this->extractModernProfileValue($normalizedText, ['Contact No.', 'Contact No', 'Contact Number', 'Contact']);
        $contacts = [];
        if (is_string($contactRaw) && $contactRaw !== '') {
            $digits = \App\Services\Ocr\OcrNormalize::normalizeDigits($contactRaw);
            $digits = preg_replace('/\D/u', '', (string) $digits) ?? '';
            if (strlen($digits) >= 10) {
                $digits = substr($digits, -10);
            }
            if (preg_match('/^[6-9]\d{9}$/', (string) $digits)) {
                $contacts[] = [
                    'type' => 'alternate',
                    'number' => $digits,
                    'label' => 'profile',
                    'is_primary' => false,
                ];
            }
        }

        $out['core'] = $core;
        $out['contacts'] = $contacts;

        return $out;
    }

    private function cleanModernProfileLooseValue(string $v): string
    {
        $v = html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $v = trim(preg_replace('/\s+/u', ' ', (string) $v) ?? $v);
        // Common table flattener artifacts: "- : value", ": value"
        $v = preg_replace('/^\s*[\-*•]?\s*[:\-]\s*/u', '', (string) $v) ?? $v;
        $v = preg_replace('/^\s*[\-*•]\s*:\s*/u', '', (string) $v) ?? $v;
        $v = trim((string) $v);

        return $v;
    }

    /**
     * Pull key/value pairs out of HTML <table> profile blocks.
     * Keeps only simple 2-column rows ("Key" + ": Value") and ignores noisy multi-column duplicates.
     *
     * @return array<string, string>
     */
    private function extractModernProfileHtmlTableKeyValues(string $rawText): array
    {
        if ($rawText === '' || stripos($rawText, '<table') === false) {
            return [];
        }
        $html = html_entity_decode($rawText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (! preg_match('/<table\b/is', $html)) {
            return [];
        }
        $prev = libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $wrapped = '<?xml encoding="UTF-8"><body>'.$html.'</body>';
        if (@$dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD) === false) {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);

            return [];
        }
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return [];
        }

        $out = [];
        foreach ($body->getElementsByTagName('tr') as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $child) {
                if (! $child instanceof \DOMElement) {
                    continue;
                }
                $tag = strtolower($child->tagName);
                if (! in_array($tag, ['td', 'th'], true)) {
                    continue;
                }
                $txt = trim(self::stripResidualHtmlTagsFromString($child->textContent ?? ''));
                $txt = trim(preg_replace('/\s+/u', ' ', (string) $txt) ?? $txt);
                if ($txt !== '') {
                    $cells[] = $txt;
                }
            }
            if (count($cells) < 2) {
                continue;
            }
            $k = trim((string) ($cells[0] ?? ''));
            $v = trim(implode(' ', array_slice($cells, 1)));
            // Common pattern: second cell is ": Kolhapur, Maharashtra" — keep value.
            if ($k !== '' && $v !== '' && ! isset($out[$k])) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    private function normalizeModernProfileDateOfBirth(string $raw): ?string
    {
        $r = trim($raw);
        $r = preg_replace('/^\s*[:\-]\s*/u', '', $r) ?? $r;
        $r = trim((string) $r);
        if ($r === '') {
            return null;
        }
        // Try dd-MMM yyyy (e.g. 08-Aug 1983)
        if (preg_match('/^(\d{1,2})\s*[-\/]\s*([A-Za-z]{3,})\s*[-\/]\s*(\d{4})$/', $r, $m)) {
            $dd = (int) $m[1];
            $mon = strtolower($m[2]);
            $yy = (int) $m[3];
            $map = [
                'jan' => 1, 'january' => 1,
                'feb' => 2, 'february' => 2,
                'mar' => 3, 'march' => 3,
                'apr' => 4, 'april' => 4,
                'may' => 5,
                'jun' => 6, 'june' => 6,
                'jul' => 7, 'july' => 7,
                'aug' => 8, 'august' => 8,
                'sep' => 9, 'sept' => 9, 'september' => 9,
                'oct' => 10, 'october' => 10,
                'nov' => 11, 'november' => 11,
                'dec' => 12, 'december' => 12,
            ];
            $key = substr($mon, 0, 3);
            $mm = $map[$mon] ?? ($map[$key] ?? null);
            if ($mm !== null && checkdate((int) $mm, $dd, $yy)) {
                return sprintf('%04d-%02d-%02d', $yy, $mm, $dd);
            }
        }
        // Fallback: use existing normalizer when input is already numeric-ish.
        $try = \App\Services\Ocr\OcrNormalize::normalizeDate($r);

        return ($try !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $try)) ? (string) $try : null;
    }

    /**
     * @return array{height:?string,height_cm:?float}
     */
    private function normalizeModernProfileHeight(string $raw): array
    {
        $r = trim($raw);
        $r = preg_replace('/^\s*[:\-]\s*/u', '', $r) ?? $r;
        $r = trim((string) $r);
        if ($r === '') {
            return ['height' => null, 'height_cm' => null];
        }
        if (preg_match('/(\d)\s*\'\s*(\d{1,2})\s*(?:\"|in\b)?/u', $r, $m)) {
            $ft = (int) $m[1];
            $in = (int) $m[2];
            if ($ft >= 3 && $ft <= 7 && $in >= 0 && $in <= 11) {
                $cm = round((($ft * 12) + $in) * 2.54, 2);

                return [
                    'height' => $ft.' ft '.$in.' in',
                    'height_cm' => $cm,
                ];
            }
        }

        return ['height' => null, 'height_cm' => null];
    }

    /**
     * Extract "Key : Value" from section-style modern profiles (English). Stops at newline.
     */
    private function extractModernProfileValue(string $text, array $keys): ?string
    {
        foreach ($keys as $k) {
            $q = preg_quote($k, '/');
            // Line-anchored match: keys often contain dots (e.g. "Contact No.") so \b boundaries are unreliable.
            if (preg_match('/^\s*(?:[\-*•]\s*)?'.$q.'\s*[:\-]\s*([^\n\r<]+)/imu', $text, $m)) {
                $v = trim((string) $m[1]);
                $v = html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $v = trim(preg_replace('/\s+/u', ' ', (string) $v) ?? $v);

                return $v !== '' ? $v : null;
            }
        }

        return null;
    }

    /**
     * Split चुलते/चुलती (and similar) notes where multiple persons are separated by "/" before the next honorific.
     * Avoids treating "/" before मु. पो. (address only) as a person boundary.
     */
    private function splitRelativeRowsBySlashPersonBoundaries(array $relatives): array
    {
        $allowedTypes = ['चुलते', 'चुलती', 'मामा', 'काका', 'काकू', 'दाजी', 'मावशी', 'आत्या'];
        $out = [];
        foreach ($relatives as $row) {
            $type = (string) ($row['relation_type'] ?? '');
            $notes = $row['notes'] ?? '';
            if (! is_string($notes) || trim($notes) === '') {
                $out[] = $row;

                continue;
            }
            if (! in_array($type, $allowedTypes, true) && ! preg_match('/^(?:मुलीचे|मुलाचे)\s+चुलते\b/u', $notes)) {
                $out[] = $row;

                continue;
            }
            if (! preg_match('/\/\s*(?=श्री\.|सौ\.|कै\.)/u', $notes)) {
                $out[] = $row;

                continue;
            }
            $parts = preg_split('/\s*\/\s*(?=(?:श्री\.|सौ\.|कै\.))/u', $notes, -1, PREG_SPLIT_NO_EMPTY);
            $parts = array_values(array_filter(array_map('trim', $parts), fn ($p) => $p !== ''));
            if (count($parts) <= 1) {
                $out[] = $row;

                continue;
            }
            foreach ($parts as $segment) {
                if (! $this->isMeaningfulRelativeNote($segment)) {
                    continue;
                }
                $out[] = [
                    'relation_type' => $type !== '' ? $type : ($row['relation_type'] ?? null),
                    'notes' => $segment,
                ];
            }
        }

        return $out;
    }

    /**
     * चुलते line with comma-separated persons (no enumerator): "कै. शामराव…, कृष्णा…, हरि…" → one row per person.
     *
     * @param  array<int, array<string, mixed>>  $relatives
     * @return array<int, array<string, mixed>>
     */
    private function splitRelativeRowsByCommaSeparatedChulute(array $relatives): array
    {
        $out = [];
        foreach ($relatives as $row) {
            $type = (string) ($row['relation_type'] ?? '');
            $notes = $row['notes'] ?? '';
            if ($type !== 'चुलते' || ! is_string($notes) || (mb_strpos($notes, ',') === false && mb_strpos($notes, '，') === false)) {
                $out[] = $row;

                continue;
            }
            $notes = trim((string) preg_replace('/^[\s\-–—•*]+\s*/u', '', $notes));
            $stripped = trim((string) preg_replace('/^(?:चुलते|चुलती)\s*[:\-]+\s*/u', '', $notes));
            $parts = preg_split('/\s*[,，]\s*/u', $stripped, -1, PREG_SPLIT_NO_EMPTY);
            $parts = array_values(array_filter(array_map('trim', $parts), fn ($p) => $p !== ''));
            if (count($parts) < 2) {
                $out[] = $row;

                continue;
            }
            $allNamed = true;
            foreach ($parts as $p) {
                if (mb_strlen($p) < 4) {
                    $allNamed = false;
                    break;
                }
            }
            if (! $allNamed) {
                $out[] = $row;

                continue;
            }
            foreach ($parts as $segment) {
                $seg = trim($segment);
                if ($seg === '') {
                    continue;
                }
                $out[] = [
                    'relation_type' => 'चुलते',
                    'notes' => $seg,
                ];
            }
        }

        return $out;
    }

    /**
     * Align career_history rows with wizard / ManualSnapshotBuilder keys (additive).
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function normalizeCareerHistoryCanonicalKeys(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $r = $row;
            if (! empty($r['role']) && empty($r['occupation_title'])) {
                $r['occupation_title'] = $r['role'];
            }
            if (! empty($r['job_title']) && empty($r['occupation_title'])) {
                $r['occupation_title'] = $r['job_title'];
            }
            if (! empty($r['company']) && empty($r['company_name'])) {
                $r['company_name'] = $r['company'];
            }
            if (! empty($r['employer']) && empty($r['company_name'])) {
                $r['company_name'] = $r['employer'];
            }
            if (! empty($r['location']) && empty($r['work_location_text'])) {
                $r['work_location_text'] = is_string($r['location']) ? $r['location'] : null;
            }
            $out[] = $r;
        }

        return $out;
    }

    private function extractField(string $text, array $labels): ?string
    {
        foreach ($labels as $label) {
            $quoted = preg_quote($label, '/');
            if ($label === 'आई') {
                $quoted = 'आई(?!\s*चे)';
            }
            // "(मामा) :-" must not match as core field "मामा": loose third alternative would capture ") :- …".
            if ($label === 'मामा') {
                if (preg_match('/(?:^|(?<!\())\s*'.$quoted.'(?!\s*\))\s*(?::\s*-\s*|:\s*[:\-–—]{0,4}\s*)(.+?)(?=\n|$)/us', $text, $match)) {
                    return $this->cleanValue(trim($match[1]));
                }

                continue;
            }
            if ($label === 'नोकरी') {
                $quoted = 'नोकरी(?!\s*,)';
            }
            if (preg_match('/'.$quoted.'\s*(?::\s*-\s*|:\s*[:\-]{0,4}\s*|\s*[:\-]{0,5}\s*)(.+?)(?=\n|$)/us', $text, $match)) {
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
        if (\function_exists('normalizer_normalize')) {
            $n = normalizer_normalize($v, \Normalizer::FORM_C);
            if (is_string($n) && $n !== '') {
                $v = $n;
            }
        }
        $v = trim((string) preg_replace('/^[\-\s]+/u', '', $v));
        $v = trim(preg_replace('/\s+/u', ' ', $v) ?? '');
        if (mb_strlen($v) > 40 || mb_strpos($v, 'नोकरी') !== false || preg_match('/\d{10}/', $v)) {
            return null;
        }
        // Horoscope varna is a small closed set; reject complexion words like "गोरा".
        if (preg_match('/^(?:गोरा|सावळा|काळा|कृष्ण|फेऱ|फेस)/u', $v)) {
            return null;
        }
        // Allow common OCR alternates for the four varnas (same canonical output).
        if (preg_match('/^वैश्य$/u', $v)) {
            return 'वैश्य';
        }
        if (preg_match('/^क्षत्रिय$/u', $v)) {
            return 'क्षत्रिय';
        }
        if (preg_match('/^ब्राह्मण$/u', $v)) {
            return 'ब्राह्मण';
        }
        if (preg_match('/^शूद्र$/u', $v)) {
            return 'शूद्र';
        }

        return null;
    }

    /** Known labels that must not be captured as field values (wrong assignment). */
    private const LABEL_NOISE = [
        'जन्म वेळ', 'जन्म स्थळ', 'जन्म तारीख', 'जन्मतारीख', 'जन्मवार', 'जन्मवार व वेळ', 'वर्ण', 'शिक्षण', 'शिक्षिण', 'आईचे नाव', 'वडिलांचे नाव',
        'नाव', 'मुलीचे नाव', 'मुलाचे नाव', 'वधूचे नाव', 'नावरस नाव', 'नांवटस नाव', 'नवरस नाव', 'जात', 'धर्म', 'उंची', 'गोत्र', 'कुलदैवत', 'नाडी', 'गण',
        'रास', 'राशी', 'सध्याचा पत्ता', 'मोबाईल नं', 'कौटुंबिक माहिती', 'संपर्क', 'वैवाहिक स्थिती',
    ];

    /**
     * True when the entire string is a field label / header fragment, not real data.
     */
    private function isLikelyLabelOnlyValue(string $value): bool
    {
        $v = preg_replace('/\s+/u', ' ', trim($value));
        if ($v === '') {
            return true;
        }
        $candidates = array_merge(self::LABEL_NOISE, [
            'जन्मस्थळ', 'Birth place', 'Date of birth', 'Full name', 'Name', 'Occupation', 'नोकरी',
        ]);
        foreach ($candidates as $p) {
            if ($v === $p) {
                return true;
            }
        }
        // Normalized equality (case-insensitive ASCII + exact Devanagari duplicates already covered)
        $lower = mb_strtolower($v);
        foreach (['date of birth', 'full name', 'birth date'] as $en) {
            if ($lower === $en) {
                return true;
            }
        }
        // Header-only fragments (no person-name substance)
        if (preg_match('/^(?:नाव|जन्म|रास|नाडी|गण|धर्म|जात|उंची|शिक्षण)(?:\s*[:\-।.|]+)?$/u', $v)) {
            return true;
        }
        if (preg_match('/^नावरस\s+नाव$/u', $v) || preg_match('/^नांवटस\s+नाव$/u', $v) || preg_match('/^नवरस\s+नाव$/u', $v)) {
            return true;
        }

        return false;
    }

    /**
     * HTML &lt;table&gt; rows → structured hints (runs before separated + inline-derived core). Priority: table → separated → heuristics.
     *
     * @param  array<string, mixed>  $core
     * @param  array<string, string>  $hints
     * @param  list<array<string, mixed>>  $contacts
     * @param  list<array<string, mixed>>  $careerHistory
     * @param  list<array<string, mixed>>  $relativesRows
     * @param  list<array<string, mixed>>  $educationHistory
     * @param  list<array<string, mixed>>  $horoscope
     * @param  array<string, true>  $htmlStructuredFieldLocks  Fields set from HTML table — OCR/separated/AI must not overwrite.
     */
    private function mergeHtmlTableStructuredHints(
        array &$core,
        array $hints,
        array &$contacts,
        array &$careerHistory,
        array &$relativesRows,
        array &$educationHistory,
        ?string &$propertySummary,
        ?string &$addressBlock,
        ?string &$residentialAddressLine,
        string $text,
        array &$siblings,
        array &$horoscope,
        array &$htmlStructuredFieldLocks
    ): void {
        if (! \App\Services\Parsing\HtmlMarathiBiodataTableExtractor::isStructuredTableHints($hints)) {
            return;
        }
        unset($hints[\App\Services\Parsing\HtmlMarathiBiodataTableExtractor::STRUCTURED_MARKER]);

        $fill = fn (?string $cur): bool => $cur === null || trim((string) $cur) === ''
            || $this->isLikelyLabelOnlyValue((string) $cur);

        $rejectFatherNoise = static function (string $v): bool {
            if (preg_match('/मुलाची\s*आई/u', $v)) {
                return true;
            }
            if (preg_match('/^मुलाचे\s*भाऊ$/u', trim($v))) {
                return true;
            }

            return false;
        };
        $rejectMotherNoise = static function (string $v): bool {
            return (bool) preg_match('/मुलाचे\s*वडील/u', $v);
        };

        if (isset($hints['full_name'])) {
            $cand = trim($hints['full_name']);
            $cur = (string) ($core['full_name'] ?? '');
            if ($this->shouldForceReplaceHtmlTablePersonName($cur, $cand, 'full_name')) {
                $v = $this->validateFullName($this->cleanValue($cand));
                if ($v !== null) {
                    $core['full_name'] = $v;
                }
            }
        }

        if (isset($hints['date_of_birth'])) {
            $cand = trim($hints['date_of_birth']);
            $cur = (string) ($core['date_of_birth'] ?? '');
            if ($this->shouldApplyStructuredTableHint($cur, $cand, 'date_of_birth')) {
                $raw = $this->rejectBirthDateRawWithoutDigits(
                    $this->truncateDobRawAtBirthTimeLabel($cand)
                );
                if ($raw !== null) {
                    $dob = \App\Services\Ocr\OcrNormalize::normalizeDate($raw);
                    if ($dob === $raw) {
                        $dob = $this->normalizeDate($raw);
                    }
                    if ($dob !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $dob)) {
                        $core['date_of_birth'] = $dob;
                    }
                }
            }
        }

        if (isset($hints['birth_place'])) {
            $cand = trim($hints['birth_place']);
            $cur = (string) ($core['birth_place'] ?? '');
            if ($this->shouldApplyStructuredTableHint($cur, $cand, 'birth_place')) {
                $v = $this->rejectIfLabelNoise($this->cleanValue($cand));
                if ($v !== null && ! $this->valueSmellsLikeHoroscopeOrGotraLeak($v)) {
                    $core['birth_place'] = $v;
                    $core['birth_place_text'] = $v;
                }
            }
        }

        if (isset($hints['birth_time'])) {
            $cand = trim($hints['birth_time']);
            $cur = (string) ($core['birth_time'] ?? '');
            if ($this->shouldApplyStructuredTableHint($cur, $cand, 'birth_time')) {
                $bt = $this->normalizeBirthTime($cand);
                if ($bt !== null) {
                    $core['birth_time'] = $bt;
                }
            }
        }

        if (isset($hints['height'])) {
            $cand = trim($hints['height']);
            if ($this->shouldApplyStructuredTableHint((string) ($core['height'] ?? ''), $cand, 'height')
                || (($core['height_cm'] ?? null) === null && $cand !== '')) {
                $heightNorm = \App\Services\Ocr\OcrNormalize::normalizeHeight($cand);
                $cm = $this->normalizeHeight($heightNorm ?? $cand);
                if ($cm !== null && $cm > 0) {
                    $core['height_cm'] = $cm;
                    $core['height'] = $this->formatHeightFeetInchesDisplay($cm, $cand, $heightNorm);
                }
            }
        }

        if (isset($hints['complexion'])) {
            $cand = trim($hints['complexion']);
            $cur = (string) ($core['complexion'] ?? '');
            if ($this->shouldApplyStructuredTableHint($cur, $cand, 'complexion')) {
                $v = $this->rejectBleededPhysicalComplexion($this->rejectIfLabelNoise($this->cleanValue($cand)));
                if ($v !== null && $v !== '') {
                    $core['complexion'] = $v;
                }
            }
        }

        if (isset($hints['blood_group'])) {
            $cand = trim($hints['blood_group']);
            $cur = (string) ($core['blood_group'] ?? '');
            if ($this->shouldApplyStructuredTableHint($cur, $cand, 'blood_group')) {
                $raw = $this->cleanValue($cand);
                $bg = \App\Services\Ocr\OcrNormalize::normalizeBloodGroup($raw);
                if ($bg) {
                    $bg = \App\Services\Ocr\OcrNormalize::applyBaselinePatterns('blood_group', $bg);
                }
                if ($bg === $raw) {
                    $bg = $this->normalizeBloodGroup($raw);
                }
                $bg = $this->validateBloodGroupStrict($bg);
                if ($bg !== null) {
                    $core['blood_group'] = $bg;
                }
            }
        }

        $mergedEdu = false;
        if (isset($hints['highest_education'])) {
            $cand = trim($hints['highest_education']);
            $cur = (string) ($core['highest_education'] ?? '');
            if ($this->shouldApplyStructuredTableHint($cur, $cand, 'highest_education')) {
                $v = $this->cleanValue($cand);
                if (! $this->valueSmellsLikeHoroscopeOrGotraLeak($v)) {
                    $v = $this->validateEducation($v) ?? ($fill($cur) && mb_strlen($v) >= 3 ? $v : null);
                    if ($v !== null) {
                        $core['highest_education'] = $v;
                        $mergedEdu = true;
                    }
                }
            }
        }
        if ($mergedEdu && ! empty($core['highest_education'])) {
            $educationHistory = [[
                'degree' => $this->stripTrailingEducationNoise((string) $core['highest_education']),
                'institution' => null,
                'year' => null,
            ]];
        }

        if (isset($hints['occupation_raw'])) {
            $cand = trim($hints['occupation_raw']);
            if ($cand !== ''
                && (($core['occupation_title'] ?? null) === null && $careerHistory === [])
                && $this->shouldApplyStructuredTableHint('', $cand, 'occupation_raw')) {
                $v = $this->cleanValue($cand);
                $v = $this->validateCareer($v) ?? ($v !== '' ? $v : null);
                if ($v !== null && ! $this->valueSmellsLikeHoroscopeOrGotraLeak($v)) {
                    $careerHistory = [[
                        'role' => $v,
                        'occupation_title' => $v,
                        'job_title' => $v,
                        'employer' => null,
                        'company_name' => null,
                        'from' => null,
                        'to' => null,
                    ]];
                    $core['occupation_title'] = $v;
                }
            }
        }

        if (isset($hints['kuldaivat'])) {
            $cand = trim($hints['kuldaivat']);
            $cur = (string) ($core['kuldaivat'] ?? '');
            if ($this->shouldApplyStructuredTableHint($cur, $cand, 'kuldaivat')) {
                $v = $this->rejectIfLabelNoise($this->cleanValue($cand));
                if ($v !== null && ! $this->valueSmellsLikeHoroscopeOrGotraLeak($v)) {
                    $core['kuldaivat'] = $v;
                }
            }
        }

        if (isset($hints['gotra'])) {
            $cand = trim($hints['gotra']);
            $cur = (string) ($core['gotra'] ?? '');
            if ($this->shouldApplyStructuredTableHint($cur, $cand, 'gotra')) {
                $v = $this->rejectIfLabelNoise($this->cleanValue($cand));
                if ($v !== null) {
                    $core['gotra'] = $this->balanceTrailingParenthesesInGotra($v);
                }
            }
        }

        if (isset($hints['devak'])) {
            $cand = trim($hints['devak']);
            $cur = (string) ($core['devak'] ?? '');
            if ($this->shouldApplyStructuredTableHint($cur, $cand, 'devak')) {
                $v = $this->rejectHoroscopeJunk($this->rejectIfLabelNoise($this->cleanValue($cand)));
                if ($v !== null && trim($v) !== '' && trim($v) !== 'देव') {
                    $core['devak'] = $v;
                }
            }
        }

        if (isset($hints['caste'])) {
            $cand = trim($hints['caste']);
            $cur = (string) ($core['caste'] ?? '');
            if ($this->shouldApplyStructuredTableHint($cur, $cand, 'caste')) {
                $v = $this->validateCaste($this->normalizeCasteValue($this->cleanValue($cand)));
                if ($v !== null) {
                    $core['caste'] = $v;
                }
            }
        }

        $fatherCur = (string) ($core['father_name'] ?? '');
        if (isset($hints['father_name'])) {
            $cand = trim(preg_replace('/\s+/u', ' ', trim($hints['father_name'])) ?? '');
            if ($this->shouldForceReplaceHtmlTablePersonName($fatherCur, $cand, 'father_name')
                || $this->separatedLayoutParentFieldLooksSwapped($fatherCur, 'father')) {
                if (! $rejectFatherNoise($cand)) {
                    $fatherOccFromHint = null;
                    $rawFather = $cand;
                    if ($rawFather !== '' && preg_match('/\(([^)]+)\)\s*$/u', $rawFather, $fpm)) {
                        $inner = $this->cleanValue(trim($fpm[1]));
                        if ($inner !== '' && ! $this->isMaritalStatusOccupationLeak($inner)) {
                            $fatherOccFromHint = $inner;
                        }
                        $rawFather = trim(preg_replace('/\s*\([^)]+\)\s*$/u', '', $rawFather));
                    }
                    [$fn, $fph] = $this->extractFatherNameAndPhoneFromVadilancheNaavLine('वडिलांचे नांव :- '.$rawFather);
                    $raw = $fn !== null ? $fn : $this->rejectIfLabelNoise($this->cleanValue($rawFather));
                    if ($raw !== null) {
                        $v = $this->stripTrailingMobileFragmentFromPersonLine($raw);
                        $v = $v !== null ? $this->cleanPersonName($v) : null;
                        $v = $this->validateFatherName($v);
                        if ($v !== null) {
                            $core['father_name'] = $v;
                        }
                    }
                    if ($fatherOccFromHint !== null && ($core['father_occupation'] ?? null) === null) {
                        $core['father_occupation'] = $this->sanitizeCoreOccupation($fatherOccFromHint);
                    }
                    if ($fph !== null && preg_match('/^[6-9]\d{9}$/', $fph)) {
                        $core['father_contact_1'] = $core['father_contact_1'] ?? $fph;
                        $this->fatherContactSlotsMergePhone($contacts, $fph);
                    }
                }
            }
        }

        if (isset($hints['mother_name'])) {
            $cand = trim(preg_replace('/\s+/u', ' ', trim($hints['mother_name'])) ?? '');
            $motherCur = (string) ($core['mother_name'] ?? '');
            if ($this->shouldForceReplaceHtmlTablePersonName($motherCur, $cand, 'mother_name')
                || $this->separatedLayoutParentFieldLooksSwapped($motherCur, 'mother')) {
                if (! $rejectMotherNoise($cand)) {
                    $motherOccFromHint = null;
                    $rawMom = $this->rejectIfLabelNoise($this->cleanValue($cand));
                    if ($rawMom !== null && preg_match('/\(([^)]+)\)\s*$/u', $rawMom, $momPm)) {
                        $inner = $this->cleanValue(trim($momPm[1]));
                        if ($inner !== '' && ! $this->isMaritalStatusOccupationLeak($inner)) {
                            $motherOccFromHint = $inner;
                        }
                        $rawMom = trim(preg_replace('/\s*\([^)]+\)\s*$/u', '', $rawMom));
                    }
                    $v = $this->stripTrailingMobileFragmentFromPersonLine($rawMom);
                    $v = $v !== null ? $this->cleanPersonName($v) : null;
                    $v = $this->validateMotherName($v);
                    if ($v !== null) {
                        $core['mother_name'] = $v;
                    }
                    if ($motherOccFromHint !== null && ($core['mother_occupation'] ?? null) === null) {
                        $core['mother_occupation'] = $this->sanitizeCoreOccupation($motherOccFromHint);
                    }
                }
            }
        }

        if (isset($hints['address_native'])) {
            $cand = trim($hints['address_native']);
            $addr = $this->finalizeAddressBlockCandidate($this->cleanValue($cand));
            if ($addr !== null && $addr !== '') {
                $residentialAddressLine = $addr;
            }
        }

        if (isset($hints['address_current'])) {
            $cand = trim($hints['address_current']);
            $addr = $this->finalizeAddressBlockCandidate($this->cleanValue($cand));
            if ($addr !== null && $addr !== '') {
                $addressBlock = $addr;
                $core['address_line'] = $addr;
            }
        }

        if (isset($hints['primary_contact'])) {
            $blob = trim($hints['primary_contact']);
            if ($blob !== '') {
                $this->applyHtmlTablePrimaryContactAsAuthoritative($contacts, $blob, $text);
            }
        }

        if (isset($hints['property_summary'])) {
            $cand = trim($hints['property_summary']);
            $cur = (string) ($propertySummary ?? '');
            if (($this->hasAnyKeyword($text, self::PROPERTY_TRIGGER) || $fill($cur))
                && $this->shouldApplyStructuredTableHint($cur, $cand, 'property_summary')) {
                if (! $this->otherRelativesTextLooksPolluted($cand)) {
                    $propertySummary = $this->cleanValue($cand);
                }
            }
        }

        if (isset($hints['other_relatives_text'])) {
            $cand = trim($hints['other_relatives_text']);
            $t = $this->rejectIfLabelNoise($this->cleanValue($cand));
            if ($t !== null && $t !== '' && ! $this->isLikelyLabelOnlyValue($t)
                && ! $this->otherRelativesOcrFallbackHardReject($t)
                && ! $this->otherRelativesTextLooksPolluted($t)) {
                // HTML row is authoritative: replace OCR/footer-concatenated junk.
                $core['other_relatives_text'] = $t;
            }
        }

        $this->mergeHtmlTableSiblingHints($siblings, $hints);

        $appendRelIfMissing = function (string $relationType, string $note) use (&$relativesRows): void {
            foreach ($relativesRows as $r) {
                if (($r['relation_type'] ?? '') === $relationType) {
                    return;
                }
            }
            $note = trim(preg_replace('/\s+/u', ' ', $note) ?? '');
            if ($note === '' || $this->isLikelyLabelOnlyValue($note)) {
                return;
            }
            $relativesRows[] = [
                'relation_type' => $relationType,
                'name' => null,
                'address_line' => null,
                'location' => null,
                'occupation' => null,
                'contact_number' => null,
                'raw_note' => $this->relativeRawNoteCleanup($note, $relationType),
            ];
        };

        if (isset($hints['mama_note'])) {
            $appendRelIfMissing('मामा', $hints['mama_note']);
        }
        if (isset($hints['atya_note'])) {
            $appendRelIfMissing('आत्या', $hints['atya_note']);
        }
        if (isset($hints['ajol_note'])) {
            $appendRelIfMissing('आजोळ', $hints['ajol_note']);
        }

        $this->mergeHtmlTableHoroscopeHints($horoscope, $hints, $core);
        $this->applyHtmlTableStructuredFieldLocks($hints, $core, $htmlStructuredFieldLocks);
        $this->patchSiblingNamesHonorificSpacing($siblings);
        $this->patchRelativeRowNamesWhenStubOrShort($relativesRows);
    }

    /**
     * Mark core fields supplied by HTML table so OCR / separated layout / AI cannot overwrite them.
     *
     * @param  array<string, string>  $hints
     * @param  array<string, mixed>  $core
     * @param  array<string, true>  $htmlStructuredFieldLocks
     */
    private function applyHtmlTableStructuredFieldLocks(array $hints, array $core, array &$htmlStructuredFieldLocks): void
    {
        $scalar = [
            'full_name', 'date_of_birth', 'birth_place', 'birth_time', 'father_name', 'mother_name',
            'caste', 'other_relatives_text', 'blood_group', 'highest_education', 'complexion',
            'gotra', 'devak', 'kuldaivat', 'property_summary',
        ];
        foreach ($scalar as $k) {
            if (! isset($hints[$k]) || trim((string) $hints[$k]) === '') {
                continue;
            }
            if (($core[$k] ?? null) !== null && trim((string) $core[$k]) !== '') {
                $htmlStructuredFieldLocks[$k] = true;
            }
        }
        if (isset($hints['height']) && ($core['height_cm'] ?? null) !== null) {
            $htmlStructuredFieldLocks['height_cm'] = true;
        }
        if (isset($hints['occupation_raw']) && ($core['occupation_title'] ?? null) !== null && trim((string) $core['occupation_title']) !== '') {
            $htmlStructuredFieldLocks['occupation_title'] = true;
        }
        if (isset($hints['address_current']) && ($core['address_line'] ?? null) !== null && trim((string) $core['address_line']) !== '') {
            $htmlStructuredFieldLocks['address_line'] = true;
        }
        if (isset($hints['address_native']) && trim((string) $hints['address_native']) !== '') {
            $htmlStructuredFieldLocks['residential_address_hint'] = true;
        }
        if (isset($hints['primary_contact']) && trim((string) $hints['primary_contact']) !== '') {
            $htmlStructuredFieldLocks['contacts'] = true;
        }
        foreach ($hints as $hk => $_v) {
            if (is_string($hk) && str_starts_with($hk, 'horoscope_') && trim((string) $_v) !== '') {
                $htmlStructuredFieldLocks['horoscope'] = true;
                break;
            }
        }
        if (($core['varna'] ?? null) !== null && trim((string) $core['varna']) !== '' && isset($hints['horoscope_varna'])) {
            $htmlStructuredFieldLocks['varna'] = true;
        }
        foreach (['mama_note', 'atya_note', 'ajol_note'] as $rn) {
            if (isset($hints[$rn]) && trim((string) $hints[$rn]) !== '') {
                $htmlStructuredFieldLocks['relatives_rows_notes'] = true;

                break;
            }
        }
        if (isset($hints['mama_note']) && trim((string) $hints['mama_note']) !== '') {
            $htmlStructuredFieldLocks['mama'] = true;
        }
    }

    /**
     * Table मोबाईल नंबर row is authoritative: drop footer-only numbers not also present in the table cell, then add numbers from the cell.
     *
     * @param  list<array<string, mixed>>  $contacts
     */
    private function applyHtmlTablePrimaryContactAsAuthoritative(array &$contacts, string $blob, string $fullText): void
    {
        $blob = trim($blob);
        if ($blob === '') {
            return;
        }
        $blobNorm = \App\Services\Ocr\OcrNormalize::normalizeDigits($blob);
        $blobPhones = [];
        if (preg_match_all('/\b([6-9]\d{9})\b/u', $blobNorm, $bm)) {
            $blobPhones = array_values(array_unique($bm[1]));
        }
        $blobPhoneSet = array_fill_keys($blobPhones, true);
        $out = [];
        foreach ($contacts as $c) {
            $pn = \App\Services\Ocr\OcrNormalize::normalizePhone((string) ($c['number'] ?? $c['phone_number'] ?? '')) ?? '';
            if ($pn !== '' && str_contains($blobNorm, $pn)) {
                $out[] = $c;

                continue;
            }
            if ($pn !== '' && $this->phoneDigitsAppearOnlyOnFooterOrShopLines($fullText, $pn)) {
                continue;
            }
            if ($pn !== '' && $this->contactLineLooksLikeShopOrTypingFooter($fullText, $pn)) {
                continue;
            }
            // Table cell is final truth: drop OCR-scanned numbers that are not listed in the मोबाईल row.
            if ($blobPhones !== [] && $pn !== '' && ! isset($blobPhoneSet[$pn])) {
                continue;
            }
            $out[] = $c;
        }
        $contacts = $out;
        $this->appendAlternateContactsFromSeparatedHintBlob($contacts, $blob);
    }

    /** Strong reject list for OCR-derived other_relatives (HTML path uses separate gate). */
    private function otherRelativesOcrFallbackHardReject(string $value): bool
    {
        $v = trim($value);

        return (bool) preg_match(
            '/राशी|नक्षत्र|कुंडली|स्वामी|ग्रह|॥|कॉम्प्युटर|संपर्क\s*:|B-0|टायपिंग|टायप|Computer|Typing|जन्म\s*तारीख|जन्म\s*लग्न|लग्न\s*कुंडली|बायोडेटा|Biodata|Print\s+Shop|प्रिंट|मुद्रण/ui',
            $v
        ) || preg_match('/[\x{0C80}-\x{0CFF}]/u', $v);
    }

    /**
     * True if the only lines containing this phone match shop/typing/footer heuristics.
     *
     * @param  array<int, string>  $lines
     */
    private function contactLineLooksLikeShopOrTypingFooter(string $fullText, string $digits10): bool
    {
        if (! preg_match('/^[6-9]\d{9}$/', $digits10)) {
            return false;
        }
        $norm = \App\Services\Ocr\OcrNormalize::normalizeDigits($fullText);
        foreach (preg_split('/\R/u', $norm) ?: [] as $ln) {
            if (! str_contains((string) $ln, $digits10)) {
                continue;
            }
            if (preg_match('/कॉम्प्युटर|टायपिंग|टायप|Computer|Typing|B-0|संपर्क\s*:|Shop|Print|प्रिंट|मुद्रण/ui', $ln)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Apply horoscope label/value pairs from HTML table rows (ordered cells; avoids legend bleed into values).
     *
     * @param  array<string, string>  $hints
     * @param  array<string, mixed>  $core
     * @param  list<array<string, mixed>>  $horoscope
     */
    private function mergeHtmlTableHoroscopeHints(array &$horoscope, array $hints, array &$core): void
    {
        $hasHtmlHoroscopeHints = false;
        foreach (array_keys($hints) as $hk) {
            if (is_string($hk) && str_starts_with($hk, 'horoscope_') && trim((string) ($hints[$hk] ?? '')) !== '') {
                $hasHtmlHoroscopeHints = true;

                break;
            }
        }
        if ($hasHtmlHoroscopeHints) {
            foreach (['rashi', 'nadi', 'gan'] as $ck) {
                if (isset($core[$ck]) && $this->isLegendToken((string) $core[$ck])) {
                    $core[$ck] = null;
                }
            }
            if ($horoscope !== []) {
                $rk = &$horoscope[0];
                foreach (['rashi', 'nakshatra', 'nadi', 'gan', 'yoni', 'charan', 'navras_name', 'vairavarga'] as $fk) {
                    $vv = $rk[$fk] ?? null;
                    if ($vv !== null && $this->isLegendToken((string) $vv)) {
                        $rk[$fk] = null;
                    }
                }
            }
        }

        $map = [
            'horoscope_nakshatra' => 'nakshatra',
            'horoscope_charan' => 'charan',
            'horoscope_nadi' => 'nadi',
            'horoscope_yoni' => 'yoni',
            'horoscope_gan' => 'gan',
            'horoscope_rashi' => 'rashi',
            'horoscope_swami' => 'navras_name',
            'horoscope_vairavarga' => 'vairavarga',
        ];

        $patch = [];
        foreach ($map as $hintKey => $rowKey) {
            if (! isset($hints[$hintKey])) {
                continue;
            }
            $raw = trim(self::stripResidualHtmlTagsFromString(trim((string) $hints[$hintKey])));
            if ($raw === '' || $this->horoscopeHtmlValueLooksLikeLegendBleed($raw)) {
                continue;
            }
            if ($rowKey === 'rashi') {
                $v = $this->rejectHoroscopeJunk(self::sanitizeRashiDisplayText($raw));
            } elseif ($rowKey === 'nakshatra' || $rowKey === 'yoni') {
                $v = $this->rejectHoroscopeJunk($raw);
            } elseif ($rowKey === 'nadi') {
                $nadiRaw = $this->rejectHoroscopeJunk($raw);
                $v = $nadiRaw !== null ? ($this->normalizeNadiValue($nadiRaw) ?? $nadiRaw) : null;
                $v = $this->rejectHoroscopeJunk($v);
            } elseif ($rowKey === 'gan') {
                $v = self::sanitizeGanValue($raw);
            } elseif ($rowKey === 'charan') {
                $v = trim((string) $raw);
                $v = $v === '' ? null : $v;
            } else {
                $v = $this->rejectHoroscopeJunk($raw);
            }
            if ($v !== null && $v !== '') {
                $patch[$rowKey] = $v;
            }
        }

        if (isset($hints['horoscope_varna'])) {
            $vv = trim((string) $hints['horoscope_varna']);
            $vv = $vv === '' ? null : $this->validateVarna($this->cleanValue($vv));
            if ($vv !== null) {
                $core['varna'] = $vv;
            }
        }

        if ($patch === []) {
            return;
        }

        if ($horoscope === []) {
            $horoscope = [[
                'rashi_id' => null,
                'nakshatra_id' => null,
                'gan_id' => null,
                'nadi_id' => null,
                'yoni_id' => null,
                'mangal_dosh_type_id' => null,
                'rashi' => null,
                'nakshatra' => null,
                'charan' => null,
                'nadi' => null,
                'gan' => null,
                'yoni' => null,
                'devak' => null,
                'kuldaivat' => null,
                'gotra' => null,
                'navras_name' => null,
                'birth_weekday' => null,
                'vairavarga' => null,
            ]];
        }

        $row = &$horoscope[0];
        foreach ($patch as $k => $v) {
            $row[$k] = $v;
        }

        // Keep core scalar horoscope fields aligned with structured table row (OCR may have legend bleed).
        $sync = $horoscope[0];
        foreach (['rashi', 'nadi', 'gan'] as $hk) {
            if (isset($sync[$hk]) && $sync[$hk] !== null && trim((string) $sync[$hk]) !== '') {
                $core[$hk] = $sync[$hk];
            }
        }
    }

    /**
     * After AI / caste / separated layout: re-apply structured HTML table fields so OCR/footer never wins.
     *
     * @param  array<string, string>  $tableHints
     * @param  array<string, mixed>  $core
     * @param  list<array<string, mixed>>  $contacts
     * @param  list<array<string, mixed>>  $horoscope
     */
    private function finalizeStructuredHtmlTableSnapshot(
        array $tableHints,
        array &$core,
        ?string &$addressBlock,
        ?string &$residentialAddressLine,
        array &$contacts,
        array &$horoscope,
        string $text
    ): void {
        $hints = $tableHints;
        unset($hints[\App\Services\Parsing\HtmlMarathiBiodataTableExtractor::STRUCTURED_MARKER]);

        if (isset($hints['address_current'])) {
            $addr = $this->finalizeAddressBlockCandidate($this->cleanValue(trim((string) $hints['address_current'])));
            if ($addr !== null && $addr !== '') {
                $addressBlock = $addr;
                $core['address_line'] = $addr;
            }
        }
        if (isset($hints['address_native'])) {
            $addrN = $this->finalizeAddressBlockCandidate($this->cleanValue(trim((string) $hints['address_native'])));
            if ($addrN !== null && $addrN !== '') {
                $residentialAddressLine = $addrN;
            }
        }

        if (isset($hints['other_relatives_text'])) {
            $t = $this->rejectIfLabelNoise($this->cleanValue(trim((string) $hints['other_relatives_text'])));
            if ($t !== null && $t !== '' && ! $this->isLikelyLabelOnlyValue($t)
                && ! $this->otherRelativesOcrFallbackHardReject($t)) {
                $core['other_relatives_text'] = $t;
            }
        }

        if (isset($hints['primary_contact']) && trim((string) $hints['primary_contact']) !== '') {
            $this->rebuildContactsFromHtmlMobileBlobOnly($contacts, trim((string) $hints['primary_contact']));
        }

        $this->mergeHtmlTableHoroscopeHints($horoscope, $hints, $core);
    }

    /**
     * @param  list<array<string, mixed>>  $contacts
     */
    private function rebuildContactsFromHtmlMobileBlobOnly(array &$contacts, string $blob): void
    {
        $blob = trim($blob);
        if ($blob === '') {
            return;
        }
        $blobNorm = \App\Services\Ocr\OcrNormalize::normalizeDigits($blob);
        if (! preg_match_all('/\b([6-9]\d{9})\b/u', $blobNorm, $m) || $m[1] === []) {
            $contacts = [];

            return;
        }
        $contacts = [];
        $this->appendAlternateContactsFromSeparatedHintBlob($contacts, $blobNorm);
    }

    /** True when HTML cell value is another column header (legend bleed), not the real field value. */
    private function horoscopeHtmlValueLooksLikeLegendBleed(string $value): bool
    {
        return $this->isLegendToken($value);
    }

    /**
     * Horoscope grid label tokens that must never be stored as the field value (same-row value only).
     */
    private function isLegendToken(?string $value): bool
    {
        if ($value === null || trim($value) === '') {
            return false;
        }
        $t = trim($value);

        return (bool) preg_match('/^(नक्षत्र|चरण|नाडी|योनी|गण|रास|राशी|स्वामी|वर्ण|वैरवर्ग|मांगलिक)$/u', $t);
    }

    /**
     * Drop phone numbers that appear only on printer/shop/footer lines (not biodata body lines).
     */
    private function phoneDigitsAppearOnlyOnFooterOrShopLines(string $text, string $digits10): bool
    {
        if (! preg_match('/^[6-9]\d{9}$/', $digits10)) {
            return false;
        }
        $normText = \App\Services\Ocr\OcrNormalize::normalizeDigits($text);
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $normText) ?: [])));
        $matching = [];
        foreach ($lines as $i => $ln) {
            $lnNorm = \App\Services\Ocr\OcrNormalize::normalizeDigits($ln);
            if ($lnNorm !== null && str_contains((string) $lnNorm, $digits10)) {
                $matching[] = ['i' => $i, 'line' => $ln];
            }
        }
        if ($matching === []) {
            return false;
        }
        $n = count($lines);
        foreach ($matching as $m) {
            $ln = $m['line'];
            if ($this->lineLooksLikePrintFooterOrShopContact($ln)) {
                continue;
            }
            $idx = $m['i'];
            if ($n >= 4 && $idx >= $n - 3 && mb_strlen($ln) <= 52 && preg_match('/^[०-९0-9\s\/\-\(\)\.\,]+$/u', $ln)) {
                if (preg_match('/मोबाईल|मोबाइल|Mobile|संपर्क|Contact|Phone|नंबर/ui', $ln)) {
                    return false;
                }

                continue;
            }

            return false;
        }

        return true;
    }

    private function lineLooksLikePrintFooterOrShopContact(string $line): bool
    {
        $t = trim($line);
        if ($t === '') {
            return false;
        }

        return (bool) preg_match(
            '/प्रिंट|Print\s+Shop|Print|शॉप|Shop\s+Contact|Shop|Contact\s*No|डिझाइन|डिझाईन|विवाह\s*संस्था|Footer|फुटर|Copyright|जॉब|कार्ड|Digital|Offset|Lamination|Visiting\s*Card|मुद्रण|फोटो\s*स्टुडिओ|Studio|Design\s*By|कॉम्प्युटर|Computer|टायपिंग|Typing/ui',
            $t
        );
    }

    /**
     * Final pass: drop alternates whose digits appear only on printer/shop/footer lines (late merge can reintroduce them).
     *
     * @param  list<array<string, mixed>>  $contacts
     */
    private function filterContactsRemoveFooterShopNumbers(array &$contacts, string $text): void
    {
        $out = [];
        foreach ($contacts as $c) {
            $raw = (string) ($c['number'] ?? $c['phone_number'] ?? '');
            $digits = preg_replace('/\D/u', '', $raw);
            if ($digits !== '' && strlen($digits) >= 10) {
                $digits = substr($digits, -10);
                if ($this->phoneDigitsAppearOnlyOnFooterShopLines($text, $digits)) {
                    continue;
                }
            }
            $out[] = $c;
        }
        $contacts = $out;
    }

    /**
     * True when every line containing this 10-digit number looks like a print/shop/footer line.
     */
    private function phoneDigitsAppearOnlyOnFooterShopLines(string $text, string $digits10): bool
    {
        if (! preg_match('/^[6-9]\d{9}$/', $digits10)) {
            return false;
        }
        $normText = \App\Services\Ocr\OcrNormalize::normalizeDigits($text);
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $normText) ?: [])));
        $saw = false;
        foreach ($lines as $ln) {
            $lnNorm = \App\Services\Ocr\OcrNormalize::normalizeDigits($ln);
            if ($lnNorm === null || ! str_contains((string) $lnNorm, $digits10)) {
                continue;
            }
            $saw = true;
            if (! $this->lineLooksLikePrintFooterOrShopContact($ln)) {
                return false;
            }
        }

        return $saw;
    }

    /**
     * @param  list<array<string, mixed>>  $relatives
     * @return list<array<string, mixed>>
     */
    private function filterRelativeRowsDropSectionPseudoHeaders(array $relatives): array
    {
        if ($relatives === []) {
            return $relatives;
        }

        return array_values(array_filter($relatives, function (array $r): bool {
            $notes = trim((string) preg_replace('/^[\s\-–—•*]+/u', '', (string) ($r['notes'] ?? '')));
            // Notes-only: relation_type may be canonical "आजोळ"/"मामा" and must not be mistaken for a heading.
            if ($this->isRelativeSectionHeadingNotes($notes)) {
                return false;
            }

            return true;
        }));
    }

    private function fatherContactSlotsMergePhone(array &$contacts, string $digits10): void
    {
        if (! preg_match('/^[6-9]\d{9}$/', $digits10)) {
            return;
        }
        $seen = [];
        foreach ($contacts as $c) {
            $k = (string) ($c['number'] ?? $c['phone_number'] ?? '');
            if ($k !== '') {
                $seen[\App\Services\Ocr\OcrNormalize::normalizePhone($k) ?? $k] = true;
            }
        }
        $norm = \App\Services\Ocr\OcrNormalize::normalizePhone($digits10);
        if ($norm !== null && ! isset($seen[$norm])) {
            $contacts[] = [
                'type' => 'alternate',
                'label' => 'parent',
                'number' => $norm,
                'phone_number' => $norm,
                'relation_type' => '',
                'contact_name' => '',
                'is_primary' => false,
            ];
        }
    }

    private function valueIsHonorificOnlyStub(string $value): bool
    {
        $t = trim($value);
        if ($t === '') {
            return true;
        }
        if ($this->valueIsStubName($t)) {
            return true;
        }
        if (preg_match('/^(?:कु\.?|चि\.?|श्री\.?|सौ\.?|श्रीमती\.?|कै\.?|डॉ\.?)\s*$/u', $t)) {
            return true;
        }
        if (mb_strlen($t) <= 3 && preg_match('/^(?:कु|चि|श्री|सौ|कै)$/u', $t)) {
            return true;
        }

        return false;
    }

    private function otherRelativesTextLooksPolluted(?string $value): bool
    {
        if ($value === null || trim($value) === '') {
            return false;
        }
        $v = trim($value);
        if (mb_strlen($v) > 900) {
            return true;
        }
        if (substr_count($v, "\n") > 15) {
            return true;
        }
        if (preg_match('/राशी|रास\s*[:\-]|नक्षत्र\s*[:\-]|नाडी\s*[:\-]|गोत्र\s*[:\-]|ग्रह|कुलस्वामी|योनी\s*[:\-]|मांगलिक|ज्योतिष|कुंडली|प्रिंट|Print|Shop|डिझाइन|जॉब|विवाह\s*संस्था|डिझाईन|Footer|फुटर|Copyright|बायोडेटा|Biodata/ui', $v)) {
            return true;
        }
        if (preg_match('/[\x{0C80}-\x{0CFF}]/u', $v)) {
            return true;
        }

        return false;
    }

    private function educationFieldLooksPolluted(?string $value): bool
    {
        if ($value === null || trim($value) === '') {
            return false;
        }
        $v = trim($value);
        if (preg_match('/जन्म\s*तारीख|जन्मवार|वेळ\s*[:\-]|\d{1,2}[\/\.-]\d{1,2}[\/\.-]\d{2,4}/u', $v)) {
            return true;
        }
        if ($this->valueSmellsLikeHoroscopeOrGotraLeak($v)) {
            return true;
        }

        return false;
    }

    /**
     * Prefer a clean structured table/slot value over missing, label-shaped, or polluted OCR fallback.
     */
    private function shouldApplyStructuredTableHint(string $current, string $candidate, string $fieldKind): bool
    {
        $cand = trim($candidate);
        if ($cand === '' || $this->rejectIfLabelNoise($cand) === null) {
            return false;
        }
        $cur = trim($current);
        if ($cur === '' || $this->isLikelyLabelOnlyValue($cur)) {
            return true;
        }

        if (in_array($fieldKind, ['full_name', 'father_name', 'mother_name'], true)) {
            if ($this->valueIsHonorificOnlyStub($cur)) {
                return true;
            }
            if ($this->preferStructuredPersonNameOverPollutedCandidate($cur, $cand)) {
                return true;
            }
        }

        if ($fieldKind === 'highest_education' && $this->educationFieldLooksPolluted($cur)) {
            return $this->validateEducation($this->cleanValue($cand)) !== null
                || (mb_strlen($cand) >= 3 && ! $this->educationFieldLooksPolluted($cand));
        }

        if ($fieldKind === 'other_relatives_text' && $this->otherRelativesTextLooksPolluted($cur) && ! $this->otherRelativesTextLooksPolluted($cand)) {
            return true;
        }

        if ($fieldKind === 'blood_group') {
            return self::sanitizeBloodGroupValue($cur) === null;
        }

        if (in_array($fieldKind, ['birth_place', 'address_current', 'address_native', 'complexion'], true)
            && mb_strlen($cand) > mb_strlen($cur) + 4 && $this->valueSmellsLikeHoroscopeOrGotraLeak($cur)) {
            return true;
        }

        return false;
    }

    private function preferStructuredPersonNameOverPollutedCandidate(string $current, string $candidateRaw): bool
    {
        if ($this->valueIsHonorificOnlyStub($current)) {
            return ! $this->valueIsHonorificOnlyStub($candidateRaw);
        }
        $c = trim($current);
        $d = trim($candidateRaw);
        if (mb_strlen($d) > mb_strlen($c) + 6 && preg_match('/\s+/u', $d) && ! preg_match('/\s+/u', $c)) {
            return true;
        }

        return false;
    }

    /**
     * Strict stub: lone honorific token with no name tail (invalid as stored person field).
     */
    private function valueIsStubName(string $value): bool
    {
        $t = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if ($t === '') {
            return true;
        }

        return (bool) preg_match('/^(?:श्री\.?|सौ\.?|कु\.?|चि\.?|श्रीमती\.?|कै\.?|डॉ\.?)$/u', $t);
    }

    /**
     * HTML table person fields: always prefer structured cell when current is stub, label-like, shorter, or already failing validation.
     *
     * @param  'full_name'|'father_name'|'mother_name'  $kind
     */
    private function shouldForceReplaceHtmlTablePersonName(string $current, string $candidate, string $kind): bool
    {
        $cand = trim($candidate);
        if ($cand === '' || $this->rejectIfLabelNoise($cand) === null) {
            return false;
        }
        if ($this->shouldApplyStructuredTableHint($current, $cand, $kind)) {
            return true;
        }
        $cur = trim($current);
        if ($this->valueIsStubName($cur)) {
            return true;
        }
        if ($this->isLikelyLabelOnlyValue($cur)) {
            return true;
        }
        if ($this->valueIsHonorificOnlyStub($cur)) {
            return true;
        }
        if ($cur !== '' && mb_strlen($cand) > mb_strlen($cur)) {
            return true;
        }
        if ($kind === 'father_name') {
            $pc = $this->cleanPersonName($cand);
            if ($pc !== null && $this->validateFatherName($cur) === null && $this->validateFatherName($pc) !== null) {
                return true;
            }
        }
        if ($kind === 'mother_name') {
            $pc = $this->cleanPersonName($cand);
            if ($pc !== null && $this->validateMotherName($cur) === null && $this->validateMotherName($pc) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Candidate full_name honorific overrides label-based gender inference (कु. female, चि. male).
     *
     * @param  array<string, mixed>  $core
     */
    private function applyGenderFromCandidateFullNameHonorific(array &$core): void
    {
        $fn = trim((string) ($core['full_name'] ?? ''));
        if ($fn === '') {
            return;
        }
        if (preg_match('/^कु\./u', $fn)) {
            $core['gender'] = 'female';

            return;
        }
        if (preg_match('/^चि\./u', $fn)) {
            $core['gender'] = 'male';
        }
    }

    /**
     * @param  list<array<string, mixed>>  $siblings
     */
    private function patchSiblingNamesHonorificSpacing(array &$siblings): void
    {
        foreach ($siblings as $i => $row) {
            $n = trim((string) ($row['name'] ?? ''));
            if ($n === '') {
                continue;
            }
            if (preg_match('/^(चि\.|कु\.|श्री\.|सौ\.|कै\.|श्रीमती\.)(?=\S[^\s])/u', $n)) {
                $n = preg_replace('/^(चि\.|कु\.|श्री\.|सौ\.|कै\.|श्रीमती\.)/u', '$1 ', $n);
                $siblings[$i]['name'] = trim(preg_replace('/\s+/u', ' ', $n) ?? '');
            }
        }
    }

    /**
     * HTML table भाऊ / बहिण rows override OCR sibling lines for that gender (structured-first).
     *
     * @param  list<array<string, mixed>>  $siblings
     * @param  array<string, string>  $hints
     */
    private function mergeHtmlTableSiblingHints(array &$siblings, array $hints): void
    {
        foreach (['sibling_brother_line' => 'brother', 'sibling_sister_line' => 'sister'] as $hintKey => $rel) {
            if (! isset($hints[$hintKey]) || trim((string) $hints[$hintKey]) === '') {
                continue;
            }
            $blob = trim((string) $hints[$hintKey]);
            $siblings = array_values(array_filter($siblings, fn ($s) => ($s['relation_type'] ?? '') !== $rel));
            foreach (preg_split('/\R+/u', $blob) ?: [] as $ln) {
                $ln = trim((string) $ln);
                if ($ln === '') {
                    continue;
                }
                foreach ($this->splitSiblingEnumeratorChunksFromLine($ln) as $chunk) {
                    $chunk = trim((string) $chunk);
                    if ($chunk === '') {
                        continue;
                    }
                    $parsed = $this->parseSiblingRichValue($chunk, $rel);
                    $row = ['relation_type' => $rel, 'contact_number' => null];
                    foreach (['name', 'marital_status', 'occupation', 'address_line', 'notes'] as $fk) {
                        if (($parsed[$fk] ?? null) !== null && trim((string) $parsed[$fk]) !== '') {
                            $row[$fk] = $parsed[$fk];
                        }
                    }
                    if (($row['name'] ?? null) !== null && trim((string) $row['name']) !== '') {
                        $n = $this->cleanPersonName((string) $row['name'], true);
                        $row['name'] = $n !== null && $n !== '' ? $n : (string) $row['name'];
                    }
                    if (trim((string) ($row['name'] ?? '')) !== '') {
                        $siblings[] = $row;
                    }
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private function splitSiblingEnumeratorChunksFromLine(string $line): array
    {
        $line = trim($line);
        if ($line === '') {
            return [];
        }
        if (preg_match('/[\d०-९]{1,2}\s*\)\s*(?:(?:अविवाहित|अविवाहीत)[\-–—]?\s*)?(?:चि\.|कु\.|श्री\.|सौ\.|कै\.|श्रीमती\.)/u', $line)) {
            $parts = preg_split(
                '/(?=[\d०-९]{1,2}\s*\)\s*(?:(?:अविवाहित|अविवाहीत)[\-–—]?\s*)?(?:चि\.|कु\.|श्री\.|सौ\.|कै\.|श्रीमती\.))/u',
                $line
            ) ?: [];
            $out = [];
            foreach ($parts as $p) {
                $p = trim((string) $p);
                if ($p === '') {
                    continue;
                }
                $p = preg_replace('/^[\d०-९]{1,2}\s*\)\s*(?:(?:अविवाहित|अविवाहीत)[\-–—]?\s*)?/u', '', $p);
                $p = trim((string) $p);
                if ($p !== '' && preg_match('/चि\.|कु\.|श्री\.|सौ\.|कै\.|श्रीमती\./u', $p)) {
                    $out[] = $p;
                }
            }

            return $out !== [] ? $out : [$line];
        }

        return [$line];
    }

    /**
     * Decompose one भाऊ/बहिण cell: honorific + name vs (degree) vs (अविवाहीत) vs रा./जि. address tail.
     *
     * @return array{name: ?string, marital_status: ?string, occupation: ?string, address_line: ?string, notes: ?string}
     */
    private function parseSiblingRichValue(string $value, string $relationType): array
    {
        $out = ['name' => null, 'marital_status' => null, 'occupation' => null, 'address_line' => null, 'notes' => null];
        $v = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if ($v === '') {
            return $out;
        }
        $v = trim((string) preg_replace('/^[\d०-९]{1,2}\s*\)\s*(?:(?:अविवाहित|अविवाहीत)[\-–—]?\s*)+/u', '', $v));

        if (preg_match('/^(सौ\.|सौं\.)/u', $v)) {
            $out['marital_status'] = 'married';
        }

        $occChunks = [];
        $foundUnmarried = false;
        $work = preg_replace_callback('/\(\s*([^)]{1,200})\s*\)/u', function (array $m) use (&$occChunks, &$foundUnmarried): string {
            $inner = trim($m[1]);
            if (preg_match('/^अविवाहीत|अविवाहित$/u', $inner)) {
                $foundUnmarried = true;

                return ' ';
            }
            if ($inner !== '' && (
                preg_match('/^(?:B\.|M\.|BE|ME|B\.Com|B\.Sc|M\.Sc|B\.E|B\.Tech|MBA|BAMS|MBBS|B\.A|LL\.B)/ui', $inner)
                || preg_match('/Year|Appear|Sem|Horti|Mech|Elect|Computer|II|III|IV|Horti/ui', $inner)
                || (preg_match('/^[A-Za-z0-9.\s\-]{2,100}$/u', $inner) && ! preg_match('/^[अ-ह]/u', $inner))
            )) {
                $occChunks[] = $inner;
            }

            return ' ';
        }, $v);
        $work = trim(preg_replace('/\s+/u', ' ', (string) $work) ?? '');
        if ($foundUnmarried) {
            $out['marital_status'] = 'unmarried';
        }
        if ($occChunks !== []) {
            $out['occupation'] = implode(' ', $occChunks);
        }

        if (preg_match('/^(.*?)[,，]?\s*((?:रा\.|मु\.?\s*पो\.|जि\.|ता\.).+)$/us', $work, $am)) {
            $namePart = trim($am[1]);
            $addrPart = trim($am[2]);
            if ($namePart !== '' && mb_strlen($addrPart) >= 4) {
                $out['address_line'] = $addrPart;
                $work = $namePart;
            }
        }

        $out['name'] = $this->honorificPreserveInvariant($work);

        return $out;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function applySiblingRichDecompositionToRow(array $row): array
    {
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            return $row;
        }
        $rel = (string) ($row['relation_type'] ?? '');
        if ($rel !== 'brother' && $rel !== 'sister') {
            return $row;
        }
        if (($row['address_line'] ?? null) !== null && trim((string) $row['address_line']) !== '') {
            return $row;
        }
        if (! preg_match('/\(|\)|रा\.|मु\.?\s*पो\.|जि\.|अविवाहीत|अविवाहित|B\.|M\.|Com|Year|Appear/ui', $name)) {
            return $row;
        }
        $parsed = $this->parseSiblingRichValue($name, $rel);
        if (($parsed['name'] ?? null) !== null && trim((string) $parsed['name']) !== '') {
            $cn = $this->cleanPersonName((string) $parsed['name'], true);
            $row['name'] = $cn !== null && $cn !== '' ? $cn : (string) $parsed['name'];
        }
        foreach (['occupation', 'address_line', 'marital_status', 'notes'] as $k) {
            if (($parsed[$k] ?? null) !== null && trim((string) $parsed[$k]) !== '') {
                if ($k === 'occupation' && ! empty($row['occupation'])) {
                    continue;
                }
                $row[$k] = $parsed[$k];
            }
        }

        return $row;
    }

    /**
     * @param  list<array<string, mixed>>  $relativesRows
     */
    private function patchRelativeRowNamesWhenStubOrShort(array &$relativesRows): void
    {
        foreach ($relativesRows as $i => $row) {
            $n = trim((string) ($row['name'] ?? ''));
            $rn = trim((string) ($row['raw_note'] ?? ''));
            if ($rn === '') {
                continue;
            }
            $firstLine = trim((string) (preg_split("/\R/u", $rn)[0] ?? $rn));
            if ($firstLine === '') {
                continue;
            }
            $mustPatch = $this->valueIsStubName($n)
                || ($n !== '' && $this->valueIsHonorificOnlyStub($n))
                || ($n !== '' && mb_strlen($firstLine) > mb_strlen($n) + 4);
            if (! $mustPatch) {
                continue;
            }
            $cand = $this->honorificPreserveInvariant($firstLine);
            $cand = $this->cleanupRelativePersonNameFragment($this->cleanPersonName($cand, true));
            if ($cand !== null && $cand !== '' && ! $this->valueIsStubName($cand)) {
                $relativesRows[$i]['name'] = $cand;
            }
        }
    }

    /**
     * Two-phase Marathi OCR: vertical label block then aligned value block. Fills missing/bad core fields only.
     *
     * @param  array<string, mixed>  $core
     * @param  array<string, mixed>|null  $hints
     * @param  list<array<string, mixed>>  $contacts
     * @param  list<array<string, mixed>>  $careerHistory
     * @param  list<array<string, mixed>>  $relativesRows
     * @param  list<array<string, mixed>>  $educationHistory
     * @param  array<string, true>  $htmlStructuredFieldLocks  Fields already set from HTML table — must not overwrite.
     */
    private function mergeMarathiSeparatedLayoutHints(
        array &$core,
        ?array $hints,
        array &$contacts,
        array &$careerHistory,
        array &$relativesRows,
        array &$educationHistory,
        array $htmlStructuredFieldLocks = []
    ): void {
        if ($hints === null || ($hints['_separated_layout'] ?? '') !== '1') {
            return;
        }
        unset($hints['_separated_layout']);

        $locked = static function (array $locks, string $k): bool {
            return isset($locks[$k]) && $locks[$k];
        };

        $fill = fn (?string $cur): bool => $cur === null || trim((string) $cur) === ''
            || $this->isLikelyLabelOnlyValue((string) $cur);

        $rejectFatherNoise = static function (string $v): bool {
            if (preg_match('/मुलाची\s*आई/u', $v)) {
                return true;
            }
            if (preg_match('/^मुलाचे\s*भाऊ$/u', trim($v))) {
                return true;
            }

            return false;
        };
        $rejectMotherNoise = static function (string $v): bool {
            return (bool) preg_match('/मुलाचे\s*वडील/u', $v);
        };

        if (! $locked($htmlStructuredFieldLocks, 'full_name') && isset($hints['full_name']) && $fill((string) ($core['full_name'] ?? ''))) {
            $v = $this->validateFullName($this->cleanValue($hints['full_name']));
            if ($v !== null) {
                $core['full_name'] = $v;
            }
        }

        if (! $locked($htmlStructuredFieldLocks, 'date_of_birth') && isset($hints['date_of_birth']) && $fill((string) ($core['date_of_birth'] ?? ''))) {
            $raw = $this->rejectBirthDateRawWithoutDigits(
                $this->truncateDobRawAtBirthTimeLabel(trim($hints['date_of_birth']))
            );
            if ($raw !== null) {
                $dob = \App\Services\Ocr\OcrNormalize::normalizeDate($raw);
                if ($dob === $raw) {
                    $dob = $this->normalizeDate($raw);
                }
                if ($dob !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $dob)) {
                    $core['date_of_birth'] = $dob;
                }
            }
        }

        if (! $locked($htmlStructuredFieldLocks, 'height_cm') && isset($hints['height'])
            && (($core['height_cm'] ?? null) === null || $fill((string) ($core['height'] ?? '')))) {
            $raw = trim($hints['height']);
            $heightNorm = \App\Services\Ocr\OcrNormalize::normalizeHeight($raw);
            $cm = $this->normalizeHeight($heightNorm ?? $raw);
            if ($cm !== null && $cm > 0) {
                $core['height_cm'] = $cm;
                $core['height'] = $this->formatHeightFeetInchesDisplay($cm, $raw, $heightNorm);
            }
        }

        if (! $locked($htmlStructuredFieldLocks, 'blood_group') && isset($hints['blood_group']) && $fill((string) ($core['blood_group'] ?? ''))) {
            $raw = $this->cleanValue($hints['blood_group']);
            $bg = \App\Services\Ocr\OcrNormalize::normalizeBloodGroup($raw);
            if ($bg) {
                $bg = \App\Services\Ocr\OcrNormalize::applyBaselinePatterns('blood_group', $bg);
            }
            if ($bg === $raw) {
                $bg = $this->normalizeBloodGroup($raw);
            }
            $bg = $this->validateBloodGroupStrict($bg);
            if ($bg !== null) {
                $core['blood_group'] = $bg;
            }
        }

        $mergedEducationFromHint = false;
        if (! $locked($htmlStructuredFieldLocks, 'highest_education') && isset($hints['highest_education']) && $fill((string) ($core['highest_education'] ?? ''))) {
            $v = $this->cleanValue($hints['highest_education']);
            if (! $this->valueSmellsLikeHoroscopeOrGotraLeak($v)) {
                $v = $this->validateEducation($v);
                if ($v !== null) {
                    $core['highest_education'] = $v;
                    $mergedEducationFromHint = true;
                }
            }
        }
        if ($mergedEducationFromHint && ! empty($core['highest_education'])) {
            $educationHistory = [[
                'degree' => $this->stripTrailingEducationNoise((string) $core['highest_education']),
                'institution' => null,
                'year' => null,
            ]];
        }

        $occHint = $hints['occupation_raw'] ?? ($hints['overflow_occupation'] ?? null);
        if (! $locked($htmlStructuredFieldLocks, 'occupation_title') && is_string($occHint) && $occHint !== ''
            && ($core['occupation_title'] ?? null) === null
            && $careerHistory === []) {
            $v = $this->cleanValue($occHint);
            $v = $this->validateCareer($v) ?? ($v !== '' ? $v : null);
            if ($v !== null && ! $this->valueSmellsLikeHoroscopeOrGotraLeak($v)) {
                $careerHistory = [[
                    'role' => $v,
                    'occupation_title' => $v,
                    'job_title' => $v,
                    'employer' => null,
                    'company_name' => null,
                    'from' => null,
                    'to' => null,
                ]];
                $core['occupation_title'] = $v;
            }
        }

        $fatherCur = (string) ($core['father_name'] ?? '');
        $fatherCandSep = isset($hints['father_name']) ? trim((string) $hints['father_name']) : '';
        if (! $locked($htmlStructuredFieldLocks, 'father_name') && isset($hints['father_name']) && ($fill($fatherCur) || $this->separatedLayoutParentFieldLooksSwapped($fatherCur, 'father')
            || ($fatherCandSep !== '' && $this->preferStructuredPersonNameOverPollutedCandidate($fatherCur, $fatherCandSep)))) {
            $raw = $this->rejectIfLabelNoise(trim($hints['father_name']));
            if ($raw !== null && ! $rejectFatherNoise($raw)) {
                $v = $this->stripTrailingMobileFragmentFromPersonLine($raw);
                $v = $v !== null ? $this->cleanPersonName($v) : null;
                $v = $this->validateFatherName($v);
                if ($v !== null) {
                    $core['father_name'] = $v;
                }
            }
        }

        $motherCur = (string) ($core['mother_name'] ?? '');
        $motherCandSep = isset($hints['mother_name']) ? trim((string) $hints['mother_name']) : '';
        if (! $locked($htmlStructuredFieldLocks, 'mother_name') && isset($hints['mother_name']) && ($fill($motherCur) || $this->separatedLayoutParentFieldLooksSwapped($motherCur, 'mother')
            || ($motherCandSep !== '' && $this->preferStructuredPersonNameOverPollutedCandidate($motherCur, $motherCandSep)))) {
            $raw = $this->rejectIfLabelNoise(trim($hints['mother_name']));
            if ($raw !== null && ! $rejectMotherNoise($raw)) {
                $v = $this->stripTrailingMobileFragmentFromPersonLine($raw);
                $v = $v !== null ? $this->cleanPersonName($v) : null;
                $v = $this->validateMotherName($v);
                if ($v !== null) {
                    $core['mother_name'] = $v;
                }
            }
        }

        if (! $locked($htmlStructuredFieldLocks, 'mama') && isset($hints['mama_note']) && $fill((string) ($core['mama'] ?? ''))) {
            $raw = $this->rejectIfLabelNoise($this->cleanValue($hints['mama_note']));
            $m = $raw !== null ? $this->sanitizeCoreMamaField($raw) : null;
            if ($m !== null && $m !== '') {
                $core['mama'] = $m;
            }
        }

        $appendRelNote = function (string $relationType, string $note) use (&$relativesRows): void {
            $note = trim(preg_replace('/\s+/u', ' ', $note) ?? '');
            if ($note === '' || $this->isLikelyLabelOnlyValue($note)) {
                return;
            }
            $relativesRows[] = [
                'relation_type' => $relationType,
                'name' => null,
                'address_line' => null,
                'location' => null,
                'occupation' => null,
                'contact_number' => null,
                'raw_note' => $this->relativeRawNoteCleanup($note, $relationType),
            ];
        };

        if (! $locked($htmlStructuredFieldLocks, 'relatives_rows_notes') && isset($hints['siblings_note'])) {
            $appendRelNote('brother', $hints['siblings_note']);
        }
        if (! $locked($htmlStructuredFieldLocks, 'relatives_rows_notes') && isset($hints['atya_note'])) {
            $appendRelNote('आत्या', $hints['atya_note']);
        }
        if (! $locked($htmlStructuredFieldLocks, 'other_relatives_text') && isset($hints['overflow_relatives']) && is_string($hints['overflow_relatives'])) {
            foreach (preg_split("/\R/u", $hints['overflow_relatives']) ?: [] as $ln) {
                $ln = trim((string) $ln);
                if ($ln !== '') {
                    $appendRelNote('other', $ln);
                }
            }
        }

        if (! $locked($htmlStructuredFieldLocks, 'other_relatives_text') && isset($hints['other_relatives_text'])) {
            $t = $this->rejectIfLabelNoise($this->cleanValue($hints['other_relatives_text']));
            if ($t !== null && $t !== '' && ! $this->isLikelyLabelOnlyValue($t)) {
                $core['other_relatives_text'] = $t;
            }
        }

        $phoneBlob = trim(implode("\n", array_filter([
            $hints['primary_contact'] ?? '',
            $hints['overflow_contact'] ?? '',
        ], fn ($s) => is_string($s) && $s !== '')));
        if ($phoneBlob !== '' && ! $locked($htmlStructuredFieldLocks, 'contacts')) {
            $this->appendAlternateContactsFromSeparatedHintBlob($contacts, $phoneBlob);
        }
    }

    private function separatedLayoutParentFieldLooksSwapped(string $current, string $slot): bool
    {
        $t = trim($current);
        if ($t === '') {
            return false;
        }
        if ($slot === 'father') {
            return (bool) preg_match('/मुलाची\s*आई/u', $t)
                || $t === 'मुलाची आई'
                || (bool) preg_match('/^आईचे\s+(?:नाव|नांव)$/u', $t)
                || (bool) preg_match('/^मुलाचे\s*(?:भाऊ|मामा)\s*$/u', $t);
        }
        if ($slot === 'mother') {
            return (bool) preg_match('/मुलाचे\s*वडील/u', $t)
                || $t === 'मुलाचे वडील'
                || (bool) preg_match('/^वडिलांचे\s+(?:नाव|नांव)$/u', $t)
                || (bool) preg_match('/^मुलाचे\s*भाऊ\s*$/u', $t)
                || (bool) preg_match('/^मुलाचे\s*मामा\s*$/u', $t);
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $contacts
     */
    private function appendAlternateContactsFromSeparatedHintBlob(array &$contacts, string $blob): void
    {
        if (! preg_match_all('/\b([6-9]\d{9})\b/', $blob, $m)) {
            return;
        }
        $seen = [];
        foreach ($contacts as $c) {
            $raw = (string) ($c['number'] ?? $c['phone_number'] ?? '');
            if ($raw !== '') {
                $k = \App\Services\Ocr\OcrNormalize::normalizePhone($raw) ?? $raw;
                $seen[$k] = true;
            }
        }
        foreach (array_unique($m[1]) as $num) {
            $norm = \App\Services\Ocr\OcrNormalize::normalizePhone((string) $num);
            if ($norm === null || isset($seen[$norm])) {
                continue;
            }
            $seen[$norm] = true;
            $contacts[] = [
                'type' => 'alternate',
                'label' => 'other',
                'number' => $norm,
                'phone_number' => $norm,
                'relation_type' => '',
                'contact_name' => '',
                'is_primary' => false,
            ];
        }
    }

    /** Reject DOB raw text that contains no digits (avoid treating labels as dates). */
    private function rejectBirthDateRawWithoutDigits(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        if (! preg_match('/[0-9०-९]/u', $raw)) {
            return null;
        }

        return $raw;
    }

    /**
     * Merged OCR row: keep only the date segment before birth-time / weekday labels.
     * Example: "24/10/1998 जन्म वेळ :- रात्री …" → "24/10/1998"
     */
    private function truncateDobRawAtBirthTimeLabel(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return $raw;
        }
        $parts = preg_split(
            '/\s+(?=जन्म\s*वेळ|जन्मवेळ|जन्म\s*वार\b|जन्मवार\b|Birth\s*time|Time\s*of\s*birth)/ui',
            trim($raw),
            2
        );
        $head = trim($parts[0] ?? $raw);

        return $head === '' ? $raw : $head;
    }

    /**
     * Last-chance ISO date_of_birth from normalized biodata text (जन्म तारीख / DOB lines, anchored windows).
     * Used when label extraction or table row merge drops the value.
     */
    public function recoverDateOfBirthFromNormalizedBiodataText(string $text): ?string
    {
        if (trim($text) === '') {
            return null;
        }

        $toIso = function (?string $frag): ?string {
            if ($frag === null || trim($frag) === '') {
                return null;
            }
            $frag = $this->truncateDobRawAtBirthTimeLabel(trim($frag));
            if ($frag === null || trim($frag) === '') {
                return null;
            }
            $frag = $this->rejectIfLabelNoise($frag);
            $frag = $this->rejectBirthDateRawWithoutDigits($frag);
            if ($frag === null) {
                return null;
            }
            $n = \App\Services\Ocr\OcrNormalize::normalizeDate($frag);
            if ($n !== null && $n !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $n)) {
                return \App\Services\Ocr\OcrNormalize::applyBaselinePatterns('date_of_birth', (string) $n);
            }
            if ($n === $frag) {
                $n = $this->normalizeDate($frag);
            }
            if ($n !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $n)) {
                return \App\Services\Ocr\OcrNormalize::applyBaselinePatterns('date_of_birth', (string) $n);
            }

            return null;
        };

        foreach (['/जन्म\s*तारीख\s*[:\-]+\s*([^\n]+)/u', '/जन्मतारीख\s*[:\-]+\s*([^\n]+)/u'] as $pat) {
            if (preg_match_all($pat, $text, $ms)) {
                foreach ($ms[1] as $cap) {
                    $iso = $toIso(trim((string) $cap));
                    if ($iso !== null) {
                        return $iso;
                    }
                }
            }
        }

        foreach (['Date of birth', 'Date Of Birth', 'DOB', 'Birth date', 'Birth Date'] as $label) {
            $q = preg_quote($label, '/');
            if (preg_match_all('/'.$q.'\s*[:\-]+\s*([^\n]+)/ui', $text, $ms)) {
                foreach ($ms[1] as $cap) {
                    $iso = $toIso(trim((string) $cap));
                    if ($iso !== null) {
                        return $iso;
                    }
                }
            }
        }

        foreach (preg_split('/\R/u', $text) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (! preg_match('/जन्म|तारीख|दिनांक|Date\s+of\s+birth|\bDOB\b|Birth\s+date/ui', $line)) {
                continue;
            }
            $tail = preg_replace('/^.*?(?:जन्म\s*तारीख|जन्मतारीख|जन्म\s*दिनांक)\s*[:\-]+\s*/u', '', $line);
            foreach ([$tail, $line] as $cand) {
                $iso = $toIso($cand);
                if ($iso !== null) {
                    return $iso;
                }
            }
        }

        $len = mb_strlen($text);
        $scanPos = 0;
        while ($scanPos < $len) {
            $p = mb_strpos($text, 'जन्म', $scanPos);
            if ($p === false) {
                break;
            }
            $window = mb_substr($text, $p, min(200, $len - $p));
            if (preg_match('/तारीख/u', $window) && preg_match('/(\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{4})/u', $window, $wm)) {
                $iso = $toIso($wm[1]);
                if ($iso !== null) {
                    return $iso;
                }
            }
            $scanPos = $p + 1;
        }

        return null;
    }

    /**
     * Strip known label noise and marital-status leakage from parent occupation fields.
     */
    private function sanitizeCoreOccupation(?string $value): ?string
    {
        $v = $this->rejectIfLabelNoise($value);
        if ($v === null) {
            return null;
        }
        if ($this->isMaritalStatusOccupationLeak($v)) {
            return null;
        }
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^नोकरी\s*[\-–:]\s*[6-9]\d{9}\s*$/u', $v)) {
            return 'नोकरी';
        }
        $digits = preg_replace('/\D/u', '', $v);
        if (strlen($digits) === 10 && preg_match('/^[6-9]/', $digits)) {
            return null;
        }
        if (preg_match('/[6-9]\d{9}/', $v) || preg_match('/\d{5,}/', $v)) {
            $stripped = trim((string) preg_replace('/\s*[\-–]?\s*[6-9]\d{9}\s*/u', '', $v));
            $stripped = trim((string) preg_replace('/\d+/u', '', $stripped));
            $stripped = trim(preg_replace('/\s+/u', ' ', $stripped) ?? '');

            return $stripped !== '' ? $stripped : null;
        }

        return $v;
    }

    /**
     * Physical complexion must not inherit unrelated OCR lines (names + phones).
     */
    private function rejectBleededPhysicalComplexion(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $v = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if (preg_match('/\d/u', $v)) {
            return null;
        }
        if (preg_match('/[6-9]\d{9}/', $v) || preg_match('/\bनोकरी\s*[\-–]/u', $v)) {
            return null;
        }
        $words = preg_split('/\s+/u', $v, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($words) > 6) {
            return null;
        }

        return $v;
    }

    /**
     * @return list<array{address_line: string, raw: string, type: string}>
     */
    private function buildAddressesArray(?string $primary, ?string $residential, ?string $native = null): array
    {
        $out = [];
        if ($primary !== null && trim($primary) !== '') {
            $out[] = [
                'address_line' => $primary,
                'raw' => $primary,
                'type' => 'current',
            ];
        }
        if ($residential !== null && trim($residential) !== '') {
            $out[] = [
                'address_line' => $residential,
                'raw' => $residential,
                'type' => 'residential',
            ];
        }
        if ($native !== null && trim($native) !== '') {
            $n = trim($native);
            $out[] = [
                'address_line' => $n,
                'raw' => $n,
                'type' => 'native',
            ];
        }

        return $out;
    }

    /** Second address block: निवासी पत्ता (separate from native मु.पो. पत्ता). */
    private function extractResidentialAddressFromText(string $text): ?string
    {
        $raw = $this->extractFieldAfterLabels($text, ['निवासी पत्ता', 'निवास पत्ता', 'निवासीपत्ता'])
            ?? $this->extractField($text, ['निवासी पत्ता', 'निवास पत्ता', 'निवासीपत्ता']);
        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }
        $raw = $this->truncateFieldBeforeInlineSectionLabels($raw, [
            'भाऊ', 'बहिण', 'बहीण', 'चुलते', 'मामा', 'वडिलांचे', 'आईचे', 'संपर्क', 'अपेक्षा',
        ]);

        return $this->finalizeAddressBlockCandidate(trim((string) $raw));
    }

    /**
     * Markdown / list OCR: use the first top-to-bottom line that declares व्यवसाय/नोकरी so duplicate labels later do not win.
     */
    private function extractCareerFieldPreferFirstDocumentLine(string $text): ?string
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/^अपेक्षा\s*[:\-]/u', $line)) {
                continue;
            }
            foreach (['व्यवसाय', 'नोकरी/व्यवसाय', 'नोकटी/व्यवसाय', 'नोकरी'] as $lab) {
                $q = preg_quote($lab, '#');
                if ($lab === 'नोकरी') {
                    $q = 'नोकरी(?!\s*,)';
                }
                // Use # delimiter so `/` inside the label class [:\-：／/] does not end the pattern.
                if (preg_match('#^[\s\-–—•*]*'.$q.'\s*[:\-：／/]\s*(.+)$#u', $line, $m)) {
                    $v = $this->cleanValue(trim($m[1]));

                    return $v !== '' ? $v : null;
                }
            }
        }

        return null;
    }

    /**
     * True when the value is only a marital-status word (must not map to occupation).
     */
    private function isMaritalStatusOccupationLeak(string $value): bool
    {
        $v = preg_replace('/\s+/u', ' ', trim($value));
        if ($v === '') {
            return true;
        }
        foreach (self::MARITAL_STATUS_OCCUPATION_REJECT as $tok) {
            if (mb_strtolower($v) === mb_strtolower($tok)) {
                return true;
            }
        }
        if (preg_match('/^(विवाहीत|अविवाहित|अविवाहीत|घटस्फोटित|विधवा|विधुर)(?:\s*[,\.])?$/u', $v)) {
            return true;
        }

        return false;
    }

    /**
     * Explicit लिंग / Gender label in document (takes precedence over heuristic inferGender).
     *
     * @return 'male'|'female'|null
     */
    private function extractExplicitGender(string $text): ?string
    {
        if (preg_match('/लिंग\s*[:\-]?\s*(पुरुष|स्त्री|पुरूष)/u', $text, $m)) {
            $g = trim($m[1] ?? '');
            if ($g === 'स्त्री' || mb_strpos($g, 'स्त्री') === 0) {
                return 'female';
            }
            if ($g === 'पुरुष' || $g === 'पुरूष' || mb_strpos($g, 'पुरुष') === 0 || mb_strpos($g, 'पुरूष') === 0) {
                return 'male';
            }
        }
        if (preg_match('/(?:Gender|Sex)\s*[:\-]*\s*(male|female|पुरुष|स्त्री)/ui', $text, $m)) {
            $g = trim($m[1] ?? '');
            if (preg_match('/female|स्त्री/i', $g)) {
                return 'female';
            }
            if (preg_match('/male|पुरुष/i', $g)) {
                return 'male';
            }
        }

        return null;
    }

    private function labelExpectsPersonName(string $label): bool
    {
        foreach (['नाव', 'मुलाचे नाव', 'मुलीचे नाव', 'वधूचे नाव', 'वडिलांचे नाव', 'वडिलांचे नांव', 'वडीलांचे नाव', 'आईचे नाव', 'Name', 'Full name', 'Full Name'] as $n) {
            if ($label === $n) {
                return true;
            }
        }

        return false;
    }

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
        if ($this->isLikelyLabelOnlyValue($v)) {
            return null;
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

    /**
     * Sibling count labels must not match inside longer tokens (e.g. "भाऊ" in "भाऊराव", "बहिण" in "बहिणी").
     */
    private function siblingCountLabelRegexFragment(string $label): string
    {
        $q = preg_quote($label, '/');
        if ($label === 'भाऊ') {
            return $q.'(?!राव)';
        }
        if ($label === 'बहिण' || $label === 'बहीण') {
            return $q.'(?!ी)';
        }

        return $q;
    }

    private function extractCount(string $text, array $labels): ?int
    {
        $siblingLabels = ['भाऊ', 'बंधू', 'Brother', 'बहिण', 'बहीण', 'Sister'];
        if (count(array_intersect($labels, $siblingLabels)) > 0 && $this->isAmbiguousSiblingAvivahitLine($text)) {
            return null;
        }

        foreach ($labels as $label) {
            $tok = $this->siblingCountLabelRegexFragment($label);
            // Some biodata writes "मुलाची बहिण" / "मुलाचा भाऊ" — allow that optional prefix without changing meaning.
            if ($label === 'बहिण' || $label === 'बहीण') {
                $tok = '(?:मुलाची\s+)?'.$tok;
            }
            if ($label === 'भाऊ') {
                $tok = '(?:मुलाचा\s+|मुलाचे\s+)?'.$tok;
            }
            // Marathi number words (table OCR: "भाऊ :- दोन- अविवाहीत - 1) चि.…")
            if (preg_match('/'.$tok.'\s*[:\-]?\s*(दोन|तीन|चार|पाच|सहा)\b/u', $text, $wm)) {
                $marathiNum = ['दोन' => 2, 'तीन' => 3, 'चार' => 4, 'पाच' => 5, 'सहा' => 6];

                return $marathiNum[$wm[1]] ?? null;
            }
            if (preg_match('/'.$tok.'\s*[:\-]?\s*(\d+)/u', $text, $m)) {
                return (int) $m[1];
            }
            // एक / १ → 1 (e.g. "भाऊ :- एक अविवाहित")
            if (preg_match('/'.$tok.'\s*[:\s_\-]*(एक|१)\b/um', $text, $m)) {
                if (preg_match('/अविवाहित|अविवाहीत/u', $m[0] ?? '')) {
                    continue;
                }

                return 1;
            }
            // नाही / None / No → 0 (allow optional : _ - and spaces; don't require end-of-line)
            if (preg_match('/'.$tok.'\s*[:\s_\-]*(नाही|None|No\b|०|0)\b/um', $text, $m)) {
                return 0;
            }
            // Fallback: if label appears with some non-empty text (and not "नाही"), treat as at least one.
            if (preg_match('/'.$tok.'.*\S/um', $text, $m) && mb_strpos($m[0], 'नाही') === false) {
                if (preg_match('/भाऊ\s*\/\s*बहिण|भाऊ\/बहिण|भाऊ\s*\/\s*बहीण|भाऊ\/बहीण/u', $m[0] ?? '') && preg_match('/अविवाहित|अविवाहीत/u', $m[0] ?? '')) {
                    continue;
                }
                if (preg_match('/अविवाहित|अविवाहीत/u', $m[0] ?? '') && ! preg_match('/श्री\.|सौ\.|कु\.|चि\./u', $m[0] ?? '')) {
                    continue;
                }

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

            $combinedParents = $this->parseCombinedVadilAaiLine($line);
            if ($combinedParents !== null) {
                if (($combinedParents['father_name'] ?? null) !== null) {
                    $result['father_name'] = $combinedParents['father_name'];
                }
                if (($combinedParents['father_occupation'] ?? null) !== null) {
                    $result['father_occupation'] = $combinedParents['father_occupation'];
                }
                if (($combinedParents['mother_name'] ?? null) !== null) {
                    $result['mother_name'] = $combinedParents['mother_name'];
                }
                if (($combinedParents['mother_occupation'] ?? null) !== null) {
                    $result['mother_occupation'] = $combinedParents['mother_occupation'];
                }

                continue;
            }

            // Father name + optional inline (occupation) + nearby नोकरी line (OCR-tolerant labels).
            $fatherLabelHit = preg_match('/(?:वडिलांचे|वडिलाचे|वडीलांचे)\s*नां?व/u', $line);
            if ($fatherLabelHit && $result['father_name'] === null) {
                if (preg_match('/(?:वडिलांचे|वडिलाचे|वडीलांचे)\s*नां?व\s*[:\-]?\s*(.+)$/u', $line, $m)) {
                    $valueLine = trim($m[1]);
                    $valueLine = $this->takeFatherNameBeforeCommaMo($valueLine) ?? $valueLine;
                    $occInline = null;
                    if (preg_match('/\((.+?)\)/u', $valueLine, $occMatch)) {
                        $occInline = $this->cleanValue($occMatch[1]);
                        $valueLine = trim(preg_replace('/\(.+?\)/u', '', $valueLine) ?? '');
                    }
                    $name = $this->cleanPersonName($valueLine);
                    if ($name !== null) {
                        $result['father_name'] = $name;
                    }
                    if (($result['father_occupation'] ?? null) === null && $occInline !== null && $occInline !== '' && ! $this->isMaritalStatusOccupationLeak($occInline)) {
                        $result['father_occupation'] = $occInline;
                    }
                } elseif ($i + 1 < $lineCount) {
                    $name = $this->cleanPersonName($lines[$i + 1]);
                    if ($name !== null) {
                        $result['father_name'] = $name;
                    }
                }

                if ($result['father_occupation'] === null) {
                    for ($j = $i + 1; $j < min($i + 5, $lineCount); $j++) {
                        $candidate = trim($lines[$j]);
                        if ($candidate === '') {
                            continue;
                        }
                        if (preg_match('/^(?:आईचे|मातेचे|वडिलांचे|भाऊ|बहीण|बहिण|मामा|चुलते|संपर्क|जात|शिक्षण)\b/u', $candidate)) {
                            break;
                        }
                        if (
                            mb_strpos($candidate, 'नोकरी') !== false ||
                            mb_strpos($candidate, 'फॅक्टरी') !== false ||
                            mb_strpos($candidate, 'कंपनी') !== false ||
                            stripos($candidate, 'Factory') !== false ||
                            mb_strpos($candidate, 'रिटायर्ड') !== false ||
                            mb_strpos($candidate, 'रीटायर्ड') !== false ||
                            stripos($candidate, 'Retired') !== false ||
                            mb_strpos($candidate, 'लिमिटेड') !== false ||
                            mb_strpos($candidate, 'Limited') !== false ||
                            mb_strpos($candidate, 'अॅटो') !== false ||
                            mb_strpos($candidate, 'ऑटो') !== false ||
                            mb_strpos($candidate, 'Auto') !== false
                        ) {
                            $cleaned = $this->cleanValue($candidate);
                            $result['father_occupation'] = \App\Services\AIParsingService::cleanOccupationLabel($cleaned) ?? $cleaned;
                            break;
                        }
                    }
                }
            }

            // Short labels (sample 3): "वडील :- …" / "आई :- …" without वडिलांचे/आईचे नाव
            if ($result['father_name'] === null && preg_match('/^वडील\s*(?::\s*-\s*|:\s+|\s+[:\-]\s)\s*(.+)$/u', $line, $vm)) {
                $name = $this->cleanPersonName(trim($vm[1]));
                if ($name !== null) {
                    $result['father_name'] = $name;
                }
            }
            if ($result['mother_name'] === null && preg_match('/^आई(?!\s*चे)\s*(?::\s*-\s*|:\s+|\s+[:\-]\s)\s*(.+)$/u', $line, $am)) {
                $name = $this->cleanPersonName(trim($am[1]));
                if ($name !== null) {
                    $result['mother_name'] = $name;
                }
            }

            // Mother name + inline occupation from parentheses (e.g. आईचे नाव :- सौ. अनिता ... (गृहिणी))
            if (preg_match('/(?:आईचे|आईचं)\s*नां?व/u', $line) && $result['mother_name'] === null) {
                $valueLine = $line;
                if (! preg_match('/(?:आईचे|आईचं)\s*नां?व\s*[:\-]?\s*(.+)$/u', $line, $m) && $i + 1 < $lineCount) {
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
                    if ($occupation !== null && ! $this->isMaritalStatusOccupationLeak($occupation)) {
                        $result['mother_occupation'] = $occupation;
                    }
                }
            }

            // Very conservative sibling count fallback:
            // if a clear "भाऊ" or "बहिण/बहीण" line without नाही appears, treat as at least one.
            if (! $this->isAmbiguousSiblingAvivahitLine($text)) {
                if ($result['brothers_count'] === null && mb_strpos($line, 'भाऊ') !== false && mb_strpos($line, 'नाही') === false) {
                    if (! (preg_match('/भाऊ\s*\/\s*बहिण|भाऊ\/बहिण|भाऊ\s*\/\s*बहीण|भाऊ\/बहीण/u', $line) && preg_match('/अविवाहित|अविवाहीत/u', $line))) {
                        $result['brothers_count'] = 1;
                    }
                }
                if ($result['sisters_count'] === null && (mb_strpos($line, 'बहिण') !== false || mb_strpos($line, 'बहीण') !== false) && mb_strpos($line, 'नाही') === false) {
                    if (! (preg_match('/भाऊ\s*\/\s*बहिण|भाऊ\/बहिण|भाऊ\s*\/\s*बहीण|भाऊ\/बहीण/u', $line) && preg_match('/अविवाहित|अविवाहीत/u', $line))) {
                        $result['sisters_count'] = 1;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * @return array{father_name: ?string, father_occupation: ?string, mother_name: ?string, mother_occupation: ?string}|null
     */
    private function findCombinedVadilAaiParents(string $text): ?array
    {
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $p = $this->parseCombinedVadilAaiLine(trim($line));
            if ($p !== null) {
                return $p;
            }
        }

        return null;
    }

    /**
     * Single physical line: "वडील :- … (नोकरी …) आई :- सौ. … (occ)" — stop father/occupation before आई.
     *
     * @return array{father_name: ?string, father_occupation: ?string, mother_name: ?string, mother_occupation: ?string}|null
     */
    private function parseCombinedVadilAaiLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }
        if (! preg_match('/वडील\s*(?::\s*-\s*|:\s+)\s*(.+?)\s+आई(?!\s*चे)\s*(?::\s*-\s*|:\s+)\s*(.+)$/us', $line, $m)) {
            return null;
        }
        $fatherSeg = trim($m[1]);
        $motherSeg = trim($m[2]);

        $fatherName = null;
        $fatherOcc = null;
        if (preg_match('/^(.+?)\s*,\s*\(\s*नोकरी\s+(.+?)\s*\)\s*$/u', $fatherSeg, $fm)) {
            $fatherName = trim($fm[1]);
            $fatherOcc = trim($fm[2]);
        } elseif (preg_match('/^(.+?)\s*\(\s*नोकरी\s+(.+?)\s*\)\s*$/u', $fatherSeg, $fm)) {
            $fatherName = trim($fm[1]);
            $fatherOcc = trim($fm[2]);
        } elseif (preg_match('/^(.+?)\s*\((.+?)\)\s*$/u', $fatherSeg, $fm)) {
            $fatherName = trim($fm[1]);
            $inner = trim($fm[2]);
            $fatherOcc = preg_replace('/^नोकरी\s+/u', '', $inner);
        } else {
            $fatherName = $fatherSeg;
        }

        $motherName = null;
        $motherOcc = null;
        if (preg_match('/^(.+?)\s*\((.+?)\)\s*$/u', $motherSeg, $mm)) {
            $motherName = trim($mm[1]);
            $motherOcc = trim($mm[2]);
        } else {
            $motherName = $motherSeg;
        }

        $fatherName = $this->cleanPersonName($fatherName);
        $motherName = $this->validateMotherName($this->cleanPersonName($motherName));

        $fatherOccClean = $fatherOcc !== null && $fatherOcc !== ''
            ? \App\Services\AIParsingService::cleanOccupationLabel($fatherOcc)
            : null;
        $motherOccClean = $motherOcc !== null && $motherOcc !== ''
            ? $this->sanitizeCoreOccupation($motherOcc)
            : null;

        return [
            'father_name' => $fatherName,
            'father_occupation' => $fatherOccClean,
            'mother_name' => $motherName,
            'mother_occupation' => $motherOccClean,
        ];
    }

    /**
     * Split "मामा :- … ) चुलते:- …" into two lines so मामा and चुलते land in separate relative buffers.
     */
    private function expandMamaChulteOnSameLine(string $text): string
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $t = trim($line);
            if ($t !== '' && preg_match('/मामा/u', $t) && preg_match('/चुलते/u', $t) && preg_match('/\)\s*चुलते\s*[:\-–—]/u', $t)) {
                $chunks = preg_split('/\)\s*(?=चुलते\s*[:\-–—])/u', $t, 2);
                if (count($chunks) === 2) {
                    $out[] = trim($chunks[0]).')';
                    $out[] = trim($chunks[1]);

                    continue;
                }
            }
            $out[] = $line;
        }

        return implode("\n", $out);
    }

    /**
     * Pull trailing "(B.com)" / "(B.Sc)" style tokens out before cleanPersonName — dots inside parens must not truncate the name.
     *
     * @return array{0: string, 1: ?string}
     */
    private function stripSiblingParenDegreeFromNamePart(string $namePart): array
    {
        $namePart = trim($namePart);
        $namePart = trim(preg_replace('/\(\s*[6-9]\d{9}\s*\)/u', '', $namePart) ?? $namePart);
        if (! preg_match('/\(\s*([^)]+)\s*\)\s*$/u', $namePart, $pm)) {
            return [$namePart, null];
        }
        $inner = trim($pm[1]);
        $digits = preg_replace('/\D/u', '', $inner);
        if (strlen($digits) >= 10 && preg_match('/^[6-9]\d{9}$/', substr($digits, -10))) {
            return [$namePart, null];
        }
        if (preg_match('/अपेक्षा\s*[:\-]|खानदानी|उच्चशिक्षीत|उच्च\s*शिक्षीत|खानदानी\s*,\s*नोकरी|नोकरी\s*,\s*उच्च/u', $inner)) {
            $namePart = trim(preg_replace('/\s*\([^)]*\)\s*$/u', '', $namePart) ?? $namePart);

            return [$namePart, null];
        }
        if (preg_match('/\p{Devanagari}/u', $inner)) {
            return [$namePart, null];
        }
        if (preg_match('/^[A-Za-z][A-Za-z0-9.\s\-]+$/u', $inner) && mb_strlen($inner) <= 40) {
            $occ = trim(preg_replace('/\s+/u', ' ', $inner));
            $namePart = trim(preg_replace('/\(\s*[^)]+\s*\)\s*$/u', '', $namePart));

            return [$namePart, $occ !== '' ? $occ : null];
        }

        return [$namePart, null];
    }

    /** Strip "१) अविवाहित- " style prefixes before honorific / name (sibling OCR rows). */
    private function stripSiblingEnumeratorAndMaritalPrefix(string $namePart): string
    {
        $namePart = trim($namePart);
        $namePart = preg_replace('/^[\d०-९0-9]{1,2}\s*\)\s*(?:(?:अविवाहित|अविवाहीत)[\-–—]?\s*)+/u', '', $namePart) ?? $namePart;

        return trim($namePart);
    }

    /**
     * Collapse accidental doubled honorifics (e.g. "श्री. श्री. नाम") to a single prefix.
     */
    private function dedupeDuplicateHonorificPrefixes(string $v): string
    {
        $v = trim($v);
        foreach (['श्रीमती\.', 'श्री\.', 'सौ\.', 'कै\.', 'कु\.', 'कुं\.', 'चि\.', 'सौं\.'] as $p) {
            $v = preg_replace('/^(?:'.$p.'\s*){2,}/u', $p.' ', $v) ?? $v;
        }

        return trim($v);
    }

    /**
     * Centralized invariants for Marathi honorific prefixes:
     * - preserve semantic prefixes (कु., चि., श्री., सौ., श्रीमती., कै.)
     * - dedupe accidental repeats (e.g. "श्री. श्री. नाम" -> "श्री. नाम")
     */
    private function honorificPreserveInvariant(string $value): string
    {
        $v = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return $this->dedupeDuplicateHonorificPrefixes($v);
    }

    /**
     * Clean person name: preserve Marathi honorifics (चि., कु., श्री., सौ., कै., श्रीमती.) as semantic markers.
     * Strips only trailing junk, optional enumerator/marital fragments upstream, and relation-token bleed.
     *
     * @param  bool  $preserveRelativeFullName  When true, do not split on dot/danda (keeps multi-word names like "प्रविण काकासो जाधव").
     */
    private function cleanPersonName(string $value, bool $preserveRelativeFullName = false): ?string
    {
        $v = $this->honorificPreserveInvariant($value);
        if ($v === '') {
            return null;
        }

        $v = preg_replace('/[,，]\s*मु\.?\s*$/u', '', $v) ?? $v;
        $v = preg_replace('/\s+[A-Z]{1,3}\s*$/u', '', $v) ?? $v;

        // Dots after honorifics (श्री., कै., चि., …) must not truncate the name at the first period.
        if (! $preserveRelativeFullName) {
            if (preg_match('/^(?:कु\.|कुं\.|चि\.|कै\.|श्रीमती\.|श्रीमती|श्री\.|सौ\.|सौं\.)\s/u', $v)
                || preg_match('/^(?:कु\.|चि\.|श्री\.|सौ\.|कै\.|श्रीमती\.)(?=\S)/u', $v)
                || preg_match('/कै\.|डॉ\./u', $v)) {
                $preserveRelativeFullName = true;
            }
        }

        // Stop at the first sentence boundary (dot or Marathi danda) only when no honorific-driven full-name preservation.
        if (! $preserveRelativeFullName) {
            $parts = preg_split('/[\.।]/u', $v);
            if (! empty($parts)) {
                $v = trim($parts[0]);
            }
        }

        // 3) Cut off at the start of any obvious relation token that may have
        //    bled into this line from the next field. Skip when preserving full relative names:
        //    e.g. "काका" matches inside surname "काकासो" and would truncate to the first word only.
        if (! $preserveRelativeFullName) {
            $parts = preg_split('/\b(दाजी|चुलते|मामा|काका|मावशी|इतर\s+नातेवाईक|इतर)\b/u', $v);
            if (! empty($parts)) {
                $v = trim($parts[0]);
            }
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
    private function isFamilyStructureBoundaryLine(string $line): bool
    {
        $t = trim($line);
        if ($t === '') {
            return false;
        }
        // New relative headings: handled by the main elseif chain first.
        if (preg_match('/^(?:दाजी|चुलते|चुलती|मामा|मावशी|मावसी|आत्या)\b/u', $t)
            || preg_match('/इतर\s+(?:नातेवाईक|पाहुणे)/u', $t)) {
            return false;
        }
        // OCR line-number / bullet prefix, then a major biodata section (terminates relative buffer).
        $pfx = '^\s*(?:[०-९]{1,2}\s*[.)]\s*|[0-9]{1,2}\s*[.)]\s*|["•\-*❖|]+\s*)?';
        $kw = '(?:भाऊ|बहीण|बहिण|'
            .'वडिलांचे|वडिलाचे|वडीलांचे|वडिल|'
            .'आईचे|आईचं|आई|मातेचे|मातेचं|'
            .'आजोळ|'
            .'नोकरी|संपर्क|Contact|मुलाचे|मुलीचे|वधूचे|'
            .'जात|शिक्षण|उंची|ऊंची|'
            // Not कै.: relative blocks (आत्या/मामा/आजोळ) use "कै. …" as continuation person lines.
            .'चि\.|कु\.|'
            .'गोत्र|रक्त|रास|नक्षत्र|जन्म|काका|काकू|'
            .'व्यवसाय|पत्ता|पता|धर्म|अपेक्षा|स्थायिक|मालमत्ता|वैवाहिक|पगार|'
            .'Package|Salary|Annual|DOB|Date\s+of\s+Birth|Height|Address|Education|Occupation)';

        return (bool) preg_match('/'.$pfx.$kw.'/u', $t);
    }

    /**
     * "मुलाचे भाऊ" blocks where each line is वहिनी/भाऊ (सौ./श्री.) with optional trailing location after comma.
     * Scoped: only when section label is brother-specific and the line matches an explicit slash pair (not global slash parsing).
     *
     * @return list<array<string, mixed>>
     */
    private function extractMulacheBhauVahiniPairSiblings(string $text): array
    {
        $text = preg_replace('/[\x{200B}\x{200C}\x{FEFF}]/u', '', $text);
        if (! preg_match(
            '/मुलाचे\s+भाऊ\s*[:\-–—]*\s*(.*?)(?=\R\s*(?:मुलाची\s+(?:बहिण|बहीण)|मामा\s*[:\-]|वडिलांचे|आईचे|संपर्क|इतर\s+नातेवाईक|अपेक्षा)|\R\s*मुलाचे\s+(?!भाऊ\s*[:\-–—])|$)/su',
            $text,
            $m
        )) {
            return [];
        }
        $body = trim((string) ($m[1] ?? ''));
        if ($body === '' || mb_strpos($body, '/') === false || mb_strpos($body, 'श्री.') === false) {
            return [];
        }

        $out = [];
        foreach (preg_split('/\R+/u', $body) ?: [] as $rawLine) {
            $line = trim((string) $rawLine);
            if ($line === '') {
                continue;
            }
            foreach ($this->splitMulacheBhauSlashPairSegments($line) as $seg) {
                $row = $this->parseMulacheBhauVahiniSlashPairSegment(trim((string) $seg));
                if ($row !== null) {
                    $out[] = $row;
                }
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function splitMulacheBhauSlashPairSegments(string $line): array
    {
        $line = trim($line);
        if ($line === '') {
            return [];
        }
        // Rare: two numbered entries fused on one physical line.
        if (preg_match_all('/[\d०-९]+\s*\)\s*.+\//u', $line) > 1) {
            $parts = preg_split('/(?=[\d०-९]+\s*\)\s*)/u', $line) ?: [];
            $parts = array_values(array_filter(array_map('trim', $parts), fn ($p) => $p !== '' && mb_strpos((string) $p, '/') !== false));

            return $parts !== [] ? $parts : [$line];
        }

        return [$line];
    }

    /**
     * One segment: optional enumerator, सौ.* / श्री.* [, location].
     *
     * @return array<string, mixed>|null
     */
    private function parseMulacheBhauVahiniSlashPairSegment(string $line): ?array
    {
        $line = trim($line);
        if ($line === '' || mb_strpos($line, '/') === false) {
            return null;
        }
        if (! preg_match('/^(?:[\d०-९]+\s*\)\s*)?(.+?)\s*\/\s*([^,]+?)(?:\s*,\s*(.+))?$/u', $line, $pm)) {
            return null;
        }
        $female = trim((string) ($pm[1] ?? ''));
        $male = trim((string) ($pm[2] ?? ''));
        $loc = isset($pm[3]) ? trim((string) $pm[3]) : null;
        if ($loc === '') {
            $loc = null;
        }
        // Narrow trigger: explicit वहिनी-style + brother (श्री./डॉ.) — avoids unrelated slashes elsewhere.
        if (! preg_match('/^(?:सौ\.|सौं\.|कु\.)/u', $female)) {
            return null;
        }
        if (! preg_match('/^(?:श्री\.|डॉ\.)/u', $male)) {
            return null;
        }
        $brotherName = $this->cleanPersonName($male, true);
        $vahiniName = $this->cleanPersonName($female, true);
        if ($brotherName === null || trim($brotherName) === '' || ! $this->isMeaningfulSiblingName($brotherName, 'brother')) {
            return null;
        }
        if ($vahiniName === null || trim($vahiniName) === '') {
            return null;
        }
        $row = [
            'relation_type' => 'brother',
            'name' => $brotherName,
            'marital_status' => 'married',
            'contact_number' => null,
            'occupation' => null,
            'spouse' => ['name' => $vahiniName],
        ];
        if ($loc !== null && $loc !== '') {
            $row['address_line'] = $loc;
        }

        return $row;
    }

    /**
     * Drop comma-separated fragments that are clearly another biodata section, not "other relatives" narrative.
     */
    public static function pruneOtherRelativesNarrative(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }
        $parts = preg_split('/\s*,\s*/u', $text) ?: [];
        $out = [];
        $junk = '/^(?:अपेक्षा|नोकरी|संपर्क|शिक्षण|व्यवसाय|जात|उंची|ऊंची|पत्ता|धर्म|गोत्र|रक्त|रास|नक्षत्र|जन्म|मुलाचे|मुलीचे|Contact|Address|Mobile|Phone|DOB|Package|Salary)\b/ui';
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            if (preg_match($junk, $p)) {
                continue;
            }
            $out[] = $p;
        }
        $s = trim(implode(', ', $out));
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
        $s = trim((string) preg_replace('/^[\s\-–—•*]+/u', '', $s));

        return $s === '' ? null : $s;
    }

    /**
     * Weekday field: only canonical Marathi day names or null (strips composite junk).
     */
    public static function sanitizeBirthWeekdayStrict(?string $weekday): ?string
    {
        if ($weekday === null || trim($weekday) === '') {
            return null;
        }
        $t = trim(preg_replace('/\s+/u', ' ', $weekday) ?? '');
        if ($t === 'वेळ' || $t === 'जन्म वेळ' || preg_match('/^(?:जन्म\s*वार|जन्मवार)\s*[,，]?\s*वेळ\b/u', $t)) {
            return null;
        }
        $days = ['रविवार', 'सोमवार', 'मंगळवार', 'मंगलवार', 'बुधवार', 'गुरुवार', 'शुक्रवार', 'शनिवार'];
        foreach ($days as $d) {
            if (mb_strpos($t, $d) !== false) {
                return $d;
            }
        }

        return null;
    }

    /**
     * Navras display: strip label/HTML noise; reject label-only tokens.
     */
    public static function sanitizeNavrasDisplayText(?string $v): ?string
    {
        if ($v === null || trim($v) === '') {
            return null;
        }
        $n = self::stripResidualHtmlTagsFromString(trim($v));
        if ($n === '') {
            return null;
        }
        $n = preg_replace('/^(?:नावरस|नवरस|नांवटस)\s+नाव\s*[:\-.\s]*/u', '', $n) ?? '';
        $n = trim(preg_replace('/\s+/u', ' ', $n) ?? '');
        $n = trim($n, " \t.:;,|'\")");
        $n = preg_replace('/^[।]+|[।]+$/u', '', $n) ?? $n;
        $n = trim($n);
        if ($n === '' || mb_strlen($n) < 2) {
            return null;
        }
        if (preg_match('/^(?:नाव|जन्म|रास|राशी|वेळ|वर्ण|नक्षत्र)\s*$/u', $n)) {
            return null;
        }

        return $n;
    }

    /**
     * Rashi free-text: strip trailing punctuation / HTML remnants.
     */
    public static function sanitizeRashiDisplayText(?string $v): ?string
    {
        if ($v === null || trim($v) === '') {
            return null;
        }
        $r = self::stripResidualHtmlTagsFromString(trim($v));
        $r = trim(preg_replace('/\s+/u', ' ', $r) ?? '');
        $r = trim(preg_replace('/[\)\]\'\"।]+$/u', '', $r) ?? '');
        $r = trim(preg_replace('/^[\(\[\s]+/u', '', $r) ?? '');
        if ($r === '' || mb_strlen($r) < 2) {
            return null;
        }
        if (preg_match('/^(?:रास|राशी|वर्ण)\s*$/u', $r)) {
            return null;
        }

        return $r;
    }

    private function extractFamilyStructures(string $text, bool $skipSiblingRows = false, bool $suppressOtherRelativesTextFromOcr = false): array
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

        // --- SIBLINGS (label-first: भाऊ :- / बहीण :- / बहिण :-, then legacy OCR variants) ---
        foreach ($lines as $idx => $line) {
            if ($skipSiblingRows) {
                continue;
            }
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // Brother: explicit "भाऊ :-" / "भाऊ :"
            if (preg_match('/^(?:\s*[\-–—•*]+\s*)?भाऊ\s*(?::\s*-\s*|:\s+)\s*(.+)$/u', $line, $em)) {
                if (preg_match('/भाऊ\s*[\/｜|]\s*बहिण|भाऊ\s*[\/｜|]\s*बहीण|भाऊ\/बहिण|भाऊ\/बहीण/u', $line) && preg_match('/अविवाहित|अविवाहीत/u', $line) && ! preg_match('/श्री\.|सौ\.|कु\./u', $line)) {
                    continue;
                }
                $sectionUnmarried = preg_match('/अविवाहित|अविवाहीत/u', $line) === 1;
                $namePart = trim($em[1]);
                $phone = null;
                if (preg_match('/\(\s*([6-9]\d{9})\s*\)/u', $namePart, $pm)) {
                    $phone = $pm[1];
                }
                $npParts = preg_split('/\s+(?:नोकरी|पत्ता|दाजी|बहीण|बहिण)\s*[:\-]/u', $namePart);
                $namePart = trim((string) ($npParts[0] ?? $namePart));
                $namePart = trim(preg_replace('/\(\s*[6-9]\d{9}\s*\)/u', '', $namePart) ?? $namePart);
                if (preg_match('/[\d०-९0-9]{1,2}\s*\)\s*(?:(?:अविवाहित|अविवाहीत)[\-–—]?\s*)?(?:चि\.|कु\.|श्री\.|डॉ\.)/u', $namePart)) {
                    // Split before "१) चि." / "१) अविवाहित- चि." / "२) चि."; supports "भोसले.२)" and "भोसले. २)" (space before enumerator).
                    $pieces = preg_split('/(?<=[\s.\-–—,]|^)(?=\s*[\d०-९0-9]{1,2}\s*\)\s*(?:(?:अविवाहित|अविवाहीत)[\-–—]?\s*)?(?:चि\.|कु\.|श्री\.|डॉ\.))/u', $namePart);
                    foreach ($pieces as $piece) {
                        $piece = trim($piece);
                        if ($piece === '') {
                            continue;
                        }
                        $piece = preg_replace('/^[\d०-९0-9]{1,2}\s*\)\s*(?:(?:अविवाहित|अविवाहीत)[\-–—]?\s*)?/u', '', $piece);
                        $piece = trim($piece);
                        if ($piece === '' || ! preg_match('/चि\.|कु\.|श्री\.|डॉ\./u', $piece)) {
                            continue;
                        }
                        [$onePart, $parenOcc] = $this->stripSiblingParenDegreeFromNamePart($piece);
                        $name = $this->cleanPersonName($onePart);
                        $name = $this->stripAppekshaParenBleedFromSiblingName($name);
                        if ($name !== null && $this->isMeaningfulSiblingName($name, 'brother')) {
                            $siblings[] = [
                                'relation_type' => 'brother',
                                'name' => $name,
                                'contact_number' => $phone,
                                'occupation' => $parenOcc,
                                'marital_status' => $sectionUnmarried ? 'unmarried' : null,
                            ];
                        }
                    }
                } else {
                    $namePart = preg_replace('/^(चि\.|कु\.|श्री\.|डॉ\.)(?=\S)/u', '$1 ', $namePart) ?? $namePart;
                    $namePart = $this->stripSiblingEnumeratorAndMaritalPrefix($namePart);
                    [$namePart, $parenOcc] = $this->stripSiblingParenDegreeFromNamePart($namePart);
                    $name = $this->cleanPersonName($namePart);
                    $name = $this->stripAppekshaParenBleedFromSiblingName($name);
                    if ($name !== null && $this->isMeaningfulSiblingName($name, 'brother')) {
                        $siblings[] = [
                            'relation_type' => 'brother',
                            'name' => $name,
                            'contact_number' => $phone,
                            'occupation' => $parenOcc,
                            'marital_status' => $sectionUnmarried ? 'unmarried' : null,
                        ];
                    }
                }

                continue;
            }

            // Brother: lines starting with "भाऊ" without colon (e.g. "भाऊ + श्री. …")
            if (preg_match('/^भाऊ\b/u', $line)) {
                if (preg_match('/भाऊ\s*[\/｜|]\s*बहिण|भाऊ\s*[\/｜|]\s*बहीण|भाऊ\/बहिण|भाऊ\/बहीण/u', $line) && preg_match('/अविवाहित|अविवाहीत/u', $line) && ! preg_match('/श्री\.|सौ\.|कु\./u', $line)) {
                    continue;
                }
                $name = null;
                $phone = null;

                $parenOccBr = null;
                if (preg_match('/^भाऊ[^\p{L}]*(.+?)(\(\s*([6-9]\d{9})\s*\))?$/u', $line, $m)) {
                    $namePart = trim($m[1]);
                    $npParts = preg_split('/\s+(?:नोकरी|पत्ता|दाजी|बहीण|बहिण)\s*[:\-]/u', $namePart);
                    $namePart = trim((string) ($npParts[0] ?? $namePart));
                    [$namePart, $parenOccBr] = $this->stripSiblingParenDegreeFromNamePart($namePart);
                    $name = $this->cleanPersonName($namePart);
                    $name = $this->stripAppekshaParenBleedFromSiblingName($name);
                    if (isset($m[3]) && preg_match('/^[6-9]\d{9}$/', $m[3])) {
                        $phone = $m[3];
                    }
                }

                if ($name !== null && $this->isMeaningfulSiblingName($name, 'brother')) {
                    $siblings[] = [
                        'relation_type' => 'brother',
                        'name' => $name,
                        'contact_number' => $phone,
                        'occupation' => $parenOccBr,
                    ];
                }

                continue;
            }

            // Brother: "चि." (Chiranjeevi) prefix without explicit "भाऊ" (e.g. "चि. प्रज्योत सुभाष पानसरे (B.SC Horti)")
            if (preg_match('/^चि\.\s*(.+?)(?:\s*\(([^)]+)\))?\s*$/u', $line, $m)) {
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

                continue;
            }

            // Sister: explicit "बहीण :-" / "बहिण :-" (single-line preferred; avoid merging unrelated OCR lines).
            if (preg_match('/^(?:\s*[\-–—•*]+\s*)?(बहीण|बहिण)\s*(?::\s*-\s*|:\s+)\s*(.+)$/u', $line, $sm)) {
                if (preg_match('/भाऊ\s*[\/｜|]\s*बहिण|भाऊ\s*[\/｜|]\s*बहीण|भाऊ\/बहिण|भाऊ\/बहीण/u', $line) && preg_match('/अविवाहित|अविवाहीत/u', $line) && ! preg_match('/श्री\.|सौ\.|कु\./u', $line)) {
                    continue;
                }
                $normSib = $this->cleanOcrNoiseFromFieldValue($line);
                if (preg_match('/ब[ही]ण(?:\s*[०-९0-9]+)?\s*[:\-–—\.]+\s*नाही/u', $line)
                    || preg_match('/ब[ही]ण(?:\s*[०-९0-9]+)?\s*[:\-–—\.]+\s*नाही/u', $normSib)) {
                    continue;
                }

                $sectionUnmarried = preg_match('/अविवाहित|अविवाहीत/u', $line) === 1;
                $namePart = trim((string) ($sm[2] ?? ''));
                $needsNext = $namePart !== '' && ! preg_match('/श्री\.|सौ\.|कु\.|कै\./u', $namePart) && mb_strlen(preg_replace('/\s+/u', '', $namePart) ?? '') <= 2;
                if ($needsNext && isset($lines[$idx + 1])) {
                    $namePart = trim($namePart.' '.trim($lines[$idx + 1]));
                }
                $parts = preg_split('/\s+(दाजी|चुलते|मामा|इतर\s+नातेवाईक|इतर\s+पाहुणे)\b/u', $namePart);
                $namePart = trim((string) ($parts[0] ?? $namePart));
                $npCut = preg_split('/\s+(?:नोकरी|पत्ता|भाऊ|दाजी)\s*[:\-]/u', $namePart);
                $namePart = trim((string) ($npCut[0] ?? $namePart));
                $namePart = preg_replace('/^(?:एक|१)\s+अविवाहीत\s*-\s*/u', '', $namePart) ?? $namePart;
                $namePart = trim((string) $namePart);
                [$namePart, $sisterOcc] = $this->stripSiblingParenDegreeFromNamePart($namePart);
                $name = $this->cleanPersonName($namePart);

                if ($name !== null && $this->isMeaningfulSiblingName($name, 'sister')) {
                    $siblings[] = [
                        'relation_type' => 'sister',
                        'name' => $name,
                        'contact_number' => null,
                        'occupation' => $sisterOcc,
                        'marital_status' => $sectionUnmarried ? 'unmarried' : null,
                    ];
                }

                continue;
            }

            // Sister: legacy lines (count prefix, split OCR) — only when बहीण appears on this line
            if (mb_strpos($line, 'बहिण') !== false || mb_strpos($line, 'बहीण') !== false) {
                if (preg_match('/भाऊ\s*[\/｜|]\s*बहिण|भाऊ\s*[\/｜|]\s*बहीण|भाऊ\/बहिण|भाऊ\/बहीण/u', $line) && preg_match('/अविवाहित|अविवाहीत/u', $line) && ! preg_match('/श्री\.|सौ\.|कु\./u', $line)) {
                    continue;
                }
                $normSib = $this->cleanOcrNoiseFromFieldValue($line);
                if (preg_match('/ब[ही]ण(?:\s*[०-९0-9]+)?\s*[:\-–—\.]+\s*नाही/u', $line)
                    || preg_match('/ब[ही]ण(?:\s*[०-९0-9]+)?\s*[:\-–—\.]+\s*नाही/u', $normSib)) {
                    continue;
                }

                $combined = $line;
                if (isset($lines[$idx + 1])) {
                    $combined .= ' '.trim($lines[$idx + 1]);
                }
                $sectionUnmarried = preg_match('/अविवाहित|अविवाहीत/u', $combined) === 1;

                $combined = trim($combined);
                $namePart = preg_replace('/^\s*ब[ही]ण\s*[0-9०-९]*\s*[:\-–—]*\s*/u', '', $combined);
                $parts = preg_split('/\s+(दाजी|चुलते|मामा|इतर\s+नातेवाईक|इतर\s+पाहुणे)\b/u', $namePart);
                $namePart = $parts[0] ?? $namePart;
                $npCut = preg_split('/\s+(?:नोकरी|पत्ता|भाऊ|दाजी)\s*[:\-]/u', $namePart);
                $namePart = trim((string) ($npCut[0] ?? $namePart));

                $name = $this->cleanPersonName($namePart);

                if ($name !== null && $this->isMeaningfulSiblingName($name, 'sister')) {
                    $siblings[] = [
                        'relation_type' => 'sister',
                        'name' => $name,
                        'contact_number' => null,
                        'occupation' => null,
                        'marital_status' => $sectionUnmarried ? 'unmarried' : null,
                    ];
                }
            }
        }

        if (! $skipSiblingRows) {
            $mulacheBhau = $this->extractMulacheBhauVahiniPairSiblings($text);
            if ($mulacheBhau !== []) {
                $siblings = array_merge($siblings, $mulacheBhau);
            }
        }

        $siblings = array_map(function (array $row): array {
            $occ = $row['occupation'] ?? null;
            if (is_string($occ) && $occ !== '' && $this->siblingOccupationLooksLikePreferencesBleed($occ)) {
                $row['occupation'] = null;
            }

            return $row;
        }, $siblings);
        $siblings = $this->dedupeSiblingRows($siblings);
        $siblings = array_map(fn (array $r): array => $this->applySiblingRichDecompositionToRow($r), $siblings);

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
            } elseif (mb_strpos($line, 'चुलती') !== false) {
                $inOtherRelatives = false;
                $flushRelative();
                $currentType = 'चुलती';
                $buffer[] = $line;
            } elseif (mb_strpos($line, 'आत्या') !== false) {
                // Separate block for paternal aunt (आत्या) and तिचा नवरा.
                $inOtherRelatives = false;
                $flushRelative();
                $currentType = 'आत्या';
                $buffer[] = $line;
            } elseif (preg_match('/^आजोळ\s*[\-–—]?\s*\(\s*मामा\s*\)/u', trim($line))) {
                // "आजोळ (मामा)" / "आजोळ - (मामा)" lists maternal uncles — bucket as मामा so headers are not person rows.
                $inOtherRelatives = false;
                $flushRelative();
                $currentType = 'मामा';
                $buffer[] = $line;
            } elseif (preg_match('/^आजोळ\b/u', trim($line)) || preg_match('/^आजोळ\s*[:\-]/u', trim($line))) {
                $inOtherRelatives = false;
                $flushRelative();
                $currentType = 'आजोळ';
                $buffer[] = $line;
            } elseif (mb_strpos($line, 'मामा') !== false) {
                $inOtherRelatives = false;
                $flushRelative();
                $currentType = 'मामा';
                $buffer[] = $line;
            } elseif (mb_strpos($line, 'मावशी') !== false || mb_strpos($line, 'मावसी') !== false) {
                $inOtherRelatives = false;
                $flushRelative();
                $currentType = 'मावशी';
                $buffer[] = $line;
            } elseif (mb_strpos($line, 'इतर नातेवाईक') !== false || mb_strpos($line, 'इतर पाहुणे') !== false) {
                $flushRelative();
                $inOtherRelatives = true;
                $firstLine = preg_replace('/^.*?इतर\s+(?:नातेवाईक|पाहुणे)\s*[:-]\s*/u', '', $line);
                $otherRelativesBuffer = [trim($firstLine)];
            } elseif ($inOtherRelatives && preg_match('/^\s*अपेक्षा\s*[:\-]/u', trim($line))) {
                // Do not absorb preferences into other_relatives_text.
                $inOtherRelatives = false;
                continue;
            } elseif ($inOtherRelatives && (preg_match('/^\s*Contact\.?\s*No\.?/ui', $line) || preg_match('/मोबाइल|Mobile\s*[:.-]/ui', $line))) {
                $inOtherRelatives = false;
            } elseif ($inOtherRelatives && (
                preg_match('/^(?:\s*[\-–—•*]+\s*)?(?:नक्षत्र|रास|राशी|चरण|योनी|नाडी|नाड|गण|वैरवर्ग|वश्य|नावरस|नावरसनांव)\s*[:\-]/u', trim($line))
                || $this->lineLooksLikePrintFooterOrShopContact($line)
                || $this->valueSmellsLikeHoroscopeOrGotraLeak($line)
            )) {
                // Stop other_relatives_text at horoscope/footer blocks; never bleed structured values.
                $inOtherRelatives = false;
            } elseif ($currentType === 'आजोळ' && preg_match('/^(?:मु\.?\s*पो\.?|मु\.|पो\.|ता\.|जि\.|रा\.)/u', trim($line))) {
                // Continuation line: "कै. … (आजोबा)" on previous line, address on next line (avoid boundary flush).
                $buffer[] = $line;
            } elseif ($currentType === 'आजोळ' && preg_match('/^कै\.?\s/u', trim($line))) {
                // Person line starting with कै. must not hit isFamilyStructureBoundaryLine (kw list includes कै.).
                $buffer[] = $line;
            } elseif ($currentType !== null && preg_match('/^कै\.\s/u', trim($line))) {
                // आत्या / मामा blocks: multiple "कै. …" lines must stay in the same relative buffer.
                $buffer[] = $line;
            } elseif ($currentType === 'आत्या' && preg_match('/^(?:श्री\.|सौ\.|श्रीमती\.)\s/u', trim($line))) {
                // Continuation person rows under आत्या (same buffer; split later by splitRelativeRowsByShri).
                $buffer[] = $line;
            } elseif (preg_match('/^\s*पाहुणे\s*[-–—]/u', trim($line))) {
                // Hard boundary for structured relative buffers; full line preserved under core.other_relatives_text (wizard parity).
                $flushRelative();
                $otherRelativesBuffer[] = trim($line);
                continue;
            } elseif ($currentType !== null && $this->isFamilyStructureBoundaryLine($line)) {
                // Stop swallowing: भाऊ/वडिल/संपर्क/… belong to other sections (siblings/core), not this relative buffer.
                $flushRelative();
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
            $otherRelativesText = self::stripResidualHtmlTagsFromString($otherRelativesText);
            $otherRelativesText = trim(preg_replace('/\s+/u', ' ', $otherRelativesText));
            if ($otherRelativesText === '') {
                $otherRelativesText = null;
            } else {
                $otherRelativesText = self::pruneOtherRelativesNarrative($otherRelativesText);
            }
        }
        if ($suppressOtherRelativesTextFromOcr) {
            $otherRelativesText = null;
        }

        $relatives = $this->splitRelativeRowsByEnumerators($relatives);
        $relatives = $this->splitRelativeRowsBySlashPersonBoundaries($relatives);
        $relatives = $this->splitRelativeRowsByCommaSeparatedChulute($relatives);
        $relatives = $this->splitRelativeRowsByShri($relatives);
        $relatives = $this->mergeRelativeParenContinuationRows($relatives);
        $relatives = array_values(array_filter($relatives, function ($r) {
            return $this->isMeaningfulRelativeNote((string) ($r['notes'] ?? ''));
        }));
        $relatives = $this->structureRelativeRows($relatives);

        // --- CAREER SPLIT (candidate vs father vs brother) ---
        // Use "नोकरी" lines heuristically.
        $careerLines = [];
        foreach ($lines as $i => $line) {
            if (mb_strpos($line, 'नोकरी') === false) {
                continue;
            }
            $tl = trim($line);
            // Preference row: "अपेक्षा :- … नोकरी …" must not become brother_job / career bleed.
            if (preg_match('/^अपेक्षा\s*[:\-]/u', $tl)) {
                continue;
            }
            // "नोकरी" inside अपेक्षा / sibling rows (e.g. भाऊ line with "(… नोकरी …)") must not become brother_job.
            if (preg_match('/^(?:\s*[\-–—•*]+\s*)?भाऊ\b/u', $tl)
                || preg_match('/^(?:\s*[\-–—•*]+\s*)?(बहिण|बहीण)\b/u', $tl)) {
                continue;
            }
            $careerLines[] = ['idx' => $i, 'line' => $line];
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
                'company_name' => $company,
                'company' => $company,
                'location' => $location,
            ];
        }

        if ($fatherJob !== null) {
            $coreOverrides['father_occupation'] = $fatherJob;
        }

        // Attach brother occupation to brother sibling row if both present (do not overwrite degree from parentheses, e.g. "(B.com)").
        if ($brotherJob !== null && ! empty($siblings)) {
            foreach ($siblings as $i => $s) {
                if (($s['relation_type'] ?? null) === 'brother') {
                    $existing = trim((string) ($s['occupation'] ?? ''));
                    if ($existing === '') {
                        $siblings[$i]['occupation'] = $brotherJob;
                    }
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
     * Same-line composite: "रास :- कन्या, योनी :- व्याघ्र" — split rashi vs yoni when merged into रास field.
     */
    private function applyRashiYoniCompositeSplit(?string &$rashi, ?string &$yoni): void
    {
        if ($rashi === null || trim((string) $rashi) === '') {
            return;
        }
        $t = trim((string) $rashi);
        if (preg_match('/^(.+?)\s*,\s*योनी\s*[:\-–—]\s*(.+)$/u', $t, $m)) {
            $rashi = trim($m[1]);
            $tail = trim($m[2]);
            if (($yoni === null || trim((string) $yoni) === '') && $tail !== '') {
                $yoni = $tail;
            }
        }
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
                $ganRaw = trim($m[2]);

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
     * One-line multi-field horoscope rows (देवक+रक्तगट, रास+नक्षत्र, नाड+गण+चरण).
     *
     * @param  ?string  $bloodGroupRawComposite  Set when देवक/रक्तगट combo line matches.
     */
    private function applyCompositeHoroscopeSegmentOverrides(
        string $text,
        ?string &$devak,
        ?string &$rashi,
        ?string &$nakshatra,
        ?string &$nadi,
        ?string &$gan,
        ?string &$charan,
        ?string &$bloodGroupRawComposite
    ): void {
        // देवक + रक्त गट on same line (OCR: रक्तगट, ZWNJ in रक्‍त गट, ":- " vs ":" only).
        $raktaGat = '(?:रक्त\s*गट|रक्तगट|रक[\x{094D}\x{200C}\s]+त\s*गट)';
        // Colon + hyphen may be ASCII, en/em dash, or OCR-split; allow ": -" and lone ":" before value.
        $labelSep = '(?::\s*[\-–—]\s*|:\s*[:\-–—]?\s*)';
        $devakLead = '/देवक\s*'.$labelSep.'\s*(.+?)\s+'.$raktaGat.'\s*'.$labelSep.'\s*([A-Za-z0-9+\-\s.ve]+)/u';
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R+/u', $text) ?: []), fn ($l) => $l !== ''));
        // Prefer single-line scans first; full body last (avoids a bad full-text match stopping before a clean line match).
        $chunks = array_values(array_unique(array_merge($lines, [$text])));
        foreach ($chunks as $chunk) {
            if ($chunk === '' || mb_strpos($chunk, 'देवक') === false || mb_strpos($chunk, 'रक्त') === false) {
                continue;
            }
            if (! preg_match($devakLead, $chunk, $m)) {
                continue;
            }
            $d = trim($m[1]);
            $bg = trim($m[2]);
            $bgCompact = str_replace([' ', '.', 've'], '', strtolower($bg));
            $bloodLooksJunk = $bg !== '' && ! preg_match('/[abo]/i', $bg) && preg_match('/^\d+$/', preg_replace('/\D/', '', $bg));
            $devakLooksJunk = preg_match('/^\s*\+/u', $d);
            if (! $devakLooksJunk && ! $bloodLooksJunk && $d !== '') {
                $devak = $this->rejectHoroscopeJunk($this->rejectIfLabelNoise($d));
            }
            if (! $devakLooksJunk && ! $bloodLooksJunk && $bg !== '' && $bgCompact !== '') {
                $bloodGroupRawComposite = $bg;
                break;
            }
            // Regex matched but blood failed junk guard — keep trying other chunks (do not break).
        }
        // Deterministic fallback: same-line split when main regex misses (spacing/ZWJ/OCR dash variants).
        if ($bloodGroupRawComposite === null || trim((string) $bloodGroupRawComposite) === '') {
            foreach ($lines as $ln) {
                if (mb_strpos($ln, 'देवक') === false || mb_strpos($ln, 'रक्त') === false) {
                    continue;
                }
                $pieces = preg_split('/'.$raktaGat.'\s*'.$labelSep.'/u', $ln, 2);
                if (count($pieces) < 2) {
                    continue;
                }
                $tail = trim((string) ($pieces[1] ?? ''));
                if ($tail === '') {
                    continue;
                }
                if (self::sanitizeBloodGroupValue($tail) === null) {
                    continue;
                }
                $bloodGroupRawComposite = $tail;
                $before = trim((string) ($pieces[0] ?? ''));
                $before = preg_replace('/^देवक\s*'.$labelSep.'\s*/u', '', $before);
                $before = trim((string) $before);
                if ($before !== '' && ! preg_match('/^\s*\+/u', $before)) {
                    $devak = $this->rejectHoroscopeJunk($this->rejectIfLabelNoise($before)) ?? $devak;
                }

                break;
            }
        }
        // रास :- वृश्चिक नक्षत्र :- मृग (same ":- " tokenization as देवक line)
        if (preg_match('/रास\s*:\s*-\s*([^\s\n]+)\s+नक्षत्र\s*:\s*-\s*([^\s\n\.]+)/u', $text, $m)) {
            $ra = trim($m[1]);
            $na = trim($m[2]);
            if ($ra !== '') {
                $rashi = $this->rejectHoroscopeJunk($this->rejectIfLabelNoise($ra));
            }
            if ($na !== '') {
                $nakshatra = $this->rejectHoroscopeJunk($this->rejectIfLabelNoise($na));
            }
        }
        // नाड :- आध्य गण :- राक्षस. चरण :- ४
        if (preg_match('/नाड[ी]?\s*:\s*-\s*([^\s]+)\s+गण\s*:\s*-\s*([^\.]+)\.\s*चरण\s*:\s*-\s*([०-९0-9]+)/u', $text, $m)) {
            $nd = trim($m[1]);
            $gn = trim($m[2]);
            $ch = trim($m[3]);
            if ($nd !== '') {
                $nadi = $this->normalizeNadiValue($nd);
            }
            if ($gn !== '') {
                $gan = self::sanitizeGanValue($this->rejectIfLabelNoise($gn));
            }
            if ($ch !== '') {
                $charan = $ch;
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

    /**
     * Human-readable height for core.height (e.g. "5 ft 4 in") when height_cm is known or Marathi ft/in present.
     */
    private function formatHeightFeetInchesDisplay(?float $heightCm, ?string $heightRaw, ?string $heightNormalized): ?string
    {
        $src = $heightNormalized ?? $heightRaw;
        if ($src !== null && trim($src) !== '') {
            $src = \App\Services\Ocr\OcrNormalize::normalizeDigits(trim($src));
            if (preg_match('/(\d{1,2})\s*फ[ुू]ट\s*(\d{1,2})/u', $src, $m)) {
                return (int) $m[1].' ft '.(int) $m[2].' in';
            }
            if (preg_match('/(\d+)\'(\d+)/', $src, $m)) {
                return (int) $m[1].' ft '.(int) $m[2].' in';
            }
            if (preg_match('/(\d{1,2})\s*ft\s*(\d{1,2})\s*in/ui', $src, $m)) {
                return (int) $m[1].' ft '.(int) $m[2].' in';
            }
        }
        if ($heightCm !== null && $heightCm > 0) {
            $totalIn = (int) round($heightCm / 2.54);
            $ft = intdiv($totalIn, 12);
            $in = $totalIn % 12;

            return $ft.' ft '.$in.' in';
        }

        return null;
    }

    /**
     * Father line often fuses ", मो. 98..." — keep only the name segment before ", मो.".
     */
    private function takeFatherNameBeforeCommaMo(?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return null;
        }
        $n = trim($name);
        if (preg_match('/^(.*),\s*मो\.?\s*[0-9०-९\s\/-]+/u', $n, $m)) {
            $left = trim($m[1]);

            return $left === '' ? null : $left;
        }

        return $n;
    }

    /**
     * True when text looks like "भाऊ/बहिण : अविवाहित" (marital summary, not numeric sibling counts).
     * Handles OCR variants: full-width slash, split across two lines, extra spaces.
     */
    private function lineLooksLikeAmbiguousSiblingAvivahit(string $t): bool
    {
        $t = trim($t);
        if ($t === '') {
            return false;
        }
        $t = str_replace(['／', '⁄', '∕'], '/', $t);
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
        if (! preg_match('/भाऊ\s*\/\s*बहिण|भाऊ\s*\/\s*बहीण|भाऊ\/बहिण|भाऊ\/बहीण|भाऊ\s*[｜|]\s*बहिण|भाऊ\s*[｜|]\s*बहीण/u', $t)) {
            return false;
        }
        if (! preg_match('/अविवाहित|अविवाहीत/u', $t)) {
            return false;
        }
        if (preg_match('/[:\-–—]\s*[०-९0-9]+/u', $t)) {
            return false;
        }
        if (preg_match('/श्री\.|सौ\.|कु\./u', $t)) {
            return false;
        }

        return true;
    }

    /**
     * "भाऊ/बहीण अविवाहित" style lines describe marital status of siblings, not numeric counts — do not infer counts.
     */
    private function isAmbiguousSiblingAvivahitLine(string $text): bool
    {
        $text = preg_replace('/[\x{200B}\x{200C}\x{FEFF}]/u', '', $text);
        $lines = preg_split('/\R/u', $text) ?: [];
        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '') {
                continue;
            }
            if ($this->lineLooksLikeAmbiguousSiblingAvivahit($t)) {
                return true;
            }
        }
        // OCR: "भाऊ/" on one line and "बहिण : अविवाहित" on the next.
        for ($i = 0, $n = count($lines); $i < $n - 1; $i++) {
            $pair = trim($lines[$i]).' '.trim($lines[$i + 1]);
            if ($this->lineLooksLikeAmbiguousSiblingAvivahit($pair)) {
                return true;
            }
        }

        return false;
    }

    /**
     * नोकरी line: text before "(" → company/title; inside "(...)" → salary hint for annual_income.
     *
     * @return array{company_name: ?string, role_title: ?string, salary_paren: ?string}
     */
    private function splitNokariLineCompanyAndSalary(?string $line): array
    {
        $out = ['company_name' => null, 'role_title' => null, 'salary_paren' => null, 'location' => null];
        if ($line === null || trim($line) === '') {
            return $out;
        }
        $line = trim($line);
        $line = preg_replace('/^(?:नोकरी|व्यवसाय|नोकरी\/व्यवसाय|नोकटी\/व्यवसाय)\s*[:\-\/\s>]*/u', '', $line) ?? $line;
        $line = trim((string) (preg_replace('/\s+गोत्र\s*[:\-].*$/us', '', $line) ?? $line));
        if (preg_match('/^(.+?)\s*\(([^)]+)\)\s*$/u', $line, $m)) {
            $before = trim($m[1]);
            $inner = trim($m[2]);
            $out['company_name'] = $before !== '' ? $before : null;
            $out['role_title'] = $before !== '' ? $before : null;
            $out['salary_paren'] = $inner !== '' ? $inner : null;

            return $out;
        }
        // Common pattern: "Company, Location" → company_name + location.
        $lineClean = trim((string) (preg_replace('/\s*\.$/u', '', $line) ?? $line));
        if (preg_match('/^([^,]{2,120}),\s*(.+)$/u', $lineClean, $cm)) {
            $out['company_name'] = trim($cm[1]) !== '' ? trim($cm[1]) : null;
            $out['location'] = trim($cm[2]) !== '' ? trim($cm[2]) : null;
            $out['role_title'] = $out['company_name'] ?? $lineClean;
        } else {
            $out['role_title'] = $lineClean !== '' ? $lineClean : null;
            $out['company_name'] = $lineClean !== '' ? $lineClean : null;
        }

        return $out;
    }

    /**
     * Strip truncated degree fragments and orphaned short tokens from relative name fields.
     */
    private function cleanupRelativePersonNameFragment(?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return null;
        }
        $n = trim($name);
        $n = preg_replace('/[,:;：\-–—]+$/u', '', $n) ?? $n;
        $n = trim($n);
        $n = preg_replace('/\s+(?:B\.?A\.?|B\.?Sc\.?|B\.?Com\.?|B\.?E\.?|B\.?Tech\.?|M\.?Sc\.?|M\.?Com\.?|M\.?E\.?|MBBS|BAMS|B\.?Sc)\s*$/ui', '', $n) ?? $n;
        $n = preg_replace('/\s+(?:B\.?Sc|B\.?A|M\.?Sc)\s*$/ui', '', $n) ?? $n;
        $n = preg_replace('/\s+[Bब]\.?\s*$/u', '', $n) ?? $n;
        $n = trim(preg_replace('/\s+/u', ' ', $n) ?? '');

        return $n === '' ? null : $n;
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
        if (! preg_match('/Lac|Lakh|लाख|per\s*year|वार्षिक/ui', $value) && preg_match('/(\d+)/', $valueNoComma, $m)) {
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
        $ocrCanon = \App\Services\Ocr\OcrNormalize::normalizeBloodGroup($v);
        if (is_string($ocrCanon) && in_array($ocrCanon, self::VALID_BLOOD_GROUPS, true)) {
            return $ocrCanon;
        }
        // Reject OCR garbage: "84४७" (B+ve misread as digits), or any value that is only digits/punctuation
        if (preg_match('/^[0-9०-९\s\-\.]+$/u', $v)) {
            return null;
        }
        $v = preg_replace('/\s+/u', '', $v);
        $v = strtoupper($v);
        $v = str_replace(['VE', 'POSITIVE', 'NEGATIVE', 'NEG'], '', $v);

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
            $notes = preg_replace('/\s+\/\s+(?=श्री\.|सौ\.|कै\.|श्रीमती\.)/u', "\n", $notes) ?? $notes;
            $parts = preg_split('/(?=श्री\.|सौ\.|कै\.|श्रीमती\.)/u', $notes, -1, PREG_SPLIT_NO_EMPTY);
            $parts = array_map('trim', array_filter($parts, fn ($p) => trim($p) !== ''));
            // Merge relation-only prefix (e.g. "मामा", "मामा +") with the following श्री./सौ. segment.
            $mergedParts = [];
            for ($si = 0, $sn = count($parts); $si < $sn; $si++) {
                $cur = $parts[$si];
                $next = $parts[$si + 1] ?? null;
                if ($next !== null
                    && ! $this->isMeaningfulRelativeNote($cur)
                    && preg_match('/^(श्री\.|सौ\.|कै\.|श्रीमती\.)/u', ltrim((string) $next))) {
                    $mergedParts[] = trim($cur).' '.$next;
                    $si++;
                } else {
                    $mergedParts[] = $cur;
                }
            }
            $parts = $mergedParts;
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
        if (preg_match('/^\s*('.$kwPattern.')\s*[+\-*\.\s०-९0-9]*$/u', $t)) {
            return false;
        }
        if (preg_match('/^मुलीचे\s+चुलते\s*$/u', $t) || preg_match('/^मुलाचे\s+चुलते\s*$/u', $t)) {
            return false;
        }
        if (preg_match('/^मुलीच्या\s+आईचे\s+मामा\s*$/u', $t) || preg_match('/^मुलीचे\s+मावस\s+मामा\s*$/u', $t)) {
            return false;
        }
        if (preg_match('/^(?:मुलीची|मुलाची)\s+मावशी(?:\s*[-–]\s*काका)?\s*$/u', $t)) {
            return false;
        }
        if (preg_match('/^[\s\-–—\.]*(?:दाजी|चुलते|मामा|मावशी|आत्या)\s*(?::\s*-\s*|:\s*[:\-–—]*\s*)[\s\-–—\.]*$/u', $t)) {
            return false;
        }
        if (preg_match('/^-\s*(?:दाजी|बहीण|भाऊ)\s*[:\-]\s*$/u', $t)) {
            return false;
        }
        if (preg_match('/^आजोळ\s*[\-–—]?\s*\(\s*मामा\s*\)\s*[:\-]?\s*$/u', $t)) {
            return false;
        }
        if (preg_match('/^मावशी\s*[:\-–—]?\s*$/u', $t) || preg_match('/^मावसी\s*[:\-–—]?\s*$/u', $t)) {
            return false;
        }
        if (preg_match('/^आत्या\s*[:\-–—]?\s*$/u', $t)) {
            return false;
        }
        if (preg_match('/^आजोळ\s*(?::\s*-\s*|:\s*)?\s*$/u', $t)) {
            return false;
        }
        if (preg_match('/^\s*\d{1,2}\s*[\).]\s*$/u', $t)) {
            return false;
        }
        if (preg_match('/^\s*[०-९]{1,2}\s*[\).]\s*$/u', $t)) {
            return false;
        }
        if (preg_match('/^\s*\d{1,2}\s*$/u', $t) || preg_match('/^\s*[०-९]{1,2}\s*$/u', $t)) {
            return false;
        }

        return true;
    }

    /**
     * Parenthetical fragment after a relative name: full address, place list, or short occupation.
     */
    private function classifyRelativeParenFragment(string $paren): array
    {
        $paren = trim($paren);
        if ($paren === '') {
            return ['location' => null, 'occupation' => null];
        }
        if (preg_match('/रा\.|मु\.?\s*पो\.?|ता\.|जि\.|[6-9]\d{9}|मो\.|Mobile|फोन|Ph\./u', $paren)) {
            return ['location' => $paren, 'occupation' => null];
        }
        // "गाव, शहर" style (Marathi place names) — not a job title; keep as location for preview/map.
        // Use Devanagari block explicitly: \p{L} is unreliable for Marathi across PCRE builds.
        if (preg_match('/,/u', $paren) && preg_match('/^[\x{0900}-\x{097F}\s,，\-a-zA-Z]+$/u', $paren) && mb_strlen($paren) >= 4) {
            return ['location' => preg_replace('/\s*,\s*/u', ', ', trim(preg_replace('/\s+/u', ' ', $paren))), 'occupation' => null];
        }
        // Relation-role labels (आजोबा / आजी), not occupations — keep full text in raw_note upstream.
        if (preg_match('/^(?:आजोबा|आजी|आजोळ)$/u', $paren)) {
            return ['location' => null, 'occupation' => null];
        }
        // Locality fragment (e.g. "( माळीनगर)") — not an occupation.
        if (preg_match('/(?:नगर|वाडी|पुर|कोलनी|कॉलनी|नगरी|वस्ती)/u', $paren)) {
            return ['location' => trim($paren), 'occupation' => null];
        }

        return ['location' => null, 'occupation' => $paren];
    }

    /**
     * When a comma introduces a clear address tail (मु. पो., ता., etc.), keep only the person name before it.
     *
     * @return array{name: string, address_tail: string|null}
     */
    private function splitRelativeNameBeforeCommaAddressMarker(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['name' => $name, 'address_tail' => null];
        }
        // Period before address tail (OCR): "पाटील. मु. पो. बोरगाव"
        if (preg_match('/^(.+?)\.\s+(मु\.?\s*पो\.?.+)$/us', $name, $pm)) {
            $addr = trim($pm[2]);
            if (preg_match('/^(?:मु\.?\s*पो\.?|मु\.|पो\.|ता\.|जि\.|रा\.|मु(?:\s|$|\.))/u', $addr)) {
                return ['name' => trim($pm[1]), 'address_tail' => $addr];
            }
        }
        if (! str_contains($name, ',')) {
            return ['name' => $name, 'address_tail' => null];
        }
        $parts = preg_split('/,\s*/u', $name, 2);
        if (count($parts) < 2) {
            return ['name' => $name, 'address_tail' => null];
        }
        $addr = trim($parts[1]);
        if ($addr === '') {
            return ['name' => $name, 'address_tail' => null];
        }
        // Address tail: मु. पो., ता., जि., रा., or truncated "मु" at line/OCR cut.
        if (preg_match('/^(?:मु\.?\s*पो\.?|मु\.|पो\.|ता\.|जि\.|रा\.|मु(?:\s|$|\.))/u', $addr)) {
            return ['name' => trim($parts[0]), 'address_tail' => $addr];
        }

        return ['name' => $name, 'address_tail' => null];
    }

    /**
     * Convert relative rows with notes into structured objects: name, location, raw_note.
     * Person names include Marathi honorific prefixes (श्री., सौ., कै., श्रीमती., etc.) when present in source text.
     */
    private function structureRelativeRows(array $relatives): array
    {
        $out = [];
        foreach ($relatives as $row) {
            $notes = (string) ($row['notes'] ?? '');
            if (preg_match('/^आजोळ\s*[\-–—]?\s*\(\s*मामा\s*\)/u', $notes)) {
                $notes = trim((string) (preg_replace('/^आजोळ\s*[\-–—]?\s*\(\s*मामा\s*\)\s*(?::\s*-\s*|:\s+[:\-–—]{0,2}\s*)\s*/u', '', $notes) ?? $notes));
            }
            $relType = $row['relation_type'] ?? null;
            // Full line: "दाजी :- श्री.… पत्ता. …" — [:\-] alone only matches one of ":" or "-", breaking ":-".
            // After splitRelativeRowsByShri, notes may be only "श्री.… पत्ता. …".
            if ($relType === 'दाजी'
                && (preg_match('/दाजी\s*(?::\s*-\s*|[:\-]\s*)श्री\.?\s*(.+?)\s+पत्ता\.\s*(.+)$/us', $notes, $dj)
                    || preg_match('/^श्री\.?\s*(.+?)\s+पत्ता\.\s*(.+)$/us', $notes, $dj))) {
                $dn = trim($dj[1]);
                $dn = $this->cleanupRelativePersonNameFragment($this->cleanPersonName(trim('श्री. '.$dn), true));
                $addr = trim(preg_replace('/\s+/u', ' ', $dj[2]));
                $addr = $this->trimRelativeStructuredAddressField($addr !== '' ? $addr : null);
                $ph = $this->extractIndianMobile10FromRelativeNotes($notes);
                $out[] = [
                    'relation_type' => 'दाजी',
                    'name' => $dn !== '' ? $dn : null,
                    'address_line' => $addr,
                    'location' => $addr,
                    'occupation' => null,
                    'contact_number' => $ph,
                    'raw_note' => $notes,
                ];

                continue;
            }
            // Strip relation heading so "मामा :- कै.…" matches the same श्री/सौ/कै name patterns as a bare notes line.
            // Markdown list "- चुलते :" must not block stripping.
            // Use ":- aware" strip — a single [:\-] would leave "- कै…" and break ^कै patterns.
            $notesForParse = trim((string) preg_replace('/^[\s\-–—•*]+\s*/u', '', (string) $notes));
            $parseNotes = trim((string) (preg_replace('/^(?:मामा|चुलते|चुलती|काका|काकू|मावशी|आत्या)\s*(?::\s*-\s*|:\s+[:\-–—]{0,2}\s*)\s*/u', '', $notesForParse) ?? ''));
            // Require ":" or ":-" after आजोळ so optional [:\-]? does not strip only the space and leave a stray ":" (breaks ^कै. matching).
            $ajolTail = preg_replace('/^आजोळ\s*(?::\s*-\s*|:\s*)\s*/u', '', $notes);
            $ajolTail = $ajolTail === null ? '' : trim((string) $ajolTail);
            if ($ajolTail === '' || $ajolTail === trim((string) $notes)) {
                $ajolTail = trim((string) (preg_replace('/^आजोळ\s+/u', '', $notes) ?? ''));
            }
            $name = null;
            $location = null;
            $addressLine = null;
            $phone = null;
            $occupation = null;
            if (preg_match('/श्रीमती\.?\s*([^(]+?)\s*(?:\(([^)]+)\))/u', $parseNotes, $m)) {
                $name = trim('श्रीमती. '.trim($m[1]));
                $name = trim(preg_replace('/\s*\/\s*(?=श्रीमती\.|श्री\.|सौ\.|कै\.).*$/us', '', $name) ?? $name);
                $name = trim(preg_replace('/\s*\/\s*$/u', '', $name) ?? $name);
                $paren = isset($m[2]) ? trim($m[2]) : null;
                if ($paren !== null && $paren !== '') {
                    $cls = $this->classifyRelativeParenFragment($paren);
                    $location = $cls['location'];
                    $occupation = $cls['occupation'];
                }
            } elseif (preg_match('/श्री\.?\s*([^(]+?)\s*(?:\(([^)]+)\))/u', $parseNotes, $m)) {
                $name = trim('श्री. '.trim($m[1]));
                $name = trim(preg_replace('/\s*\/\s*(?=श्रीमती\.|श्री\.|सौ\.|कै\.).*$/us', '', $name) ?? $name);
                $name = trim(preg_replace('/\s*\/\s*$/u', '', $name) ?? $name);
                $paren = isset($m[2]) ? trim($m[2]) : null;
                if ($paren !== null && $paren !== '') {
                    $cls = $this->classifyRelativeParenFragment($paren);
                    $location = $cls['location'];
                    $occupation = $cls['occupation'];
                }
            } elseif (preg_match('/सौ\.?\s*([^(]+?)\s*(?:\(([^)]+)\))/u', $parseNotes, $m)) {
                $name = trim('सौ. '.trim($m[1]));
                $name = trim(preg_replace('/\s*\/\s*(?=श्रीमती\.|श्री\.|सौ\.|कै\.).*$/us', '', $name) ?? $name);
                $name = trim(preg_replace('/\s*\/\s*$/u', '', $name) ?? $name);
                $paren = isset($m[2]) ? trim($m[2]) : null;
                if ($paren !== null && $paren !== '') {
                    $cls = $this->classifyRelativeParenFragment($paren);
                    $location = $cls['location'];
                    $occupation = $cls['occupation'];
                }
            } elseif (preg_match('/^कै\.\s*([^(]+?)\s*(?:\(([^)]+)\))/u', $parseNotes, $m)) {
                $innerName = trim((string) ($m[1] ?? ''));
                $name = trim('कै. '.$innerName);
                $name = trim(preg_replace('/\s*\/\s*(?=श्रीमती\.|श्री\.|सौ\.|कै\.).*$/us', '', $name) ?? $name);
                $name = trim(preg_replace('/\s*\/\s*$/u', '', $name) ?? $name);
                $paren = isset($m[2]) ? trim($m[2]) : null;
                if ($paren !== null && $paren !== '') {
                    $cls = $this->classifyRelativeParenFragment($paren);
                    $location = $cls['location'];
                    $occupation = $cls['occupation'];
                }
            } elseif (preg_match('/श्रीमती\.?\s*(.+)/u', $parseNotes, $m)) {
                $name = trim($m[1]);
                if (preg_match('/\(([^)]+)\)/u', $parseNotes, $locM)) {
                    $paren = trim($locM[1]);
                    if ($paren !== '') {
                        $cls = $this->classifyRelativeParenFragment($paren);
                        $location = $cls['location'];
                        $occupation = $cls['occupation'];
                    }
                    $name = trim(preg_replace('/\s*\([^)]+\)\s*$/u', '', $name));
                }
                $name = trim('श्रीमती. '.$name);
                $name = trim(preg_replace('/\s*\/\s*(?=श्रीमती\.|श्री\.|सौ\.|कै\.).*$/us', '', $name) ?? $name);
                $name = trim(preg_replace('/\s*\/\s*$/u', '', $name) ?? $name);
            } elseif (preg_match('/श्री\.?\s*(.+)/u', $parseNotes, $m)) {
                $name = trim($m[1]);
                if (preg_match('/\(([^)]+)\)/u', $parseNotes, $locM)) {
                    $paren = trim($locM[1]);
                    if ($paren !== '') {
                        $cls = $this->classifyRelativeParenFragment($paren);
                        $location = $cls['location'];
                        $occupation = $cls['occupation'];
                    }
                    $name = trim(preg_replace('/\s*\([^)]+\)\s*$/u', '', $name));
                }
                $name = trim('श्री. '.$name);
                $name = trim(preg_replace('/\s*\/\s*(?=श्रीमती\.|श्री\.|सौ\.|कै\.).*$/us', '', $name) ?? $name);
                $name = trim(preg_replace('/\s*\/\s*$/u', '', $name) ?? $name);
            } elseif (preg_match('/सौ\.?\s*(.+)/u', $parseNotes, $m)) {
                $name = trim($m[1]);
                if (preg_match('/\(([^)]+)\)/u', $parseNotes, $locM)) {
                    $paren = trim($locM[1]);
                    if ($paren !== '') {
                        $cls = $this->classifyRelativeParenFragment($paren);
                        $location = $cls['location'];
                        $occupation = $cls['occupation'];
                    }
                    $name = trim(preg_replace('/\s*\([^)]+\)\s*$/u', '', $name));
                }
                $name = trim('सौ. '.$name);
                $name = trim(preg_replace('/\s*\/\s*(?=श्रीमती\.|श्री\.|सौ\.|कै\.).*$/us', '', $name) ?? $name);
                $name = trim(preg_replace('/\s*\/\s*$/u', '', $name) ?? $name);
            } elseif (preg_match('/^(कै\.\s*.+)$/u', $parseNotes, $m)) {
                $name = trim($m[1]);
                if (preg_match('/\(([^)]+)\)/u', $parseNotes, $locM)) {
                    $paren = trim($locM[1]);
                    if ($paren !== '') {
                        $cls = $this->classifyRelativeParenFragment($paren);
                        $location = $cls['location'];
                        $occupation = $cls['occupation'];
                    }
                    $name = trim(preg_replace('/\s*\([^)]+\)\s*$/u', '', $name));
                }
                $name = trim(preg_replace('/\s*\/\s*(?=श्रीमती\.|श्री\.|सौ\.|कै\.).*$/us', '', $name) ?? $name);
                $name = trim(preg_replace('/\s*\/\s*$/u', '', $name) ?? $name);
            }
            // चुलते comma-split rows: "कृष्णा लक्ष्मण डाकवे" (no श्री./कै.) — still a full name line.
            if ($name === null && $relType === 'चुलते' && $parseNotes !== '' && ! preg_match('/^(?:श्रीमती\.|श्री\.|सौ\.|कै\.)/u', $parseNotes)) {
                $name = $this->cleanupRelativePersonNameFragment($this->cleanPersonName($parseNotes, true));
                if ($name !== null && $name !== '') {
                    $name = rtrim($name, " \t\n\r\0\x0B.");
                }
            }
            if ($name === null && ($relType === 'मामा' || str_contains((string) $relType, 'मामा')) && $parseNotes !== '') {
                $cand = trim((string) preg_replace('/\s+चुलते\s*[:\-].*$/us', '', $parseNotes));
                if ($cand !== '' && preg_match('/\p{Devanagari}/u', $cand) && ! preg_match('/^(?:मामा|चुलते)\s*$/u', $cand)) {
                    $name = $this->cleanupRelativePersonNameFragment($this->cleanPersonName($cand, true));
                }
            }
            if ($name === null && $relType === 'आजोळ' && preg_match('/^कै\.?\s*/u', $ajolTail)) {
                // Person + optional "(आजोबा)" + address on same line or following segment (not only "/ …")
                $tail = '';
                if (preg_match('/^(.+?)\s*\(([^)]+)\)\s*(.*)$/us', $ajolTail, $km)) {
                    $name = trim($km[1]);
                    $tail = trim((string) ($km[3] ?? ''));
                } elseif (preg_match('/^(.+?)\s+(मु\.?\s*पो\.?[^\n]*)$/us', $ajolTail, $km)) {
                    $name = trim($km[1]);
                    $tail = trim((string) ($km[2] ?? ''));
                } else {
                    $name = trim($ajolTail);
                }
                if ($tail !== '' && ($addressLine === null || trim((string) $addressLine) === '')) {
                    if (preg_match('/^\/\s*(.+)$/us', $tail, $tm)) {
                        $addressLine = trim($tm[1]);
                    } elseif (preg_match('/^(?:मु\.?\s*पो\.?|मु\.|पो\.|ता\.|जि\.|रा\.)/u', $tail)) {
                        $addressLine = $tail;
                    }
                }
            }
            if ($location === null && $occupation === null && preg_match('/\(([^)]+)\)/u', $parseNotes, $locM)) {
                $paren = trim($locM[1]);
                if ($paren !== '') {
                    $cls = $this->classifyRelativeParenFragment($paren);
                    $location = $cls['location'];
                    $occupation = $cls['occupation'];
                }
            }
            // First 10-digit Indian mobile (ASCII or Devanagari digits; word boundaries miss "मो.९८…").
            $phone = $this->extractIndianMobile10FromRelativeNotes($notes) ?? $phone;
            // Prefer full address blocks when present: take everything from "रा." / "मु.पो." until phone/next section.
            // This deliberately keeps taluka/district so users don't have to re-type them.
            $addrCandidates = [];
            if (preg_match('/(रा\.[^\n]*)/u', $notes, $am)) {
                $addrCandidates[] = trim($am[1]);
            }
            if (preg_match('/(मु\.?\s*पो\.?[^\n]*)/u', $notes, $bm)) {
                $addrCandidates[] = trim($bm[1]);
            }
            if ($addressLine === null && ! empty($addrCandidates)) {
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
            if ($name !== null && $name !== '') {
                $commaSplit = $this->splitRelativeNameBeforeCommaAddressMarker($name);
                if ($commaSplit['address_tail'] !== null) {
                    $name = $commaSplit['name'];
                    if ($addressLine === null || trim((string) $addressLine) === '') {
                        $addressLine = $commaSplit['address_tail'];
                    }
                }
            }
            [$name, $phone, $addressLine] = $this->stripInlineMoPhoneAndRaAddressFromRelativeName($name, $phone, $addressLine);
            $name = $this->stripTrailingMuppoFromRelativeNameWhenAddressHasBlock($name, $addressLine);
            $name = $name !== null && $name !== '' ? $this->cleanupRelativePersonNameFragment($this->cleanPersonName($this->honorificPreserveInvariant($name), true)) : null;
            // Label says मावशी-काका but person is often male (श्री.); honorifics are preserved on name — check notes for edge cases.
            if (preg_match('/मावशी\s*[-–]?\s*काका/u', $notes) && $relType === 'मावशी' && $name !== null
                && (preg_match('/^श्री/u', $name) || preg_match('/मावशी\s*[-–]?\s*काका\s+श्री\.?\s+/u', $notes))) {
                $relType = 'other_maternal';
            }
            if (($name === null || $name === '')
                && ($addressLine === null || trim((string) $addressLine) === '')
                && ($location === null || trim((string) $location) === '')
                && $this->isRelativeSectionHeadingNotes($notes)) {
                continue;
            }
            if ($this->isJunkEmptyMaternalMamaRow($relType, $name, $notes, $phone, $addressLine)) {
                continue;
            }
            if ($this->isFakeMaternalMamaHeaderPersonRow($relType, $name)) {
                continue;
            }
            $addressLine = $this->trimRelativeStructuredAddressField($addressLine);
            $location = $this->trimRelativeStructuredAddressField($location);
            $locOut = $addressLine ?? ($location !== null && $location !== '' ? $location : null);
            // Paren continuation OCR: "(निवृत्त ...\nनगरपरिषद)" may fail the early श्री.(...) capture; recover occupation from any paren fragment.
            if (($occupation === null || trim((string) $occupation) === '') && is_string($notes) && $notes !== '') {
                if (preg_match('/\(\s*([^)]{3,300})/u', $notes, $pm2)) {
                    $frag2 = trim((string) $pm2[1]);
                    $frag2 = preg_split('/\b(?:मो\.|मोबाईल|मोबाइल|Mobile)\b/ui', $frag2, 2)[0] ?? $frag2;
                    $frag2 = trim((string) $frag2);
                    if ($frag2 !== '' && preg_match('/निवृत्त|निरीक्षक|इन्स्पेक्टर|अधिकारी|नगरपरिषद/u', $frag2)) {
                        $cls2 = $this->classifyRelativeParenFragment($frag2);
                        if ($cls2['occupation'] !== null && $cls2['occupation'] !== '') {
                            $occupation = $cls2['occupation'];
                        }
                    }
                }
                if (preg_match('/\(\s*([^)]{3,250})\s*\)?/u', $notes, $pm)) {
                    $frag = trim((string) $pm[1]);
                    if ($frag !== '') {
                        $frag = preg_split('/\b(?:मो\.|मोबाईल|मोबाइल|Mobile)\b/ui', $frag, 2)[0] ?? $frag;
                        $frag = trim((string) $frag);
                        $cls = $this->classifyRelativeParenFragment($frag);
                        if ($cls['occupation'] !== null && $cls['occupation'] !== '') {
                            $occupation = $cls['occupation'];
                        }
                    }
                }
            }
            if (($occupation === null || trim((string) $occupation) === '') && is_string($notes) && $notes !== '') {
                if (preg_match('/(निवृत्त[^\\n]*)/u', $notes, $om)) {
                    $occN = trim((string) $om[1]);
                    $occN = preg_split('/\b(?:मो\.|मोबाईल|मोबाइल|Mobile|रा\.|मु\.?\s*पो\.?|ता\.|जि\.)\b/ui', $occN, 2)[0] ?? $occN;
                    $occN = trim((string) $occN);
                    $occN = trim($occN, " \t\n\r\0\x0B()。.,，");
                    if ($occN !== '') {
                        $occupation = $occN;
                    }
                }
            }
            $out[] = [
                'relation_type' => $relType,
                'name' => $name,
                // Provide both keys: some UI expects address_line; older code may still read location.
                'address_line' => $addressLine,
                'location' => $locOut,
                'occupation' => $occupation,
                'contact_number' => $phone,
                'raw_note' => $this->relativeRawNoteCleanup($notes, $relType),
            ];
        }

        return $out;
    }

    /**
     * Narrow cleanup for structured relative preview/debug: strip leading "- " / bullets, trailing OCR punctuation,
     * and drop a redundant relation label when relation_type is already set on the row.
     * Does not remove Marathi honorific prefixes (श्री., कै., etc.) at the start of the note.
     */
    private function relativeRawNoteCleanup(string $notes, ?string $relationType): string
    {
        $t = trim(preg_replace('/\s+/u', ' ', $notes) ?? '');
        if ($t === '') {
            return $t;
        }
        // Guest-section leak into आत्या rows: drop " - पाहुणे - …" and everything after.
        $t = preg_replace('/\s+-\s+पाहुणे\s*[-–—].*$/u', '', $t) ?? $t;
        $t = trim($t);
        $t = preg_replace('/^-\s+/u', '', $t) ?? $t;
        $t = preg_replace('/^[•*]+\s*/u', '', $t) ?? $t;
        $t = trim($t);
        if ($relationType !== null && $relationType !== '') {
            $rq = preg_quote($relationType, '/');
            $t = preg_replace('/^(?:-\s*)?'.$rq.'\s*(?::\s*-\s*|:\s+)\s*/u', '', $t) ?? $t;
            $t = trim($t);
        }
        // Noisy "Name - मु. पो. …" → project-style comma before address tail (honorifics unchanged).
        $t = preg_replace('/\s+-\s+(?=मु\.?\s*पो\.)/u', ', ', $t) ?? $t;
        $t = preg_replace('/(?:\s*[,;:\-–—])+\s*$/u', '', $t) ?? $t;

        return trim($t);
    }

    /**
     * Narrow trailing cleanup for structured relative address_line / location only (not inner hyphens in place names).
     */
    private function trimRelativeStructuredAddressField(?string $v): ?string
    {
        if ($v === null || trim($v) === '') {
            return null;
        }
        $t = trim(preg_replace('/\s+/u', ' ', $v) ?? '');
        // Guest-section bleed into address (e.g. "… बोरगाव - पाहुणे - शिरोली…"): drop from first " - पाहुणे -" onward.
        $t = preg_replace('/\s+[-–—]\s+पाहुणे\s*[-–—].*$/u', '', $t) ?? $t;
        $t = trim($t);
        $prev = '';
        while ($t !== '' && $t !== $prev) {
            $prev = $t;
            $t = preg_replace('/\s+[-–—]\s*$/u', '', $t) ?? $t;
            $t = preg_replace('/[,;]\s*$/u', '', $t) ?? $t;
            $t = trim($t);
        }

        return $t === '' ? null : $t;
    }

    /**
     * Merge "paren continuation" relative rows: when a row ends with an open "(" fragment and next row is only the continuation/closing.
     *
     * @param  array<int, array<string, mixed>>  $relatives
     * @return array<int, array<string, mixed>>
     */
    private function mergeRelativeParenContinuationRows(array $relatives): array
    {
        $out = [];
        $n = count($relatives);
        for ($i = 0; $i < $n; $i++) {
            $row = $relatives[$i];
            $notes = $row['notes'] ?? '';
            if (! is_string($notes)) {
                $out[] = $row;
                continue;
            }
            $notesT = trim($notes);
            $next = $relatives[$i + 1] ?? null;
            if ($next !== null && ($row['relation_type'] ?? null) === ($next['relation_type'] ?? null)) {
                $nextNotes = $next['notes'] ?? '';
                if (is_string($nextNotes)) {
                    $nn = trim($nextNotes);
                    $open = substr_count($notesT, '(');
                    $close = substr_count($notesT, ')');
                    $nextLooksLikeParenTail = ($nn !== ''
                        && ! preg_match('/^(?:श्री\.|सौ\.|कै\.|श्रीमती\.)/u', $nn)
                        && mb_strlen($nn) <= 220
                        && str_contains($nn, ')'));
                    if ($open > $close && $nextLooksLikeParenTail) {
                        $row['notes'] = trim($notesT.' '.$nn);
                        $out[] = $row;
                        $i++;
                        continue;
                    }
                }
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * If sibling.occupation looks like a pure qualification token (degree) not a job title, move it to notes.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    // NOTE: sibling qualification tokens currently remain in siblings[].occupation for backward compatibility.

    private function isRelativeSectionHeadingNotes(string $notes): bool
    {
        $t = trim((string) preg_replace('/^[\s\-–—•*]+/u', '', trim($notes)));

        return (bool) preg_match('/^(?:मुलीचे|मुलाचे|मुलाची|मुलीच्या)\s+(?:चुलते|मामा|आत्या|मावशी|मावस\s+मामा|आईचे\s+मामा)(?:\s*[:\-–—]+)?\s*$/u', $t)
            || (bool) preg_match('/^आजोळ\s*[\-–—]?\s*\(\s*मामा\s*\)\s*$/u', $t)
            || (bool) preg_match('/^(?:मामा|मावशी|आत्या|चулते)\s*(?::\s*-\s*|:\s*[:\-–—]*\s*)$/u', $t)
            || (bool) preg_match('/^आजोळ\s*(?::\s*-\s*|:\s*)?\s*$/u', $t)
            || (bool) preg_match('/^\s*\d{1,2}\s*[\).]\s*$/u', $t)
            || (bool) preg_match('/^\s*[०-९]{1,2}\s*[\).]\s*$/u', $t);
    }

    /** Core `mama` is legacy; prefer structured relatives. Drop आजोळ-(मामा) bleed and enumerator fragments. */
    private function sanitizeCoreMamaField(?string $mama): ?string
    {
        if ($mama === null || trim($mama) === '') {
            return null;
        }
        $t = trim($mama);
        // Stop bleed from later sections (इतर नातेवाईक, horoscope grid, footer).
        $t = preg_split('/\s+(?:इतर\s+नातेवाईक|नक्षत्र|रास|चरण|वर्ण|वश्य|योनी|गण|नाडी|प्रिंट|Print)\s*[:\-]/u', $t, 2)[0] ?? $t;
        $t = trim((string) $t);
        if (preg_match('/^\)\s*[:\-–—]/u', $t)) {
            return null;
        }
        if (preg_match('/^(?:\d+|[०-९]+)\s*[\).]\s*श्री/u', $t)) {
            return null;
        }
        if ($this->isLikelyLabelOnlyValue($t)) {
            return null;
        }

        return $t;
    }

    /**
     * Inline मावशी/relative rows: "सौ. X मो. 9284… रा. गाव, ता. …" — keep phone/address out of name.
     *
     * @return array{0: ?string, 1: ?string, 2: ?string} name, contact_number, address_line
     */
    private function stripInlineMoPhoneAndRaAddressFromRelativeName(?string $name, ?string $phone, ?string $addressLine): array
    {
        if ($name === null || trim($name) === '') {
            return [$name, $phone, $addressLine];
        }
        $n = trim($name);
        if (preg_match('/\s+मो\.?\s*([0-9०-९]{10})\s*\.?\s*/u', $n, $m)) {
            $digits = preg_replace('/\D/u', '', \App\Services\Ocr\OcrNormalize::normalizeDigits($m[1]));
            if (preg_match('/^[6-9]\d{9}$/', $digits)) {
                if ($phone === null || $phone === '') {
                    $phone = $digits;
                }
                $n = trim(preg_replace('/\s+मो\.?\s*[0-9०-९]{10}\s*\.?\s*/u', ' ', $n) ?? $n);
            }
        }
        if (preg_match('/\s+रा\.\s*(.+)$/u', $n, $rm)) {
            $tail = trim($rm[1]);
            $tail = trim(preg_replace('/\s+मो\.?.*$/u', '', $tail) ?? $tail);
            if ($tail !== '' && ($addressLine === null || trim((string) $addressLine) === '')) {
                $addressLine = trim(preg_replace('/\s+/u', ' ', $tail));
            }
            $n = trim(preg_replace('/\s+रा\.\s*.+$/u', '', $n) ?? $n);
        }
        $n = trim(preg_replace('/\s+/u', ' ', $n) ?? '');

        return [$n !== '' ? $n : null, $phone, $addressLine];
    }

    /**
     * When full मु.पो./ता./जि. address is already captured, strip duplicate "मु.पो. गाव" tail wrongly fused into name.
     */
    private function stripTrailingMuppoFromRelativeNameWhenAddressHasBlock(?string $name, ?string $addressLine): ?string
    {
        if ($name === null || trim($name) === '') {
            return $name;
        }
        $addr = trim((string) ($addressLine ?? ''));
        if ($addr === '' || ! preg_match('/मु\.?\s*पो\.?/u', $addr)) {
            return $name;
        }
        $n = trim($name);
        if (preg_match('/\s+मु\.?\s*पो\.?\s*.+$/u', $n)) {
            return trim(preg_replace('/\s+मु\.?\s*पो\.?\s*.+$/u', '', $n) ?? $n) ?: null;
        }

        return $name;
    }

    /**
     * Pure section header "आजोळ - (मामा)" must not become a person row (flat or sectioned).
     */
    private function isFakeMaternalMamaHeaderPersonRow(?string $relType, ?string $name): bool
    {
        $nm = trim((string) $name);
        if ($nm === '') {
            return false;
        }
        $rt = (string) $relType;
        if ($rt !== 'मामा' && ! str_contains($rt, 'मामा')) {
            return false;
        }

        return (bool) preg_match('/^आजोळ\s*[\-–—]?\s*\(\s*मामा\s*\)\s*:?\s*$/u', $nm);
    }

    /** Drop structured maternal मामा row that has no name, notes, phone, or address (preview junk). */
    private function isJunkEmptyMaternalMamaRow(?string $relType, ?string $name, string $notes, ?string $phone, ?string $addressLine): bool
    {
        $rt = trim((string) $relType);
        if ($rt !== 'मामा' && ! str_contains($rt, 'मामा')) {
            return false;
        }
        $nm = trim((string) $name);
        $rn = trim($notes);
        if ($nm !== '' || $rn !== '') {
            return false;
        }
        if (($phone !== null && $phone !== '') || ($addressLine !== null && trim((string) $addressLine) !== '')) {
            return false;
        }

        return true;
    }

    /**
     * Extract a valid Indian mobile from relative note text (handles Devanagari digits and tight "मो." prefixes).
     */
    private function extractIndianMobile10FromRelativeNotes(string $notes): ?string
    {
        $n = \App\Services\Ocr\OcrNormalize::normalizeDigits($notes);
        $n = preg_replace('/[^\d]/u', ' ', $n) ?? $n;
        $n = trim(preg_replace('/\s+/u', ' ', $n) ?? '');
        if ($n === '') {
            return null;
        }
        if (preg_match_all('/(?<!\d)([6-9]\d{9})(?!\d)/', $n, $pm)) {
            foreach ($pm[1] as $digits) {
                if (preg_match('/^[6-9]\d{9}$/', $digits)) {
                    return $digits;
                }
            }
        }

        return null;
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
        // Heading fragments / OCR junk (e.g. "- बहीण :- सौ") must not become sibling rows.
        if (preg_match('/(?:^|\s)(?:भाऊ|बहीण|बहिण)\s*[:\-–—]/u', $t)) {
            return false;
        }
        if (preg_match('/भाऊ\s*\/\s*बहिण|भाऊ\/बहिण/u', $t) && preg_match('/अविवाहित|अविवाहीत/u', $t)) {
            return false;
        }
        if (preg_match('/^बहिण\s*[:\-–—\.]+\s*अविवाहित/u', $t) || preg_match('/^बहीण\s*[:\-–—\.]+\s*अविवाहित/u', $t)) {
            return false;
        }
        if (preg_match('/^अविवाहित$/u', $t) || preg_match('/^अविवाहीत$/u', $t)) {
            return false;
        }
        if (preg_match('/(?:भाऊ|बहिण|बहीण)/u', $t) && preg_match('/नाही/u', $t)) {
            return false;
        }
        // Heading only: "बहीण 2 सौ" (count + bare honorific, no personal name) — not a sibling row.
        $firstLine = trim((string) (preg_split('/\R/u', $t)[0] ?? $t));
        $firstLine = trim((string) preg_replace('/\s+Contact\b.*$/ui', '', $firstLine));
        if (preg_match('/^(?:बहीण|बहिण)\s*[0-9०-९]+\s*(?:सौ\.?|कु\.?|चि\.?|श्री\.?|श्रीमती\.?|कै\.?)?\s*$/u', $firstLine)) {
            return false;
        }
        $stripped = preg_replace('/^(भाऊ|बहिण|बहीण)\s*[0-9०-९\s]*/u', '', $t);
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
     * @param  array<int, array<string, mixed>>  $siblings
     * @return array<int, array<string, mixed>>
     */
    private function dedupeSiblingRows(array $siblings): array
    {
        $seen = [];
        $out = [];
        foreach ($siblings as $row) {
            $type = (string) ($row['relation_type'] ?? '');
            $nm = mb_strtolower(trim((string) ($row['name'] ?? '')));
            $key = $type.'|'.$nm;
            if ($nm === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $row;
        }

        return $out;
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
        $v = self::stripResidualHtmlTagsFromString(trim($value));
        if ($v === '') {
            return null;
        }

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
        if (preg_match('/^[\+\-\*:;\.]/u', $v) || ! preg_match('/^\p{L}/u', $v)) {
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

        $v = trim(preg_replace('/[\)\]\'\"]+$/u', '', $v) ?? '');
        $v = trim(preg_replace('/^[\)\]\'\"]+/u', '', $v) ?? '');

        return $v === '' ? null : $v;
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

    /** OCR often drops the closing ")" on gotra lines like "कश्यप पुरशी (कौशिक". */
    private function balanceTrailingParenthesesInGotra(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return $value;
        }
        $v = trim($value);
        $open = substr_count($v, '(');
        $close = substr_count($v, ')');
        if ($open > $close) {
            $v .= str_repeat(')', $open - $close);
        }

        return $v;
    }

    /** Preferences / अपेक्षा blocks must not become sibling occupation. */
    private function siblingOccupationLooksLikePreferencesBleed(string $occ): bool
    {
        return preg_match('/अपेक्षा\s*[:\-]|खानदानी|उच्चशिक्षीत|उच्च\s*शिक्षीत|खानदानी\s*,\s*नोकरी|नोकरी\s*,\s*उच्च/u', $occ) === 1;
    }

    private function stripAppekshaParenBleedFromSiblingName(?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return $name;
        }
        $n = trim($name);
        $n = preg_replace('/\s*\(\s*अपेक्षा\s*[:\-][^)]*\)\s*/u', '', $n) ?? $n;
        $n = preg_replace('/\s*\(\s*अपेक्षा\s*[:\-].*$/u', '', $n) ?? $n;
        $n = trim(preg_replace('/\s+/u', ' ', $n) ?? '');

        return $n === '' ? null : $n;
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
        if (preg_match('/मुलीचे\s*नाव/u', $text) || preg_match('/वधूचे\s*नाव/u', $text)) {
            return 'female';
        }

        if (preg_match('/मुलाचे\s*नाव/u', $text)) {
            return 'male';
        }

        // Clear bride/groom wording (word-boundary style for Devanagari).
        if (preg_match('/(?:^|[^\p{L}])मुलगी(?:[^\p{L}]|$)/u', $text)) {
            return 'female';
        }
        if (preg_match('/(?:^|[^\p{L}])मुलगा(?:[^\p{L}]|$)/u', $text)) {
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

    /**
     * @param  string  $text  Lines to scan (often CONTACT slice or full doc)
     * @param  string|null  $fullDocument  Full biodata; used when $text omits "पत्ता :" lines (section detection quirk)
     */
    private function extractAddressBlock(string $text, ?string $fullDocument = null): ?string
    {
        // Prefer "पत्ता :" / "पत्ता :-" (not "पत्ता." which is often दाजी tail). When multiple blocks exist, prefer candidate residence over sibling flat lines.
        $scan = ($fullDocument !== null && trim($fullDocument) !== '') ? $fullDocument : $text;
        $lines = explode("\n", $scan);
        $blocks = [];
        foreach ($lines as $i => $line) {
            $t = trim($line);
            if ($t === '') {
                continue;
            }
            if (preg_match('/दाजी/u', $t)) {
                continue;
            }
            if (preg_match('/^[\s\-–—•*]*(?:पत्ता|पता)\s*(?::\s*-\s*|:\s+[:\-–—]{0,2}\s*)\s*(.+)$/u', $t, $m)
                && ! preg_match('/^[\s\-–—•*]*निवासी\s+पत्ता/u', $t)) {
                $buf = [trim($m[1])];
                for ($j = $i + 1, $n = count($lines); $j < $n; $j++) {
                    $ln = trim($lines[$j]);
                    if ($ln === '') {
                        break;
                    }
                    if (preg_match('/^[\s\-–—•*]*निवासी\s+पत्ता/u', $ln)) {
                        break;
                    }
                    // Relative / family headings — stop before merging दाजी/चुलते/मामा/भाऊ blocks into candidate address.
                    if (preg_match('/^(?:दाजी|चुलते|चुलती|मामा|इतर\s+नातेवाईक|इतर\s+पाहुणे)\b/u', $ln)) {
                        break;
                    }
                    if (preg_match('/^(?:वडील|आई(?!\s*चे))\s*[:\-–—]/u', $ln)) {
                        break;
                    }
                    if (preg_match('/^(?:ता\.|जि\.|मु\.|पो\.|रा\.|गाव|माळी)/u', $ln)) {
                        $buf[] = $ln;

                        continue;
                    }
                    if ($this->isFamilyStructureBoundaryLine($ln)) {
                        break;
                    }
                    break;
                }
                $joined = trim(preg_replace('/\s+/u', ' ', implode(' ', $buf)));
                $joined = $this->trimCandidateAddressBlockAtFamilySections($joined);
                if ($joined !== '') {
                    $fin = $this->finalizeAddressBlockCandidate($joined);
                    if ($fin !== null) {
                        $blocks[] = $fin;
                    }
                }
            }
        }
        if (count($blocks) > 1) {
            foreach ($blocks as $b) {
                if (! preg_match('/फ्लॅट|Flat|वाघोली|B-\s*\d|सी-\s*\d/u', $b)) {
                    return $b;
                }
            }
        }
        if (count($blocks) === 1) {
            return $this->finalizeAddressBlockCandidate($blocks[0]);
        }
        if (preg_match('/(?:पत्ता|पता)\s*(?::\s*-\s*|:\s+[:\-–—]{0,2}\s*)\s*(.+?)(?=\n\n|\n\s*(?:\-\s*)?(?:निवासी\s+पत्ता|निवास\s+पत्ता)\s*[:\-]|\n(?:भाऊ|बहीण|बहिण|दाजी|चुलते|मामा|आईचे|मातेचे|वडिलांचे|इतर\s+नातेवाईक)\b|\n[अ-हA-Z]|$)/us', $scan, $m)) {
            return $this->finalizeAddressBlockCandidate($this->trimCandidateAddressBlockAtFamilySections(trim($m[1])));
        }
        if (preg_match('/Address[:\-]?\s*(.+?)(?=\n\n|\n[अ-हA-Z]|$)/us', $text, $m)) {
            return $this->finalizeAddressBlockCandidate(trim($m[1]));
        }
        // संपर्क पत्ता / संपर्क seal (OCR) — require पत्ता|seal so we don't capture "seal" as address; capture until मोबाईल so multiline address included
        if (preg_match('/(?:संपर्क\s*(?:पत्ता|seal)\s*[:\-]?\s*|oa\s*[:\-]?\s*)(.+?)(?=मोबाईल|\d{10}|$)/us', $text, $m)) {
            $addr = trim(preg_replace('/\s+/', ' ', $m[1]));
            if ($addr !== '' && $addr !== 'seal') {
                return $this->finalizeAddressBlockCandidate($this->trimCandidateAddressBlockAtFamilySections($addr));
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
            if (preg_match('/वॉर्ड|गल्ली|पेठ|बाजार|बाजाटगेट|हाऊस|House|Ward|nagar/u', $line)
                && ! preg_match('/कुलस्वामी|कुळस्वामी|कुलस्वामीनी|गोत्र|रास|नक्षत्र/u', $line)) {
                $collect[] = $line;
            }
        }
        if (! empty($collect)) {
            return $this->finalizeAddressBlockCandidate($this->trimCandidateAddressBlockAtFamilySections(trim(implode(', ', $collect))));
        }

        return null;
    }

    /**
     * Extract a candidate "self" phone when embedded in address/family context (e.g. "पत्ता ... मो. ९८६०४४६१०९").
     * Must not treat relative phones as self phones; this is only used when there is no authoritative mobile row.
     */
    private function extractSelfPhoneFromAddressContext(string $text): ?string
    {
        $lines = preg_split('/\R+/u', $text) ?: [];
        foreach ($lines as $ln) {
            $t = trim((string) $ln);
            if ($t === '') {
                continue;
            }
            // Address-like line with mobile marker.
            if (! (preg_match('/पत्ता/u', $t) || preg_match('/संपर्क/u', $t))) {
                continue;
            }
            if (! preg_match('/मो\.|मोबाईल|मोबाइल|Mobile/ui', $t)) {
                continue;
            }
            // Avoid relative headings.
            if (preg_match('/^(?:चुलते|मामा|आत्या|मावशी|दाजी|भाऊ|बहिण|बहीण)\b/u', $t)) {
                continue;
            }
            $digits = \App\Services\Ocr\OcrNormalize::normalizeDigits($t);
            if ($digits !== null && preg_match('/\b([6-9]\d{9})\b/u', $digits, $m)) {
                return (string) $m[1];
            }
        }

        return null;
    }

    /** Cut merged OCR blobs before mother/sibling/relative headings accidentally concatenated into residence. */
    private function trimCandidateAddressBlockAtFamilySections(string $addr): string
    {
        $addr = trim(preg_replace('/\s+/u', ' ', $addr) ?? '');
        if ($addr === '') {
            return $addr;
        }
        $parts = preg_split(
            '/(?=\s+(?:आईचे\s+नाव|आईचं\s+नाव|मातेचे\s+नाव|मातेचं\s+नाव|वडिलांचे\s+नाव|वडिलाचे\s+नाव|भाऊ|बहीण|बहिण|दाजी|चुलते|चुलती|मामा|इतर\s+नातेवाईक|इतर\s+पाहुणे|नोकरी|संपर्क|वडील|आई)\s*[:\-])/u',
            $addr,
            2
        );

        return trim((string) ($parts[0] ?? $addr));
    }

    /**
     * When OCR merges native पत्ता with "निवासी पत्ता" (same paragraph or next line), keep only the native segment for core.address_line.
     */
    private function stripResidentialAddressFromPrimaryBlock(string $addr): string
    {
        $addr = trim(preg_replace('/\s+/u', ' ', $addr) ?? '');
        if ($addr === '') {
            return $addr;
        }
        // # delimiter: literal / inside [:\-：／/] must not close the pattern.
        $addr = trim((string) (preg_replace('#\s+(?:निवासी\s+पत्ता|निवास\s+पत्ता)\s*[:\-：／/].*$#us', '', $addr) ?? $addr));

        return trim((string) $addr);
    }

    /** Strip trailing list markers / dash noise after education extraction. */
    private function stripTrailingEducationNoise(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = trim((string) $value);
        if ($v === '') {
            return null;
        }
        // If OCR merges blood group into education line: "B.Com (Chennai) रक्तगट :- B+" → keep education only.
        $v = preg_split('/\s+(?:रक्त\s*गट|रक्तगट|Blood\s*group)\b/ui', $v, 2)[0] ?? $v;
        $v = preg_replace('/\s*\.\s*[\-–—]+\s*$/u', '', $v) ?? $v;
        $v = preg_replace('/[\s\-–—•·]+$/u', '', $v) ?? $v;
        $v = trim(preg_replace('/\s+/u', ' ', $v) ?? '');

        return $v === '' ? null : $v;
    }

    /** Extract multi-line block after "स्थायिक मालमत्ता" (or similar) until next section. */
    private function extractPropertySummaryBlock(string $text): ?string
    {
        if (preg_match(
            '/(?:स्थायिक\s*मालमत्ता|स्थावर\s*मिळकत)\s*[:\-]?\s*(.+?)(?=\n\s*(?:मूळचा\s+पत्ता|मुळचा\s+पत्ता|इतर\s+नातेवाईक|नक्षत्र|रास|चरण|वर्ण|वश्य|प्रिंट|Print|कौटुंबिक|अपेक्षा|संपर्क)|\n\s*\n|\nकौटुंबिक|\nअपेक्षा|\nसंपर्क|$)/us',
            $text,
            $m
        )) {
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
            'confidence' => array_map(fn ($v) => (float) $v, $confidence),
        ];
    }

    /**
     * Sectioned snapshot bucket for maternal ajol: address fields only (no name/occupation payload).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function buildAjolSectionedRelativeRow(array $row): array
    {
        $addr = trim((string) ($row['address_line'] ?? ''));
        if ($addr === '') {
            $addr = trim((string) ($row['location'] ?? ''));
        }

        return [
            'relation_type' => 'आजोळ',
            'name' => null,
            'notes' => null,
            'address_line' => $addr !== '' ? $addr : null,
            'contact_number' => null,
        ];
    }

    /**
     * Build relatives_sectioned from structured relatives[] (same buckets as IntakeControlledFieldNormalizer).
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public static function buildRelativesSectionedFromRows(array $rows): array
    {
        $sectioned = [
            'maternal' => [
                'ajol' => [],
                'mama' => [],
                'mavshi' => [],
                'other' => [],
            ],
            'paternal' => [
                'kaka' => [],
                'chulte' => [],
                'atya' => [],
                'other' => [],
            ],
            'other' => [],
        ];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $relation = trim((string) ($row['relation_type'] ?? $row['relation'] ?? ''));
            if ($relation === '') {
                continue;
            }
            $bucket = self::relativeSectionBucketForRelation($relation);
            $side = $bucket['side'];
            $key = $bucket['key'];
            if ($side === 'maternal' && $key === 'ajol') {
                $entry = self::buildAjolSectionedRelativeRow($row);
            } else {
                $entry = $row;
            }
            if ($side === 'maternal') {
                $sectioned['maternal'][$key][] = $entry;
            } elseif ($side === 'paternal') {
                $sectioned['paternal'][$key][] = $entry;
            } else {
                $sectioned['other'][] = $entry;
            }
        }

        return $sectioned;
    }

    /**
     * @return array{side:'maternal'|'paternal'|'other', key:string}
     */
    private static function relativeSectionBucketForRelation(string $relationType): array
    {
        $r = mb_strtolower(trim($relationType), 'UTF-8');
        if (str_contains($r, 'मामा') || str_contains($r, 'mama')) {
            return ['side' => 'maternal', 'key' => 'mama'];
        }
        if (str_contains($r, 'मावशी') || str_contains($r, 'mavshi')) {
            return ['side' => 'maternal', 'key' => 'mavshi'];
        }
        if (str_contains($r, 'आजोळ') || str_contains($r, 'ajol') || str_contains($r, 'maternal_address_ajol')) {
            return ['side' => 'maternal', 'key' => 'ajol'];
        }
        if (str_contains($r, 'चुलते') || str_contains($r, 'chulte')) {
            return ['side' => 'paternal', 'key' => 'chulte'];
        }
        if (str_contains($r, 'आत्या') || str_contains($r, 'atya')) {
            return ['side' => 'paternal', 'key' => 'atya'];
        }
        if (str_contains($r, 'काका') || str_contains($r, 'kaka') || str_contains($r, 'paternal')) {
            return ['side' => 'paternal', 'key' => 'kaka'];
        }
        if (str_contains($r, 'maternal')) {
            return ['side' => 'maternal', 'key' => 'other'];
        }
        if (str_contains($r, 'paternal')) {
            return ['side' => 'paternal', 'key' => 'other'];
        }

        return ['side' => 'other', 'key' => 'other'];
    }

    /**
     * True when this phone appears only in the suchak/bureau header block (before biodata body).
     * Used by AI-first merge and contact harvesting.
     */
    public static function isPhoneExcludedSuchakHeaderStatic(string $text, ?string $phone): bool
    {
        if ($phone === null || $phone === '') {
            return false;
        }
        $digits = preg_replace('/\D/u', '', (string) $phone);
        if (strlen($digits) >= 10) {
            $digits = substr($digits, -10);
        }
        if (! preg_match('/^[6-9]\d{9}$/', $digits)) {
            return false;
        }
        $excluded = self::collectSuchakHeaderPhonesToExcludeStatic($text);

        return isset($excluded[$digits]);
    }

    /**
     * @return array<string, bool> Normalized 10-digit phone => true
     */
    private function collectSuchakHeaderPhonesToExclude(string $text): array
    {
        return self::collectSuchakHeaderPhonesToExcludeStatic($text);
    }

    /**
     * @return array<string, bool>
     */
    private static function collectSuchakHeaderPhonesToExcludeStatic(string $text): array
    {
        $lines = array_map('trim', explode("\n", $text));
        $bodyIdx = self::findFirstBiodataAnchorLineIndexStatic($lines);
        if ($bodyIdx === null || $bodyIdx < 1) {
            return [];
        }
        $headerLines = array_slice($lines, 0, $bodyIdx);
        if (! self::linesContainSuchakBureauMarkerStatic($headerLines)) {
            return [];
        }
        $out = [];
        foreach ($headerLines as $line) {
            $digitLine = \App\Services\Ocr\OcrNormalize::normalizeDigits($line);
            if (preg_match_all('/\b([6-9]\d{9})\b/', $digitLine, $mm)) {
                foreach ($mm[1] as $p) {
                    $n = \App\Services\Ocr\OcrNormalize::normalizePhone($p) ?? $p;
                    if (preg_match('/^[6-9]\d{9}$/', $n)) {
                        $out[$n] = true;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<int, string>  $lines
     */
    private static function findFirstBiodataAnchorLineIndexStatic(array $lines): ?int
    {
        foreach ($lines as $i => $line) {
            if ($line === '') {
                continue;
            }
            if (preg_match('/^(?:मुलीचे|मुलाचे|वधूचे)\s*(?:नाव|नांव)\s*[:\-]/u', $line)) {
                return $i;
            }
            if (preg_match('/^जन्म\s*(?:तारीख|तारिख|दिनांक|वेळ|वार)\b/u', $line)) {
                return $i;
            }
            if (preg_match('/^जन्मवेळ\b/u', $line)) {
                return $i;
            }
            if (preg_match('/^(?:वडिलांचे|वडिलाचे|वडीलांचे)\s*(?:नाव|नांव)/u', $line)) {
                return $i;
            }
            if (preg_match('/^(?:वडिलांचे|वडिलाचे|वडीलांचे)\s*(?:नोकरी|व्यवसाय)/u', $line)) {
                return $i;
            }
            if (preg_match('/^(?:आईचे|मातेचे)\s*(?:नाव|नांव)/u', $line)) {
                return $i;
            }
            if (preg_match('/^उंची\b/u', $line)) {
                return $i;
            }
            if (preg_match('/^शिक्षण\b/u', $line)) {
                return $i;
            }
            if (preg_match('/^रक्तगट\b/u', $line)) {
                return $i;
            }
            if (preg_match('/^(?:मुलीचे|मुलाचे)\s+चुलते\b/u', $line)) {
                return $i;
            }
            if (preg_match('/^(?:भाऊ|बहिण|बहीण)\s*[\/:]/u', $line)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $lines
     */
    private static function linesContainSuchakBureauMarkerStatic(array $lines): bool
    {
        foreach ($lines as $line) {
            if (preg_match('/(?:वध[ूु]वर|विवाह\s*सूचक|सूचक\s*केंद्र|लग्न\s*सूचक|बायोडाटा|ब्युरो|bureau|suchak)/ui', $line)) {
                return true;
            }
        }

        return false;
    }
}
