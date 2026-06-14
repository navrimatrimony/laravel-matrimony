<?php

namespace App\Services\Parsing;

use App\Services\Ocr\OcrNormalize;

class IntakeNormalizedDraftCoverageAuditor
{
    public function __construct(
        private readonly WizardRelationSchema $relationSchema
    ) {}

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function audit(array $draft): array
    {
        $extractedFacts = array_values(array_filter(is_array($draft['extracted_facts'] ?? null) ? $draft['extracted_facts'] : [], 'is_array'));
        $visibleFacts = $this->visibleFacts($draft);

        $visibleByExact = [];
        $visibleByValueField = [];
        $visibleCounts = [];
        foreach ($visibleFacts as $fact) {
            $exact = $this->factIdentity($fact, true);
            $valueField = $this->factIdentity($fact, false);
            if ($exact !== '') {
                $visibleByExact[$exact][] = $fact;
            }
            if ($valueField !== '') {
                $visibleByValueField[$valueField][] = $fact;
            }
            $visibleCounts[$exact] = ($visibleCounts[$exact] ?? 0) + 1;
        }

        $missingFacts = [];
        $wrongSectionFacts = [];
        $mixedFacts = [];
        foreach ($extractedFacts as $fact) {
            $exact = $this->factIdentity($fact, true);
            if ($exact !== '' && isset($visibleByExact[$exact])) {
                continue;
            }

            $valueField = $this->factIdentity($fact, false);
            $visibleVariants = $valueField !== '' ? ($visibleByValueField[$valueField] ?? []) : [];
            if ($visibleVariants !== []) {
                $sourceRelation = trim((string) ($fact['relation_type'] ?? ''));
                $matchedRelation = false;
                foreach ($visibleVariants as $variant) {
                    if ($sourceRelation !== '' && $sourceRelation === trim((string) ($variant['relation_type'] ?? ''))) {
                        $matchedRelation = true;
                        break;
                    }
                }
                if ($sourceRelation !== '' && ! $matchedRelation) {
                    $mixedFacts[] = $this->coverageFactSummary($fact);
                    continue;
                }
                $wrongSectionFacts[] = $this->coverageFactSummary($fact);
                continue;
            }

            if ($this->isDecomposedCasteLineFullyMapped($fact, $draft)) {
                continue;
            }

            if ($this->isOtherRelativesFactFullyMapped($fact, $draft)) {
                continue;
            }

            if ($this->isCompoundFactFullyMapped($fact, $visibleFacts)) {
                continue;
            }

            $missingFacts[] = $this->coverageFactSummary($fact);
        }

        $duplicateFacts = [];
        foreach ($visibleFacts as $fact) {
            $exact = $this->factIdentity($fact, true);
            if ($exact === '' || ($visibleCounts[$exact] ?? 0) < 2 || (($fact['fact_type'] ?? '') === 'address_line')) {
                continue;
            }
            $duplicateFacts[$exact] = $this->coverageFactSummary($fact);
        }

        $suspiciousMappedFacts = $this->suspiciousMappedFacts($draft);

        return [
            'missing_facts' => array_values($missingFacts),
            'wrong_section_facts' => array_values($wrongSectionFacts),
            'duplicate_facts' => array_values($duplicateFacts),
            'mixed_facts' => array_values($mixedFacts),
            'suspicious_mapped_facts' => $suspiciousMappedFacts,
            'source_fact_count' => count($extractedFacts),
            'visible_fact_count' => count($visibleFacts),
            'review_flags' => $this->reviewFlagsFromCoverageIssues(
                $missingFacts,
                $wrongSectionFacts,
                array_values($duplicateFacts),
                $mixedFacts,
                $suspiciousMappedFacts
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return list<array<string, mixed>>
     */
    private function visibleFacts(array $draft): array
    {
        $normalized = is_array($draft['normalized'] ?? null) ? $draft['normalized'] : [];
        $core = is_array($normalized['core'] ?? null) ? $normalized['core'] : [];
        $facts = [];

        foreach ([
            ['basic-info', 'core.full_name', 'person_name'],
            ['basic-info', 'core.gender', 'field_value'],
            ['basic-info', 'core.date_of_birth', 'field_value'],
            ['basic-info', 'core.birth_time', 'field_value'],
            ['basic-info', 'core.birth_place_text', 'field_value'],
            ['basic-info', 'core.religion', 'field_value'],
            ['basic-info', 'core.caste', 'field_value'],
            ['basic-info', 'core.sub_caste', 'field_value'],
            ['basic-info', 'core.marital_status', 'field_value'],
            ['physical', 'core.height_cm', 'field_value'],
            ['physical', 'core.complexion', 'field_value'],
            ['physical', 'core.blood_group', 'field_value'],
            ['education-career', 'core.highest_education', 'field_value'],
            ['education-career', 'core.occupation_title', 'field_value'],
            ['education-career', 'core.company_name', 'field_value'],
            ['education-career', 'core.annual_income', 'field_value'],
            ['education-career', 'core.work_location_text', 'field_value'],
            ['family-details', 'core.father_name', 'person_name'],
            ['family-details', 'core.father_occupation', 'field_value'],
            ['family-details', 'core.mother_name', 'person_name'],
            ['family-details', 'core.mother_occupation', 'field_value'],
            ['alliance', 'core.other_relatives_text', 'other_relatives_text'],
        ] as [$section, $field, $factType]) {
            $value = data_get($normalized, str_replace('core.', 'core.', $field));
            $text = $this->stringify($value);
            if ($text !== '') {
                $facts[] = ['fact_type' => $factType, 'value' => $text, 'target_section' => $section, 'target_field' => $field];
            }
        }

        foreach (['father_contact_1', 'father_contact_2', 'father_contact_3', 'mother_contact_1', 'mother_contact_2', 'mother_contact_3'] as $field) {
            $value = $this->stringify($core[$field] ?? null);
            if ($value !== '') {
                $facts[] = ['fact_type' => 'phone_number', 'value' => $value, 'target_section' => 'family-details', 'target_field' => 'core.'.$field];
            }
        }

        foreach (($normalized['addresses'] ?? []) as $address) {
            if (! is_array($address)) {
                continue;
            }
            $value = $this->stringify($address['address_line'] ?? $address['raw'] ?? null);
            if ($value === '') {
                continue;
            }
            $type = trim((string) ($address['address_type'] ?? $address['type'] ?? 'other'));
            $facts[] = ['fact_type' => 'address_line', 'value' => $value, 'target_section' => 'basic-info', 'target_field' => 'addresses.'.$type.'.address_line'];
        }

        foreach (($normalized['parents_addresses'] ?? []) as $address) {
            if (! is_array($address)) {
                continue;
            }
            $value = $this->stringify($address['address_line'] ?? $address['raw'] ?? null);
            if ($value !== '') {
                $facts[] = ['fact_type' => 'address_line', 'value' => $value, 'target_section' => 'family-details', 'target_field' => 'parents_addresses.address_line'];
            }
        }

        $propertySummary = $this->stringify(data_get($normalized, 'property_summary.summary_text'));
        if ($propertySummary !== '') {
            $facts[] = ['fact_type' => 'field_value', 'value' => $propertySummary, 'target_section' => 'property', 'target_field' => 'core.property_details'];
            $strippedPropertySummary = $this->stripPropertyLabel($propertySummary);
            if ($strippedPropertySummary !== '' && $strippedPropertySummary !== $propertySummary) {
                $facts[] = ['fact_type' => 'field_value', 'value' => $strippedPropertySummary, 'target_section' => 'property', 'target_field' => 'core.property_details'];
            }
        }

        foreach ($this->visibleUserContactFacts($core, $normalized) as $fact) {
            $facts[] = $fact;
        }

        foreach (($normalized['siblings'] ?? []) as $sibling) {
            if (! is_array($sibling)) {
                continue;
            }
            $relationType = trim((string) ($sibling['relation_type'] ?? ''));
            foreach ([
                'name' => 'person_name',
                'occupation' => 'field_value',
                'contact_number' => 'phone_number',
                'contact_number_2' => 'phone_number',
                'contact_number_3' => 'phone_number',
                'address_line' => 'address_line',
                'notes' => 'text_detail',
            ] as $field => $factType) {
                $value = $this->stringify($sibling[$field] ?? null);
                if ($value !== '') {
                    $facts[] = ['fact_type' => $factType, 'value' => $value, 'target_section' => 'siblings', 'target_field' => 'siblings.'.$relationType.'.'.$field, 'relation_type' => $relationType];
                }
            }
            $spouse = is_array($sibling['spouse'] ?? null) ? $sibling['spouse'] : [];
            $spouseRelation = $relationType === 'brother' ? 'brother_wife' : ($relationType === 'sister' ? 'sister_husband' : '');
            foreach ([
                'name' => 'person_name',
                'occupation' => 'field_value',
                'occupation_title' => 'field_value',
                'contact_number' => 'phone_number',
                'contact_number_2' => 'phone_number',
                'contact_number_3' => 'phone_number',
                'address_line' => 'address_line',
                'additional_info' => 'text_detail',
                'notes' => 'text_detail',
            ] as $field => $factType) {
                $value = $this->stringify($spouse[$field] ?? null);
                if ($value !== '') {
                    $facts[] = ['fact_type' => $factType, 'value' => $value, 'target_section' => 'siblings', 'target_field' => 'siblings.spouse.'.$field, 'relation_type' => $spouseRelation];
                }
            }
        }

        foreach (($normalized['relatives'] ?? []) as $relative) {
            if (! is_array($relative)) {
                continue;
            }
            $relationType = trim((string) ($relative['relation_type'] ?? ''));
            $section = $this->relationSchema->sectionForRelationType($relationType) ?? 'review_needed';
            foreach ([
                'name' => 'person_name',
                'occupation' => 'field_value',
                'contact_number' => 'phone_number',
                'address_line' => 'address_line',
                'notes' => 'text_detail',
            ] as $field => $factType) {
                $value = $this->stringify($relative[$field] ?? null);
                if ($value !== '') {
                    $facts[] = ['fact_type' => $factType, 'value' => $value, 'target_section' => $section, 'target_field' => 'relatives.'.$relationType.'.'.$field, 'relation_type' => $relationType];
                }
            }
        }

        foreach ((array) ($normalized['horoscope'] ?? []) as $field => $value) {
            if ($field === 'raw') {
                continue;
            }
            $text = $this->stringify($value);
            if ($text !== '') {
                $facts[] = ['fact_type' => 'horoscope_value', 'value' => $text, 'target_section' => 'horoscope', 'target_field' => 'horoscope.'.$field];
            }
        }

        foreach ((array) ($normalized['preferences'] ?? []) as $field => $value) {
            $text = $this->stringify($value);
            if ($text !== '') {
                $facts[] = ['fact_type' => 'preference_text', 'value' => $text, 'target_section' => 'about-preferences', 'target_field' => 'preferences.'.$field];
            }
        }

        return $facts;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return list<array<string, mixed>>
     */
    private function suspiciousMappedFacts(array $draft): array
    {
        $normalized = is_array($draft['normalized'] ?? null) ? $draft['normalized'] : [];
        $core = is_array($normalized['core'] ?? null) ? $normalized['core'] : [];
        $issues = [];

        $fullName = $this->stringify($core['full_name'] ?? null);
        if ($fullName !== '' && $this->looksLikeAddressText($fullName)) {
            $issues[] = ['fact_type' => 'person_name', 'value' => $fullName, 'target_section' => 'basic-info', 'target_field' => 'core.full_name', 'reason' => 'full_name_looks_like_address'];
        }

        foreach (['core.father_name' => $core['father_name'] ?? null, 'core.mother_name' => $core['mother_name'] ?? null] as $field => $value) {
            $text = $this->stringify($value);
            if ($text !== '' && $this->looksLikeSectionHeading($text)) {
                $issues[] = ['fact_type' => 'person_name', 'value' => $text, 'target_section' => 'family-details', 'target_field' => $field, 'reason' => 'person_name_looks_like_section_heading'];
            }
        }

        foreach (($normalized['relatives'] ?? []) as $relative) {
            if (! is_array($relative)) {
                continue;
            }
            $name = $this->stringify($relative['name'] ?? null);
            $relationType = trim((string) ($relative['relation_type'] ?? ''));
            if ($name !== '' && $this->looksLikeSectionHeading($name)) {
                $issues[] = ['fact_type' => 'person_name', 'value' => $name, 'target_section' => $this->relationSchema->sectionForRelationType($relationType) ?? 'review_needed', 'target_field' => 'relatives.'.$relationType.'.name', 'reason' => 'person_name_looks_like_section_heading'];
            }
        }

        return $issues;
    }

    /**
     * @param  list<array<string, mixed>>  $missingFacts
     * @param  list<array<string, mixed>>  $wrongSectionFacts
     * @param  list<array<string, mixed>>  $duplicateFacts
     * @param  list<array<string, mixed>>  $mixedFacts
     * @param  list<array<string, mixed>>  $suspiciousFacts
     * @return list<array<string, mixed>>
     */
    private function reviewFlagsFromCoverageIssues(array $missingFacts, array $wrongSectionFacts, array $duplicateFacts, array $mixedFacts, array $suspiciousFacts): array
    {
        $flags = [];
        foreach ([
            'coverage_missing_fact' => $missingFacts,
            'coverage_wrong_section_fact' => $wrongSectionFacts,
            'coverage_duplicate_fact' => $duplicateFacts,
            'coverage_mixed_fact' => $mixedFacts,
        ] as $reason => $facts) {
            foreach ($facts as $fact) {
                $flags[] = [
                    'field' => (string) ($fact['target_field'] ?? 'review.coverage'),
                    'reason' => $reason,
                    'raw' => (string) ($fact['source_text'] ?? $fact['value'] ?? ''),
                    'suggested_section' => (string) ($fact['target_section'] ?? 'review_needed'),
                    'source_line_no' => $fact['source_line_no'] ?? null,
                    'source_text' => (string) ($fact['source_text'] ?? ''),
                ];
            }
        }

        foreach ($suspiciousFacts as $fact) {
            $flags[] = [
                'field' => (string) ($fact['target_field'] ?? 'review.suspicious'),
                'reason' => (string) ($fact['reason'] ?? 'coverage_suspicious_fact'),
                'raw' => (string) ($fact['value'] ?? ''),
                'suggested_section' => (string) ($fact['target_section'] ?? 'review_needed'),
            ];
        }

        return $flags;
    }

    /**
     * @param  array<string, mixed>  $fact
     * @return array<string, mixed>
     */
    private function coverageFactSummary(array $fact): array
    {
        return [
            'fact_type' => (string) ($fact['fact_type'] ?? ''),
            'value' => (string) ($fact['value'] ?? ''),
            'target_section' => (string) ($fact['target_section'] ?? ''),
            'target_field' => (string) ($fact['target_field'] ?? ''),
            'source_line_no' => $fact['source_line_no'] ?? null,
            'source_text' => (string) ($fact['source_text'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $fact
     */
    private function factIdentity(array $fact, bool $includeSection): string
    {
        $factType = trim((string) ($fact['fact_type'] ?? ''));
        $targetField = $this->canonicalTargetField((string) ($fact['target_field'] ?? ''));
        $canonicalValue = $this->canonicalScalar((string) ($fact['value'] ?? ''), $factType, $targetField);
        if ($factType === '' || $canonicalValue === '' || $targetField === '') {
            return '';
        }

        $parts = [$factType, $canonicalValue];
        if ($includeSection) {
            $parts[] = trim((string) ($fact['target_section'] ?? ''));
        }
        $parts[] = $targetField;

        return implode('|', $parts);
    }

    private function canonicalTargetField(string $field): string
    {
        $field = preg_replace('/\.\d+\./', '.', $field) ?? $field;
        $field = str_replace(['.native.', '.current.', '.other.'], '.address_line.', $field);
        $field = str_replace('parents_addresses.address_line.address_line', 'parents_addresses.address_line', $field);
        $field = str_replace('addresses.address_line.address_line', 'addresses.address_line', $field);
        $field = str_replace(['siblings.brother_wife', 'siblings.sister_husband'], 'siblings.spouse', $field);
        $field = str_replace(['contact_number_2', 'contact_number_3'], 'contact_number', $field);
        $field = str_replace(['father_contact_2', 'father_contact_3'], 'father_contact_1', $field);
        $field = str_replace(['mother_contact_2', 'mother_contact_3'], 'mother_contact_1', $field);
        $field = str_replace(['core.primary_contact_number_2', 'core.primary_contact_number_3'], 'core.primary_contact_number', $field);
        $field = str_replace('occupation_title', 'occupation', $field);
        $field = str_replace('additional_info', 'notes', $field);

        return trim($field, '.');
    }

    private function stripPropertyLabel(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/^\s*(?:प्रॉपर्टी|प्रोपर्टी|स्थावर\s*मिळकत|स्थायिक\s*मालमत्ता|मालमत्ता|स्थावर|शेती|जमीन|स्वता:ची\s+मालमत्ता|स्वताची\s+मालमत्ता|स्वतःची\s+मालमत्ता|स्वत[:ः]?ची\s+मालमत्ता)\s*(?::\s*-\s*|[:\-–—]\s*)/u', '', $value) ?? $value;

        return trim((string) preg_replace('/^[\s:.\-|–—]+|[\s:.\-|–—]+$/u', '', $value));
    }

    private function canonicalScalar(string $value, string $factType, string $targetField = ''): string
    {
        $value = OcrNormalize::normalizeDigits(trim($value));
        if ($targetField === 'core.date_of_birth') {
            $date = OcrNormalize::normalizeDate($value);
            if ($date !== null && $date !== '') {
                return $date;
            }
        }

        if ($factType === 'phone_number') {
            preg_match_all('/[6-9]\d{9}/', $value, $matches);

            return implode(',', array_unique($matches[0] ?? []));
        }

        if ($factType === 'person_name') {
            $value = preg_replace('/^\s*(?:श्री\.?|सौ\.?|कु\.?|चि\.?|कै\.?|डॉ\.?)\s*/u', '', $value) ?? $value;
        }
        if ($factType === 'other_relatives_text') {
            $value = preg_replace('/^(?:नाते\s+)?संबंध\s*(?::\s*-\s*|[:\-]\s*)/u', '', $value) ?? $value;
        }
        if (str_contains($value, 'B+ve')) {
            $value = str_replace('B+ve', 'B+', $value);
        }
        if (str_contains($value, 'A+ve')) {
            $value = str_replace('A+ve', 'A+', $value);
        }
        if (str_contains($value, 'AB+ve')) {
            $value = str_replace('AB+ve', 'AB+', $value);
        }
        if (str_contains($value, 'O+ve')) {
            $value = str_replace('O+ve', 'O+', $value);
        }

        $value = trim((string) preg_replace('/[.,;]+$/u', '', $value));
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_strtolower(trim($value));
    }

    /**
     * @param  array<string, mixed>  $core
     * @param  array<string, mixed>  $normalized
     * @return list<array<string, mixed>>
     */
    private function visibleUserContactFacts(array $core, array $normalized): array
    {
        $facts = [];
        $phones = [];
        $parentPhones = [];
        foreach (['father_contact_1', 'father_contact_2', 'father_contact_3', 'mother_contact_1', 'mother_contact_2', 'mother_contact_3'] as $field) {
            $phone = $this->stringify($core[$field] ?? null);
            if ($phone !== '') {
                $parentPhones[$phone] = true;
            }
        }

        foreach ([$core['primary_contact_number'] ?? null, $core['primary_contact_number_2'] ?? null, $core['primary_contact_number_3'] ?? null] as $value) {
            $phone = $this->stringify($value);
            if ($phone !== '' && ! isset($parentPhones[$phone]) && ! in_array($phone, $phones, true)) {
                $phones[] = $phone;
            }
        }

        foreach (($normalized['contacts'] ?? []) as $contact) {
            if (! is_array($contact)) {
                continue;
            }
            $phone = $this->stringify($contact['phone_number'] ?? null);
            if ($phone !== '' && ! isset($parentPhones[$phone]) && ! in_array($phone, $phones, true)) {
                $phones[] = $phone;
            }
        }

        foreach ($phones as $phone) {
            $facts[] = ['fact_type' => 'phone_number', 'value' => $phone, 'target_section' => 'basic-info', 'target_field' => 'core.primary_contact_number'];
        }

        return $facts;
    }

    private function looksLikeSectionHeading(string $value): bool
    {
        return preg_match('/^(?:वैयक्तिक\s+माहिती|वैयक्तिक\s+तपशील|कौटुंबिक\s+माहिती|कौटुंबिक\s+तपशील|इतर\s+पाहुणे|इतर\s+प्रॉपर्टी|मुलाची\s+आई|मुलाचे\s+भाऊ|बायोडाटा|कौटुंबिक\s+माहिती)$/u', trim($value)) === 1;
    }

    private function looksLikeAddressText(string $value): bool
    {
        return preg_match('/(?:मु\.?\s*पो\.?|रा\.|ता\.|जि\.|पत्ता|निवास|पोस्ट|नगर|रोड|कॉलनी|वाडी|गाव|फ्लॅट|वॉर्ड)/u', $value) === 1;
    }

    private function stringify(mixed $value): string
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
     * @param  array<string, mixed>  $fact
     * @param  array<string, mixed>  $draft
     */
    private function isDecomposedCasteLineFullyMapped(array $fact, array $draft): bool
    {
        $field = trim((string) ($fact['target_field'] ?? ''));
        if ($field !== 'core.caste') {
            return false;
        }

        $core = is_array($draft['normalized']['core'] ?? null) ? $draft['normalized']['core'] : [];
        $religion = trim($this->stringify($core['religion'] ?? null));
        $caste = trim($this->stringify($core['caste'] ?? null));
        $subCaste = trim($this->stringify($core['sub_caste'] ?? null));
        $sourceText = trim($this->stringify($fact['source_text'] ?? $fact['value'] ?? null));

        if ($religion === '' || $caste === '') {
            return false;
        }

        if (preg_match('/(\d+\s*कुळी|९६\s*कुळी)/u', $sourceText)) {
            return $subCaste !== '';
        }

        if (preg_match('/हिंद[ुू]/u', $sourceText) && preg_match('/मराठा/u', $sourceText)) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $fact
     * @param  array<string, mixed>  $draft
     */
    private function isOtherRelativesFactFullyMapped(array $fact, array $draft): bool
    {
        if (($fact['fact_type'] ?? '') !== 'other_relatives_text'
            || trim((string) ($fact['target_field'] ?? '')) !== 'core.other_relatives_text') {
            return false;
        }

        $source = $this->canonicalOtherRelativesText((string) ($fact['value'] ?? ''));
        $visible = $this->canonicalOtherRelativesText((string) data_get($draft, 'normalized.core.other_relatives_text', ''));
        if ($source === '' || $visible === '') {
            return false;
        }

        return str_contains($visible, $source);
    }

    private function canonicalOtherRelativesText(string $value): string
    {
        $value = OcrNormalize::normalizeDigits(trim($value));
        $value = preg_replace('/^\s*(?:\d+|[०-९]+)[\).]\s*/u', '', $value) ?? $value;
        $value = preg_replace('/^\s*(?:नातेवाईक|इतर\s+नातेवाईक|उत्तर\s+नातेवाईक|नातेसंबंध|नाते\s+संबंध|पाहुणे|इतर\s+पाहुणे|इतर\s+पाहूणे)\s*(?::\s*-\s*|[:\-–—]\s*)/u', '', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = trim($value, " \t\n\r\0\x0B,;।");

        return $value;
    }

    /**
     * @param  array<string, mixed>  $fact
     * @param  list<array<string, mixed>>  $visibleFacts
     */
    private function isCompoundFactFullyMapped(array $fact, array $visibleFacts): bool
    {
        $value = trim((string) ($fact['value'] ?? ''));
        if ($value === '' || ! str_contains($value, ',')) {
            return false;
        }

        if (preg_match('/(?:पत्ता|पता|रा\.|राहणार|मु\.?\s*पो\.?)/u', $value)
            || preg_match('/\([^()]*,[^()]*\)/u', $value)
            || preg_match('/,\s*[\p{L}\p{M}\s.]+\([^()]+\)\s*$/u', $value)
            || preg_match('/,\s*(?:पुणे|मुंबई|सोलापूर|सांगली|सातारा|कोल्हापूर|ठाणे|कराड|नाशिक)$/u', $value)) {
            return false;
        }

        $parts = preg_split('/\s*,\s*(?=(?:\d+\)|[०-९]+\)|श्री|सौ|कै|कु|डॉ|[[:alpha:]\p{L}]))/u', $value) ?: [];
        $parts = array_values(array_filter(array_map(
            function (string $part): string {
                return trim((string) preg_replace('/^\s*(?:\d+|[०-९]+)[\).]\s*/u', '', trim($part)));
            },
            $parts
        )));

        if (count($parts) < 2) {
            return false;
        }

        $factType = trim((string) ($fact['fact_type'] ?? ''));
        $targetField = $this->canonicalTargetField((string) ($fact['target_field'] ?? ''));
        $relationType = trim((string) ($fact['relation_type'] ?? ''));
        $visibleKeys = [];

        foreach ($visibleFacts as $visible) {
            if (! is_array($visible)) {
                continue;
            }
            if (trim((string) ($visible['fact_type'] ?? '')) !== $factType) {
                continue;
            }
            if ($this->canonicalTargetField((string) ($visible['target_field'] ?? '')) !== $targetField) {
                continue;
            }
            if ($relationType !== '' && $relationType !== trim((string) ($visible['relation_type'] ?? ''))) {
                continue;
            }
            $canonical = $this->canonicalScalar((string) ($visible['value'] ?? ''), $factType);
            if ($canonical !== '') {
                $visibleKeys[$canonical] = true;
            }
        }

        foreach ($parts as $part) {
            $canonical = $this->canonicalScalar($part, $factType);
            if ($canonical === '' || ! isset($visibleKeys[$canonical])) {
                return false;
            }
        }

        return true;
    }
}
