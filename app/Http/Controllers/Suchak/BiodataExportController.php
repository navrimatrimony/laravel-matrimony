<?php

namespace App\Http\Controllers\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakBiodataExport;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakQrToken;
use App\Modules\Suchak\Services\SuchakPdfQrFoundationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            ->with('success', 'Secure PDF/QR export generated.')
            ->with('export_id', $result['export']->id)
            ->with('qr_url_path', $result['qr_url_path']);
    }

    public function download(
        Request $request,
        SuchakBiodataExport $export,
        SuchakPdfQrFoundationService $exportService,
    ): StreamedResponse|RedirectResponse {
        try {
            $trackedExport = $exportService->markExportDownloaded(
                $export,
                $request->user(),
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return redirect()->route('suchak.dashboard')->with('error', $exception->getMessage());
        }

        return Storage::disk('local')->download(
            $trackedExport->file_path,
            'suchak-biodata-export-'.$trackedExport->id.'.pdf',
            ['Content-Type' => 'application/pdf'],
        );
    }

    public function markShared(
        Request $request,
        SuchakBiodataExport $export,
        SuchakPdfQrFoundationService $exportService,
    ): RedirectResponse {
        try {
            $exportService->markExportShared(
                $export,
                $request->user(),
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'PDF/QR share tracking updated.');
    }

    public function revokeQrToken(
        Request $request,
        SuchakQrToken $qrToken,
        SuchakPdfQrFoundationService $exportService,
    ): RedirectResponse {
        try {
            $exportService->revokeQrToken(
                $qrToken,
                $request->user(),
                'manual_revoke',
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'QR token revoked.');
    }
}
