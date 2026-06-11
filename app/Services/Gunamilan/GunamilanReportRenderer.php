<?php

namespace App\Services\Gunamilan;

use App\Support\ReportFontAssets;
use Barryvdh\DomPDF\Facade\Pdf;

final class GunamilanReportRenderer
{
    /**
     * @param  array<string, mixed>  $data
     */
    /**
     * @param  array<string, string>  $template
     */
    public function binary(array $data, array $template): string
    {
        return Pdf::loadView($template['view'], array_merge($data, [
            'pdfMode' => true,
        ]))
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled' => false,
                'chroot' => ReportFontAssets::chrootDirectories(),
                'defaultFont' => 'ReportDevanagari',
            ])
            ->output();
    }
}
