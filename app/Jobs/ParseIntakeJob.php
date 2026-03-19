<?php

namespace App\Jobs;

use App\Models\AdminSetting;
use App\Models\BiodataIntake;
use App\Services\Parsing\ParserStrategyResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| ParseIntakeJob — Phase-5 SSOT: parse only, never modify raw_ocr_text
|--------------------------------------------------------------------------
|
| 1) Fetch intake. 2) If parse_status != pending return. 3) Parse raw_ocr_text
| via BiodataParserService. 4) Wrap to SSOT structure. 5) Store parsed_json.
| Do NOT touch raw_ocr_text. Do NOT recalculate OCR.
|
| When forceRecompute is true (e.g. admin reparse), content_hash cache is
| bypassed so updated parser code runs and parsed_json is recomputed.
|
*/
class ParseIntakeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $intakeId,
        public bool $forceRecompute = false
    ) {
    }

    /**
     * Execute: parse via BiodataParserService, store SSOT-compliant parsed_json only.
     */
    public function handle(): void
    {
        $intake = BiodataIntake::find($this->intakeId);

        Log::info('ParseIntakeJob::handle() started', [
            'intake_id' => $this->intakeId,
            'forceRecompute' => $this->forceRecompute,
            'parse_status_before' => $intake?->parse_status,
            'intake_found' => $intake !== null,
        ]);

        if ($intake === null) {
            return;
        }

        // Do not reparse approved/locked intakes.
        if ($intake->approved_by_user || $intake->intake_locked) {
            Log::info('ParseIntakeJob::handle() early return: approved or locked', ['intake_id' => $this->intakeId]);
            return;
        }

        if ($intake->parse_status !== 'pending') {
            Log::info('ParseIntakeJob::handle() early return: parse_status not pending', [
                'intake_id' => $this->intakeId,
                'parse_status' => $intake->parse_status,
            ]);
            return;
        }

        $resolver = app(ParserStrategyResolver::class);
        $mode = $resolver->resolveActiveMode();

        // Smart caching — avoid re-parsing identical content for same parser_version.
        // Bypass cache when forceRecompute is true (e.g. admin reparse) so updated parser runs.
        $canonicalVersion = $resolver->normalizeMode($intake->parser_version ?: $mode);
        if (!$this->forceRecompute && $intake->content_hash && $canonicalVersion) {
            $cached = BiodataIntake::where('id', '!=', $intake->id)
                ->where('content_hash', $intake->content_hash)
                ->where('parser_version', $canonicalVersion)
                ->where('parse_status', 'parsed')
                ->first();
            if ($cached && ! empty($cached->parsed_json)) {
                $intake->update([
                    'parsed_json' => $cached->parsed_json,
                    'parse_status' => 'parsed',
                    'parser_version' => $canonicalVersion,
                ]);

                Log::info('Intake parse reused cached parsed_json', [
                    'intake_id' => $intake->id,
                    'parser_version' => $canonicalVersion,
                ]);

                return;
            }
        }

        $retryLimit = (int) AdminSetting::getValue('intake_parse_retry_limit', '3');

        $parser = $resolver->makeParser($mode);
        $raw = $intake->raw_ocr_text ?? '';
        $raw = \App\Services\Ocr\OcrNormalize::normalizeRawTextForParsing($raw);

        $parsed = null;
        $attempts = 0;
        $lastException = null;
        $aiCalls = 0; // Reserved for future detailed AI tracking
        $start = microtime(true);

        while ($attempts === 0 || ($attempts < $retryLimit && $parsed === null && $lastException !== null)) {
            $attempts++;
            try {
                $parsed = $parser->parse($raw, [
                    'intake_id' => $intake->id,
                    'parser_mode' => $mode,
                ]);
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

            Log::error('Intake parse failed', [
                'intake_id' => $intake->id,
                'parser_mode' => $mode,
                'error' => $lastException?->getMessage(),
            ]);

            return;
        }

        // At this point parsers are already required to return SSOT-compatible shape.
        $ssot = $parsed;

        $intake->update([
            'parsed_json' => $ssot,
            'parse_status' => 'parsed',
            'parser_version' => $canonicalVersion,
            'parse_duration_ms' => $durationMs,
            'ai_calls_used' => $aiCalls,
        ]);

        Log::info('Intake parsed successfully', [
            'intake_id' => $intake->id,
            'parser_mode' => $mode,
            'parser_version' => $canonicalVersion,
            'contacts_count' => is_countable($ssot['contacts'] ?? []) ? count($ssot['contacts']) : 0,
            'education_count' => is_countable($ssot['education_history'] ?? []) ? count($ssot['education_history']) : 0,
            'career_count' => is_countable($ssot['career_history'] ?? []) ? count($ssot['career_history']) : 0,
            'relatives_count' => is_countable($ssot['relatives'] ?? []) ? count($ssot['relatives']) : 0,
            'siblings_count' => is_countable($ssot['siblings'] ?? []) ? count($ssot['siblings']) : 0,
            'addresses_count' => is_countable($ssot['addresses'] ?? []) ? count($ssot['addresses']) : 0,
            'duration_ms' => $durationMs,
        ]);
    }

}

