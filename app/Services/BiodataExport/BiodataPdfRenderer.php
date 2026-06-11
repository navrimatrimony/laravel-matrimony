<?php

namespace App\Services\BiodataExport;

use Barryvdh\DomPDF\Facade\Pdf;

final class BiodataPdfRenderer
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $template
     */
    public function binary(array $payload, array $template): string
    {
        return Pdf::loadView((string) $template['view'], [
            'payload' => $payload,
            'template' => $template,
            'pdfMode' => true,
        ])
            ->setPaper('a4', (string) $template['orientation'])
            ->setOptions([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled' => false,
                'defaultFont' => 'DejaVu Sans',
            ])
            ->output();
    }
}
