<?php

namespace App\Services\Parsing;

use App\Services\Ocr\OcrNormalize;

class IntakeNormalizedBiodataDraftBuilder
{
    private const LABEL_SUFFIX = '(?:[\s:：\-\.]|$)';

    private bool $candidateNameFromFallback = false;

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function build(string $rawText, array $context = []): array
    {
        $this->candidateNameFromFallback = false;
        $prepared = app(IntakeNormalizedBiodataHtmlPreprocessor::class)->prepare($rawText);
        $cleanedText = $this->cleanText($prepared['text']);
        $sections = $this->splitSections($cleanedText);
        $normalized = $this->normalizeSectionValues($sections);
        $draft = [
            'meta' => [
                'schema' => 'normalized_biodata_draft_v1',
                'source' => 'in_memory',
                'html_table_structured' => $prepared['has_structured_table'],
                'table_hints' => $prepared['table_hints'],
                'post_table_body' => $prepared['post_table_body'],
            ],
            'cleaned_text' => $cleanedText,
            'sections' => $sections,
            'normalized' => $normalized,
            'review_flags' => [],
        ];
        $draft = app(IntakeHtmlTableHintApplier::class)->apply($draft);
        $draft['review_flags'] = $this->buildReviewFlags($draft);

        return $draft;
    }

    public function cleanText(string $rawText): string
    {
        $rawText = preg_replace('/^\x{FEFF}/u', '', $rawText) ?? $rawText;
        $rawText = str_replace(["\r\n", "\r"], "\n", $rawText);
        $lines = [];
        foreach (explode("\n", $rawText) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^\*?\s*प्रतिमा/u', $line)) {
                continue;
            }
            if (preg_match('/^(?:[:;|।\-\s*#_=~•■□▪▫]+)$/u', $line)) {
                continue;
            }
            if (preg_match('/^(?::\s*■\s*:)+$/u', $line)) {
                continue;
            }
            $lines[] = preg_replace('/\h+/u', ' ', $line) ?? $line;
        }

        return trim(implode("\n", $lines));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function splitSections(string $cleanedText): array
    {
        $sections = $this->emptySections();
        $current = 'candidate';
        $relativesClosed = false;
        foreach (explode("\n", $cleanedText) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if ($this->isHardSectionBoundary($line)) {
                $relativesClosed = true;
            }
            $detected = $this->detectSection($line, $current, $relativesClosed);
            if ($detected !== null) {
                $current = $detected;
            }
            if ($current === 'relatives' && $relativesClosed) {
                continue;
            }
            $this->appendLine($sections, $current, $line);
        }

        return $sections;
    }

    /**
     * @param  array<string, array<string, mixed>>  $sections
     * @return array<string, mixed>
     */
    public function normalizeSectionValues(array $sections): array
    {
        $core = $this->emptyCore();
        $contacts = [];
        $siblings = [];
        $relatives = [];
        $addresses = [];
        $propertySummary = null;
        $horoscope = null;

        $allLines = $this->allLines($sections);
        $candidateLines = array_merge(
            $sections['candidate']['lines'] ?? [],
            $sections['personal']['lines'] ?? []
        );

        $core['full_name'] = $this->extractCandidateName($candidateLines, $allLines);
        $core['gender'] = $this->inferGender($candidateLines, $core['full_name']);
        $this->extractCoreFields($allLines, $core);
        $this->extractEducationCareer($allLines, $core);

        foreach ($allLines as $line) {
            foreach ($this->extractPhones($line) as $phone) {
                $contacts[$phone] = ['phone_number' => $phone];
            }
        }

        foreach ($allLines as $line) {
            $this->extractSiblingCounts($line, $core, $siblings);
            $this->extractAddressLine($line, $addresses);
            $this->extractPropertyLine($line, $propertySummary);
            $this->extractHoroscopeLine($line, $horoscope);
        }

        foreach (($sections['relatives']['lines'] ?? []) as $line) {
            if ($this->isHardSectionBoundary($line) || $this->startsPahune($line)) {
                continue;
            }
            if (preg_match('/^\s*[-–—]?\s*(मामा|मावशी|माऊशी|आत्या|चुलते|चुलत\s+भाऊ|इतर\s+नातेवाईक|नातेसंबंध)\s*(?::\s*-\s*|[:\-–—]\s*)(.+)$/u', $line, $m)) {
                $name = trim($m[1]);
                $value = trim($m[2]);
                if ($value !== '') {
                    if (preg_match('/^इतर\s+नातेवाईक$/u', $name)) {
                        $core['other_relatives_text'] = $this->setTextOnce($core['other_relatives_text'] ?? null, $value);
                    } else {
                        $relatives[] = ['relation_type' => $name, 'name' => $value, 'raw' => $line];
                    }
                }

                continue;
            }
            if (preg_match('/^(?:श्री\.|कै\.|डॉ\.|श्री\s)/u', $line)) {
                $relatives[] = ['name' => trim($line), 'raw' => $line];
            }
        }

        return [
            'core' => $core,
            'contacts' => array_values($contacts),
            'siblings' => array_values($siblings),
            'relatives' => $relatives,
            'addresses' => $addresses,
            'property_summary' => $propertySummary,
            'horoscope' => $horoscope,
        ];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return list<array<string, string>>
     */
    public function buildReviewFlags(array $draft): array
    {
        $flags = [];
        $core = is_array($draft['normalized']['core'] ?? null) ? $draft['normalized']['core'] : [];
        if (($core['gender'] ?? null) === null && $this->hasCandidateGenderAmbiguity((string) ($core['full_name'] ?? ''), (string) ($draft['cleaned_text'] ?? ''))) {
            $flags[] = ['field' => 'core.gender', 'reason' => 'ambiguous_gender', 'raw' => (string) ($core['full_name'] ?? '')];
        }
        if (($core['gender'] ?? null) === null) {
            $flags[] = ['field' => 'core.gender', 'reason' => 'missing_critical', 'raw' => ''];
        }
        if (empty($draft['normalized']['contacts'] ?? [])) {
            $flags[] = ['field' => 'core.primary_contact_number', 'reason' => 'missing_critical', 'raw' => ''];
        }
        if (($core['religion'] ?? null) === null && ($core['caste'] ?? null) !== null) {
            $flags[] = ['field' => 'core.religion', 'reason' => 'missing_critical', 'raw' => (string) ($core['caste'] ?? '')];
        }
        if (($core['full_name'] ?? null) !== null && in_array($core['full_name'], [$core['father_name'] ?? null, $core['mother_name'] ?? null], true)) {
            $flags[] = ['field' => 'core.full_name', 'reason' => 'suspicious_candidate_name_matches_parent', 'raw' => (string) $core['full_name']];
        }
        if (($core['full_name'] ?? null) !== null && $this->isSuspiciousHeadingAsName((string) $core['full_name'])) {
            $flags[] = ['field' => 'core.full_name', 'reason' => 'suspicious_heading_as_name', 'raw' => (string) $core['full_name'], 'suggested_section' => 'basic-info'];
        }
        if ($this->candidateNameFromFallback && ($core['full_name'] ?? null) !== null) {
            $flags[] = ['field' => 'core.full_name', 'reason' => 'candidate_name_from_heading_fallback', 'raw' => (string) $core['full_name']];
        }
        foreach (($draft['normalized']['relatives'] ?? []) as $relative) {
            $blob = implode(' ', array_map('strval', is_array($relative) ? $relative : []));
            if (preg_match('/घरचा\s+पत्ता|सध्याचा\s+पत्ता|मोबाईल|मोबाइल|संपर्क|प्रोपर्टी|स्थावर/u', $blob)) {
                $flags[] = ['field' => 'relatives', 'reason' => 'relative_address_bleed_risk', 'raw' => $blob];
            }
        }
        foreach ($this->unmappedUsefulLineFlags($draft) as $flag) {
            $flags[] = $flag;
        }

        return $flags;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function emptySections(): array
    {
        return [
            'candidate' => ['lines' => [], 'fields' => []],
            'personal' => ['lines' => [], 'fields' => []],
            'family' => ['lines' => [], 'fields' => []],
            'contacts' => ['lines' => [], 'phones' => []],
            'addresses' => ['lines' => [], 'fields' => []],
            'property' => ['lines' => [], 'summary_text' => null],
            'horoscope' => ['lines' => [], 'fields' => []],
            'education_career' => ['lines' => [], 'fields' => []],
            'relatives' => ['lines' => [], 'rows' => []],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyCore(): array
    {
        return [
            'full_name' => null,
            'gender' => null,
            'date_of_birth' => null,
            'birth_time' => null,
            'birth_place_text' => null,
            'religion' => null,
            'caste' => null,
            'sub_caste' => null,
            'height_cm' => null,
            'complexion' => null,
            'blood_group' => null,
            'marital_status' => null,
            'father_name' => null,
            'father_occupation' => null,
            'mother_name' => null,
            'mother_occupation' => null,
            'brother_count' => null,
            'sister_count' => null,
            'highest_education' => null,
            'occupation_title' => null,
            'company_name' => null,
            'annual_income' => null,
            'work_location_text' => null,
        ];
    }

    private function detectSection(string $line, string $current, bool $relativesClosed = false): ?string
    {
        if ($this->startsAddressLine($line)) {
            return 'addresses';
        }
        if ($this->startsContactLine($line)) {
            return 'contacts';
        }
        if ($this->startsPropertyLine($line)) {
            return 'property';
        }
        if ($this->startsPahune($line)) {
            return 'family';
        }
        if (preg_match('/^(?:वैयक्तिक\s+माहिती|वैयक्तिक\s+तपशील)/u', $line)) {
            return 'personal';
        }
        if (preg_match('/^(?:कौटुंबिक\s+माहिती|कौटुंबिक\s+तपशील)/u', $line)) {
            return 'family';
        }
        if (preg_match('/^(?:रास|राशी|नक्षत्र|देवक|कुलदैवत|कुलस्वामी|कुळस्वामी|नाडी|गण|चरण|गोत्र)'.self::LABEL_SUFFIX.'/u', $line)) {
            return 'horoscope';
        }
        if (preg_match('/^(?:शिक्षण|नोकरी|व्यवसाय|वेतन|उत्पन्न|नोकरी\/व्यवसाय)'.self::LABEL_SUFFIX.'/u', $line)) {
            return 'education_career';
        }
        if (! $relativesClosed && preg_match('/^\s*[-–—]?\s*(?:मामा|मावशी|माऊशी|आत्या|चुलते|चुलत\s+भाऊ|इतर\s+नातेवाईक|नातेसंबंध)'.self::LABEL_SUFFIX.'/u', $line)) {
            return 'relatives';
        }

        return null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $sections
     */
    private function appendLine(array &$sections, string $section, string $line): void
    {
        $sections[$section]['lines'][] = $line;
        if ($section === 'contacts') {
            foreach ($this->extractPhones($line) as $phone) {
                $sections[$section]['phones'][$phone] = $phone;
            }
        }
        if ($section === 'property') {
            $existing = trim((string) ($sections[$section]['summary_text'] ?? ''));
            $sections[$section]['summary_text'] = trim($existing.' '.$line);
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $sections
     * @return list<string>
     */
    private function allLines(array $sections): array
    {
        $lines = [];
        foreach ($sections as $section) {
            foreach (($section['lines'] ?? []) as $line) {
                if (is_string($line)) {
                    $lines[] = $line;
                }
            }
        }

        return $lines;
    }

    /**
     * @param  list<string>  $candidateLines
     * @param  list<string>  $allLines
     */
    private function extractCandidateName(array $candidateLines, array $allLines): ?string
    {
        foreach ($candidateLines as $line) {
            if (preg_match('/^(?:मुलाचे\s+नां?व|मुलीचे\s+नां?व|वधूचे\s+नां?व|नां?व)\s*(?::\s*-\s*|[:\-]\s*)(.+)$/u', $line, $m)) {
                return $this->cleanPersonName($m[1]);
            }
        }

        $parentRelativeNames = $this->extractParentRelativeNames($allLines);
        $hasBiodataContext = $this->hasBiodataCandidateContext($allLines);

        foreach ($allLines as $index => $line) {
            if (preg_match('/^#{1,6}\s*(.+)$/u', trim($line), $m)) {
                $headingName = $this->cleanPersonName($m[1]);
                if ($this->isPlausibleCandidateName($headingName)
                    && ! $this->isParentRelativeName($headingName, $parentRelativeNames)
                    && ! $this->lineHasRelativeContextMarker($line, $allLines, $index)
                    && $hasBiodataContext) {
                    $this->candidateNameFromFallback = true;

                    return $headingName;
                }
            }
        }

        foreach ($allLines as $index => $line) {
            if ($this->isParentRelativeLabelLine($line) || $this->lineHasRelativeContextMarker($line, $allLines, $index)) {
                continue;
            }
            $t = trim(preg_replace('/^#{1,6}\s*/u', '', trim($line)) ?? trim($line));
            if (! $this->isPlausibleCandidateName($t) || preg_match('/बायोडाटा|वैयक्तिक|कौटुंबिक|श्री\s+साई|गणेश|प्रसन्न/u', $t)) {
                continue;
            }
            if ($this->isParentRelativeName($t, $parentRelativeNames)) {
                continue;
            }
            if (! $hasBiodataContext && ! preg_match('/^#{1,6}\s/u', trim($line))) {
                continue;
            }
            $this->candidateNameFromFallback = true;

            return $this->cleanPersonName($t);
        }

        return null;
    }

    /**
     * @param  list<string>  $candidateLines
     */
    private function inferGender(array $candidateLines, ?string $fullName): ?string
    {
        foreach ($candidateLines as $line) {
            if (preg_match('/^(?:मुलाचे\s+नां?व)/u', $line) || preg_match('/\bचि\./u', $line)) {
                return 'male';
            }
            if (preg_match('/^(?:मुलीचे\s+नां?व|वधूचे\s+नां?व)/u', $line) || preg_match('/\bकु\./u', $line)) {
                return $this->candidateHonorificConflictsWithFemale($fullName ?? $line) ? null : 'female';
            }
        }
        if ($fullName !== null && preg_match('/^चि\./u', $fullName)) {
            return 'male';
        }
        if ($fullName !== null && preg_match('/^कु\./u', $fullName)) {
            return $this->candidateHonorificConflictsWithFemale($fullName) ? null : 'female';
        }

        return null;
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, mixed>  $core
     */
    private function extractCoreFields(array $lines, array &$core): void
    {
        foreach ($lines as $line) {
            $normalizedLine = OcrNormalize::normalizeDigits($line);
            if (preg_match('/(?:जन्म\s+तारीख|जन्मतारीख)\s*(?::\s*-\s*|[:\-]\s*)(.+)$/u', $line, $m)) {
                $core['date_of_birth'] = trim($m[1]);
            }
            if (preg_match('/जन्म\s+वेळ\s*(?::\s*-\s*|[:\-]\s*)(.+)$/u', $line, $m)) {
                $core['birth_time'] = trim($m[1]);
            }
            if ($core['birth_time'] === null
                && preg_match('/^(?:वार|जन्म\s*वार\s*व\s*वेळ|जन्मवार\s*व\s*वेळ)\s*(?::\s*-\s*|[:\-]\s*)(.+)$/u', $line, $m)
                && preg_match('/\d{1,2}(?:[.:]\d{1,2})?\s*(?:A\.?M\.?|P\.?M\.?|am|pm)?|सकाळी|दुपारी|सायंकाळी|रात्री/ui', OcrNormalize::normalizeDigits($m[1]))) {
                $core['birth_time'] = trim($m[1]);
            }
            if (preg_match('/(?:जन्म\s*(?:ठिकाण|स्थळ)|जन्मठिकाण)\s*(?::\s*-\s*|[:\-]\s*)(.+)$/u', $line, $m)) {
                $core['birth_place_text'] = trim($m[1]);
            }
            if (preg_match('/(?:धर्म|religion)\s*(?::\s*-\s*|[:\-]\s*)(.+)$/ui', $line, $m)) {
                $core['religion'] = trim($m[1]);
            }
            if (preg_match('/(?:जात|कास्ट)\s*(?::\s*-\s*|[:\-]\s*)(.+)$/u', $line, $m)) {
                $this->normalizeCasteLine(trim($m[1]), $core);
            }
            if (preg_match('/(?:आईचे|मातेचे)\s+नां?व\s*(?::\s*-\s*|[:\-]\s*)(.+)$/u', $line, $m)) {
                [$core['mother_name'], $core['mother_occupation']] = $this->splitNameOccupation($m[1]);
            }
            if (preg_match('/(?:पित्याचे|वडिलांचे|वडीलांचे)\s+नां?व\s*(?::\s*-\s*|[:\-]\s*)(.+)$/u', $line, $m)) {
                [$core['father_name'], $core['father_occupation']] = $this->splitNameOccupation($m[1]);
            }
            if (preg_match('/(?:उंची|ऊंची)\s*(?::\s*-\s*|[:\-]\s*)(.+)$/u', $line, $m)) {
                $core['height_cm'] = $this->parseHeightCm($m[1]);
            }
            if (preg_match('/(?:वर्ण|complexion)\s*(?::\s*-\s*|[:\-]\s*)(.+)$/ui', $line, $m)) {
                $core['complexion'] = trim($m[1]);
            }
            if (preg_match('/(?:ब्लड\s*ग्रुप|रक्त\s*गट|blood\s*group)\s*(?::\s*-\s*|[:\-]\s*)(.+)$/ui', $line, $m)) {
                $core['blood_group'] = trim($m[1]);
            }
            if (preg_match('/(?:वैवाहिक|marital)\s*(?::\s*-\s*|[:\-]\s*)(.+)$/ui', $line, $m)) {
                $core['marital_status'] = trim($m[1]);
            }
            if ($core['annual_income'] === null && preg_match('/([0-9]+(?:\.[0-9]+)?)\s*(?:LAC|लाख)/ui', $normalizedLine, $m)) {
                $core['annual_income'] = (int) round(((float) $m[1]) * 100000);
            }
        }
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, mixed>  $core
     */
    private function extractEducationCareer(array $lines, array &$core): void
    {
        foreach ($lines as $line) {
            if (preg_match('/शिक्षण\s*(?::\s*-\s*|[:\-]\s*)(.+)$/u', $line, $m)) {
                $core['highest_education'] = trim($m[1]);
            }
            if (preg_match('/(?:नोकरी\/व्यवसाय|नोकरी|व्यवसाय)\s*(?::\s*-\s*|[:\-]\s*)(.+)$/u', $line, $m)) {
                $work = trim($m[1]);
                $this->parseWorkLine($work, $core);
            }
            if (preg_match('/(?:कंपनी|company)\s*(?::\s*-\s*|[:\-]\s*)(.+)$/ui', $line, $m)) {
                $core['company_name'] = trim($m[1]);
            }
            if (preg_match('/(?:कामाचे\s+ठिकाण|नोकरीचे\s+ठिकाण|work\s+location)\s*(?::\s*-\s*|[:\-]\s*)(.+)$/ui', $line, $m)) {
                $core['work_location_text'] = trim($m[1]);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function extractPhones(string $text): array
    {
        $normalized = OcrNormalize::normalizeDigits($text);
        $found = [];
        if (preg_match_all('/(?<!\d)([6-9]\d{9})(?!\d)/u', $normalized, $tight)) {
            foreach ($tight[1] as $phone) {
                $found[$phone] = $phone;
            }
        }
        if (preg_match_all('/(?<!\d)([6-9]\d{4})[\s\-\/]+(\d{5})(?!\d)/u', $normalized, $split, PREG_SET_ORDER)) {
            foreach ($split as $m) {
                $phone = $m[1].$m[2];
                if (preg_match('/^[6-9]\d{9}$/', $phone)) {
                    $found[$phone] = $phone;
                }
            }
        }

        return array_values($found);
    }

    /**
     * @param  array<string, mixed>  $core
     * @param  array<string, array<string, mixed>>  $siblings
     */
    private function extractSiblingCounts(string $line, array &$core, array &$siblings): void
    {
        if (preg_match('/भाऊ\s*(?::\s*-\s*|[:\-]\s*)(.+)$/u', $line, $m)) {
            $value = trim($m[1]);
            if ($this->isNoSiblingValue($value)) {
                $core['brother_count'] = 0;

                return;
            }
            if ($this->isNumericCountValue($value)) {
                $core['brother_count'] = (int) OcrNormalize::normalizeDigits($value);

                return;
            }
            $siblings[] = ['relation_type' => 'brother', 'name' => $value];
        }
        if (preg_match('/(?:बहीण|बहिण|बहिणी)\s*(?::\s*-\s*|[:\-]\s*)(.+)$/u', $line, $m)) {
            $value = trim($m[1]);
            if ($this->isNoSiblingValue($value)) {
                $core['sister_count'] = 0;

                return;
            }
            if ($this->isOneCountValue($value)) {
                $core['sister_count'] = 1;

                return;
            }
            if ($this->isNumericCountValue($value)) {
                $core['sister_count'] = (int) OcrNormalize::normalizeDigits($value);

                return;
            }
            $siblings[] = ['relation_type' => 'sister', 'name' => $value];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $addresses
     */
    private function extractAddressLine(string $line, array &$addresses): void
    {
        if (! preg_match('/^(?:घरचा\s+पत्ता|घराचा\s+पत्ता|घर\s+पत्ता|सध्याचा\s+पत्ता|गावचा\s+पत्ता|मुळगाव|मूळगाव)\s*(?::\s*-\s*|[:\-]\s*)(.+)$/u', $line, $m)) {
            return;
        }
        $address = preg_split('/\s+(?:मोबाईल|मोबाइल|संपर्क|प्रोपर्टी|प्रॉपर्टी|स्थावर|कौटुंबिक)'.self::LABEL_SUFFIX.'/u', trim($m[1]), 2)[0] ?? trim($m[1]);
        $addresses[] = ['raw' => trim($address), 'address_line' => trim($address)];
    }

    private function extractPropertyLine(string $line, mixed &$propertySummary): void
    {
        if ($this->startsAddressLine($line)) {
            return;
        }
        $isProperty = $this->startsPropertyLine($line) || $this->containsPropertyOwnershipSignal($line);
        if (! $isProperty) {
            return;
        }
        $text = trim($line);
        $propertySummary = [
            'summary_text' => trim(((string) ($propertySummary['summary_text'] ?? '')).' '.$text),
        ];
    }

    private function extractHoroscopeLine(string $line, mixed &$horoscope): void
    {
        if (! preg_match('/^(?:रास|राशी|नक्षत्र|देवक|कुलदैवत|कुलस्वामी|कुळस्वामी|नाडी|गण|चरण|गोत्र)'.self::LABEL_SUFFIX.'/u', $line)) {
            return;
        }
        $horoscope = is_array($horoscope) ? $horoscope : ['raw' => []];
        $horoscope['raw'][] = $line;
        foreach ([
            'rashi' => ['रास', 'राशी'],
            'nakshatra' => ['नक्षत्र'],
            'nadi' => ['नाडी'],
            'gan' => ['गण'],
            'devak' => ['देवक'],
            'kuldaivat' => ['कुलदैवत', 'कुलस्वामी', 'कुळस्वामी'],
            'charan' => ['चरण'],
            'gotra' => ['गोत्र'],
        ] as $field => $labels) {
            $value = $this->extractLabeledValue($line, $labels);
            if ($value !== null && ! $this->horoscopeValueLooksPolluted($value)) {
                $horoscope[$field] = $value;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $core
     */
    private function normalizeCasteLine(string $value, array &$core): void
    {
        $value = trim(str_replace(['{', '}'], ['(', ')'], $value));
        $kuliPattern = '(?:कुळी|क्‌ळी|क[\x{094D}\x{200C}\s]*ळी|कळी)';
        if (preg_match('/हिंद[ुू]\s*मराठा\s*\(?\s*([0-9०-९]+\s*'.$kuliPattern.')\s*\)?/u', $value, $m)
            || preg_match('/हिंद[ुू]\s*[-–]?\s*([0-9०-९]+\s*'.$kuliPattern.')\s*मराठा/u', $value, $m)) {
            $core['religion'] = 'हिंदू';
            $core['caste'] = 'मराठा';
            $core['sub_caste'] = $this->normalizeKuli($m[1]);

            return;
        }
        if (preg_match('/([0-9०-९]+\s*'.$kuliPattern.')\s*मराठा/u', $value, $m)) {
            $core['caste'] = 'मराठा';
            $core['sub_caste'] = $this->normalizeKuli($m[1]);
            if (preg_match('/हिंद[ुू]/u', $value)) {
                $core['religion'] = 'हिंदू';
            }
        }
        if ($core['caste'] === null && preg_match('/^मराठा$/u', $value)) {
            $core['caste'] = 'मराठा';
        }
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function splitNameOccupation(string $value): array
    {
        $value = trim($value);
        $occupation = null;
        if (preg_match('/[\(\{]\s*(.+?)\s*[\)\}]/u', $value, $m)) {
            $occupation = trim($m[1]);
            $value = trim(preg_replace('/[\(\{]\s*.+?\s*[\)\}]/u', '', $value) ?? $value);
        }

        return [$this->cleanPersonName($value), $occupation];
    }

    private function cleanPersonName(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function normalizeKuli(string $value): string
    {
        $value = preg_replace('/(?:क्‌ळी|क[\x{094D}\x{200C}\s]*ळी|कळी)/u', 'कुळी', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function parseHeightCm(string $value): ?float
    {
        $v = OcrNormalize::normalizeDigits($value);
        if (preg_match('/([0-9]+)\s*(?:फूट|फुट|feet|ft)\s*(?:([0-9]+)\s*(?:इंच|inch|in)?)?/ui', $v, $m)) {
            $feet = (int) $m[1];
            $inches = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;

            return round(($feet * 12 + $inches) * 2.54, 2);
        }

        return null;
    }

    private function isNoSiblingValue(string $value): bool
    {
        $value = preg_replace('/^[\s.।:(){}\[\]\-–—]+|[\s.।:(){}\[\]\-–—]+$/u', '', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return (bool) preg_match('/^(?:नाही|None|No|०|0)$/ui', $value);
    }

    private function isOneCountValue(string $value): bool
    {
        $value = preg_replace('/^[\s.।:(){}\[\]\-–—]+|[\s.।:(){}\[\]\-–—]+$/u', '', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return (bool) preg_match('/^(?:एक|१|1)(?:\s*\(?\s*अविवाहित\s*\)?)?$/u', $value);
    }

    private function isNumericCountValue(string $value): bool
    {
        $v = trim(OcrNormalize::normalizeDigits($value));

        return (bool) preg_match('/^[0-9]+$/', $v);
    }

    private function startsAddressLine(string $line): bool
    {
        return (bool) preg_match('/^(?:घरचा\s+पत्ता|घराचा\s+पत्ता|घर\s+पत्ता|सध्याचा\s+पत्ता|गावचा\s+पत्ता|मुळगाव|मूळगाव)'.self::LABEL_SUFFIX.'/u', $line);
    }

    private function startsContactLine(string $line): bool
    {
        return (bool) preg_match('/^(?:मोबाईल|मोबाइल|संपर्क|Mobile|Phone)(?:[\s:：\-\.]|$)/ui', $line);
    }

    private function startsPropertyLine(string $line): bool
    {
        if ($this->startsAddressLine($line)) {
            return false;
        }
        if (preg_match('/^(?:प्रोपर्टी|प्रॉपर्टी|स्थावर|शेती|प्लॉट|फ्लॅट|बंगला)'.self::LABEL_SUFFIX.'/u', $line)) {
            return true;
        }
        if (preg_match('/^(?:स्वत[:ः]?चे\s+घर|मालकीचे\s+घर)'.self::LABEL_SUFFIX.'/u', $line)) {
            return true;
        }
        if (preg_match('/^घर'.self::LABEL_SUFFIX.'/u', $line) && $this->containsPropertyOwnershipSignal($line)) {
            return true;
        }

        return false;
    }

    private function containsPropertyOwnershipSignal(string $line): bool
    {
        return (bool) preg_match('/(?:स्वत[:ः]?च(?:े|्या)|मालकीच(?:े|्या)|बंगला|फ्लॅट|प्लॉट|स्थावर|शेती)\s*(?:घर)?/u', $line);
    }

    /**
     * @param  array<string, mixed>  $core
     */
    private function parseWorkLine(string $work, array &$core): void
    {
        $work = trim($work);
        if ($work === '') {
            return;
        }

        if (preg_match('/^(.+?)\s+(?:at|@|मध्ये)\s+(.+?)(?:,\s*(.+))?$/ui', $work, $m)) {
            $core['occupation_title'] = trim($m[1]);
            $core['company_name'] = trim($m[2]);
            if (isset($m[3]) && trim($m[3]) !== '') {
                $core['work_location_text'] = trim($m[3]);
            }

            return;
        }

        if (preg_match('/^(.+?)\s*[-–—]\s*([^,]+)(?:,\s*(.+))?$/u', $work, $m)) {
            $core['occupation_title'] = trim($m[1]);
            $core['company_name'] = trim($m[2]);
            if (isset($m[3]) && trim($m[3]) !== '') {
                $core['work_location_text'] = trim($m[3]);
            }

            return;
        }

        $core['occupation_title'] = $work;
    }

    private function setTextOnce(mixed $current, string $value): string
    {
        $current = trim((string) $current);
        $value = trim($value);
        if ($current === '') {
            return $value;
        }
        if (str_contains($current, $value)) {
            return $current;
        }

        return $current.'; '.$value;
    }

    /**
     * @param  list<string>  $labels
     */
    private function extractLabeledValue(string $line, array $labels): ?string
    {
        foreach ($labels as $label) {
            $pattern = '/'.preg_quote($label, '/').'\s*(?::\s*-\s*|[:\-–—]\s*|\s+)(.+)$/u';
            if (! preg_match($pattern, $line, $m)) {
                continue;
            }
            $value = trim($m[1]);
            $value = preg_split(
                '/\s+(?:रास|राशी|नक्षत्र|देवक|कुलदैवत|कुलस्वामी|कुळस्वामी|नाडी|गण|चरण|गोत्र|ब्लड\s*ग्रुप|रक्त\s*गट|मोबाईल|मोबाइल|संपर्क|प्रोपर्टी|प्रॉपर्टी|स्थावर)'.self::LABEL_SUFFIX.'/u',
                $value,
                2
            )[0] ?? $value;
            $value = trim($value);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function horoscopeValueLooksPolluted(string $value): bool
    {
        return (bool) preg_match('/(?:ब्लड\s*ग्रुप|रक्त\s*गट|मोबाईल|मोबाइल|संपर्क|प्रोपर्टी|प्रॉपर्टी|स्थावर|घरचा\s+पत्ता|सध्याचा\s+पत्ता)/u', $value);
    }

    private function isSuspiciousHeadingAsName(string $value): bool
    {
        return (bool) preg_match('/^(?:वैयक्तिक\s+माहिती|कौटुंबिक\s+माहिती|कौटुंबिक\s+तपशील|शिक्षण|नोकरी|व्यवसाय|बायोडाटा)$/u', trim($value));
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return list<array<string, string>>
     */
    private function unmappedUsefulLineFlags(array $draft): array
    {
        $flags = [];
        $normalized = is_array($draft['normalized'] ?? null) ? $draft['normalized'] : [];
        $core = is_array($normalized['core'] ?? null) ? $normalized['core'] : [];
        $lines = $this->allLines(is_array($draft['sections'] ?? null) ? $draft['sections'] : []);

        foreach ($lines as $line) {
            $raw = trim($line);
            if ($raw === '' || mb_strlen($raw) > 220) {
                continue;
            }
            if ($this->lineLooksMixedFieldValue($raw)) {
                $flags['mixed_field_value|'.$raw] = [
                    'field' => 'review.missing',
                    'reason' => 'mixed_field_value',
                    'raw' => $raw,
                    'suggested_section' => 'review_needed',
                ];
            }
            if ($this->lineHasMappedValue($raw, $normalized, $core)) {
                continue;
            }
            $flag = $this->reviewFlagForUsefulLine($raw);
            if ($flag !== null) {
                $key = $flag['field'].'|'.$flag['reason'].'|'.$flag['raw'];
                $flags[$key] = $flag;
            }
        }

        return array_values($flags);
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<string, mixed>  $core
     */
    private function lineHasMappedValue(string $line, array $normalized, array $core): bool
    {
        foreach ($core as $value) {
            if (is_scalar($value) && trim((string) $value) !== '' && str_contains($line, trim((string) $value))) {
                return true;
            }
        }
        foreach (['contacts', 'relatives', 'siblings', 'addresses'] as $bucket) {
            foreach (($normalized[$bucket] ?? []) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                foreach ($row as $value) {
                    if (is_scalar($value) && trim((string) $value) !== '' && str_contains($line, trim((string) $value))) {
                        return true;
                    }
                }
            }
        }
        $propertyText = (string) (($normalized['property_summary'] ?? [])['summary_text'] ?? '');
        if ($propertyText !== '' && str_contains($propertyText, $line)) {
            return true;
        }
        $horoscopeRaw = is_array(($normalized['horoscope'] ?? [])['raw'] ?? null) ? ($normalized['horoscope'] ?? [])['raw'] : [];
        foreach ($horoscopeRaw as $raw) {
            if (is_string($raw) && trim($raw) === $line) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>|null
     */
    private function reviewFlagForUsefulLine(string $line): ?array
    {
        $rows = [
            ['mixed_field_value', 'review_needed', '/(?:जन्म|उंची|जात|शिक्षण|नोकरी|राशी|नाडी|गण|मोबाईल).*(?:जन्म|उंची|जात|शिक्षण|नोकरी|राशी|नाडी|गण|मोबाईल)/u'],
            ['unmapped_horoscope', 'horoscope', '/(?:रास|राशी|नक्षत्र|देवक|कुलदैवत|कुलस्वामी|कुळस्वामी|नाडी|गण|चरण|गोत्र)/u'],
            ['unmapped_relatives', 'relatives', '/^\s*[-–—]\s*(?:मामा|मावशी|माऊशी|आत्या|चुलते|इतर\s+नातेवाईक)/u'],
            ['unmapped_family', 'family-details', '/(?:वडील|आई|भाऊ|बहिण|कुटुंब|कौटुंबिक)/u'],
            ['unmapped_address', 'basic-info', '/(?:पत्ता|मु\.?\s*पो\.?|रा\.)/u'],
            ['unmapped_property', 'property', '/(?:प्रोपर्टी|प्रॉपर्टी|स्थावर|शेती|फ्लॅट|प्लॉट|मालकीचे\s+घर)/u'],
            ['unmapped_contact', 'basic-info', '/(?:मोबाईल|मोबाइल|संपर्क|[6-9][0-9]{9})/u'],
            ['unmapped_career', 'education-career', '/(?:शिक्षण|नोकरी|व्यवसाय|कंपनी|उत्पन्न|वेतन)/u'],
        ];

        foreach ($rows as [$reason, $section, $pattern]) {
            if (preg_match($pattern, OcrNormalize::normalizeDigits($line))) {
                return [
                    'field' => $section === 'relatives' ? 'relatives' : 'review.'.$section,
                    'reason' => $reason,
                    'raw' => $line,
                    'suggested_section' => $section,
                ];
            }
        }

        return null;
    }

    private function lineLooksMixedFieldValue(string $line): bool
    {
        preg_match_all('/(?:जन्म\s+वेळ|जन्म\s+तारीख|जन्म\s+स्थळ|उंची|जात|धर्म|शिक्षण|नोकरी|व्यवसाय|राशी|नक्षत्र|नाडी|गण|देवक|मोबाईल|मोबाइल|संपर्क|प्रॉपर्टी|प्रोपर्टी)'.self::LABEL_SUFFIX.'/u', $line, $matches);

        return count($matches[0] ?? []) > 1;
    }

    private function isHardSectionBoundary(string $line): bool
    {
        return $this->startsAddressLine($line)
            || $this->startsContactLine($line)
            || $this->startsPropertyLine($line)
            || $this->startsPahune($line)
            || (bool) preg_match('/^(?:नातेसंबंध|शिक्षण|नोकरी|व्यवसाय|वेतन|उत्पन्न)'.self::LABEL_SUFFIX.'/u', $line);
    }

    private function startsPahune(string $line): bool
    {
        return (bool) preg_match('/^पाहुणे'.self::LABEL_SUFFIX.'/u', $line);
    }

    /**
     * @param  list<string>  $allLines
     * @return list<string>
     */
    private function extractParentRelativeNames(array $allLines): array
    {
        $names = [];
        foreach ($allLines as $line) {
            if (preg_match('/^(?:पित्याचे|वडिलांचे|वडीलांचे|वडील)\s+नां?व\s*(?::\s*-\s*|[:\-]\s*)(.+)$/u', $line, $m)) {
                $names[] = $this->cleanPersonName($this->splitNameOccupation($m[1])[0]);
            }
            if (preg_match('/^(?:आईचे|मातेचे|आई)\s+नां?व\s*(?::\s*-\s*|[:\-]\s*)(.+)$/u', $line, $m)) {
                $names[] = $this->cleanPersonName($this->splitNameOccupation($m[1])[0]);
            }
            if (preg_match('/^(?:मामा|मावशी|आत्या|चुलत|जावई|पाहुणे|नातेसंबंध)\s*[:\-]\s*(.+)$/u', $line, $m)) {
                $names[] = $this->cleanPersonName(trim($m[1]));
            }
        }

        return array_values(array_filter(array_unique($names)));
    }

    /**
     * @param  list<string>  $allLines
     */
    private function hasBiodataCandidateContext(array $allLines): bool
    {
        $blob = implode("\n", $allLines);

        return (bool) preg_match('/(?:बायोडाटा|मुलाचे\s+नां?व|मुलीचे\s+नां?व|वधूचे\s+नां?व|(?:पित्याचे|वडील|आईचे|मातेचे)\s+नां?व|(?:जात|कास्ट)|#{1,6}\s+[\p{L}])/u', $blob);
    }

    private function isParentRelativeLabelLine(string $line): bool
    {
        return (bool) preg_match('/^(?:पित्याचे|वडिलांचे|वडीलांचे|वडील|आईचे|मातेचे|आई|मामा|मावशी|आत्या|चुलत|जावई|पाहुणे|नातेसंबंध)'.self::LABEL_SUFFIX.'/u', $line);
    }

    /**
     * @param  list<string>  $allLines
     */
    private function lineHasRelativeContextMarker(string $line, array $allLines, int $index): bool
    {
        $window = [];
        for ($i = max(0, $index - 2); $i <= min(count($allLines) - 1, $index + 2); $i++) {
            $window[] = $allLines[$i];
        }
        $blob = implode("\n", $window);

        return (bool) preg_match('/(?:वडील|पित्याचे|आई|मामा|मावशी|आत्या|चुलत|पाहुणे|नातेसंबंध|जावई)(?:[\s:：\-\.]|$)/u', $blob)
            && ! preg_match('/^#{1,6}\s/u', trim($line));
    }

    private function isPlausibleCandidateName(string $value): bool
    {
        return (bool) preg_match('/^([\p{L}\p{M}.]+\s+[\p{L}\p{M}.]+(?:\s+[\p{L}\p{M}.]+){0,3})$/u', trim($value));
    }

    /**
     * @param  list<string>  $parentRelativeNames
     */
    private function isParentRelativeName(string $candidate, array $parentRelativeNames): bool
    {
        $candidate = $this->cleanPersonName($candidate);
        foreach ($parentRelativeNames as $name) {
            if ($candidate === $name || str_contains($name, $candidate) || str_contains($candidate, $name)) {
                return true;
            }
        }

        return false;
    }

    private function candidateHonorificConflictsWithFemale(string $candidateText): bool
    {
        $name = trim((string) preg_replace('/^(?:कु\.|कुं\.)\s*/u', '', trim($candidateText)));
        $first = trim((string) (preg_split('/\s+/u', $name)[0] ?? ''));

        return in_array($first, ['युवराज'], true);
    }

    private function hasCandidateGenderAmbiguity(string $fullName, string $text): bool
    {
        return $this->candidateHonorificConflictsWithFemale($fullName)
            || (preg_match('/कु\.\s*युवराज/u', $text) === 1);
    }
}
