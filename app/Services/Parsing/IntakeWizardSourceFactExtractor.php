<?php

namespace App\Services\Parsing;

use App\Services\Ocr\OcrNormalize;

class IntakeWizardSourceFactExtractor
{
    private const OCR_DECORATIVE_LABELS = [
        'मुलाचे नाव', 'मुलीचे नाव', 'नाव', 'जन्मतारीख', 'जन्म तारीख', 'जन्मवेळ', 'जन्म वेळ',
        'जन्मस्थळ', 'जन्म स्थळ', 'धर्म', 'जात', 'उपजात', 'उप जात', 'उंची', 'रंग', 'रक्तगट',
        'रक्त गट', 'शिक्षण', 'शिक्षण / नोकरी', 'व्यवसाय', 'नोकरी', 'कंपनी', 'वार्षिक उत्पन्न',
        'उत्पन्न', 'वडील', 'वडिलांचे नाव', 'आई', 'आईचे नाव', 'मूळ गाव', 'मुळगाव', 'मूळगाव',
        'निवास', 'सध्याचा पत्ता', 'पत्ता', 'घरचा पत्ता', 'आजोळचा पत्ता', 'अपेक्षा', 'देवक',
        'नाडी', 'राशी', 'रास', 'नक्षत्र', 'गण', 'गोत्र', 'कुलदैवत', 'कुल दैवत', 'प्रॉपर्टी',
        'प्रॉपर्टी तपशील', 'स्थावर मालमत्ता', 'भ्रमणध्वनी', 'मोबाईल', 'मोबाइल', 'मो. नं',
        'मो. नं.', 'मो नं', 'मो नं.', 'भाऊ', 'मुलाचा भाऊ', 'मुलाचे भाऊ', 'बहीण', 'बहिण', 'मुलाची बहीण',
        'मुलाची बहिण', 'दाजी', 'जावई', 'मामा', 'मुलाचे मामा', 'मावशी',
        'माऊशी', 'आत्या', 'मुलाची आत्या', 'मुलाची आत्त्या', 'चुलते', 'मुलाचे चुलते', 'नातेसंबंध', 'नाते संबंध', 'इतर नातेवाईक', 'पाहुणे',
        'इतर पाहुणे', 'नावरस', 'नावरस नाव',
    ];

    public function __construct(
        private readonly WizardRelationSchema $relationSchema
    ) {}

    /**
     * @param  array<string, mixed>  $draft
     * @return list<array<string, mixed>>
     */
    public function extract(array $draft): array
    {
        $facts = [];
        $activePerson = null;
        $activeAddressField = null;
        $activeOtherRelatives = false;
        $activeParentContext = false;

        foreach (($draft['source_lines'] ?? []) as $sourceLine) {
            if (! is_array($sourceLine)) {
                continue;
            }

            $lineNo = (int) ($sourceLine['line_no'] ?? 0);
            $sourceText = trim((string) ($sourceLine['raw'] ?? ''));
            if ($lineNo <= 0 || $sourceText === '') {
                continue;
            }

            $line = trim((string) ($sourceLine['normalized'] ?? ''));
            if ($line === '') {
                $line = OcrNormalize::normalizeDigits($this->normalizeDecorativeLabelSeparators($sourceText));
            }
            [$label, $value] = $this->splitLabelAndValue($line);
            $normalizedLabel = $this->normalizeLabel($label);
            $phones = $this->extractPhones($line);

            if ($this->relationSchema->isOtherRelativesTextLabel($normalizedLabel)) {
                $activeOtherRelatives = true;
                $activePerson = null;
                $activeAddressField = null;
                $activeParentContext = false;
                $facts[] = $this->fact('other_relatives_text', $value, $lineNo, $sourceText, $label, 'alliance', 'core.other_relatives_text');
                foreach ($phones as $phone) {
                    $facts[] = $this->fact('phone_number', $phone, $lineNo, $sourceText, $label, 'alliance', 'core.other_relatives_text');
                }
                continue;
            }

            $relationType = $this->relationSchema->canonicalRelationTypeFromLabel($normalizedLabel);
            if ($relationType !== null) {
                $activeOtherRelatives = false;
                $activeAddressField = null;
                $activeParentContext = false;
                $activePerson = [
                    'section' => $this->relationSchema->sectionForRelationType($relationType),
                    'relation_type' => $relationType,
                    'label' => $label,
                    'target_field' => $this->targetFieldForRelation($relationType),
                ];
                foreach ($this->relationFacts($relationType, $value, $lineNo, $sourceText, $label, $phones) as $fact) {
                    $facts[] = $fact;
                }
                continue;
            }

            if ($activePerson !== null && in_array($normalizedLabel, ['पत्ता', 'पता'], true) && $value !== '') {
                $facts[] = $this->fact(
                    'address_line',
                    $this->cleanAddressValue($value),
                    $lineNo,
                    $sourceText,
                    $label,
                    (string) $activePerson['section'],
                    (string) $activePerson['target_field'].'.address_line',
                    (string) ($activePerson['relation_type'] ?? '')
                );
                foreach ($phones as $phone) {
                    $facts[] = $this->fact(
                        'phone_number',
                        $phone,
                        $lineNo,
                        $sourceText,
                        $label,
                        'basic-info',
                        'core.primary_contact_number',
                        (string) ($activePerson['relation_type'] ?? '')
                    );
                }
                continue;
            }

            $basicFact = $this->basicFieldFact($normalizedLabel, $value, $lineNo, $sourceText, $label, $phones, $activeParentContext);
            if ($basicFact !== []) {
                $activeOtherRelatives = false;
                $activePerson = null;
                $activeParentContext = $this->shouldKeepParentContext($basicFact);
                foreach ($basicFact as $fact) {
                    $facts[] = $fact;
                    if (($fact['fact_type'] ?? '') === 'address_line') {
                        $activeAddressField = (string) ($fact['target_field'] ?? '');
                    }
                }
                continue;
            }

            if ($activeOtherRelatives && $this->looksLikeOtherRelativesContinuation($line)) {
                $facts[] = $this->fact('other_relatives_text', $line, $lineNo, $sourceText, '', 'alliance', 'core.other_relatives_text');
                foreach ($phones as $phone) {
                    $facts[] = $this->fact('phone_number', $phone, $lineNo, $sourceText, '', 'alliance', 'core.other_relatives_text');
                }
                continue;
            }

            if ($activePerson !== null && $this->looksLikeContactOnlyContinuation($line)) {
                foreach ($phones as $phone) {
                    $facts[] = $this->fact(
                        'phone_number',
                        $phone,
                        $lineNo,
                        $sourceText,
                        '',
                        (string) $activePerson['section'],
                        (string) $activePerson['target_field'].'.contact_number',
                        (string) $activePerson['relation_type']
                    );
                }
                continue;
            }

            if ($activePerson !== null && $this->looksLikePersonAddressContinuation($line)) {
                $facts[] = $this->fact(
                    'address_line',
                    $line,
                    $lineNo,
                    $sourceText,
                    '',
                    (string) $activePerson['section'],
                    (string) $activePerson['target_field'].'.address_line',
                    (string) $activePerson['relation_type']
                );
                foreach ($phones as $phone) {
                    $facts[] = $this->fact(
                        'phone_number',
                        $phone,
                        $lineNo,
                        $sourceText,
                        '',
                        (string) $activePerson['section'],
                        (string) $activePerson['target_field'].'.contact_number',
                        (string) $activePerson['relation_type']
                    );
                }
                continue;
            }

            if ($activeAddressField !== null && $this->looksLikeAddressContinuation($line)) {
                $facts[] = $this->fact('address_line', $line, $lineNo, $sourceText, '', $this->sectionForAddressField($activeAddressField), $activeAddressField);
                foreach ($phones as $phone) {
                    $facts[] = $this->fact('phone_number', $phone, $lineNo, $sourceText, '', $this->sectionForAddressField($activeAddressField), $activeAddressField);
                }
                continue;
            }

            if ($phones !== []) {
                $activeParentContext = false;
                foreach ($phones as $phone) {
                    $facts[] = $this->fact('phone_number', $phone, $lineNo, $sourceText, $label, 'basic-info', 'core.primary_contact_number');
                }
            }
        }

        return $this->dedupeFacts($facts);
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitLabelAndValue(string $line): array
    {
        $line = $this->normalizeDecorativeLabelSeparators($line);
        if (preg_match('/^\s*[-–—]?\s*([^:.\-–—]+?)\s*(?::\s*-\s*|[:\-–—]\s*|\.\s*)(.+)$/u', $line, $m)) {
            return [trim((string) $m[1]), trim((string) $m[2])];
        }

        return ['', trim($line)];
    }

    /**
     * @param  list<string>  $phones
     * @return list<array<string, mixed>>
     */
    private function relationFacts(string $relationType, string $value, int $lineNo, string $sourceText, string $label, array $phones): array
    {
        $section = $this->relationSchema->sectionForRelationType($relationType);
        if ($section === null) {
            return [];
        }

        if ($this->isSiblingCountOrStatusOnly($relationType, $value)) {
            return [];
        }

        $targetPrefix = $this->targetFieldForRelation($relationType);
        $facts = [];

        if ($relationType === 'maternal_address_ajol') {
            $facts[] = $this->fact('address_line', $value, $lineNo, $sourceText, $label, $section, $targetPrefix.'.address_line', $relationType);

            return $facts;
        }

        foreach ($this->splitNumberedPersonValues($value) as $personValue) {
            [$name, $occupation, $address, $notes] = $this->parsePersonLikeValue($personValue);
            if ($name !== '') {
                $facts[] = $this->fact('person_name', $name, $lineNo, $sourceText, $label, $section, $targetPrefix.'.name', $relationType);
            }
            if ($occupation !== '') {
                $facts[] = $this->fact('field_value', $occupation, $lineNo, $sourceText, $label, $section, $targetPrefix.'.occupation', $relationType);
            }
            if ($address !== '') {
                $facts[] = $this->fact('address_line', $address, $lineNo, $sourceText, $label, $section, $targetPrefix.'.address_line', $relationType);
            }
            if ($notes !== '' && $this->shouldEmitNoteFact($notes)) {
                $facts[] = $this->fact('text_detail', $notes, $lineNo, $sourceText, $label, $section, $targetPrefix.'.notes', $relationType);
            }
            foreach ($this->extractPhones($personValue) as $phone) {
                $facts[] = $this->fact('phone_number', $phone, $lineNo, $sourceText, $label, $section, $targetPrefix.'.contact_number', $relationType);
            }
        }

        return $facts;
    }

    /**
     * @param  list<string>  $phones
     * @return list<array<string, mixed>>
     */
    private function basicFieldFact(string $label, string $value, int $lineNo, string $sourceText, string $sourceLabel, array $phones, bool $activeParentContext = false): array
    {
        $map = [
            'नाव' => ['person_name', 'basic-info', 'core.full_name'],
            'मुलाचे नाव' => ['person_name', 'basic-info', 'core.full_name'],
            'मुलीचे नाव' => ['person_name', 'basic-info', 'core.full_name'],
            'जन्मतारीख' => ['field_value', 'basic-info', 'core.date_of_birth'],
            'जन्म तारीख' => ['field_value', 'basic-info', 'core.date_of_birth'],
            'जन्मवेळ' => ['field_value', 'basic-info', 'core.birth_time'],
            'जन्म वेळ' => ['field_value', 'basic-info', 'core.birth_time'],
            'जन्मस्थळ' => ['field_value', 'basic-info', 'core.birth_place_text'],
            'जन्म स्थळ' => ['field_value', 'basic-info', 'core.birth_place_text'],
            'धर्म' => ['field_value', 'basic-info', 'core.religion'],
            'जात' => ['field_value', 'basic-info', 'core.caste'],
            'उपजात' => ['field_value', 'basic-info', 'core.sub_caste'],
            'उप जात' => ['field_value', 'basic-info', 'core.sub_caste'],
            'उंची' => ['field_value', 'physical', 'core.height_cm'],
            'रंग' => ['field_value', 'physical', 'core.complexion'],
            'रक्तगट' => ['field_value', 'physical', 'core.blood_group'],
            'रक्त गट' => ['field_value', 'physical', 'core.blood_group'],
            'शिक्षण' => ['field_value', 'education-career', 'core.highest_education'],
            'शिक्षण / नोकरी' => ['field_value', 'education-career', 'core.highest_education'],
            'व्यवसाय' => ['field_value', 'education-career', 'core.occupation_title'],
            'नोकरी' => ['field_value', 'education-career', 'core.occupation_title'],
            'कंपनी' => ['field_value', 'education-career', 'core.company_name'],
            'वार्षिक उत्पन्न' => ['field_value', 'education-career', 'core.annual_income'],
            'उत्पन्न' => ['field_value', 'education-career', 'core.annual_income'],
            'वडील' => ['person_name', 'family-details', 'core.father_name'],
            'वडिलांचे नाव' => ['person_name', 'family-details', 'core.father_name'],
            'आई' => ['person_name', 'family-details', 'core.mother_name'],
            'आईचे नाव' => ['person_name', 'family-details', 'core.mother_name'],
            'मूळ गाव' => ['address_line', 'basic-info', 'addresses.native.address_line'],
            'मुळगाव' => ['address_line', 'basic-info', 'addresses.native.address_line'],
            'मूळगाव' => ['address_line', 'basic-info', 'addresses.native.address_line'],
            'निवास' => ['address_line', 'basic-info', 'addresses.current.address_line'],
            'सध्याचा पत्ता' => ['address_line', 'basic-info', 'addresses.current.address_line'],
            'पत्ता' => ['address_line', 'basic-info', 'addresses.other.address_line'],
            'घरचा पत्ता' => ['address_line', 'family-details', 'parents_addresses.current.address_line'],
            'आजोळचा पत्ता' => ['address_line', 'alliance', 'relatives.maternal_address_ajol.address_line'],
            'अपेक्षा' => ['preference_text', 'about-preferences', 'preferences.expectations'],
            'देवक' => ['horoscope_value', 'horoscope', 'horoscope.devak'],
            'नाडी' => ['horoscope_value', 'horoscope', 'horoscope.nadi'],
            'राशी' => ['horoscope_value', 'horoscope', 'horoscope.rashi'],
            'रास' => ['horoscope_value', 'horoscope', 'horoscope.rashi'],
            'नक्षत्र' => ['horoscope_value', 'horoscope', 'horoscope.nakshatra'],
            'गण' => ['horoscope_value', 'horoscope', 'horoscope.gan'],
            'गोत्र' => ['horoscope_value', 'horoscope', 'horoscope.gotra'],
            'कुलदैवत' => ['horoscope_value', 'horoscope', 'horoscope.kuldaivat'],
            'कुल दैवत' => ['horoscope_value', 'horoscope', 'horoscope.kuldaivat'],
            'प्रॉपर्टी' => ['field_value', 'property', 'property.summary'],
            'प्रॉपर्टी तपशील' => ['field_value', 'property', 'property.summary'],
            'स्थावर मालमत्ता' => ['field_value', 'property', 'property.summary'],
            'भ्रमणध्वनी' => ['phone_number', 'basic-info', 'core.primary_contact_number'],
            'मोबाईल' => ['phone_number', 'basic-info', 'core.primary_contact_number'],
            'मोबाइल' => ['phone_number', 'basic-info', 'core.primary_contact_number'],
            'मो. नं' => ['phone_number', 'basic-info', 'core.primary_contact_number'],
            'मो. नं.' => ['phone_number', 'basic-info', 'core.primary_contact_number'],
            'मो नं' => ['phone_number', 'basic-info', 'core.primary_contact_number'],
            'मो नं.' => ['phone_number', 'basic-info', 'core.primary_contact_number'],
        ];

        $spec = $map[$label] ?? null;
        if ($spec === null) {
            return [];
        }

        [$factType, $section, $field] = $spec;
        if ($label === 'पत्ता' && $activeParentContext) {
            $section = 'family-details';
            $field = 'parents_addresses.address_line';
        } elseif ($label === 'पत्ता' && preg_match('/(?:मु\.?\s*पो\.?|ता\.|जि\.)/u', $value)) {
            $field = 'addresses.native.address_line';
        }
        $facts = [];
        $value = $this->trimAtNextKnownLabel($value);

        if ($field === 'core.father_name' || $field === 'core.mother_name') {
            [$name, $occupation] = $this->parseParentValue($value);
            if ($name !== '') {
                $facts[] = $this->fact('person_name', $name, $lineNo, $sourceText, $sourceLabel, $section, $field);
            }
            if ($occupation !== '') {
                $occupationField = $field === 'core.father_name' ? 'core.father_occupation' : 'core.mother_occupation';
                $facts[] = $this->fact('field_value', $occupation, $lineNo, $sourceText, $sourceLabel, $section, $occupationField);
            }
            foreach ($phones as $index => $phone) {
                $contactFieldPrefix = $field === 'core.father_name' ? 'core.father_contact_' : 'core.mother_contact_';
                $facts[] = $this->fact('phone_number', $phone, $lineNo, $sourceText, $sourceLabel, $section, $contactFieldPrefix.($index + 1));
            }

            return $facts;
        }

        if ($field === 'core.caste' && preg_match('/^(हिंदू|मुस्लिम|ख्रिश्चन|जैन|बौद्ध)\s+(.+)$/u', $value, $m)) {
            return [
                $this->fact('field_value', trim((string) $m[1]), $lineNo, $sourceText, $sourceLabel, 'basic-info', 'core.religion'),
                $this->fact('field_value', trim((string) $m[2]), $lineNo, $sourceText, $sourceLabel, 'basic-info', 'core.caste'),
            ];
        }

        if ($field === 'core.caste') {
            $splitFacts = $this->splitCompoundCasteFacts($value, $lineNo, $sourceText, $sourceLabel);
            if ($splitFacts !== []) {
                return $splitFacts;
            }
        }

        if ($field === 'core.height_cm' && preg_match('/(?:फूट|फुट|इंच|\')/u', $value)) {
            return [];
        }

        if ($field === 'core.blood_group') {
            $value = OcrNormalize::normalizeBloodGroup($value) ?? $value;
        }

        if ($field === 'core.occupation_title' && preg_match('/^([^-]+)-\s*(.+)$/u', $value, $m)) {
            return [
                $this->fact('field_value', trim((string) $m[2]), $lineNo, $sourceText, $sourceLabel, 'education-career', 'core.occupation_title'),
                $this->fact('field_value', trim((string) $m[1]), $lineNo, $sourceText, $sourceLabel, 'education-career', 'core.company_name'),
            ];
        }

        if ($field === 'core.occupation_title' && preg_match('/[A-Z]{3,}/', $value)) {
            $field = 'core.company_name';
        }

        if ($factType === 'phone_number') {
            foreach ($phones as $phone) {
                $facts[] = $this->fact('phone_number', $phone, $lineNo, $sourceText, $sourceLabel, $section, $field);
            }

            return $facts;
        }

        if ($factType === 'address_line') {
            $value = $this->cleanAddressValue($value);
        }
        if ($value !== '') {
            $facts[] = $this->fact($factType, $value, $lineNo, $sourceText, $sourceLabel, $section, $field);
        }
        foreach ($phones as $phone) {
            $phoneSection = $factType === 'address_line' ? 'basic-info' : $section;
            $phoneField = $factType === 'address_line' ? 'core.primary_contact_number' : $field;
            $facts[] = $this->fact('phone_number', $phone, $lineNo, $sourceText, $sourceLabel, $phoneSection, $phoneField);
        }

        return $facts;
    }

    /**
     * @return array{0:string,1:string,2:string,3:string}
     */
    private function parsePersonLikeValue(string $value): array
    {
        $phones = $this->extractPhones($value);
        $work = OcrNormalize::normalizeDigits($this->normalizeDecorativeLabelSeparators($value));
        foreach ($phones as $phone) {
            $work = str_replace($phone, '', $work);
        }
        $work = preg_replace('/(?:मो\.?\s*नं\.?|मोबाईल|मोबाइल|संपर्क)\s*[-: ]*/u', '', $work) ?? $work;

        $occupation = '';
        if (preg_match('/\(([^()]*(?:teacher|engineer|doctor|business|service|job|शिक्षक|शिक्षिका|प्राध्यापक|शेती|गृहिणी|सेवानिवृत्त|डॉक्टर|इंजिनियर|व्यवसाय|व्यवसाईक|नोकरी|सदस्य)[^()]*)\)/ui', $work, $m)) {
            $occupation = $this->cleanOccupationFragment(trim((string) $m[1]));
            $work = trim(str_replace($m[0], '', $work));
        }

        $address = '';
        $hasOpenAddressFragment = substr_count($work, '(') > substr_count($work, ')');
        if ($hasOpenAddressFragment && str_contains($work, '(')) {
            $work = trim((string) strstr($work, '(', true));
        }
        if (! $hasOpenAddressFragment && preg_match('/\(([^()]*(?:रा\.|मु\.?\s*पो\.?|ता\.|जि\.)[^()]*)\)$/u', $work, $m)) {
            $address = trim((string) $m[1]);
            $work = trim(str_replace((string) $m[0], '', $work));
        } elseif (! $hasOpenAddressFragment && preg_match('/(?:पत्ता|पता|रा\.|राहणार|मु\.?\s*पो\.?)\s*[:\-.]?\s*(.+)$/u', $work, $m)) {
            $address = trim((string) $m[1]);
            $work = trim((string) substr($work, 0, (int) strpos($work, $m[0])));
        }

        $notes = [];
        if (preg_match_all('/\(([^()]*)\)/u', $work, $matches)) {
            foreach ($matches[1] as $note) {
                $note = trim((string) $note);
                if ($note !== '') {
                    $notes[] = $note;
                }
            }
            $work = preg_replace('/\([^()]*\)/u', '', $work) ?? $work;
        }

        $name = trim((string) preg_replace('/^\s*(?:\d+|[०-९]+)[\).]\s*/u', '', $work));
        $name = trim((string) preg_replace('/^\s*(?:एक|दोन|तीन|चार|पाच|सहा|सात|आठ|नऊ|दहा|1|2|3|4|5|6|7|8|9|10)\s*/u', '', $name));
        $name = trim((string) preg_replace('/^\s*(?:श्री\.?|सौ\.?|कु\.?|चि\.?|कै\.?|डॉ\.?)\s*/u', '', $name));
        $name = trim((string) preg_replace('/\b(?:Whats\s*App|Whatsapp)\b/ui', '', $name));
        $name = trim($name, " \t\n\r\0\x0B(");
        $address = trim($address, " \t\n\r\0\x0B)");

        return [$name, $occupation, trim($address), implode('; ', array_unique($notes))];
    }

    /**
     * @return list<string>
     */
    private function splitNumberedPersonValues(string $value): array
    {
        $value = trim($value);
        $numberedParts = preg_split('/(?:\R|\s+)(?=(?:\d+|[०-९]+)[\).])/u', $value) ?: [];
        if (count($numberedParts) > 1) {
            $out = [];
            foreach ($numberedParts as $part) {
                $part = trim((string) preg_replace('/^\s*(?:\d+|[०-९]+)[\).]\s*/u', '', $part));
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
            $part = trim((string) preg_replace('/^\s*(?:\d+|[०-९]+)[\).]\s*/u', '', $part));
            if ($part !== '') {
                $out[] = $part;
            }
        }

        return $out !== [] ? $out : [trim($value)];
    }

    /**
     * @return array{0:string,1:string}
     */
    private function parseParentValue(string $value): array
    {
        $value = $this->normalizeDecorativeLabelSeparators($value);
        $inlineOccupation = '';
        if (preg_match('/^(.*?)(?:\s*[,.।]?\s*)(?:नोकरी|व्यवसाय)\s*(?::\s*-\s*|[:>\-]\s*)(.+)$/u', $value, $m)) {
            $prefix = (string) $m[1];
            $openParens = substr_count($prefix, '(') + substr_count($prefix, '{');
            $closeParens = substr_count($prefix, ')') + substr_count($prefix, '}');
            if ($openParens <= $closeParens) {
                $value = trim($prefix);
                $inlineOccupation = trim((string) $m[2]);
            }
        }
        [$name, $occupation, $address, $notes] = $this->parsePersonLikeValue($value);
        unset($address, $notes);
        if ($occupation === '' && $inlineOccupation !== '') {
            $occupation = $this->cleanOccupationFragment($inlineOccupation);
        }

        return [$name, $occupation];
    }

    private function cleanOccupationFragment(string $value): string
    {
        $value = OcrNormalize::normalizeDigits($value);
        $value = preg_replace('/(?:मो\.?\s*नं\.?|मोबाईल|मोबाइल|संपर्क)\s*[-: ]*[6-9]\d{9}/u', '', $value) ?? $value;
        $value = preg_replace('/(?<!\d)[6-9]\d{9}(?!\d)/u', '', $value) ?? $value;
        $value = trim((string) preg_replace('/[\s,;:|\/\-–—]+$/u', '', trim($value)));

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function targetFieldForRelation(string $relationType): string
    {
        return match ($relationType) {
            'brother' => 'siblings.brother',
            'sister' => 'siblings.sister',
            'brother_wife' => 'siblings.spouse',
            'sister_husband' => 'siblings.spouse',
            default => 'relatives.'.$relationType,
        };
    }

    private function sectionForAddressField(string $field): string
    {
        return str_starts_with($field, 'parents_addresses') ? 'family-details' : 'basic-info';
    }

    private function looksLikeContactOnlyContinuation(string $line): bool
    {
        return (bool) preg_match('/^(?:मो\.?|मोबाईल|मोबाइल|संपर्क|contact)/ui', trim($line));
    }

    private function looksLikeAddressContinuation(string $line): bool
    {
        return (bool) preg_match('/^(?:\d+|[०-९]+)[\).]|^(?:रा\.|मु\.?\s*पो\.?|पत्ता|ता\.|जि\.)/u', trim($line));
    }

    private function looksLikePersonAddressContinuation(string $line): bool
    {
        return (bool) preg_match('/^(?:रा\.|मु\.?\s*पो\.?|पत्ता|ता\.|जि\.)/u', trim($line));
    }

    private function looksLikeOtherRelativesContinuation(string $line): bool
    {
        return ! str_contains($line, ':') && ! str_contains($line, ':-') && ! str_starts_with(trim($line), '|');
    }

    /**
     * @return list<string>
     */
    private function extractPhones(string $text): array
    {
        $text = OcrNormalize::normalizeDigits($text);
        preg_match_all('/(?<!\d)[6-9]\d{9}(?!\d)/u', $text, $matches);

        return array_values(array_unique($matches[0] ?? []));
    }

    /**
     * @return array<string, mixed>
     */
    private function fact(
        string $factType,
        string $value,
        int $sourceLineNo,
        string $sourceText,
        string $sourceLabel,
        string $targetSection,
        string $targetField,
        ?string $relationType = null
    ): array {
        return array_filter([
            'fact_type' => $factType,
            'value' => trim($value),
            'source_line_no' => $sourceLineNo,
            'source_text' => $sourceText,
            'source_label' => trim($sourceLabel),
            'target_section' => $targetSection,
            'target_field' => $targetField,
            'relation_type' => $relationType,
        ], static fn ($item) => $item !== null);
    }

    /**
     * @param  list<array<string, mixed>>  $facts
     * @return list<array<string, mixed>>
     */
    private function dedupeFacts(array $facts): array
    {
        $out = [];
        $seen = [];
        foreach ($facts as $fact) {
            $identity = implode('|', [
                (string) ($fact['fact_type'] ?? ''),
                mb_strtolower(trim((string) ($fact['value'] ?? ''))),
                (string) ($fact['source_line_no'] ?? ''),
                (string) ($fact['target_section'] ?? ''),
                (string) ($fact['target_field'] ?? ''),
            ]);
            if ($identity === '||||' || isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $out[] = $fact;
        }

        return $out;
    }

    private function normalizeLabel(string $label): string
    {
        $label = trim($label);
        $label = preg_replace('/\s+/u', ' ', $label) ?? $label;

        return $label;
    }

    private function trimAtNextKnownLabel(string $value): string
    {
        $value = $this->normalizeDecorativeLabelSeparators($value);
        if (preg_match('/^(.+?)\s+(?:(?:जन्म\s+तारीख|जन्मतारीख|जन्म\s+वेळ|जन्मवेळ|जन्म\s+ठिकाण|जन्म\s+स्थळ|धर्म|जात|उपजात|उप\s+जात|उंची|रंग|वर्ण|शिक्षण|नोकरी|व्यवसाय|कंपनी|देवक|कुलदैवत|कुल\s+दैवत|नक्षत्र|नाडी|गण|रास|राशी|गोत्र|अपेक्षा|पत्ता|निवास|मामा|चुलते|आत्या|वडील|आई)\s*(?::\s*-\s*|[:\-]))/u', $value, $m)) {
            return trim((string) $m[1]);
        }

        return trim($value);
    }

    private function isSiblingCountOrStatusOnly(string $relationType, string $value): bool
    {
        if (! in_array($relationType, ['brother', 'sister'], true)) {
            return false;
        }

        $value = trim(OcrNormalize::normalizeDigits($value));

        return preg_match('/^(?:नाही|शून्य|0|एक|दोन|तीन|चार|पाच)(?:\s*\([^)]*\))?$/u', $value) === 1;
    }

    private function shouldEmitNoteFact(string $notes): bool
    {
        return preg_match('/(?:B\.|M\.|MBA|BE|B\.Com|B\.A|M\.A|मोठे\s+मामा|B\.Ed|M\.Ed|Diploma|ITI)/ui', $notes) === 1;
    }

    private function cleanAddressValue(string $value): string
    {
        $value = OcrNormalize::normalizeDigits($this->normalizeDecorativeLabelSeparators($value));
        $value = preg_replace('/(?:मो\.?\s*नं?\.?|मोबाईल|मोबाइल|संपर्क)\s*[:\-\.]?\s*[6-9][0-9\s\/-]{9,}\.?/u', '', $value) ?? $value;
        $value = preg_replace('/(?<!\d)[6-9]\d{9}(?!\d)/u', '', $value) ?? $value;
        $value = preg_replace('/\s*(?:मो\.?\s*नं?\.?|मोबाईल|मोबाइल|संपर्क)\s*[:\-\.]*\s*$/u', '', $value) ?? $value;

        return trim((string) preg_replace('/[\s,.।]+$/u', '', trim($value)));
    }

    /**
     * @param  list<array<string, mixed>>  $facts
     */
    private function shouldKeepParentContext(array $facts): bool
    {
        foreach ($facts as $fact) {
            $field = (string) ($fact['target_field'] ?? '');
            if (str_starts_with($field, 'core.father_') || str_starts_with($field, 'core.mother_') || str_starts_with($field, 'parents_addresses.')) {
                return true;
            }
        }

        return false;
    }

    private function normalizeDecorativeLabelSeparators(string $line): string
    {
        static $pattern = null;
        if ($pattern === null) {
            $labels = array_map(static fn (string $label): string => preg_quote($label, '/'), self::OCR_DECORATIVE_LABELS);
            usort($labels, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
            $pattern = '/(?<!\S)('.implode('|', $labels).')\s*[8८]\s*/u';
        }

        return preg_replace($pattern, '$1 : ', $line) ?? $line;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function splitCompoundCasteFacts(string $value, int $lineNo, string $sourceText, string $sourceLabel): array
    {
        $value = trim(str_replace(['{', '}'], ['(', ')'], $value));
        $kuliPattern = '(?:कुळी|क्‌ळी|क[\x{094D}\x{200C}\s]*ळी|कळी)';
        $facts = [];

        if (preg_match('/([0-9०-९]+\s*'.$kuliPattern.')\s*हिंद[ुू]\s*[-–—]?\s*मराठा/u', $value, $m)
            || preg_match('/हिंद[ुू]\s*[-–—]?\s*मराठा\s*\(?\s*([0-9०-९]+\s*'.$kuliPattern.')\s*\)?/u', $value, $m)
            || preg_match('/हिंद[ुू]\s*[-–]?\s*([0-9०-९]+\s*'.$kuliPattern.')\s*मराठा/u', $value, $m)
            || preg_match('/([0-9०-९]+\s*'.$kuliPattern.')\s*मराठा/u', $value, $m)) {
            $facts[] = $this->fact('field_value', 'हिंदू', $lineNo, $sourceText, $sourceLabel, 'basic-info', 'core.religion');
            $facts[] = $this->fact('field_value', 'मराठा', $lineNo, $sourceText, $sourceLabel, 'basic-info', 'core.caste');
            $facts[] = $this->fact(
                'field_value',
                OcrNormalize::normalizeDigits(trim($m[1])),
                $lineNo,
                $sourceText,
                $sourceLabel,
                'basic-info',
                'core.sub_caste'
            );

            return $facts;
        }

        if (preg_match('/हिंद[ुू]\s*[-–—]?\s*मराठा/u', $value)) {
            $facts[] = $this->fact('field_value', 'हिंदू', $lineNo, $sourceText, $sourceLabel, 'basic-info', 'core.religion');
            $facts[] = $this->fact('field_value', 'मराठा', $lineNo, $sourceText, $sourceLabel, 'basic-info', 'core.caste');

            return $facts;
        }

        return [];
    }
}
