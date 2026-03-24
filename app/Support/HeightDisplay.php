<?php

namespace App\Support;

/**
 * Display helpers: feet/inches primary, centimetres in brackets (canonical storage remains cm).
 */
final class HeightDisplay
{
    /**
     * e.g. 175 → 5'9" (175 cm)
     */
    public static function formatCm(int $cm): string
    {
        if ($cm < 1) {
            return (string) $cm.' cm';
        }
        [$ft, $in] = self::cmToFeetInches($cm);

        return sprintf("%d'%d\" (%d cm)", $ft, $in, $cm);
    }

    /**
     * e.g. 165 – 175 cm as 5'5" – 5'9" (165 – 175 cm)
     */
    public static function formatCmRange(int $minCm, int $maxCm): string
    {
        return self::formatCm($minCm).' – '.self::formatCm($maxCm);
    }

    /**
     * e.g. 175 → 5'9"
     */
    public static function formatFeetInches(int $cm): string
    {
        if ($cm < 1) {
            return (string) $cm.' cm';
        }
        [$ft, $in] = self::cmToFeetInches($cm);

        return sprintf("%d'%d\"", $ft, $in);
    }

    /**
     * e.g. 165 - 175 cm as 5'5" - 5'9"
     */
    public static function formatFeetInchesRange(int $minCm, int $maxCm): string
    {
        return self::formatFeetInches($minCm).' - '.self::formatFeetInches($maxCm);
    }

    /**
     * @return array{0: int, 1: int} feet, inches (whole inches)
     */
    public static function cmToFeetInches(int $cm): array
    {
        $totalInches = $cm / 2.54;
        $feet = (int) floor($totalInches / 12);
        $inches = (int) round($totalInches - $feet * 12);
        if ($inches === 12) {
            $feet++;
            $inches = 0;
        }
        if ($inches < 0) {
            $inches = 0;
        }

        return [$feet, $inches];
    }
}
