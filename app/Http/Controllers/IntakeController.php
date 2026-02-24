<?php

namespace App\Http\Controllers;

use App\Jobs\ParseIntakeJob;
use App\Models\BiodataIntake;
use App\Services\IntakeApprovalService;
use App\Services\OcrService;
use App\Services\Preview\PreviewSectionMapper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| IntakeController — Phase-5 User-Side Intake UI Foundation
|--------------------------------------------------------------------------
|
| User-side intake flow: upload form, preview, approval, status.
| SSOT: OCR runs BEFORE intake creation; raw_ocr_text set at insert only.
|
*/
class IntakeController extends Controller
{
    /**
     * Show upload form.
     */
    public function uploadForm()
    {
        return view('intake.upload');
    }

    /**
     * Phase-5 Day-18 Step-1: OCR before create. Store biodata intake with final raw_ocr_text.
     * ParseIntakeJob only parses; does not modify raw_ocr_text.
     */
    public function store(Request $request, OcrService $ocrService)
    {
        $request->validate([
            'raw_text' => ['nullable', 'string', 'required_without:file'],
            'file' => ['nullable', 'file', 'max:10240', 'required_without:raw_text'],
        ]);

        $path = null;
        $originalName = null;
        $rawText = null;

        if ($request->hasFile('file')) {
            $path = $request->file('file')->store('intakes');
            $originalName = $request->file('file')->getClientOriginalName();
            try {
                $rawText = $ocrService->extractTextFromPath($path, $originalName);
            } catch (\Throwable $e) {
                return redirect()->back()
                    ->withErrors(['file' => 'OCR extraction failed. ' . $e->getMessage()])
                    ->withInput();
            }
        } else {
            $rawText = $request->input('raw_text', '');
        }

        $intake = null;
        DB::transaction(function () use ($path, $originalName, $rawText, &$intake) {
            $intake = BiodataIntake::create([
                'uploaded_by' => auth()->id(),
                'file_path' => $path,
                'original_filename' => $originalName,
                'raw_ocr_text' => $rawText,
                'intake_status' => 'uploaded',
                'parse_status' => 'pending',
                'approved_by_user' => false,
                'intake_locked' => false,
                'snapshot_schema_version' => 1,
            ]);
        });

        ParseIntakeJob::dispatch($intake->id);

        return redirect()->route('intake.status')->with('success', 'Intake uploaded successfully.');
    }

    /**
     * Show preview. Phase-5 Day-19: Editable snapshot, confidence enforcement, lifecycle transition.
     * When parse_status = 'parsed' and intake has linked profile: transition profile to awaiting_user_approval.
     * No profile mutation in preview; only biodata_intakes may be modified later on approve.
     */
    public function preview(BiodataIntake $intake)
    {
        if ($intake->parse_status !== 'parsed') {
            abort(403);
        }

        $data = $intake->parsed_json;
        if (empty($data) || !is_array($data)) {
            abort(400);
        }

        $mapper = new PreviewSectionMapper();
        $sections = $mapper->map($data);

        $confidenceMap = $data['confidence_map'] ?? [];
        if (!is_array($confidenceMap)) {
            $confidenceMap = [];
        }

        $criticalFields = [
            'full_name',
            'date_of_birth',
            'gender',
            'religion',
            'caste',
            'sub_caste',
            'marital_status',
            'annual_income',
            'family_income',
            'primary_contact_number',
            'serious_intent_id',
        ];

        $requiredCorrectionFields = []; // confidence < 0.50
        $warningFields = []; // confidence < 0.75
        foreach ($confidenceMap as $field => $conf) {
            $c = (float) $conf;
            if ($c < 0.50) {
                $requiredCorrectionFields[] = $field;
            } elseif ($c < 0.75) {
                $warningFields[] = $field;
            }
        }

        $coreData = $sections['core']['data'] ?? [];
        $missingCriticalFields = [];
        foreach ($criticalFields as $field) {
            $val = $coreData[$field] ?? null;
            if ($val === null || $val === '') {
                $missingCriticalFields[] = $field;
            }
        }

        // Day-19: When preview loaded and intake has linked profile with state 'parsed' → awaiting_user_approval
        if ($intake->matrimony_profile_id) {
            $profile = \App\Models\MatrimonyProfile::find($intake->matrimony_profile_id);
            if ($profile && (($profile->lifecycle_state ?? '') === 'parsed')) {
                try {
                    \App\Services\ProfileLifecycleService::transitionTo($profile, 'awaiting_user_approval', auth()->user());
                } catch (\Throwable $e) {
                    // Log but do not block preview
                    report($e);
                }
            }
        }

        session(['preview_seen_' . $intake->id => true]);

        $sectionSourceKeys = [
            'core' => 'core',
            'contacts' => 'contacts',
            'children' => 'children',
            'education' => 'education_history',
            'career' => 'career_history',
            'addresses' => 'addresses',
            'property_summary' => 'property_summary',
            'property_assets' => 'property_assets',
            'horoscope' => 'horoscope',
            'legal_cases' => 'legal_cases',
            'preferences' => 'preferences',
            'narrative' => 'extended_narrative',
        ];

        return view('intake.preview', compact(
            'intake',
            'sections',
            'confidenceMap',
            'criticalFields',
            'missingCriticalFields',
            'requiredCorrectionFields',
            'warningFields',
            'data',
            'sectionSourceKeys'
        ));
    }

    /**
     * Approve intake. Uses edited snapshot from form when present; else parsed_json.
     * No profile update here; IntakeApprovalService only updates biodata_intakes.
     */
    public function approve(Request $request, BiodataIntake $intake)
    {
        if (!session('preview_seen_' . $intake->id)) {
            abort(403);
        }

        $snapshot = $request->input('snapshot');
        if (is_array($snapshot)) {
            $base = is_array($intake->parsed_json) ? $intake->parsed_json : [];
            $snapshot = $this->normalizeApprovalSnapshot(array_merge($base, $snapshot));
        } else {
            $snapshot = null;
        }

        $result = app(IntakeApprovalService::class)->approve($intake, (int) auth()->id(), $snapshot);
        return redirect()->route('intake.status')
            ->with('success', 'Intake approved successfully.')
            ->with('mutation_result', $result);
    }

    /**
     * Ensure snapshot has SSOT top-level keys (all present, empty array when missing).
     */
    private function normalizeApprovalSnapshot(array $snapshot): array
    {
        $keys = [
            'core', 'contacts', 'children', 'education_history', 'career_history',
            'addresses', 'property_summary', 'property_assets', 'horoscope',
            'legal_cases', 'preferences', 'extended_narrative', 'confidence_map',
        ];
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = isset($snapshot[$k]) && is_array($snapshot[$k]) ? $snapshot[$k] : [];
        }
        return $out;
    }

    /**
     * Show approval.
     */
    public function approval()
    {
        return view('intake.approval');
    }

    /**
     * Show status.
     */
    public function status()
    {
        return view('intake.status');
    }
}
