<?php

namespace App\Services\Ocr;

/**
 * GD-only heuristic: suggest an axis-aligned document (paper) bounding box as 4 corners.
 * Used only to pre-fill the manual crop UI; user may adjust. Does not modify uploads.
 */
class AutoCropSuggestionService
{
    private const MAX_ANALYSIS_SIDE = 400;

    private const ROW_COL_BRIGHT_FRACTION = 0.12;

    /**
     * @return array{tl: array{x: int, y: int}, tr: array{x: int, y: int}, br: array{x: int, y: int}, bl: array{x: int, y: int}, confidence: float}
     */
    public function suggest(string $imagePath): array
    {
        if ($imagePath === '' || ! is_file($imagePath) || ! is_readable($imagePath)) {
            return $this->defaultInset(1, 1);
        }

        $info = @getimagesize($imagePath);
        if ($info === false) {
            return $this->defaultInset(1, 1);
        }

        $w0 = (int) ($info[0] ?? 0);
        $h0 = (int) ($info[1] ?? 0);
        if ($w0 < 2 || $h0 < 2) {
            return $this->defaultInset(max(1, $w0), max(1, $h0));
        }

        $binary = @file_get_contents($imagePath);
        if ($binary === false || $binary === '') {
            return $this->defaultInset($w0, $h0);
        }

        $src = @imagecreatefromstring($binary);
        if ($src === false) {
            return $this->defaultInset($w0, $h0);
        }

        $scale = min(self::MAX_ANALYSIS_SIDE / $w0, self::MAX_ANALYSIS_SIDE / $h0, 1.0);
        $ws = max(2, (int) round($w0 * $scale));
        $hs = max(2, (int) round($h0 * $scale));

        $small = imagecreatetruecolor($ws, $hs);
        if ($small === false) {
            imagedestroy($src);

            return $this->defaultInset($w0, $h0);
        }
        imagealphablending($small, false);
        imagesavealpha($small, true);
        imagecopyresampled($small, $src, 0, 0, 0, 0, $ws, $hs, $w0, $h0);
        imagedestroy($src);

        $lum = [];
        $sum = 0.0;
        for ($y = 0; $y < $hs; $y++) {
            $lum[$y] = [];
            for ($x = 0; $x < $ws; $x++) {
                $rgb = imagecolorat($small, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $L = (int) round(0.299 * $r + 0.587 * $g + 0.114 * $b);
                $lum[$y][$x] = $L;
                $sum += $L;
            }
        }
        imagedestroy($small);

        $n = $ws * $hs;
        $mean = $n > 0 ? $sum / $n : 128.0;
        $varSum = 0.0;
        for ($y = 0; $y < $hs; $y++) {
            for ($x = 0; $x < $ws; $x++) {
                $d = $lum[$y][$x] - $mean;
                $varSum += $d * $d;
            }
        }
        $std = $n > 0 ? sqrt($varSum / $n) : 1.0;
        $threshold = (int) round(min(255, max(0, $mean + 0.35 * $std)));

        $rowBright = [];
        for ($y = 0; $y < $hs; $y++) {
            $c = 0;
            for ($x = 0; $x < $ws; $x++) {
                if ($lum[$y][$x] >= $threshold) {
                    $c++;
                }
            }
            $rowBright[$y] = $c / $ws;
        }

        $colBright = [];
        for ($x = 0; $x < $ws; $x++) {
            $c = 0;
            for ($y = 0; $y < $hs; $y++) {
                if ($lum[$y][$x] >= $threshold) {
                    $c++;
                }
            }
            $colBright[$x] = $c / $hs;
        }

        $frac = self::ROW_COL_BRIGHT_FRACTION;
        $top = 0;
        for ($y = 0; $y < $hs; $y++) {
            if ($rowBright[$y] >= $frac) {
                $top = $y;
                break;
            }
        }
        $bottom = $hs - 1;
        for ($y = $hs - 1; $y >= 0; $y--) {
            if ($rowBright[$y] >= $frac) {
                $bottom = $y;
                break;
            }
        }
        $left = 0;
        for ($x = 0; $x < $ws; $x++) {
            if ($colBright[$x] >= $frac) {
                $left = $x;
                break;
            }
        }
        $right = $ws - 1;
        for ($x = $ws - 1; $x >= 0; $x--) {
            if ($colBright[$x] >= $frac) {
                $right = $x;
                break;
            }
        }

        if ($right <= $left || $bottom <= $top) {
            return $this->defaultInset($w0, $h0);
        }

        $sx = $w0 / $ws;
        $sy = $h0 / $hs;
        $x1 = (int) floor($left * $sx);
        $y1 = (int) floor($top * $sy);
        $x2 = (int) ceil(($right + 1) * $sx) - 1;
        $y2 = (int) ceil(($bottom + 1) * $sy) - 1;

        $mx = max(1, (int) round($w0 * 0.02));
        $my = max(1, (int) round($h0 * 0.02));
        $x1 = min($w0 - 2, $x1 + $mx);
        $y1 = min($h0 - 2, $y1 + $my);
        $x2 = max($x1 + 1, $x2 - $mx);
        $y2 = max($y1 + 1, $y2 - $my);

        $area = ($x2 - $x1) * ($y2 - $y1);
        $imageArea = $w0 * $h0;
        $coverage = $imageArea > 0 ? $area / $imageArea : 0.0;

        $confidence = 0.45;
        if ($coverage >= 0.15 && $coverage <= 0.92) {
            $confidence += 0.35;
        }
        if ($coverage >= 0.25 && $coverage <= 0.88) {
            $confidence += 0.1;
        }
        $confidence = min(1.0, $confidence);

        return [
            'tl' => ['x' => $x1, 'y' => $y1],
            'tr' => ['x' => $x2, 'y' => $y1],
            'br' => ['x' => $x2, 'y' => $y2],
            'bl' => ['x' => $x1, 'y' => $y2],
            'confidence' => round($confidence, 3),
        ];
    }

    /**
     * @return array{tl: array{x: int, y: int}, tr: array{x: int, y: int}, br: array{x: int, y: int}, bl: array{x: int, y: int}, confidence: float}
     */
    private function defaultInset(int $w, int $h): array
    {
        $w = max(1, $w);
        $h = max(1, $h);
        $mx = max(1, (int) round($w * 0.06));
        $my = max(1, (int) round($h * 0.06));
        $x1 = $mx;
        $y1 = $my;
        $x2 = max($x1 + 1, $w - 1 - $mx);
        $y2 = max($y1 + 1, $h - 1 - $my);

        return [
            'tl' => ['x' => $x1, 'y' => $y1],
            'tr' => ['x' => $x2, 'y' => $y1],
            'br' => ['x' => $x2, 'y' => $y2],
            'bl' => ['x' => $x1, 'y' => $y2],
            'confidence' => 0.15,
        ];
    }
}
