<?php

namespace App\Services\Parsing;

use App\Services\Ocr\OcrNormalize;

class IntakeNormalizedDraftToParsedJsonMapper
{
    private const CONF_EXPLICIT = 0.85;

    private const CONF_FALLBACK = 0.65;

    private const CONF_MISSING = 0.0;

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function map(array $draft): array
    {
        $skeleton = app(IntakeParsedSnapshotSkeleton::class);
        $parsed = $skeleton->defaults();

        $core = $this->mapCore($draft);
        $contacts = $this->mapContacts($draft);
        if (($core['primary_contact_number'] ?? null) === null && $contacts !== []) {
            foreach ($contacts as $contact) {
                if (! empty($contact['is_primary'])) {
                    $core['primary_contact_number'] = $contact['phone_number'] ?? $contact['number'] ?? null;
                    break;
                }
            }
            if (($core['primary_contact_number'] ?? null) === null) {
                $core['primary_contact_number'] = $contacts[0]['phone_number'] ?? $contacts[0]['number'] ?? null;
            }
        }

        $parsed['core'] = $core;
        $parsed['contacts'] = $contacts;
        $parsed['addresses'] = $this->mapAddresses($draft);
        $parsed['parents_addresses'] = $this->mapParentsAddresses($draft);
        $parsed['relatives'] = $this->mapRelatives($draft);
        $parsed['siblings'] = $this->mapSiblings($draft);
        $parsed['property_summary'] = $this->mapPropertySummary($draft);
        $parsed['horoscope'] = $this->mapHoroscope($draft);
        $parsed['confidence_map'] = $this->mapConfidenceMap($draft, $parsed);

        $education = trim((string) ($core['highest_education'] ?? ''));
        if ($education !== '') {
            $parsed['education_history'] = [
                ['degree' => $education, 'institution' => null, 'year' => null],
            ];
        }

        $occupation = trim((string) ($core['occupation_title'] ?? ''));
        $company = trim((string) ($core['company_name'] ?? ''));
        $workLocation = trim((string) ($core['work_location_text'] ?? ''));
        if ($occupation !== '' || $company !== '' || $workLocation !== '') {
            $parsed['career_history'] = [[
                'occupation_title' => $occupation !== '' ? $occupation : null,
                'job_title' => $occupation !== '' ? $occupation : null,
                'role' => $occupation !== '' ? $occupation : null,
                'company_name' => $company !== '' ? $company : null,
                'employer' => $company !== '' ? $company : null,
                'location' => $workLocation !== '' ? $workLocation : null,
            ]];
        }

        $birthPlaceText = trim((string) ($core['birth_place_text'] ?? ''));
        if ($birthPlaceText !== '') {
            $parsed['birth_place'] = [
                'address_line' => $birthPlaceText,
                'raw' => $birthPlaceText,
            ];
        }

        $nativePlace = $this->resolveNativePlace($parsed['addresses']);
        if ($nativePlace !== null) {
            $parsed['native_place'] = $nativePlace;
        }

        if (($parsed['core']['address_line'] ?? null) === null && $parsed['addresses'] !== []) {
            $parsed['core']['address_line'] = (string) ($parsed['addresses'][0]['address_line'] ?? '');
        }

        return $skeleton->ensure($parsed);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function mapCore(array $draft): array
    {
        $defaults = app(IntakeParsedSnapshotSkeleton::class)->defaults()['core'];
        $normalized = is_array($draft['normalized']['core'] ?? null) ? $draft['normalized']['core'] : [];
        $mapped = array_replace($defaults, $this->pickCoreFields($normalized));

        foreach (['religion_id', 'caste_id', 'sub_caste_id', 'city_id', 'gender_id', 'birth_city_id'] as $idField) {
            if (! array_key_exists($idField, $normalized) || $normalized[$idField] === null) {
                $mapped[$idField] = null;
            }
        }

        $contacts = is_array($draft['normalized']['contacts'] ?? null) ? $draft['normalized']['contacts'] : [];
        $mapped['primary_contact_number'] = $this->firstNormalizedPhone($contacts);

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return list<array<string, mixed>>
     */
    public function mapContacts(array $draft): array
    {
        $rawContacts = is_array($draft['normalized']['contacts'] ?? null) ? $draft['normalized']['contacts'] : [];
        $mapped = [];
        $seen = [];

        foreach ($rawContacts as $contact) {
            if (! is_array($contact)) {
                continue;
            }
            $phone = OcrNormalize::normalizePhone((string) ($contact['phone_number'] ?? $contact['number'] ?? ''));
            if ($phone === null || ! preg_match('/^[6-9]\d{9}$/', $phone) || isset($seen[$phone])) {
                continue;
            }
            $seen[$phone] = true;
            $mapped[] = [
                'phone_number' => $phone,
                'number' => $phone,
                'type' => (string) ($contact['type'] ?? 'alternate'),
                'label' => (string) ($contact['label'] ?? 'other'),
                'relation_type' => (string) ($contact['relation_type'] ?? ''),
                'contact_name' => (string) ($contact['contact_name'] ?? ''),
                'is_primary' => false,
            ];
        }

        if ($mapped !== []) {
            $mapped[0]['type'] = 'self';
            $mapped[0]['label'] = 'self';
            $mapped[0]['is_primary'] = true;
        }

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return list<array<string, mixed>>
     */
    public function mapAddresses(array $draft): array
    {
        $rawAddresses = is_array($draft['normalized']['addresses'] ?? null) ? $draft['normalized']['addresses'] : [];
        $sectionLines = is_array($draft['sections']['addresses']['lines'] ?? null)
            ? $draft['sections']['addresses']['lines']
            : [];
        $mapped = [];

        foreach ($rawAddresses as $index => $address) {
            if (! is_array($address)) {
                continue;
            }
            $line = trim((string) ($address['address_line'] ?? $address['raw'] ?? ''));
            if ($line === '' || $this->isAddressContaminated($line)) {
                continue;
            }
            $sourceLine = is_string($sectionLines[$index] ?? null) ? (string) $sectionLines[$index] : '';
            $mapped[] = [
                'address_line' => $line,
                'raw' => trim((string) ($address['raw'] ?? $line)),
                'type' => $this->inferAddressType($sourceLine !== '' ? $sourceLine : $line),
            ];
        }

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return list<array<string, mixed>>
     */
    private function mapParentsAddresses(array $draft): array
    {
        $rows = $draft['normalized']['parents_addresses'] ?? [];
        if (! is_array($rows)) {
            return [];
        }

        $mapped = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $addressLine = trim((string) ($row['address_line'] ?? ''));
            $raw = trim((string) ($row['raw'] ?? ''));
            if ($addressLine === '' && $raw === '') {
                continue;
            }

            $mapped[] = [
                'type' => 'parents',
                'address_line' => $addressLine !== '' ? $addressLine : null,
                'raw' => $raw !== '' ? $raw : null,
            ];
        }

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return list<array<string, mixed>>
     */
    public function mapRelatives(array $draft): array
    {
        $rawRelatives = is_array($draft['normalized']['relatives'] ?? null) ? $draft['normalized']['relatives'] : [];
        $mapped = [];

        foreach ($rawRelatives as $relative) {
            if (! is_array($relative)) {
                continue;
            }
            $name = trim((string) ($relative['name'] ?? ''));
            $raw = trim((string) ($relative['raw'] ?? $name));
            $blob = implode(' ', array_filter([$name, $raw]));
            if ($blob === '' || $this->isRelativeContaminated($blob)) {
                continue;
            }

            $relationType = $this->inferRelativeRelationType($raw);
            $mapped[] = [
                'relation_type' => $relationType,
                'name' => $name !== '' ? $name : null,
                'raw_note' => $raw !== '' ? $raw : null,
                'notes' => $name !== '' ? $name : $raw,
                'address_line' => null,
                'location' => null,
            ];
        }

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    public function mapPropertySummary(array $draft): mixed
    {
        $property = $draft['normalized']['property_summary'] ?? null;
        if (! is_array($property)) {
            return null;
        }

        $text = trim((string) ($property['summary_text'] ?? ''));
        if ($text === '') {
            return null;
        }

        if ($this->isAddressOnlyPropertyText($text)) {
            return null;
        }

        $landAcres = null;
        if (preg_match('/([0-9०-९]+(?:\.[0-9]+)?)\s*(?:एकर|acre|acres)/ui', $text, $m)) {
            $digits = OcrNormalize::normalizeDigits($m[1]);
            $landAcres = is_numeric($digits) ? (float) $digits : null;
        }

        $summary = [
            'owns_house' => (bool) preg_match('/(?:स्वत[:ः]?च(?:े|्या)|मालकीच(?:े|्या))\s*(?:घर)?/u', $text),
            'owns_flat' => (bool) preg_match('/(?:flat|bhk|फ्लॅट|फ्लाट|apartment)/ui', $text),
            'owns_agriculture' => (bool) preg_match('/(?:शेती|बागायत|जमीन|agri|agriculture|land)/ui', $text),
            'total_land_acres' => $landAcres,
            'annual_agri_income' => null,
            'summary_notes' => $text,
        ];

        if (! $summary['owns_house'] && ! $summary['owns_flat'] && ! $summary['owns_agriculture']
            && $summary['total_land_acres'] === null && $summary['summary_notes'] === '') {
            return null;
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return list<array<string, mixed>>
     */
    public function mapHoroscope(array $draft): array
    {
        $horoscope = $draft['normalized']['horoscope'] ?? null;
        if (! is_array($horoscope)) {
            return [];
        }

        $rawLines = is_array($horoscope['raw'] ?? null) ? $horoscope['raw'] : [];
        if ($rawLines === []) {
            return [];
        }

        $blob = implode("\n", array_map('strval', $rawLines));
        $row = [
            'rashi_id' => null,
            'nakshatra_id' => null,
            'gan_id' => null,
            'nadi_id' => null,
            'rashi' => $this->extractHoroscopeField($blob, ['रास', 'राशी']),
            'nakshatra' => $this->extractHoroscopeField($blob, ['नक्षत्र']),
            'devak' => $this->extractHoroscopeField($blob, ['देवक']),
            'kuldaivat' => $this->extractHoroscopeField($blob, ['कुलदैवत', 'कुलदेवत']),
            'gotra' => $this->extractHoroscopeField($blob, ['गोत्र']),
            'nadi' => $this->extractHoroscopeField($blob, ['नाडी']),
            'gan' => $this->extractHoroscopeField($blob, ['गण']),
        ];

        $nonEmpty = array_filter($row, static fn ($value, $key) => ! str_ends_with((string) $key, '_id') && $value !== null && $value !== '', ARRAY_FILTER_USE_BOTH);
        if ($nonEmpty === []) {
            return [];
        }

        return [$row];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $parsed
     * @return array<string, float>
     */
    public function mapConfidenceMap(array $draft, array $parsed = []): array
    {
        if ($parsed === []) {
            $parsed = [
                'core' => $this->mapCore($draft),
                'contacts' => $this->mapContacts($draft),
            ];
        }

        $core = is_array($parsed['core'] ?? null) ? $parsed['core'] : [];
        $confidence = [];
        $explicitFields = [
            'full_name', 'gender', 'date_of_birth', 'birth_time', 'birth_place_text',
            'religion', 'caste', 'sub_caste', 'height_cm', 'complexion', 'blood_group',
            'marital_status', 'father_name', 'father_occupation', 'mother_name', 'mother_occupation',
            'brother_count', 'sister_count', 'highest_education', 'occupation_title',
            'company_name', 'annual_income', 'work_location_text', 'primary_contact_number',
        ];

        foreach ($explicitFields as $field) {
            $value = $core[$field] ?? null;
            if ($value === null || $value === '') {
                $confidence['core.'.$field] = self::CONF_MISSING;

                continue;
            }
            if ($field === 'full_name' && $this->hasReviewFlag($draft, 'core.full_name', 'candidate_name_from_heading_fallback')) {
                $confidence['core.'.$field] = self::CONF_FALLBACK;

                continue;
            }
            if ($field === 'gender' && $this->hasReviewFlag($draft, 'core.gender', 'ambiguous_gender')) {
                $confidence['core.'.$field] = self::CONF_MISSING;

                continue;
            }
            $confidence['core.'.$field] = self::CONF_EXPLICIT;
        }

        if (($core['primary_contact_number'] ?? null) !== null) {
            $confidence['core.primary_contact_number'] = self::CONF_EXPLICIT;
        }

        foreach ($parsed['contacts'] ?? [] as $index => $contact) {
            if (! is_array($contact)) {
                continue;
            }
            $phone = (string) ($contact['phone_number'] ?? $contact['number'] ?? '');
            $confidence['contacts.'.$index.'.phone_number'] = preg_match('/^[6-9]\d{9}$/', $phone)
                ? self::CONF_EXPLICIT
                : self::CONF_MISSING;
        }

        return $confidence;
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return array<string, mixed>
     */
    private function pickCoreFields(array $normalized): array
    {
        $fields = [
            'full_name', 'gender', 'date_of_birth', 'birth_time', 'birth_place_text',
            'religion', 'caste', 'sub_caste', 'height_cm', 'complexion', 'blood_group',
            'marital_status', 'father_name', 'father_occupation', 'mother_name', 'mother_occupation',
            'brother_count', 'sister_count', 'highest_education', 'occupation_title',
            'company_name', 'annual_income', 'work_location_text', 'other_relatives_text',
        ];
        $picked = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $normalized)) {
                $picked[$field] = $normalized[$field];
            }
        }

        return $picked;
    }

    /**
     * @param  list<array<string, mixed>>  $contacts
     */
    private function firstNormalizedPhone(array $contacts): ?string
    {
        foreach ($contacts as $contact) {
            if (! is_array($contact)) {
                continue;
            }
            $phone = OcrNormalize::normalizePhone((string) ($contact['phone_number'] ?? $contact['number'] ?? ''));
            if ($phone !== null && preg_match('/^[6-9]\d{9}$/', $phone)) {
                return $phone;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return list<array<string, mixed>>
     */
    private function mapSiblings(array $draft): array
    {
        $rawSiblings = is_array($draft['normalized']['siblings'] ?? null) ? $draft['normalized']['siblings'] : [];
        $mapped = [];
        foreach ($rawSiblings as $sibling) {
            if (! is_array($sibling)) {
                continue;
            }
            $name = trim((string) ($sibling['name'] ?? ''));
            if ($name === '' || in_array($name, ['नाही', 'एक', 'No', 'None', '0', '०'], true)) {
                continue;
            }
            $mapped[] = [
                'relation_type' => (string) ($sibling['relation_type'] ?? ''),
                'name' => $name,
            ];
        }

        return $mapped;
    }

    /**
     * @param  list<array<string, mixed>>  $addresses
     * @return array<string, string>|null
     */
    private function resolveNativePlace(array $addresses): ?array
    {
        foreach ($addresses as $address) {
            if (! is_array($address)) {
                continue;
            }
            if (($address['type'] ?? null) === 'native') {
                $line = trim((string) ($address['address_line'] ?? ''));
                if ($line !== '') {
                    return ['address_line' => $line, 'raw' => $line];
                }
            }
        }

        return null;
    }

    private function inferAddressType(string $line): string
    {
        if (preg_match('/^(?:मुळगाव|मूळगाव|गावचा\s+पत्ता)/u', $line)) {
            return 'native';
        }

        return 'current';
    }

    private function isAddressContaminated(string $line): bool
    {
        return (bool) preg_match('/(?:मोबाईल|मोबाइल|संपर्क|पाहुणे|नातेसंबंध|प्रोपर्टी|स्थावर|कौटुंबिक)/u', $line);
    }

    private function isRelativeContaminated(string $blob): bool
    {
        return (bool) preg_match('/(?:घरचा\s+पत्ता|सध्याचा\s+पत्ता|मोबाईल|मोबाइल|संपर्क|पाहुणे|नातेसंबंध|स्थावर|प्रोपर्टी|शिक्षण|नोकरी)/u', $blob);
    }

    private function isAddressOnlyPropertyText(string $text): bool
    {
        return (bool) preg_match('/^(?:घरचा\s+पत्ता|घराचा\s+पत्ता|सध्याचा\s+पत्ता|गावचा\s+पत्ता)/u', $text)
            && ! preg_match('/(?:स्वत[:ः]?च(?:े|्या)|मालकीच(?:े|्या)|flat|bhk|फ्लॅट|शेती|बागायत|जमीन|एकर)/ui', $text);
    }

    private function inferRelativeRelationType(string $raw): ?string
    {
        if (preg_match('/^मामा/u', $raw)) {
            return 'maternal_uncle';
        }
        if (preg_match('/^मावशी/u', $raw)) {
            return 'maternal_aunt';
        }
        if (preg_match('/^आत्या/u', $raw)) {
            return 'paternal_aunt';
        }

        return null;
    }

    /**
     * @param  list<string>  $labels
     */
    private function extractHoroscopeField(string $blob, array $labels): ?string
    {
        foreach ($labels as $label) {
            $pattern = '/'.preg_quote($label, '/').'\s*(?::\s*-\s*|[:\-]\s*|)\s*([^\n\r|]+)/u';
            if (preg_match($pattern, $blob, $m)) {
                $value = trim($m[1]);
                if ($value !== '' && ! preg_match('/^(?:रास|राशी|नक्षत्र|देवक|कुल)/u', $value)) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function hasReviewFlag(array $draft, string $field, string $reason): bool
    {
        foreach ($draft['review_flags'] ?? [] as $flag) {
            if (! is_array($flag)) {
                continue;
            }
            if (($flag['field'] ?? null) === $field && ($flag['reason'] ?? null) === $reason) {
                return true;
            }
        }

        return false;
    }
}
