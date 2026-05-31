<?php

namespace App\Services\Parsing;

use App\Services\Ocr\OcrNormalize;

class IntakeNormalizedBiodataDraftBuilder
{
    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function build(string $rawText, array $context = []): array
    {
        $cleanedText = $this->cleanText($rawText);
        $sections = $this->splitSections($cleanedText);
        $normalized = $this->normalizeSectionValues($sections);
        $draft = [
            'meta' => [
                'schema' => 'normalized_biodata_draft_v1',
                'source' => 'in_memory',
            ],
            'cleaned_text' => $cleanedText,
            'sections' => $sections,
            'normalized' => $normalized,
            'review_flags' => [],
        ];
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
        foreach (explode("\n", $cleanedText) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $detected = $this->detectSection($line, $current);
            if ($detected !== null) {
                $current = $detected;
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
            if ($this->startsAddressPropertyOrContact($line) || $this->startsPahune($line)) {
                continue;
            }
            if (preg_match('/^(?:मामा|मावशी|आत्या|चुलत\s+भाऊ|नातेसंबंध)\s*[:\-]\s*(.+)$/u', $line, $m)) {
                $name = trim($m[1]);
                if ($name !== '') {
                    $relatives[] = ['name' => $name, 'raw' => $line];
                }
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
        foreach (($draft['normalized']['relatives'] ?? []) as $relative) {
            $blob = implode(' ', array_map('strval', is_array($relative) ? $relative : []));
            if (preg_match('/घरचा\s+पत्ता|सध्याचा\s+पत्ता|मोबाईल|मोबाइल|संपर्क|प्रोपर्टी|स्थावर/u', $blob)) {
                $flags[] = ['field' => 'relatives', 'reason' => 'relative_address_bleed_risk', 'raw' => $blob];
            }
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

    private function detectSection(string $line, string $current): ?string
    {
        if ($this->startsAddressPropertyOrContact($line)) {
            if (preg_match('/^(?:संपर्क|मोबाईल|मोबाइल|Mobile|Phone)/ui', $line)) {
                return 'contacts';
            }
            if (preg_match('/^(?:प्रोपर्टी|प्रॉपर्टी|स्थावर|शेती|प्लॉट|फ्लॅट|घर)\b/u', $line)) {
                return 'property';
            }

            return 'addresses';
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
        if (preg_match('/^(?:रास|राशी|नक्षत्र|देवक|कुलदैवत)\b/u', $line)) {
            return 'horoscope';
        }
        if (preg_match('/^(?:शिक्षण|नोकरी|व्यवसाय|वेतन|उत्पन्न|नोकरी\/व्यवसाय)\b/u', $line)) {
            return 'education_career';
        }
        if (preg_match('/^(?:मामा|मावशी|आत्या|चुलत\s+भाऊ|नातेसंबंध)\b/u', $line)) {
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
        foreach ($allLines as $line) {
            $t = trim(preg_replace('/^#{1,6}\s*/u', '', trim($line)) ?? trim($line));
            if (preg_match('/^(?:पित्याचे|वडिलांचे|वडीलांचे|आईचे|मातेचे|मामा|मावशी|आत्या)\s+नां?व/u', $t)) {
                continue;
            }
            if (preg_match('/^([\p{L}\p{M}.]+\s+[\p{L}\p{M}.]+(?:\s+[\p{L}\p{M}.]+){0,3})$/u', $t, $m)
                && ! preg_match('/बायोडाटा|वैयक्तिक|कौटुंबिक|श्री\s+साई|गणेश|प्रसन्न/u', $t)) {
                return $this->cleanPersonName($m[1]);
            }
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
            if (preg_match('/(?:जन्म\s*(?:ठिकाण|स्थळ)|जन्मठिकाण)\s*(?::\s*-\s*|[:\-]\s*)(.+)$/u', $line, $m)) {
                $core['birth_place_text'] = trim($m[1]);
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
                if (preg_match('/^नोकरी\s*[-–—]\s*([A-Za-z.]+)\s+([^,]+),\s*(.+)$/u', $work, $wm)) {
                    $core['occupation_title'] = trim($wm[1]);
                    $core['company_name'] = trim($wm[2]);
                    $core['work_location_text'] = trim($wm[3]);
                } else {
                    $core['occupation_title'] = $work;
                }
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
            $siblings[] = ['relation_type' => 'brother', 'name' => $value];
        }
        if (preg_match('/(?:बहीण|बहिण)\s*(?::\s*-\s*|[:\-]\s*)(.+)$/u', $line, $m)) {
            $value = trim($m[1]);
            if ($this->isNoSiblingValue($value)) {
                $core['sister_count'] = 0;

                return;
            }
            if ($this->isOneCountValue($value)) {
                $core['sister_count'] = 1;

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
        if (! preg_match('/^(?:घरचा\s+पत्ता|सध्याचा\s+पत्ता|गावचा\s+पत्ता|मुळगाव|मूळगाव)\s*(?::\s*-\s*|[:\-]\s*)(.+)$/u', $line, $m)) {
            return;
        }
        $address = preg_split('/\s+(?:मोबाईल|मोबाइल|संपर्क|प्रोपर्टी|प्रॉपर्टी|स्थावर|कौटुंबिक)\b/u', trim($m[1]), 2)[0] ?? trim($m[1]);
        $addresses[] = ['raw' => trim($address), 'address_line' => trim($address)];
    }

    private function extractPropertyLine(string $line, mixed &$propertySummary): void
    {
        if (! preg_match('/^(?:प्रोपर्टी|प्रॉपर्टी|स्थावर|शेती|प्लॉट|फ्लॅट|घर)\s*[:\-]?\s*(.*)$/u', $line, $m)) {
            return;
        }
        $text = trim($line);
        $propertySummary = [
            'summary_text' => trim(((string) ($propertySummary['summary_text'] ?? '')).' '.$text),
        ];
    }

    private function extractHoroscopeLine(string $line, mixed &$horoscope): void
    {
        if (! preg_match('/^(?:रास|राशी|नक्षत्र|देवक|कुलदैवत)\b/u', $line)) {
            return;
        }
        $horoscope = is_array($horoscope) ? $horoscope : ['raw' => []];
        $horoscope['raw'][] = $line;
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
        if (preg_match('/([0-9]+)\s*(?:फूट|feet|ft)\s*([0-9]+)?/ui', $v, $m)) {
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

    private function startsAddressPropertyOrContact(string $line): bool
    {
        return (bool) preg_match('/^(?:घरचा\s+पत्ता|सध्याचा\s+पत्ता|गावचा\s+पत्ता|मुळगाव|मूळगाव|मोबाईल|मोबाइल|संपर्क|Mobile|Phone|प्रोपर्टी|प्रॉपर्टी|स्थावर|शेती|प्लॉट|फ्लॅट|घर)\b/ui', $line);
    }

    private function startsPahune(string $line): bool
    {
        return (bool) preg_match('/^पाहुणे\b/u', $line);
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
