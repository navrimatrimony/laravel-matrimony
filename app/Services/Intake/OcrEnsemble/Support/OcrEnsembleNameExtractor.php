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

            $glued = $this->valueAfterGluedNavLabel($line);
            if ($glued !== null) {
                $cleaned = $this->cleanCandidateName($glued);
                if ($this->validCandidateName($cleaned)) {
                    $candidates[] = ['name' => $cleaned, 'score' => 95];
                }
            }

            $fromTitle = $this->valueAfterBiodataTitle($line);
            if ($fromTitle !== null) {
                $cleaned = $this->cleanCandidateName($fromTitle);
                if ($this->validCandidateName($cleaned)) {
                    $candidates[] = ['name' => $cleaned, 'score' => 90];
                }
            } elseif (preg_match('/^(?:बायो\s*डाटा|बायोडाटा|bio\s*data)\s*$/iu', trim($line)) === 1) {
                // Title alone on its line; candidate name often on the next line.
                $next = trim((string) ($lines[$index + 1] ?? ''));
                $cleaned = $this->cleanCandidateName($next);
                if ($this->validCandidateName($cleaned)) {
                    $candidates[] = ['name' => $cleaned, 'score' => 92];
                }
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

        $best = $candidates[0];
        if ((int) $best['score'] >= 90 && $this->devanagariNameWordCount((string) $best['name']) === 2) {
            $surname = $this->surnameFromFatherLine($lines);
            if ($surname !== null && mb_stripos((string) $best['name'], $surname) === false) {
                $extended = $this->cleanCandidateName($best['name'].' '.$surname);
                if ($this->validCandidateName($extended) && $this->devanagariNameWordCount((string) $extended) === 3) {
                    return $extended;
                }
            }
        }

        return $best['name'];
    }

    public function cleanCandidateName(?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        $name = $this->stripHtmlAndOcrArtifacts($name);
        $name = $this->stopAtNextCandidateField($name);
        // Drop phone/occupation parentheses; keep short Devanagari surname aliases like (कदम).
        $name = preg_replace_callback('/\(([^)]*)\)/u', static function (array $m): string {
            $inner = trim((string) ($m[1] ?? ''));
            if (preg_match('/^[\x{0900}-\x{097F}]{2,12}$/u', $inner) === 1) {
                return '('.$inner.')';
            }

            return '';
        }, $name) ?? $name;
        // OCR garble before honorific: "र : कु. प्रतीक्षा ..."
        $name = preg_replace('/^(?:[\p{L}\p{M}]{1,3}\s*[:：]\s*)+/u', '', $name) ?? $name;
        $name = $this->stripNameEdgeNoiseTokens($name);

        do {
            $before = $name;
            $name = preg_replace('/^(?:bio\s*data|candidate|full\s*name|name|resume)\s*[:：\-–—.\s]+/iu', '', $name) ?? $name;
            $name = $this->stripNameEdgeNoiseTokens($name);
            $name = $this->stripCandidateNameLabelPrefix($name);
            $name = $this->stripNameHonorificPrefix($name);
            $name = $this->trimEdgePunctuation($name);
        } while ($name !== $before);

        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        $name = $this->trimToLikelyPersonName($name);

        return $name === '' ? null : $name;
    }

    /**
     * Keep leading person-name tokens; drop trailing OCR junk ("फार 9 झज", "त्स दुस").
     */
    private function trimToLikelyPersonName(string $name): string
    {
        $tokens = preg_split('/\s+/u', trim($name)) ?: [];
        if ($tokens === []) {
            return '';
        }

        $kept = [];
        foreach ($tokens as $tok) {
            $tok = trim($tok, " \t.,;:|");
            if ($tok === '') {
                continue;
            }

            $isDevName = preg_match('/^[\x{0900}-\x{097F}.]+$/u', $tok) === 1;
            $isDevAlias = preg_match('/^\([\x{0900}-\x{097F}]{2,12}\)$/u', $tok) === 1;
            $isLatinName = preg_match('/^[A-Za-z.]{2,}$/', $tok) === 1;
            $hasDigit = preg_match('/\d/u', $tok) === 1;

            if ($hasDigit) {
                break;
            }

            if ($isDevAlias) {
                if (count($kept) >= 2) {
                    $kept[] = $tok;
                }
                break;
            }

            if (! $isDevName && ! $isLatinName) {
                if (count($kept) >= 2) {
                    break;
                }

                continue;
            }

            // Typical Marathi biodata is 2–3 name tokens; further Devanagari is often OCR tail junk.
            if (count($kept) >= 3 && $isDevName) {
                break;
            }

            // After 3 solid tokens, stop on short trailing OCR syllables.
            if (count($kept) >= 3 && mb_strlen($tok, 'UTF-8') <= 2) {
                break;
            }

            // Mixed script junk after a Devanagari full name
            if (count($kept) >= 3 && $isLatinName && preg_match('/[\x{0900}-\x{097F}]/u', implode(' ', $kept)) === 1) {
                break;
            }

            $kept[] = $tok;
            if (count($kept) >= 4) {
                break;
            }
        }

        return trim(implode(' ', $kept));
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
        if (preg_match('/(?:मुलाचे\s+नां?व|मुलीचे\s+नां?व|वधूचे\s+नां?व|वराचे\s+नां?व|नां?व|नाब|मुलाचे\s+बां|मुलीचे\s+बां)\s*(?::\s*-\s*|[:\-：]\s*|[८8]\s*|\s+)\s*(.+)$/ui', $line, $matches) === 1) {
            return $this->stopAtNextCandidateField(trim((string) $matches[1]));
        }

        // Mask relation English name labels (ASCII or curly apostrophe), then take Name: value.
        $masked = preg_replace('/\b(?:Father|Mother|Birth)\S{0,2}\s*Name\b/iu', 'REL_NAME', $line) ?? $line;
        if (preg_match('/(?:^|(?<=\s))(?:full\s+)?name\s*(?::\s*-\s*|[:\-]\s+)\s*(.+)$/iu', $masked, $matches) === 1) {
            return $this->stopAtNextCandidateField(trim((string) $matches[1]));
        }

        return null;
    }

    private function stopAtNextCandidateField(string $value): string
    {
        $stops = 'जन्म\s*तारीख|जन्मतारीख|जन्म\s*दिनांक|जन्मदि|जन्म\s*ठिकाण|जन्म\s*स्थळ|उंची|ऊंची|लिंग|शिक्षण|शैक्षणिक|नोकरी|व्यवसाय|पद|मोबाईल|मोबाइल|मोबा\.?|मो\.?\s*नं\.?|संपर्क|वडील|वडिलांचे\s+नाव|आई|आईचे\s+नाव|मामा|आत्या|भाऊ|बहिण|बहीण|पत्ता|धर्म|जात|रास|राशी|नक्षत्र'
            .'|Father\S{0,2}\s*Name|Mother\S{0,2}\s*Name|Date\s*of\s*Birth|Birth\s*Time|Place\s*of\s*Birth|Birth\s*Name|REL_NAME'
            .'|जन्म\b';
        $value = preg_split('/\s+(?:'.$stops.')(?:\s*[:：\-–—.]|\s+)/ui', $value, 2)[0] ?? $value;

        return trim($value);
    }

    private function stripCandidateNameLabelPrefix(string $value): string
    {
        foreach ([
            'मुलाचे नांव',
            'मुलाचे नाव',
            'मुलाचे बां',
            'मुलीचे नांव',
            'मुलीचे नाव',
            'मुलीचे बां',
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
        // OCR sometimes doubles श्री as शश्रीनाथ; peel one श before glued श्री.
        $value = preg_replace('/^श(?=श्री)/u', '', $value) ?? $value;

        // Require separator after short honorifics so चिवाजी / कुमार are not truncated.
        $value = preg_replace(
            '/^(?:\*[\s]*)?(?:Ms\.|Mr\.|Mrs\.|Miss\s+|कु\.|कुं\.|कुमारी\s+|चि\.|चच\.|चिरंजीव\s+|श्री\.|श्रीमती\s+|सौ\.)\s*/iu',
            '',
            $value
        ) ?? $value;

        foreach ([
            'चिरंजीव',
            'श्रीमती.',
            'श्रीमती',
            'कुमारी',
            'चि.',
            'चच.',
            'कुं.',
            'कु.',
            'श्री.',
            'श्री',
            'सौ.',
            'Ms.',
            'Mr.',
            'Mrs.',
            'Miss',
        ] as $prefix) {
            $lower = mb_strtolower($value, 'UTF-8');
            $p = mb_strtolower($prefix, 'UTF-8');
            if (! str_starts_with($lower, $p)) {
                continue;
            }
            $rest = mb_substr($value, mb_strlen($prefix, 'UTF-8'), null, 'UTF-8');
            // Bare `श्री` may be glued (श्रीनाथ). Never strip bare चि/कु — those begin real names (चिवाजी/कुमार).
            if (in_array($prefix, ['श्रीमती', 'कुमारी', 'चिरंजीव', 'Miss', 'श्री'], true)
                || str_ends_with($prefix, '.')
                || preg_match('/^[\s:：\-–—(]/u', $rest) === 1) {
                return $this->trimEdgePunctuation($rest);
            }
        }

        return $value;
    }

    /**
     * Megapage OCR often glues label+name: नावनवनाथ / नाबप्रतीक्षा
     */
    private function valueAfterGluedNavLabel(string $line): ?string
    {
        if (preg_match('/(?:^|[^\p{L}\p{M}])(?:नां?व|नाब)(?=[\x{0900}-\x{097F}])/u', $line, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        // Byte offset from PREG_OFFSET_CAPTURE is not UTF-8 safe for mb_substr — re-match with capture.
        if (preg_match('/(?:^|[^\p{L}\p{M}])(?:नां?व|नाब)([\x{0900}-\x{097F}][\x{0900}-\x{097F}\s.]*?)(?=(?:णे\s*)?(?:तारीख|जन्म|उंची|ऊंची|मो\.|मोबाईल|धर्म|जात|$))/u', $line, $m) !== 1) {
            return null;
        }

        $value = trim((string) $m[1]);

        return $value !== '' ? $this->stopAtNextCandidateField($value) : null;
    }

    private function valueAfterBiodataTitle(string $line): ?string
    {
        if (preg_match('/^(?:बायो\s*डाटा|बायोडाटा|bio\s*data|marriage\s*biodata|वैवाहिक\s*बायो\s*डाटा)\s+(.+)$/iu', trim($line), $m) !== 1) {
            return null;
        }

        return $this->stopAtNextCandidateField(trim((string) $m[1]));
    }

    private function stripNameEdgeNoiseTokens(string $value): string
    {
        $value = OcrNormalize::normalizeDigits($value);
        $value = preg_replace('/^\s*(?:\d+\s*)+/u', '', $value) ?? $value;

        if (preg_match('/[\x{0900}-\x{097F}]/u', $value) !== 1) {
            return trim($value);
        }

        // OCR often inserts & / अँड. / short Latin junk before the real given name.
        $value = preg_replace('/^(?:&\s*|अँड\.?\s*|and\.?\s*)+/iu', '', $value) ?? $value;
        $value = preg_replace('/^(?:[a-z]{1,5}\s+){1,4}(?=[\x{0900}-\x{097F}])/iu', '', $value) ?? $value;
        $value = preg_replace('/\s+(?:[a-z]{1,5}\s*){1,5}$/iu', '', $value) ?? $value;
        // Drop isolated & / अँड tokens between Devanagari name parts.
        $value = preg_replace('/\s+(?:&|अँड\.?|and\.?)\s+/iu', ' ', $value) ?? $value;

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

        if ($this->looksLikeAddress($name) || $this->looksLikeBiodataTitle($name) || $this->looksLikeInvocation($name)) {
            return false;
        }

        // Reject tiny OCR fragments ("न्स", "डे कू") that beat real names via weak scores.
        $tokens = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens === []) {
            return false;
        }
        if (count($tokens) === 1 && mb_strlen($tokens[0], 'UTF-8') <= 3) {
            return false;
        }
        $compactLen = mb_strlen(implode('', $tokens), 'UTF-8');
        if (count($tokens) <= 2 && $compactLen <= 4) {
            return false;
        }

        return preg_match('/\p{L}/u', $name) === 1;
    }

    private function looksLikeInvocation(string $value): bool
    {
        return preg_match('/गणेशाय\s*नम|श्री\s*गणेश|जय\s*श्री|शुभमं|प्रसन्न/u', $value) === 1
            && preg_match('/(?:कु\.|चि\.|श्री\.|सौ\.|Ms\.|Mr\.)/u', $value) !== 1;
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
        $trimmed = trim($value);
        if (preg_match('/^(?:बायो\s*डाटा|बायोडाटा|bio\s*data|marriage\s*biodata|वैवाहिक\s*बायो\s*डाटा|resume)\b/iu', $trimmed) !== 1) {
            return false;
        }

        // "बायोडाटा रेखा शिवदास पाटील" is a title + name — strip title and keep rest for cleaner.
        $rest = trim(preg_replace('/^(?:बायो\s*डाटा|बायोडाटा|bio\s*data|marriage\s*biodata|वैवाहिक\s*बायो\s*डाटा|resume)\s*/iu', '', $trimmed) ?? '');

        return $rest === '';
    }

    private function hasCandidateHonorific(string $line): bool
    {
        return preg_match('/(?:^|[\s:：\-–—(])(?:चि\.|चच\.|चि\s+|चिरंजीव\s*|कु\.|कुं\.|कुमारी\s+|श्री\.|श्रीमती\s+|सौ\.|Ms\.|Mr\.|Mrs\.)\s*[\p{L}\p{M}]/iu', $line) === 1
            || preg_match('/(?:^|[\s*])(?:\*[\s]*)?(?:कु\.|चि\.|चच\.|श्री\.|श्रीमती\.|सौ\.)/u', $line) === 1;
    }

    private function hasCandidateNameLabel(string $line): bool
    {
        if (preg_match('/(?:मुलाचे\s+नां?व|मुलीचे\s+नां?व|वधूचे\s+नां?व|वराचे\s+नां?व|मुलाचे\s+बां|मुलीचे\s+बां)/u', $line) === 1) {
            return true;
        }

        if (preg_match('/(?:^|\s)(?:नां?व|नाब)(?:[\s:：\-–—._]|$)/u', $line) === 1 && ! $this->hasRelationContext($line)) {
            return true;
        }

        $masked = preg_replace('/\b(?:Father|Mother|Birth)\S{0,2}\s*Name\b/iu', 'REL_NAME', $line) ?? $line;

        return preg_match('/(?:^|(?<=\s))(?:full\s+)?name\s*(?::\s*-\s*|[:\-]\s+)/iu', $masked) === 1;
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

    private function devanagariNameWordCount(string $name): int
    {
        $tokens = preg_split('/\s+/u', trim($name)) ?: [];

        return count(array_filter($tokens, static fn (string $tok): bool => preg_match('/^[\x{0900}-\x{097F}.]{2,}$/u', $tok) === 1));
    }

    /**
     * @param  list<string>  $lines
     */
    private function surnameFromFatherLine(array $lines): ?string
    {
        $patterns = [
            '/(?:चंडिलांचे|वडिलांचे|वडीलांचे|पित्याचे)\s*नाव\s*[:\-–—.\s]+(.+)$/u',
            '/Father\S{0,2}\s*Name\s*[:\-]\s*(.+)$/iu',
        ];

        foreach ($lines as $line) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $line, $matches) !== 1) {
                    continue;
                }

                $value = trim((string) ($matches[1] ?? ''));
                $value = preg_split('/\s*\(/u', $value, 2)[0] ?? $value;
                $value = $this->stopAtNextCandidateField($value);
                // Do not run full person-name trim (caps at ~3 tokens and can drop the true surname).
                $value = $this->stripNameHonorificPrefix($value);
                $value = preg_replace('/^(?:धि|श्री\.?|कै\.?)\s*/u', '', $value) ?? $value;
                $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
                if ($value === '') {
                    continue;
                }

                $tokens = preg_split('/\s+/u', $value) ?: [];
                $devTokens = array_values(array_filter(
                    $tokens,
                    static fn (string $tok): bool => preg_match('/^[\x{0900}-\x{097F}.]{2,}$/u', $tok) === 1
                ));
                if ($devTokens === []) {
                    continue;
                }

                $surname = (string) end($devTokens);

                return mb_strlen($surname, 'UTF-8') >= 2 ? $surname : null;
            }
        }

        return null;
    }
}
