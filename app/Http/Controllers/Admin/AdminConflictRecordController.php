<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ConflictRecord;
use App\Models\MatrimonyProfile;
use App\Services\ConflictResolutionService;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| Admin conflict records UI.
| (moved from AdminController, Phase 2)
|--------------------------------------------------------------------------
*/
class AdminConflictRecordController extends Controller
{
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
}
