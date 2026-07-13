<?php

namespace App\Services\Intake\OcrEnsemble\Support;

use App\Services\Ocr\OcrNormalize;

/**
 * Production Phase 3 candidate name extraction and cleanup from OCR text.
 */
class OcrEnsembleNameExtractor
{
    /**
     * @param  list<string>  $lines
     */
    public function extract(array $lines, ?string $coreName = null, ?string $hintName = null): ?string
    {
        $candidates = [];

        foreach ([$coreName, $hintName] as $candidate) {
            $cleaned = $this->cleanCandidateName($candidate);
            if ($this->validCandidateName($cleaned)) {
                $candidates[] = ['name' => $cleaned, 'score' => 40];
            }
        }

        foreach ($lines as $index => $line) {
            if ($this->isFooterNoise($line)) {
                continue;
            }

            if ($this->hasCandidateNameLabel($line)) {
                $value = $this->valueAfterNameLabel($line) ?? trim((string) ($lines[$index + 1] ?? ''));
                $cleaned = $this->cleanCandidateName($value);
                if ($this->validCandidateName($cleaned)) {
                    $candidates[] = ['name' => $cleaned, 'score' => 100];
                }

                continue;
            }

            if ($this->isHtmlNoiseLine($line)) {
                continue;
            }
        }

        foreach ($this->candidateScopedLines($lines) as $line) {
            if ($this->hasRelationContext($line) || ! $this->hasCandidateHonorific($line)) {
                continue;
            }

            $cleaned = $this->cleanCandidateName($line);
            if ($this->validRescuedName($cleaned)) {
                $candidates[] = ['name' => $cleaned, 'score' => 60];
            }
        }

        foreach (array_slice($this->candidateScopedLines($lines), 0, 5) as $line) {
            if ($this->isHtmlNoiseLine($line) || $this->looksLikeBiodataTitle($line)) {
                continue;
            }

            $cleaned = $this->cleanCandidateName($line);
            if ($this->validCandidateName($cleaned)) {
                $candidates[] = ['name' => $cleaned, 'score' => 20];
            }
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);

        return $candidates[0]['name'];
    }

    public function cleanCandidateName(?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        $name = $this->stripHtmlAndOcrArtifacts($name);
        $name = $this->stopAtNextCandidateField($name);
        $name = preg_replace('/\([^)]*\)/u', '', $name) ?? $name;
        $name = $this->stripNameEdgeNoiseTokens($name);

        do {
            $before = $name;
            $name = preg_replace('/^(?:bio\s*data|candidate|full\s*name|name)\s*[:：\-–—.\s]+/iu', '', $name) ?? $name;
            $name = $this->stripNameEdgeNoiseTokens($name);
            $name = $this->stripCandidateNameLabelPrefix($name);
            $name = $this->stripNameHonorificPrefix($name);
            $name = $this->trimEdgePunctuation($name);
        } while ($name !== $before);

        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        return $name === '' ? null : $name;
    }

    private function stripHtmlAndOcrArtifacts(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\b(?:td|tr|table|div|span|br|html|body)\b/iu', ' ', $value) ?? $value;
        $value = preg_replace('/(?:à¤[«»]?){1,}[^\s]*/u', '', $value) ?? $value;
        $value = preg_replace('/[<>|]{1,}/u', ' ', $value) ?? $value;
        $value = preg_replace('/&[a-z]+;/iu', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function candidateScopedLines(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            if ($this->isFamilySectionBoundary($line)) {
                break;
            }
            $out[] = $line;
        }

        return $out;
    }

    private function valueAfterNameLabel(string $line): ?string
    {
        if (preg_match('/(?:मुलाचे\s+नां?व|मुलीचे\s+नां?व|वधूचे\s+नां?व|वराचे\s+नां?व|नां?व)\s*(?::\s*-\s*|[:\-：]\s*|[८8]\s*|\s+)\s*(.+)$/ui', $line, $matches) !== 1) {
            return null;
        }

        return $this->stopAtNextCandidateField(trim((string) $matches[1]));
    }

    private function stopAtNextCandidateField(string $value): string
    {
        $stops = 'जन्म\s*तारीख|जन्मतारीख|जन्म\s*दिनांक|जन्मदि|जन्म\s*ठिकाण|जन्म\s*स्थळ|उंची|ऊंची|लिंग|शिक्षण|शैक्षणिक|नोकरी|व्यवसाय|पद|मोबाईल|मोबाइल|मोबा\.?|मो\.?\s*नं\.?|संपर्क|वडील|वडिलांचे\s+नाव|आई|आईचे\s+नाव|मामा|आत्या|भाऊ|बहिण|बहीण|पत्ता|धर्म|जात|रास|राशी|नक्षत्र';
        $value = preg_split('/\s+(?:'.$stops.')(?:\s*[:：\-–—.]|\s+)/ui', $value, 2)[0] ?? $value;

        return trim($value);
    }

    private function stripCandidateNameLabelPrefix(string $value): string
    {
        foreach ([
            'मुलाचे नांव',
            'मुलाचे नाव',
            'मुलीचे नांव',
            'मुलीचे नाव',
            'वधूचे नांव',
            'वधूचे नाव',
            'वराचे नांव',
            'वराचे नाव',
            'बायोडाटा',
            'नांव',
            'नाव',
        ] as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return $this->trimEdgePunctuation(mb_substr($value, mb_strlen($prefix, 'UTF-8'), null, 'UTF-8'));
            }
        }

        return $value;
    }

    private function stripNameHonorificPrefix(string $value): string
    {
        $value = preg_replace('/^(?:\*[\s]*)?(?:कु\.|कुं\.|कुमारी\s+|चि\.|चिरंजीव\s+|श्री\.|श्रीमती\s+|सौ\.)/u', '', $value) ?? $value;

        foreach ([
            'चिरंजीव',
            'श्रीमती.',
            'श्रीमती',
            'कुमारी',
            'कुमार',
            'चि.',
            'चि',
            'कुं.',
            'कुं',
            'कु.',
            'कु',
            'श्री.',
            'श्री',
            'सौ.',
            'सौ',
        ] as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return $this->trimEdgePunctuation(mb_substr($value, mb_strlen($prefix, 'UTF-8'), null, 'UTF-8'));
            }
        }

        return $value;
    }

    private function stripNameEdgeNoiseTokens(string $value): string
    {
        $value = OcrNormalize::normalizeDigits($value);
        $value = preg_replace('/^\s*(?:\d+\s*)+/u', '', $value) ?? $value;

        if (preg_match('/[\x{0900}-\x{097F}]/u', $value) !== 1) {
            return trim($value);
        }

        $value = preg_replace('/^(?:[a-z]{1,5}\s+){1,4}(?=[\x{0900}-\x{097F}])/iu', '', $value) ?? $value;
        $value = preg_replace('/\s+(?:[a-z]{1,5}\s*){1,5}$/iu', '', $value) ?? $value;

        return trim($value);
    }

    private function trimEdgePunctuation(string $value): string
    {
        $value = trim($value, " \t\n\r\0\x0B:-|.,;");
        $value = preg_replace('/^[\s–—।]+|[\s–—।]+$/u', '', $value) ?? $value;

        return trim($value, " \t\n\r\0\x0B:-|.,;");
    }

    private function validCandidateName(?string $name): bool
    {
        if ($name === null || $name === '' || mb_strlen($name, 'UTF-8') > 80) {
            return false;
        }

        if ($this->hasRelationContext($name) || $this->hasAnyFieldLabel($name)) {
            return false;
        }

        if (preg_match('/(?:\+?91[\s\-]*)?[6-9]\d{9}/u', preg_replace('/\s+/', '', $name) ?? '') === 1) {
            return false;
        }

        if (preg_match('/\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{2,4}/u', $name) === 1) {
            return false;
        }

        if ($this->looksLikeAddress($name) || $this->looksLikeBiodataTitle($name)) {
            return false;
        }

        return preg_match('/\p{L}/u', $name) === 1;
    }

    private function validRescuedName(?string $name): bool
    {
        if ($name === null || $name === '' || mb_strlen($name, 'UTF-8') > 80 || $this->hasRelationContext($name) || $this->hasAnyFieldLabel($name)) {
            return false;
        }

        if (preg_match('/(?:\+?91[\s\-]*)?[6-9]\d{9}/u', preg_replace('/\s+/', '', $name) ?? '') === 1) {
            return false;
        }

        if (preg_match('/\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{2,4}/u', $name) === 1) {
            return false;
        }

        if ($this->looksLikeAddress($name)) {
            return false;
        }

        return preg_match('/^[A-Za-z\s.]+$/', $name) !== 1;
    }

    private function isHtmlNoiseLine(string $line): bool
    {
        $stripped = $this->stripHtmlAndOcrArtifacts($line);
        if ($stripped === '') {
            return true;
        }

        if (preg_match('/<[^>]+>|&(?:nbsp|amp|lt|gt);|à¤/u', $line) !== 1) {
            return false;
        }

        $compact = preg_replace('/[\s:：\-–—.|,;0-9]+/u', '', $stripped) ?? '';

        return mb_strlen($compact, 'UTF-8') < 3;
    }

    private function looksLikeBiodataTitle(string $value): bool
    {
        return preg_match('/^(?:बायो\s*डाटा|bio\s*data|marriage\s*biodata|वैवाहिक\s*बायो\s*डाटा)\b/iu', trim($value)) === 1;
    }

    private function hasCandidateHonorific(string $line): bool
    {
        return preg_match('/(?:^|[\s:：\-–—(])(?:चि\.|चि\s+|चिरंजीव\s*|कु\.|कुं\.|कुमारी\s+|श्री\.|श्रीमती\s+|सौ\.)\s*[\p{L}\p{M}]/u', $line) === 1
            || preg_match('/(?:^|[\s*])(?:\*[\s]*)?(?:कु\.|चि\.|श्री\.|श्रीमती\.|सौ\.)/u', $line) === 1;
    }

    private function hasCandidateNameLabel(string $line): bool
    {
        if (preg_match('/(?:मुलाचे\s+नां?व|मुलीचे\s+नां?व|वधूचे\s+नां?व|वराचे\s+नां?व)/u', $line) === 1) {
            return true;
        }

        return preg_match('/(?:^|\s)नां?व(?:[\s:：\-–—.]|$)/u', $line) === 1
            && ! $this->hasRelationContext($line);
    }

    private function hasRelationContext(string $value): bool
    {
        return preg_match('/(?:वडील|वडिलांचे|पित्याचे|आई|मातेचे|मामा|मावशी|माऊशी|आत्या|चुलते|काका|आजोळ|भाऊ|बहिण|बहीण|दाजी|जावई)(?:[\s:：\-–—.]|$)/u', $value) === 1
            || preg_match('/\b(?:father|mother|brother|sister|uncle|aunt)\b/ui', $value) === 1;
    }

    private function hasAnyFieldLabel(string $value): bool
    {
        return preg_match('/(?:^|\s)(?:मुलाचे\s+नां?व|मुलीचे\s+नां?व|वधूचे\s+नां?व|वराचे\s+नां?व|जन्म\s*तारीख|जन्मतारीख|जन्म\s*दिनांक|जन्मदि|जन्म\s*ठिकाण|जन्म\s*स्थळ|उंची|ऊंची|लिंग|शिक्षण|शैक्षणिक|नोकरी|व्यवसाय|पद|मोबाईल|मोबाइल|संपर्क|पत्ता|धर्म|जात)(?:[\s:：\-–—.]|$)/ui', $value) === 1;
    }

    private function looksLikeAddress(string $value): bool
    {
        return preg_match('/(?:मु\.?\s*पो\.?|रा\.|ता\.|जि\.|पत्ता|पोस्ट|कॉलनी|रोड|नगर|वाडी|गाव|फ्लॅट|वॉर्ड)/u', $value) === 1;
    }

    private function isFamilySectionBoundary(string $line): bool
    {
        return preg_match('/^\s*(?:कौटुंबिक\s+माहिती|कौटुंबिक\s+तपशील|वडील|वडिलांचे|पित्याचे|आई|आईचे|मातेचे|भाऊ|बहिण|बहीण|मुलाचे\s+भाऊ|मुलाची\s+बहीण|मुलाची\s+बहिण|मामा|मावशी|माऊशी|आत्या|चुलते|काका|आजोळ|नातेवाईक|इतर\s+नातेवाईक|पाहुणे)(?:[\s:：\-–—.]|$)/u', $line) === 1;
    }

    private function isFooterNoise(string $line): bool
    {
        return preg_match('/print|printing|shop|प्रिंट|छपाई/ui', $line) === 1;
    }
}
