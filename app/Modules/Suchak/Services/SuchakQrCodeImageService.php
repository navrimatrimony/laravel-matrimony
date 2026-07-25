<?php

namespace App\Modules\Suchak\Services;

use BaconQrCode\Renderer\Image\ImagickImageBackEnd;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class SuchakQrCodeImageService
{
    public function svgDataUri(string $content, int $size = 240): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle($size, 3),
            new SvgImageBackEnd,
        );

        $svg = (new Writer($renderer))->writeString($content);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * Raster PNG bytes for the same QR content. Used where a real image URL is
     * required (e.g. an og:image the WhatsApp link-preview crawler can fetch),
     * which an inline SVG data URI cannot satisfy. Rendered via Imagick.
     */
    public function pngBytes(string $content, int $size = 512): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle($size, 3),
            new ImagickImageBackEnd,
        );

        return (new Writer($renderer))->writeString($content);
    }
}
