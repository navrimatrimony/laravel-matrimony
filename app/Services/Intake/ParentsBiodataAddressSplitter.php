<?php

declare(strict_types=1);

namespace App\Services\Intake;

/**
 * Split biodata "parents home" blobs into flat line, hierarchy search text, and phone digits.
 */
final class ParentsBiodataAddressSplitter
{
    /**
     * @return array{address_line: string, location_text: string, phones: list<string>}
     */
    public static function split(string $raw): array
    {
        $raw = trim(preg_replace('/\s+/u', ' ', $raw));
        if ($raw === '') {
            return ['address_line' => '', 'location_text' => '', 'phones' => []];
        }

        $phones = [];
        if (preg_match_all('/(?<!\d)([6-9]\d{9})(?!\d)/u', $raw, $pm)) {
            $phones = array_values(array_unique($pm[1]));
        }

        $work = preg_replace('/(?<!\d)([6-9]\d{9})(?!\d)/u', ' ', $raw) ?? $raw;
        $work = preg_replace('/\s*[-–—■•|]+\s*(?:मोबाईल|मोबा|मो\.|Mobile|Phone|Contact)(?:\s*नंबर|\s*नं)?\s*$/ui', '', $work) ?? $work;
        $work = preg_replace('/\s*(?:मोबाईल|मोबा|मो\.|Mobile|Phone|Contact)(?:\s*नंबर|\s*नं)?\s*$/ui', '', $work) ?? $work;
        $work = trim($work);

        $addressLine = $work;
        $locationText = '';

        if (preg_match('/^(.*?)(?:,\s*)(ता\.\s*.+)$/u', $work, $m)) {
            $addressLine = trim($m[1]);
            $locationText = trim($m[2]);
        } elseif (preg_match('/^(.*?)(?:,\s*)(जि\.\s*.+)$/u', $work, $m2)) {
            $addressLine = trim($m2[1]);
            $locationText = trim($m2[2]);
        }

        $addressLine = preg_replace('/^मु\.?\s*पो\.?\s*:?\s*/u', '', $addressLine) ?? $addressLine;
        $addressLine = trim($addressLine, " ,-–—");

        if ($locationText === '' && preg_match('/ता\.\s*\S|जि\.\s*\S/u', $work)) {
            $locationText = trim(preg_replace('/^मु\.?\s*पो\.?\s*:?\s*/u', '', $work) ?? $work);
        }

        if ($locationText !== '' && $addressLine !== '' && ! preg_match('/ता\.|जि\./u', $locationText)) {
            $locationText = trim($addressLine.', '.$locationText, " ,");
        }

        return [
            'address_line' => $addressLine,
            'location_text' => $locationText,
            'phones' => $phones,
        ];
    }

    public static function looksLikeParentsHomeBlob(string $raw): bool
    {
        $raw = trim($raw);
        if ($raw === '') {
            return false;
        }

        if (preg_match('/^मु\.?\s*पो\.?/u', $raw)) {
            return true;
        }

        return preg_match('/ता\.\s*\S/u', $raw) === 1
            && preg_match('/जि\.\s*\S/u', $raw) === 1
            && preg_match('/वॉर्ड|पेठ|गल्ली|Flat|House|Ward|ए\s*वॉर्ड/u', $raw) === 1;
    }
}
