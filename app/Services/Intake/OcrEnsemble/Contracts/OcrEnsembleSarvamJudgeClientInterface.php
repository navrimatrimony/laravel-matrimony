<?php

namespace App\Services\Intake\OcrEnsemble\Contracts;

use App\Services\Intake\OcrEnsemble\Data\SarvamJudgeRequest;
use App\Services\Intake\OcrEnsemble\Data\SarvamJudgeResponse;

/**
 * Sarvam judge HTTP client — sole network entry point for Phase 4 judge calls.
 */
interface OcrEnsembleSarvamJudgeClientInterface
{
    public function judge(SarvamJudgeRequest $request): SarvamJudgeResponse;
}
