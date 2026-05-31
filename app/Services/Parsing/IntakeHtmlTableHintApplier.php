<?php

declare(strict_types=1);

namespace App\Services\Parsing;

use App\Services\Ocr\OcrNormalize;

/**
 * Phase 3d-2: apply safe HTML table hints to normalized draft core + contacts only.
 */
final class IntakeHtmlTableHintApplier
{
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
            $this->applyCoreHints($hints, $core);
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
     */
    private function applyCoreHints(array $hints, array &$core): void
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
        }

        if (isset($hints['mother_name'])) {
            [$name, $occupation] = $this->splitNameOccupation(trim($hints['mother_name']));
            $this->setCoreField($core, 'mother_name', $name, true);
            if ($occupation !== null && $occupation !== '') {
                $this->setCoreField($core, 'mother_occupation', $occupation, false);
            }
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

        return [$this->cleanPersonName($value), $occupation !== '' ? $occupation : null];
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
        if (preg_match('/([0-9]+)\s*(?:फूट|feet|ft)\s*([0-9]+)?/ui', $v, $m)) {
            $feet = (int) $m[1];
            $inches = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;

            return round(($feet * 12 + $inches) * 2.54, 2);
        }
        if (preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*(?:cm|से\.?\s*मी\.?)?$/ui', trim($v), $m)) {
            return (float) $m[1];
        }

        return null;
    }
}
