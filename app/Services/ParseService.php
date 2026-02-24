<?php

namespace App\Services;

use App\Models\BiodataIntake;

/*
|--------------------------------------------------------------------------
| ParseService — Phase-5 Step-2 Foundation
|--------------------------------------------------------------------------
|
| This is Phase-5 Step-2 foundation. Parsing returns a structured array
| from raw_ocr_text only. No AI call yet; no mutation allowed.
| Does NOT modify the intake, MatrimonyProfile, or lifecycle.
|
*/
class ParseService
{
    /**
     * Parse raw OCR text into a structured array.
     * Reads intake only; does not modify intake, call external API, or touch profile/lifecycle.
     *
     * @param  BiodataIntake  $intake
     * @return array{core: array, contacts: array, children: array, education_history: array, career_history: array, confidence_map: array}
     */
    public function parse(BiodataIntake $intake): array
    {
        // Phase-5 Step-2: Read raw text only. No AI call yet. No mutation allowed.
        $rawText = $intake->raw_ocr_text;

        return [
            'core' => [
                'full_name' => null,
                'date_of_birth' => null,
            ],
            'contacts' => [],
            'children' => [],
            'education_history' => [],
            'career_history' => [],
            'confidence_map' => [],
        ];
    }
}
