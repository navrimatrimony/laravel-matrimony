<?php

namespace App\Services\Intake\OcrEnsemble\Support;

use App\Services\Ocr\OcrNormalize;
use Illuminate\Support\Carbon;

/**
 * Production Phase 3 DOB normalization from OCR text (DD/MM/YYYY first, OCR digit recovery).
 */
class OcrEnsembleDobNormalizer
{
    public function normalize(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = $this->prepareDateText($value);

        if (preg_match('/(?<!\d)(\d{1,2})\s*[\/.\-]\s*(\d{1,2})\s*[\/.\-]\s*(\d{2,4})(?!\d)/u', $value, $matches) === 1) {
            $iso = $this->isoFromDayMonthYear((int) $matches[1], (int) $matches[2], (string) $matches[3]);
            if ($iso !== null) {
                return $iso;
            }
        }

        $normalized = OcrNormalize::normalizeDate($value);
        if ($this->validCandidateIsoDate($normalized)) {
            return $normalized;
        }

        return null;
    }

    /**
     * @param  list<string>  $lines
     */
    public function normalizeFromLines(array $lines): ?string
    {
        foreach ($lines as $line) {
            if (preg_match('/जन्म\s*तारीख|जन्मतारीख|जन्म\s*दिनांक|DOB|date\s*of\s*birth/ui', $line) !== 1) {
                continue;
            }

            if (preg_match('/(\d{1,2}\s*[\/.-]\s*\d{1,2}\s*[\/.-]\s*\d{2,4})/u', $line, $matches) === 1) {
                $iso = $this->normalize($matches[1]);
                if ($iso !== null) {
                    return $iso;
                }
            }

            $iso = $this->normalize($line);
            if ($iso !== null) {
                return $iso;
            }
        }

        foreach ($lines as $line) {
            if (preg_match('/(?<!\d)(\d{1,2}\s*[\/.-]\s*\d{1,2}\s*[\/.-]\s*\d{4})(?!\d)/u', $line, $matches) !== 1) {
                continue;
            }

            $iso = $this->normalize($matches[1]);
            if ($iso !== null) {
                return $iso;
            }
        }

        return null;
    }

    private function prepareDateText(string $value): string
    {
        $value = OcrNormalize::normalizeDigits(trim($value));
        $value = $this->recoverOcrDateDigits($value);

        return OcrNormalize::normalizeMarathiMonthWordsToEnglish($value);
    }

    private function recoverOcrDateDigits(string $value): string
    {
        return preg_replace_callback(
            '/(?<!\d)([0-9OIlSZBo]{1,2})\s*([\/.\-])\s*([0-9OIlSZBo]{1,2})\s*\2\s*([0-9OIlSZBo]{2,4})(?!\d)/u',
            static function (array $matches): string {
                $fix = static fn (string $digits): string => strtr($digits, [
                    'O' => '0', 'o' => '0', 'l' => '1', 'I' => '1', 'S' => '5', 's' => '5', 'Z' => '2', 'B' => '8',
                ]);

                return $fix($matches[1]).$matches[2].$fix($matches[3]).$matches[2].$fix($matches[4]);
            },
            $value
        ) ?? $value;
    }

    private function isoFromDayMonthYear(int $day, int $month, string $yearRaw): ?string
    {
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
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) !== 1) {
            return false;
        }

        return $this->isoDateIfCandidateAge((int) $matches[3], (int) $matches[2], (int) $matches[1]) === $value;
    }
}
