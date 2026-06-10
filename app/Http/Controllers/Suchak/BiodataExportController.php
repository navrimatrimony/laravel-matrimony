<?php

namespace App\Http\Controllers\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakProfileRepresentation;
use App\Modules\Suchak\Services\SuchakPdfQrFoundationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class BiodataExportController extends Controller
{
    public function store(
        Request $request,
        SuchakProfileRepresentation $representation,
        SuchakPdfQrFoundationService $exportService,
    ): RedirectResponse {
        try {
            $result = $exportService->createGovernedBiodataPdfExport(
                $representation,
                $request->user(),
                null,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('suchak.dashboard')
            ->with('success', 'Secure PDF/QR export record created.')
            ->with('qr_url_path', $result['qr_url_path']);
    }
}
