<?php

namespace App\Http\Controllers\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakOfflineCamp;
use App\Models\SuchakOfflineCampIntakeLink;
use App\Models\SuchakOfflineCampPackageAssignment;
use App\Models\SuchakServicePackage;
use App\Modules\Suchak\Services\SuchakOfflineCampService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class OfflineCampController extends Controller
{
    public function index(Request $request, SuchakOfflineCampService $campService): View
    {
        $account = $request->user()?->suchakAccount;
        if (! $account) {
            abort(403, 'Suchak account is required.');
        }

        $selectedCamp = null;
        if ($request->query('camp')) {
            $selectedCamp = SuchakOfflineCamp::query()
                ->where('suchak_account_id', $account->id)
                ->with([
                    'intakeLinks.sourceLink.biodataIntake',
                    'intakeLinks.sourceLink.customerContext',
                    'intakeLinks.packageAssignments.servicePackage',
                    'packageAssignments.servicePackage',
                    'conversionReports',
                ])
                ->findOrFail((int) $request->query('camp'));
        }

        return view('suchak.offline-camps.index', [
            'suchakAccount' => $account,
            'summary' => $campService->dashboardFor($account),
            'selectedCamp' => $selectedCamp,
            'consentPendingList' => $selectedCamp instanceof SuchakOfflineCamp
                ? $campService->consentPendingList($selectedCamp)
                : collect(),
            'campTypes' => SuchakOfflineCamp::TYPES,
        ]);
    }

    public function store(Request $request, SuchakOfflineCampService $campService): RedirectResponse
    {
        $account = $this->account($request);
        $validated = $request->validate([
            'camp_key' => ['nullable', 'string', 'max:96'],
            'camp_name' => ['required', 'string', 'min:8', 'max:160'],
            'camp_name_mr' => ['nullable', 'string', 'max:160'],
            'camp_type' => ['required', 'string', Rule::in(SuchakOfflineCamp::TYPES)],
            'source_tag' => ['required', 'string', 'max:96'],
            'location_label' => ['nullable', 'string', 'max:160'],
            'location_label_mr' => ['nullable', 'string', 'max:160'],
            'camp_date' => ['nullable', 'date_format:Y-m-d'],
            'expected_intake_count' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'privacy_note' => ['required', 'string', 'min:10', 'max:1000'],
            'privacy_note_mr' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $camp = $campService->createCamp($account, $request->user(), $validated);
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage())->withInput();
        }

        return redirect()
            ->route('suchak.offline-camps.index', ['camp' => $camp->id])
            ->with('success', 'Suchak offline camp created.');
    }

    public function uploadIntake(
        Request $request,
        SuchakOfflineCamp $offlineCamp,
        SuchakOfflineCampService $campService,
    ): RedirectResponse {
        $validated = $request->validate([
            'raw_text' => ['nullable', 'string', 'required_without:file'],
            'file' => ['nullable', 'file', 'max:20480', 'required_without:raw_text'],
            'link_note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $campService->uploadAndLinkIntake(
                $offlineCamp,
                $request->user(),
                $request->file('file'),
                $validated['raw_text'] ?? null,
                $validated['link_note'] ?? null,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage())->withInput();
        }

        return redirect()
            ->route('suchak.offline-camps.index', ['camp' => $offlineCamp->id])
            ->with('success', 'Suchak camp intake linked through governed intake pipeline.');
    }

    public function linkSourceLinks(
        Request $request,
        SuchakOfflineCamp $offlineCamp,
        SuchakOfflineCampService $campService,
    ): RedirectResponse {
        $validated = $request->validate([
            'source_link_ids' => ['required', 'array', 'min:1'],
            'source_link_ids.*' => ['integer', 'exists:suchak_biodata_intake_links,id'],
            'link_note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $campService->linkExistingSourceLinks(
                $offlineCamp,
                $request->user(),
                $validated['source_link_ids'],
                $validated['link_note'] ?? null,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage())->withInput();
        }

        return redirect()
            ->route('suchak.offline-camps.index', ['camp' => $offlineCamp->id])
            ->with('success', 'Suchak source links attached to camp.');
    }

    public function assignPackage(
        Request $request,
        SuchakOfflineCampIntakeLink $campIntakeLink,
        SuchakOfflineCampService $campService,
    ): RedirectResponse {
        $validated = $request->validate([
            'service_package_id' => ['required', 'integer', 'exists:suchak_service_packages,id'],
            'assignment_note' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        try {
            $assignment = $campService->assignPackage(
                $campIntakeLink,
                SuchakServicePackage::query()->findOrFail((int) $validated['service_package_id']),
                $request->user(),
                $validated,
            );
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage())->withInput();
        }

        return redirect()
            ->route('suchak.offline-camps.index', ['camp' => $assignment->offline_camp_id])
            ->with('success', 'Suchak camp package assignment recorded.');
    }

    public function generateReport(
        Request $request,
        SuchakOfflineCamp $offlineCamp,
        SuchakOfflineCampService $campService,
    ): RedirectResponse {
        $validated = $request->validate([
            'report_note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $campService->generateConversionReport($offlineCamp, $request->user(), $validated['report_note'] ?? null);
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage())->withInput();
        }

        return redirect()
            ->route('suchak.offline-camps.index', ['camp' => $offlineCamp->id])
            ->with('success', 'Suchak camp conversion report generated.');
    }

    private function account(Request $request)
    {
        $account = $request->user()?->suchakAccount;
        if (! $account) {
            abort(403, 'Suchak account is required.');
        }

        return $account;
    }
}
