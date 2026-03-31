<?php

namespace App\Services\Parsing;

/**
 * Detects two-column Marathi biodata OCR layouts: a vertical block of labels only,
 * then a separate block of values in the same order. Emits flat field hints for
 * {@see \App\Services\BiodataParserService} to merge without replacing confident parses.
 */
final class MarathiSeparatedLabelValueExtractor
{
    private const MIN_LABEL_SLOTS = 5;

    private const MAX_LABEL_SCAN_LINES = 48;

    /**
     * Ordered groups: one label row per group (match any pattern). Optional groups
     * may be omitted when the next line does not match any pattern in the group.
     *
     * @var list<array{key: string, optional?: bool, patterns: list<string>}>
     */
    private const SLOT_GROUPS = [
        ['key' => 'full_name', 'patterns' => [
            '/^(?:मुलाचे|मुलीचे|वधूचे)\s+नाव\s*:?\s*$/u',
            '/^(?:मुलाचे|मुलीचे|वधूचे)\s+नांव\s*:?\s*$/u',
        ]],
        ['key' => 'date_of_birth', 'patterns' => [
            '/^जन्म\s*तारीख\s*:?\s*$/u',
            '/^जन्मतारीख\s*:?\s*$/u',
            '/^जन्म\s*दिनांक\s*:?\s*$/u',
        ]],
        ['key' => 'height', 'patterns' => [
            '/^उंची\s*:?\s*$/u',
            '/^ऊंची\s*:?\s*$/u',
        ]],
        ['key' => 'blood_group', 'optional' => true, 'patterns' => [
            '/^(?:रक्त\s*गट|रक्तगट)\s*:?\s*$/u',
        ]],
        ['key' => 'highest_education', 'patterns' => [
            '/^शिक्षण\s*:?\s*$/u',
        ]],
        ['key' => 'occupation_raw', 'optional' => true, 'patterns' => [
            '/^(?:नोकरी|व्यवसाय|व्यवसाय\s*विषय)\s*:?\s*$/u',
        ]],
        ['key' => 'father_name', 'patterns' => [
            '/^मुलाचे\s+वडील\s*:?\s*$/u',
            '/^(?:वडिलांचे|वडीलांचे)\s+(?:नाव|नांव)\s*:?\s*$/u',
            '/^(?:वडिलाचे|वडीलाचे)\s+(?:नाव|नांव)\s*:?\s*$/u',
        ]],
        ['key' => 'mother_name', 'patterns' => [
            '/^मुलाची\s+आई\s*:?\s*$/u',
            '/^आईचे\s+(?:नाव|नांव)\s*:?\s*$/u',
        ]],
        ['key' => 'siblings_note', 'optional' => true, 'patterns' => [
            '/^मुलाचे\s+(?:भाऊ|बहिण|बहीण|भावंडे)\s*:?\s*$/u',
        ]],
        ['key' => 'mama_note', 'optional' => true, 'patterns' => [
            '/^मुलाचे\s+मामा\s*:?\s*$/u',
        ]],
        ['key' => 'atya_note', 'optional' => true, 'patterns' => [
            '/^मुलाची\s+आत्या\s*:?\s*$/u',
        ]],
        ['key' => 'other_relatives_text', 'optional' => true, 'patterns' => [
            '/^इतर\s+पाहुणे\s*:?\s*$/u',
            '/^इतर\s+नातेवाईक\s*:?\s*$/u',
        ]],
        ['key' => 'primary_contact', 'optional' => true, 'patterns' => [
            '/^(?:संपर्क\s*नंबर|संपर्क\s*क्रमांक|मोबाईल|मोबाइल)\s*:?\s*$/u',
        ]],
    ];

    /**
     * @param  list<string>  $lines
     * @return array<string, string>|null
     */
    public static function extract(array $lines): ?array
    {
        $n = count($lines);
        if ($n < 12) {
            return null;
        }

        [$matchedKeys, $valueStartLine] = self::consumeLabelBlock($lines);
        if ($matchedKeys === null || count($matchedKeys) < self::MIN_LABEL_SLOTS) {
            return null;
        }

        while ($valueStartLine < $n && trim((string) $lines[$valueStartLine]) === '') {
            $valueStartLine++;
        }
        if ($valueStartLine >= $n) {
            return null;
        }

        $slotCount = count($matchedKeys);
        $valueSlice = [];
        for ($j = 0; $j < $slotCount; $j++) {
            if ($valueStartLine + $j >= $n) {
                return null;
            }
            $valueSlice[] = trim((string) $lines[$valueStartLine + $j]);
        }

        $hints = [];
        for ($i = 0; $i < $slotCount; $i++) {
            $key = $matchedKeys[$i];
            $val = $valueSlice[$i] ?? '';
            if ($val === '') {
                continue;
            }
            if (self::lineLooksLikeKnownLabel($val)) {
                continue;
            }
            if (! isset($hints[$key])) {
                $hints[$key] = $val;
            } elseif (in_array($key, ['siblings_note', 'mama_note', 'atya_note', 'other_relatives_text'], true)) {
                $hints[$key] = trim($hints[$key]."\n".$val);
            }
        }

        $extraStart = $valueStartLine + $slotCount;
        $overflow = [];
        for ($k = $extraStart; $k < $n; $k++) {
            $t = trim((string) $lines[$k]);
            if ($t === '' || self::isDecorativeNoiseLine($t)) {
                continue;
            }
            if (self::lineLooksLikeKnownLabel($t) && mb_strlen($t) < 72) {
                break;
            }
            $overflow[] = $t;
        }
        if ($overflow !== []) {
            foreach (self::classifyOverflowLines($overflow) as $ek => $ev) {
                if ($ev === '') {
                    continue;
                }
                $hints[$ek] = isset($hints[$ek]) ? trim($hints[$ek]."\n".$ev) : $ev;
            }
        }

        $hints['_separated_layout'] = '1';

        return $hints;
    }

    /**
     * @param  list<string>  $lines
     * @return array{0: list<string>|null, 1: int}
     */
    private static function consumeLabelBlock(array $lines): array
    {
        $n = count($lines);
        $matched = [];
        $i = 0;
        $groups = self::SLOT_GROUPS;
        $gCount = count($groups);

        for ($g = 0; $g < $gCount; $g++) {
            $group = $groups[$g];

            if ($i >= $n) {
                if (! empty($group['optional']) && count($matched) >= self::MIN_LABEL_SLOTS) {
                    break;
                }

                return [null, 0];
            }

            if ($i >= self::MAX_LABEL_SCAN_LINES) {
                return count($matched) >= self::MIN_LABEL_SLOTS ? [$matched, $i] : [null, 0];
            }

            while ($i < $n && trim((string) $lines[$i]) === '') {
                $i++;
            }
            if ($i >= $n) {
                if (! empty($group['optional'])) {
                    continue;
                }

                return count($matched) >= self::MIN_LABEL_SLOTS ? [$matched, $i] : [null, 0];
            }

            $line = trim((string) $lines[$i]);

            if (! self::isPureLabelLineCandidate($line)) {
                if (count($matched) >= self::MIN_LABEL_SLOTS) {
                    return [$matched, $i];
                }

                if (! empty($group['optional'])) {
                    continue;
                }

                return [null, 0];
            }

            $ok = false;
            foreach ($group['patterns'] as $re) {
                if (preg_match($re, $line) === 1) {
                    $ok = true;
                    break;
                }
            }

            if ($ok) {
                $matched[] = $group['key'];
                $i++;

                continue;
            }

            if (! empty($group['optional'])) {
                if (self::lineMatchesAnyFutureGroup($line, $g + 1)) {
                    continue;
                }
                if (count($matched) >= self::MIN_LABEL_SLOTS) {
                    return [$matched, $i];
                }

                continue;
            }

            if (count($matched) >= self::MIN_LABEL_SLOTS) {
                return [$matched, $i];
            }

            return [null, 0];
        }

        while ($i < $n && trim((string) $lines[$i]) === '') {
            $i++;
        }

        return [$matched, $i];
    }

    private static function lineMatchesAnyFutureGroup(string $line, int $fromIndex): bool
    {
        $groups = self::SLOT_GROUPS;
        for ($h = $fromIndex, $c = count($groups); $h < $c; $h++) {
            foreach ($groups[$h]['patterns'] as $re) {
                if (preg_match($re, $line) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function isPureLabelLineCandidate(string $line): bool
    {
        if (mb_strlen($line) > 96) {
            return false;
        }
        if (preg_match('/[०-९0-9]{1,2}\s*[\/\.\-]\s*[०-९0-9]{1,2}\s*[\/\.\-]\s*[०-९0-9]{2,4}/u', $line)) {
            return false;
        }
        if (preg_match('/\+ve|-ve|[ABO]\s*[\+\-]/ui', $line)) {
            return false;
        }
        if (preg_match('/\b(?:B\.Com|B\.A\.?|M\.|LL\.B|BE|B\.E|B\.Tech|MBA)\b/ui', $line)) {
            return false;
        }
        if (preg_match('/(?:चि\.|कु\.|श्री\.|डॉ\.)\s*\S{4,}/u', $line)) {
            return false;
        }
        if (preg_match('/[6-9]\d{9}/', $line)) {
            return false;
        }

        return true;
    }

    private static function lineLooksLikeKnownLabel(string $line): bool
    {
        $t = trim($line);
        if ($t === '') {
            return false;
        }
        foreach (self::SLOT_GROUPS as $group) {
            foreach ($group['patterns'] as $re) {
                if (preg_match($re, $t) === 1) {
                    return true;
                }
            }
        }

        if (preg_match('/^(?:मुलाचे|मुलीचे)\s+(?:वडील|आई|भाऊ|मामा)\s*$/u', $t)) {
            return true;
        }
        if ($t === 'मुलाची आई' || $t === 'मुलाचे वडील') {
            return true;
        }

        return false;
    }

    private static function isDecorativeNoiseLine(string $line): bool
    {
        return (bool) preg_match('/मुंबई\s*जॉब|विवाह\s*संस्था|सूचक\s*केंद्र/u', $line);
    }

    /**
     * @param  list<string>  $lines
     * @return array<string, string>
     */
    private static function classifyOverflowLines(array $lines): array
    {
        $notes = [];
        $rel = [];
        $occ = [];
        $con = [];

        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '') {
                continue;
            }
            if (preg_match('/[6-9]\d{9}/', $t)) {
                $con[] = $t;
                continue;
            }
            if (preg_match('/एकर|गुंठा|हेक्टर|प्लॉट|शेती/u', $t)) {
                $notes[] = $t;
                continue;
            }
            if (preg_match('/(?:Pvt|Ltd|Limited|LLP|LLC|लिमिटेड)\b/ui', $t) || preg_match('/नोकरी|वेतन|ऑफिस|कंपनी/u', $t)) {
                $occ[] = $t;
                continue;
            }
            if (preg_match('/मु\.पो\.|ता\.|जि\.|गाव|रा\./u', $t)) {
                $rel[] = $t;
                continue;
            }
            if (mb_strlen($t) >= 12 && preg_match('/\p{L}{3,}/u', $t)) {
                $rel[] = $t;
            } else {
                $notes[] = $t;
            }
        }

        $out = [];
        if ($con !== []) {
            $out['overflow_contact'] = implode("\n", $con);
        }
        if ($occ !== []) {
            $out['overflow_occupation'] = implode("\n", $occ);
        }
        if ($rel !== []) {
            $out['overflow_relatives'] = implode("\n", $rel);
        }
        if ($notes !== []) {
            $out['overflow_notes'] = implode("\n", $notes);
        }

        return $out;
    }
}
