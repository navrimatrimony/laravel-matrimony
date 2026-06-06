<?php

namespace App\Services\Parsing;

use App\Services\Ocr\OcrNormalize;

class IntakeNormalizedDraftCoverageAuditor
{
    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function audit(array $draft): array
    {
        $extractedFacts = is_array($draft['extracted_facts'] ?? null) ? $draft['extracted_facts'] : [];
        $visibleFacts = $this->visibleFacts($draft);
        $visibleIndex = [];
        $visibleCounts = [];

        foreach ($visibleFacts as $fact) {
            if (! is_array($fact)) {
                continue;
            }
            $identity = $this->factIdentity($fact);
            if ($identity === '') {
                continue;
            }
            $visibleIndex[$identity] = true;
            $duplicateIdentity = $this->duplicateIdentity($fact);
            $visibleCounts[$duplicateIdentity] = ($visibleCounts[$duplicateIdentity] ?? 0) + 1;
        }

        $missingFacts = [];
        foreach ($extractedFacts as $fact) {
            if (! is_array($fact)) {
                continue;
            }
            $identity = $this->factIdentity($fact);
            if ($identity === '' || isset($visibleIndex[$identity])) {
                continue;
            }
            $missingFacts[] = $this->coverageFactSummary($fact);
        }

        $duplicateFacts = [];
        $recordedDuplicates = [];
        foreach ($visibleFacts as $fact) {
            if (! is_array($fact)) {
                continue;
            }
            $duplicateIdentity = $this->duplicateIdentity($fact);
            if ($duplicateIdentity === '' || ($visibleCounts[$duplicateIdentity] ?? 0) < 2 || isset($recordedDuplicates[$duplicateIdentity])) {
                continue;
            }
            $duplicateFacts[] = $this->coverageFactSummary($fact);
            $recordedDuplicates[$duplicateIdentity] = true;
        }

        $suspiciousMappedFacts = $this->suspiciousMappedFacts($draft, $visibleFacts);

        return [
            'missing_facts' => $missingFacts,
            'duplicate_facts' => $duplicateFacts,
            'suspicious_mapped_facts' => $suspiciousMappedFacts,
            'source_fact_count' => count(array_filter($extractedFacts, 'is_array')),
            'visible_fact_count' => count(array_filter($visibleFacts, 'is_array')),
            'review_flags' => $this->reviewFlagsFromCoverageIssues($missingFacts, $duplicateFacts, $suspiciousMappedFacts),
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
            ['basic-info', 'core.full_name', 'full_name'],
            ['basic-info', 'core.gender', 'gender'],
            ['basic-info', 'core.date_of_birth', 'date_of_birth'],
            ['basic-info', 'core.birth_time', 'birth_time'],
            ['basic-info', 'core.birth_place_text', 'birth_place_text'],
            ['basic-info', 'core.religion', 'religion'],
            ['basic-info', 'core.caste', 'caste'],
            ['basic-info', 'core.sub_caste', 'sub_caste'],
            ['basic-info', 'core.marital_status', 'marital_status'],
            ['physical', 'core.height_cm', 'height_cm'],
            ['physical', 'core.complexion', 'complexion'],
            ['physical', 'core.blood_group', 'blood_group'],
            ['physical', 'core.weight_kg', 'weight_kg'],
            ['physical', 'core.physical_build', 'physical_build'],
            ['physical', 'core.spectacles_lens', 'spectacles_lens'],
            ['physical', 'core.physical_condition', 'physical_condition'],
            ['physical', 'core.diet', 'diet'],
            ['education-career', 'core.highest_education', 'highest_education'],
            ['education-career', 'core.occupation_title', 'occupation_title'],
            ['education-career', 'core.company_name', 'company_name'],
            ['education-career', 'core.annual_income', 'annual_income'],
            ['education-career', 'core.work_location_text', 'work_location_text'],
            ['education-career', 'core.specialization', 'specialization'],
            ['family-details', 'core.father_name', 'father_name'],
            ['family-details', 'core.father_occupation', 'father_occupation'],
            ['family-details', 'core.father_extra_info', 'father_extra_info'],
            ['family-details', 'core.father_contact_1', 'father_contact_1'],
            ['family-details', 'core.father_contact_2', 'father_contact_2'],
            ['family-details', 'core.father_contact_3', 'father_contact_3'],
            ['family-details', 'core.mother_name', 'mother_name'],
            ['family-details', 'core.mother_occupation', 'mother_occupation'],
            ['family-details', 'core.mother_extra_info', 'mother_extra_info'],
            ['family-details', 'core.mother_contact_1', 'mother_contact_1'],
            ['family-details', 'core.mother_contact_2', 'mother_contact_2'],
            ['family-details', 'core.mother_contact_3', 'mother_contact_3'],
            ['family-details', 'core.family_income', 'family_income'],
            ['family-details', 'core.family_type', 'family_type'],
            ['family-details', 'core.family_status', 'family_status'],
            ['family-details', 'core.family_values', 'family_values'],
            ['alliance', 'core.other_relatives_text', 'other_relatives_text'],
        ] as [$section, $field, $coreKey]) {
            $value = $this->stringify($core[$coreKey] ?? null);
            if ($value === '') {
                continue;
            }
            $facts[] = [
                'fact_type' => $this->factTypeFromField($field),
                'value' => $value,
                'target_section' => $section,
                'target_field' => $field,
            ];
        }

        foreach (($normalized['addresses'] ?? []) as $index => $address) {
            if (! is_array($address)) {
                continue;
            }
            $value = $this->stringify($address['address_line'] ?? $address['raw'] ?? null);
            if ($value === '') {
                continue;
            }
            $facts[] = [
                'fact_type' => 'address_line',
                'value' => $value,
                'target_section' => 'basic-info',
                'target_field' => 'addresses.'.($index + 1).'.address_line',
            ];
        }

        foreach (($normalized['parents_addresses'] ?? []) as $index => $address) {
            if (! is_array($address)) {
                continue;
            }
            $value = $this->stringify($address['address_line'] ?? $address['raw'] ?? null);
            if ($value === '') {
                continue;
            }
            $facts[] = [
                'fact_type' => 'address_line',
                'value' => $value,
                'target_section' => 'family-details',
                'target_field' => 'parents_addresses.'.($index + 1).'.address_line',
            ];
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
                $value = $this->stringify($sibling[$field] ?? null);
                if ($value === '') {
                    continue;
                }
                $facts[] = [
                    'fact_type' => $factType,
                    'value' => $value,
                    'target_section' => 'siblings',
                    'target_field' => 'siblings.'.($index + 1).'.'.$field,
                ];
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
                $value = $this->stringify($spouse[$field] ?? null);
                if ($value === '') {
                    continue;
                }
                $facts[] = [
                    'fact_type' => $factType,
                    'value' => $value,
                    'target_section' => 'siblings',
                    'target_field' => 'siblings.'.($index + 1).'.spouse.'.$field,
                ];
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
                $value = $this->stringify($relative[$field] ?? null);
                if ($value === '') {
                    continue;
                }
                $facts[] = [
                    'fact_type' => $factType,
                    'value' => $value,
                    'target_section' => $section,
                    'target_field' => 'relatives.'.($index + 1).'.'.$field,
                ];
            }
        }

        $horoscope = is_array($normalized['horoscope'] ?? null) ? $normalized['horoscope'] : [];
        foreach ($horoscope as $field => $value) {
            if ($field === 'raw') {
                continue;
            }
            $text = $this->stringify($value);
            if ($text === '') {
                continue;
            }
            $facts[] = [
                'fact_type' => 'horoscope_value',
                'value' => $text,
                'target_section' => 'horoscope',
                'target_field' => 'horoscope.'.$field,
            ];
        }

        $preferences = is_array($normalized['preferences'] ?? null) ? $normalized['preferences'] : [];
        foreach ($preferences as $field => $value) {
            $text = $this->stringify($value);
            if ($text === '') {
                continue;
            }
            $facts[] = [
                'fact_type' => 'preference_text',
                'value' => $text,
                'target_section' => 'about-preferences',
                'target_field' => 'preferences.'.$field,
            ];
        }

        foreach ($this->visibleUserContactFacts($core, $normalized) as $fact) {
            $facts[] = $fact;
        }

        return $facts;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  list<array<string, mixed>>  $visibleFacts
     * @return list<array<string, mixed>>
     */
    private function suspiciousMappedFacts(array $draft, array $visibleFacts): array
    {
        $normalized = is_array($draft['normalized'] ?? null) ? $draft['normalized'] : [];
        $core = is_array($normalized['core'] ?? null) ? $normalized['core'] : [];
        $issues = [];

        $fullName = $this->stringify($core['full_name'] ?? null);
        if ($fullName !== '' && $this->looksLikeAddressText($fullName)) {
            $issues[] = [
                'fact_type' => 'full_name',
                'value' => $fullName,
                'target_section' => 'basic-info',
                'target_field' => 'core.full_name',
                'reason' => 'full_name_looks_like_address',
            ];
        }

        foreach ([
            'core.father_name' => $this->stringify($core['father_name'] ?? null),
            'core.mother_name' => $this->stringify($core['mother_name'] ?? null),
        ] as $field => $value) {
            if ($value === '' || ! $this->looksLikeSectionHeading($value)) {
                continue;
            }
            $issues[] = [
                'fact_type' => 'person_name',
                'value' => $value,
                'target_section' => 'family-details',
                'target_field' => $field,
                'reason' => 'parent_name_looks_like_section_heading',
            ];
        }

        foreach (($normalized['relatives'] ?? []) as $index => $relative) {
            if (! is_array($relative)) {
                continue;
            }
            $name = $this->stringify($relative['name'] ?? null);
            if ($name !== '' && $this->looksLikeSectionHeading($name)) {
                $issues[] = [
                    'fact_type' => 'relative_name',
                    'value' => $name,
                    'target_section' => $this->relativeTargetSection($relative),
                    'target_field' => 'relatives.'.($index + 1).'.name',
                    'reason' => 'relative_name_looks_like_section_heading',
                ];
            }
        }

        return $issues;
    }

    /**
     * @param  list<array<string, mixed>>  $missingFacts
     * @param  list<array<string, mixed>>  $duplicateFacts
     * @param  list<array<string, mixed>>  $suspiciousFacts
     * @return list<array<string, mixed>>
     */
    private function reviewFlagsFromCoverageIssues(array $missingFacts, array $duplicateFacts, array $suspiciousFacts): array
    {
        $flags = [];

        foreach ($missingFacts as $fact) {
            $flags[] = [
                'field' => (string) ($fact['target_field'] ?? 'review.missing'),
                'reason' => 'coverage_missing_fact',
                'raw' => (string) ($fact['source_text'] ?? $fact['value'] ?? ''),
                'suggested_section' => (string) ($fact['target_section'] ?? 'review_needed'),
                'source_line_no' => $fact['source_line_no'] ?? null,
                'source_text' => (string) ($fact['source_text'] ?? ''),
            ];
        }

        foreach ($duplicateFacts as $fact) {
            $flags[] = [
                'field' => (string) ($fact['target_field'] ?? 'review.duplicate'),
                'reason' => 'coverage_duplicate_fact',
                'raw' => (string) ($fact['value'] ?? ''),
                'suggested_section' => (string) ($fact['target_section'] ?? 'review_needed'),
                'source_line_no' => $fact['source_line_no'] ?? null,
                'source_text' => (string) ($fact['source_text'] ?? ''),
            ];
        }

        foreach ($suspiciousFacts as $fact) {
            $flags[] = [
                'field' => (string) ($fact['target_field'] ?? 'review.suspicious'),
                'reason' => (string) ($fact['reason'] ?? 'coverage_suspicious_fact'),
                'raw' => (string) ($fact['value'] ?? ''),
                'suggested_section' => (string) ($fact['target_section'] ?? 'review_needed'),
                'source_line_no' => $fact['source_line_no'] ?? null,
                'source_text' => (string) ($fact['source_text'] ?? ''),
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
            'value' => (string) ($fact['value'] ?? $fact['name'] ?? $fact['details'] ?? ''),
            'target_section' => (string) ($fact['target_section'] ?? ''),
            'target_field' => (string) ($fact['target_field'] ?? ''),
            'source_line_no' => $fact['source_line_no'] ?? null,
            'source_text' => (string) ($fact['source_text'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $fact
     */
    private function factIdentity(array $fact): string
    {
        $factType = trim((string) ($fact['fact_type'] ?? ''));
        $value = (string) ($fact['value'] ?? $fact['name'] ?? $fact['details'] ?? '');
        $canonicalValue = $this->canonicalScalar($value, $factType);

        if ($factType === '' || $canonicalValue === '') {
            return '';
        }

        return $factType.'|'.$canonicalValue;
    }

    private function canonicalScalar(string $value, string $factType): string
    {
        $value = OcrNormalize::normalizeDigits(trim($value));
        if ($factType === 'phone_number') {
            preg_match_all('/[6-9]\d{9}/', $value, $matches);

            return implode(',', array_unique($matches[0] ?? []));
        }

        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_strtolower(trim($value));
    }

    /**
     * @param  array<string, mixed>  $relative
     */
    private function relativeTargetSection(array $relative): string
    {
        $type = mb_strtolower(trim((string) ($relative['relation_type'] ?? '')));

        return in_array($type, [
            'maternal_uncle',
            'wife_maternal_uncle',
            'maternal_aunt',
            'husband_maternal_aunt',
            'maternal_cousin',
            'maternal_address_ajol',
        ], true) ? 'alliance' : 'relatives';
    }

    private function factTypeFromField(string $field): string
    {
        return match (true) {
            str_contains($field, 'contact') || str_contains($field, 'phone') => 'phone_number',
            str_contains($field, 'address') => 'address_line',
            preg_match('/(?:^|\.)(?:full_name|father_name|mother_name|name)$/', $field) === 1 => 'person_name',
            str_contains($field, 'expectations') => 'preference_text',
            default => 'field_value',
        };
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
        foreach ([
            'father_contact_number', 'father_contact_1', 'father_contact_2', 'father_contact_3',
            'mother_contact_number', 'mother_contact_1', 'mother_contact_2', 'mother_contact_3',
        ] as $field) {
            $phone = $this->stringify($core[$field] ?? null);
            if ($phone !== '') {
                $parentPhones[$phone] = true;
            }
        }

        foreach ([
            $core['primary_contact_number'] ?? null,
            $core['primary_contact_number_2'] ?? null,
            $core['primary_contact_number_3'] ?? null,
        ] as $value) {
            $phone = $this->stringify($value);
            if ($phone === '' || isset($parentPhones[$phone]) || in_array($phone, $phones, true)) {
                continue;
            }
            $phones[] = $phone;
        }

        foreach (($normalized['contacts'] ?? []) as $contact) {
            if (! is_array($contact)) {
                continue;
            }
            $phone = $this->stringify($contact['phone_number'] ?? null);
            if ($phone === '' || isset($parentPhones[$phone]) || in_array($phone, $phones, true)) {
                continue;
            }
            $phones[] = $phone;
        }

        foreach ($phones as $index => $phone) {
            $facts[] = [
                'fact_type' => 'phone_number',
                'value' => $phone,
                'target_section' => 'basic-info',
                'target_field' => match ($index) {
                    0 => 'core.primary_contact_number',
                    1 => 'core.primary_contact_number_2',
                    2 => 'core.primary_contact_number_3',
                    default => 'contacts.'.($index + 1).'.phone_number',
                },
            ];
        }

        return $facts;
    }

    /**
     * @param  array<string, mixed>  $fact
     */
    private function duplicateIdentity(array $fact): string
    {
        $identity = $this->factIdentity($fact);
        if ($identity === '') {
            return '';
        }

        return $identity.'|'.trim((string) ($fact['target_field'] ?? ''));
    }

    private function looksLikeSectionHeading(string $value): bool
    {
        return preg_match('/^(?:वैयक्तिक\s+माहिती|वैयक्तिक\s+तपशील|कौटुंबिक\s+माहिती|कौटुंबिक\s+तपशील|शिक्षण|नोकरी|व्यवसाय|बायोडाटा|बयोडाटा|मुलाची\s+माहिती|मुलाचे\s+नाव|मुलीचे\s+नाव|नाव)$/u', trim($value)) === 1;
    }

    private function looksLikeAddressText(string $value): bool
    {
        return preg_match('/(?:मु\.?\s*पो\.?|रा\.|ता\.|जि\.|पत्ता|पोस्ट|नगर|रोड|कॉलनी|वाडी|गाव|फ्लॅट|वॉर्ड)/u', $value) === 1;
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
}
