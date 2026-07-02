<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ParseIntakeJob;
use App\Models\AdminSetting;
use App\Models\BiodataIntake;
use App\Services\Intake\IntakeCreationService;
use App\Services\Intake\IntakePreviewNormalizedDraftPresenter;
use App\Services\Intake\IntakeReviewParseInputTextResolver;
use App\Services\IntakeApprovalService;
use App\Services\Parsing\ParserStrategyResolver;
use App\Services\Parsing\ProviderResolver;
use App\Services\Preview\PreviewSectionMapper;
use App\Services\ProfileForm\ProfileFormSectionSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BiodataIntakeApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $intakes = BiodataIntake::query()
            ->where('uploaded_by', $request->user()->id)
            ->latest()
            ->limit(25)
            ->get()
            ->map(fn (BiodataIntake $intake): array => $this->summaryPayload($intake))
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'intakes' => $intakes,
            'data' => $intakes,
            'intake_settings' => $this->intakeSettingsPayload(),
        ]);
    }

    public function store(Request $request, IntakeCreationService $intakeCreation): JsonResponse
    {
        $mobileMode = $this->mobileBiodataSourceMode();
        $usesLaravelPipeline = $mobileMode === 'laravel_pipeline';

        $validated = $request->validate([
            'raw_text' => $usesLaravelPipeline
                ? ['nullable', 'string', 'min:20', 'max:60000', 'required_without:file']
                : ['required', 'string', 'min:20', 'max:60000'],
            'file' => $usesLaravelPipeline
                ? ['nullable', 'file', 'max:20480', 'required_without:raw_text']
                : ['prohibited'],
            'parse_now' => ['nullable', 'boolean'],
        ]);

        $file = $usesLaravelPipeline ? $request->file('file') : null;
        if (is_array($file)) {
            $file = null;
        }
        $rawText = array_key_exists('raw_text', $validated) ? (string) $validated['raw_text'] : null;

        $prepared = $intakeCreation->prepare(
            (int) $request->user()->id,
            $file,
            $rawText
        );

        $intake = $intakeCreation->persistPrepared((int) $request->user()->id, $prepared);

        if (! $usesLaravelPipeline) {
            // Mobile OCR already provides text, so keep this path cost-safe and deterministic.
            $intake->forceFill([
                'parser_version' => ParserStrategyResolver::MODE_RULES_ONLY,
            ])->save();
        }

        if (AdminSetting::getBool('intake_auto_parse_enabled', true)) {
            if ($request->boolean('parse_now', true)) {
                (new ParseIntakeJob((int) $intake->id))->handle();
            } else {
                ParseIntakeJob::dispatch((int) $intake->id);
            }
            $intake->refresh();
        }

        return response()->json([
            'success' => true,
            'message' => __('intake.uploaded_successfully'),
            'intake' => $this->detailPayload($intake),
            'preview' => $intake->parse_status === 'parsed' ? $this->previewPayload($intake) : null,
            'intake_settings' => $this->intakeSettingsPayload(),
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $intake = $this->findOwnedIntake($request, $id);
        if (! $intake) {
            return $this->notFoundResponse();
        }

        return response()->json([
            'success' => true,
            'intake' => $this->detailPayload($intake),
            'data' => $this->detailPayload($intake),
            'intake_settings' => $this->intakeSettingsPayload(),
        ]);
    }

    public function preview(Request $request, int $id): JsonResponse
    {
        $intake = $this->findOwnedIntake($request, $id);
        if (! $intake) {
            return $this->notFoundResponse();
        }

        if ($intake->parse_status !== 'parsed') {
            return response()->json([
                'success' => true,
                'ready' => false,
                'intake' => $this->detailPayload($intake),
                'preview' => null,
                'intake_settings' => $this->intakeSettingsPayload(),
            ]);
        }

        return response()->json([
            'success' => true,
            'ready' => true,
            'intake' => $this->detailPayload($intake),
            'preview' => $this->previewPayload($intake),
            'intake_settings' => $this->intakeSettingsPayload(),
        ]);
    }

    public function approve(Request $request, int $id, IntakeApprovalService $approvalService): JsonResponse
    {
        $intake = $this->findOwnedIntake($request, $id);
        if (! $intake) {
            return $this->notFoundResponse();
        }

        $validated = $request->validate([
            'snapshot' => ['required', 'array'],
        ]);

        $result = $approvalService->approve(
            $intake,
            (int) $request->user()->id,
            $validated['snapshot']
        );

        $intake->refresh();

        return response()->json([
            'success' => true,
            'message' => __('intake.approved_successfully'),
            'intake' => $this->detailPayload($intake),
            'result' => $result,
            'intake_settings' => $this->intakeSettingsPayload(),
        ]);
    }

    private function findOwnedIntake(Request $request, int $id): ?BiodataIntake
    {
        return BiodataIntake::query()
            ->where('uploaded_by', $request->user()->id)
            ->whereKey($id)
            ->first();
    }

    private function summaryPayload(BiodataIntake $intake): array
    {
        return [
            'id' => $intake->id,
            'intake_status' => $intake->intake_status,
            'parse_status' => $intake->parse_status,
            'approved_by_user' => (bool) $intake->approved_by_user,
            'intake_locked' => (bool) $intake->intake_locked,
            'created_at' => optional($intake->created_at)->toISOString(),
            'parsed_at' => optional($intake->parsed_at)->toISOString(),
            'last_error' => $intake->last_error,
            'source_type' => trim((string) ($intake->file_path ?? '')) !== '' ? 'file' : 'raw_text',
            'source_label' => $this->sourceLabel($intake),
        ];
    }

    private function detailPayload(BiodataIntake $intake): array
    {
        return array_merge($this->summaryPayload($intake), [
            'ready_for_review' => $intake->parse_status === 'parsed' && ! $intake->approved_by_user,
            'can_apply' => $intake->parse_status === 'parsed' && ! $intake->intake_locked,
            'parser_version' => $intake->parser_version,
            'fields_auto_filled_count' => $intake->fields_auto_filled_count,
            'fields_manually_edited_count' => $intake->fields_manually_edited_count,
        ]);
    }

    private function previewPayload(BiodataIntake $intake): array
    {
        $parsedSnapshot = is_array($intake->parsed_json) ? $intake->parsed_json : [];
        $approvalSnapshot = is_array($intake->approval_snapshot_json) ? $intake->approval_snapshot_json : null;
        $reviewSnapshot = $this->reviewSnapshotForDisplay($parsedSnapshot, $approvalSnapshot);
        $resolverPayload = app(IntakeReviewParseInputTextResolver::class)->resolve($intake);
        $rawText = (string) ($resolverPayload['text'] ?? '');
        $source = (string) ($resolverPayload['source'] ?? '');
        $isBiodataText = in_array($source, ['parse_snapshot', 'ai_vision_cache', 'ocr_transient'], true);
        $reviewSections = (new PreviewSectionMapper)->map($reviewSnapshot);
        $parsedJsonSections = (new PreviewSectionMapper)->map($parsedSnapshot);

        return [
            'form_contract_version' => 1,
            'parsed_snapshot' => $parsedSnapshot,
            'approval_snapshot' => $approvalSnapshot,
            'review_snapshot' => $reviewSnapshot,
            'normalized_draft' => app(IntakePreviewNormalizedDraftPresenter::class)
                ->present($rawText, $isBiodataText, $parsedSnapshot),
            'review_sections' => $reviewSections,
            'parsed_json_sections' => $parsedJsonSections,
            'editable_form_sections' => $this->editableFormSectionsPayload(),
            'review_requirements' => $this->reviewRequirementsPayload($reviewSnapshot, $parsedSnapshot),
            'raw_text' => $rawText,
            'source' => $source,
            'provenance' => $resolverPayload['provenance'] ?? null,
            'debug' => $this->debugPayload($intake, $rawText, $source),
            'intake_settings' => $this->intakeSettingsPayload(),
        ];
    }

    /**
     * Web preview shows approval_snapshot_json core values when present. Keep the
     * mobile review snapshot aligned without changing stored parsed_json.
     *
     * @param  array<string, mixed>  $parsedSnapshot
     * @param  array<string, mixed>|null  $approvalSnapshot
     * @return array<string, mixed>
     */
    private function reviewSnapshotForDisplay(array $parsedSnapshot, ?array $approvalSnapshot): array
    {
        if (! is_array($approvalSnapshot)) {
            return $parsedSnapshot;
        }

        $snapshot = $parsedSnapshot;
        foreach ($approvalSnapshot as $key => $value) {
            if ($key === 'snapshot_schema_version') {
                continue;
            }
            $snapshot[$key] = $value;
        }

        return $snapshot;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function editableFormSectionsPayload(): array
    {
        return array_map(
            function (array $section): array {
                $labelKey = (string) ($section['label'] ?? '');

                return [
                    'key' => (string) $section['key'],
                    'label' => $labelKey !== '' ? __($labelKey) : (string) $section['key'],
                    'label_key' => $labelKey,
                    'editable' => (bool) ($section['editable'] ?? false),
                    'surface' => (string) ($section['surface'] ?? 'shared'),
                    'display_order' => (int) ($section['display_order'] ?? 0),
                    'in_full_form' => (bool) ($section['in_full_form'] ?? false),
                ];
            },
            ProfileFormSectionSchema::fullFormSections()
        );
    }

    /**
     * @param  array<string, mixed>  $reviewSnapshot
     * @param  array<string, mixed>  $parsedSnapshot
     * @return array<string, mixed>
     */
    private function reviewRequirementsPayload(array $reviewSnapshot, array $parsedSnapshot): array
    {
        $criticalFields = [
            'full_name',
            'gender',
            'date_of_birth',
            'religion',
            'caste',
            'sub_caste',
        ];

        $core = is_array($reviewSnapshot['core'] ?? null) ? $reviewSnapshot['core'] : [];
        $requiredCorrectionFields = [];
        foreach ($criticalFields as $field) {
            $value = $core[$field] ?? null;
            $trimmed = is_scalar($value) ? trim((string) $value) : '';
            if ($trimmed === '—' || $trimmed === '-' || $trimmed === '–') {
                $trimmed = '';
            }
            if ($field === 'religion' && $trimmed === '' && ! empty($core['religion_id'])) {
                $trimmed = 'set';
            }
            if ($field === 'caste' && $trimmed === '' && ! empty($core['caste_id'])) {
                $trimmed = 'set';
            }
            if ($field === 'sub_caste' && $trimmed === '' && ! empty($core['sub_caste_id'])) {
                $trimmed = 'set';
            }
            if ($trimmed === '') {
                $requiredCorrectionFields[] = $field;
            }
        }

        $confidenceMap = is_array($parsedSnapshot['confidence_map'] ?? null) ? $parsedSnapshot['confidence_map'] : [];
        $highConfThreshold = (float) AdminSetting::getValue('intake_confidence_high_threshold', '0.85');
        if ($highConfThreshold <= 0 || $highConfThreshold >= 1) {
            $highConfThreshold = 0.85;
        }
        $warningFields = [];
        foreach ($confidenceMap as $field => $confidence) {
            if ((float) $confidence < $highConfThreshold) {
                $warningFields[] = (string) $field;
            }
        }

        return [
            'critical_fields' => $criticalFields,
            'required_correction_fields' => $requiredCorrectionFields,
            'warning_fields' => $warningFields,
            'high_confidence_threshold' => $highConfThreshold,
            'requires_user_confirmation' => true,
        ];
    }

    private function debugPayload(BiodataIntake $intake, string $rawText, string $source): array
    {
        $lines = preg_split('/\R/u', $rawText);

        return [
            'app_debug' => (bool) config('app.debug'),
            'parse_input_source' => $source,
            'parser_version' => $intake->parser_version,
            'mobile_ocr_text_only' => trim((string) ($intake->file_path ?? '')) === '',
            'raw_text_char_count' => mb_strlen($rawText),
            'raw_text_line_count' => is_array($lines) ? count(array_filter($lines, static fn (string $line): bool => trim($line) !== '')) : 0,
        ];
    }

    private function intakeSettingsPayload(): array
    {
        $providerResolver = app(ProviderResolver::class);

        return [
            'auto_parse_enabled' => AdminSetting::getBool('intake_auto_parse_enabled', true),
            'processing_mode' => $providerResolver->processingMode(),
            'primary_ai_provider' => $providerResolver->primaryAiProvider(),
            'ai_vision_provider' => $providerResolver->visionTranscriptionProvider(),
            'use_normalized_draft_parser' => AdminSetting::getBool(
                'intake_use_normalized_draft_parser',
                (bool) config('intake.use_normalized_draft_parser', false)
            ),
            'mobile_biodata_source_mode' => $this->mobileBiodataSourceMode(),
        ];
    }

    private function mobileBiodataSourceMode(): string
    {
        $mode = (string) AdminSetting::getValue('intake_mobile_biodata_source_mode', 'ml_kit');

        return in_array($mode, ['ml_kit', 'laravel_pipeline'], true) ? $mode : 'ml_kit';
    }

    private function sourceLabel(BiodataIntake $intake): string
    {
        $filename = trim((string) ($intake->original_filename ?? ''));
        if ($filename !== '') {
            return $filename;
        }

        return trim((string) ($intake->file_path ?? '')) !== '' ? 'Uploaded file' : 'Mobile OCR text';
    }

    private function notFoundResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Biodata intake not found',
        ], 404);
    }
}
