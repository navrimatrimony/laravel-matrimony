<?php

namespace App\Services\Intake;

use App\Services\Ocr\OcrNormalize;
use App\Support\MobileNumber;

class BulkIntakeCandidateMobileCollector
{
    /**
     * @param  array<string, mixed>  $parsed
     * @return list<string>
     */
    public function collectFromSources(array $parsed, ?string $rawOcrText = null): array
    {
        $rawValues = [];

        foreach ([
            'core.primary_contact_number',
            'core.mobile',
            'core.user_contact_1',
            'core.contact_number',
            'core.all_contact_numbers',
            'primary_contact_number',
            'mobile',
            'user_contact_1',
            'contact_number',
            'all_contact_numbers',
        ] as $path) {
            $value = data_get($parsed, $path);
            if (is_array($value)) {
                foreach ($value as $entry) {
                    if (is_string($entry) && trim($entry) !== '') {
                        $rawValues[] = $entry;
                    }
                }
            } elseif (is_string($value) && trim($value) !== '') {
                $rawValues[] = $value;
            }
        }

        $contacts = $parsed['contacts'] ?? [];
        if (is_array($contacts)) {
            foreach ($contacts as $contact) {
                if (! is_array($contact)) {
                    continue;
                }

                foreach (['phone_number', 'number', 'mobile', 'contact_number'] as $key) {
                    $value = $contact[$key] ?? null;
                    if (is_string($value) && trim($value) !== '') {
                        $rawValues[] = $value;
                    }
                }
            }
        }

        if (is_string($rawOcrText) && trim($rawOcrText) !== '') {
            $rawValues[] = $rawOcrText;
        }

        return $this->normalizeUnique($rawValues);
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    public function displayFromSources(array $parsed, ?string $rawOcrText = null): ?string
    {
        $mobiles = $this->collectFromSources($parsed, $rawOcrText);

        return $mobiles === [] ? null : implode(', ', $mobiles);
    }

    /**
     * @return list<string>
     */
    public function parseInput(?string $input): array
    {
        if (! is_string($input) || trim($input) === '') {
            return [];
        }

        return $this->normalizeUnique(preg_split('/[,;\n|]+/', $input) ?: []);
    }

    /**
     * @param  list<string>  $rawValues
     * @return list<string>
     */
    private function normalizeUnique(array $rawValues): array
    {
        $seen = [];
        $normalized = [];

        foreach ($rawValues as $rawValue) {
            foreach ($this->digitSequences((string) $rawValue) as $sequence) {
                $mobile = MobileNumber::normalize($sequence);
                if ($mobile === null || isset($seen[$mobile])) {
                    continue;
                }

                $seen[$mobile] = true;
                $normalized[] = $mobile;
            }
        }

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private function digitSequences(string $text): array
    {
        $text = OcrNormalize::normalizeDigits($text);
        $sequences = [];

        foreach (preg_split('/[,;\n|\/]+/', $text) ?: [] as $segment) {
            $this->appendMobileSequencesFromSegment(trim($segment), $sequences);
        }

        $this->appendMobileSequencesFromSegment($text, $sequences);

        return array_values(array_unique($sequences));
    }

    /**
     * @param  list<string>  $sequences
     */
    private function appendMobileSequencesFromSegment(string $segment, array &$sequences): void
    {
        if ($segment === '') {
            return;
        }

        $compact = preg_replace('/\D+/', '', $segment) ?? '';
        if ($compact === '') {
            return;
        }

        if (strlen($compact) === 12 && str_starts_with($compact, '91')) {
            $sequences[] = substr($compact, 2);

            return;
        }

        if (strlen($compact) === 10) {
            $sequences[] = $compact;

            return;
        }

        if (preg_match_all('/(?<!\d)([6-9]\d{9})(?!\d)/', $segment, $matches) === 1) {
            foreach ($matches[1] as $match) {
                $sequences[] = $match;
            }
        }
    }
}
