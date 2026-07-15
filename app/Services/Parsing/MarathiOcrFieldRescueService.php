<?php

namespace App\Services\Parsing;

use App\Services\Ocr\OcrNormalize;
use Illuminate\Support\Carbon;

final class MarathiOcrFieldRescueService
{
    /**
     * @param  list<string>  $lines
     * @param  array<string, mixed>  $core
     * @return array<string, mixed>
     */
    public function rescueCoreFields(array $lines, array $core): array
    {
        $lines = $this->normalizedLines($lines);

        $name = $this->rescueFullName($lines);
        if ($name !== null && $this->shouldReplaceName($core['full_name'] ?? null)) {
            $core['full_name'] = $name;
        }

        $gender = $this->rescueGender($lines);
        if ($gender !== null) {
            $core['gender'] = $gender['value'];
        } elseif ($gender === null
            && ! $this->empty($core['gender'] ?? null)
            && ($this->genderLooksDrivenByFamilyHonorific($lines) || $this->genderLooksDrivenByWeakKuHonorific($lines))) {
            $core['gender'] = null;
        }

        $dob = $this->rescueDateOfBirth($lines);
        if ($dob !== null) {
            $core['date_of_birth'] = $dob;
        } elseif ($this->hasDateOfBirthLabel($lines) && ! $this->validCandidateIsoDate($core['date_of_birth'] ?? null)) {
            $core['date_of_birth'] = null;
        }

        $heightCm = $this->rescueHeightCm($lines);
        if ($heightCm !== null && ! $this->validHeightCm($core['height_cm'] ?? null)) {
            $core['height_cm'] = $heightCm;
        }

        $phone = $this->rescueMobile($lines);
        if ($phone !== null && ! $this->validPhone($core['primary_contact_number'] ?? null)) {
            $core['primary_contact_number'] = $phone;
        }

        $birthPlace = $this->rescueShortLocation($lines);
        if ($birthPlace !== null && $this->empty($core['birth_place_text'] ?? null)) {
            $core['birth_place_text'] = $birthPlace;
        }

        $education = $this->rescueEducation($lines);
        if ($education !== null && $this->shouldReplaceShortText($core['highest_education'] ?? null)) {
            $core['highest_education'] = $education;
        }

        $occupation = $this->rescueOccupation($lines);
        if ($occupation !== null && $this->shouldReplaceOccupation($core['occupation_title'] ?? null)) {
            $core['occupation_title'] = $occupation;
        }

        return $core;
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function normalizedLines(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $line = OcrNormalize::normalizeDigits((string) $line);
            $line = trim(preg_replace('/\s+/u', ' ', $line) ?? $line);
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $lines
     */
    private function rescueFullName(array $lines): ?string
    {
        foreach ($lines as $index => $line) {
            if (! $this->hasCandidateNameLabel($line)) {
                continue;
            }

            $value = $this->valueAfterLabelPattern(
                $line,
                'मुलाचे\s+नां?व|मुलीचे\s+नां?व|वधूचे\s+नां?व|वराचे\s+नां?व|नां?व'
            );
            if ($value === null || $value === '') {
                $value = $lines[$index + 1] ?? null;
            }

            if (! is_string($value)) {
                continue;
            }

            $name = $this->cleanName($value);
            if ($this->validRescuedName($name)) {
                return $name;
            }
        }

        foreach ($this->candidateScopedLines($lines) as $line) {
            if ($this->hasRelationContext($line) || ! $this->hasCandidateHonorific($line)) {
                continue;
            }

            $name = $this->cleanName($line);
            if ($this->validRescuedName($name)) {
                return $name;
            }
        }

        return null;
    }

    private function hasCandidateNameLabel(string $line): bool
    {
        if (preg_match('/(?:मुलाचे\s+नां?व|मुलीचे\s+नां?व|वधूचे\s+नां?व|वराचे\s+नां?व)/u', $line) === 1) {
            return true;
        }

        return preg_match('/(?:^|\s)नां?व(?:[\s:：\-–—.]|$)/u', $line) === 1
            && ! $this->hasRelationContext($line);
    }

    /**
     * @param  list<string>  $lines
     * @return array{value: string, source: string}|null
     */
    private function rescueGender(array $lines): ?array
    {
        foreach ($lines as $line) {
            $value = $this->valueAfterLabelPattern($line, 'लिंग|gender');
            if ($value === null) {
                continue;
            }

            $gender = $this->genderFromText($value);
            if ($gender !== null) {
                return ['value' => $gender, 'source' => 'explicit'];
            }
        }

        $candidateLines = array_values(array_filter(
            $this->candidateScopedLines($lines),
            fn (string $line): bool => ! $this->hasRelationContext($line)
        ));

        foreach ([
            fn (string $line): ?string => $this->genderFromCandidateNameLabel($line),
            fn (string $line): ?string => $this->genderFromStrongCandidateWord($line),
            fn (string $line): ?string => $this->genderFromCandidateHonorific($line),
        ] as $resolver) {
            $gender = $this->singleGenderFromCandidateLines($candidateLines, $resolver);
            if ($gender !== null) {
                return ['value' => $gender, 'source' => 'candidate'];
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $lines
     */
    private function singleGenderFromCandidateLines(array $lines, callable $resolver): ?string
    {
        $found = [];
        foreach ($lines as $line) {
            if ($this->hasRelationContext($line)) {
                continue;
            }

            $gender = $resolver($line);
            if ($gender !== null) {
                $found[$gender] = true;
            }
        }

        return count($found) === 1 ? array_key_first($found) : null;
    }

    private function genderFromText(string $value): ?string
    {
        if (preg_match('/स्त्री|female|\bf\b/ui', $value) === 1) {
            return 'female';
        }
        if (preg_match('/पुरुष|male|\bm\b/ui', $value) === 1) {
            return 'male';
        }

        return null;
    }

    /**
     * @param  list<string>  $lines
     */
    private function rescueDateOfBirth(array $lines): ?string
    {
        foreach ($lines as $line) {
            if (! $this->lineHasDobLabel($line)) {
                continue;
            }

            $afterLabel = $this->valueAfterLabelPattern(
                $line,
                '(?:[जऄ]|ज्)?\.?\s*न्म\s*तार्?[ीईि]?ख|जन्मतारीख|जन्म\s*तारीख|जन्म\s*दिनांक|जन्मदि|DOB|date\s*of\s*birth'
            );
            foreach (array_filter([$afterLabel, $line], 'is_string') as $candidate) {
                $dob = $this->normalizeDobFromText($candidate);
                if ($dob !== null) {
                    return $dob;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $lines
     */
    private function hasDateOfBirthLabel(array $lines): bool
    {
        foreach ($lines as $line) {
            if ($this->lineHasDobLabel($line)) {
                return true;
            }
        }

        return false;
    }

    private function lineHasDobLabel(string $line): bool
    {
        // Shared fuzzy pattern with Phase 3 normalizer (OCR often corrupts leading ज).
        return preg_match(
            \App\Services\Intake\OcrEnsemble\Support\OcrEnsembleDobNormalizer::DOB_LABEL_PATTERN,
            $line
        ) === 1;
    }

    private function normalizeDobFromText(string $value): ?string
    {
        $value = OcrNormalize::normalizeDigits($value);
        if (preg_match('/(?<!\d)(\d{1,2})\s*[\/.\-]\s*(\d{1,2})\s*[\/.\-]\s*(\d{2,4})(?!\d)/u', $value, $m) !== 1) {
            $normalized = OcrNormalize::normalizeDate($value);

            return $this->validCandidateIsoDate($normalized) ? $normalized : null;
        }

        $day = (int) $m[1];
        $month = (int) $m[2];
        $yearRaw = (string) $m[3];
        $years = strlen($yearRaw) === 2
            ? [2000 + (int) $yearRaw, 1900 + (int) $yearRaw]
            : [(int) $yearRaw];

        foreach ($years as $year) {
            $iso = $this->isoDateIfCandidateAge($day, $month, $year);
            if ($iso !== null) {
                return $iso;
            }
        }

        return null;
    }

    private function isoDateIfCandidateAge(int $day, int $month, int $year): ?string
    {
        if (! checkdate($month, $day, $year)) {
            return null;
        }

        $date = Carbon::create($year, $month, $day, 0, 0, 0);
        if ($date->isFuture()) {
            return null;
        }

        $age = $date->age;
        if ($age < 18 || $age > 75) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function validCandidateIsoDate(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return false;
        }

        return $this->isoDateIfCandidateAge((int) $m[3], (int) $m[2], (int) $m[1]) === $value;
    }

    /**
     * @param  list<string>  $lines
     */
    private function rescueHeightCm(array $lines): ?float
    {
        foreach ($lines as $line) {
            if (preg_match('/उंची|ऊंची|height/ui', $line) !== 1) {
                continue;
            }

            $value = $this->valueAfterLabelPattern($line, 'उंची|ऊंची|height') ?? $line;
            $height = $this->parseHeightCm($value);
            if ($height !== null) {
                return $height;
            }
        }

        return null;
    }

    private function parseHeightCm(string $value): ?float
    {
        $value = OcrNormalize::normalizeDigits($value);
        $normalized = OcrNormalize::normalizeHeight($value);
        if (is_string($normalized) && $normalized !== '') {
            $value = $normalized;
        }

        $feet = null;
        $inches = null;
        if (preg_match('/([3-7])\s*(?:फूट|फुट|foot|feet|ft)\s*([0-9]{1,2})?/ui', $value, $m) === 1) {
            $feet = (int) $m[1];
            $inches = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;
        } elseif (preg_match('/([3-7])\s*[\'’′]\s*([0-9]{1,2})?/u', $value, $m) === 1) {
            $feet = (int) $m[1];
            $inches = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;
        } elseif (preg_match('/^\s*([3-7])\s*[.]\s*([0-9]{1,2})\s*$/u', $value, $m) === 1) {
            $feet = (int) $m[1];
            $inches = (int) $m[2];
        }

        if ($feet === null || $inches === null || $inches < 0 || $inches > 11) {
            return null;
        }

        $cm = round(($feet * 12 + $inches) * 2.54, 2);

        return $cm >= 120 && $cm <= 213 ? $cm : null;
    }

    /**
     * @param  list<string>  $lines
     */
    private function rescueMobile(array $lines): ?string
    {
        foreach ($lines as $index => $line) {
            if (! $this->lineHasMobileLabel($line) || $this->isFooterNoise($line)) {
                continue;
            }
            if ($this->hasRelationContext($line)) {
                continue;
            }
            if (($this->hasNearbyParentContext($lines, $index) && ! $this->lineHasDirectContactLabel($line))
                || $this->hasNonContactPhoneContext($line)) {
                continue;
            }

            foreach ($this->extractPhones($line) as $phone) {
                return $phone;
            }

            $nextLine = $lines[$index + 1] ?? null;
            if (is_string($nextLine)
                && ! $this->hasRelationContext($nextLine)
                && ! $this->hasNonContactPhoneContext($nextLine)) {
                foreach ($this->extractPhones($nextLine) as $phone) {
                    return $phone;
                }
            }
        }

        return null;
    }

    private function lineHasMobileLabel(string $line): bool
    {
        return preg_match('/मोबाईल|मोबाइल|मोबा\.?|मो\.?\s*नं\.?|मो\.|भ्रमणध्वनी|संपर्क|mobile|phone|contact/ui', $line) === 1;
    }

    /**
     * @return list<string>
     */
    private function extractPhones(string $line): array
    {
        $line = OcrNormalize::normalizeDigits($line);
        $phones = [];
        if (preg_match_all('/(?:\+?91[\s\-]*)?[6-9][0-9\s\-\/]{9,14}/u', $line, $matches)) {
            foreach ($matches[0] as $raw) {
                $phone = OcrNormalize::normalizePhone($raw);
                if ($this->validPhone($phone)) {
                    $phones[$phone] = $phone;
                }
            }
        }

        $phone = OcrNormalize::normalizePhone($line);
        if ($this->validPhone($phone)) {
            $phones[$phone] = $phone;
        }

        return array_values($phones);
    }

    /**
     * @param  list<string>  $lines
     */
    private function rescueShortLocation(array $lines): ?string
    {
        foreach ($lines as $line) {
            if (preg_match('/जन्म\s*ठिकाण|जन्म\s*स्थळ|जन्मठिकाण|जन्मस्थळ|राहणार/u', $line) !== 1) {
                continue;
            }

            $value = $this->valueAfterLabelPattern($line, 'जन्म\s*ठिकाण|जन्म\s*स्थळ|जन्मठिकाण|जन्मस्थळ|राहणार') ?? '';
            $value = $this->cleanShortText($value);
            if ($this->validShortLocation($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $lines
     */
    private function rescueEducation(array $lines): ?string
    {
        foreach ($lines as $line) {
            if (preg_match('/शिक्षण|शैक्षणिक|education/ui', $line) !== 1) {
                continue;
            }
            $value = $this->valueAfterLabelPattern($line, 'शिक्षण|शैक्षणिक\s*पात्रता|education') ?? '';
            $value = $this->cleanShortText($value);
            if ($this->validShortField($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $lines
     */
    private function rescueOccupation(array $lines): ?string
    {
        $labelPattern = $this->candidateOccupationLabelPattern();
        foreach ($this->candidateScopedLines($lines) as $line) {
            if (preg_match('/'.$labelPattern.'/ui', $line) !== 1) {
                continue;
            }

            $value = $this->valueAfterOccupationLabelPattern($line) ?? '';
            $value = $this->cleanOccupationText($value);
            if ($this->validOccupationField($value, $line)) {
                return $value;
            }
        }

        return null;
    }

    private function candidateOccupationLabelPattern(): string
    {
        return 'नोकरी|नौकरी|व्यवसाय|पद|कंपनी|occupation|profession|job|designation|company';
    }

    private function valueAfterOccupationLabelPattern(string $line): ?string
    {
        $labelPattern = $this->candidateOccupationLabelPattern();
        if (preg_match('/(?:^|\s)(?:'.$labelPattern.')\s*(?:(?:[:：\-–—.>\/]|[८8])\s*|\s+)+(.+)$/ui', $line, $m) !== 1) {
            return null;
        }

        return $this->stopAtNextCandidateField(trim($m[1]));
    }

    private function valueAfterLabelPattern(string $line, string $labelPattern): ?string
    {
        if (preg_match('/(?:^|\s)(?:'.$labelPattern.')\s*(?:[:：\-–—.]|\s)+(.+)$/ui', $line, $m) !== 1) {
            return null;
        }

        return $this->stopAtNextCandidateField(trim($m[1]));
    }

    private function stopAtNextCandidateField(string $value): string
    {
        $stops = 'जन्म\s*तारीख|जन्मतारीख|जन्म\s*दिनांक|जन्मदि|जन्म\s*ठिकाण|जन्म\s*स्थळ|उंची|ऊंची|लिंग|शिक्षण|शैक्षणिक|नोकरी|व्यवसाय|पद|मोबाईल|मोबाइल|मोबा\.?|मो\.?\s*नं\.?|संपर्क|वडील|वडिलांचे\s+नाव|आई|आईचे\s+नाव|मामा|आत्या|भाऊ|बहिण|बहीण|पत्ता|धर्म|जात|रास|राशी|नक्षत्र';
        $value = preg_split('/\s+(?:'.$stops.')(?:\s*[:：\-–—.]|\s+)/ui', $value, 2)[0] ?? $value;

        return trim($value);
    }

    private function cleanName(string $value): string
    {
        $value = $this->stopAtNextCandidateField($value);
        $value = preg_replace('/\([^)]*\)/u', '', $value) ?? $value;
        $value = $this->stripNameEdgeNoiseTokens($value);
        $value = $this->trimEdgePunctuation($value);
        do {
            $before = $value;
            $value = preg_replace('/^(?:bio\s*data|candidate|full\s*name|name)\s*[:：\-–—.\s]+/iu', '', $value) ?? $value;
            $value = $this->stripNameEdgeNoiseTokens($value);
            $value = $this->stripCandidateNameLabelPrefix($value);
            $value = $this->stripNameEdgeNoiseTokens($value);
            $value = $this->stripNameHonorificPrefix($value);
            $value = $this->stripNameEdgeNoiseTokens($value);
            $value = $this->trimEdgePunctuation($value);
        } while ($value !== $before);

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
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

    private function trimEdgePunctuation(string $value): string
    {
        $value = trim($value, " \t\n\r\0\x0B:-|.,;");
        $value = preg_replace('/^[\s–—।]+|[\s–—।]+$/u', '', $value) ?? $value;

        return trim($value, " \t\n\r\0\x0B:-|.,;");
    }

    private function cleanShortText(string $value): string
    {
        $value = $this->stopAtNextCandidateField($value);
        $value = preg_replace('/(?:मोबाईल|मोबाइल|मोबा\.?|मो\.?\s*नं\.?|संपर्क)\s*[:\-]?\s*(?:\+?91[\s\-]*)?[6-9][0-9\s\-\/]{9,14}/ui', '', $value) ?? $value;
        $value = preg_replace('/(?<!\d)[6-9]\d{9}(?!\d)/u', '', OcrNormalize::normalizeDigits($value)) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $this->trimEdgePunctuation($value)) ?? $value);
    }

    private function cleanOccupationText(string $value): string
    {
        $value = $this->stopAtNextCandidateField($value);
        $value = preg_replace('/^(?:'.$this->candidateOccupationLabelPattern().')\s*(?:[:：\-–—.>\/]|[८8]|\s)*/ui', '', $value) ?? $value;
        $value = preg_replace('/^[\s:：\-–—.>\/८8]+/u', '', $value) ?? $value;
        $value = preg_replace('/(?:मोबाईल|मोबाइल|मोबा\.?|मो\.?\s*नं\.?|संपर्क)\s*[:\-]?\s*(?:\+?91[\s\-]*)?[6-9][0-9\s\-\/]{9,14}/ui', '', $value) ?? $value;
        $value = preg_replace('/(?<!\d)[6-9]\d{9}(?!\d)/u', '', OcrNormalize::normalizeDigits($value)) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $this->trimEdgePunctuation($value)) ?? $value);
    }

    private function validCandidateName(string $name): bool
    {
        if ($name === '' || mb_strlen($name, 'UTF-8') > 80 || $this->hasRelationContext($name) || $this->hasAnyFieldLabel($name)) {
            return false;
        }
        if (preg_match('/(?:\+?91[\s\-]*)?[6-9]\d{9}/u', preg_replace('/\s+/', '', $name) ?? '') === 1) {
            return false;
        }
        if (preg_match('/\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{2,4}/u', $name) === 1) {
            return false;
        }
        if ($this->looksLikeAddress($name) || $this->junkRatio($name) > 0.35) {
            return false;
        }

        return preg_match('/\p{L}/u', $name) === 1;
    }

    private function validRescuedName(string $name): bool
    {
        if ($name === '' || mb_strlen($name, 'UTF-8') > 80 || $this->hasRelationContext($name) || $this->hasAnyFieldLabel($name)) {
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

    private function shouldReplaceName(mixed $value): bool
    {
        if (! is_string($value) || trim($value) === '') {
            return true;
        }

        if ($this->hasCandidateNameLabel($value) || $this->hasAnyFieldLabel($value)) {
            return true;
        }

        if ($this->hasCandidateHonorific($value) && $this->validRescuedName($this->cleanName($value))) {
            return true;
        }

        return ! $this->validCandidateName($this->cleanName($value));
    }

    private function shouldReplaceShortText(mixed $value): bool
    {
        if (! is_string($value) || trim($value) === '') {
            return true;
        }

        return ! $this->validShortField($value);
    }

    private function shouldReplaceOccupation(mixed $value): bool
    {
        if (! is_string($value) || trim($value) === '') {
            return true;
        }

        if (in_array(trim($value), ['नोकरी', 'नौकरी', 'व्यवसाय', 'job', 'occupation'], true)) {
            return true;
        }

        return ! $this->validOccupationField($value, '');
    }

    private function validShortLocation(string $value): bool
    {
        return $this->validShortField($value)
            && ! $this->hasRelationContext($value)
            && preg_match('/(?:मोबाईल|मोबाइल|संपर्क|शिक्षण|नोकरी|व्यवसाय)/u', $value) !== 1;
    }

    private function validShortField(string $value): bool
    {
        return $value !== ''
            && mb_strlen($value, 'UTF-8') <= 70
            && preg_match('/\p{L}|\d/u', $value) === 1
            && ! $this->hasRelationContext($value)
            && $this->junkRatio($value) <= 0.45;
    }

    private function validOccupationField(string $value, string $sourceLine): bool
    {
        if ($value === ''
            || mb_strlen($value, 'UTF-8') > 120
            || preg_match('/\p{L}|\d/u', $value) !== 1
            || $this->hasRelationContext($value)
            || $this->junkRatio($value) > 0.45
            || $this->looksLikeEducationOnly($value)
            || $this->looksLikeMoneyOnly($value)) {
            return false;
        }

        if (preg_match('/^व्यवसाय/u', trim($sourceLine)) === 1
            && preg_match('/^[A-Za-z][A-Za-z0-9&().\/\-\s]+,\s*[A-Za-z][A-Za-z\s]+(?:\s*[-–—]?\s*\d{3,})?\.?$/u', $value) === 1
            && preg_match('/\b(?:consultant|analyst|engineer|developer|manager|executive|officer|architect|accountant|teacher|lecturer|professor|designer|sap|finance|hr|marketing|banker|clerk|specialist|lead|senior)\b/ui', $value) !== 1) {
            return false;
        }

        return true;
    }

    private function validHeightCm(mixed $value): bool
    {
        return is_numeric($value) && (float) $value >= 120 && (float) $value <= 213;
    }

    private function validPhone(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[6-9]\d{9}$/', $value) === 1;
    }

    private function empty(mixed $value): bool
    {
        return ! is_string($value) || trim($value) === '';
    }

    /**
     * @param  list<string>  $lines
     */
    private function hasNearbyParentContext(array $lines, int $index): bool
    {
        for ($i = max(0, $index - 2); $i <= $index; $i++) {
            if ($this->hasRelationContext($lines[$i] ?? '')) {
                return true;
            }
        }

        return false;
    }

    private function hasRelationContext(string $value): bool
    {
        return preg_match('/(?:वडील|वडिलांचे|पित्याचे|आई|मातेचे|मामा|मावशी|माऊशी|आत्या|चुलते|काका|आजोळ|भाऊ|बहिण|बहीण|दाजी|जावई)(?:[\s:：\-–—.]|$)/u', $value) === 1
            || preg_match('/\b(?:father|mother|brother|sister|uncle|aunt)\b/ui', $value) === 1;
    }

    private function hasNonContactPhoneContext(string $line): bool
    {
        return preg_match('/(?:जन्म\s*तारीख|जन्मतारीख|जन्म\s*दिनांक|जन्म\s*वेळ|जन्मवेळ|जमीन|शेती|एकर|उत्पन्न|वेतन|पत्ता|पिन\s*कोड|pincode|pin\s*code|कुंडली|पत्रिका|नक्षत्र|रास|राशी|गण|नाडी|देवक|कुलदैवत)/ui', $line) === 1;
    }

    private function hasAnyFieldLabel(string $value): bool
    {
        return preg_match('/(?:^|\s)(?:मुलाचे\s+नां?व|मुलीचे\s+नां?व|वधूचे\s+नां?व|वराचे\s+नां?व|जन्म\s*तारीख|जन्मतारीख|जन्म\s*दिनांक|जन्मदि|जन्म\s*ठिकाण|जन्म\s*स्थळ|उंची|ऊंची|लिंग|शिक्षण|शैक्षणिक|नोकरी|व्यवसाय|पद|मोबाईल|मोबाइल|संपर्क|पत्ता|धर्म|जात)(?:[\s:：\-–—.]|$)/ui', $value) === 1;
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

    private function isFamilySectionBoundary(string $line): bool
    {
        return preg_match('/^\s*(?:कौटुंबिक\s+माहिती|कौटुंबिक\s+तपशील|वडील|वडिलांचे|वडीलांचे|पित्याचे|आई|आईचे|मातेचे|भाऊ|बहिण|बहीण|मुलाचे\s+भाऊ|मुलाची\s+बहीण|मुलाची\s+बहिण|मामा|मावशी|माऊशी|आत्या|चुलते|काका|आजोळ|नातेवाईक|इतर\s+नातेवाईक|पाहुणे)(?:[\s:：\-–—.]|$)/u', $line) === 1;
    }

    private function lineHasMaleCandidateSignal(string $line): bool
    {
        return $this->genderFromCandidateNameLabel($line) === 'male'
            || $this->genderFromStrongCandidateWord($line) === 'male'
            || $this->hasMaleCandidateHonorific($line);
    }

    private function lineHasFemaleCandidateSignal(string $line): bool
    {
        return $this->genderFromCandidateNameLabel($line) === 'female'
            || $this->genderFromStrongCandidateWord($line) === 'female'
            || $this->genderFromCandidateHonorific($line) === 'female';
    }

    private function genderFromCandidateNameLabel(string $line): ?string
    {
        $hasFemale = preg_match('/(?:मुलीचे\s+नां?व|वधूचे\s+नां?व)/u', $line) === 1;
        $hasMale = preg_match('/(?:मुलाचे\s+नां?व|वराचे\s+नां?व)/u', $line) === 1;

        if ($hasFemale === $hasMale) {
            return null;
        }

        return $hasFemale ? 'female' : 'male';
    }

    private function genderFromStrongCandidateWord(string $line): ?string
    {
        $hasFemale = preg_match('/(?:मुलगी|(?<!\p{L})वधू(?!\p{L}))/u', $line) === 1;
        $hasMale = preg_match('/(?:मुलगा|(?<!\p{L})वर(?!\p{L}))/u', $line) === 1;

        if ($hasFemale === $hasMale) {
            return null;
        }

        return $hasFemale ? 'female' : 'male';
    }

    private function genderFromCandidateHonorific(string $line): ?string
    {
        $hasFemale = $this->hasFemaleCandidateHonorific($line);
        $hasMale = $this->hasMaleCandidateHonorific($line);

        if ($hasFemale === $hasMale) {
            return null;
        }

        return $hasFemale ? 'female' : 'male';
    }

    private function hasCandidateHonorific(string $line): bool
    {
        return $this->hasMaleCandidateHonorific($line)
            || $this->hasFemaleCandidateHonorific($line)
            || $this->hasWeakKuCandidateHonorific($line);
    }

    private function hasMaleCandidateHonorific(string $line): bool
    {
        return preg_match('/(?:^|[\s:：\-–—(])(?:चि\.|चि\s+|चिरंजीव\s*)\s*[\p{L}\p{M}]/u', $line) === 1;
    }

    private function hasFemaleCandidateHonorific(string $line): bool
    {
        return preg_match('/(?:^|[\s:：\-–—(])(?:कुमारी\s*)\s*[\p{L}\p{M}]/u', $line) === 1;
    }

    private function hasWeakKuCandidateHonorific(string $line): bool
    {
        return preg_match('/(?:^|[\s:：\-–—(])(?:कु\.|कुं\.?|कु\s+)\s*[\p{L}\p{M}]/u', $line) === 1;
    }

    /**
     * @param  list<string>  $lines
     */
    private function genderLooksDrivenByFamilyHonorific(array $lines): bool
    {
        foreach ($this->candidateScopedLines($lines) as $line) {
            if (! $this->hasRelationContext($line)
                && ($this->lineHasMaleCandidateSignal($line) || $this->lineHasFemaleCandidateSignal($line))) {
                return false;
            }
        }

        foreach ($lines as $line) {
            if ($this->hasRelationContext($line) && $this->hasCandidateHonorific($line)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $lines
     */
    private function genderLooksDrivenByWeakKuHonorific(array $lines): bool
    {
        foreach ($this->candidateScopedLines($lines) as $line) {
            if ($this->hasRelationContext($line)) {
                continue;
            }

            if ($this->lineHasMaleCandidateSignal($line) || $this->lineHasFemaleCandidateSignal($line)) {
                return false;
            }

            if ($this->hasWeakKuCandidateHonorific($line)) {
                return true;
            }
        }

        return false;
    }

    private function lineHasDirectContactLabel(string $line): bool
    {
        return preg_match('/(?:मोबाईल|मोबाइल|मो\.?\s*नं\.?|संपर्क|mobile|phone|contact)/ui', $line) === 1;
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

        $noise = '(?:ae|et|ner|ia|s|च्च)';
        do {
            $before = $value;
            $value = preg_replace('/^\s*(?:\d+|'.$noise.')\s+/iu', '', $value) ?? $value;
            $value = preg_replace('/\s+(?:\d+|'.$noise.')\s*$/iu', '', $value) ?? $value;
            $value = $this->trimEdgePunctuation($value);
        } while ($value !== $before);

        return trim($value);
    }

    private function looksLikeEducationOnly(string $value): bool
    {
        return preg_match('/^(?:B\.?\s*(?:Com|A|Sc|E|Tech)|M\.?\s*(?:Com|A|Sc|E|Tech)|MBA|BBA|BCOM|BSC|BE|ME|MTECH|Diploma|ITI|HSC|SSC|शिक्षण|पदवी)/ui', trim($value)) === 1
            && preg_match('/\b(?:consultant|analyst|engineer|developer|manager|executive|officer|teacher|lecturer|professor|company|bank|शेती|नोकरी|व्यवसाय)\b/ui', $value) !== 1;
    }

    private function looksLikeMoneyOnly(string $value): bool
    {
        $normalized = str_replace(',', '', OcrNormalize::normalizeDigits($value));

        return preg_match('/^[\s₹RsINR0-9.,\/\-]+(?:लाख|lac|lpa|per\s*month|per\s*annum|वार्षिक|monthly|yearly)?\s*$/ui', $normalized) === 1;
    }

    private function looksLikeAddress(string $value): bool
    {
        return preg_match('/(?:मु\.?\s*पो\.?|रा\.|ता\.|जि\.|पत्ता|पोस्ट|कॉलनी|रोड|नगर|वाडी|गाव|फ्लॅट|वॉर्ड)/u', $value) === 1;
    }

    private function isFooterNoise(string $line): bool
    {
        return preg_match('/print|printing|shop|प्रिंट|छपाई/ui', $line) === 1;
    }

    private function junkRatio(string $value): float
    {
        $compact = preg_replace('/\s+/u', '', trim($value)) ?? '';
        $length = mb_strlen($compact, 'UTF-8');
        if ($length === 0) {
            return 1.0;
        }

        $junk = preg_match_all('/[^\p{L}\p{M}\d.(),+\-\/&]/u', $compact);

        return ((int) $junk) / $length;
    }
}
