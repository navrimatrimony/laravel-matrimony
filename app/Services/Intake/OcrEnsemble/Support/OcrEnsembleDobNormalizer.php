<?php

namespace App\Services\Intake\OcrEnsemble\Support;

use App\Services\Ocr\OcrNormalize;
use Illuminate\Support\Carbon;

/**
 * Production Phase 3 DOB normalization from OCR text (DD/MM/YYYY first, OCR digit recovery).
 */
class OcrEnsembleDobNormalizer
{
    /**
     * Fuzzy Marathi / English DOB labels (Tesseract often corrupts leading ज / birth glyphs).
     */
    public const DOB_LABEL_PATTERN = '/(?:[जअलग]|ज्)?\.?\s*न्म\s*तार्[ीईि]?ख|जन्मतारीख|जन्म\s*दिनांक|जन्मदि|DOB|date\s*of\s*birth/ui';

    /**
     * Common single-glyph OCR digit confusions (year recovery only).
     *
     * @var array<string, list<string>>
     */
    private const YEAR_DIGIT_ALTS = [
        '0' => ['8', '9', '6'],
        '1' => ['7', '4'],
        '2' => ['7', '3'],
        '3' => ['9', '8', '5'],
        '4' => ['1', '9'],
        '5' => ['6', '3', '8'],
        '6' => ['5', '8', '0'],
        '7' => ['1', '2'],
        '8' => ['3', '0', '9', '6'],
        '9' => ['3', '8', '4', '0'],
    ];

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
            if (preg_match(self::DOB_LABEL_PATTERN, $line) !== 1) {
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

    public function lineLooksLikeDobLabel(string $line): bool
    {
        return preg_match(self::DOB_LABEL_PATTERN, $line) === 1;
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

            $recovered = $this->recoverYearDigitOcr($day, $month, $year);
            if ($recovered !== null) {
                return $recovered;
            }
        }

        return null;
    }

    /**
     * When calendar date is valid but age is out of band, try one-glyph year OCR fixes
     * (e.g. 1938 → 1998, 1396 → 1996). Does not invent a date without a parsed triple.
     */
    private function recoverYearDigitOcr(int $day, int $month, int $year): ?string
    {
        if ($year < 1000 || $year > 2100) {
            return null;
        }

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        $yearDigits = str_pad((string) $year, 4, '0', STR_PAD_LEFT);
        $candidates = [];

        for ($i = 0; $i < 4; $i++) {
            $digit = $yearDigits[$i];
            foreach (self::YEAR_DIGIT_ALTS[$digit] ?? [] as $alt) {
                $try = $yearDigits;
                $try[$i] = $alt;
                $tryYear = (int) $try;
                $iso = $this->isoDateIfCandidateAge($day, $month, $tryYear);
                if ($iso === null) {
                    continue;
                }
                $age = Carbon::createFromDate($tryYear, $month, $day)->age;
                $candidates[$iso] = [
                    'iso' => $iso,
                    'age' => $age,
                    'distance_from_28' => abs($age - 28),
                ];
            }
        }

        if ($candidates === []) {
            return null;
        }

        uasort($candidates, static function (array $a, array $b): int {
            return $a['distance_from_28'] <=> $b['distance_from_28'];
        });

        $best = reset($candidates);

        return is_array($best) ? (string) $best['iso'] : null;
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
