<?php

namespace App\Jobs;

use App\Models\BiodataIntake;
use App\Services\BiodataParserService;
use App\Models\AdminSetting;
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

        // Day-35: Smart caching — avoid re-parsing identical content for same parser_version.
        if ($intake->content_hash && $intake->parser_version) {
            $cached = BiodataIntake::where('id', '!=', $intake->id)
                ->where('content_hash', $intake->content_hash)
                ->where('parser_version', $intake->parser_version)
                ->where('parse_status', 'parsed')
                ->first();
            if ($cached && ! empty($cached->parsed_json)) {
                $intake->update([
                    'parsed_json' => $cached->parsed_json,
                    'parse_status' => 'parsed',
                ]);
                return;
            }
        }

        $activeParser = AdminSetting::getValue('intake_active_parser', 'rules_only');
        $retryLimit = (int) AdminSetting::getValue('intake_parse_retry_limit', '3');

        $parser = app(BiodataParserService::class);
        $raw = $intake->raw_ocr_text ?? '';

        $parsed = null;
        $attempts = 0;
        $lastException = null;
        $aiCalls = 0;
        $start = microtime(true);

        // Simple retry loop; future versions can branch by $activeParser.
        while ($attempts === 0 || ($attempts < $retryLimit && $parsed === null && $lastException !== null)) {
            $attempts++;
            try {
                $parsed = $parser->parse($raw);
                // For now, treat this as rules-only parse; AI calls will be wired later.
                $aiCalls = 0;
                $lastException = null;
                break;
            } catch (\Throwable $e) {
                $lastException = $e;
            }
        }

        $durationMs = (int) round((microtime(true) - $start) * 1000);

        if ($parsed === null) {
            $intake->update([
                'parse_status' => 'error',
                'last_error' => $lastException ? substr($lastException->getMessage(), 0, 255) : 'parse_failed',
                'parse_duration_ms' => $durationMs,
                'ai_calls_used' => $aiCalls,
            ]);
            return;
        }

        $ssot = isset($parsed['core'], $parsed['confidence_map']) ? $parsed : $this->wrapToSsot($parsed);

        $intake->update([
            'parsed_json' => $ssot,
            'parse_status' => 'parsed',
            'parse_duration_ms' => $durationMs,
            'ai_calls_used' => $aiCalls,
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
