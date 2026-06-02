<?php

declare(strict_types=1);

namespace App\Services\Intake;

use App\Models\BiodataIntake;

final class IntakePhotoCandidateSuggestionService
{
    /**
     * @return array{
     *     available: bool,
     *     confidence: float,
     *     box: array{x: int, y: int, width: int, height: int}|null,
     *     message: string
     * }
     */
    public function suggest(BiodataIntake $intake): array
    {
        $relativePath = trim((string) ($intake->file_path ?? ''));
        if ($relativePath === '') {
            return $this->payload(false, 0.0, null, 'No uploaded image biodata available.');
        }

        $path = storage_path('app/private/'.$relativePath);
        if (! is_file($path) || ! is_readable($path)) {
            return $this->payload(false, 0.0, null, 'No uploaded image biodata available.');
        }

        $binary = file_get_contents($path);
        if ($binary === false || $binary === '') {
            return $this->payload(false, 0.0, null, 'Candidate photo auto-detection is unavailable for this image.');
        }

        $image = @imagecreatefromstring($binary);
        if (! $image instanceof \GdImage) {
            return $this->payload(false, 0.0, null, 'Candidate photo auto-detection is unavailable for this image.');
        }

        try {
            return $this->suggestFromImage($image);
        } finally {
            imagedestroy($image);
        }
    }

    /**
     * @return array{
     *     available: bool,
     *     confidence: float,
     *     box: array{x: int, y: int, width: int, height: int}|null,
     *     message: string
     * }
     */
    private function suggestFromImage(\GdImage $image): array
    {
        $width = imagesx($image);
        $height = imagesy($image);
        if ($width < 120 || $height < 120) {
            return $this->payload(false, 0.0, null, 'Image is too small for profile photo auto-detection.');
        }

        $step = max(2, (int) floor(max($width, $height) / 320));
        $bg = $this->averageCornerColor($image, $width, $height, $step);

        $cols = (int) ceil($width / $step);
        $rows = (int) ceil($height / $step);
        $mask = [];
        $hits = 0;

        for ($row = 0; $row < $rows; $row++) {
            $mask[$row] = [];
            $y = min($height - 1, $row * $step);
            for ($col = 0; $col < $cols; $col++) {
                $x = min($width - 1, $col * $step);
                $rgb = $this->rgbAt($image, $x, $y);
                $sat = $this->saturation($rgb);
                $distance = $this->distance($rgb, $bg);
                $on = $sat >= 28 && $distance >= 38;
                $mask[$row][$col] = $on;
                if ($on) {
                    $hits++;
                }
            }
        }

        if ($hits < 24) {
            return $this->payload(false, 0.0, null, 'No reliable profile photo block was detected. Adjust the crop border manually.');
        }

        $components = $this->photoLikeComponents($mask, $cols, $rows, $step, $width, $height);
        if ($components === []) {
            return $this->payload(false, 0.0, null, 'No reliable profile photo block was detected. Adjust the crop border manually.');
        }

        usort($components, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $best = $components[0];

        $pad = max(4, $step * 2);
        $x = max(0, (int) $best['minX'] - $pad);
        $y = max(0, (int) $best['minY'] - $pad);
        $w = min($width - $x, ((int) $best['maxX'] - (int) $best['minX']) + 1 + ($pad * 2));
        $h = min($height - $y, ((int) $best['maxY'] - (int) $best['minY']) + 1 + ($pad * 2));
        [$x, $y, $w, $h] = $this->normalizeToProfileAspect($x, $y, $w, $h, $width, $height);

        $areaRatio = ($w * $h) / max(1, $width * $height);
        if ($areaRatio > 0.55 || $areaRatio < 0.008) {
            return $this->payload(false, 0.0, null, 'No reliable profile photo block was detected. Adjust the crop border manually.');
        }

        $componentFill = ((int) $best['cells']) / max(1, ((int) $best['gridWidth'] * (int) $best['gridHeight']));
        $confidence = min(0.94, max(0.35, ((float) $best['areaRatio'] * 4.5) + ($componentFill * 0.35) + 0.45));
        if ($confidence < 0.55) {
            return $this->payload(false, round($confidence, 3), [
                'x' => $x,
                'y' => $y,
                'width' => $w,
                'height' => $h,
            ], 'Low confidence detection. Adjust the crop border manually.');
        }

        return $this->payload(true, round($confidence, 3), [
            'x' => $x,
            'y' => $y,
            'width' => $w,
            'height' => $h,
        ], 'Detected candidate photo area. Adjust if needed, then save.');
    }

    /**
     * @param  array<int, array<int, bool>>  $mask
     * @return list<array{minX: int, minY: int, maxX: int, maxY: int, cells: int, gridWidth: int, gridHeight: int, areaRatio: float, score: float}>
     */
    private function photoLikeComponents(array $mask, int $cols, int $rows, int $step, int $width, int $height): array
    {
        $visited = array_fill(0, $rows, array_fill(0, $cols, false));
        $components = [];

        for ($row = 0; $row < $rows; $row++) {
            for ($col = 0; $col < $cols; $col++) {
                if ($visited[$row][$col] || empty($mask[$row][$col])) {
                    continue;
                }

                $queue = [[$row, $col]];
                $visited[$row][$col] = true;
                $minCol = $col;
                $maxCol = $col;
                $minRow = $row;
                $maxRow = $row;
                $cells = 0;

                while ($queue !== []) {
                    [$r, $c] = array_pop($queue);
                    $cells++;
                    $minCol = min($minCol, $c);
                    $maxCol = max($maxCol, $c);
                    $minRow = min($minRow, $r);
                    $maxRow = max($maxRow, $r);

                    foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dr, $dc]) {
                        $nr = $r + $dr;
                        $nc = $c + $dc;
                        if ($nr < 0 || $nc < 0 || $nr >= $rows || $nc >= $cols || $visited[$nr][$nc] || empty($mask[$nr][$nc])) {
                            continue;
                        }
                        $visited[$nr][$nc] = true;
                        $queue[] = [$nr, $nc];
                    }
                }

                $minX = $minCol * $step;
                $minY = $minRow * $step;
                $maxX = min($width - 1, (($maxCol + 1) * $step) - 1);
                $maxY = min($height - 1, (($maxRow + 1) * $step) - 1);
                $boxW = max(1, $maxX - $minX + 1);
                $boxH = max(1, $maxY - $minY + 1);
                $areaRatio = ($boxW * $boxH) / max(1, $width * $height);
                $gridWidth = $maxCol - $minCol + 1;
                $gridHeight = $maxRow - $minRow + 1;

                if ($cells < 20 || $areaRatio < 0.006 || $areaRatio > 0.45 || $boxW < 60 || $boxH < 80) {
                    continue;
                }

                $fill = $cells / max(1, $gridWidth * $gridHeight);
                $shapeScore = min($boxW, $boxH) / max($boxW, $boxH);
                $score = ($areaRatio * 1000.0) + ($fill * 15.0) + ($shapeScore * 5.0);

                $components[] = [
                    'minX' => $minX,
                    'minY' => $minY,
                    'maxX' => $maxX,
                    'maxY' => $maxY,
                    'cells' => $cells,
                    'gridWidth' => $gridWidth,
                    'gridHeight' => $gridHeight,
                    'areaRatio' => $areaRatio,
                    'score' => $score,
                ];
            }
        }

        return $components;
    }

    /**
     * @return array{int, int, int, int}
     */
    private function normalizeToProfileAspect(int $x, int $y, int $w, int $h, int $imageW, int $imageH): array
    {
        $aspect = 600 / 800;
        $current = $w / max(1, $h);
        if ($current > $aspect) {
            $targetH = $h;
            $targetW = (int) round($targetH * $aspect);
        } else {
            $targetW = $w;
            $targetH = (int) round($targetW / $aspect);
        }

        $cx = $x + ($w / 2);
        $cy = $y + ($h / 2);
        $x = (int) round($cx - ($targetW / 2));
        $y = (int) round($cy - ($targetH / 2));
        $x = max(0, min($imageW - $targetW, $x));
        $y = max(0, min($imageH - $targetH, $y));

        return [$x, $y, min($targetW, $imageW), min($targetH, $imageH)];
    }

    /**
     * @return array{r: float, g: float, b: float}
     */
    private function averageCornerColor(\GdImage $image, int $width, int $height, int $step): array
    {
        $points = [
            [0, 0],
            [max(0, $width - (16 * $step)), 0],
            [0, max(0, $height - (16 * $step))],
            [max(0, $width - (16 * $step)), max(0, $height - (16 * $step))],
        ];
        $sum = ['r' => 0.0, 'g' => 0.0, 'b' => 0.0];
        $count = 0;

        foreach ($points as [$startX, $startY]) {
            for ($y = $startY; $y < min($height, $startY + (16 * $step)); $y += $step) {
                for ($x = $startX; $x < min($width, $startX + (16 * $step)); $x += $step) {
                    $rgb = $this->rgbAt($image, $x, $y);
                    $sum['r'] += $rgb['r'];
                    $sum['g'] += $rgb['g'];
                    $sum['b'] += $rgb['b'];
                    $count++;
                }
            }
        }

        return [
            'r' => $sum['r'] / max(1, $count),
            'g' => $sum['g'] / max(1, $count),
            'b' => $sum['b'] / max(1, $count),
        ];
    }

    /**
     * @return array{r: int, g: int, b: int}
     */
    private function rgbAt(\GdImage $image, int $x, int $y): array
    {
        $raw = imagecolorat($image, $x, $y);

        return [
            'r' => ($raw >> 16) & 0xFF,
            'g' => ($raw >> 8) & 0xFF,
            'b' => $raw & 0xFF,
        ];
    }

    /**
     * @param  array{r: int, g: int, b: int}  $rgb
     */
    private function saturation(array $rgb): float
    {
        $max = max($rgb['r'], $rgb['g'], $rgb['b']);
        $min = min($rgb['r'], $rgb['g'], $rgb['b']);

        return $max === 0 ? 0.0 : (($max - $min) / $max) * 100.0;
    }

    /**
     * @param  array{r: int|float, g: int|float, b: int|float}  $a
     * @param  array{r: int|float, g: int|float, b: int|float}  $b
     */
    private function distance(array $a, array $b): float
    {
        return sqrt((($a['r'] - $b['r']) ** 2) + (($a['g'] - $b['g']) ** 2) + (($a['b'] - $b['b']) ** 2));
    }

    /**
     * @param  array{x: int, y: int, width: int, height: int}|null  $box
     * @return array{
     *     available: bool,
     *     confidence: float,
     *     box: array{x: int, y: int, width: int, height: int}|null,
     *     message: string
     * }
     */
    private function payload(bool $available, float $confidence, ?array $box, string $message): array
    {
        return [
            'available' => $available,
            'confidence' => $confidence,
            'box' => $box,
            'message' => $message,
        ];
    }
}
