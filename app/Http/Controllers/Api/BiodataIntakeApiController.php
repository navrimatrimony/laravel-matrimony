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
        ]);
    }

    public function store(Request $request, IntakeCreationService $intakeCreation): JsonResponse
    {
        $validated = $request->validate([
            'raw_text' => ['required', 'string', 'min:20', 'max:60000'],
            'parse_now' => ['nullable', 'boolean'],
        ]);

        $prepared = $intakeCreation->prepare(
            (int) $request->user()->id,
            null,
            $validated['raw_text']
        );

        $intake = $intakeCreation->persistPrepared((int) $request->user()->id, $prepared);

        // Mobile OCR already provides text, so keep this path cost-safe and deterministic.
        $intake->forceFill([
            'parser_version' => ParserStrategyResolver::MODE_RULES_ONLY,
        ])->save();

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
            ]);
        }

        return response()->json([
            'success' => true,
            'ready' => true,
            'intake' => $this->detailPayload($intake),
            'preview' => $this->previewPayload($intake),
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
        $resolverPayload = app(IntakeReviewParseInputTextResolver::class)->resolve($intake);
        $rawText = (string) ($resolverPayload['text'] ?? '');
        $source = (string) ($resolverPayload['source'] ?? '');
        $isBiodataText = in_array($source, ['parse_snapshot', 'ai_vision_cache', 'ocr_transient'], true);

        return [
            'parsed_snapshot' => is_array($intake->parsed_json) ? $intake->parsed_json : [],
            'approval_snapshot' => is_array($intake->approval_snapshot_json) ? $intake->approval_snapshot_json : null,
            'normalized_draft' => app(IntakePreviewNormalizedDraftPresenter::class)
                ->present($rawText, $isBiodataText, is_array($intake->parsed_json) ? $intake->parsed_json : null),
            'raw_text' => $rawText,
            'source' => $source,
            'provenance' => $resolverPayload['provenance'] ?? null,
        ];
    }

    private function notFoundResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Biodata intake not found',
        ], 404);
    }
}
