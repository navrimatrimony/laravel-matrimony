<?php

namespace App\Services\Parsing;

use App\Services\Ocr\OcrNormalize;

class IntakeNormalizedBiodataDraftBuilder
{
    private const LABEL_SUFFIX = '(?:[\s:：\-\.]|$)';

    private const OCR_DECORATIVE_LABELS = [
        'मुलाचे नांव', 'मुलाचे नाव', 'मुलीचे नांव', 'मुलीचे नाव', 'वधूचे नांव', 'वधूचे नाव',
        'नाव', 'जन्म तारीख', 'जन्मतारीख', 'जन्म वेळ व वार', 'जन्म वेळ आणि वार', 'जन्मवेळ व वार',
        'जन्मवेळ आणि वार', 'जन्म वेळ', 'जन्मवेळ', 'जन्म ठिकाण', 'जन्म स्थळ', 'जन्मठिकाण',
        'धर्म-जात', 'धर्म', 'जात', 'कास्ट', 'उपजात', 'उप जात', 'उंची', 'ऊंची', 'कुंची', 'वर्ण', 'रंग',
        'ब्लड ग्रुप', 'ब्लड ग्रप', 'रक्तगट', 'रक्त गट', 'शिक्षण', 'शिक्षण / नोकरी', 'नोकरी',
        'नोकरी/व्यवसाय', 'व्यवसाय', 'कंपनी', 'वार्षिक उत्पन्न', 'उत्पन्न', 'वडील', 'वडिलांचे नाव',
        'पित्याचे नाव', 'आई', 'आईचे नाव', 'मातेचे नाव', 'मूळ गाव', 'मुळगाव', 'मूळगाव',
        'निवास', 'सध्याचा पत्ता', 'निवासी पत्ता', 'पत्ता', 'पता', 'घरचा पत्ता', 'घराचा पत्ता',
        'गावचा पत्ता', 'मुळ गाव', 'मूळ गाव', 'आजोळचा पत्ता', 'अपेक्षा', 'देवक', 'नाडी', 'नाड',
        'जन्मरास', 'राशी', 'रास', 'नक्षत्र', 'गण', 'गोत्र', 'कुलदैवत', 'कुल दैवत', 'प्रॉपर्टी',
        'प्रॉपर्टी तपशील', 'प्रोपर्टी', 'स्थावर मालमत्ता', 'मालमत्ता', 'भ्रमणध्वनी', 'मोबाईल', 'मोबाइल',
        'मोबाईल नंबर', 'मोबाइल नंबर', 'मो. नं', 'मो. नं.', 'मो नं', 'मो नं.', 'संपर्क', 'फोन',
        'भाऊ', 'मुलाचा भाऊ', 'मुलाचे भाऊ', 'बहीण', 'बहिण', 'बहिणी', 'मुलाची बहीण', 'मुलाची बहिण',
        'दाजी', 'जावई', 'मामा', 'मुलाचे मामा', 'मावशी', 'माऊशी', 'आत्या',
        'मुलाची आत्या', 'मुलाची आत्त्या', 'चुलते', 'मुलाचे चुलते', 'नातेसंबंध', 'नाते संबंध', 'नातेवाईक', 'इतर नातेवाईक', 'उत्तर नातेवाईक', 'पाहुणे',
        'इतर पाहूणे', 'इतर पाहुणे', 'नावरस', 'नावरस नाव',
    ];

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
        $cleanedText = MarathiSplitLabelValueRejoiner::rejoin($cleanedText);
        $parsingText = $this->normalizeDecorativeLabelSeparatorsInText($cleanedText);
        $sections = $this->splitSections($parsingText);
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
            'source_lines' => $this->buildSourceLines($cleanedText),
            'extracted_facts' => [],
            'coverage_audit' => [],
            'review_flags' => [],
        ];
        $draft = app(IntakeHtmlTableHintApplier::class)->apply($draft);
        if (is_array($draft['normalized']['core'] ?? null)) {
            $this->syncParentContactAliases($draft['normalized']['core']);
            $this->dedupePreviewContactSlotsAgainstParents($draft['normalized']['core']);
        }
        $draft['source_lines'] = $this->finalizeSourceLines($draft);
        $draft['extracted_facts'] = app(IntakeWizardSourceFactExtractor::class)->extract($draft);
        $draft['coverage_audit'] = app(IntakeNormalizedDraftCoverageAuditor::class)->audit($draft);
        $draft['review_flags'] = $this->mergeReviewFlags(
            $this->buildReviewFlags($draft),
            is_array($draft['coverage_audit']['review_flags'] ?? null) ? $draft['coverage_audit']['review_flags'] : []
        );

        return $draft;
    }

    public function cleanText(string $rawText): string
    {
        $rawText = preg_replace('/^\x{FEFF}/u', '', $rawText) ?? $rawText;
        $rawText = str_replace(["\r\n", "\r"], "\n", $rawText);
        $lines = [];
        foreach (explode("\n", $rawText) as $line) {
            $line = trim($line);
            $line = trim(preg_replace('/^(?:[*•■□▪▫\-–—]\s*)+/u', '', $line) ?? $line);
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
     * @return list<array<string, mixed>>
     */
    private function buildSourceLines(string $cleanedText): array
    {
        $sourceLines = [];
        $lineNo = 1;
        foreach (explode("\n", $cleanedText) as $line) {
            $raw = trim($line);
            if ($raw === '') {
                continue;
            }
            $sourceLines[] = [
                'line_no' => $lineNo,
                'raw' => $raw,
                'normalized' => OcrNormalize::normalizeDigits($this->normalizeDecorativeLabelSeparators($raw)),
                'ignored' => false,
                'ignore_reason' => null,
            ];
            $lineNo++;
        }

        return $sourceLines;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return list<array<string, mixed>>
     */
    private function finalizeSourceLines(array $draft): array
    {
        $sourceLines = is_array($draft['source_lines'] ?? null) ? $draft['source_lines'] : [];
        foreach ($sourceLines as $index => $sourceLine) {
            if (! is_array($sourceLine)) {
                continue;
            }
            $raw = trim((string) ($sourceLine['raw'] ?? ''));
            $ignoreReason = null;
            if ($raw === '') {
                $ignoreReason = 'blank_line';
            } elseif ($this->isIgnorableReviewLine($raw)) {
                $ignoreReason = 'ignorable_heading_or_noise';
            } elseif ($this->isHardSectionBoundary($raw) && $this->extractPhones($raw) === []) {
                $ignoreReason = 'section_boundary';
            }
            $sourceLines[$index]['ignored'] = $ignoreReason !== null;
            $sourceLines[$index]['ignore_reason'] = $ignoreReason;
        }

        return $sourceLines;
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
                if ($detected === 'relatives') {
                    $relativesClosed = false;
                }
            }
            if ($current === 'relatives' && $relativesClosed) {
                continue;
            }
            $sections['__all']['lines'][] = $line;
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
        $parentsAddresses = [];
        $propertySummary = null;
        $propertyAssets = [];
        $horoscope = null;
        $preferences = [];

        $allLines = $this->allLines($sections);
        $candidateLines = array_merge(
            $sections['candidate']['lines'] ?? [],
            $sections['personal']['lines'] ?? []
        );

        $core['full_name'] = $this->extractCandidateName($candidateLines, $allLines);
        $core['gender'] = $this->inferGender($candidateLines, $core['full_name']);
        $this->extractCoreFields($allLines, $core);
        if (($core['gender'] ?? null) === null) {
            $core['gender'] = $this->inferGenderFromContext($allLines);
        }
        $this->extractStandaloneBasicFields($allLines, $core);
        $this->extractEnglishBasicFields($allLines, $core);
        $this->extractEducationCareer($allLines, $core);
        $this->extractParentFamilyDetailsFromOrderedLines(
            $allLines,
            array_merge($sections['family']['lines'] ?? [], $sections['addresses']['lines'] ?? []),
            $core,
            $parentsAddresses
        );
        $familyAddressLineSet = [];
        foreach (array_merge($sections['family']['lines'] ?? [], $sections['addresses']['lines'] ?? []) as $familyAddressLine) {
            $familyAddressLineSet[trim((string) $familyAddressLine)] = true;
        }
        $this->syncParentContactAliases($core);
        $parentPhones = $this->parentContactPhones($core);

        foreach ($allLines as $line) {
            if ($this->isParentContactLine($line)) {
                continue;
            }
            foreach ($this->extractPhones($line) as $phone) {
                if (isset($parentPhones[$phone])) {
                    continue;
                }
                $contacts[$phone] = ['phone_number' => $phone];
            }
        }
        $this->routeOrphanPhonesToPreviewContactSlots($core, array_keys($contacts));
        $this->syncParentContactAliases($core);
        $this->dedupePreviewContactSlotsAgainstParents($core);

        $previousLine = null;
        $propertyContinuationContext = null;
        foreach ($allLines as $line) {
            $this->extractSiblingCounts($line, $core, $siblings);
            $trimmedLine = trim($line);
            $familyParentAddressLine = isset($familyAddressLineSet[$trimmedLine]) && (bool) preg_match('/^प(?:त्)?ता'.self::LABEL_SUFFIX.'/u', $trimmedLine);
            if (! $familyParentAddressLine) {
                $this->extractAddressLine($line, $addresses, $previousLine);
                $this->extractStandaloneAddressLine($line, $addresses, $previousLine);
                $this->extractUnlabeledNativeAddressLine($line, $addresses);
            }
            $propertyContinuationContext = $this->extractPropertyLine($line, $propertySummary, $propertyAssets, $propertyContinuationContext);
            $this->extractHoroscopeLine($line, $horoscope);
            $this->extractPreferenceLine($line, $preferences);
            $previousLine = $line;
        }
        $this->appendSiblingContinuationRows($allLines, $siblings);
        $this->enrichSiblingRowsFromOrderedLines($allLines, $siblings);
        $this->attachJawaiRowsToMarriedSisters($allLines, $siblings);
        $siblings = $this->removeDuplicateAttachedSiblingSpouses($siblings);
        $siblings = $this->normalizeSiblingRowsForWizard($siblings);
        $this->extractEnglishAddresses($allLines, $addresses);
        $addresses = $this->removeParentAddressDuplicates($addresses, $parentsAddresses);
        $this->normalizeEmptyCoreStringsToNull($core);

        $lastRelativeLabel = null;
        $lastRelativeIndex = null;
        $lastRelativeGroupStartIndex = null;
        foreach ($this->expandEmbeddedRelativeLabels($sections['relatives']['lines'] ?? []) as $line) {
            if ($this->startsPahune($line) || $this->startsOtherRelativesLine($line)) {
                if ($this->startsPahune($line) || $this->startsOtherRelativesLine($line)) {
                    $core['other_relatives_text'] = $this->setTextOnce(
                        $core['other_relatives_text'] ?? null,
                        $this->cleanOtherRelativesText($this->stripOtherRelativesLabel($line))
                    );
                    $lastRelativeLabel = $this->otherRelativesLabelFromLine($line);
                    $lastRelativeIndex = null;
                    $lastRelativeGroupStartIndex = null;

                    continue;
                }
            }
            if ($this->isHardSectionBoundary($line)) {
                $lastRelativeLabel = null;
                $lastRelativeIndex = null;
                $lastRelativeGroupStartIndex = null;
                continue;
            }
            if ($this->isRelativeNoiseLine($line)) {
                continue;
            }
            if ($this->isIncompleteRelativeLabelFragment($line)) {
                continue;
            }
            if ($this->startsSiblingLine($line)) {
                $lastRelativeLabel = null;
                $lastRelativeIndex = null;
                $lastRelativeGroupStartIndex = null;

                continue;
            }
            if (preg_match('/^\s*(?:इतर\s+नातेवाईक|उत्तर\s+नातेवाईक|नातेवाईक|नातेसंबंध|नाते\s+संबंध|इतर\s+पाहूणे|इतर\s+पाहुणे|पाहुणे)\s*(?::\s*-\s*|[:\-–—]\s*)?(.*)$/u', $line, $otherMatch)) {
                $core['other_relatives_text'] = $this->setTextOnce(
                    $core['other_relatives_text'] ?? null,
                    $this->cleanOtherRelativesText((string) ($otherMatch[1] ?? ''))
                );
                $lastRelativeLabel = trim((string) preg_replace('/\s*(?::\s*-\s*|[:\-–—]).*$/u', '', $line));
                $lastRelativeIndex = null;
                $lastRelativeGroupStartIndex = null;

                continue;
            }
            if ($this->startsSisterSpouseLine($line)) {
                $lastRelativeLabel = null;
                $lastRelativeIndex = null;
                $lastRelativeGroupStartIndex = null;

                continue;
            }
            if (preg_match('/^\s*[-–—]?\s*(वडिलांचे\s+वडील|वडिलांची\s+आई|वडिलांची\s+बहीण|वडिलांची\s+बहिण|आजोबा|आजी|चुलते|काका|चुलती|काकू|आत्या|मुलाची\s+आत्या|मुलाची\s+आत्त्या|आत्याचे\s+यजमान|आत्यांचे\s+यजमान|आत्या\s+यजमान|चुलत\s+भाऊ|चुलत\s+बहिण|चुलत\s+बहीण|आईचे\s+वडील|आईची\s+आई|मुलाचे\s+मामा|मुलीचे\s+मामा|मामाचे\s+नाव|मामा|मामी|मावशी|मुलाची\s+मावशी|माऊशी|मावशीचे\s+यजमान|मावशीचा\s+नवरा|मावस\s+भाऊ|मावस\s+बहिण|मावस\s+बहीण|इतर\s+नातेवाईक|उत्तर\s+नातेवाईक|नातेवाईक|इतर\s+पाहूणे|इतर\s+पाहुणे|पाहुणे|आजोळ|नातेसंबंध|नाते\s+संबंध)\s*(?::\s*-\s*|[:\-–—]\s*)(.+)$/u', $line, $m)) {
                $name = trim($m[1]);
                $value = trim($m[2]);
                $lastRelativeLabel = $name;
                if ($value !== '') {
                    if ($this->isOtherRelativesLabel($name)) {
                        $core['other_relatives_text'] = $this->setTextOnce(
                            $core['other_relatives_text'] ?? null,
                            $this->cleanOtherRelativesText($value)
                        );
                        $lastRelativeIndex = null;
                        $lastRelativeGroupStartIndex = null;
                    } else {
                        $lastRelativeGroupStartIndex = count($relatives);
                        foreach ($this->splitRelativeValues($value) as $relativeValue) {
                            $relatives[] = $this->relativeRow($name, $relativeValue, $line);
                            $lastRelativeIndex = array_key_last($relatives);
                        }
                    }
                }

                continue;
            }
            if ($lastRelativeGroupStartIndex !== null && $lastRelativeLabel !== null && $this->isSharedRelativeAddressLine($line)) {
                $this->applySharedRelativeAddressLine($relatives, $lastRelativeGroupStartIndex, $line);

                continue;
            }
            if ($lastRelativeLabel !== null && $this->looksLikeAnyKnownLabel($line)) {
                $lastRelativeLabel = null;
                $lastRelativeIndex = null;
                $lastRelativeGroupStartIndex = null;

                continue;
            }
            if ($lastRelativeLabel !== null
                && $this->isOtherRelativesLabel($lastRelativeLabel)
                && $this->startsPreferenceLine($line)) {
                $lastRelativeLabel = null;
                $lastRelativeIndex = null;
                $lastRelativeGroupStartIndex = null;

                continue;
            }
            if ($lastRelativeIndex !== null && isset($relatives[$lastRelativeIndex])) {
                if ($this->mergeRelativeContinuationLine($relatives[$lastRelativeIndex], $line)) {
                    continue;
                }
            }
            if ($lastRelativeLabel !== null && $this->isOtherRelativesLabel($lastRelativeLabel)) {
                $cleanOther = $this->cleanOtherRelativesText(trim($line));
                if ($cleanOther !== '') {
                    $core['other_relatives_text'] = $this->setTextOnce(
                        $core['other_relatives_text'] ?? null,
                        $cleanOther
                    );
                }

                continue;
            }
            if ($lastRelativeLabel !== null && preg_match('/^\s*(?:[-–—]\s*)?(?::\s*-\s*)?(?:(?:\d+|[०-९]+)[\).]\s*|\(\s*(?:\d+|[०-९]+)\s*\)\s*)?(?:श्री\.?|सौ\.?|कै\.?|डॉ\.?|कु\.?)?\s*[\p{L}\p{M}.]+/u', $line)) {
                $line = trim(preg_replace('/^\s*(?:[-–—]\s*)?(?::\s*-\s*)?/u', '', $line) ?? $line);
                if ($this->isRelativeNoiseLine($line)) {
                    continue;
                }
                if ($this->isOtherRelativesLabel($lastRelativeLabel)) {
                    $core['other_relatives_text'] = $this->setTextOnce(
                        $core['other_relatives_text'] ?? null,
                        $this->cleanOtherRelativesText(trim($line))
                    );

                    continue;
                }
                if ($lastRelativeIndex !== null && isset($relatives[$lastRelativeIndex])) {
                    if ($this->mergeRelativeContinuationLine($relatives[$lastRelativeIndex], $line)) {
                        continue;
                    }
                }
                foreach ($this->splitRelativeValues($line) as $relativeValue) {
                    $relatives[] = $this->relativeRow($lastRelativeLabel, $relativeValue, $line);
                    $lastRelativeIndex = array_key_last($relatives);
                }

                continue;
            }
            if (preg_match('/^(?:श्री\.|कै\.|डॉ\.|श्री\s)/u', $line)) {
                $relatives[] = ['name' => trim($line), 'raw' => $line];
            }
        }
        $this->attachRelativeAddressContinuationsFromOrderedLines($allLines, $relatives);

        return [
            'core' => $core,
            'contacts' => array_values($contacts),
            'siblings' => $this->dedupeSiblingRows(array_values($siblings)),
            'relatives' => $this->dedupeRows($relatives, ['relation_type', 'name', 'phone_number']),
            'addresses' => $addresses,
            'parents_addresses' => $parentsAddresses,
            'property_summary' => $propertySummary,
            'property_assets' => $this->dedupeRows($propertyAssets, ['asset_type_key', 'location', 'ownership_type_key', 'notes']),
            'horoscope' => $horoscope,
            'preferences' => $preferences,
        ];
    }

    /**
     * @param  list<string>  $lines
     * @param  list<array<string, mixed>>  $relatives
     */
    private function attachRelativeAddressContinuationsFromOrderedLines(array $lines, array &$relatives): void
    {
        $cursor = -1;
        $currentRelativeIndex = null;
        $withinRelativeGroup = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            if (preg_match('/^\s*[-–—]?\s*मुलाची\s+आत्त?्या\s*(?::\s*-\s*|[:\-–—]\s*)(.+)$/u', $trimmed, $m)) {
                $cursor++;
                $currentRelativeIndex = $cursor;
                $withinRelativeGroup = true;

                continue;
            }

            if (preg_match('/^\s*[-–—]?\s*(वडिलांचे\s+वडील|वडिलांची\s+आई|वडिलांची\s+बहीण|वडिलांची\s+बहिण|आजोबा|आजी|चुलते|काका|चुलती|काकू|आत्या|मुलाची\s+आत्या|मुलाची\s+आत्त्या|आत्याचे\s+यजमान|आत्यांचे\s+यजमान|आत्या\s+यजमान|चुलत\s+भाऊ|चुलत\s+बहिण|चुलत\s+बहीण|आईचे\s+वडील|आईची\s+आई|मुलाचे\s+मामा|मुलीचे\s+मामा|मामाचे\s+नाव|मामा|मामी|मावशी|मुलाची\s+मावशी|माऊशी|मावशीचे\s+यजमान|मावशीचा\s+नवरा|मावस\s+भाऊ|मावस\s+बहिण|मावस\s+बहीण)\s*(?::\s*-\s*|[:\-–—]\s*)(.+)$/u', $trimmed, $m)) {
                foreach ($this->splitRelativeValues(trim((string) $m[2])) as $_) {
                    $cursor++;
                    $currentRelativeIndex = $cursor;
                }
                $withinRelativeGroup = true;

                continue;
            }

            if ($withinRelativeGroup
                && $currentRelativeIndex !== null
                && ! $this->startsAddressLine($trimmed)
                && ! $this->looksLikeAnyKnownLabel($trimmed)
                && ! $this->startsSiblingLine($trimmed)
                && ! $this->startsSisterSpouseLine($trimmed)
                && ! $this->startsPahune($trimmed)
                && ! $this->startsOtherRelativesLine($trimmed)
                && preg_match('/^\s*(?:[-–—]\s*)?(?:(?:\d+|[०-९]+)[\).]\s*)?(?:श्री\.?|सौ\.?|कै\.?|डॉ\.?|कु\.?)?\s*[\p{L}\p{M}]/u', $trimmed)) {
                $cursor++;
                $currentRelativeIndex = $cursor;

                continue;
            }

            if ($currentRelativeIndex === null || ! isset($relatives[$currentRelativeIndex])) {
                continue;
            }

            if ($this->startsAddressLine($trimmed)) {
                $address = $this->labeledAddressValue($trimmed);
                if ($address !== '') {
                    $relatives[$currentRelativeIndex]['address_line'] = $this->setTextOnce($relatives[$currentRelativeIndex]['address_line'] ?? null, $address);
                }

                continue;
            }

            if ($this->looksLikeAnyKnownLabel($trimmed)
                || $this->startsSiblingLine($trimmed)
                || $this->startsSisterSpouseLine($trimmed)
                || $this->startsPahune($trimmed)
                || $this->startsOtherRelativesLine($trimmed)) {
                $currentRelativeIndex = null;
                $withinRelativeGroup = false;
            }
        }
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
        if (($core['full_name'] ?? null) !== null && $this->looksLikeAddressText((string) $core['full_name'])) {
            $flags[] = ['field' => 'core.full_name', 'reason' => 'full_name_looks_like_address', 'raw' => (string) $core['full_name'], 'suggested_section' => 'basic-info'];
        }
        if ($this->candidateNameFromFallback && ($core['full_name'] ?? null) !== null && ! $this->fullNameCameFromTableHint($draft, (string) $core['full_name'])) {
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
     * @param  array<string, mixed>  $draft
     * @return list<array<string, mixed>>
     */
    private function buildExtractedFacts(array $draft): array
    {
        $normalized = is_array($draft['normalized'] ?? null) ? $draft['normalized'] : [];
        $core = is_array($normalized['core'] ?? null) ? $normalized['core'] : [];
        $facts = [];

        foreach ([
            ['field_value', 'basic-info', 'core.full_name', $core['full_name'] ?? null],
            ['field_value', 'basic-info', 'core.gender', $core['gender'] ?? null],
            ['field_value', 'basic-info', 'core.date_of_birth', $core['date_of_birth'] ?? null],
            ['field_value', 'basic-info', 'core.birth_time', $core['birth_time'] ?? null],
            ['field_value', 'basic-info', 'core.birth_place_text', $core['birth_place_text'] ?? null],
            ['field_value', 'basic-info', 'core.religion', $core['religion'] ?? null],
            ['field_value', 'basic-info', 'core.caste', $core['caste'] ?? null],
            ['field_value', 'basic-info', 'core.sub_caste', $core['sub_caste'] ?? null],
            ['field_value', 'basic-info', 'core.marital_status', $core['marital_status'] ?? null],
            ['phone_number', 'basic-info', 'core.primary_contact_number', $core['primary_contact_number'] ?? null],
            ['phone_number', 'basic-info', 'core.primary_contact_number_2', $core['primary_contact_number_2'] ?? null],
            ['phone_number', 'basic-info', 'core.primary_contact_number_3', $core['primary_contact_number_3'] ?? null],
            ['field_value', 'physical', 'core.height_cm', $core['height_cm'] ?? null],
            ['field_value', 'physical', 'core.complexion', $core['complexion'] ?? null],
            ['field_value', 'physical', 'core.blood_group', $core['blood_group'] ?? null],
            ['field_value', 'education-career', 'core.highest_education', $core['highest_education'] ?? null],
            ['field_value', 'education-career', 'core.occupation_title', $core['occupation_title'] ?? null],
            ['field_value', 'education-career', 'core.company_name', $core['company_name'] ?? null],
            ['field_value', 'education-career', 'core.annual_income', $core['annual_income'] ?? null],
            ['field_value', 'education-career', 'core.work_location_text', $core['work_location_text'] ?? null],
            ['field_value', 'family-details', 'core.father_name', $core['father_name'] ?? null],
            ['field_value', 'family-details', 'core.father_occupation', $core['father_occupation'] ?? null],
            ['phone_number', 'family-details', 'core.father_contact_1', $core['father_contact_1'] ?? null],
            ['phone_number', 'family-details', 'core.father_contact_2', $core['father_contact_2'] ?? null],
            ['phone_number', 'family-details', 'core.father_contact_3', $core['father_contact_3'] ?? null],
            ['field_value', 'family-details', 'core.mother_name', $core['mother_name'] ?? null],
            ['field_value', 'family-details', 'core.mother_occupation', $core['mother_occupation'] ?? null],
            ['phone_number', 'family-details', 'core.mother_contact_1', $core['mother_contact_1'] ?? null],
            ['phone_number', 'family-details', 'core.mother_contact_2', $core['mother_contact_2'] ?? null],
            ['phone_number', 'family-details', 'core.mother_contact_3', $core['mother_contact_3'] ?? null],
            ['field_value', 'alliance', 'core.other_relatives_text', $core['other_relatives_text'] ?? null],
        ] as [$factType, $section, $field, $value]) {
            $fact = $this->makeFact($draft, (string) $factType, $value, (string) $section, (string) $field);
            if ($fact !== null) {
                $facts[] = $fact;
            }
        }

        foreach (($normalized['contacts'] ?? []) as $index => $contact) {
            if (! is_array($contact)) {
                continue;
            }
            $fact = $this->makeFact(
                $draft,
                'phone_number',
                $contact['phone_number'] ?? null,
                'basic-info',
                'contacts.'.($index + 1).'.phone_number'
            );
            if ($fact !== null) {
                $facts[] = $fact;
            }
        }

        foreach (($normalized['addresses'] ?? []) as $index => $address) {
            if (! is_array($address)) {
                continue;
            }
            $fact = $this->makeFact(
                $draft,
                'address_line',
                $address['address_line'] ?? $address['raw'] ?? null,
                'basic-info',
                'addresses.'.($index + 1).'.address_line'
            );
            if ($fact !== null) {
                $facts[] = $fact;
            }
        }

        foreach (($normalized['parents_addresses'] ?? []) as $index => $address) {
            if (! is_array($address)) {
                continue;
            }
            $fact = $this->makeFact(
                $draft,
                'address_line',
                $address['address_line'] ?? $address['raw'] ?? null,
                'family-details',
                'parents_addresses.'.($index + 1).'.address_line'
            );
            if ($fact !== null) {
                $facts[] = $fact;
            }
        }

        foreach (($normalized['siblings'] ?? []) as $index => $sibling) {
            if (! is_array($sibling)) {
                continue;
            }
            foreach ([
                'name' => 'sibling_name',
                'occupation' => 'sibling_occupation',
                'contact_number' => 'phone_number',
                'contact_number_2' => 'phone_number',
                'contact_number_3' => 'phone_number',
                'address_line' => 'address_line',
                'notes' => 'text_detail',
            ] as $field => $factType) {
                $fact = $this->makeFact($draft, $factType, $sibling[$field] ?? null, 'siblings', 'siblings.'.($index + 1).'.'.$field);
                if ($fact !== null) {
                    $facts[] = $fact;
                }
            }
            $spouse = is_array($sibling['spouse'] ?? null) ? $sibling['spouse'] : [];
            foreach ([
                'name' => 'sibling_spouse_name',
                'occupation' => 'sibling_spouse_occupation',
                'occupation_title' => 'sibling_spouse_occupation',
                'contact_number' => 'phone_number',
                'contact_number_2' => 'phone_number',
                'contact_number_3' => 'phone_number',
                'address_line' => 'address_line',
                'additional_info' => 'text_detail',
                'notes' => 'text_detail',
            ] as $field => $factType) {
                $fact = $this->makeFact($draft, $factType, $spouse[$field] ?? null, 'siblings', 'siblings.'.($index + 1).'.spouse.'.$field);
                if ($fact !== null) {
                    $facts[] = $fact;
                }
            }
        }

        foreach (($normalized['relatives'] ?? []) as $index => $relative) {
            if (! is_array($relative)) {
                continue;
            }
            $section = $this->relativeTargetSection($relative);
            foreach ([
                'name' => 'relative_name',
                'occupation' => 'relative_occupation',
                'contact_number' => 'phone_number',
                'address_line' => 'address_line',
                'notes' => 'text_detail',
            ] as $field => $factType) {
                $fact = $this->makeFact($draft, $factType, $relative[$field] ?? null, $section, 'relatives.'.($index + 1).'.'.$field);
                if ($fact !== null) {
                    $facts[] = $fact;
                }
            }
        }

        $horoscope = is_array($normalized['horoscope'] ?? null) ? $normalized['horoscope'] : [];
        foreach ($horoscope as $field => $value) {
            if ($field === 'raw') {
                continue;
            }
            $fact = $this->makeFact($draft, 'horoscope_value', $value, 'horoscope', 'horoscope.'.$field);
            if ($fact !== null) {
                $facts[] = $fact;
            }
        }

        foreach ((array) ($normalized['preferences'] ?? []) as $field => $value) {
            $fact = $this->makeFact($draft, 'preference_text', $value, 'about-preferences', 'preferences.'.$field);
            if ($fact !== null) {
                $facts[] = $fact;
            }
        }

        return $this->mergeExtractedFacts($facts, $this->sourceOnlyPhoneFacts($draft, $facts));
    }

    private function factTypeForTargetField(string $factType, string $targetField): string
    {
        if ($factType !== 'field_value') {
            return $factType;
        }

        return match (true) {
            preg_match('/(?:^|\.)(?:full_name|father_name|mother_name|name)$/', $targetField) === 1 => 'person_name',
            str_contains($targetField, 'expectations') => 'preference_text',
            default => 'field_value',
        };
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  list<array<string, mixed>>  $existingFacts
     * @return list<array<string, mixed>>
     */
    private function sourceOnlyPhoneFacts(array $draft, array $existingFacts): array
    {
        $facts = [];
        $knownPhones = [];
        foreach ($existingFacts as $fact) {
            if (! is_array($fact) || ($fact['fact_type'] ?? null) !== 'phone_number') {
                continue;
            }
            $phone = $this->canonicalPhone((string) ($fact['value'] ?? ''));
            if ($phone !== '') {
                $knownPhones[$phone] = true;
            }
        }

        foreach (($draft['source_lines'] ?? []) as $sourceLine) {
            if (! is_array($sourceLine) || ! empty($sourceLine['ignored'])) {
                continue;
            }
            $raw = trim((string) ($sourceLine['raw'] ?? ''));
            foreach ($this->extractPhones($raw) as $phone) {
                $canonical = $this->canonicalPhone($phone);
                if ($canonical === '' || isset($knownPhones[$canonical])) {
                    continue;
                }
                $facts[] = [
                    'fact_type' => 'phone_number',
                    'value' => $phone,
                    'source_line_no' => $sourceLine['line_no'] ?? null,
                    'source_text' => $raw,
                    'target_section' => $this->guessPhoneTargetSection($raw),
                    'target_field' => $this->guessPhoneTargetField($raw),
                ];
            }
        }

        return $facts;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>|null
     */
    private function makeFact(array $draft, string $factType, mixed $value, string $targetSection, string $targetField): ?array
    {
        $text = $this->stringifyExtractedFactValue($value);
        if ($text === '') {
            return null;
        }
        $factType = $this->factTypeForTargetField($factType, $targetField);
        $source = $this->findSourceLineForFact($draft, $text, $targetField);

        return [
            'fact_type' => $factType,
            'value' => $text,
            'source_line_no' => $source['line_no'] ?? null,
            'source_text' => $source['raw'] ?? '',
            'target_section' => $targetSection,
            'target_field' => $targetField,
        ];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>|null
     */
    private function findSourceLineForFact(array $draft, string $value, string $targetField): ?array
    {
        $needles = array_filter([$value, $this->canonicalPhone($value)]);
        foreach (($draft['source_lines'] ?? []) as $sourceLine) {
            if (! is_array($sourceLine)) {
                continue;
            }
            $raw = (string) ($sourceLine['raw'] ?? '');
            $normalized = (string) ($sourceLine['normalized'] ?? '');
            foreach ($needles as $needle) {
                if ($needle === '') {
                    continue;
                }
                if (str_contains($raw, $needle) || str_contains($normalized, $needle)) {
                    return $sourceLine;
                }
            }
        }

        $fieldHint = preg_replace('/^[^.]+\./', '', $targetField) ?? $targetField;
        foreach (($draft['source_lines'] ?? []) as $sourceLine) {
            if (! is_array($sourceLine)) {
                continue;
            }
            $raw = (string) ($sourceLine['raw'] ?? '');
            if ($fieldHint !== '' && preg_match('/'.preg_quote(str_replace('_', ' ', $fieldHint), '/').'/iu', $raw)) {
                return $sourceLine;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $facts
     * @return list<array<string, mixed>>
     */
    private function mergeExtractedFacts(array $facts, array $extraFacts): array
    {
        $merged = [];
        $seen = [];
        foreach (array_merge($facts, $extraFacts) as $fact) {
            if (! is_array($fact)) {
                continue;
            }
            $identity = implode('|', [
                trim((string) ($fact['fact_type'] ?? '')),
                mb_strtolower(trim((string) ($fact['value'] ?? ''))),
                trim((string) ($fact['target_field'] ?? '')),
            ]);
            if ($identity === '||' || isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $merged[] = $fact;
        }

        return $merged;
    }

    /**
     * @param  list<array<string, mixed>>  $baseFlags
     * @param  list<array<string, mixed>>  $extraFlags
     * @return list<array<string, mixed>>
     */
    private function mergeReviewFlags(array $baseFlags, array $extraFlags): array
    {
        $flags = [];
        $seen = [];
        foreach (array_merge($baseFlags, $extraFlags) as $flag) {
            if (! is_array($flag)) {
                continue;
            }
            $identity = implode('|', [
                trim((string) ($flag['field'] ?? '')),
                trim((string) ($flag['reason'] ?? '')),
                trim((string) ($flag['raw'] ?? '')),
                trim((string) ($flag['source_line_no'] ?? '')),
            ]);
            if ($identity === '|||' || isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
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
            'physical_build' => null,
            'spectacles_lens' => null,
            'physical_condition' => null,
            'diet' => null,
            'smoking' => null,
            'drinking' => null,
            'marital_status' => null,
            'father_name' => null,
            'father_occupation' => null,
            'father_contact_number' => null,
            'father_contact_1' => null,
            'father_contact_2' => null,
            'father_contact_3' => null,
            'primary_contact_number_2' => null,
            'primary_contact_number_3' => null,
            'mother_name' => null,
            'mother_occupation' => null,
            'mother_contact_number' => null,
            'mother_contact_1' => null,
            'mother_contact_2' => null,
            'mother_contact_3' => null,
            'brother_count' => null,
            'sister_count' => null,
            'family_type' => null,
            'family_type_id' => null,
            'family_status' => null,
            'family_values' => null,
            'family_income' => null,
            'highest_education' => null,
            'occupation_title' => null,
            'company_name' => null,
            'annual_income' => null,
            'salary_package_text' => null,
            'work_location_text' => null,
        ];
    }

    private function detectSection(string $line, string $current, bool $relativesClosed = false): ?string
    {
        $normalizedLine = $this->stripMarkdownHeadingDecorators($line);
        if ($this->startsAddressLine($line)) {
            if ($current === 'relatives' && ! $relativesClosed) {
                return 'relatives';
            }

            return 'addresses';
        }
        if ($this->startsContactLine($line)) {
            return 'contacts';
        }
        if ($this->startsPropertyLine($line)) {
            return 'property';
        }
        if ($this->startsPahune($line)) {
            return 'relatives';
        }
        if (preg_match('/^(?:वैयक्तिक\s+माहिती|वैयक्तिक\s+तपशील)/u', $normalizedLine)) {
            return 'personal';
        }
        if (preg_match('/^(?:कौटुंबिक\s+माहिती|कौटुंबिक\s+तपशील)/u', $normalizedLine)) {
            return 'family';
        }
        if (preg_match('/^(?:रास|राशी|जन्मरास|नक्षत्र|जन्मनक्षत्र|देवक|कुल\s*दैवत|कुलदैवत|कुलदेवत|कुलस्वामी|कुळस्वामी|नाड|नाडी|गण|चरण|गोत्र|योनी|वर्ण|योग|नावरस|नावरस\s*नाव|जन्मवार\s*आणि\s*वेळ|जन्मवार\s*व\s*वेळ)'.self::LABEL_SUFFIX.'/u', $normalizedLine)) {
            return 'horoscope';
        }
        if (preg_match('/^(?:शिक्षण|नोकरी|व्यवसाय|वेतन|उत्पन्न|नोकरी\/व्यवसाय)'.self::LABEL_SUFFIX.'/u', $normalizedLine)) {
            return 'education_career';
        }
        if (preg_match('/^\s*[-–—]?\s*(?:वडिलांचे\s+वडील|वडिलांची\s+आई|वडिलांची\s+बहीण|वडिलांची\s+बहिण|आजोबा|आजी|चुलते|काका|चुलती|काकू|आत्या|मुलाची\s+आत्या|मुलाची\s+आत्त्या|आत्याचे\s+यजमान|आत्यांचे\s+यजमान|आत्या\s+यजमान|चुलत\s+भाऊ|चुलत\s+बहिण|चुलत\s+बहीण|आईचे\s+वडील|आईची\s+आई|मुलाचे\s+मामा|मुलीचे\s+मामा|मामाचे\s+नाव|मामा|मामी|मावशी|माऊशी|मुलाची\s+मावशी|मावशीचे\s+यजमान|मावशीचा\s+नवरा|मावस\s+भाऊ|मावस\s+बहिण|मावस\s+बहीण|इतर\s+नातेवाईक|उत्तर\s+नातेवाईक|नातेवाईक|इतर\s+पाहूणे|इतर\s+पाहुणे|पाहुणे|आजोळ|नातेसंबंध|नाते\s+संबंध)'.self::LABEL_SUFFIX.'/u', $normalizedLine)) {
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
        if (is_array($sections['__all']['lines'] ?? null)) {
            return array_values(array_filter(
                $sections['__all']['lines'],
                static fn ($line): bool => is_string($line)
            ));
        }

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
            $normalizedLine = $this->stripMarkdownHeadingDecorators($line);
            if (preg_match('/^(?:मुलाचे\s+नां?व|मुलीचे\s+नां?व|वधूचे\s+नां?व|नां?व)\s*(?::\s*-\s*|[:\-]\s*)(.+)$/u', $normalizedLine, $m)) {
                $candidate = $this->cleanPersonName($m[1]);
                if ($candidate !== '' && ! $this->looksLikeAddressText($candidate)) {
                    return $candidate;
                }
            }
        }

        $parentRelativeNames = $this->extractParentRelativeNames($allLines);
        $hasBiodataContext = $this->hasBiodataCandidateContext($allLines);
        $englishStackedName = $this->extractEnglishStackedName($allLines);
        if ($englishStackedName !== null) {
            return $englishStackedName;
        }

        foreach ($allLines as $index => $line) {
            if (preg_match('/^मुलाची\s+माहिती$/u', $this->stripMarkdownHeadingDecorators($line))) {
                $headingName = $this->extractNameAfterCandidateInfoHeading($allLines, $index);
                if ($headingName !== null) {
                    return $headingName;
                }
            }
            if (preg_match('/^#{1,6}\s*(.+)$/u', trim($line))
                && preg_match('/^(.+)$/u', $this->stripMarkdownHeadingDecorators($line), $m)) {
                $headingName = $this->cleanPersonName($m[1]);
                if ($this->isPlausibleCandidateName($headingName)
                    && ! $this->isSuspiciousHeadingAsName($headingName)
                    && ! $this->looksLikeAddressText($headingName)
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
            $t = $this->stripMarkdownHeadingDecorators($line);
            if (! $this->isPlausibleCandidateName($t) || $this->looksLikeAddressText($t) || preg_match('/बायोडाटा|वैयक्तिक|कौटुंबिक|श्री\s+साई|गणेश|प्रसन्न/u', $t)) {
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
     * @param  list<string>  $allLines
     */
    private function extractNameAfterCandidateInfoHeading(array $allLines, int $headingIndex): ?string
    {
        for ($i = $headingIndex + 1; $i < min(count($allLines), $headingIndex + 8); $i++) {
            $line = trim($allLines[$i]);
            if ($line === '' || preg_match('/^(?:\/\/|॥|!!|श्री\s)/u', $line)) {
                continue;
            }
            $name = $this->cleanPersonName($line);
            if ($this->isPlausibleCandidateName($name)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $allLines
     */
    private function extractEnglishStackedName(array $allLines): ?string
    {
        foreach ($allLines as $index => $line) {
            if (! preg_match('/^#{1,6}\s*Personal\s+Details$/iu', trim($line))) {
                continue;
            }
            $parts = [];
            for ($i = $index - 1; $i >= 0 && $i >= $index - 5; $i--) {
                $candidate = trim($allLines[$i]);
                if ($candidate === '' || str_starts_with($candidate, '*') || str_starts_with($candidate, '#')) {
                    continue;
                }
                if (! preg_match('/^[A-Z][A-Z.\'\-]+(?:\s+[A-Z][A-Z.\'\-]+)*$/', $candidate)) {
                    break;
                }
                array_unshift($parts, $candidate);
            }
            $name = trim(implode(' ', $parts));
            if (preg_match('/^[A-Z][A-Z.\'\-]+(?:\s+[A-Z][A-Z.\'\-]+){1,5}$/', $name)) {
                return $name;
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
            if (preg_match('/^(?:मुलीचे\s+नां?व|वधूचे\s+नां?व)/u', $line)) {
                return $this->candidateHonorificConflictsWithFemale($fullName ?? $line) ? null : 'female';
            }
        }
        if ($fullName !== null && preg_match('/^चि\./u', $fullName)) {
            return 'male';
        }

        return null;
    }

    /**
     * @param  list<string>  $lines
     */
    private function inferGenderFromContext(array $lines): ?string
    {
        $blob = implode("\n", $lines);
        $hasFemaleSignal = preg_match('/(?:महिलेचे|महिला|स्त्री)/u', $blob) === 1;
        $hasMaleSignal = preg_match('/(?:पुरुषाचे|पुरुष)/u', $blob) === 1;

        if ($hasFemaleSignal && ! $hasMaleSignal) {
            return 'female';
        }
        if ($hasMaleSignal && ! $hasFemaleSignal) {
            return 'male';
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
            if (($dobValue = $this->extractLabeledValue($line, ['जन्म तारीख', 'जन्मतारीख', 'जन्म दि', 'जन्म दिनांक'])) !== null) {
                [$dateOfBirth, $birthTime] = $this->splitDateOfBirthAndTime($dobValue);
                $core['date_of_birth'] = $this->normalizeDateValue($dateOfBirth);
                if ($core['birth_time'] === null && $birthTime !== null) {
                    $core['birth_time'] = $birthTime;
                }
            }
            if (($birthTimeValue = $this->extractLabeledValue($line, ['जन्म वेळ व वार', 'जन्म वेळ आणि वार', 'जन्मवेळ व वार', 'जन्मवेळ आणि वार', 'जन्म वेळ', 'जन्मवेळ'])) !== null) {
                $core['birth_time'] = $birthTimeValue;
            }
            if ($core['birth_time'] === null
                && preg_match('/^(?:वार|जन्म\s*वार\s*व\s*वेळ|जन्मवार\s*व\s*वेळ|जन्मवार\s*आणि\s*वेळ)\s*(?::\s*-\s*|[:\-]\s*)(.+)$/u', $line, $m)
                && preg_match('/\d{1,2}(?:[.:]\d{1,2})?\s*(?:A\.?M\.?|P\.?M\.?|am|pm)?|सकाळी|दुपारी|सायंकाळी|रात्री/ui', OcrNormalize::normalizeDigits($m[1]))) {
                $core['birth_time'] = trim($m[1]);
            }
            if (($birthPlace = $this->extractLabeledValue($line, ['जन्म ठिकाण', 'जन्म स्थळ', 'जन्मठिकाण'])) !== null) {
                $core['birth_place_text'] = $birthPlace;
            }
            if (($religion = $this->extractLabeledValue($line, ['धर्म', 'religion'])) !== null) {
                $core['religion'] = $religion;
                if (($core['caste'] ?? null) === null || ($core['sub_caste'] ?? null) === null) {
                    $this->normalizeCasteLine($religion, $core);
                }
            }
            if (($casteLine = $this->extractLabeledValue($line, ['जात', 'कास्ट'])) !== null) {
                $this->normalizeCasteLine($casteLine, $core);
            }
            $motherLine = $this->extractLabeledValue($line, ['आईचे नाव', 'मातेचे नाव', 'आई']);
            if ($motherLine !== null) {
                [$core['mother_name'], $core['mother_occupation'], $core['mother_contact_number']] = $this->splitNameOccupation($motherLine);
            }
            $fatherLine = $this->extractLabeledValue($line, ['पित्याचे नाव', 'वडिलांचे नाव', 'वडीलांचे नाव', 'वकिलांचे नाव', 'वडील']);
            if ($fatherLine !== null) {
                [$core['father_name'], $core['father_occupation'], $core['father_contact_number']] = $this->splitNameOccupation($fatherLine);
            }
            if (($core['height_cm'] ?? null) === null
                && ! $this->startsPreferenceLine($line)
                && ($height = $this->extractLabeledValue($line, ['उंची', 'ऊंची', 'कुंची'])) !== null) {
                $core['height_cm'] = $this->parseHeightCm($height);
            }
            if (($complexionValue = $this->extractLabeledValue($line, ['वर्ण', 'रंग', 'complexion'])) !== null) {
                $complexion = $this->cleanComplexionValue($complexionValue);
                if ($this->looksLikeComplexion($complexion)) {
                    $core['complexion'] = $complexion;
                }
            }
            if (($bloodGroup = $this->extractLabeledValue($line, ['ब्लड ग्रुप', 'ब्लड ग्रप', 'रक्तगट', 'रक्त गट', 'blood group'])) !== null) {
                $core['blood_group'] = $this->cleanBloodGroupValue($bloodGroup);
            }
            if (preg_match('/(?:आहार|diet)\s*(?::\s*-\s*|[:\-]\s*)(.+)$/ui', $line, $m)) {
                $core['diet'] = trim($m[1]);
            }
            if (preg_match('/(?:धूम्रपान|smoking)\s*(?::\s*-\s*|[:\-]\s*)(.+)$/ui', $line, $m)) {
                $core['smoking'] = trim($m[1]);
            }
            if (preg_match('/(?:मद्यपान|drinking)\s*(?::\s*-\s*|[:\-]\s*)(.+)$/ui', $line, $m)) {
                $core['drinking'] = trim($m[1]);
            }
            if (preg_match('/(?:वैवाहिक\s*स्थिती|वैवाहिक|marital)\s*(?::\s*-\s*|[:\-]\s*)(.+)$/ui', $line, $m)) {
                $core['marital_status'] = trim($m[1]);
            }
            if (preg_match('/(?:कुटुंब\s+प्रकार|family\s+type)\s*(?::\s*-\s*|[:\-]\s*)(.+)$/ui', $line, $m)) {
                $core['family_type'] = trim($m[1]);
            }
            if (preg_match('/(?:कुटुंब\s+स्थिती|family\s+status)\s*(?::\s*-\s*|[:\-]\s*)(.+)$/ui', $line, $m)) {
                $core['family_status'] = trim($m[1]);
            }
            if (preg_match('/(?:कुटुंब\s+मूल्ये|family\s+values)\s*(?::\s*-\s*|[:\-]\s*)(.+)$/ui', $line, $m)) {
                $core['family_values'] = trim($m[1]);
            }
            if (preg_match('/(?:कुटुंब\s+उत्पन्न|family\s+income)\s*(?::\s*-\s*|[:\-]\s*)(.+)$/ui', $line, $m)) {
                $core['family_income'] = trim($m[1]);
            }
            if (! preg_match('/कुटुंब/u', $normalizedLine) && preg_match('/(?:package|पॅकेज)\s*[:=\-]?\s*([0-9]+(?:\.[0-9]+)?)\s*(?:LPA|LAC|लाख)/ui', $normalizedLine, $m)) {
                $core['salary_package_text'] = trim($m[0]);
            } elseif ($core['annual_income'] === null
                && ! preg_match('/कुटुंब/u', $normalizedLine)
                && preg_match('/([0-9]+(?:\.[0-9]+)?)\s*(?:LAC|लाख)/ui', $normalizedLine, $m)) {
                $core['annual_income'] = (int) round(((float) $m[1]) * 100000);
            } elseif ($core['annual_income'] === null
                && preg_match('/(?:पगार|वेतन|उत्पन्न|salary)\s*(?::\s*-\s*|[:\-]\s*)(.+)$/ui', $normalizedLine, $m)) {
                $this->extractAmountFromIncomeText($m[1], $core);
            } elseif (($core['annual_income'] ?? null) === null
                && ($core['salary_package_text'] ?? null) === null
                && $this->looksLikeStandaloneIncomeLine($normalizedLine)) {
                $this->extractAmountFromIncomeText($normalizedLine, $core);
            }
        }
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, mixed>  $core
     */
    private function extractStandaloneBasicFields(array $lines, array &$core): void
    {
        foreach ($lines as $line) {
            $trimmed = trim($line);
            $normalized = OcrNormalize::normalizeDigits($trimmed);
            if (($core['date_of_birth'] ?? null) === null
                && preg_match('/^([0-9]{1,2}\s*\/\s*[0-9]{1,2}\s*\/\s*[0-9]{2,4})(?:\s+जन्म\s*वेळ\s*(?::\s*-\s*|[:\-]\s*)(.+))?$/u', $normalized, $m)) {
                $core['date_of_birth'] = $this->normalizeDateValue(trim($m[1]));
                if (($core['birth_time'] ?? null) === null && ! empty($m[2])) {
                    $core['birth_time'] = trim($m[2]);
                }
            }
            if (($core['religion'] ?? null) === null && ($core['caste'] ?? null) === null
                && preg_match('/हिंद[ुू]\s*[-–—]?\s*मराठा/u', $trimmed)) {
                $this->normalizeCasteLine($trimmed, $core);
            }
            if (($core['height_cm'] ?? null) === null && preg_match('/^\s*([0-9]{1,2})\s*[.\']\s*([0-9]{1,2})\s*(?:इंच|inch|in)?\b/u', $normalized, $m)) {
                $core['height_cm'] = $this->parseHeightCm($m[1]."'".$m[2]);
            }
            if (($core['blood_group'] ?? null) === null && preg_match('/^\s*((?:A|B|AB|O)\s*[+-](?:ve)?)(?=\s|$)/ui', $normalized, $m)) {
                $core['blood_group'] = $this->cleanBloodGroupValue($m[1]);
            }
        }
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function splitDateOfBirthAndTime(string $value): array
    {
        $raw = trim((string) preg_replace('/^\s*[8८]\s*/u', '', $value));
        $normalized = OcrNormalize::normalizeDigits($raw);
        if (preg_match('/^(.+?)\s+जन्म\s*वेळ\s*(?::\s*-\s*|[:\-]\s*)(.+)$/u', $raw, $m)) {
            return [$this->normalizeDateValue(trim($m[1])), trim($m[2]) !== '' ? trim($m[2]) : null];
        }

        if (preg_match('/^([0-9]{1,2}\s*\/\s*[0-9]{1,2}\s*\/\s*[0-9]{2,4})\s+(.+)$/u', $normalized, $m)
            && preg_match('/\d{1,2}(?:[.:]\d{1,2})?\s*(?:A\.?M\.?|P\.?M\.?|am|pm)?|सकाळी|दुपारी|सायंकाळी|रात्री/ui', $m[2])) {
            return [$this->normalizeDateValue(trim($m[1])), trim($m[2])];
        }

        return [$this->normalizeDateValue(trim((string) preg_replace('/[\s,.।]+$/u', '', $raw))), null];
    }

    private function normalizeDateValue(string $value): string
    {
        $normalized = OcrNormalize::normalizeDate($value);

        return $normalized !== null && $normalized !== '' ? $normalized : $value;
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, mixed>  $core
     */
    private function extractEnglishBasicFields(array $lines, array &$core): void
    {
        foreach ($lines as $index => $line) {
            $label = mb_strtolower(trim($line));
            $next = trim((string) ($lines[$index + 1] ?? ''));
            if (($core['date_of_birth'] ?? null) === null && $label === 'dob' && $next !== '') {
                $core['date_of_birth'] = $this->normalizeDateValue($next);
            }
            if (($core['height_cm'] ?? null) === null && $label === 'height' && $next !== '') {
                $core['height_cm'] = $this->parseHeightCm($next);
            }
            if (($core['highest_education'] ?? null) === null && $label === 'education' && $next !== '') {
                $core['highest_education'] = $next;
            }
        }
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, mixed>  $core
     */
    private function extractEducationCareer(array $lines, array &$core): void
    {
        $capturedCareer = false;
        $careerClosed = false;
        foreach ($lines as $line) {
            if ($careerClosed) {
                continue;
            }
            if ($capturedCareer && $this->looksLikeStandaloneIncomeLine($line)) {
                $this->extractAmountFromIncomeText($line, $core);

                continue;
            }
            if ($capturedCareer && $this->startsCandidateCareerBoundary($line)
                && ! preg_match('/^(?:शिक्षण|नोकरी|व्यवसाय|वेतन|उत्पन्न|नोकरी\/व्यवसाय|कंपनी|company|कामाचे\s+ठिकाण|नोकरीचे\s+ठिकाण|work\s+location)'.self::LABEL_SUFFIX.'/ui', $line)) {
                $careerClosed = true;
                continue;
            }
            if (($education = $this->extractLabeledValue($line, ['शिक्षण'])) !== null) {
                $core['highest_education'] = $education;
            }
            $work = null;
            $trimmedLine = trim($line);
            if (preg_match('/^(?:नोकरी\/व्यवसाय|नोकरी|व्यवसाय)\s*(?::\s*-\s*|[:\-–—]\s*)(.+)$/u', $trimmedLine, $m)) {
                $work = trim($m[1]);
            } elseif (preg_match('/^(?:नोकरी\/व्यवसाय|नोकरी|व्यवसाय)\s+(.+)$/u', $trimmedLine, $m)
                && $this->looksLikeSeparatorlessWorkValue(trim($m[1]))) {
                $work = trim($m[1]);
            }
            if ($work !== null) {
                $isBusinessLine = preg_match('/^व्यवसाय'.self::LABEL_SUFFIX.'/u', $line) === 1;
                if ($isBusinessLine && (($core['company_name'] ?? null) !== null || ($core['work_location_text'] ?? null) !== null)) {
                    $occupation = $this->cleanOccupationText($work);
                    if ($occupation !== '') {
                        $core['occupation_title'] = $occupation;
                        $capturedCareer = true;
                    }
                    continue;
                }
                $this->parseWorkLine($work, $core);
                if (($core['occupation_title'] ?? null) === null && ($core['company_name'] ?? null) !== null) {
                    $core['occupation_title'] = 'नोकरी';
                }
                if (($core['occupation_title'] ?? null) !== null || ($core['company_name'] ?? null) !== null || ($core['work_location_text'] ?? null) !== null) {
                    $capturedCareer = true;
                }
            }
            if (($company = $this->extractLabeledValue($line, ['कंपनी', 'company'])) !== null) {
                $core['company_name'] = $company;
                $capturedCareer = true;
            }
            if (($workLocation = $this->extractLabeledValue($line, ['कामाचे ठिकाण', 'नोकरीचे ठिकाण', 'work location'])) !== null) {
                $core['work_location_text'] = $workLocation;
                $capturedCareer = true;
            }
        }
    }

    private function startsCandidateCareerBoundary(string $line): bool
    {
        $line = trim($line);

        return $this->isParentContactLine($line)
            || $this->startsSiblingLine($line)
            || (bool) preg_match('/^(?:बहीण|बहिण|बहिणी|दाजी|मामा|मावशी|माऊशी|आत्या|चुलते|आजोळ|इतर\s+नातेवाईक|नातेसंबंध)'.self::LABEL_SUFFIX.'/u', $line);
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
        if (preg_match('/^\s*[-–—]?\s*(?:भावजय|वहिनी|वाहिनी)\s*(?::\s*-\s*|[:\-–—]\s*)(.+)$/u', $line, $m)) {
            $siblings[] = array_merge(
                ['relation_type' => 'brother_wife', 'marital_status' => 'married'],
                $this->siblingSpouseLineToRow(trim($m[1]))
            );

            return;
        }
        if (preg_match('/^\s*[-–—]?\s*(?:दाजी|जावई|भाऊजी|भावजी)\s*(?::\s*-\s*|[:\-–—]\s*)(.+)$/u', $line, $m)) {
            $siblings[] = array_merge(
                ['relation_type' => 'sister_husband', 'marital_status' => 'married'],
                $this->siblingSpouseLineToRow(trim($m[1]))
            );

            return;
        }
        if (preg_match('/^\s*[-–—]?\s*भाऊ\s*(?::\s*-\s*|[:\-–—]\s*)(.+)$/u', $line, $m)) {
            $value = trim($m[1]);
            if ($this->isNoSiblingValue($value)) {
                $core['brother_count'] = 0;

                return;
            }
            if (($count = $this->siblingCountFromValue($value)) !== null) {
                $core['brother_count'] = $count;
                $maritalStatus = $this->extractSiblingMaritalStatus($value);
                if ($maritalStatus !== null) {
                    for ($i = 0; $i < $count; $i++) {
                        $siblings[] = ['relation_type' => 'brother', 'name' => null, 'marital_status' => $maritalStatus];
                    }
                }

                return;
            }
            if ($this->isNumericCountValue($value)) {
                $core['brother_count'] = (int) OcrNormalize::normalizeDigits($value);

                return;
            }
            $siblings[] = ['relation_type' => 'brother', 'name' => $value];
        }
        if (preg_match('/^\s*[-–—]?\s*(?:बहीण|बहिण|बहिणी)\s*(?::\s*-\s*|[:\-–—]\s*)(.+)$/u', $line, $m)) {
            $value = trim($m[1]);
            if ($this->isNoSiblingValue($value)) {
                $core['sister_count'] = 0;

                return;
            }
            if (($count = $this->siblingCountFromValue($value)) !== null) {
                $core['sister_count'] = $count;
                $maritalStatus = $this->extractSiblingMaritalStatus($value);
                if ($maritalStatus !== null) {
                    for ($i = 0; $i < $count; $i++) {
                        $siblings[] = ['relation_type' => 'sister', 'name' => null, 'marital_status' => $maritalStatus];
                    }
                }

                return;
            }
            if ($this->isOneCountValue($value)) {
                $core['sister_count'] = 1;
                $maritalStatus = $this->extractSiblingMaritalStatus($value);
                if ($maritalStatus !== null) {
                    $siblings[] = ['relation_type' => 'sister', 'name' => null, 'marital_status' => $maritalStatus];
                }

                return;
            }
            if ($this->isNumericCountValue($value)) {
                $core['sister_count'] = (int) OcrNormalize::normalizeDigits($value);

                return;
            }
            $siblings[] = ['relation_type' => 'sister', 'name' => $value];
        }
    }

    private function siblingCountFromValue(string $value): ?int
    {
        $normalized = OcrNormalize::normalizeDigits($value);
        if (preg_match('/(?:^|\s)([0-9]+)\s*(?:भाऊ|बंधू|बहीण|बहिण|बहिणी)(?:\s|$|\()/u', $normalized, $m)) {
            return max(0, (int) $m[1]);
        }

        return null;
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, array<string, mixed>>  $siblings
     */
    private function enrichSiblingRowsFromOrderedLines(array $lines, array &$siblings): void
    {
        $siblingKeys = array_keys($siblings);
        $cursor = -1;
        $currentKey = null;
        $capturingAddress = false;
        $currentRelation = null;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            if ($this->startsSiblingLine($trimmed)) {
                $value = $this->siblingLineValue($trimmed);
                $capturingAddress = false;
                if ($value === null || $this->isNoSiblingValue($value) || $this->isNumericCountValue($value)) {
                    $currentKey = null;
                    $currentRelation = null;
                    continue;
                }
                $cursor++;
                $currentKey = $siblingKeys[$cursor] ?? null;
                $currentRelation = $currentKey !== null && isset($siblings[$currentKey])
                    ? (string) ($siblings[$currentKey]['relation_type'] ?? '')
                    : null;
                continue;
            }

            if ($currentKey === null || ! isset($siblings[$currentKey])) {
                continue;
            }

            if ($this->isParentContactLine($trimmed)) {
                $currentKey = null;
                $capturingAddress = false;
                $currentRelation = null;
                continue;
            }

            if ($currentRelation === 'sister' && preg_match('/^(?:दाजी)\s*(?::\s*-\s*|[:\-]\s*|\s+)(.+)$/u', $trimmed, $m)) {
                $siblings[$currentKey]['marital_status'] = 'married';
                $siblings[$currentKey]['spouse'] = $this->parseSisterSpouseLine(trim($m[1]));
                $capturingAddress = false;
                continue;
            }

            if ((bool) preg_match('/^(?:दाजी|सासरे|मामा|मावशी|माऊशी|आत्या|आत्त्या|मुलाची\s+आत्या|मुलाची\s+आत्त्या|चुलते|मुलाचे\s+चुलते|मुलाचे\s+मामा|आजोळ|इतर\s+नातेवाईक|उत्तर\s+नातेवाईक|नातेसंबंध|नाते\s+संबंध)'.self::LABEL_SUFFIX.'/u', $trimmed)) {
                $currentKey = null;
                $capturingAddress = false;
                $currentRelation = null;
                continue;
            }

            if (preg_match('/^(?:नोकरी\/व्यवसाय|नोकरी|व्यवसाय)\s*(?::\s*-\s*|[:\-]\s*)(.+)$/u', $trimmed, $m)) {
                $value = trim($m[1]);
                if ($this->looksLikeSiblingAddressText($value)
                    && ! $this->looksLikeSiblingAdditionalInfoText($value)
                    && ! $this->looksLikeEmployerLeadSegment($value)
                    && ! $this->looksLikeCompanyName($value)
                    && ! preg_match('/(?:कंपनी|company|ltd|limited|bank|electric|pharma|construction|consultant|analyst)/ui', $value)) {
                    $siblings[$currentKey]['address_line'] = $this->setTextOnce($siblings[$currentKey]['address_line'] ?? null, $value);
                } else {
                    $siblings[$currentKey]['occupation'] = $value;
                }
                $capturingAddress = false;
                continue;
            }

            if (preg_match('/^\(\s*(?:नोकरी\/व्यवसाय|नोकरी|व्यवसाय)\s*[-:]\s*(.+?)\s*\)$/u', $trimmed, $m)) {
                $siblings[$currentKey]['occupation'] = $this->setTextOnce($siblings[$currentKey]['occupation'] ?? null, $this->cleanSiblingOccupationText($m[1]));
                $capturingAddress = false;
                continue;
            }

            if ($this->startsParentScopedAddressLine($trimmed)) {
                $capturingAddress = false;
                continue;
            }

            if ($this->startsAddressLine($trimmed)) {
                $address = $this->labeledAddressValue($trimmed);
                if ($address !== '') {
                    $siblings[$currentKey]['address_line'] = $this->setTextOnce($siblings[$currentKey]['address_line'] ?? null, $address);
                    $capturingAddress = true;
                }
                continue;
            }

            if ($capturingAddress && ! $this->looksLikeAnyKnownLabel($trimmed)) {
                $siblings[$currentKey]['address_line'] = $this->setTextOnce($siblings[$currentKey]['address_line'] ?? null, $trimmed);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function parseSisterSpouseLine(string $line): array
    {
        $phones = $this->extractPhones($line);
        $clean = OcrNormalize::normalizeDigits($line);
        foreach ($phones as $phone) {
            $clean = str_replace($phone, '', $clean);
        }
        $occupationHintPattern = 'व्यवसाय|व्यवसायिक|व्यवसाईक|business|doctor|teacher|engineer|नोकरी|शिक्षक|शिक्षिका|डॉक्टर|इंजिनिअर|इंजिनियर|प्राध्यापक|सेवानिवृत्त|सरकारी|खाजगी';
        $addressHintPattern = 'ता\.?|जि\.?|मुर्ती|मुंबई|पुणे|सांगली|सोलापूर|ठाणे|बारामती|डोंबिवली|सातारा|कराड|कोल्हापूर';
        $occupation = null;
        $address = null;
        $notes = [];
        if (preg_match('/\(([^()]*(?:'.$occupationHintPattern.')[^()]*)\)/ui', $clean, $m)) {
            $occupation = $this->cleanOccupationText($m[1]);
            $clean = trim(str_replace($m[0], '', $clean));
        }
        if (preg_match_all('/\(([^()]*)\)/u', $clean, $noteMatches, PREG_SET_ORDER)) {
            foreach ($noteMatches as $noteMatch) {
                $note = $this->trimSeparators($noteMatch[1] ?? '');
                if ($note === '' || $note === 'सरकार' || preg_match('/(?:'.$addressHintPattern.')/ui', $note)) {
                    continue;
                }
                $notes[] = $note;
                $clean = trim(str_replace($noteMatch[0], '', $clean));
            }
        }
        $parts = preg_split('/\s*(?:पत्ता\.?|पत्ता|पता)\s*[:\-.]?\s*/u', $clean, 2);
        $nameAddress = trim((string) ($parts[0] ?? $clean));
        $address = trim((string) ($parts[1] ?? ''));
        if ($address === '' && preg_match('/^(.+?)\s*\(([^()]*(?:'.$addressHintPattern.')[^()]*)\)\s+(.+)$/u', $nameAddress, $m)) {
            $nameAddress = trim($m[1]);
            $address = trim($m[2].'; '.$m[3]);
        } elseif ($address === '' && preg_match('/^(.+?)\s+(रा\.?\s*.+)$/u', $nameAddress, $m)) {
            $nameAddress = trim($m[1]);
            $address = trim($m[2]);
        } elseif ($address === '' && preg_match('/^(.+?),\s*(.+)$/u', $nameAddress, $m)) {
            $nameAddress = trim($m[1]);
            $address = trim($m[2]);
        } elseif ($address === '' && preg_match('/^(.+?)\s*\(([^()]*(?:'.$addressHintPattern.')[^()]*)\)$/u', $nameAddress, $m)) {
            $nameAddress = trim($m[1]);
            $address = trim($m[2]);
        }
        $name = $this->cleanSiblingName($nameAddress);
        if ($name !== '' && str_contains($nameAddress, '(सरकार)') && ! str_contains($name, '(सरकार)')) {
            $name .= ' (सरकार)';
        }
        $spouse = [];
        if ($name !== '') {
            $spouse['name'] = $name;
        }
        if ($occupation !== null && $occupation !== '') {
            $spouse['occupation_title'] = $occupation;
        }
        if ($address !== '') {
            if (preg_match_all('/\(([^()]*(?:'.$occupationHintPattern.')[^()]*)\)/ui', $address, $occMatches)) {
                foreach ($occMatches[1] as $occ) {
                    $spouse['occupation_title'] = $this->setTextOnce($spouse['occupation_title'] ?? null, $this->cleanOccupationText($occ));
                }
                $address = preg_replace('/\([^()]*(?:'.$occupationHintPattern.')[^()]*\)/ui', '', $address) ?? $address;
            }
            if (preg_match('/^(.+?);\s*((?:श्री\s+)?(?:क्लिनिक|हॉस्पिटल|Hospital|Clinic)\b.*)$/ui', $address, $m)) {
                $address = trim($m[1]);
                $spouse['occupation_title'] = $this->setTextOnce($spouse['occupation_title'] ?? null, $this->trimSeparators($m[2]));
            }
            $spouse['address_line'] = $this->trimSeparators($address);
        }
        if ($notes !== []) {
            $spouse['notes'] = implode('; ', array_unique($notes));
        }
        if ($phones !== []) {
            $spouse['contact_number'] = $phones[0];
        }

        return $spouse;
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, array<string, mixed>>  $siblings
     */
    private function attachJawaiRowsToMarriedSisters(array $lines, array &$siblings): void
    {
        $spouseValues = [];
        $capturing = false;
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            if (preg_match('/^\s*[-–—]?\s*(?:जावई|दाजी)\s*(?::\s*-\s*|[:\-]\s*|\s+)(.+)$/u', $trimmed, $m)) {
                $capturing = true;
                foreach ($this->splitJawaiSpouseValues(trim($m[1])) as $value) {
                    $spouseValues[] = $value;
                }
                continue;
            }
            if (! $capturing) {
                continue;
            }
            if ($this->startsSiblingLine($trimmed) || $this->startsSiblingSpouseLine($trimmed)) {
                $capturing = false;
                continue;
            }
            if ($this->startsAddressLine($trimmed) || $this->startsContactLine($trimmed)) {
                $capturing = false;
                continue;
            }
            if ($this->startsPahune($trimmed) || $this->isOtherRelativesLabel(trim((string) preg_replace('/\s*(?::\s*-\s*|[:\-–—]).*$/u', '', $trimmed)))) {
                $capturing = false;
                continue;
            }
            if ($this->looksLikeAnyKnownLabel($trimmed)) {
                $capturing = false;
                continue;
            }
            $lineValue = trim(preg_replace('/^\s*[-–—]\s*/u', '', $trimmed) ?? $trimmed);
            if ($lineValue === '') {
                continue;
            }
            if ($spouseValues !== [] && ! $this->startsLikelySpouseName($lineValue)) {
                $last = array_key_last($spouseValues);
                $spouseValues[$last] = trim($spouseValues[$last].' '.$lineValue);
                continue;
            }
            if ($spouseValues !== [] && $this->lineIsRelativeOccupationOnly($lineValue)) {
                $last = array_key_last($spouseValues);
                $spouseValues[$last] = trim($spouseValues[$last].' '.$lineValue);
                continue;
            }
            foreach ($this->splitJawaiSpouseValues($lineValue) as $value) {
                $spouseValues[] = $value;
            }
        }

        if ($spouseValues === []) {
            return;
        }

        $sisterKeys = [];
        foreach ($siblings as $key => $sibling) {
            if (($sibling['relation_type'] ?? null) === 'sister') {
                $sisterKeys[] = $key;
            }
        }

        foreach ($spouseValues as $index => $value) {
            $key = $sisterKeys[$index] ?? null;
            if ($key === null || ! isset($siblings[$key])) {
                break;
            }
            $spouse = $this->parseSisterSpouseLine($value);
            if ($spouse === []) {
                continue;
            }
            $siblings[$key]['spouse'] = $this->mergeSiblingSpouseRows(
                is_array($siblings[$key]['spouse'] ?? null) ? $siblings[$key]['spouse'] : [],
                $spouse
            );
            $siblings[$key]['marital_status'] = 'married';
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $siblings
     * @return array<string, array<string, mixed>>
     */
    private function removeDuplicateAttachedSiblingSpouses(array $siblings): array
    {
        $attachedSpouseNames = [];
        foreach ($siblings as $row) {
            if (! is_array($row)) {
                continue;
            }
            $relationType = $this->normalizeSiblingRelationType($row['relation_type'] ?? null);
            if ($relationType !== 'sister') {
                continue;
            }
            $spouseName = trim((string) ($row['spouse']['name'] ?? ''));
            if ($spouseName !== '') {
                $attachedSpouseNames[$this->cleanSiblingName($spouseName)] = true;
            }
        }

        $out = [];
        foreach ($siblings as $key => $row) {
            if (! is_array($row)) {
                continue;
            }
            $relationType = $this->normalizeSiblingRelationType($row['relation_type'] ?? null);
            $name = trim((string) ($row['name'] ?? ''));
            if ($relationType === 'sister_husband' && $name !== '' && isset($attachedSpouseNames[$this->cleanSiblingName($name)])) {
                continue;
            }
            $out[$key] = $row;
        }

        return $out;
    }

    private function startsLikelySpouseName(string $line): bool
    {
        $line = trim($line);

        return (bool) preg_match('/^(?:(?:\d+|[०-९]+)[\).]\s*)?(?:डॉ\.?|श्री\.?\s+(?!क्लिनिक)|सौ\.?|कै\.?)/u', $line);
    }

    /**
     * @return list<string>
     */
    private function splitJawaiSpouseValues(string $value): array
    {
        $value = trim($value);
        $numberedParts = preg_split('/(?:\R|\s+)(?=(?:\d+|[०-९]+)[\).])/u', $value) ?: [];
        if (count($numberedParts) <= 1) {
            return $value !== '' ? [trim(preg_replace('/^\s*(?:\d+|[०-९]+)[\).]\s*/u', '', $value) ?? $value)] : [];
        }

        $out = [];
        foreach ($numberedParts as $part) {
            $part = trim(preg_replace('/^\s*(?:\d+|[०-९]+)[\).]\s*/u', '', $part) ?? $part);
            if ($part !== '') {
                $out[] = $part;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergeSiblingSpouseRows(array $current, array $incoming): array
    {
        foreach ($incoming as $field => $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            if (! isset($current[$field]) || trim((string) $current[$field]) === '') {
                $current[$field] = $value;
            } elseif (in_array($field, ['address_line', 'occupation_title', 'notes'], true)) {
                $current[$field] = $this->setTextOnce($current[$field], $value);
            }
        }

        return $current;
    }

    private function siblingLineValue(string $line): ?string
    {
        if (preg_match('/^(?:भाऊ|बहीण|बहिण|बहिणी|मुलाचा\s+भाऊ|मुलाचे\s+भाऊ|मुलाची\s+बहीण|मुलाची\s+बहिण)\s*(?::\s*-\s*|[:\-]\s*)(.+)$/u', $line, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, array<string, mixed>>  $siblings
     */
    private function appendSiblingContinuationRows(array $lines, array &$siblings): void
    {
        $sourceRows = array_values($siblings);
        $sourceIndex = 0;
        $ordered = [];
        $currentRelation = null;
        $allowContinuation = false;
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            if ($this->startsSiblingLine($trimmed)) {
                $value = $this->siblingLineValue($trimmed);
                if ($value === null || $this->isNoSiblingValue($value) || $this->isNumericCountValue($value)) {
                    $currentRelation = null;
                    continue;
                }
                $currentRelation = preg_match('/^भाऊ/u', $trimmed) ? 'brother' : 'sister';
                $row = $sourceRows[$sourceIndex] ?? ['relation_type' => $currentRelation, 'name' => $value];
                $sourceIndex++;
                $ordered[] = $row;
                $allowContinuation = ! (bool) preg_match('/^(?:मुलाचा\s+भाऊ|मुलाचे\s+भाऊ|मुलाची\s+बहीण|मुलाची\s+बहिण)\s*(?::\s*-\s*|[:\-–—]\s*)/u', $trimmed);
                if (! $allowContinuation) {
                    $currentRelation = null;
                }
                continue;
            }

            if ($currentRelation === null) {
                continue;
            }

            if ($this->isParentContactLine($trimmed)
                || $this->looksLikeAnyKnownLabel($trimmed)
                || $this->looksLikeRelativeContinuationBoundary($trimmed)) {
                if (! $this->startsAddressLine($trimmed) && ! preg_match('/^(?:नोकरी\/व्यवसाय|नोकरी|व्यवसाय)'.self::LABEL_SUFFIX.'/u', $trimmed)) {
                    $currentRelation = null;
                    $allowContinuation = false;
                }
                continue;
            }

            if ($allowContinuation && $this->looksLikeSiblingContinuationName($trimmed)) {
                $ordered[] = ['relation_type' => $currentRelation, 'name' => $trimmed];
            }
        }

        while (isset($sourceRows[$sourceIndex])) {
            $ordered[] = $sourceRows[$sourceIndex];
            $sourceIndex++;
        }

        if ($ordered !== []) {
            $siblings = $ordered;
        }
    }

    private function looksLikeSiblingContinuationName(string $line): bool
    {
        $withoutParentheses = preg_replace('/\([^()]*\)/u', '', $line) ?? $line;
        if (preg_match('/(?:पत्ता|मोबाईल|मोबाइल|संपर्क|नोकरी|व्यवसाय|शिक्षण|जन्म|रास|नक्षत्र|गण|नाडी|मामा|आत्या|चुलते|नाते\s*संबंध)/u', $withoutParentheses)) {
            return false;
        }

        return (bool) preg_match('/^(?:कु\.?|चि\.?|श्री\.?|सौ\.?)?\s*[\p{L}\p{M}.]+\s+[\p{L}\p{M}.]+/u', $line);
    }

    private function looksLikeRelativeContinuationBoundary(string $line): bool
    {
        return (bool) preg_match('/^(?:दाजी|सासरे|मामा|मावशी|माऊशी|आत्या|आत्त्या|मुलाची\s+आत्या|मुलाची\s+आत्त्या|चुलते|मुलाचे\s+चुलते|आजोळ|इतर\s+नातेवाईक|इतर\s+पाहूणे|इतर\s+पाहुणे|पाहुणे|नातेसंबंध|नाते\s+संबंध|मुलाचे\s+मामा)'.self::LABEL_SUFFIX.'/u', $line);
    }

    /**
     * @param  array<string, array<string, mixed>>  $siblings
     * @return array<string, array<string, mixed>>
     */
    private function normalizeSiblingRowsForWizard(array $siblings): array
    {
        $out = [];
        foreach ($siblings as $key => $row) {
            if (! is_array($row)) {
                continue;
            }
            $relationType = $this->normalizeSiblingRelationType($row['relation_type'] ?? null);
            if ($relationType === null) {
                continue;
            }

            $rawName = trim((string) ($row['name'] ?? ''));
            $maritalStatus = $this->normalizeSiblingMaritalStatus($row['marital_status'] ?? null)
                ?? $this->extractSiblingMaritalStatus($rawName)
                ?? $this->inferSiblingMaritalStatusFromHonorific($rawName);
            $phones = array_values(array_unique(array_merge(
                $this->extractPhones($rawName),
                $this->extractPhones((string) ($row['contact_number'] ?? '')),
                $this->extractPhones((string) ($row['contact_number_2'] ?? '')),
                $this->extractPhones((string) ($row['contact_number_3'] ?? ''))
            )));

            $occupationParts = [];
            $addressParts = [];
            $noteParts = [];
            $nameParentheticalParts = [];
            $rawNameForName = $rawName;
            if (! empty($row['occupation'])) {
                $occupationParts[] = $this->cleanSiblingOccupationText((string) $row['occupation']);
            }
            if (preg_match_all('/\{([^{}]*)\}/u', $rawName, $braceMatches)) {
                foreach ($braceMatches[1] as $inner) {
                    $inner = trim((string) $inner);
                    if ($inner === '' || $this->extractSiblingMaritalStatus($inner) !== null || $this->extractPhones($inner) !== []) {
                        continue;
                    }
                    if ($this->looksLikeSiblingAddressText($inner)) {
                        $addressParts[] = $inner;
                    } elseif ($this->looksLikeSiblingAdditionalInfoText($inner)) {
                        $noteParts[] = $inner;
                    } else {
                        $occupationParts[] = $this->cleanSiblingOccupationText($inner);
                    }
                }
            }
            if (preg_match('/^(.*?)\{[^{}]*\}\s*(.+)$/u', $rawName, $m)) {
                $tail = $this->trimSeparators($m[2]);
                if ($tail !== '' && $this->looksLikeSiblingAddressText($tail)) {
                    $addressParts[] = $tail;
                    $rawNameForName = trim($m[1]);
                }
            }
            if (preg_match_all('/\(([^()]*)\)/u', $rawName, $parenMatches)) {
                foreach ($parenMatches[1] as $inner) {
                    $inner = trim((string) $inner);
                    if ($inner === '' || $this->extractSiblingMaritalStatus($inner) !== null || $this->extractPhones($inner) !== []) {
                        continue;
                    }
                    if ($this->looksLikeSiblingAddressText($inner)) {
                        $addressParts[] = $inner;
                    } elseif ($this->looksLikeSiblingAdditionalInfoText($inner)) {
                        $noteParts[] = $inner;
                    } elseif (in_array($relationType, ['brother_wife', 'sister_husband'], true) && $inner === 'सरकार') {
                        $nameParentheticalParts[] = $inner;
                    } else {
                        $occupationParts[] = $this->cleanSiblingOccupationText($inner);
                    }
                }
            }

            $name = $this->cleanSiblingName($rawNameForName);
            foreach (array_unique($nameParentheticalParts) as $part) {
                if ($name !== '' && ! str_contains($name, '('.$part.')')) {
                    $name .= ' ('.$part.')';
                }
            }
            if ($name === '' && $maritalStatus !== null && empty($row['spouse'] ?? null)) {
                $out[$key] = [
                    'relation_type' => $relationType,
                    'name' => null,
                    'marital_status' => $maritalStatus,
                ];
                continue;
            }
            $normalized = [
                'relation_type' => $relationType,
                'name' => $name !== '' ? $name : null,
            ];
            if ($maritalStatus !== null) {
                $normalized['marital_status'] = $maritalStatus;
            }
            if (in_array($relationType, ['brother_wife', 'sister_husband'], true)) {
                $normalized['marital_status'] = 'married';
            }
            foreach (array_values(array_unique(array_filter($occupationParts))) as $occupation) {
                $normalized['occupation'] = $this->setTextOnce($normalized['occupation'] ?? null, $occupation);
            }
            foreach (array_values(array_unique(array_filter($addressParts))) as $address) {
                $normalized['address_line'] = $this->setTextOnce($normalized['address_line'] ?? null, $address);
            }
            if (empty($normalized['address_line'] ?? null)
                && ! empty($normalized['occupation'] ?? null)
                && $this->looksLikeSiblingAddressText((string) $normalized['occupation'])
                && ! $this->looksLikeCompanyName((string) $normalized['occupation'])
                && ! preg_match('/(?:कंपनी|company|ltd|limited|bank|electric|pharma|construction|consultant|analyst)/ui', (string) $normalized['occupation'])) {
                $normalized['address_line'] = $normalized['occupation'];
                unset($normalized['occupation']);
            }
            foreach (array_values(array_unique(array_filter($noteParts))) as $note) {
                $normalized['notes'] = $this->setTextOnce($normalized['notes'] ?? null, $note);
            }
            foreach ([
                'id',
                'gender',
                'address_line',
                'notes',
                'sort_order',
                'location_display',
                'occupation_master_id',
                'occupation_custom_id',
                'city_id',
                'taluka_id',
                'district_id',
                'state_id',
            ] as $field) {
                if (array_key_exists($field, $row) && $row[$field] !== null && trim((string) $row[$field]) !== '') {
                    $normalized[$field] = $row[$field];
                }
            }
            foreach ($phones as $index => $phone) {
                if ($index > 2) {
                    break;
                }
                $normalized[$index === 0 ? 'contact_number' : 'contact_number_'.($index + 1)] = $phone;
            }
            if (isset($row['spouse']) && is_array($row['spouse'])) {
                $spouse = $this->normalizeSiblingSpouseRow($row['spouse']);
                if ($spouse !== []) {
                    $normalized['spouse'] = $spouse;
                    $normalized['marital_status'] = 'married';
                }
            }

            $out[$key] = $normalized;
        }

        $namedRelations = [];
        foreach ($out as $row) {
            if (trim((string) ($row['name'] ?? '')) !== '') {
                $namedRelations[(string) ($row['relation_type'] ?? '')] = true;
            }
        }

        return array_filter(
            $out,
            static function (array $row) use ($namedRelations): bool {
                $relationType = (string) ($row['relation_type'] ?? '');
                $isStatusOnly = trim((string) ($row['name'] ?? '')) === ''
                    && ! isset($row['spouse'])
                    && ! isset($row['occupation'])
                    && ! isset($row['address_line'])
                    && ! isset($row['contact_number']);

                return ! ($isStatusOnly && isset($namedRelations[$relationType]));
            }
        );
    }

    private function normalizeSiblingRelationType(mixed $value): ?string
    {
        $v = mb_strtolower(trim((string) $value));

        return match ($v) {
            'brother', 'भाऊ' => 'brother',
            'sister', 'बहीण', 'बहिण', 'बहिणी' => 'sister',
            'brother_wife', 'brother wife', "brother's wife", 'भावजय', 'वहिनी', 'वाहिनी' => 'brother_wife',
            'sister_husband', 'sister husband', "sister's husband", 'दाजी', 'जावई', 'भाऊजी', 'भावजी' => 'sister_husband',
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function siblingSpouseLineToRow(string $line): array
    {
        $spouse = $this->parseSisterSpouseLine($line);

        return [
            'name' => $spouse['name'] ?? null,
            'occupation' => $spouse['occupation_title'] ?? null,
            'contact_number' => $spouse['contact_number'] ?? null,
            'address_line' => $spouse['address_line'] ?? null,
            'notes' => $spouse['notes'] ?? null,
        ];
    }

    private function normalizeSiblingMaritalStatus(mixed $value): ?string
    {
        $v = mb_strtolower(trim((string) $value));

        return in_array($v, ['married', 'unmarried'], true) ? $v : null;
    }

    private function extractSiblingMaritalStatus(string $value): ?string
    {
        if (preg_match('/अविवाहित|अविवाहीत|unmarried/ui', $value)) {
            return 'unmarried';
        }
        if (preg_match('/विवाहित|विवाहीत|married/ui', $value)) {
            return 'married';
        }

        return null;
    }

    private function inferSiblingMaritalStatusFromHonorific(string $value): ?string
    {
        $v = trim($value);
        if (preg_match('/^(?:चि\.?|कु\.?)\s*/u', $v)) {
            return 'unmarried';
        }
        if (preg_match('/^(?:श्री\.?|सौ\.?)\s*/u', $v)) {
            return 'married';
        }

        return null;
    }

    private function cleanSiblingOccupationText(string $value): string
    {
        $value = $this->cleanOccupationText($value);
        $value = preg_replace('/^(?:नोकरी\/व्यवसाय|नोकरी|व्यवसाय)\s*[-:]\s*/u', '', $value) ?? $value;

        return $this->trimSeparators($value);
    }

    private function looksLikeSiblingAddressText(string $value): bool
    {
        return (bool) preg_match('/(?:ता\.?|जि\.?|मु\.?\s*पो\.?|रा\.|रोड|नगर|गाव|वाडी|पुणे|कोल्हापूर|सांगली|सोलापूर|सातारा|करवीर|पन्हाळा|मुंबई|नवी\s+मुंबई|usa|u\.s\.a|united\s+states|san\s+francisco)/ui', $value);
    }

    private function looksLikeSiblingAdditionalInfoText(string $value): bool
    {
        return (bool) preg_match('/(?:शिक्षण\b|\b(?:B\.?\s*A|B\.?\s*Com|B\.?\s*Sc|B\.?\s*E|M\.?\s*A|M\.?\s*Com|M\.?\s*Sc|M\.?\s*Tech|MBA|BBA|BA|BCOM|BSC|BE|ME|MTECH|ITI|Diploma|डिप्लोमा)\b)/ui', $value);
    }

    private function cleanSiblingName(string $value): string
    {
        $v = OcrNormalize::normalizeDigits($value);
        $v = preg_replace('/(?<!\d)[6-9]\d{9}(?!\d)/u', '', $v) ?? $v;
        $v = preg_replace('/\([^()]*\)/u', '', $v) ?? $v;
        $v = preg_replace('/\{[^{}]*\}/u', '', $v) ?? $v;
        $v = preg_replace('/^(?:[0-9]+|[०-९]+|एक|दोन|तीन|चार|पाच|सहा)\s*(?:[-–—:]|\.|\)|\.)?\s*/u', '', $v) ?? $v;
        $v = preg_replace('/\b(?:अविवाहित|अविवाहीत|विवाहित|विवाहीत)\b\s*[-–—:]?\s*/u', '', $v) ?? $v;
        $v = preg_replace('/^\s*(?:[0-9]+|[०-९]+)\s*[\.)]\s*/u', '', $v) ?? $v;
        $v = preg_replace('/^\s*(?:चि\.?|कु\.?|श्री\.?|सौ\.?)\s*/u', '', $v) ?? $v;
        $v = preg_replace('/\b(?:Whats\s*App|Whatsapp)\b/ui', '', $v) ?? $v;
        $v = preg_replace('/(?:मो\.?\s*नं?\.?|मोबाईल|मोबाइल|संपर्क)\s*[:\-\.]*$/u', '', $v) ?? $v;

        return $this->trimSeparators($v);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeSiblingSpouseRow(array $row): array
    {
        $out = [];
        foreach ([
            'name',
            'occupation_title',
            'address_line',
            'location_display',
            'notes',
            'additional_info',
            'occupation_master_id',
            'occupation_custom_id',
            'city_id',
            'taluka_id',
            'district_id',
            'state_id',
        ] as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null && trim((string) $row[$field]) !== '') {
                $out[$field] = $row[$field];
            }
        }
        $phones = $this->extractPhones(implode(' ', array_map('strval', [
            $row['contact_number'] ?? '',
            $row['phone_number'] ?? '',
            $row['name'] ?? '',
        ])));
        if ($phones !== []) {
            $out['contact_number'] = $phones[0];
        }

        return $out;
    }

    private function labeledAddressValue(string $line): string
    {
        if (preg_match('/^(?:घरचा\s+पत्ता|घराचा\s+पत्ता|घर\s+पत्ता|सध्याचा\s+पत्ता|निवासी\s+पत्ता|गावचा\s+पत्ता|मुळगाव|मूळगाव|पत्ता|पता)\s*(?::\s*-\s*|[:\-]\s*)?(.*)$/u', $line, $m)) {
            return $this->cleanRelativeAddress(trim((string) ($m[1] ?? '')));
        }

        return '';
    }

    private function looksLikeAnyKnownLabel(string $line): bool
    {
        $line = trim((string) preg_replace('/^\s*[-–—]\s*/u', '', $line));

        return (bool) preg_match('/^(?:मुलाचे\s+नां?व|मुलीचे\s+नां?व|वधूचे\s+नां?व|जन्म|शिक्षण|नोकरी|व्यवसाय|जात|धर्म|उंची|वर्ण|देवक|रास|राशी|नक्षत्र|नाड|नाडी|गण|चरण|वडिलांचे|पित्याचे|आईचे|मातेचे|आई|भाऊ|बहीण|बहिण|मुलाचा\s+भाऊ|मुलाचे\s+भाऊ|मुलाची\s+बहीण|मुलाची\s+बहिण|दाजी|जावई|मामा|मावशी|माऊशी|आत्या|मुलाची\s+आत्या|चुलते|मुलाचे\s+मामा|चुलत\s+भाऊ|चुलत\s+बहिण|चुलत\s+बहीण|पत्ता|गावचा\s+पत्ता|सध्याचा\s+पत्ता|मोबाईल|मोबाइल|मोबाईल\s+नंबर|फोन|संपर्क|अपेक्षा|जोडीदार\s+अपेक्षा|प्रॉपर्टी|प्रोपर्टी|मालमत्ता|शेती|कौटुंबिक|नातेसंबंध|नाते\s+संबंध)'.self::LABEL_SUFFIX.'/u', $line);
    }

    /**
     * @param  list<string>  $lines
     * @param  list<string>  $familyLines
     * @param  array<string, mixed>  $core
     * @param  list<array<string, mixed>>  $parentsAddresses
     */
    private function extractParentFamilyDetailsFromOrderedLines(array $lines, array $familyLines, array &$core, array &$parentsAddresses): void
    {
        $currentParent = null;
        $capturingParentAddress = false;
        $lastParentAddressIndex = null;
        $familyLineSet = [];
        foreach ($familyLines as $familyLine) {
            $familyLineSet[trim((string) $familyLine)] = true;
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            $isFamilyLine = isset($familyLineSet[$trimmed]);

            if ($this->isFatherLine($trimmed)) {
                $currentParent = 'father';
                $capturingParentAddress = false;
                $lastParentAddressIndex = null;
                $this->applyParentLine($trimmed, 'father', $core);
                continue;
            }

            if ($this->isMotherLine($trimmed)) {
                $currentParent = 'mother';
                $capturingParentAddress = false;
                $lastParentAddressIndex = null;
                $this->applyParentLine($trimmed, 'mother', $core);
                continue;
            }

            if ($currentParent === null && ! $isFamilyLine) {
                continue;
            }

            if (preg_match('/^#{1,6}\s+\S/u', $trimmed)) {
                $currentParent = null;
                $capturingParentAddress = false;
                $lastParentAddressIndex = null;
                continue;
            }

            if ($this->startsSiblingLine($trimmed)
                || (bool) preg_match('/^(?:दाजी|मामा|मावशी|माऊशी|आत्या|मुलाची\s+आत्या|चुलते|मुलाचे\s+मामा|आजोळ|इतर\s+नातेवाईक|नातेसंबंध|नाते\s+संबंध|मुलाचे\s+नां?व|मुलीचे\s+नां?व|जन्म|शिक्षण|जात|धर्म|उंची|देवक|रास|राशी|नक्षत्र|गण|चरण)'.self::LABEL_SUFFIX.'/u', $trimmed)) {
                $currentParent = null;
                $capturingParentAddress = false;
                $lastParentAddressIndex = null;
                continue;
            }

            if ($currentParent !== null && preg_match('/^(नोकरी\/व्यवसाय|नोकरी|व्यवसाय)\s*(?::\s*-\s*|[:>\-]\s*)(.+)$/u', $trimmed, $m)) {
                $this->setParentOccupationOrExtra($core, $currentParent, trim($m[1]), trim($m[2]));
                $capturingParentAddress = false;
                continue;
            }

            if ($currentParent !== null && preg_match_all('/\(([^()]*)\)/u', $trimmed, $matches)) {
                $capturedParenthetical = false;
                foreach ($matches[1] as $segment) {
                    $segment = $this->trimSeparators((string) $segment);
                    if ($segment === '') {
                        continue;
                    }
                    if (empty($core[$currentParent.'_occupation'] ?? null) && $this->looksLikeParentOccupationText($segment)) {
                        $core[$currentParent.'_occupation'] = $this->cleanOccupationText($segment);
                        $capturedParenthetical = true;

                        continue;
                    }
                    if ($this->looksLikeParentExtraInfoText($segment)) {
                        $core[$currentParent.'_extra_info'] = $this->setTextOnce($core[$currentParent.'_extra_info'] ?? null, $segment);
                        $capturedParenthetical = true;
                    }
                }
                if ($capturedParenthetical) {
                    continue;
                }
            }

            if ($currentParent !== null
                && empty($core[$currentParent.'_occupation'] ?? null)
                && preg_match('/^\((.+?)\)?$/u', $trimmed, $m)
                && $this->looksLikeParentOccupationText($m[1])) {
                $core[$currentParent.'_occupation'] = $this->cleanOccupationText($m[1]);
                continue;
            }

            if (($this->startsParentHomeAddressLine($trimmed) && ($currentParent !== null || $isFamilyLine))
                || ($isFamilyLine && $this->startsCandidateResidenceAddressLine($trimmed))) {
                $address = $this->labeledAddressValue($trimmed);
                if ($address !== '' && ($isFamilyLine || $currentParent !== null)) {
                    $typeKey = $this->parentAddressTypeFromLabelLine($trimmed, $address);
                    foreach ($this->splitNumberedAddressValues($address) as $index => $addressPart) {
                        $parentsAddresses[] = [
                            'type' => 'parents',
                            'address_type_key' => $typeKey,
                            'raw' => $index === 0 ? $trimmed : $addressPart,
                            'address_line' => $addressPart,
                        ];
                    }
                    $lastParentAddressIndex = array_key_last($parentsAddresses);
                    $capturingParentAddress = true;
                }
                continue;
            }

            if ($this->startsCandidateResidenceAddressLine($trimmed)) {
                $currentParent = null;
                $capturingParentAddress = false;
                $lastParentAddressIndex = null;
                continue;
            }

            if ($this->isParentMobileLine($trimmed)) {
                $phoneParent = $currentParent;
                if ($capturingParentAddress && empty($core['father_contact_1'] ?? null) && ! empty($core['father_name'] ?? null)) {
                    $phoneParent = 'father';
                }
                if ($phoneParent !== null && ($capturingParentAddress || $isFamilyLine || $this->looksLikeDirectParentPhoneLine($trimmed))) {
                    $this->assignParentPhones($core, $phoneParent, $this->extractPhones($trimmed));
                }
                $capturingParentAddress = false;
                $lastParentAddressIndex = null;
                continue;
            }

            if ($capturingParentAddress && $lastParentAddressIndex !== null && ! $this->looksLikeAnyKnownLabel($trimmed)) {
                if (preg_match('/^\s*(?:\d+|[०-९]+)[\).]\s*\S/u', $trimmed)) {
                    foreach ($this->splitNumberedAddressValues($trimmed) as $addressPart) {
                        $parentsAddresses[] = [
                            'type' => 'parents',
                            'address_type_key' => 'other',
                            'raw' => $addressPart,
                            'address_line' => $addressPart,
                        ];
                    }
                    $lastParentAddressIndex = array_key_last($parentsAddresses);

                    continue;
                }
                if ($this->looksLikeSeparateParentAddressLine($trimmed)) {
                    $parentsAddresses[] = [
                        'type' => 'parents',
                        'address_type_key' => 'other',
                        'raw' => $trimmed,
                        'address_line' => $trimmed,
                    ];
                    $lastParentAddressIndex = array_key_last($parentsAddresses);

                    continue;
                }
                $parentsAddresses[$lastParentAddressIndex]['address_line'] = $this->setTextOnce(
                    $parentsAddresses[$lastParentAddressIndex]['address_line'] ?? null,
                    $trimmed
                );
                $parentsAddresses[$lastParentAddressIndex]['raw'] = $this->setTextOnce(
                    $parentsAddresses[$lastParentAddressIndex]['raw'] ?? null,
                    $trimmed
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $core
     */
    private function applyParentLine(string $line, string $parent, array &$core): void
    {
        if (! preg_match('/^(?:पित्याचे|वडिलांचे|वडीलांचे|वकिलांचे|वडील|आईचे|मातेचे|आई)\s+नां?व?\s*(?::\s*-\s*|[:\-]\s*)(.+)$/u', $line, $m)
            && ! preg_match('/^(?:वडील|आई)\s*(?::\s*-\s*|[:\-]\s*)(.+)$/u', $line, $m)) {
            return;
        }

        $value = trim($m[1]);
        if ($parent === 'father' && preg_match('/^(.*?)\s+(आई(?:चे)?\s*(?:नां?व)?\s*(?::\s*-\s*|[:\-]\s*).+)$/u', $value, $inlineMother)) {
            $value = trim($inlineMother[1]);
            $this->applyParentLine($inlineMother[2], 'mother', $core);
        }

        [$name, $occupation, $phone] = $this->splitNameOccupation($value);
        $core[$parent.'_name'] = $name !== '' ? $name : ($core[$parent.'_name'] ?? null);
        if ($occupation !== null && $occupation !== '') {
            $core[$parent.'_occupation'] = $occupation;
        }
        $phones = $this->extractPhones($m[1]);
        if ($phone !== null && ! in_array($phone, $phones, true)) {
            array_unshift($phones, $phone);
        }
        $this->assignParentPhones($core, $parent, $phones);
    }

    private function isFatherLine(string $line): bool
    {
        return (bool) preg_match('/^(?:पित्याचे|वडिलांचे|वडीलांचे|वकिलांचे|वडील)\s+नां?व?'.self::LABEL_SUFFIX.'/u', $line)
            || (bool) preg_match('/^वडील\s*(?::\s*-\s*|[:\-])/u', $line);
    }

    private function isMotherLine(string $line): bool
    {
        return (bool) preg_match('/^(?:आईचे|मातेचे|आई)\s+नां?व?'.self::LABEL_SUFFIX.'/u', $line)
            || (bool) preg_match('/^आई\s*(?::\s*-\s*|[:\-])/u', $line);
    }

    private function isParentMobileLine(string $line): bool
    {
        return (bool) preg_match('/^(?:मोबा\.?|मो\.?\s*नं\.?|मोबाईल|मोबाइल|मोबाईल\s+नंबर|मोबाइल\s+नंबर|फोन\s*नं\.?|फोन\s+नंबर|फोन|संपर्क)'.self::LABEL_SUFFIX.'/u', $line);
    }

    private function looksLikeDirectParentPhoneLine(string $line): bool
    {
        return (bool) preg_match('/^(?:मोबा\.?|फोन\s*नं\.?|फोन\s+नंबर|फोन|मोबाईल|मोबाइल)\s*(?::\s*-\s*|[:\-]\s*)/u', $line)
            && ! (bool) preg_match('/^(?:मोबाईल|मोबाइल)\s+(?:नं\.?|नंबर)\s*(?::\s*-\s*|[:\-]\s*)/u', $line);
    }

    private function startsCandidateResidenceAddressLine(string $line): bool
    {
        return (bool) preg_match('/^(?:सध्याचा\s+पत्ता|निवासी\s+पत्ता|पता)'.self::LABEL_SUFFIX.'/u', trim($line));
    }

    private function startsParentHomeAddressLine(string $line): bool
    {
        return (bool) preg_match('/^(?:घरचा\s+पत्ता|घराचा\s+पत्ता|घर\s+पत्ता|गावचा\s+पत्ता|मुळगाव|मूळगाव|पत्ता)'.self::LABEL_SUFFIX.'/u', trim($line));
    }

    private function startsParentScopedAddressLine(string $line): bool
    {
        return (bool) preg_match('/^(?:घरचा\s+पत्ता|घराचा\s+पत्ता|घर\s+पत्ता|मुळगाव|मूळगाव|पता)'.self::LABEL_SUFFIX.'/u', trim($line));
    }

    private function shouldRouteAddressToParents(string $line, string $address): bool
    {
        if (preg_match('/^(?:घरचा\s+पत्ता|घराचा\s+पत्ता|घर\s+पत्ता)'.self::LABEL_SUFFIX.'/u', trim($line))) {
            return true;
        }

        return (bool) preg_match('/(?:flat|house|building|apartment|society|road|landmark|colony|फ्लॅट|फ्लाट|घर|बिल्डिंग|अपार्टमेंट|सोसायटी|रोड|रस्ता|कॉलनी|नगर)/ui', $address);
    }

    private function looksLikeSeparateParentAddressLine(string $line): bool
    {
        $line = trim($line);
        if ($line === '') {
            return false;
        }

        return (bool) preg_match('/^(?:\d+|[०-९]+)(?:\)|\.)?\s*,?\s*(?:[A-Za-zअ-ह])/u', $line)
            && (bool) preg_match('/(?:वॉर्ड|वार्ड|पेठ|नगर|कॉलनी|सोसायटी|फ्लॅट|रोड|रस्ता|मु\.?\s*पो\.?|ता\.|जि\.)/u', $line);
    }

    private function parentAddressTypeFromLabelLine(string $line, string $address): string
    {
        if (preg_match('/^(?:सध्याचा\s+पत्ता|निवासी\s+पत्ता|पता|घरचा\s+पत्ता|घराचा\s+पत्ता|घर\s+पत्ता)'.self::LABEL_SUFFIX.'/u', trim($line))) {
            return 'current';
        }
        if (preg_match('/^(?:मुळगाव|मूळगाव|गावचा\s+पत्ता|पत्ता)'.self::LABEL_SUFFIX.'/u', trim($line))
            || preg_match('/(?:मु\.?\s*पो\.?|मू\.?\s*पो\.?)/u', $address)) {
            return 'permanent';
        }

        return 'current';
    }

    private function looksLikeParentOccupationText(string $value): bool
    {
        return (bool) preg_match('/सेवानिवृत्त|नोकरी|व्यवसाय|व्यावसायिक|सुपरवायझर|कारखाना|फॅक्टरी|कंपनी|शिक्षक|शिक्षिका|गृहिणी|Retired|Factory|Company|Supervisor|Business|Farming|शेती/ui', $value);
    }

    private function looksLikeParentExtraInfoText(string $value): bool
    {
        return (bool) preg_match('/\b(?:B\.?\s*A|B\.?\s*Com|B\.?\s*Sc|B\.?\s*E|M\.?\s*A|M\.?\s*Com|M\.?\s*Sc|M\.?\s*Tech|MBA|BBA|BCOM|BSC|BE|ME|MTECH|Diploma|ITI)\b/ui', $value);
    }

    /**
     * @param  array<string, mixed>  $core
     */
    private function setParentOccupationOrExtra(array &$core, string $parent, string $label, string $value): void
    {
        if ($value === '') {
            return;
        }
        $occupationKey = $parent.'_occupation';
        $extraKey = $parent.'_extra_info';
        $normalizedLabel = trim($label);

        if ($normalizedLabel === 'नोकरी' || $normalizedLabel === 'व्यवसाय') {
            $core[$occupationKey] = $normalizedLabel;
            $core[$extraKey] = $this->setTextOnce($core[$extraKey] ?? null, $value);

            return;
        }

        $currentOccupation = trim((string) ($core[$occupationKey] ?? ''));
        if ($currentOccupation === '' || $currentOccupation === 'नोकरी' || $currentOccupation === 'व्यवसाय') {
            $core[$occupationKey] = $value;
        } else {
            $core[$extraKey] = $this->setTextOnce($core[$extraKey] ?? null, $value);
        }
    }

    /**
     * @param  array<string, mixed>  $core
     * @param  list<string>  $phones
     */
    private function assignParentPhones(array &$core, string $parent, array $phones): void
    {
        $slot = 1;
        $existing = [];
        for ($i = 1; $i <= 3; $i++) {
            $value = trim((string) ($core[$parent.'_contact_'.$i] ?? ''));
            if ($value !== '') {
                $existing[$value] = true;
                $slot = $i + 1;
            }
        }
        foreach ($phones as $phone) {
            if ($phone === '' || isset($existing[$phone])) {
                continue;
            }
            while ($slot <= 3 && trim((string) ($core[$parent.'_contact_'.$slot] ?? '')) !== '') {
                $slot++;
            }
            if ($slot > 3) {
                break;
            }
            $core[$parent.'_contact_'.$slot] = $phone;
            $existing[$phone] = true;
            $slot++;
        }
        if (empty($core[$parent.'_contact_number'] ?? null)) {
            $core[$parent.'_contact_number'] = $core[$parent.'_contact_1'] ?? null;
        }
    }

    /**
     * @param  array<string, mixed>  $core
     */
    private function syncParentContactAliases(array &$core): void
    {
        foreach (['father', 'mother'] as $parent) {
            if (! empty($core[$parent.'_contact_number'] ?? null) && empty($core[$parent.'_contact_1'] ?? null)) {
                $core[$parent.'_contact_1'] = $core[$parent.'_contact_number'];
            }
            if (! empty($core[$parent.'_contact_1'] ?? null) && empty($core[$parent.'_contact_number'] ?? null)) {
                $core[$parent.'_contact_number'] = $core[$parent.'_contact_1'];
            }
        }
    }

    /**
     * Orphan biodata phone numbers should still be visible in preview.
     * Per user rule, parent contact slots must stay tied to explicit parent lines only.
     * Unknown phones should use generic self/user preview slots and must not backfill parents.
     *
     * @param  array<string, mixed>  $core
     * @param  list<string>  $phones
     */
    private function routeOrphanPhonesToPreviewContactSlots(array &$core, array $phones): void
    {
        $seen = [];
        $parentPhones = $this->parentContactPhones($core);
        foreach ([
            'father_contact_number', 'father_contact_1', 'father_contact_2', 'father_contact_3',
            'primary_contact_number', 'primary_contact_number_2', 'primary_contact_number_3',
            'mother_contact_number', 'mother_contact_1', 'mother_contact_2', 'mother_contact_3',
        ] as $field) {
            $value = trim((string) ($core[$field] ?? ''));
            if ($value !== '') {
                $seen[$value] = true;
            }
        }

        foreach ($phones as $phone) {
            if ($phone === '' || isset($seen[$phone]) || isset($parentPhones[$phone])) {
                continue;
            }
            if ($this->assignPreviewPhoneToSlots($core, ['primary_contact_number_2', 'primary_contact_number_3'], $phone)) {
                $seen[$phone] = true;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $core
     * @param  list<string>  $slots
     */
    private function assignPreviewPhoneToSlots(array &$core, array $slots, string $phone): bool
    {
        foreach ($slots as $slot) {
            $current = trim((string) ($core[$slot] ?? ''));
            if ($current === '') {
                $core[$slot] = $phone;

                return true;
            }
            if ($current === $phone) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $core
     */
    private function dedupePreviewContactSlotsAgainstParents(array &$core): void
    {
        $parentPhones = $this->parentContactPhones($core);
        $slots = ['primary_contact_number_2', 'primary_contact_number_3'];
        $values = [];
        $primary = trim((string) ($core['primary_contact_number'] ?? ''));

        foreach ($slots as $slot) {
            $phone = trim((string) ($core[$slot] ?? ''));
            if ($phone === '' || isset($parentPhones[$phone]) || ($primary !== '' && $phone === $primary)) {
                continue;
            }
            if (! in_array($phone, $values, true)) {
                $values[] = $phone;
            }
        }

        foreach ($slots as $index => $slot) {
            $core[$slot] = $values[$index] ?? null;
        }
    }

    /**
     * @param  array<string, mixed>  $core
     * @return array<string, true>
     */
    private function parentContactPhones(array $core): array
    {
        $phones = [];
        foreach (['father', 'mother'] as $parent) {
            foreach (['contact_number', 'contact_1', 'contact_2', 'contact_3'] as $suffix) {
                $phone = trim((string) ($core[$parent.'_'.$suffix] ?? ''));
                if ($phone !== '') {
                    $phones[$phone] = true;
                }
            }
        }

        return $phones;
    }

    /**
     * @param  list<array<string, mixed>>  $addresses
     * @param  list<array<string, mixed>>  $parentsAddresses
     * @return list<array<string, mixed>>
     */
    private function removeParentAddressDuplicates(array $addresses, array $parentsAddresses): array
    {
        return $addresses;
    }

    /**
     * @param  list<array<string, mixed>>  $addresses
     */
    private function extractAddressLine(string $line, array &$addresses, ?string $previousLine = null): void
    {
        if (! preg_match('/^(घरचा\s+पत्ता|घराचा\s+पत्ता|घर\s+पत्ता|सध्याचा\s+पत्ता|निवासी\s+पत्ता|गावचा\s+पत्ता|मुळगाव|मुळ\s+गाव|मूळगाव|मूळ\s+गाव|निवास|रहिवास|पत्ता|पता)\s*(?::\s*-\s*|[:\-–—]\s*)(.+)$/u', $line, $m)) {
            return;
        }
        $label = trim($m[1]);
        $address = preg_split('/\s+(?:मोबाईल|मोबाइल|संपर्क|प्रोपर्टी|प्रॉपर्टी|स्थावर|कौटुंबिक)'.self::LABEL_SUFFIX.'/u', trim($m[2]), 2)[0] ?? trim($m[2]);
        foreach ($this->splitNumberedAddressValues(trim($address)) as $addressPart) {
            $addresses[] = [
                'type' => $this->addressTypeFromLabel($label, $addressPart),
                'raw' => $addressPart,
                'address_line' => $addressPart,
            ];
        }
    }

    /**
     * @return list<string>
     */
    private function splitNumberedAddressValues(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        $parts = preg_split('/(?:(?<=^)|(?<=\s)|(?<=,))(?=(?:\d{1,2}|[०-९]{1,2})[\).]\s*\D)/u', $value) ?: [];
        if (count($parts) <= 1) {
            return [$value];
        }

        return array_values(array_filter(array_map(function (string $part): string {
            $part = preg_replace('/^\s*(?:\d+|[०-९]+)[\).]\s*/u', '', $part) ?? $part;

            return $this->trimSeparators($part);
        }, $parts)));
    }

    /**
     * @param  list<array<string, mixed>>  $addresses
     */
    private function extractStandaloneAddressLine(string $line, array &$addresses, ?string $previousLine = null): void
    {
        $line = trim($line);
        if ($line === '' || $previousLine === null) {
            return;
        }
        if (! preg_match('/^(?:कायमचा\s+पत्ता|पत्ता|पता)$/u', trim($previousLine))) {
            return;
        }
        if (! preg_match('/(?:मु\.?\s*पो\.?|A\/P|ता\.|तालुका|जि\.|जिल्हा|Dist|Tal)/ui', $line)) {
            return;
        }
        if ($this->addressAlreadyExists($addresses, $line)) {
            return;
        }
        $addresses[] = [
            'type' => 'native',
            'raw' => $line,
            'address_line' => $line,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $addresses
     */
    private function extractUnlabeledNativeAddressLine(string $line, array &$addresses): void
    {
        $line = trim($line);
        if ($line === '' || ! preg_match('/^मु\.?\s*पो\.?/u', $line)) {
            return;
        }
        if (preg_match('/^(?:श्री|सौ|कै|डॉ|कु)\.?/u', $line) || $this->addressAlreadyExists($addresses, $line)) {
            return;
        }
        $addresses[] = [
            'type' => 'native',
            'raw' => $line,
            'address_line' => $line,
        ];
    }

    private function addressTypeFromLabel(string $label, string $address): string
    {
        if (preg_match('/^(?:सध्याचा\s+पत्ता|निवासी\s+पत्ता|निवास|रहिवास)$/u', $label)) {
            return 'current';
        }
        if (preg_match('/^पता$/u', $label)
            && ! preg_match('/(?:मु\.?\s*पो\.?|ता\.|तालुका|जि\.|जिल्हा|गाव|वाडी)/u', $address)) {
            return 'current';
        }

        return 'native';
    }

    private function startsSiblingLine(string $line): bool
    {
        return (bool) preg_match('/^(?:भाऊ|बहीण|बहिण|बहिणी|मुलाचा\s+भाऊ|मुलाचे\s+भाऊ|मुलाची\s+बहीण|मुलाची\s+बहिण)\s*(?::\s*-\s*|[:\-–—]\s*)/u', trim($line));
    }

    private function startsSiblingSpouseLine(string $line): bool
    {
        return (bool) preg_match('/^(?:भावजय|वहिनी|वाहिनी|भाऊजी|भावजी)\s*(?::\s*-\s*|[:\-–—]\s*)/u', trim($line));
    }

    private function startsSisterSpouseLine(string $line): bool
    {
        return (bool) preg_match('/^\s*[-–—]?\s*(?:जावई|दाजी)\s*(?::\s*-\s*|[:\-–—]|\s|$)/u', trim($line));
    }

    /**
     * @param  list<string>  $lines
     * @param  list<array<string, mixed>>  $addresses
     */
    private function extractEnglishAddresses(array $lines, array &$addresses): void
    {
        foreach ($lines as $index => $line) {
            if (mb_strtolower(trim($line)) !== 'address') {
                continue;
            }
            $parts = [];
            for ($i = $index + 1; $i < count($lines); $i++) {
                $part = trim($lines[$i]);
                if ($part === '' || preg_match('/^(?:Contact|Mobile|Phone|##\s+)/iu', $part)) {
                    break;
                }
                $parts[] = $part;
            }
            $address = trim(implode(' ', $parts));
            if ($address === '' || $this->addressAlreadyExists($addresses, $address)) {
                continue;
            }
            $addresses[] = [
                'type' => 'native',
                'raw' => $address,
                'address_line' => $address,
            ];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $addresses
     */
    private function addressAlreadyExists(array $addresses, string $address): bool
    {
        foreach ($addresses as $row) {
            if (is_array($row) && trim((string) ($row['address_line'] ?? '')) === $address) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $propertyAssets
     */
    private function extractPropertyLine(string $line, mixed &$propertySummary, array &$propertyAssets, ?array $continuationContext = null): ?array
    {
        if ($this->startsAddressLine($line)) {
            return null;
        }
        if (preg_match('/^\((.+)\)$/u', trim($line), $m) && $this->looksLikeParentOccupationText($m[1])) {
            return null;
        }
        if ($this->looksLikeAnyKnownLabel($line) && ! $this->startsPropertyLine($line)) {
            return null;
        }
        if ($this->startsPersonRelativeLine($line) && ! $this->startsPropertyLine($line)) {
            return null;
        }
        if ($continuationContext !== null && $this->isPropertyContinuationLine($line)) {
            $text = trim($line);
            $propertySummary = [
                'summary_text' => trim(((string) ($propertySummary['summary_text'] ?? '')).' '.$text),
            ];

            $asset = $this->propertyAssetFromInheritedDescriptor($text, $continuationContext);
            if ($asset !== null) {
                $propertyAssets[] = $asset;
            }

            return $continuationContext;
        }
        $isProperty = $this->startsPropertyLine($line) || $this->containsPropertyOwnershipSignal($line);
        if (! $isProperty) {
            return null;
        }
        $text = trim($line);
        $propertySummary = [
            'summary_text' => trim(((string) ($propertySummary['summary_text'] ?? '')).' '.$text),
        ];
        $descriptorContext = $this->propertyContinuationDescriptorContext($text);

        foreach ($this->propertyAssetsFromText($text, $descriptorContext) as $asset) {
            $propertyAssets[] = $asset;
        }

        return $descriptorContext;
    }

    private function startsPersonRelativeLine(string $line): bool
    {
        return (bool) preg_match('/^\s*(?:(?:\d+|[०-९]+)[\).]\s*)?(?:श्री\.?|कै\.?|सौ\.?|चि\.?|कु\.?|डॉ\.?)\s*[\p{L}\p{M}.]+/u', trim($line));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function propertyAssetsFromText(string $text, ?array $descriptorContext = null): array
    {
        $value = $this->stripPropertyLabel($text);
        if ($value === '') {
            return [];
        }

        $segments = $this->splitPropertyAssetSegments($value);
        if ($descriptorContext !== null && $segments !== []
            && trim((string) $segments[0]) === trim((string) ($descriptorContext['descriptor'] ?? ''))) {
            array_shift($segments);
        }
        $assets = [];
        foreach ($segments as $segment) {
            $asset = $descriptorContext !== null && $this->propertySegmentCanInheritDescriptor($segment)
                ? $this->propertyAssetFromInheritedDescriptor($segment, $descriptorContext)
                : $this->propertyAssetFromSegment($segment);
            if ($asset !== null) {
                $assets[] = $asset;
            }
        }

        if ($assets === [] && $this->propertyAssetTypeKey($value) !== null) {
            $asset = $this->propertyAssetFromSegment($value);
            if ($asset !== null) {
                $assets[] = $asset;
            }
        }

        return $assets;
    }

    private function stripPropertyLabel(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/^\s*[-–—]?\s*(?:प्रॉपर्टी|प्रोपर्टी|स्थावर\s*मिळकत|स्थायिक\s*मालमत्ता|मालमत्ता|स्थावर|शेती|जमीन|स्वता:ची\s+मालमत्ता|स्वताची\s+मालमत्ता|स्वतःची\s+मालमत्ता|स्वत[:ः]?ची\s+मालमत्ता)\s*(?::\s*-\s*|[:\-–—]\s*)/u', '', $text) ?? $text;

        return $this->trimSeparators($text);
    }

    /**
     * @return list<string>
     */
    private function splitPropertyAssetSegments(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        $parts = preg_split('/(?:\R|\s+)(?=(?:\d+|[०-९]+)[\).]\s*)/u', $value) ?: [];
        if (count($parts) <= 1) {
            $parts = preg_split('/\s+(?=(?:\d+\s*BHK|[0-9०-९]+\s*(?:एकर|acre|acres)|(?:Land|सोने|Gold|Vehicle|Car|Bike)\b))/ui', $value) ?: [];
        }
        if (count($parts) <= 1 && str_contains($value, '/')) {
            $parts = preg_split('/\s*\/\s*/u', $value) ?: [];
        }

        return array_values(array_filter(array_map(
            fn (string $part): string => $this->trimSeparators(preg_replace('/^\s*(?:\d+|[०-९]+)[\).]\s*/u', '', $part) ?? $part),
            $parts
        )));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function propertyAssetFromSegment(string $segment): ?array
    {
        $segment = $this->trimSeparators($segment);
        if ($segment === '') {
            return null;
        }

        $assetType = $this->propertyAssetTypeKey($segment);
        if ($assetType === null) {
            return [
                'asset_type_key' => 'other',
                'asset_type_label' => 'Other',
                'notes' => $segment,
            ];
        }

        $ownershipType = $this->propertyOwnershipTypeKey($segment);
        [$location, $notes] = $this->propertyLocationAndNotes($segment, $assetType, $ownershipType);

        $asset = [
            'asset_type_key' => $assetType,
            'asset_type_label' => $this->propertyAssetTypeLabel($assetType),
        ];
        if ($location !== '') {
            $asset['location'] = $location;
        }
        if ($ownershipType !== null) {
            $asset['ownership_type_key'] = $ownershipType;
            $asset['ownership_type_label'] = $this->propertyOwnershipTypeLabel($ownershipType);
        }
        if ($notes !== '') {
            $asset['notes'] = $notes;
        }

        return $asset;
    }

    private function propertyAssetTypeKey(string $value): ?string
    {
        return match (true) {
            (bool) preg_match('/(?:जमीन|शेती|बागायत|एकर|acre|acres|\bland\b)/ui', $value) => 'land',
            (bool) preg_match('/(?:घर|फ्लॅट|फ्लाट|flat|bhk|apartment|bungalow|row\s*house)/ui', $value) => 'house',
            (bool) preg_match('/(?:वाहन|गाडी|कार|बाईक|bike|car|vehicle|four\s*wheeler|two\s*wheeler)/ui', $value) => 'vehicle',
            (bool) preg_match('/(?:सोने|दागिने|gold|jewellery|jewelry)/ui', $value) => 'gold',
            (bool) preg_match('/(?:FD|fixed\s*deposit|bank|share|shares|mutual\s*fund|financial|आर्थिक|ठेव|बँक)/ui', $value) => 'financial',
            default => null,
        };
    }

    private function propertyOwnershipTypeKey(string $value): ?string
    {
        return match (true) {
            (bool) preg_match('/(?:संयुक्त|joint)/ui', $value) => 'joint',
            (bool) preg_match('/(?:कौटुंबिक|वडिलोपार्जित|family|ancestral)/ui', $value) => 'family',
            (bool) preg_match('/(?:स्वत[:ः]?च(?:े|्या)|स्व[:ः]?ताच्या|स्वतःचे|मालकीच(?:े|्या)|own|owned|sole)/ui', $value) => 'sole',
            default => null,
        };
    }

    private function propertyAssetTypeLabel(string $key): string
    {
        return [
            'land' => 'Land',
            'house' => 'House',
            'vehicle' => 'Vehicle',
            'gold' => 'Gold',
            'financial' => 'Financial',
            'other' => 'Other',
        ][$key] ?? 'Other';
    }

    private function propertyOwnershipTypeLabel(string $key): string
    {
        return [
            'sole' => 'Sole',
            'joint' => 'Joint',
            'family' => 'Family',
            'other' => 'Other',
        ][$key] ?? 'Other';
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function propertyLocationAndNotes(string $segment, string $assetType, ?string $ownershipType): array
    {
        $notes = $segment;
        $location = '';

        if (preg_match('/\(([^()]*(?:ता\.?|जि\.?|मु\.?\s*पो\.?|पुणे|मुंबई|ठाणे|सांगली|सातारा|कोल्हापूर|सोलापूर|नगर|गाव|वाडी|रोड)[^()]*)\)/u', $segment, $m)
            && $this->propertyOwnershipTypeKey($m[1]) === null) {
            $location = $this->trimSeparators($m[1]);
            $notes = trim(str_replace($m[0], '', $notes));
        } elseif (preg_match('/[,;]\s*((?:मु\.?\s*पो\.?\s*)?[\p{L}\p{M}\s.,]+(?:ता\.?|जि\.?|पुणे|मुंबई|ठाणे|सांगली|सातारा|कोल्हापूर|सोलापूर|नगर|गाव|वाडी|रोड).*)$/u', $segment, $m)) {
            $location = $this->trimSeparators($m[1]);
            $notes = trim(str_replace($m[1], '', $notes));
        } elseif (preg_match('/^(.+?)\s+((?:पुणे|मुंबई|ठाणे|सांगली|सातारा|कोल्हापूर|सोलापूर|नगर))$/u', $segment, $m)) {
            $location = $this->trimSeparators($m[2]);
            $notes = trim($m[1]);
        }

        $notes = preg_replace('/(?:स्वत[:ः]?च(?:े|्या)|स्व[:ः]?ताच्या|स्वतःचे|मालकीच(?:े|्या)|संयुक्त|कौटुंबिक|वडिलोपार्जित|own|owned|sole|joint|family|ancestral)/ui', '', $notes) ?? $notes;
        $notes = $this->trimSeparators($notes);
        $notesOnlyAssetWord = preg_replace('/[().\s]+/u', '', $notes) ?? $notes;
        if (in_array(mb_strtolower($notesOnlyAssetWord), ['घर', 'फ्लॅट', 'फ्लाट', 'flat', 'land', 'जमीन', 'शेती'], true)) {
            $notes = '';
        }

        return [$location, $notes];
    }

    /**
     * @return array{descriptor: string, asset_type_key: string, asset_type_label: string, ownership_type_key?: string, ownership_type_label?: string}|null
     */
    private function propertyContinuationDescriptorContext(string $text): ?array
    {
        $value = $this->stripPropertyLabel($text);
        if ($value === '' || ! preg_match('/(?:^|\s)(?:\d+|[०-९]+)[\).]\s*/u', $value)) {
            return null;
        }

        $segments = $this->splitPropertyAssetSegments($value);
        if (count($segments) < 2) {
            return null;
        }

        $descriptor = trim((string) ($segments[0] ?? ''));
        $assetType = $this->propertyAssetTypeKey($descriptor);
        if ($descriptor === '' || $assetType === null || $this->looksLikePropertyLocationText($descriptor)) {
            return null;
        }

        $context = [
            'descriptor' => $descriptor,
            'asset_type_key' => $assetType,
            'asset_type_label' => $this->propertyAssetTypeLabel($assetType),
        ];

        $ownershipType = $this->propertyOwnershipTypeKey($descriptor);
        if ($ownershipType !== null) {
            $context['ownership_type_key'] = $ownershipType;
            $context['ownership_type_label'] = $this->propertyOwnershipTypeLabel($ownershipType);
        }

        return $context;
    }

    private function propertySegmentCanInheritDescriptor(string $segment): bool
    {
        $segment = $this->trimSeparators(preg_replace('/^\s*(?:\d+|[०-९]+)[\).]\s*/u', '', $segment) ?? $segment);

        return $segment !== ''
            && $this->propertyAssetTypeKey($segment) === null
            && $this->propertyOwnershipTypeKey($segment) === null
            && $this->looksLikePropertyLocationText($segment);
    }

    /**
     * @param  array{descriptor: string, asset_type_key: string, asset_type_label: string, ownership_type_key?: string, ownership_type_label?: string}  $descriptorContext
     * @return array<string, mixed>|null
     */
    private function propertyAssetFromInheritedDescriptor(string $segment, array $descriptorContext): ?array
    {
        $location = $this->trimSeparators(preg_replace('/^\s*(?:\d+|[०-९]+)[\).]\s*/u', '', $segment) ?? $segment);
        if ($location === '') {
            return null;
        }

        $asset = [
            'asset_type_key' => $descriptorContext['asset_type_key'],
            'asset_type_label' => $descriptorContext['asset_type_label'],
            'location' => $location,
        ];

        if (! empty($descriptorContext['ownership_type_key']) && ! empty($descriptorContext['ownership_type_label'])) {
            $asset['ownership_type_key'] = $descriptorContext['ownership_type_key'];
            $asset['ownership_type_label'] = $descriptorContext['ownership_type_label'];
        }

        return $asset;
    }

    private function isPropertyContinuationLine(string $line): bool
    {
        if ($line === '' || $this->looksLikeAnyKnownLabel($line) || $this->startsContactLine($line)) {
            return false;
        }

        return (bool) preg_match('/^\s*(?:\d+|[०-९]+)[\).]\s*/u', $line)
            || $this->looksLikePropertyLocationText($line);
    }

    private function looksLikePropertyLocationText(string $value): bool
    {
        return (bool) preg_match('/(?:,|मु\.?\s*पो\.?|ता\.?|जि\.?|पेठ|वॉर्ड|नगर|गाव|वाडी|रोड|रस्ता|कॉलनी|सोसायटी|अपार्टमेंट|होम्स|हॉम्स|पुणे|मुंबई|ठाणे|सांगली|सातारा|कोल्हापूर|सोलापूर|अहमदनगर|नाशिक)/u', trim($value));
    }

    private function extractHoroscopeLine(string $line, mixed &$horoscope): void
    {
        $tableSegments = $this->extractMarkdownHoroscopeTableSegments($line);
        if ($tableSegments !== []) {
            $horoscope = is_array($horoscope) ? $horoscope : ['raw' => []];
            foreach ($tableSegments as $field => $value) {
                $normalizedValue = $this->normalizeHoroscopeFieldValue($field, $value);
                if ($normalizedValue !== null && ! $this->horoscopeValueLooksPolluted($normalizedValue)) {
                    $horoscope[$field] = $normalizedValue;
                }
            }

            return;
        }

        if (preg_match('/^वर्ण'.self::LABEL_SUFFIX.'/u', $line)
            && ($complexion = $this->extractLabeledValue($line, ['वर्ण'])) !== null
            && $this->looksLikeComplexion($this->cleanComplexionValue($complexion))) {
            return;
        }

        if (! preg_match('/^(?:रास|राशी|जन्मरास|रास\s*नाव|राशी\s*नाव|नावास\s*नाव|नावरस\s*नाव|नक्षत्र|जन्मनक्षत्र|देवक|कुल\s*दैवत|कुलदैवत|कुलदेवत|कलदैवत|कुलस्वामी|कुळस्वामी|नाड|नाडी|गण|चरण|गोत्र|योनी|वर्ण|वश्य|वैरवर्ग|राशी\s*स्वामी|रास\s*स्वामी|स्वामी|योग|मंगळ(?:िक|दोष)?|नावरस|जन्मवार\s*आणि\s*वेळ|जन्मवार\s*व\s*वेळ)'.self::LABEL_SUFFIX.'/u', $line)) {
            return;
        }
        $horoscope = is_array($horoscope) ? $horoscope : ['raw' => []];
        $horoscope['raw'][] = $line;
        foreach ($this->extractHoroscopeSegments($line) as $field => $value) {
            $normalizedValue = $this->normalizeHoroscopeFieldValue($field, $value);
            if ($normalizedValue !== null && ! $this->horoscopeValueLooksPolluted($normalizedValue)) {
                $horoscope[$field] = $normalizedValue;
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function extractMarkdownHoroscopeTableSegments(string $line): array
    {
        $trimmed = trim($line);
        if (! str_contains($trimmed, '|')) {
            return [];
        }

        $cells = array_values(array_filter(array_map(
            fn (string $cell): string => trim($cell),
            explode('|', trim($trimmed, " \t\n\r\0\x0B|"))
        ), static fn (string $cell): bool => $cell !== '' && ! preg_match('/^:?-{2,}:?$/', $cell)));

        if (count($cells) < 2) {
            return [];
        }

        $labelToField = [
            'रास' => 'rashi',
            'राशी' => 'rashi',
            'गण' => 'gan',
            'नक्षत्र' => 'nakshatra',
            'चरण' => 'charan',
            'देवक' => 'devak',
        ];

        $segments = [];
        for ($i = 0; $i < count($cells); $i += 2) {
            $label = $cells[$i] ?? '';
            $value = $cells[$i + 1] ?? '';
            $field = $labelToField[$label] ?? null;
            if ($field === null || $value === '') {
                continue;
            }

            $segments[$field] = $value;
        }

        return $segments;
    }

    /**
     * @return array<string, string>
     */
    private function extractHoroscopeSegments(string $line): array
    {
        $labelToField = [
            'जन्मवार आणि वेळ' => 'birth_weekday',
            'जन्मवार व वेळ' => 'birth_weekday',
            'राशी स्वामी' => 'rashi_lord',
            'रास स्वामी' => 'rashi_lord',
            'जन्मरास' => 'rashi',
            'राशी नाव' => 'navras_name',
            'रास नाव' => 'navras_name',
            'नावास नाव' => 'navras_name',
            'नावरस नांव' => 'navras_name',
            'नावरस नाव' => 'navras_name',
            'नावरस' => 'navras_name',
            'जन्मनक्षत्र' => 'nakshatra',
            'कुल दैवत' => 'kuldaivat',
            'कुलदैवत' => 'kuldaivat',
            'कुलदेवत' => 'kuldaivat',
            'कलदैवत' => 'kuldaivat',
            'कुलस्वामी' => 'kuldaivat',
            'कुळस्वामी' => 'kuldaivat',
            'मंगळिक' => 'mangal_dosh_type',
            'मंगळ दोष' => 'mangal_dosh_type',
            'नक्षत्र' => 'nakshatra',
            'देवक' => 'devak',
            'नाड' => 'nadi',
            'नाडी' => 'nadi',
            'गण' => 'gan',
            'चरण' => 'charan',
            'गोत्र' => 'gotra',
            'योनी' => 'yoni',
            'वर्ण' => 'varna',
            'वश्य' => 'vashya',
            'वैरवर्ग' => 'vashya',
            'योग' => 'yog',
            'राशी' => 'rashi',
            'रास' => 'rashi',
            'स्वामी' => 'rashi_lord',
        ];

        $labels = array_keys($labelToField);
        usort($labels, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $escaped = array_map(static fn (string $label): string => preg_quote($label, '/'), $labels);
        $pattern = '/(?P<prefix>^|[\s,;|])(?P<label>'.implode('|', $escaped).')\s*(?::\s*-\s*|[:\-–—]\s*)/u';

        preg_match_all($pattern, $line, $matches, PREG_OFFSET_CAPTURE);

        $segments = [];
        $fullMatches = $matches[0] ?? [];
        $labelMatches = $matches['label'] ?? [];
        foreach ($fullMatches as $index => $fullMatch) {
            $fullText = $fullMatch[0] ?? '';
            $fullStart = $fullMatch[1] ?? null;
            $label = $labelMatches[$index][0] ?? null;
            if (! is_int($fullStart) || ! is_string($label) || $label === '') {
                continue;
            }

            $valueStart = $fullStart + strlen($fullText);
            $nextStart = isset($fullMatches[$index + 1][1]) && is_int($fullMatches[$index + 1][1])
                ? $fullMatches[$index + 1][1]
                : strlen($line);
            $value = substr($line, $valueStart, $nextStart - $valueStart);
            $value = trim($value);
            if ($value === '') {
                continue;
            }

            $field = $labelToField[$label] ?? null;
            if ($field === null) {
                continue;
            }

            $segments[$field] = $value;
        }

        if (isset($segments['nakshatra']) && ! isset($segments['charan'])) {
            $nakshatraValue = trim((string) $segments['nakshatra']);
            if (preg_match('/^(.+?)\s+([०-९0-9]+)$/u', $nakshatraValue, $m)) {
                $segments['nakshatra'] = trim($m[1]);
                $segments['charan'] = trim($m[2]);
            }
        }

        return $segments;
    }

    private function normalizeHoroscopeFieldValue(string $field, string $value): ?string
    {
        $value = trim($value);
        $value = trim(preg_replace('/^[,;:.\-|–—\s]+|[,;:.\-|–—\s]+$/u', '', $value) ?? $value);
        if ($value === '') {
            return null;
        }

        if ($field === 'birth_weekday') {
            if (preg_match('/(सोमवार|मंगळवार|बुधवार|गुरुवार|शुक्रवार|शनिवार|रविवार)/u', $value, $weekday)) {
                return $weekday[1];
            }

            return null;
        }

        if ($field === 'navras_name') {
            return \App\Services\BiodataParserService::sanitizeNavrasDisplayText($value) ?? $this->trimSeparators($value);
        }

        if ($field === 'rashi') {
            return \App\Services\BiodataParserService::sanitizeRashiDisplayText($value) ?? $this->trimSeparators($value);
        }

        $value = $this->trimSeparators($value);
        $value = preg_split(
            '/\s+(?:ब्लड\s*ग्रुप|ब्लड\s*ग्रप|रक्तगट|रक्त\s*गट|रक[\x{094D}\x{200C}\s]*त\s*गट|कुंची|उंची|height|मोबाईल|मोबाइल|संपर्क|प्रोपर्टी|प्रॉपर्टी|स्थावर|घरचा\s+पत्ता|सध्याचा\s+पत्ता)'.self::LABEL_SUFFIX.'/ui',
            $value,
            2
        )[0] ?? $value;
        $value = $this->trimSeparators($value);
        $value = trim(preg_replace('/[.।]+$/u', '', $value) ?? $value);

        if ($field === 'varna' && $this->looksLikeComplexion($value)) {
            return null;
        }

        $fieldAliases = [
            'nakshatra' => [
                'चचत्रा' => 'चित्रा',
            ],
            'yoni' => [
                'व्याघ्र' => 'वाघ',
            ],
        ];
        if (isset($fieldAliases[$field][$value])) {
            $value = $fieldAliases[$field][$value];
        }

        return $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $core
     */
    private function normalizeCasteLine(string $value, array &$core): void
    {
        $value = trim(str_replace(['{', '}'], ['(', ')'], $value));
        $kuliPattern = '(?:कुळी|क्‌ळी|क[\x{094D}\x{200C}\s]*ळी|कळी)';
        if (preg_match('/([0-9०-९]+\s*'.$kuliPattern.')\s*हिंद[ुू]\s*[-–—]?\s*मराठा/u', $value, $m)) {
            $core['religion'] = 'हिंदू';
            $core['caste'] = 'मराठा';
            $core['sub_caste'] = $this->normalizeKuli($m[1]);

            return;
        }
        if (preg_match('/हिंद[ुू]\s*[-–—]?\s*मराठा\s*\(?\s*([0-9०-९]+\s*'.$kuliPattern.')\s*\)?/u', $value, $m)
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
        if ($core['caste'] === null && preg_match('/हिंद[ुू]\s*[-–—]?\s*मराठा/u', $value)) {
            $core['religion'] = 'हिंदू';
            $core['caste'] = 'मराठा';
        }
        if ($core['caste'] === null && preg_match('/^मराठा$/u', $value)) {
            $core['caste'] = 'मराठा';
        }
    }

    /**
     * @return array{0: string, 1: ?string, 2: ?string}
     */
    private function splitNameOccupation(string $value): array
    {
        $value = trim($value);
        $phone = $this->extractPhones($value)[0] ?? null;
        $occupation = null;
        if (preg_match('/[\(\{]\s*(.+?)\s*[\)\}]/u', $value, $m)) {
            $occupation = $this->cleanOccupationText($m[1]);
            $value = trim(preg_replace('/[\(\{]\s*.+?\s*[\)\}]/u', '', $value) ?? $value);
        }
        if (preg_match('/^(.*?)(?:\s*[,.।]?\s*)(?:नोकरी|व्यवसाय)\s*(?::\s*-\s*|[:>\-]\s*)(.+)$/u', $value, $m)) {
            $value = trim((string) $m[1]);
            $occupation = $occupation ?: $this->cleanOccupationText((string) $m[2]);
        }

        return [$this->cleanPersonName($this->stripTrailingAddressAndPhones($value)), $occupation !== '' ? $occupation : null, $phone];
    }

    private function stripTrailingAddressAndPhones(string $value): string
    {
        $value = preg_split('/\s+(?:घरचा\s+पत्ता|घराचा\s+पत्ता|सध्याचा\s+पत्ता|निवासी\s+पत्ता|पत्ता|मोबाईल|मोबाइल|संपर्क|मो\.?\s*नं\.?)\s*[:\-]/u', $value, 2)[0] ?? $value;
        $value = preg_split('/(?:\R|\s)+(?:मू\.?\s*पो\.?|मु\.?\s*पो\.?|रा\.|-\s*\(?\s*मो\.?\s*नं\.?|\(?\s*मो\.?\s*नं\.?)/u', $value, 2)[0] ?? $value;
        $value = preg_replace('/(?<!\d)[6-9]\d{9}(?!\d)/u', '', OcrNormalize::normalizeDigits($value)) ?? $value;

        return $this->trimSeparators($value);
    }

    private function cleanOccupationText(string $value): string
    {
        $value = OcrNormalize::normalizeDigits($value);
        $value = preg_replace('/(?:मो\.?\s*नं\.?|मोबाईल|मोबाइल|संपर्क)\s*[:\-]?\s*[6-9]\d{9}/u', '', $value) ?? $value;
        $value = preg_replace('/(?<!\d)[6-9]\d{9}(?!\d)/u', '', $value) ?? $value;
        $value = $this->trimSeparators($value);

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function trimSeparators(string $value): string
    {
        return trim(preg_replace('/^[\s,;:|\/\-–—]+|[\s,;:|\/\-–—]+$/u', '', $value) ?? $value);
    }

    private function looksLikeComplexion(string $value): bool
    {
        return (bool) preg_match('/^(?:गोरा|गोरी|निम\s*गोरा|निम\s*गोरी|निमगोरा|निमगोरी|सावळा|सावळी|गव्हाळ|fair|wheatish|dusky)/ui', trim($value));
    }

    private function cleanComplexionValue(string $value): string
    {
        $value = preg_split('/\s*(?:रास|राशी|नक्षत्र|देवक|कुलदैवत|कुलस्वामी|नाडी|गण|चरण|गोत्र|योनी)\s*[:\-–—]/u', $value, 2)[0] ?? $value;

        return trim(preg_replace('/[\s,.।]+$/u', '', $value) ?? $value);
    }

    private function cleanBloodGroupValue(string $value): string
    {
        $value = OcrNormalize::normalizeDigits($value);
        $value = preg_split('/\s*(?:रास|राशी|नक्षत्र|देवक|कुलदैवत|कुलस्वामी|नाडी|गण|चरण|गोत्र|योनी|वर्ण|रंग)\s*[:\-–—]/u', $value, 2)[0] ?? $value;
        $value = trim(preg_replace('/[\s,.।]+$/u', '', $value) ?? $value);
        $normalized = OcrNormalize::normalizeBloodGroup($value);

        return $normalized ?? $value;
    }

    private function cleanPersonName(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        if ($this->isSuspiciousHeadingAsName($value) || $this->isOtherRelativesLabel($value) || $this->isInvalidPersonLabelValue($value)) {
            return '';
        }

        return trim((string) preg_replace('/[\s,.।]+$/u', '', $value));
    }

    private function looksLikeAddressText(string $value): bool
    {
        return (bool) preg_match('/(?:मु\.?\s*पो\.?|रा\.|ता\.|जि\.|पत्ता|पोस्ट|कॉलनी|रोड|नगर|वाडी|गाव|फ्लॅट|वॉर्ड)/u', $value);
    }

    private function isInvalidPersonLabelValue(string $value): bool
    {
        return (bool) preg_match('/^(?:मामा|मामाचे\s+नाव|वडील|वडिलांचे\s+नाव|आई|आईचे\s+नाव|नातेसंबंध|नाते\s+संबंध|इतर\s+नातेवाईक|पाहुणे|इतर\s+पाहुणे|नाव)$/u', trim($value));
    }

    private function normalizeKuli(string $value): string
    {
        $value = preg_replace('/(?:क्‌ळी|क[\x{094D}\x{200C}\s]*ळी|कळी)/u', 'कुळी', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function parseHeightCm(string $value): ?float
    {
        $v = OcrNormalize::normalizeDigits($value);
        $normalizedHeight = OcrNormalize::normalizeHeight($v);
        if (is_string($normalizedHeight) && $normalizedHeight !== '') {
            $v = $normalizedHeight;
        }
        if (preg_match('/([0-9]+)\s*(?:फूट|फुट|feet|ft)\s*(?:([0-9]+)\s*(?:इंच|inch|in)?)?/ui', $v, $m)) {
            $feet = (int) $m[1];
            $inches = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;

            return round(($feet * 12 + $inches) * 2.54, 2);
        }
        if (preg_match('/([0-9]+)\s*[\'’]\s*([0-9]+)?/u', $v, $m)) {
            $feet = (int) $m[1];
            $inches = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;

            return round(($feet * 12 + $inches) * 2.54, 2);
        }
        if (preg_match('/^\s*([0-9]{1,2})\s*[.]\s*([0-9]{1,2})\s*$/u', $v, $m)) {
            $feet = (int) $m[1];
            $inches = (int) $m[2];
            if ($feet >= 4 && $feet <= 7 && $inches >= 0 && $inches <= 11) {
                return round(($feet * 12 + $inches) * 2.54, 2);
            }
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

        return (bool) preg_match('/^(?:एक|१|1)(?:\s*\(?\s*(?:अविवाहित|अविवाहीत|विवाहित|विवाहीत)\s*\)?)?$/u', $value);
    }

    private function isNumericCountValue(string $value): bool
    {
        $v = trim(OcrNormalize::normalizeDigits($value));

        return (bool) preg_match('/^[0-9]+$/', $v);
    }

    private function startsAddressLine(string $line): bool
    {
        return (bool) preg_match('/^(?:घरचा\s+पत्ता|घराचा\s+पत्ता|घर\s+पत्ता|सध्याचा\s+पत्ता|निवासी\s+पत्ता|गावचा\s+पत्ता|मुळगाव|मुळ\s+गाव|मूळगाव|मूळ\s+गाव|निवास|रहिवास|पत्ता|पता)'.self::LABEL_SUFFIX.'/u', $line);
    }

    private function startsContactLine(string $line): bool
    {
        return (bool) preg_match('/^\s*[-–—]?\s*(?:मोबाईल|मोबाइल|मोबा\.?\s*नं\.?|मो\.?\s*नं\.?|संपर्क|भ्रमणध्वनी|Mobile|Phone)(?:[\s:：\-\.]|$)/ui', $line);
    }

    private function isParentContactLine(string $line): bool
    {
        return (bool) preg_match('/^(?:पित्याचे|वडिलांचे|वडीलांचे|वडील|आईचे|मातेचे|आई)\s+नां?व?'.self::LABEL_SUFFIX.'/u', $line)
            || (bool) preg_match('/^(?:वडील|आई)\s*(?::\s*-\s*|[:\-])/u', $line);
    }

    private function startsPropertyLine(string $line): bool
    {
        if ($this->startsAddressLine($line)) {
            return false;
        }
        if (preg_match('/^(?:प्रोपर्टी|प्रॉपर्टी|स्थावर|मालमत्ता|शेती|प्लॉट|फ्लॅट|बंगला|स्वता:ची\s+मालमत्ता|स्वताची\s+मालमत्ता|स्वतःची\s+मालमत्ता|स्वत[:ः]?ची\s+मालमत्ता)'.self::LABEL_SUFFIX.'/u', $line)) {
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
        return (bool) preg_match('/(?:स्वत[:ः]?च(?:े|्या)|स्व[:ः]?ताच्या|मालकीच(?:े|्या)|वडिलोपार्जित|बंगला|फ्लॅट|प्लॉट|स्थावर|शेती|जमीन)\s*(?:घर)?/u', $line);
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

        $work = $this->extractSalaryPackageFromWorkText($work, $core);
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
            $left = trim($m[1]);
            $right = trim($m[2]);
            if ($this->looksLikeEmployerLeadSegment($left) && $this->looksLikeOccupationRole($right)) {
                $core['company_name'] = $left;
                $core['occupation_title'] = $right;
            } else {
                $core['occupation_title'] = $left;
                $core['company_name'] = $right;
            }
            if (isset($m[3]) && trim($m[3]) !== '') {
                $core['work_location_text'] = trim($m[3]);
            }

            return;
        }

        if (preg_match('/^([^,]+),\s*(.+)$/u', $work, $m)) {
            [$company, $locationPrefix] = $this->splitCompanyLocationPrefix(trim($m[1]));
            $core['company_name'] = $company;
            $core['work_location_text'] = trim(implode(', ', array_filter([$locationPrefix, trim($m[2])])));

            return;
        }

        if (($split = $this->splitEmployerLocationSuffix($work)) !== null) {
            $core['company_name'] = $split['company_name'];
            $core['work_location_text'] = $split['work_location_text'];

            return;
        }

        if ($this->looksLikeCompanyName($work)) {
            $core['company_name'] = $work;

            return;
        }

        $core['occupation_title'] = $work;
    }

    private function looksLikeSeparatorlessWorkValue(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        if (preg_match('/(?:माहिती\s+उपलब्ध|format\s+वेगळा|फॉरमॅट\s+वेगळा)/ui', $value) === 1) {
            return false;
        }

        return preg_match('/[A-Za-z0-9]/u', $value) === 1
            || preg_match('/(?:कंपनी|सरकारी|खाजगी|प्रा\.?|लि\.?|शेती|व्यवसाय|सेवा|नोकरी|डॉक्टर|इंजिनियर|शिक्षक|बँक|पोलीस)/u', $value) === 1;
    }

    /**
     * @param  array<string, mixed>  $core
     */
    private function extractSalaryPackageFromWorkText(string $work, array &$core): string
    {
        $normalized = OcrNormalize::normalizeDigits($work);
        if (preg_match('/(?:package|पॅकेज)\s*[:=\-]?\s*([0-9]+(?:\.[0-9]+)?)\s*(?:LPA|LAC|लाख)/ui', $normalized, $m)) {
            $core['salary_package_text'] = trim($m[0]);
        }

        $withoutPackage = preg_replace('/\s*\(?\s*(?:package|पॅकेज)\s*[:=\-]?\s*[0-9]+(?:\.[0-9]+)?\s*(?:LPA|LAC|लाख)\s*\)?/ui', '', $work) ?? $work;
        $withoutPackage = preg_replace('/\s{2,}/u', ' ', $withoutPackage) ?? $withoutPackage;

        return trim($withoutPackage, " \t\n\r\0\x0B,-–—");
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function splitCompanyLocationPrefix(string $value): array
    {
        if (preg_match('/^(.+\bCompany)\s+(.+)$/ui', $value, $m)) {
            return [trim($m[1]), trim($m[2])];
        }

        return [$value, null];
    }

    private function looksLikeCompanyName(string $value): bool
    {
        return (bool) preg_match('/\b(?:pvt|private|limited|ltd|llp|inc|corp|company|technologies|technology|healthcare|systems|solutions|industries)\b/ui', $value);
    }

    private function looksLikeEmployerLeadSegment(string $value): bool
    {
        $value = trim($value);
        if ($value === '' || $this->looksLikeOccupationRole($value)) {
            return false;
        }

        if ($this->looksLikeCompanyName($value)) {
            return true;
        }

        return preg_match('/^[A-Za-z][A-Za-z0-9&().\/\-\s]{2,}$/u', $value) === 1
            && str_word_count($value) <= 4;
    }

    private function looksLikeOccupationRole(string $value): bool
    {
        return preg_match('/\b(?:consultant|analyst|engineer|developer|manager|executive|officer|architect|accountant|teacher|lecturer|professor|designer|sap|finance|hr|marketing|banker|clerk|specialist|lead|sr\.?|senior)\b/ui', $value) === 1;
    }

    /**
     * @return array{company_name: string, work_location_text: string}|null
     */
    private function splitEmployerLocationSuffix(string $value): ?array
    {
        if (! preg_match('/^([A-Za-z][A-Za-z0-9&().\/\-\s]+?)\s+((?:नवी\s+मुंबई|मुंबई|पुणे|ठाणे|नाशिक|बंगळुरू|बेंगळुरु|हैदराबाद|चेन्नई|दिल्ली|गुरुग्राम|नोएडा)(?:\s*\([^)]*\))?)$/u', trim($value), $m)) {
            return null;
        }

        $company = trim($m[1]);
        $location = trim($m[2]);
        if (! $this->looksLikeEmployerLeadSegment($company)) {
            return null;
        }

        return [
            'company_name' => $company,
            'work_location_text' => $location,
        ];
    }

    /**
     * @param  array<string, mixed>  $core
     */
    private function extractAmountFromIncomeText(string $value, array &$core): void
    {
        $normalized = OcrNormalize::normalizeDigits($value);
        $normalized = str_replace(',', '', $normalized);
        if (! preg_match('/([0-9]+(?:\.[0-9]+)?)/u', $normalized, $m)) {
            return;
        }

        $amount = (float) $m[1];
        $core['salary_package_text'] = $core['salary_package_text'] ?? $this->trimSeparators($value);
        if (preg_match('/(?:प्रति\s*महिना|दर\s*महा|monthly|per\s*month)/ui', $normalized)) {
            $core['annual_income'] = (int) round($amount * 12);

            return;
        }
        if (preg_match('/(?:प्रति\s*वर्ष|वार्षिक|yearly|per\s*year|annual|p\s*\/\s*a|p\.?\s*a\.?|per\s*annum)/ui', $normalized)) {
            $core['annual_income'] = (int) round($amount);
        }
    }

    private function looksLikeStandaloneIncomeLine(string $value): bool
    {
        $normalized = OcrNormalize::normalizeDigits(trim($value));

        return (bool) preg_match('/[0-9][0-9,]+(?:\.[0-9]+)?\s*(?:p\s*\/\s*a|p\.?\s*a\.?|per\s*annum|वार्षिक|annual|lpa|lac|लाख)/ui', $normalized);
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
     * @return list<string>
     */
    private function splitRelativeValues(string $value): array
    {
        $value = trim($value);
        $numberedParts = preg_split('/(?:\R|\s+)(?=(?:(?:\d+|[०-९]+)[\).]|\(\s*(?:\d+|[०-९]+)\s*\)))/u', $value) ?: [];
        if (count($numberedParts) > 1) {
            $out = [];
            foreach ($numberedParts as $part) {
                $part = trim(preg_replace('/^\s*(?:(?:\d+|[०-९]+)[\).]|\(\s*(?:\d+|[०-९]+)\s*\))\s*/u', '', $part) ?? $part);
                if ($part !== '') {
                    $out[] = $part;
                }
            }

            if ($out !== []) {
                return $out;
            }
        }

        if (preg_match('/(?:पत्ता|पता|रा\.|राहणार|मु\.?\s*पो\.?)/u', $value)
            || preg_match('/\([^()]*,[^()]*\)/u', $value)
            || preg_match('/,\s*[\p{L}\p{M}\s.]+\([^()]+\)\s*$/u', $value)
            || preg_match('/,\s*(?:पुणे|मुंबई|सोलापूर|सांगली|सातारा|कोल्हापूर|ठाणे|कराड|नाशिक)$/u', $value)) {
            return [$value];
        }

        $parts = preg_split('/\s*,\s*(?=(?:\d+\)|[०-९]+\)|श्री|सौ|कै|कु|डॉ|[[:alpha:]\p{L}]))/u', $value) ?: [$value];
        $out = [];
        foreach ($parts as $part) {
            $part = trim(preg_replace('/^\s*(?:(?:\d+|[०-९]+)[\).]|\(\s*(?:\d+|[०-९]+)\s*\))\s*/u', '', $part) ?? $part);
            if ($part !== '') {
                $out[] = $part;
            }
        }

        return $out !== [] ? $out : [$value];
    }

    /**
     * @return array<string, mixed>
     */
    private function relativeRow(string $label, string $value, string $raw): array
    {
        $relationType = app(WizardRelationSchema::class)->canonicalRelationTypeFromLabel($label) ?? $label;

        if ($relationType === 'maternal_address_ajol') {
            return [
                'relation_type' => $relationType,
                'name' => null,
                'address_line' => $value,
                'raw' => $raw,
            ];
        }

        $parsed = $this->parseRelativeValue($value);

        return array_filter([
            'relation_type' => $relationType,
            'name' => $parsed['name'] ?? null,
            'occupation' => $parsed['occupation'] ?? null,
            'contact_number' => $parsed['contact_number'] ?? null,
            'address_line' => $parsed['address_line'] ?? null,
            'notes' => $parsed['notes'] ?? null,
            'raw' => $raw,
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, mixed>
     */
    private function parseRelativeValue(string $value): array
    {
        $original = trim($value);
        $work = OcrNormalize::normalizeDigits($original);
        $phones = $this->extractPhones($work);
        foreach ($phones as $phone) {
            $work = str_replace($phone, '', $work);
        }
        $work = preg_replace('/(?:मो\.|मो\s+नं\.?|मोबाईल|मोबाइल|संपर्क)\s*[:\-]?/u', '', $work) ?? $work;

        $occupation = null;
        $address = null;
        if (preg_match('/\((?:\s*(?:नोकरी|व्यवसाय|occupation)\s*[-:]?\s*)?([^()]*(?:नोकरी|व्यवसाय|teacher|engineer|doctor|business|service|job|shop|शिक्षक|शिक्षिका|प्राध्यापक|शेती|गृहिणी|सेवानिवृत्त|डॉक्टर|इंजिनियर|व्यापार)[^()]*)\)/ui', $work, $m)) {
            [$occupationText, $occupationAddress] = $this->splitOccupationAddressText($m[1]);
            $occupation = $this->cleanOccupationText($occupationText);
            if ($occupationAddress !== null) {
                $address = $this->cleanRelativeAddress($occupationAddress);
            }
            $work = trim(str_replace($m[0], '', $work));
        } elseif (preg_match('/(?:नोकरी|व्यवसाय|occupation)\s*[-:]\s*([^\n,;]+)/ui', $work, $m)) {
            $occupation = $this->cleanOccupationText($m[1]);
            $work = trim(str_replace($m[0], '', $work));
        }

        if (preg_match('/\(([^()]*(?:ता\.?|जि\.?|मु\.?\s*पो\.?|रा\.|रोड|नगर|गाव|वाडी|पुणे|कोल्हापूर|सांगली|सोलापूर|सातारा|करवीर|पन्हाळा)[^()]*)\)/u', $work, $m)) {
            $address = $this->cleanRelativeAddress($m[1]);
            $work = trim(str_replace($m[0], '', $work));
        } elseif (preg_match('/(?:पत्ता|पता|रा\.|राहणार|मु\.?\s*पो\.?)\s*[:\-.]?\s*(.+)$/u', $work, $m)) {
            $address = $this->cleanRelativeAddress($m[1]);
            $work = trim(substr($work, 0, (int) strpos($work, $m[0])));
        } elseif (preg_match('/\(([^()]*)\)/u', $work, $m) && $this->looksLikeRelativeAddressText((string) $m[1])) {
            $address = $this->cleanRelativeAddress($m[1]);
            $work = trim(str_replace($m[0], '', $work));
        }

        $notes = [];
        if (preg_match_all('/\(([^()]*)\)/u', $work, $matches)) {
            foreach ($matches[1] as $inner) {
                $inner = $this->trimSeparators((string) $inner);
                if ($inner !== '') {
                    $notes[] = $inner;
                }
            }
            $work = preg_replace('/\([^()]*\)/u', '', $work) ?? $work;
        }

        $name = $this->trimSeparators($work);
        $name = trim(preg_replace('/^\s*(?:\d+|[०-९]+)[\).]\s*/u', '', $name) ?? $name);
        $name = trim(preg_replace('/\s*[\.\(]+$/u', '', $name) ?? $name);
        $name = trim(preg_replace('/\s+नं\.?$/u', '', $name) ?? $name);
        [$name, $trailingAddress] = $this->splitTrailingRelativeAddress($name, $address !== null);
        if ($trailingAddress !== null) {
            $address = $address !== null ? $trailingAddress.' ('.$address.')' : $trailingAddress;
        }
        if ($name === '') {
            $notes[] = $original;
        }

        return [
            'name' => $name !== '' ? $name : null,
            'occupation' => $occupation,
            'contact_number' => $phones[0] ?? null,
            'address_line' => $address,
            'notes' => $notes !== [] ? implode('; ', array_unique($notes)) : null,
        ];
    }

    private function isOtherRelativesLabel(string $label): bool
    {
        return app(WizardRelationSchema::class)->isOtherRelativesTextLabel(trim($label));
    }

    private function startsOtherRelativesLine(string $line): bool
    {
        $labels = array_map(static fn (string $label): string => preg_quote($label, '/'), app(WizardRelationSchema::class)->otherRelativesTextLabels());
        $pattern = implode('|', $labels);

        return (bool) preg_match('/^\s*[-–—]?\s*(?:'.$pattern.')\s*(?::\s*-\s*|[:\-–—]|\s|$)/u', trim($line));
    }

    private function otherRelativesLabelFromLine(string $line): string
    {
        $labels = array_map(static fn (string $label): string => preg_quote($label, '/'), app(WizardRelationSchema::class)->otherRelativesTextLabels());
        $pattern = implode('|', $labels);
        if (preg_match('/^\s*[-–—]?\s*((?:'.$pattern.'))/u', trim($line), $m)) {
            return trim((string) ($m[1] ?? 'इतर नातेवाईक'));
        }

        return 'इतर नातेवाईक';
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function expandEmbeddedRelativeLabels(array $lines): array
    {
        $out = [];
        $allLabels = array_map(static fn (string $label): string => preg_quote($label, '/'), app(WizardRelationSchema::class)->allRelationLabels());
        usort($allLabels, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $labelPattern = '(?:'.implode('|', $allLabels).')';
        $protectedAliases = [];
        foreach (app(WizardRelationSchema::class)->allRelationLabels() as $index => $label) {
            if (! preg_match('/\s/u', $label)) {
                continue;
            }

            $protectedAliases[$label] = '__REL_ALIAS_'.$index.'__';
        }
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            $protectedLine = strtr($line, $protectedAliases);
            $parts = preg_split('/\s+(?='.$labelPattern.'\s*(?::\s*-\s*|[:\-–—]))/u', $protectedLine) ?: [$protectedLine];
            foreach ($parts as $part) {
                $part = strtr($part, array_flip($protectedAliases));
                $part = trim($part);
                if ($part !== '') {
                    $out[] = $part;
                }
            }
        }

        return $out;
    }

    private function stripOtherRelativesLabel(string $line): string
    {
        return trim(preg_replace('/^\s*(?:[-–—]\s*)?(?:इतर\s+नातेवाईक|उत्तर\s+नातेवाईक|नातेवाईक|नातेसंबंध|नाते\s+संबंध|इतर\s+पाहूणे|इतर\s+पाहुणे|पाहुणे)\s*(?::\s*-\s*|[:\-–—]\s*)?/u', '', $line) ?? $line);
    }

    private function cleanOtherRelativesText(string $value): string
    {
        $value = OcrNormalize::normalizeDigits($value);
        $value = preg_replace('/^\s*(?:\d+|[०-९]+)[\).]\s*/u', '', $value) ?? $value;
        $value = preg_split('/\R+\s*(?:अपेक्षा|शिक्षण|नोकरी|मोबाईल|मोबाइल|संपर्क|जन्म\s+तारीख|जन्म\s+स्थळ|प्रॉपर्टी|प्रॉपर्टि)\s*(?::\s*-\s*|[:\-–—]\s*)/u', $value, 2)[0] ?? $value;
        $value = preg_replace('/(?:मो\.?|मो\s*नं\.?|मोबाईल|मोबाइल|संपर्क|contact(?:\s*\.?\s*no\.?)?|mobile)\s*[:\-]?\s*(?:\+?91[\s-]*)?[6-9][0-9\s\/-]{9,}/ui', '', $value) ?? $value;
        $value = preg_replace('/(?<!\d)[6-9]\d{9}(?!\d)/u', '', $value) ?? $value;
        $value = preg_replace('/(?:मो\.?|मो\s*नं\.?|मोबाईल|मोबाइल|संपर्क|contact(?:\s*\.?\s*no\.?)?|mobile|no\.)\s*[:\-\.]*/ui', '', $value) ?? $value;
        $value = preg_replace('/(?:^|[;,\s])\|[^|]*\|(?:[^;]*\|)*/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s*;\s*/u', '; ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return $this->trimSeparators($value);
    }

    private function isRelativeNoiseLine(string $line): bool
    {
        $line = trim($line);
        if ($line === '') {
            return true;
        }

        return (bool) preg_match('/(?:छायाचित्र|फोटो|क्लोज-अप|साडी|कॅमेऱ्याकडे|पार्श्वभूमी|ब्लर|दिवे|उत्सवाचे|समारंभाचे|वातावरण\s+दर्शवते)/u', $line);
    }

    private function isIncompleteRelativeLabelFragment(string $line): bool
    {
        return (bool) preg_match('/^\s*(?:इतर|पाहुणे|नातेवाईक|नातेसंबंध|नाते\s+संबंध)\s*$/u', trim($line));
    }

    /**
     * @param  array<string, mixed>  $relative
     */
    private function mergeRelativeContinuationLine(array &$relative, string $line): bool
    {
        $line = $this->trimLeadingListDecorators($line);
        if ($line === '') {
            return true;
        }

        if ($this->mergeOpenRelativeFragment($relative, $line)) {
            return true;
        }

        $parsed = $this->parseRelativeValue($line);
        $name = trim((string) ($parsed['name'] ?? ''));
        $address = trim((string) ($parsed['address_line'] ?? ''));
        $occupation = trim((string) ($parsed['occupation'] ?? ''));
        $phone = trim((string) ($parsed['contact_number'] ?? ''));
        $notes = trim((string) ($parsed['notes'] ?? ''));

        if ($this->lineIsRelativeAddressOnly($line) || ($name === '' && ($address !== '' || $occupation !== ''))) {
            if ($address !== '') {
                $relative['address_line'] = $this->setTextOnce($relative['address_line'] ?? null, $address);
            } elseif ($occupation === '') {
                $relative['address_line'] = $this->setTextOnce($relative['address_line'] ?? null, $this->cleanRelativeAddress($line));
            }
            if ($occupation !== '') {
                $relative['occupation'] = $this->setTextOnce($relative['occupation'] ?? null, $occupation);
            }
            if ($phone !== '') {
                $relative['contact_number'] = $phone;
            }

            return true;
        }

        if ($this->lineIsRelativeOccupationOnly($line)) {
            $relative['occupation'] = $this->setTextOnce($relative['occupation'] ?? null, $occupation !== '' ? $occupation : $this->trimSeparators($line));

            return true;
        }

        if ($name !== '' && $this->looksLikeDuplicateRelativeContinuation($relative, $name)) {
            if ($address !== '') {
                $relative['address_line'] = $this->setTextOnce($relative['address_line'] ?? null, $address);
            }
            if ($occupation !== '') {
                $relative['occupation'] = $this->setTextOnce($relative['occupation'] ?? null, $occupation);
            }
            if ($phone !== '') {
                $relative['contact_number'] = $phone;
            }
            if ($notes !== '' && $notes !== $line) {
                $relative['notes'] = $this->setTextOnce($relative['notes'] ?? null, $notes);
            }

            return true;
        }

        return false;
    }

    private function trimLeadingListDecorators(string $line): string
    {
        $line = trim($line);

        return trim(preg_replace('/^\s*(?:[-–—•]+\s*)+/u', '', $line) ?? $line);
    }

    /**
     * OCR often breaks a single relative row inside an opening parenthesis:
     * "चुलते:- श्री. X ( गाव" + "\n" + "ता. Y)".
     * Rebuild the original raw value before we decide this is a new relative row.
     *
     * @param  array<string, mixed>  $relative
     */
    private function mergeOpenRelativeFragment(array &$relative, string $line): bool
    {
        $raw = trim((string) ($relative['raw'] ?? ''));
        if ($raw === '' || ! $this->hasUnbalancedParentheses($raw)) {
            return false;
        }
        if ($this->looksLikeAnyKnownLabel($line) || $this->startsPahune($line) || $this->isHardSectionBoundary($line)) {
            return false;
        }

        $combinedRaw = trim($raw.' '.$line);
        $value = $this->relativeValueFromRaw($combinedRaw);
        if ($value === '') {
            return false;
        }

        $this->applyParsedRelativeFields($relative, $this->parseRelativeValue($value), $combinedRaw);

        return true;
    }

    private function lineIsRelativeAddressOnly(string $line): bool
    {
        $line = trim($line);

        return (bool) preg_match('/^(?:रा\.?|राहणार|मु\.?\s*पो\.?|पत्ता|ता\.|जि\.)/u', $line)
            || (bool) preg_match('/^(?:[\p{L}\p{M}\s.]+)\s+ता\.[\p{L}\p{M}\s.]+(?:\s+जि\.[\p{L}\p{M}\s.]+)?$/u', $line);
    }

    private function lineIsRelativeOccupationOnly(string $line): bool
    {
        $line = trim($line);

        return (bool) preg_match('/^\(?\s*(?:प्राध्यापक|शिक्षक|प्राथमिक\s+शिक्षक|doctor|teacher|business|engineer|नोकरी|व्यवसाय)[^()]*\)?$/ui', $line);
    }

    private function isSharedRelativeAddressLine(string $line): bool
    {
        return (bool) preg_match('/^\s*सर्व\s+(?:रा\.?|राहणार|मु\.?\s*पो\.?|पत्ता|ता\.|जि\.)/u', trim($line));
    }

    /**
     * @param  list<array<string, mixed>>  $relatives
     */
    private function applySharedRelativeAddressLine(array &$relatives, int $startIndex, string $line): void
    {
        $address = trim((string) preg_replace('/^\s*सर्व\s+/u', '', trim($line)));
        $address = $this->cleanRelativeAddress($address);
        $address = trim((string) preg_replace('/^रा\.?\s*/u', '', $address));
        if ($address === '') {
            return;
        }

        for ($index = $startIndex; $index < count($relatives); $index++) {
            $relatives[$index]['address_line'] = $this->setTextOnce($relatives[$index]['address_line'] ?? null, $address);
        }
    }

    /**
     * @param  array<string, mixed>  $relative
     */
    private function looksLikeDuplicateRelativeContinuation(array $relative, string $name): bool
    {
        $existing = trim((string) ($relative['name'] ?? ''));
        if ($existing === '' || $name === '') {
            return false;
        }

        return str_contains($name, $existing) || str_contains($existing, $name);
    }

    private function cleanRelativeAddress(string $value): string
    {
        $address = $this->trimSeparators($value);
        $address = preg_replace('/(?:मो\.?\s*नं?\.?|मोबाईल|मोबाइल|संपर्क)\s*[:\-\.]?\s*[6-9६-९][0-9०-९\s\/-]{9,}\.?/u', '', $address) ?? $address;
        $address = preg_replace('/(?<![0-9०-९])[6-9६-९][0-9०-९]{9}(?![0-9०-९])/u', '', $address) ?? $address;
        $address = preg_replace('/\s*(?:मो\.?\s*नं?\.?|मोबाईल|मोबाइल|संपर्क)\s*[:\-\.]*\s*$/u', '', $address) ?? $address;
        $address = preg_replace('/\s+नं\.?\s*$/u', '', $address) ?? $address;
        $address = trim($address);
        if ($address !== '' && substr_count($address, '(') < substr_count($address, ')')) {
            $address = preg_replace('/\)+\s*$/u', '', $address) ?? $address;
        }

        return $this->trimSeparators($address);
    }

    private function hasUnbalancedParentheses(string $value): bool
    {
        return substr_count($value, '(') > substr_count($value, ')');
    }

    private function relativeValueFromRaw(string $raw): string
    {
        if (preg_match('/^\s*[-–—]?\s*.+?\s*(?::\s*-\s*|[:\-–—]\s*)(.+)$/u', $raw, $m)) {
            return trim((string) ($m[1] ?? ''));
        }

        return trim($raw);
    }

    /**
     * @param  array<string, mixed>  $relative
     * @param  array<string, mixed>  $parsed
     */
    private function applyParsedRelativeFields(array &$relative, array $parsed, string $raw): void
    {
        $relative['raw'] = $raw;
        foreach (['name', 'occupation', 'contact_number', 'address_line', 'notes'] as $field) {
            $value = $parsed[$field] ?? null;
            if ($value === null || trim((string) $value) === '') {
                unset($relative[$field]);
                continue;
            }
            $relative[$field] = $value;
        }
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function splitOccupationAddressText(string $value): array
    {
        $value = $this->trimSeparators($value);
        if (preg_match('/^(.+?),\s*((?:शिवाजी\s+विद्यापीठ,\s*)?(?:कोल्हापूर|सांगली|सोलापूर|सातारा|पुणे|मुंबई|ठाणे|नाशिक|बारामती|डोंबिवली).*)$/u', $value, $m)) {
            return [$this->trimSeparators($m[1]), $this->trimSeparators($m[2])];
        }

        return [$value, null];
    }

    private function looksLikeRelativeAddressText(string $value): bool
    {
        $value = $this->trimSeparators($value);
        if ($value === '') {
            return false;
        }

        return (bool) preg_match('/(?:,|ता\.?|जि\.?|मु\.?\s*पो\.?|रा\.|रोड|नगर|गाव|वाडी|पुणे|ठाणे|कोल्हापूर|सांगली|सोलापूर|सातारा|करवीर|पन्हाळा|माळीनगर)/u', $value);
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function splitTrailingRelativeAddress(string $name, bool $allowPlainCommaPlace = false): array
    {
        $name = $this->trimSeparators($name);
        if ($name === '') {
            return ['', null];
        }

        if (preg_match('/^(.+?),\s*([\p{L}\p{M}\s.]+(?:\([^()]+\))?)$/u', $name, $m)
            && ($allowPlainCommaPlace || $this->looksLikeRelativeAddressText((string) $m[2]))) {
            return [$this->trimSeparators($m[1]), $this->cleanRelativeAddress($m[2])];
        }

        if (preg_match('/^(.+?)\s+(ठाणे|पुणे|सांगली|सोलापूर|सातारा|कोल्हापूर|माळीनगर)$/u', $name, $m)) {
            return [$this->trimSeparators($m[1]), $this->cleanRelativeAddress($m[2])];
        }

        return [$name, null];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $fields
     * @return list<array<string, mixed>>
     */
    private function dedupeRows(array $rows, array $fields): array
    {
        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            $keyParts = [];
            foreach ($fields as $field) {
                $keyParts[] = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) ($row[$field] ?? '')) ?? ''));
            }
            $key = implode('|', $keyParts);
            if ($key === str_repeat('|', max(0, count($fields) - 1)) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function dedupeSiblingRows(array $rows): array
    {
        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            $spouse = is_array($row['spouse'] ?? null) ? $row['spouse'] : [];
            $key = implode('|', array_map(
                fn (mixed $value): string => mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '')),
                [
                    $row['relation_type'] ?? '',
                    $row['name'] ?? '',
                    $row['occupation'] ?? '',
                    $row['contact_number'] ?? '',
                    $row['marital_status'] ?? '',
                    $spouse['name'] ?? '',
                    $spouse['occupation_title'] ?? $spouse['occupation'] ?? '',
                    $spouse['contact_number'] ?? '',
                    $spouse['address_line'] ?? '',
                ]
            ));
            if ($key === str_repeat('|', 8) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param  list<string>  $labels
     */
    private function extractLabeledValue(string $line, array $labels): ?string
    {
        $line = $this->normalizeDecorativeLabelSeparators($line);
        foreach ($labels as $label) {
            $pattern = '/'.preg_quote($label, '/').'\s*(?::\s*-\s*|[:\-–—]\s*|\s+)(.+)$/u';
            if (! preg_match($pattern, $line, $m)) {
                continue;
            }
            $value = trim($m[1]);
            $value = preg_split(
                '/\s+(?:जन्म\s+तारीख|जन्मतारीख|जन्म\s+वेळ|जन्मवेळ|जन्म\s+ठिकाण|जन्म\s+स्थळ|धर्म|जात|कास्ट|उपजात|उंची|ऊंची|कुंची|वर्ण|रंग|शिक्षण|नोकरी|व्यवसाय|कंपनी|पित्याचे\s+नाव|वडिलांचे\s+नाव|वडीलांचे\s+नाव|वडील|आईचे\s+नाव|मातेचे\s+नाव|आई|रास|राशी|नक्षत्र|देवक|कुलदैवत|कुलस्वामी|कुळस्वामी|नाडी|गण|चरण|गोत्र|वैरवर्ग|नावरस|ब्लड\s*ग्रुप|रक्त\s*गट|मोबाईल|मोबाइल|संपर्क|प्रोपर्टी|प्रॉपर्टी|स्थावर|पत्ता|सध्याचा\s+पत्ता|घरचा\s+पत्ता|गावचा\s+पत्ता)'.self::LABEL_SUFFIX.'/u',
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

    private function normalizeDecorativeLabelSeparatorsInText(string $text): string
    {
        $lines = array_map(fn (string $line): string => $this->normalizeDecorativeLabelSeparators($line), explode("\n", $text));

        return implode("\n", $lines);
    }

    private function normalizeDecorativeLabelSeparators(string $line): string
    {
        $line = $this->stripLeadingListMarkerForKnownBiodataLine($line);

        static $pattern = null;
        if ($pattern === null) {
            $labels = array_map(static fn (string $label): string => preg_quote($label, '/'), self::OCR_DECORATIVE_LABELS);
            usort($labels, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
            $pattern = '/(?<!\S)('.implode('|', $labels).')\s*[8८]\s*/u';
        }

        return preg_replace($pattern, '$1 : ', $line) ?? $line;
    }

    private function stripLeadingListMarkerForKnownBiodataLine(string $line): string
    {
        static $labelPattern = null;
        if ($labelPattern === null) {
            $labels = array_map(static fn (string $label): string => preg_quote($label, '/'), self::OCR_DECORATIVE_LABELS);
            usort($labels, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
            $labelPattern = implode('|', $labels);
        }

        $line = preg_replace('/^\s*(?:\d+|[०-९]+)[\).]\s*(?=(?:[0०]\s*)?(?:'.$labelPattern.')'.self::LABEL_SUFFIX.')/u', '', $line) ?? $line;
        $line = preg_replace('/^\s*(?:\d+|[०-९]+)[\).]\s*(?=(?:\(?\s*(?:\d+|[०-९]+)\)?\s*)?(?:श्री|सौ|कै|डॉ|चि|कु|पै)\.?)/u', '', $line) ?? $line;
        $line = preg_replace('/^\s*[0०]\s*(?=(?:'.$labelPattern.')'.self::LABEL_SUFFIX.')/u', '', $line) ?? $line;

        return $line;
    }

    /**
     * @param  array<string, string>  $preferences
     */
    private function extractPreferenceLine(string $line, array &$preferences): void
    {
        if (($expectations = $this->extractFullLabeledValue($line, ['अपेक्षा', 'जोडीदार अपेक्षा'])) === null) {
            return;
        }

        $preferences['expectations'] = $this->setTextOnce($preferences['expectations'] ?? null, $expectations);
    }

    /**
     * @param  list<string>  $labels
     */
    private function extractFullLabeledValue(string $line, array $labels): ?string
    {
        $line = $this->normalizeDecorativeLabelSeparators($line);
        foreach ($labels as $label) {
            $pattern = '/'.preg_quote($label, '/').'\s*(?::\s*-\s*|[:\-–—]\s*|\s+)(.+)$/u';
            if (! preg_match($pattern, $line, $m)) {
                continue;
            }

            $value = trim($m[1]);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function startsPreferenceLine(string $line): bool
    {
        return (bool) preg_match('/^\s*(?:अपेक्षा|जोडीदार\s+अपेक्षा)\s*(?::\s*-\s*|[:\-–—]\s*)/u', trim($line));
    }

    private function horoscopeValueLooksPolluted(string $value): bool
    {
        return (bool) preg_match('/(?:ब्लड\s*ग्रुप|रक्त\s*गट|मोबाईल|मोबाइल|संपर्क|प्रोपर्टी|प्रॉपर्टी|स्थावर|घरचा\s+पत्ता|सध्याचा\s+पत्ता)/u', $value);
    }

    private function isSuspiciousHeadingAsName(string $value): bool
    {
        return (bool) preg_match('/^(?:वैयक्तिक\s+माहिती|वैयक्तिक\s+तपशील|कौटुंबिक\s+माहिती|कौटुंबिक\s+तपशील|परिचय\s*(?:पत्र|पञ्जक)|शिक्षण|नोकरी|व्यवसाय|बायोडाटा)$/u', trim($value));
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

        $previousLine = null;
        $recentSiblingLine = false;
        foreach ($lines as $index => $line) {
            $raw = trim($line);
            if ($raw === '' || mb_strlen($raw) > 220) {
                $previousLine = $line;
                continue;
            }
            if ($this->isIgnorableReviewLine($raw)) {
                $previousLine = $line;
                continue;
            }
            if (($recentSiblingLine || ($previousLine !== null && $this->startsSiblingLine($previousLine))) && $this->startsAddressLine($raw)) {
                $previousLine = $line;
                continue;
            }
            $recentSiblingLine = $this->startsSiblingLine($raw);
            if ($this->lineLooksMixedFieldValue($raw)
                && ! $this->lineHasMappedDateAndBirthTime($raw, $core)
                && $this->extractMarkdownHoroscopeTableSegments($raw) === []) {
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
                $flag['source_line_no'] = $index + 1;
                $flag['source_text'] = $raw;
                $key = $flag['field'].'|'.$flag['reason'].'|'.$flag['raw'];
                $flags[$key] = $flag;
            }
            $previousLine = $line;
        }

        return array_values($flags);
    }

    /**
     * @param  array<string, mixed>  $core
     */
    private function lineHasMappedDateAndBirthTime(string $line, array $core): bool
    {
        if (! preg_match('/जन्म\s+तारीख/u', $line) || ! preg_match('/जन्म\s*वेळ/u', $line)) {
            return false;
        }

        $date = $core['date_of_birth'] ?? null;
        $time = $core['birth_time'] ?? null;

        return is_scalar($date)
            && is_scalar($time)
            && $this->lineContainsMappedScalar($line, $date)
            && $this->lineContainsMappedScalar($line, $time);
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<string, mixed>  $core
     */
    private function lineHasMappedValue(string $line, array $normalized, array $core): bool
    {
        if ($this->lineHasMappedNoSiblingSignal($line, $core)) {
            return true;
        }

        foreach ($core as $value) {
            if (is_scalar($value) && $this->lineContainsMappedScalar($line, $value)) {
                return true;
            }
        }
        $horoscope = is_array($normalized['horoscope'] ?? null) ? $normalized['horoscope'] : [];
        foreach ($horoscope as $value) {
            if (is_scalar($value) && $this->lineContainsMappedScalar($line, $value)) {
                return true;
            }
        }
        foreach (['contacts', 'relatives', 'siblings', 'addresses', 'parents_addresses'] as $bucket) {
            foreach (($normalized[$bucket] ?? []) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                foreach ($row as $value) {
                    if (is_scalar($value) && $this->lineContainsMappedScalar($line, $value)) {
                        return true;
                    }
                }
            }
        }
        foreach (($normalized['preferences'] ?? []) as $value) {
            if (is_scalar($value) && $this->lineContainsMappedScalar($line, $value)) {
                return true;
            }
        }
        $propertyText = (string) (($normalized['property_summary'] ?? [])['summary_text'] ?? '');
        if ($propertyText !== '' && str_contains($propertyText, $line)) {
            return true;
        }
        $horoscope = is_array($normalized['horoscope'] ?? null) ? $normalized['horoscope'] : [];
        foreach ($horoscope as $key => $value) {
            if ($key === 'raw') {
                continue;
            }
            if (is_scalar($value) && $this->lineContainsMappedScalar($line, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $core
     */
    private function lineHasMappedNoSiblingSignal(string $line, array $core): bool
    {
        $trimmed = trim($line);
        if (! $this->startsSiblingLine($trimmed)) {
            return false;
        }

        if (! preg_match('/^(?:भाऊ|बहीण|बहिण|बहिणी|मुलाचा\s+भाऊ|मुलाचे\s+भाऊ|मुलाची\s+बहीण|मुलाची\s+बहिण)\s*(?::\s*-\s*|[:\-]\s*)(.+)$/u', $trimmed, $m)) {
            return false;
        }

        $value = trim((string) ($m[1] ?? ''));
        if (! $this->isNoSiblingValue($value)) {
            return false;
        }

        if (preg_match('/^भाऊ/u', $trimmed)) {
            return ($core['brother_count'] ?? null) === 0;
        }

        return ($core['sister_count'] ?? null) === 0;
    }

    private function lineContainsMappedScalar(string $line, mixed $value): bool
    {
        $needle = trim((string) $value);
        if ($needle === '') {
            return false;
        }
        if (str_contains($line, $needle)) {
            return true;
        }

        return str_contains(OcrNormalize::normalizeDigits($line), OcrNormalize::normalizeDigits($needle));
    }

    /**
     * @return array<string, string>|null
     */
    private function reviewFlagForUsefulLine(string $line): ?array
    {
        if (preg_match('/^(?:श्री|सौ|कै|डॉ|श्री\s*\/\s*सौ)\.?/u', trim($line))
            && preg_match('/(?:मु\.?\s*पो\.?|रा\.|ता\.|जि\.)/u', $line)) {
            return [
                'field' => 'relatives',
                'reason' => 'unmapped_relatives',
                'raw' => $line,
                'suggested_section' => 'relatives',
            ];
        }

        $rows = [
            ['mixed_field_value', 'review_needed', '/(?:जन्म|उंची|जात|शिक्षण|नोकरी|राशी|रास|नाडी|नाड|गण|मोबाईल).*(?:जन्म|उंची|जात|शिक्षण|नोकरी|राशी|रास|नाडी|नाड|गण|मोबाईल)/u'],
            ['unmapped_horoscope', 'horoscope', '/(?:जन्मरास|जन्मनक्षत्र|नावरस\s*नाव|नावास\s*नाव|रास|राशी|नक्षत्र|देवक|कुलदैवत|कुलदेवत|कुलस्वामी|कुळस्वामी|नाडी|नाड|गण|चरण|गोत्र|योनी)/u'],
            ['unmapped_relatives', 'relatives', '/^\s*[-–—]\s*(?:मामा|मावशी|माऊशी|आत्या|चुलते|इतर\s+नातेवाईक)/u'],
            ['unmapped_family', 'family-details', '/(?:वडील|आई|भाऊ|बहिण|कुटुंब|कौटुंबिक)/u'],
            ['unmapped_address', 'basic-info', '/(?:पत्ता|मु\.?\s*पो\.?|रा\.)/u'],
            ['unmapped_property', 'property', '/(?:प्रोपर्टी|प्रॉपर्टी|स्थावर|शेती|फ्लॅट|प्लॉट|मालकीचे\s+घर)/u'],
            ['unmapped_contact', 'basic-info', '/(?:मोबाईल|मोबाइल|संपर्क|[6-9][0-9]{9})/u'],
            ['unmapped_preferences', 'about-preferences', '/(?:अपेक्षा|जोडीदार\s+अपेक्षा|partner\s+preferences?)/u'],
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

    /**
     * @param  array<string, mixed>  $relative
     */
    private function relativeTargetSection(array $relative): string
    {
        $type = trim((string) ($relative['relation_type'] ?? ''));

        return app(WizardRelationSchema::class)->sectionForRelationType($type) ?? 'review_needed';
    }

    private function stringifyExtractedFactValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_scalar($value)) {
            return OcrNormalize::normalizeDigits(trim((string) $value));
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $core
     */
    private function normalizeEmptyCoreStringsToNull(array &$core): void
    {
        foreach ($core as $key => $value) {
            if (is_string($value) && trim($value) === '') {
                $core[$key] = null;
            }
        }
    }

    private function canonicalPhone(string $value): string
    {
        return $this->extractPhones($value)[0] ?? '';
    }

    private function guessPhoneTargetSection(string $line): string
    {
        if ($this->isParentContactLine($line) || $this->isParentMobileLine($line)) {
            return 'family-details';
        }
        if ($this->startsSiblingLine($line)) {
            return 'siblings';
        }

        return 'basic-info';
    }

    private function guessPhoneTargetField(string $line): string
    {
        if ($this->isParentContactLine($line) || $this->isParentMobileLine($line)) {
            return 'family-details.phone_number';
        }
        if ($this->startsSiblingLine($line)) {
            return 'siblings.phone_number';
        }

        return 'core.primary_contact_number';
    }

    private function lineLooksMixedFieldValue(string $line): bool
    {
        if (preg_match('/^\s*धर्म\s*-\s*जात'.self::LABEL_SUFFIX.'/u', trim($line))) {
            return false;
        }

        preg_match_all('/(?:जन्म\s+वेळ|जन्म\s+तारीख|जन्म\s+स्थळ|उंची|जात|धर्म|शिक्षण|नोकरी|व्यवसाय|राशी|नक्षत्र|नाडी|गण|देवक|मोबाईल|मोबाइल|संपर्क|प्रॉपर्टी|प्रोपर्टी)'.self::LABEL_SUFFIX.'/u', $line, $matches);

        return count($matches[0] ?? []) > 1;
    }

    private function isIgnorableReviewLine(string $line): bool
    {
        $line = trim($line);

        return (bool) preg_match('/^(?:#+\s*\*?\s*)?(?:परिचय\s*(?:पत्र|पञ्जक)|परिचयपत्र|कौटुंबिक\s+माहिती|कौटुंबिक\s+तपशील|मुलाची\s+माहिती|मुलीची\s+माहिती|बायोडेटा|बायोडाटा)\*?\s*[-–—:]?\s*$/u', $line)
            || (bool) preg_match('/^(?:संपर्क\s+नंबर|मोबाईल\s+नंबर|मोबाइल\s+नंबर|कायमचा\s+पत्ता)\s*:?\s*$/u', $line)
            || (bool) preg_match('/श्री\s*(?:गणेश|गणेशाय|गजानन)|तुळजाभवानी\s+प्रसन्न|खंडोबा\s+प्रसन्न/u', $line);
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function fullNameCameFromTableHint(array $draft, string $fullName): bool
    {
        $hint = $draft['meta']['table_hints']['full_name'] ?? null;

        return is_scalar($hint) && trim((string) $hint) === trim($fullName);
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
        return (bool) preg_match('/^\s*[-–—]?\s*(?:पाहुणे|इतर\s+पाहूणे|इतर\s+पाहुणे)'.self::LABEL_SUFFIX.'/u', $line);
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
            if (preg_match('/^(?:मामा|मामाचे\s+नाव|मावशी|मुलाची\s+आत्या|आत्या|चुलत|जावई|पाहुणे|नातेसंबंध)\s*[:\-]\s*(.+)$/u', $line, $m)) {
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
        return (bool) preg_match('/^(?:पित्याचे|वडिलांचे|वडीलांचे|वडील|आईचे|मातेचे|आई|मामा|मामाचे\s+नाव|मावशी|मुलाची\s+आत्या|आत्या|चुलत|जावई|पाहुणे|नातेसंबंध)'.self::LABEL_SUFFIX.'/u', $line);
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

        return (bool) preg_match('/(?:वडील|पित्याचे|आई|मामा|मामाचे\s+नाव|मावशी|मुलाची\s+आत्या|आत्या|चुलत|पाहुणे|नातेसंबंध|जावई)(?:[\s:：\-\.]|$)/u', $blob)
            && ! preg_match('/^#{1,6}\s/u', trim($line));
    }

    private function stripMarkdownHeadingDecorators(string $line): string
    {
        $line = trim($line);
        $line = preg_replace('/^\s*#{1,6}\s*/u', '', $line) ?? $line;
        $line = preg_replace('/^\*+\s*/u', '', $line) ?? $line;
        $line = preg_replace('/\s*\*+\s*$/u', '', $line) ?? $line;

        return trim($line);
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
