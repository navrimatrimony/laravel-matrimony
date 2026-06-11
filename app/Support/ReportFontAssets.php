<?php

namespace App\Support;

final class ReportFontAssets
{
    public const FAMILY = 'ReportDevanagari';

    public static function cssFontStack(): string
    {
        return '"'.self::FAMILY.'", "Mangal", "Kokila", "Utsaah", "DejaVu Sans", sans-serif';
    }

    public static function fontFaceCss(): string
    {
        $regular = self::firstExisting(self::regularCandidates());
        $bold = self::firstExisting(self::boldCandidates());

        $blocks = [];
        if ($regular !== null) {
            $blocks[] = self::fontFaceBlock($regular, 400);
        }
        if ($bold !== null) {
            $blocks[] = self::fontFaceBlock($bold, 700);
        }

        return implode("\n", $blocks);
    }

    /**
     * @return array<int, string>
     */
    public static function chrootDirectories(): array
    {
        $directories = [public_path(), base_path()];

        foreach (array_merge(self::regularCandidates(), self::boldCandidates()) as $path) {
            if (is_file($path)) {
                $directories[] = dirname($path);
            }
        }

        return array_values(array_unique($directories));
    }

    /**
     * @return array<int, string>
     */
    private static function regularCandidates(): array
    {
        return [
            public_path('fonts/NotoSansDevanagari-Regular.ttf'),
            public_path('fonts/Mangal.ttf'),
            'C:\\Windows\\Fonts\\mangal.ttf',
            'C:\\Windows\\Fonts\\kokila.ttf',
            'C:\\Windows\\Fonts\\utsaah.ttf',
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function boldCandidates(): array
    {
        return [
            public_path('fonts/NotoSansDevanagari-Bold.ttf'),
            public_path('fonts/Mangal-Bold.ttf'),
            'C:\\Windows\\Fonts\\mangalb.ttf',
            'C:\\Windows\\Fonts\\kokilab.ttf',
            'C:\\Windows\\Fonts\\utsaahb.ttf',
        ];
    }

    /**
     * @param  array<int, string>  $paths
     */
    private static function firstExisting(array $paths): ?string
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private static function fontFaceBlock(string $path, int $weight): string
    {
        return '@font-face { font-family: "'.self::FAMILY.'"; src: url("'.self::fileUrl($path).'") format("truetype"); font-weight: '.$weight.'; font-style: normal; }';
    }

    private static function fileUrl(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);

        if (preg_match('/^[A-Za-z]:\//', $normalized) === 1) {
            return 'file:///'.$normalized;
        }

        return 'file://'.$normalized;
    }
}
