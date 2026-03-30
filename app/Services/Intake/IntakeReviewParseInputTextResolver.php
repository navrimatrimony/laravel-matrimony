<?php

namespace App\Services\Intake;

use App\Models\BiodataIntake;
use App\Services\AiVisionExtractionService;
use App\Services\OcrService;
use App\Services\Parsing\ParserStrategyResolver;
use Illuminate\Support\Facades\Cache;

/**
 * Text shown in user/admin intake review: same pipeline as {@see \App\Jobs\ParseIntakeJob} parse input ($raw).
 * Does not use stored raw_ocr_text as the primary source (upload-time OCR may differ from manual-crop / vision extract).
 */
final class IntakeReviewParseInputTextResolver
{
    public function __construct(
        private ParserStrategyResolver $parserStrategyResolver,
        private OcrService $ocrService,
    ) {}

    /**
     * @return array{
     *     text: string,
     *     source: 'parse_snapshot'|'ai_vision_cache'|'ai_vision_unavailable'|'ocr_transient'|'empty',
     *     provenance: array{heading_key: string, params?: array<string, string>}
     * }
     */
    public function resolve(BiodataIntake $intake): array
    {
        if ($intake->parse_status === 'parsed'
            && is_string($intake->last_parse_input_text)
            && trim($intake->last_parse_input_text) !== '') {
            return [
                'text' => $intake->last_parse_input_text,
                'source' => 'parse_snapshot',
                'provenance' => [
                    'heading_key' => 'intake.preview_source_parse_snapshot',
                    'params' => [],
                ],
            ];
        }

        $mode = $this->parserStrategyResolver->normalizeMode(
            $intake->parser_version ?: $this->parserStrategyResolver->resolveActiveMode()
        );

        if ($mode === ParserStrategyResolver::MODE_AI_VISION_EXTRACT_V1) {
            $cached = Cache::get('intake.parse_input_text.'.$intake->id);
            $dbg = Cache::get('intake.parse_input_debug.'.$intake->id);
            $dbg = is_array($dbg) ? $dbg : [];

            if (is_string($cached) && trim($cached) !== '') {
                return [
                    'text' => $cached,
                    'source' => 'ai_vision_cache',
                    'provenance' => AiVisionExtractionService::provenanceForPreview($dbg),
                ];
            }

            $msg = $this->buildAiVisionUnavailablePanelMessage($dbg, $intake);
            if ($msg === '') {
                $msg = __('intake.preview_parse_input_ai_unavailable');
                if ($dbg !== []
                    && ($dbg['parse_input_source'] ?? '') === 'ai_vision_extract_v1'
                    && ! empty($dbg['ok'])
                    && ! empty($dbg['text_quality_ok'] ?? true)) {
                    $msg = __('intake.preview_parse_input_ai_cache_missing');
                }
            }

            return [
                'text' => $msg,
                'source' => 'ai_vision_unavailable',
                'provenance' => [
                    'heading_key' => 'intake.preview_ai_transcription_unavailable_title',
                    'params' => [],
                ],
            ];
        }

        try {
            $ocrResolved = $this->ocrService->resolveParseInputText($intake);
            $transient = (string) ($ocrResolved['text'] ?? '');
            if (trim($transient) !== '') {
                return [
                    'text' => $transient,
                    'source' => 'ocr_transient',
                    'provenance' => [
                        'heading_key' => 'intake.preview_source_ocr_parse_input',
                        'params' => [],
                    ],
                ];
            }
        } catch (\Throwable) {
        }

        return [
            'text' => '',
            'source' => 'empty',
            'provenance' => [
                'heading_key' => 'intake.preview_source_empty',
                'params' => [],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $dbg
     */
    private function buildAiVisionUnavailablePanelMessage(array $dbg, BiodataIntake $intake): string
    {
        $parts = [];
        if ($dbg !== []) {
            if (! empty($dbg['failure_detail'])) {
                $parts[] = (string) $dbg['failure_detail'];
            }
            if (! empty($dbg['quality_failure_detail'])) {
                $parts[] = (string) $dbg['quality_failure_detail'];
            }
        }
        $le = trim((string) ($intake->last_error ?? ''));
        if ($le !== '' && $parts === []) {
            $parts[] = __('intake.preview_ai_transcription_failed_code', ['code' => $le]);
        }

        return implode("\n\n", array_filter($parts));
    }
}
