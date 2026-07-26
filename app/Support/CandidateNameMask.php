<?php

namespace App\Support;

/**
 * The ONE candidate-name mask.
 *
 * "Shriram Kadam" → "Shriram K." — enough for a Suchak to recognise the person
 * they just searched for, never enough to harvest a directory. Lives in Support
 * (next to ConsentContactRole::maskMobile) because both the duplicate-check
 * matches and the pending-consent-claims listing show the same masked name; two
 * copies would drift apart the first time the rule changes.
 */
final class CandidateNameMask
{
    public static function mask(string $fullName): string
    {
        $tokens = preg_split('/\s+/u', trim($fullName)) ?: [];
        if ($tokens === [] || $tokens[0] === '') {
            return '—';
        }

        $masked = [mb_convert_case($tokens[0], MB_CASE_TITLE, 'UTF-8')];
        foreach (array_slice($tokens, 1) as $token) {
            if ($token !== '') {
                $masked[] = mb_strtoupper(mb_substr($token, 0, 1)).'.';
            }
        }

        return implode(' ', $masked);
    }
}
