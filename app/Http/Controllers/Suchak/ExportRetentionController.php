<?php

namespace App\Http\Controllers\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakBusinessExport;
use App\Modules\Suchak\Services\SuchakExportRetentionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class ExportRetentionController extends Controller
{
    public function index(Request $request, SuchakExportRetentionService $exportRetentionService): View
    {
        $account = $request->user()?->suchakAccount;
        if (! $account) {
            abort(403, 'Suchak account is required.');
        }

        return view('suchak.export-retention.index', [
            'summary' => $exportRetentionService->summary($account, $request->user()),
        ]);
    }

    public function store(Request $request, SuchakExportRetentionService $exportRetentionService): RedirectResponse
    {
        $account = $request->user()?->suchakAccount;
        if (! $account) {
            abort(403, 'Suchak account is required.');
        }

        $validated = $request->validate([
            'export_type' => ['required', 'string', Rule::in(SuchakBusinessExport::TYPES)],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date'],
            'include_private_contact' => ['nullable', 'boolean'],
        ]);

        try {
            $exportRetentionService->createBusinessExport(
                $account,
                $request->user(),
                $validated,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('suchak.export-retention.index')
            ->with('success', 'Suchak business export generated.');
    }

    public function download(
        Request $request,
        SuchakBusinessExport $businessExport,
        SuchakExportRetentionService $exportRetentionService,
    ): Response {
        try {
            $csv = $exportRetentionService->csvForExport(
                $businessExport,
                $request->user(),
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            abort(403, $exception->getMessage());
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$businessExport->file_name.'"',
        ]);
    }
}
