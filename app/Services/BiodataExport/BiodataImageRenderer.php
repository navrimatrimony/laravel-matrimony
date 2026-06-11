<?php

namespace App\Services\BiodataExport;

use RuntimeException;

final class BiodataImageRenderer
{
    public function jpgFromPdfBinary(string $pdfBinary): string
    {
        if (! class_exists(\Imagick::class)) {
            throw new RuntimeException('JPG export is not available because Imagick is not installed.');
        }

        $image = new \Imagick();
        $image->setResolution(180, 180);
        $image->readImageBlob($pdfBinary);
        $image->setIteratorIndex(0);

        $page = $image->getImage();
        $page->setImageBackgroundColor('white');
        $page->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
        $page->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
        $page->setImageFormat('jpeg');
        $page->setImageCompressionQuality(92);

        $blob = $page->getImageBlob();
        $page->clear();
        $image->clear();

        if ($blob === '') {
            throw new RuntimeException('JPG export failed while rendering the first PDF page.');
        }

        return $blob;
    }
}
