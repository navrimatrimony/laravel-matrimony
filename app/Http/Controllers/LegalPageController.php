<?php

namespace App\Http\Controllers;

use App\Support\LegalDocument;
use Illuminate\View\View;

/**
 * The five public legal documents (terms, privacy, refund, disclaimer,
 * grievance). Deliberately guest-accessible and un-throttled: Meta, PayU and
 * Google Play reviewers fetch these URLs directly during verification.
 *
 * Which documents exist is decided in config/legal.php — this controller does
 * not carry its own list.
 */
class LegalPageController extends Controller
{
    public function show(string $document): View
    {
        abort_unless(LegalDocument::exists($document), 404);

        return view('legal.show', [
            'documentKey' => $document,
            'document' => LegalDocument::content($document),
            'meta' => LegalDocument::meta($document),
            'legalLinks' => LegalDocument::links(),
        ]);
    }
}
