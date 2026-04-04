<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MatrimonyProfile;
use App\Services\OcrGovernanceService;
use App\Services\OcrMode;
use App\Services\OcrModeDetectionService;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| Day-14: OCR mode simulation (governance testing only, no OCR engine).
|--------------------------------------------------------------------------
*/
class AdminOcrSimulationController extends Controller
{
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
