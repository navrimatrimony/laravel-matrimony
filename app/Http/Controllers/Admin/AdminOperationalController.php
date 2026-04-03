<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ConflictRecord;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\ConflictResolutionService;
use App\Services\OcrGovernanceService;
use App\Services\OcrMode;
use App\Services\OcrModeDetectionService;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| Admin operational utilities: notifications debug, conflict records UI, OCR simulation.
| (moved from AdminController, Phase 2)
|--------------------------------------------------------------------------
*/
class AdminOperationalController extends Controller
{
    /**
     * Admin debug: view notifications for any user (R5).
     * Form to enter user ID, then view that user's notifications (read-only).
     */
    public function userNotificationsIndex()
    {
        return view('admin.notifications.index');
    }

    /**
     * Admin debug: list notifications for user (user_id query). Read-only, no actions.
     */
    public function userNotificationsShow(Request $request)
    {
        $request->validate(['user_id' => 'required|integer|min:1']);
        $user = User::findOrFail($request->user_id);
        $notifications = $user->notifications()->orderByDesc('created_at')->paginate(50)->withQueryString();

        return view('admin.notifications.user', [
            'targetUser' => $user,
            'notifications' => $notifications,
        ]);
    }

    /**
     * Phase-3 Day-4: Conflict records list (read-only).
     */
    public function conflictRecordsIndex()
    {
        $pendingCount = ConflictRecord::where('resolution_status', 'PENDING')->count();
        $approvedCount = ConflictRecord::where('resolution_status', 'APPROVED')->count();
        $rejectedCount = ConflictRecord::where('resolution_status', 'REJECTED')->count();

        $records = ConflictRecord::with('profile')
            ->where('resolution_status', 'PENDING')
            ->orderByDesc('detected_at')
            ->paginate(50);

        return view('admin.conflict-records.index', compact(
            'records',
            'pendingCount',
            'approvedCount',
            'rejectedCount',
        ));
    }

    /**
     * Phase-5 Day-20: Conflict record show — detailed diff, profile context, resolution form.
     */
    public function conflictRecordShow(ConflictRecord $record)
    {
        $profile = MatrimonyProfile::find($record->profile_id);
        $canResolve = $record->resolution_status === 'PENDING' && auth()->user()?->hasAdminRole(['super_admin', 'data_admin']);

        return view('admin.conflict-records.show', compact('record', 'profile', 'canResolve'));
    }

    /**
     * Phase-3 Day-4: Form to create a conflict record manually (testing only).
     */
    public function conflictRecordsCreate()
    {
        $profiles = MatrimonyProfile::withTrashed()->orderBy('id')->get(['id', 'full_name']);

        return view('admin.conflict-records.create', compact('profiles'));
    }

    /**
     * Phase-3 Day-4: Store a conflict record (testing only, minimal validation).
     */
    public function conflictRecordsStore(Request $request)
    {
        $request->validate([
            'profile_id' => ['required', 'exists:matrimony_profiles,id'],
            'field_name' => ['required', 'string', 'max:255'],
            'field_type' => ['required', 'in:CORE,EXTENDED'],
            'old_value' => ['nullable', 'string'],
            'new_value' => ['nullable', 'string'],
            'source' => ['required', 'in:OCR,USER,ADMIN,MATCHMAKER,SYSTEM'],
        ]);

        if (! ConflictRecord::where('profile_id', $request->profile_id)->where('field_name', $request->field_name)->where('resolution_status', 'PENDING')->exists()) {
            ConflictRecord::create([
                'profile_id' => $request->profile_id,
                'field_name' => $request->field_name,
                'field_type' => $request->field_type,
                'old_value' => $request->old_value,
                'new_value' => $request->new_value,
                'source' => $request->source,
                'detected_at' => now(),
                'resolution_status' => 'PENDING',
            ]);
        }

        return redirect()->route('admin.conflict-records.index')->with('success', 'Conflict record created (testing).');
    }

    /**
     * Phase-3 Day-5: Approve conflict (service handles authority + validation).
     * Day-7: Role guard — super_admin, data_admin only.
     */
    public function conflictRecordApprove(Request $request, ConflictRecord $record)
    {
        if (! $request->user()->hasAdminRole(['super_admin', 'data_admin'])) {
            abort(403, 'This action requires super_admin or data_admin role');
        }

        $request->validate(['resolution_reason' => ['required', 'string', 'min:10']]);
        ConflictResolutionService::approveConflict($record, $request->user(), $request->resolution_reason);

        return redirect()->route('admin.conflict-records.show', $record)->with('success', 'Conflict approved.');
    }

    /**
     * Phase-3 Day-5: Reject conflict (service handles authority + validation).
     * Day-7: Role guard — super_admin, data_admin only.
     */
    public function conflictRecordReject(Request $request, ConflictRecord $record)
    {
        if (! $request->user()->hasAdminRole(['super_admin', 'data_admin'])) {
            abort(403, 'This action requires super_admin or data_admin role');
        }

        $request->validate(['resolution_reason' => ['required', 'string', 'min:10']]);
        ConflictResolutionService::rejectConflict($record, $request->user(), $request->resolution_reason);

        return redirect()->route('admin.conflict-records.show', $record)->with('success', 'Conflict rejected.');
    }

    /**
     * Phase-3 Day-5: Override conflict (service handles authority + validation).
     * Day-7: Role guard — super_admin, data_admin only.
     */
    public function conflictRecordOverride(Request $request, ConflictRecord $record)
    {
        if (! $request->user()->hasAdminRole(['super_admin', 'data_admin'])) {
            abort(403, 'This action requires super_admin or data_admin role');
        }

        $request->validate(['resolution_reason' => ['required', 'string', 'min:10']]);
        ConflictResolutionService::overrideConflict($record, $request->user(), $request->resolution_reason);

        return redirect()->route('admin.conflict-records.show', $record)->with('success', 'Conflict overridden.');
    }

    /**
     * Phase-3 Day-14: OCR mode simulation UI (admin-only, testing governance).
     * Shows form to manually select OCR mode and input dummy proposed data.
     */
    public function ocrSimulation()
    {
        if (! auth()->check() || ! auth()->user()->is_admin) {
            abort(403, 'Admin access required');
        }

        $profiles = MatrimonyProfile::orderBy('id')->get(['id', 'full_name']);
        $modes = OcrMode::all();

        return view('admin.ocr-simulation.index', compact('profiles', 'modes'));
    }

    /**
     * Phase-3 Day-14: Execute OCR governance simulation (no persistence).
     * Processes dummy proposed data through governance logic only.
     * Returns decisions (ALLOW/SKIP/CREATE_CONFLICT) — does NOT mutate profile.
     */
    public function ocrSimulationExecute(Request $request)
    {
        if (! auth()->check() || ! auth()->user()->is_admin) {
            abort(403, 'Admin access required');
        }

        $request->validate([
            'ocr_mode' => ['required', 'string', 'in:'.implode(',', OcrMode::all())],
            'profile_id' => ['nullable', 'exists:matrimony_profiles,id'],
            'proposed_core' => ['nullable', 'array'],
            'proposed_extended' => ['nullable', 'array'],
        ]);

        $mode = $request->input('ocr_mode');
        $profileId = $request->input('profile_id');
        $proposedCoreRaw = $request->input('proposed_core', []);
        $proposedExtendedRaw = $request->input('proposed_extended', []);

        // Filter out empty/null values from proposed data
        // SSOT: Only process fields that are explicitly provided with non-empty values
        // Empty form fields should not trigger conflict detection
        $proposedCore = [];
        foreach ($proposedCoreRaw as $key => $value) {
            if ($value !== null && $value !== '' && trim((string) $value) !== '') {
                $proposedCore[$key] = $value;
            }
        }
        $proposedExtended = [];
        foreach ($proposedExtendedRaw as $key => $value) {
            if ($value !== null && $value !== '' && trim((string) $value) !== '') {
                $proposedExtended[$key] = $value;
            }
        }

        $profile = $profileId ? MatrimonyProfile::find($profileId) : null;

        // Get governance decisions (no persistence)
        $decisions = OcrGovernanceService::decideBulk($profile, $proposedCore, $proposedExtended);

        // Get mode per field (for display)
        $fieldModes = [];
        foreach (array_merge(array_keys($proposedCore), array_keys($proposedExtended)) as $fieldKey) {
            $fieldModes[$fieldKey] = OcrModeDetectionService::detect($profile, $fieldKey);
        }

        // Execute decisions (create conflicts only, no profile mutation)
        $createdConflicts = OcrGovernanceService::executeDecisions($profile, $proposedCore, $proposedExtended);

        return redirect()
            ->route('admin.ocr-simulation.index')
            ->with('simulation_result', [
                'mode' => $mode,
                'profile_id' => $profileId,
                'decisions' => $decisions,
                'field_modes' => $fieldModes,
                'conflicts_created' => count($createdConflicts),
            ])
            ->with('success', 'OCR governance simulation complete. '.count($createdConflicts).' conflict(s) created (if any).');
    }
}
