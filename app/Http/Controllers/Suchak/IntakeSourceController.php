<?php

namespace App\Http\Controllers\Suchak;

use App\Http\Controllers\Controller;
use App\Modules\Suchak\Services\SuchakSourceLinkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class IntakeSourceController extends Controller
{
    public function create(Request $request, SuchakSourceLinkService $sourceLinkService): View|RedirectResponse
    {
        $account = $request->user()->suchakAccount;

        if (! $account || ! $sourceLinkService->canCreate($account)) {
            return redirect()
                ->route('suchak.dashboard')
                ->with('error', 'Only active Suchak accounts can create biodata intake source links.');
        }

        return view('suchak.intakes.create', [
            'suchakAccount' => $account,
        ]);
    }

    public function store(Request $request, SuchakSourceLinkService $sourceLinkService): RedirectResponse
    {
        $validated = $request->validate([
            'raw_text' => ['nullable', 'string', 'required_without:file'],
            'file' => ['nullable', 'file', 'max:20480', 'required_without:raw_text'],
        ]);

        $account = $request->user()->suchakAccount;

        if (! $account) {
            return redirect()
                ->route('suchak.dashboard')
                ->with('error', 'Suchak account is required to create biodata intake source links.');
        }

        try {
            $link = $sourceLinkService->createFromIntakeUpload(
                $account,
                $request->user(),
                $request->file('file'),
                $validated['raw_text'] ?? null,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return redirect()
                ->route('suchak.dashboard')
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('intake.status', $link->biodata_intake_id)
            ->with('success', 'Suchak biodata intake source link created.');
    }
}
