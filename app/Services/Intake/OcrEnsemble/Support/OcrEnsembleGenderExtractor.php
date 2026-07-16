<?php

namespace App\Services\Intake\OcrEnsemble\Support;

/**
 * Production Phase 3 gender cues from biodata OCR (section headers, Ms./कु., name labels).
 * Does not invent gender from prayers or relative honorifics.
 */
class OcrEnsembleGenderExtractor
{
    /**
     * @param  list<string>  $lines
     */
    public function extract(array $lines, ?string $fallback = null, ?string $extractedName = null): ?string
    {
        $explicit = $this->fromExplicitLabel($lines);
        if ($explicit !== null) {
            return $explicit;
        }

        $section = $this->fromSectionHeader($lines);
        if ($section !== null) {
            return $section;
        }

        $nameLabel = $this->fromCandidateNameLabel($lines);
        if ($nameLabel !== null) {
            return $nameLabel;
        }

        $english = $this->fromEnglishCandidateHonorific($lines);
        if ($english !== null) {
            return $english;
        }

        $kumari = $this->fromCandidateKuHonorific($lines);
        if ($kumari !== null) {
            return $kumari;
        }

        // Note: short `कु.` is NOT used — OCR frequently misreads `चि.` as `कु.` on male biodata.

        $kanya = $this->fromKanyaDescriptor($lines);
        if ($kanya !== null) {
            return $kanya;
        }

        $fallback = is_string($fallback) ? strtolower(trim($fallback)) : null;
        if (in_array($fallback, ['male', 'female'], true)) {
            return $fallback;
        }

        $nameHonorific = $this->fromExtractedCandidateName($extractedName);
        if ($nameHonorific !== null) {
            return $nameHonorific;
        }

        $nameOnLine = $this->fromExtractedNameWithKuOnSourceLine($lines, $extractedName);
        if ($nameOnLine !== null) {
            return $nameOnLine;
        }

        return null;
    }

    /**
     * @param  list<string>  $lines
     */
    private function fromExplicitLabel(array $lines): ?string
    {
        foreach ($lines as $line) {
            if (preg_match('/(?:लिंग|Gender)\s*[:：\-–—.]+\s*(.+)$/ui', $line, $m) !== 1) {
                continue;
            }
            $v = trim((string) $m[1]);
            if (preg_match('/स्त्री|महिला|female|\bf\b/ui', $v) === 1) {
                return 'female';
            }
            if (preg_match('/पुरुष|male|\bm\b/ui', $v) === 1) {
                return 'male';
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $lines
     */
    private function fromSectionHeader(array $lines): ?string
    {
        $female = false;
        $male = false;
        foreach ($lines as $line) {
            if (preg_match('/मुलीची\s+माहिती|वधूची\s+माहिती|महिलेची\s+माहिती/u', $line) === 1) {
                $female = true;
            }
            if (preg_match('/मुलाची\s+माहिती|वराची\s+माहिती|पुरुषाची\s+माहिती/u', $line) === 1) {
                $male = true;
            }
        }
        if ($female && ! $male) {
            return 'female';
        }
        if ($male && ! $female) {
            return 'male';
        }

        return null;
    }

    /**
     * @param  list<string>  $lines
     */
    private function fromCandidateNameLabel(array $lines): ?string
    {
        $female = false;
        $male = false;
        foreach ($lines as $line) {
            if (preg_match('/(?:मुलीचे\s+नां?व|वधूचे\s+नां?व)/u', $line) === 1) {
                $female = true;
            }
            if (preg_match('/(?:मुलाचे\s+नां?व|वराचे\s+नां?व)/u', $line) === 1) {
                $male = true;
            }
        }
        if ($female && ! $male) {
            return 'female';
        }
        if ($male && ! $female) {
            return 'male';
        }

        return null;
    }

    /**
     * English resumes: Gender from Ms./Miss on the Name line; ignore Mr. on Father’s Name.
     *
     * @param  list<string>  $lines
     */
    private function fromEnglishCandidateHonorific(array $lines): ?string
    {
        foreach ($lines as $line) {
            if (preg_match('/Father|Mother|Parent/ui', $line) === 1) {
                continue;
            }
            // OCR often reads Ms. as Devanagari "मिस." on noisy photo biodata.
            if (preg_match('/मिस\.?/u', $line) === 1) {
                return 'female';
            }
            if (preg_match('/(?:^|\bName\b|\bCandidate\b).{0,40}\b(?:Ms\.?|Miss|Mrs\.?)\b/ui', $line) === 1
                || preg_match('/^\s*(?:Ms\.?|Miss|Mrs\.?)\s+[\p{L}]/ui', $line) === 1) {
                return 'female';
            }
            if (preg_match('/(?:^|\bName\b|\bCandidate\b).{0,40}\b(?:Mr\.?)\b/ui', $line) === 1
                || preg_match('/^\s*(?:Mr\.?)\s+[\p{L}]/ui', $line) === 1) {
                return 'male';
            }
        }

        // Standalone "Name: - Ms. Sonam" often OCR'd across glue.
        $blob = implode(' ', $lines);
        if (preg_match('/\bName\b.{0,30}\b(?:Ms\.?|Miss|Mrs\.?)\b/ui', $blob) === 1
            && preg_match('/\bName\b.{0,30}\bMr\.?\b/ui', $blob) !== 1) {
            return 'female';
        }

        return null;
    }

    /**
     * Full `कुमारी` only — short `कु.` confused with `चि.` on male names.
     *
     * @param  list<string>  $lines
     */
    private function fromCandidateKuHonorific(array $lines): ?string
    {
        foreach ($lines as $line) {
            if (preg_match('/(?:वडील|आई|Father|Mother|मामा|काका)/u', $line) === 1) {
                continue;
            }
            if (preg_match('/(?:नाव|नांव|Name)\s*[:：\-–—._]*\s*कुमारी/ui', $line) === 1
                || preg_match('/(?:^|[\s:：\-–—(])कुमारी\s*[\p{L}\p{M}]/u', $line) === 1) {
                return 'female';
            }
        }

        return null;
    }

    /**
     * Marathi biodata often labels complexion/status as `कन्या वर्ण` (bride/girl).
     *
     * @param  list<string>  $lines
     */
    private function fromKanyaDescriptor(array $lines): ?string
    {
        foreach ($lines as $line) {
            if (preg_match('/(?:वडील|आई|Father|Mother|मामा|काका|कन्यादान)/u', $line) === 1) {
                continue;
            }
            if (preg_match('/कन्या\s*वर्ण|(?:^|[\s:：\-–—._])कन्या(?:[\s:：\-–—._]|$)/u', $line) === 1) {
                return 'female';
            }
        }

        return null;
    }

    private function fromExtractedCandidateName(?string $name): ?string
    {
        if (! is_string($name) || trim($name) === '') {
            return null;
        }

        return preg_match('/^\s*कु\.\s*[\p{L}\p{M}]/u', $name) === 1 ? 'female' : null;
    }

    /**
     * Name cleaner strips `कु.`; recover female cue when source line still has `कु.` before the extracted given name.
     *
     * @param  list<string>  $lines
     */
    private function fromExtractedNameWithKuOnSourceLine(array $lines, ?string $extractedName): ?string
    {
        if (! is_string($extractedName) || trim($extractedName) === '') {
            return null;
        }

        $tokens = preg_split('/\s+/u', trim($extractedName)) ?: [];
        $first = $tokens[0] ?? '';
        if ($first === '' || mb_strlen($first, 'UTF-8') < 2) {
            return null;
        }

        $quoted = preg_quote($first, '/');
        foreach ($lines as $line) {
            if (preg_match('/(?:वडील|आई|Father|Mother|मामा|काका)/u', $line) === 1) {
                continue;
            }
            if (preg_match('/कु\.\s*'.$quoted.'/u', $line) === 1) {
                return 'female';
            }
        }

        return null;
    }
}
