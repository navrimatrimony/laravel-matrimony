<?php

namespace App\Services\Intake\OcrEnsemble\Contracts;

use App\Models\BiodataIntake;

/**
 * Sarvam Document Digitization judge client (wraps existing vision path in later steps).
 */
interface OcrEnsembleSarvamJudgeClientInterface
{
    /**
     * @return array{text: string, meta: array<string, mixed>}
     */
    public function extractForIntake(BiodataIntake $intake): array;
}
