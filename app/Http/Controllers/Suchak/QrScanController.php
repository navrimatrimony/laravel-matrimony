<?php

namespace App\Http\Controllers\Suchak;

use App\Http\Controllers\Controller;
use App\Modules\Suchak\Services\SuchakPdfQrFoundationService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class QrScanController extends Controller
{
    public function show(
        Request $request,
        string $token,
        SuchakPdfQrFoundationService $qrService,
    ): View {
        try {
            $scan = $qrService->scanQrToken(
                $token,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            abort(response()->view('suchak.qr.invalid', [
                'message' => $exception->getMessage(),
            ], 410));
        }

        return view('suchak.qr.show', [
            'candidateSummary' => $scan['candidate_summary'],
            'qrToken' => $scan['qr_token'],
        ]);
    }
}
