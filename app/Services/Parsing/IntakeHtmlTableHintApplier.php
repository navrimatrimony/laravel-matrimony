<?php

declare(strict_types=1);

namespace App\Services\Parsing;

use App\Services\Ocr\OcrNormalize;

/**
 * Phase 3d-2/3d-3: apply safe HTML table hints to normalized draft core, contacts,
 * addresses, other_relatives_text, and property_summary.
 */
final class IntakeHtmlTableHintApplier
{
    /** @var list<string> */
    private const OTHER_RELATIVES_POLLUTION = [
        'अपेक्षा', 'शिक्षण', 'नोकरी', 'मोबाईल', 'मोबाइल', 'संपर्क',
        'जन्म तारीख', 'जन्म स्थळ', 'प्रॉपर्टी', 'प्रॉपर्टि',
    ];

    /** @var list<string> */
    private const OTHER_RELATIVES_HINT_KEYS = [
        'other_relatives_text', 'other_relatives', 'relatives_other', 'pahune', 'पाहुणे', 'नातेसंबंध',
    ];

    /** @var list<string> */
    private const PROPERTY_HINT_KEYS = [
        'property_summary', 'property', 'other_property', 'इतर प्रॉपर्टी', 'स्थावर', 'शेती', 'प्लॉट', 'फ्लॅट',
    ];

    /** @var list<string> */
    private const AUTHORITATIVE_CORE = [
        'full_name',
        'date_of_birth',
        'birth_time',
        'birth_place_text',
        'father_name',
        'mother_name',
        'primary_contact_number',
    ];

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function apply(array $draft): array
    {
        $meta = is_array($draft['meta'] ?? null) ? $draft['meta'] : [];
        if (empty($meta['html_table_structured'])) {
            return $draft;
        }

        $hints = is_array($meta['table_hints'] ?? null) ? $meta['table_hints'] : [];
        unset($hints[HtmlMarathiBiodataTableExtractor::STRUCTURED_MARKER]);

        if (! is_array($draft['normalized'] ?? null)) {
            $draft['normalized'] = [];
        }
        if (! is_array($draft['normalized']['core'] ?? null)) {
            $draft['normalized']['core'] = [];
        }

        $core = &$draft['normalized']['core'];
        if ($hints !== []) {
            $this->applyCoreHints($hints, $core, $draft['normalized']);
            $this->applyAddressHints($hints, $core, $draft['normalized'], $draft);
            $this->applyOtherRelativesTextHints($hints, $core);
            $this->applyPropertySummaryHints($hints, $draft['normalized']);
            $this->applyHoroscopeHints($hints, $draft['normalized']);
            $this->applySiblingHints($hints, $core, $draft['normalized']);
        }
        $this->applyContactsFromHints(
            $hints,
            $draft['normalized'],
            $core,
            isset($meta['post_table_body']) ? (string) $meta['post_table_body'] : null
        );

        return $draft;
    }

    /**
     * @param  array<string, string>  $hints
     * @param  array<string, mixed>  $core
     * @param  array<string, mixed>  $normalized
     */
    private function applyCoreHints(array $hints, array &$core, array &$normalized): void
    {
        if (isset($hints['full_name'])) {
            $this->setCoreField($core, 'full_name', $this->cleanPersonName(trim($hints['full_name'])), true);
        }

        if (isset($hints['date_of_birth'])) {
            $raw = trim($hints['date_of_birth']);
            $dob = OcrNormalize::normalizeDate($raw);
            if ($dob === $raw || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $dob)) {
                $dob = trim(OcrNormalize::normalizeDigits($raw) ?? $raw);
            }
            $this->setCoreField($core, 'date_of_birth', $dob !== '' ? $dob : null, true);
        }

        if (isset($hints['birth_time'])) {
            $this->setCoreField($core, 'birth_time', trim($hints['birth_time']), true);
        }

        if (isset($hints['birth_place'])) {
            $this->setCoreField($core, 'birth_place_text', trim($hints['birth_place']), true);
        }

        if (isset($hints['highest_education'])) {
            $this->setCoreField($core, 'highest_education', trim($hints['highest_education']), false);
        }

        if (isset($hints['occupation_raw'])) {
            $this->setCoreField($core, 'occupation_title', trim($hints['occupation_raw']), false);
        }

        if (isset($hints['complexion'])) {
            $this->setCoreField($core, 'complexion', trim($hints['complexion']), false);
        }

        if (isset($hints['blood_group'])) {
            $bg = OcrNormalize::normalizeBloodGroup(trim($hints['blood_group']));
            $this->setCoreField($core, 'blood_group', $bg ?? trim($hints['blood_group']), false);
        }

        if (isset($hints['height'])) {
            $cm = $this->parseHeightCm(trim($hints['height']));
            if ($cm !== null) {
                $this->setCoreField($core, 'height_cm', $cm, false);
            }
        }

        if (isset($hints['caste'])) {
            $this->applyCasteHint(trim($hints['caste']), $core);
        }

        if (isset($hints['father_name'])) {
            [$name, $occupation] = $this->splitNameOccupation(trim($hints['father_name']));
            $this->setCoreField($core, 'father_name', $name, true);
            if ($occupation !== null && $occupation !== '') {
                $this->setCoreField($core, 'father_occupation', $occupation, false);
            }
            $this->applyParentContactAndAddressHint(trim($hints['father_name']), 'father', $core, $normalized);
        }

        if (isset($hints['mother_name'])) {
            [$name, $occupation] = $this->splitNameOccupation(trim($hints['mother_name']));
            $this->setCoreField($core, 'mother_name', $name, true);
            if ($occupation !== null && $occupation !== '') {
                $this->setCoreField($core, 'mother_occupation', $occupation, false);
            }
            $this->applyParentContactAndAddressHint(trim($hints['mother_name']), 'mother', $core, $normalized);
        }
    }

    /**
     * @param  array<string, string>  $hints
     * @param  array<string, mixed>  $normalized
     * @param  array<string, mixed>  $core
     */
    private function applyContactsFromHints(array $hints, array &$normalized, array &$core, ?string $postTableBody): void
    {
        $hintBlob = trim($hints['primary_contact'] ?? '');
        $phones = $hintBlob !== '' ? $this->extractValidPhones($hintBlob) : [];

        if ($phones === []) {
            $phones = $this->phonesFromNormalizedContacts($normalized);
            $parentPhones = $this->parentPhonesFromCore($core);
            if ($parentPhones !== []) {
                $phones = array_values(array_filter(
                    $phones,
                    static fn (string $phone): bool => ! isset($parentPhones[$phone])
                ));
            }
            $footerPhones = $postTableBody !== null && trim($postTableBody) !== ''
                ? $this->extractValidPhones($postTableBody)
                : [];
            if ($footerPhones !== []) {
                $phones = array_values(array_filter(
                    $phones,
                    static fn (string $phone): bool => ! in_array($phone, $footerPhones, true)
                ));
            }
        }

        if ($phones === []) {
            return;
        }

        $contacts = [];
        foreach ($phones as $index => $phone) {
            $contacts[] = [
                'phone_number' => $phone,
                'number' => $phone,
                'is_primary' => $index === 0,
            ];
        }

        $normalized['contacts'] = $contacts;
        $core['primary_contact_number'] = $phones[0];
    }

    /**
     * @param  array<string, mixed>  $core
     * @return array<string, true>
     */
    private function parentPhonesFromCore(array $core): array
    {
        $phones = [];
        foreach (['father', 'mother'] as $parent) {
            foreach (['contact_number', 'contact_1', 'contact_2', 'contact_3'] as $suffix) {
                $phone = OcrNormalize::normalizePhone((string) ($core[$parent.'_'.$suffix] ?? ''));
                if (is_string($phone) && preg_match('/^[6-9]\d{9}$/', $phone)) {
                    $phones[$phone] = true;
                }
            }
        }

        return $phones;
    }

    /**
     * @param  array<string, mixed>  $core
     */
    private function setCoreField(array &$core, string $field, mixed $value, bool $authoritative): void
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return;
        }

        $current = $core[$field] ?? null;
        $currentEmpty = $current === null || (is_string($current) && trim((string) $current) === '');

        if ($authoritative || $currentEmpty) {
            $core[$field] = $value;
        }
    }

    /**
     * @param  array<string, mixed>  $core
     */
    private function applyCasteHint(string $value, array &$core): void
    {
        $scratch = [
            'religion' => $core['religion'] ?? null,
            'caste' => $core['caste'] ?? null,
            'sub_caste' => $core['sub_caste'] ?? null,
        ];
        $this->normalizeCasteLine($value, $scratch);

        foreach (['religion', 'caste', 'sub_caste'] as $field) {
            if (($scratch[$field] ?? null) !== null && trim((string) $scratch[$field]) !== '') {
                $this->setCoreField($core, $field, $scratch[$field], false);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return list<string>
     */
    private function phonesFromNormalizedContacts(array $normalized): array
    {
        $phones = [];
        foreach ($normalized['contacts'] ?? [] as $contact) {
            if (! is_array($contact)) {
                continue;
            }
            $phone = OcrNormalize::normalizePhone((string) ($contact['phone_number'] ?? $contact['number'] ?? ''));
            if (is_string($phone) && preg_match('/^[6-9]\d{9}$/', $phone)) {
                $phones[$phone] = $phone;
            }
        }

        return array_values($phones);
    }

    /**
     * @return list<string>
     */
    private function extractValidPhones(string $blob): array
    {
        $normalized = OcrNormalize::normalizeDigits($blob);
        $found = [];
        if (preg_match_all('/(?<!\d)([6-9]\d{9})(?!\d)/u', $normalized, $matches)) {
            foreach ($matches[1] as $phone) {
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
        if (($core['caste'] ?? null) === null && preg_match('/^मराठा$/u', $value)) {
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
            $occupation = $this->cleanOccupationText($m[1]);
            $value = trim(preg_replace('/[\(\{]\s*.+?\s*[\)\}]/u', '', $value) ?? $value);
        }

        return [$this->cleanPersonName($this->stripTrailingAddressAndPhones($value)), $occupation !== '' ? $occupation : null];
    }

    private function stripTrailingAddressAndPhones(string $value): string
    {
        $value = preg_split('/\s+(?:घरचा\s+पत्ता|घराचा\s+पत्ता|सध्याचा\s+पत्ता|निवासी\s+पत्ता|पत्ता|मोबाईल|मोबाइल|संपर्क|मो\.?\s*नं\.?)\s*[:\-]/u', $value, 2)[0] ?? $value;
        $value = preg_split('/(?:\R|\s)+(?:मू\.?\s*पो\.?|मु\.?\s*पो\.?|रा\.|-\s*\(?\s*मो\.?\s*नं\.?|\(?\s*मो\.?\s*नं\.?)/u', $value, 2)[0] ?? $value;
        $value = preg_replace('/(?<!\d)[6-9]\d{9}(?!\d)/u', '', OcrNormalize::normalizeDigits($value)) ?? $value;

        return $this->trimSeparators($value);
    }

    /**
     * @param  array<string, mixed>  $core
     * @param  array<string, mixed>  $normalized
     */
    private function applyParentContactAndAddressHint(string $value, string $parent, array &$core, array &$normalized): void
    {
        $phones = $this->extractValidPhones($value);
        if ($phones !== []) {
            foreach ($phones as $index => $phone) {
                $key = $parent.'_contact_'.($index + 1);
                if ($index > 2 || ! empty($core[$key] ?? null)) {
                    continue;
                }
                $core[$key] = $phone;
            }
            if (empty($core[$parent.'_contact_number'] ?? null)) {
                $core[$parent.'_contact_number'] = $phones[0];
            }
        }

        $address = $this->extractParentAddressFromHint($value);
        if ($address === '') {
            return;
        }
        if (! is_array($normalized['parents_addresses'] ?? null)) {
            $normalized['parents_addresses'] = [];
        }
        $key = $this->normalizeAddressKey($address);
        foreach ($normalized['parents_addresses'] as $existing) {
            if (! is_array($existing)) {
                continue;
            }
            $existingLine = trim((string) ($existing['address_line'] ?? $existing['raw'] ?? ''));
            if ($this->normalizeAddressKey($existingLine) === $key) {
                return;
            }
        }
        $normalized['parents_addresses'][] = [
            'address_line' => $address,
            'raw' => $address,
            'type' => 'parents',
            'address_type_key' => 'permanent',
        ];
    }

    private function extractParentAddressFromHint(string $value): string
    {
        if (! preg_match('/(?:^|\R|\s)(मू\.?\s*पो\.?|मु\.?\s*पो\.?|रा\.)\s*(.+)$/us', $value, $m)) {
            return '';
        }
        $address = trim(($m[1] ?? '').' '.($m[2] ?? ''));
        $address = preg_split('/(?:\R|\s)*-?\s*\(?\s*(?:मो\.?\s*नं\.?|मोबाईल|मोबाइल|संपर्क)\s*[:\-]?/u', $address, 2)[0] ?? $address;

        return $this->cleanAddressLine($address);
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
        return trim(preg_replace('/^[\s,\-–—]+|[\s,\-–—]+$/u', '', $value) ?? $value);
    }

    private function cleanPersonName(string $value): string
    {
        $value = trim($value);

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
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
        if (preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*(?:cm|से\.?\s*मी\.?)?$/ui', trim($v), $m)) {
            return (float) $m[1];
        }

        return null;
    }

    /**
     * @param  array<string, string>  $hints
     * @param  array<string, mixed>  $core
     * @param  array<string, mixed>  $normalized
     * @param  array<string, mixed>  $draft
     */
    private function applyAddressHints(array $hints, array &$core, array &$normalized, array &$draft): void
    {
        $currentRaw = $this->firstHintValue($hints, ['address_current', 'current_address', 'address']);
        $nativeRaw = $this->firstHintValue($hints, ['address_native', 'native_address']);
        $parentsRaw = $this->firstHintValue($hints, ['address_parents', 'parents_address']);

        if ($currentRaw === null && $nativeRaw === null && $parentsRaw === null) {
            return;
        }

        if (! is_array($normalized['addresses'] ?? null)) {
            $normalized['addresses'] = [];
        }

        $seen = [];
        foreach ($normalized['addresses'] as $existing) {
            if (! is_array($existing)) {
                continue;
            }
            $line = trim((string) ($existing['address_line'] ?? $existing['raw'] ?? ''));
            if ($line !== '') {
                $seen[$this->normalizeAddressKey($line)] = true;
            }
        }

        if ($parentsRaw !== null) {
            $parentsLine = $this->cleanAddressLine($parentsRaw);
            if ($parentsLine !== '') {
                if (! is_array($normalized['parents_addresses'] ?? null)) {
                    $normalized['parents_addresses'] = [];
                }
                $parentsKey = $this->normalizeAddressKey($parentsLine);
                $alreadyStored = false;
                foreach ($normalized['parents_addresses'] as $existingParent) {
                    if (! is_array($existingParent)) {
                        continue;
                    }
                    $existingLine = trim((string) ($existingParent['address_line'] ?? $existingParent['raw'] ?? ''));
                    if ($this->normalizeAddressKey($existingLine) === $parentsKey) {
                        $alreadyStored = true;
                        break;
                    }
                }
                if (! $alreadyStored) {
                    $normalized['parents_addresses'][] = [
                        'address_line' => $parentsLine,
                        'raw' => $parentsLine,
                        'type' => 'parents',
                    ];
                }

                $meta = is_array($draft['meta'] ?? null) ? $draft['meta'] : [];
                $meta['table_hint_parents_address'] = $parentsLine;
                $draft['meta'] = $meta;
            }
        }

        if ($nativeRaw !== null) {
            $nativeLine = $this->cleanAddressLine($nativeRaw);
            if ($nativeLine !== '') {
                $this->upsertTypedAddress($normalized['addresses'], $nativeLine, 'native', $seen);
            }
        }

        if ($currentRaw !== null) {
            $currentLine = $this->cleanAddressLine($currentRaw);
            if ($currentLine !== '') {
                $this->upsertTypedAddress($normalized['addresses'], $currentLine, 'current', $seen);
            }
            if ($currentLine !== '') {
                $this->setCoreField($core, 'address_line', $currentLine, true);
            }
        }
    }

    /**
     * @param  array<string, string>  $hints
     * @param  array<string, mixed>  $core
     */
    private function applyOtherRelativesTextHints(array $hints, array &$core): void
    {
        $raw = $this->firstHintValue($hints, self::OTHER_RELATIVES_HINT_KEYS);
        if ($raw === null) {
            return;
        }

        $clean = trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw);
        if ($clean === '' || $this->otherRelativesTextLooksPolluted($clean)) {
            return;
        }

        $current = trim((string) ($core['other_relatives_text'] ?? ''));
        if ($current !== '' && $this->otherRelativesTextLooksPolluted($current)) {
            $this->setCoreField($core, 'other_relatives_text', $clean, true);

            return;
        }

        $this->setCoreField($core, 'other_relatives_text', $clean, true);
    }

    /**
     * @param  array<string, string>  $hints
     * @param  array<string, mixed>  $normalized
     */
    private function applyPropertySummaryHints(array $hints, array &$normalized): void
    {
        $raw = $this->firstHintValue($hints, self::PROPERTY_HINT_KEYS);
        if ($raw === null) {
            return;
        }

        $summary = $this->buildPropertySummary($raw);
        if ($summary === null) {
            return;
        }

        $normalized['property_summary'] = $summary;
    }

    /**
     * @param  array<string, string>  $hints
     * @param  array<string, mixed>  $normalized
     */
    private function applyHoroscopeHints(array $hints, array &$normalized): void
    {
        $map = [
            'horoscope_rashi' => 'rashi',
            'horoscope_nakshatra' => 'nakshatra',
            'horoscope_nadi' => 'nadi',
            'horoscope_gan' => 'gan',
            'horoscope_charan' => 'charan',
            'horoscope_yoni' => 'yoni',
            'horoscope_varna' => 'varna',
            'horoscope_vairavarga' => 'vashya',
            'horoscope_swami' => 'rashi_lord',
            'devak' => 'devak',
            'kuldaivat' => 'kuldaivat',
            'gotra' => 'gotra',
        ];

        foreach ($map as $hintKey => $field) {
            $value = trim((string) ($hints[$hintKey] ?? ''));
            if ($value === '' || $this->horoscopeValueLooksPolluted($value)) {
                continue;
            }
            if (! is_array($normalized['horoscope'] ?? null)) {
                $normalized['horoscope'] = ['raw' => []];
            }
            $normalized['horoscope'][$field] = $value;
            $rawLine = $this->horoscopeFieldLabel($field).': '.$value;
            if (! is_array($normalized['horoscope']['raw'] ?? null)) {
                $normalized['horoscope']['raw'] = [];
            }
            if (! in_array($rawLine, $normalized['horoscope']['raw'], true)) {
                $normalized['horoscope']['raw'][] = $rawLine;
            }
        }
    }

    /**
     * @param  array<string, string>  $hints
     * @param  array<string, mixed>  $core
     * @param  array<string, mixed>  $normalized
     */
    private function applySiblingHints(array $hints, array &$core, array &$normalized): void
    {
        foreach ([
            'sibling_brother_line' => ['brother', 'brother_count'],
            'sibling_sister_line' => ['sister', 'sister_count'],
        ] as $hintKey => [$relation, $countField]) {
            $raw = trim((string) ($hints[$hintKey] ?? ''));
            if ($raw === '') {
                continue;
            }
            foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                if ($this->isNoSiblingValue($line)) {
                    $core[$countField] = 0;
                    continue;
                }
                if ($this->isNumericCountValue($line)) {
                    $core[$countField] = (int) OcrNormalize::normalizeDigits($line);
                    continue;
                }
                if (! is_array($normalized['siblings'] ?? null)) {
                    $normalized['siblings'] = [];
                }
                [$name, $occupation] = $this->splitNameOccupation($line);
                $name = $this->cleanSiblingHintName($name);
                $sibling = ['relation_type' => $relation, 'name' => $name !== '' ? $name : $line];
                if ($occupation !== null && $occupation !== '') {
                    if ($this->looksLikeSiblingAdditionalInfoText($occupation)) {
                        $sibling['notes'] = $occupation;
                    } else {
                        $sibling['occupation'] = $occupation;
                    }
                }
                $normalized['siblings'][] = $sibling;
            }
        }
        if (is_array($normalized['siblings'] ?? null)) {
            $normalized['siblings'] = $this->dedupeRows($normalized['siblings'], ['relation_type', 'name', 'occupation', 'notes']);
        }
    }

    private function cleanSiblingHintName(string $value): string
    {
        $value = preg_replace('/^\s*(?:चि\.?|कु\.?|श्री\.?|सौ\.?)\s*/u', '', $value) ?? $value;

        return $this->trimSeparators($value);
    }

    private function looksLikeSiblingAdditionalInfoText(string $value): bool
    {
        return (bool) preg_match('/\b(?:B\.?\s*A|B\.?\s*Com|B\.?\s*Sc|B\.?\s*E|M\.?\s*A|M\.?\s*Com|M\.?\s*Sc|MBA|BBA|BA|BCOM|BSC|BE|ME|ITI|Diploma|डिप्लोमा)\b/ui', $value);
    }

    /**
     * @param  array<string, string>  $hints
     * @param  list<string>  $keys
     */
    private function firstHintValue(array $hints, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (! isset($hints[$key])) {
                continue;
            }
            $value = trim((string) $hints[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function cleanAddressLine(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        if ($value === '') {
            return '';
        }

        if ($this->extractValidPhones($value) !== []) {
            $value = trim(preg_replace('/(?:मोबाईल|मोबाइल|संपर्क|Mobile|Phone)\s*(?:नं\.?|नंबर|No\.?)?\s*[:\-]?\s*[\d०-९\s\-\/]+/ui', '', $value) ?? $value);
            $value = trim(preg_replace('/(?<!\d)([6-9]\d{9})(?!\d)/u', '', OcrNormalize::normalizeDigits($value)) ?? $value);
        }

        if (preg_match('/^(?:मोबाईल|मोबाइल|संपर्क|Print\s*Shop)/ui', $value)) {
            return '';
        }

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function normalizeAddressKey(string $line): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $line) ?? $line));
    }

    /**
     * @param  list<array<string, mixed>>  $addresses
     * @param  array<string, true>  $seen
     */
    private function upsertTypedAddress(array &$addresses, string $line, string $type, array &$seen): void
    {
        $key = $this->normalizeAddressKey($line);
        if ($key === '') {
            return;
        }

        foreach ($addresses as &$existing) {
            if (! is_array($existing)) {
                continue;
            }
            $existingLine = trim((string) ($existing['address_line'] ?? $existing['raw'] ?? ''));
            if ($existingLine === '' || $this->normalizeAddressKey($existingLine) === $key) {
                $existing['address_line'] = $line;
                $existing['raw'] = $line;
                $existing['type'] = $type;
                $seen[$key] = true;
                unset($existing);

                return;
            }
        }
        unset($existing);

        if (isset($seen[$key])) {
            return;
        }

        $addresses[] = [
            'address_line' => $line,
            'raw' => $line,
            'type' => $type,
        ];
        $seen[$key] = true;
    }

    private function otherRelativesTextLooksPolluted(string $text): bool
    {
        foreach (self::OTHER_RELATIVES_POLLUTION as $marker) {
            if (mb_stripos($text, $marker) !== false) {
                return true;
            }
        }

        if ($this->extractValidPhones($text) !== []) {
            return true;
        }

        return (bool) preg_match('/(?:जन्म\s*तारीख|जन्म\s*स्थळ|इतर\s*प्रॉपर्टी)/u', $text);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildPropertySummary(string $raw): ?array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw);
        if ($text === '' || $this->isAddressOnlyPropertyText($text)) {
            return null;
        }

        $landAcres = null;
        if (preg_match('/([0-9०-९]+(?:\.[0-9]+)?)\s*(?:एकर|acre|acres)/ui', $text, $m)) {
            $digits = OcrNormalize::normalizeDigits($m[1]);
            if (is_numeric($digits)) {
                $landAcres = (float) $digits;
            }
        }

        $ownsHouse = (bool) preg_match('/(?:स्वत[:ः]?च(?:े|्या)|मालकीच(?:े|्या))\s*(?:घर)?/u', $text);
        $ownsFlat = (bool) preg_match('/(?:flat|bhk|फ्लॅट|फ्लाट|apartment)/ui', $text);
        $ownsAgriculture = (bool) preg_match('/(?:शेती|बागायत|जमीन|agri|agriculture|land|एकर)/ui', $text);

        if (! $ownsHouse && ! $ownsFlat && ! $ownsAgriculture && $landAcres === null) {
            return null;
        }

        return [
            'owns_house' => $ownsHouse,
            'owns_flat' => $ownsFlat,
            'owns_agriculture' => $ownsAgriculture,
            'total_land_acres' => $landAcres,
            'summary_text' => $text,
            'summary_notes' => $text,
        ];
    }

    private function isAddressOnlyPropertyText(string $text): bool
    {
        return (bool) preg_match('/^(?:घरचा\s+पत्ता|घराचा\s+पत्ता|सध्याचा\s+पत्ता|गावचा\s+पत्ता|मु\.?\s*पो\.?)/u', $text)
            && ! preg_match('/(?:स्वत[:ः]?च(?:े|्या)|मालकीच(?:े|्या)|flat|bhk|फ्लॅट|शेती|बागायत|जमीन|एकर)/ui', $text);
    }

    private function horoscopeValueLooksPolluted(string $value): bool
    {
        return (bool) preg_match('/(?:ब्लड\s*ग्रुप|रक्त\s*गट|मोबाईल|मोबाइल|संपर्क|प्रोपर्टी|प्रॉपर्टी|स्थावर|घरचा\s+पत्ता|सध्याचा\s+पत्ता)/u', $value);
    }

    private function horoscopeFieldLabel(string $field): string
    {
        return [
            'rashi' => 'राशी',
            'nakshatra' => 'नक्षत्र',
            'nadi' => 'नाडी',
            'gan' => 'गण',
            'charan' => 'चरण',
            'devak' => 'देवक',
            'kuldaivat' => 'कुलदैवत',
            'gotra' => 'गोत्र',
        ][$field] ?? $field;
    }

    private function isNoSiblingValue(string $value): bool
    {
        $value = preg_replace('/^[\s.।:(){}\[\]\-–—]+|[\s.।:(){}\[\]\-–—]+$/u', '', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return (bool) preg_match('/^(?:नाही|None|No|०|0)$/ui', $value);
    }

    private function isNumericCountValue(string $value): bool
    {
        $v = trim(OcrNormalize::normalizeDigits($value));

        return (bool) preg_match('/^[0-9]+$/', $v);
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
            if (! is_array($row)) {
                continue;
            }
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
}
