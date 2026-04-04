<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BiodataIntake;
use App\Models\ConflictRecord;
use App\Models\FieldRegistry;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\ConflictResolutionService;
use App\Services\Core\ConflictPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

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

        $records = ConflictRecord::with([
            'profile' => static fn ($q) => $q->select('id', 'full_name', 'lifecycle_state'),
        ])
            ->where('resolution_status', 'PENDING')
            ->orderByDesc('detected_at')
            ->paginate(50);

        $fieldLabelMap = $this->buildConflictFieldLabelMap(collect($records->items()));

        return view('admin.conflict-records.index', compact(
            'records',
            'pendingCount',
            'approvedCount',
            'rejectedCount',
            'fieldLabelMap',
        ));
    }

    /**
     * Phase-5 Day-20: Conflict record show — detailed diff, profile context, resolution form.
     */
    public function conflictRecordShow(ConflictRecord $record)
    {
        $profile = MatrimonyProfile::query()
            ->whereKey($record->profile_id)
            ->first(['id', 'full_name', 'lifecycle_state']);

        $canResolve = $record->resolution_status === 'PENDING' && auth()->user()?->hasAdminRole(['super_admin', 'data_admin']);

        $fieldLabelMap = $this->buildConflictFieldLabelMap(collect([$record]));
        $fieldDisplayLabel = $this->conflictFieldDisplayLabel($record->field_name, $record->field_type, $fieldLabelMap);

        $latestIntake = null;
        if ($record->profile_id) {
            $latestIntake = BiodataIntake::query()
                ->where('matrimony_profile_id', $record->profile_id)
                ->orderByDesc('id')
                ->first(['id']);
        }

        $recentMutationLog = $record->profile_id
            ? $this->recentProfileChangeHistoryForProfile((int) $record->profile_id, 15)
            : [];

        return view('admin.conflict-records.show', compact(
            'record',
            'profile',
            'canResolve',
            'fieldDisplayLabel',
            'latestIntake',
            'recentMutationLog',
        ));
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
            ConflictPolicy::create([
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
     * Map "CORE|field_key" / "EXTENDED|field_key" → FieldRegistry display_label (read-only UI).
     */
    private function buildConflictFieldLabelMap(Collection $records): array
    {
        $byType = ['CORE' => [], 'EXTENDED' => []];
        foreach ($records as $r) {
            $type = $r->field_type;
            if (isset($byType[$type])) {
                $byType[$type][] = $r->field_name;
            }
        }

        $map = [];
        foreach (['CORE', 'EXTENDED'] as $type) {
            $keys = array_values(array_unique(array_filter($byType[$type])));
            if ($keys === []) {
                continue;
            }
            $labels = FieldRegistry::query()
                ->where('field_type', $type)
                ->whereIn('field_key', $keys)
                ->pluck('display_label', 'field_key');
            foreach ($labels as $key => $label) {
                if ($label !== null && $label !== '') {
                    $map[$type.'|'.$key] = (string) $label;
                }
            }
        }

        return $map;
    }

    private function conflictFieldDisplayLabel(string $fieldName, string $fieldType, array $labelMap): string
    {
        return $labelMap[$fieldType.'|'.$fieldName]
            ?? Str::headline(str_replace('_', ' ', $fieldName));
    }

    /**
     * @return list<array{changed_at:mixed,field_name:string,old_value:?string,new_value:?string,source:?string,actor:?string}>
     */
    private function recentProfileChangeHistoryForProfile(int $profileId, int $limit): array
    {
        if (! Schema::hasTable('profile_change_history')) {
            return [];
        }

        $limit = max(1, min(20, $limit));

        $rows = DB::table('profile_change_history')
            ->where('profile_id', $profileId)
            ->orderByDesc('changed_at')
            ->limit($limit)
            ->get(['field_name', 'old_value', 'new_value', 'source', 'changed_by', 'changed_at']);

        if ($rows->isEmpty()) {
            return [];
        }

        $ids = $rows->pluck('changed_by')->filter()->unique()->values()->all();
        $names = $ids === []
            ? []
            : User::query()->whereIn('id', $ids)->pluck('name', 'id')->all();

        return $rows->map(static function ($r) use ($names): array {
            $by = $r->changed_by;

            return [
                'changed_at' => $r->changed_at,
                'field_name' => (string) $r->field_name,
                'old_value' => $r->old_value !== null ? (string) $r->old_value : null,
                'new_value' => $r->new_value !== null ? (string) $r->new_value : null,
                'source' => $r->source !== null ? (string) $r->source : null,
                'actor' => $by ? (string) ($names[$by] ?? ('user #'.$by)) : null,
            ];
        })->all();
    }
}
