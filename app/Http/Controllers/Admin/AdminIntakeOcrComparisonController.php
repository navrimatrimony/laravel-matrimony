<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BiodataIntake;
use App\Services\Intake\IntakeOcrEnsemblePhase5Service;
use App\Services\Intake\OcrEnsemble\Data\Phase5ComparisonResult;

/**
 * Phase 5e — admin OCR comparison request (placeholder view; full UI in a later step).
 *
 * Read-only. Delegates entirely to IntakeOcrEnsemblePhase5Service.
 */
class AdminIntakeOcrComparisonController extends Controller
{
    public function __construct(
        private readonly IntakeOcrEnsemblePhase5Service $phase5Service,
    ) {}

    public function show(BiodataIntake $intake)
    {
        $comparisonResult = $this->phase5Service->buildComparisonForIntake($intake);

        return view('admin.intake.ocr-comparison', [
            'intake' => $intake,
            'comparisonResult' => $comparisonResult,
            'comparisonPayload' => $this->payload($comparisonResult),
        ]);
    }

    /**
     * @return array{outcome: string, reason: string, table: array<string, mixed>|null}
     */
    private function payload(Phase5ComparisonResult $result): array
    {
        return [
            'outcome' => $result->outcome,
            'reason' => $result->reason,
            'table' => $result->table?->toArray(),
        ];
    }
}
