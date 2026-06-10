<?php

namespace App\Modules\Suchak\Services;

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
}
