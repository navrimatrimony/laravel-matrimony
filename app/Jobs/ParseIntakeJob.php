<?php

namespace App\Jobs;

use App\Models\BiodataIntake;
use App\Services\BiodataParserService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/*
|--------------------------------------------------------------------------
| ParseIntakeJob — Phase-5 SSOT: parse only, never modify raw_ocr_text
|--------------------------------------------------------------------------
|
| 1) Fetch intake. 2) If parse_status != pending return. 3) Parse raw_ocr_text
| via BiodataParserService. 4) Wrap to SSOT structure. 5) Store parsed_json.
| Do NOT touch raw_ocr_text. Do NOT recalculate OCR.
|
*/
class ParseIntakeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $intakeId)
    {
    }

    /**
     * Execute: parse via BiodataParserService, store SSOT-compliant parsed_json only.
     */
    public function handle(): void
    {
        $intake = BiodataIntake::find($this->intakeId);

        if ($intake === null) {
            return;
        }

        if ($intake->parse_status !== 'pending') {
            return;
        }

        $parser = app(BiodataParserService::class);
        $parsed = $parser->parse($intake->raw_ocr_text ?? '');
        $ssot = isset($parsed['core'], $parsed['confidence_map']) ? $parsed : $this->wrapToSsot($parsed);

        $intake->update([
            'parsed_json' => $ssot,
            'parse_status' => 'parsed',
        ]);
    }

    /**
     * Map legacy flat output to Phase-5 SSOT (fallback when parser returns flat).
     */
    private function wrapToSsot(array $flat): array
    {
        $salary = $flat['salary'] ?? [];
        $annualIncome = null;
        if (isset($salary['annual_lakh'])) {
            $annualIncome = (int) $salary['annual_lakh'] * 100000;
        } elseif (isset($salary['monthly'])) {
            $annualIncome = (int) $salary['monthly'] * 12;
        }

        $core = [
            'full_name' => $flat['full_name'] ?? null,
            'date_of_birth' => $flat['birth_date'] ?? null,
            'gender' => null,
            'religion' => null,
            'caste' => null,
            'sub_caste' => null,
            'marital_status' => null,
            'annual_income' => $annualIncome,
            'family_income' => null,
            'primary_contact_number' => null,
            'serious_intent_id' => null,
        ];

        $confidenceMap = [];
        $coreKeys = array_keys($core);
        foreach ($coreKeys as $key) {
            $confidenceMap[$key] = isset($core[$key]) && $core[$key] !== null && $core[$key] !== '' ? 0.9 : 0.0;
        }

        return [
            'core' => $core,
            'contacts' => [],
            'children' => [],
            'education_history' => [],
            'career_history' => [],
            'addresses' => [],
            'property_summary' => [],
            'property_assets' => [],
            'horoscope' => [],
            'legal_cases' => [],
            'preferences' => [],
            'extended_narrative' => [],
            'confidence_map' => $confidenceMap,
        ];
    }
}
