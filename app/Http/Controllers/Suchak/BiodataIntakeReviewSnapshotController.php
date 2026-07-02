<?php

namespace App\Http\Controllers\Suchak;

use App\Http\Controllers\Controller;
use App\Models\BiodataIntake;
use App\Models\SuchakBiodataIntakeLink;
use App\Models\SuchakProfileRepresentation;
use App\Modules\Suchak\Services\SuchakAccessService;
use App\Services\Intake\IntakeHumanReviewSnapshotService;
use App\Services\Intake\IntakePipelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BiodataIntakeReviewSnapshotController extends Controller
{
    /** @var list<string> */
    private const REVIEW_SNAPSHOT_TOP_LEVEL_KEYS = [
        'snapshot_schema_version',
        'section_order',
        'sectioned',
        'missing_map',
        'core',
        'contacts',
        'birth_place',
        'native_place',
        'children',
        'marriages',
        'education_history',
        'career_history',
        'addresses',
        'parents_addresses',
        'self_addresses',
        'siblings',
        'relatives',
        'relatives_parents_family',
        'relatives_maternal_family',
        'relatives_sectioned',
        'alliance_networks',
        'property_summary',
        'property_assets',
        'horoscope',
        'legal_cases',
        'preferences',
        'extended_narrative',
        'other_relatives_text',
        'confidence_map',
    ];

    /** @var list<string> */
    private const REVIEW_EDITOR_TOP_LEVEL_KEYS = [
        'core',
        'contacts',
        'birth_place',
        'native_place',
        'children',
        'marriages',
        'education_history',
        'career_history',
        'addresses',
        'parents_addresses',
        'self_addresses',
        'siblings',
        'relatives',
        'relatives_parents_family',
        'relatives_maternal_family',
        'relatives_sectioned',
        'alliance_networks',
        'property_summary',
        'property_assets',
        'horoscope',
        'legal_cases',
        'preferences',
        'extended_narrative',
        'other_relatives_text',
    ];

    /** @var array<string, list<string>> */
    private const FIELD_CONFIDENCE_ALIASES = [
        'full_name' => ['full_name', 'name', 'candidate_name'],
        'date_of_birth' => ['date_of_birth', 'dob', 'birth_date'],
        'height' => ['height', 'height_cm', 'height_text'],
        'education' => ['education', 'highest_education', 'degree', 'course_name'],
        'occupation' => ['occupation', 'occupation_title', 'profession', 'designation'],
        'primary_contact_number' => ['primary_contact_number', 'phone_number', 'mobile'],
        'address' => ['address', 'address_line'],
        'religion' => ['religion', 'religion_id', 'religion_label'],
        'caste' => ['caste', 'caste_id', 'caste_label'],
    ];

    public function show(
        Request $request,
        BiodataIntake $intake,
        SuchakAccessService $accessService,
    ): View {
        [, $account] = $this->authorizeSuchakReview($request, $intake, $accessService);
        $qualitySignals = $this->suchakQualitySignals($intake);
        $reviewEditor = $this->suchakReviewSnapshotEditor($intake, $qualitySignals);

        return view('suchak.intakes.review-snapshot', [
            'intake' => $intake,
            'suchakAccount' => $account,
            'reviewEditor' => $reviewEditor,
            'qualitySignals' => $qualitySignals,
        ]);
    }

    public function update(
        Request $request,
        BiodataIntake $intake,
        IntakeHumanReviewSnapshotService $reviewSnapshotService,
        IntakePipelineService $intakePipeline,
        SuchakAccessService $accessService,
    ): JsonResponse|RedirectResponse {
        [$user] = $this->authorizeSuchakReview($request, $intake, $accessService);

        if ((bool) $intake->approved_by_user || (bool) $intake->intake_locked) {
            return $this->errorResponse(
                $request,
                $intake,
                'Reviewed snapshot cannot be edited after approval or lock.',
                422,
            );
        }

        $validated = $request->validate([
            'reviewed_snapshot' => ['required', 'array'],
        ]);

        $submittedSnapshot = $this->filterReviewSnapshot(
            is_array($validated['reviewed_snapshot'] ?? null) ? $validated['reviewed_snapshot'] : []
        );
        if ($submittedSnapshot === []) {
            return $this->errorResponse(
                $request,
                $intake,
                'Reviewed snapshot is empty or contains no supported intake fields.',
                422,
            );
        }

        $baseSnapshot = is_array($intake->approval_snapshot_json)
            ? $intake->approval_snapshot_json
            : (is_array($intake->parsed_json) ? $intake->parsed_json : []);
        $reviewedSnapshot = array_replace_recursive($baseSnapshot, $submittedSnapshot);
        $reviewedSnapshot = $intakePipeline->normalizeSnapshotForStorage(
            $reviewedSnapshot,
            (int) $user->id,
        );

        $saved = $reviewSnapshotService->saveReviewedSnapshot($intake, $reviewedSnapshot, [
            'reviewed_by_user_id' => (int) $user->id,
            'review_actor_type' => IntakeHumanReviewSnapshotService::ACTOR_SUCHAK,
            'review_surface' => IntakeHumanReviewSnapshotService::SURFACE_WEBSITE,
            'approval_policy' => IntakeHumanReviewSnapshotService::POLICY_PHASE2D_SUCHAK_REVIEW_V1,
            'approval_status' => IntakeHumanReviewSnapshotService::STATUS_REVIEWED,
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Reviewed snapshot saved.',
                'intake_id' => (int) $saved->id,
                'approval_status' => $saved->approval_status,
                'review_actor_type' => $saved->review_actor_type,
                'review_surface' => $saved->review_surface,
                'reviewed_at' => optional($saved->reviewed_at)->toISOString(),
                'approval_snapshot' => $saved->approval_snapshot_json,
            ]);
        }

        return redirect()
            ->route('suchak.intakes.review-snapshot.edit', $saved)
            ->with('success', 'Reviewed snapshot saved. Profile data was not modified.');
    }

    /**
     * @return array{0: \App\Models\User, 1: \App\Models\SuchakAccount}
     */
    private function authorizeSuchakReview(
        Request $request,
        BiodataIntake $intake,
        SuchakAccessService $accessService,
    ): array {
        $user = $request->user();
        $account = $user?->suchakAccount;

        abort_unless(
            $user && $account && $accessService->canOwnerPrepareCustomers($account, $user),
            403,
            'Suchak account is not allowed to review this intake.'
        );

        abort_unless(
            $this->suchakCanReviewIntake((int) $account->id, (int) $intake->id),
            403,
            'This biodata intake is not linked to your Suchak account.'
        );

        return [$user, $account];
    }

    private function suchakCanReviewIntake(int $accountId, int $intakeId): bool
    {
        $linked = SuchakBiodataIntakeLink::query()
            ->where('suchak_account_id', $accountId)
            ->where('biodata_intake_id', $intakeId)
            ->where('source_status', '!=', SuchakBiodataIntakeLink::STATUS_CANCELLED)
            ->exists();
        if ($linked) {
            return true;
        }

        return SuchakProfileRepresentation::query()
            ->where('suchak_account_id', $accountId)
            ->where('biodata_intake_id', $intakeId)
            ->whereNotIn('representation_status', [
                SuchakProfileRepresentation::STATUS_REVOKED,
                SuchakProfileRepresentation::STATUS_EXPIRED,
                SuchakProfileRepresentation::STATUS_REJECTED,
                SuchakProfileRepresentation::STATUS_SUSPENDED,
                SuchakProfileRepresentation::STATUS_CANDIDATE_DEACTIVATED,
            ])
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function filterReviewSnapshot(array $snapshot): array
    {
        $allowed = array_flip(self::REVIEW_SNAPSHOT_TOP_LEVEL_KEYS);
        $filtered = [];
        foreach ($snapshot as $key => $value) {
            if (isset($allowed[$key])) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    /**
     * @return array{source: string, snapshot: array<string, mixed>}
     */
    private function suchakReviewSnapshotSource(BiodataIntake $intake): array
    {
        $approvalSnapshot = $intake->approval_snapshot_json;
        if (is_array($approvalSnapshot) && $approvalSnapshot !== []) {
            return [
                'source' => 'approval_snapshot_json',
                'snapshot' => $this->filterEditorReviewSnapshot($approvalSnapshot),
            ];
        }

        $parsedSnapshot = $intake->parsed_json;
        if (is_array($parsedSnapshot) && $parsedSnapshot !== []) {
            return [
                'source' => 'parsed_json',
                'snapshot' => $this->filterEditorReviewSnapshot($parsedSnapshot),
            ];
        }

        return [
            'source' => 'empty',
            'snapshot' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $qualitySignals
     * @return array{
     *     source: string,
     *     available: bool,
     *     can_save: bool,
     *     field_count: int,
     *     sections: list<array{key: string, label: string, fields: list<array<string, mixed>>}>
     * }
     */
    private function suchakReviewSnapshotEditor(BiodataIntake $intake, array $qualitySignals): array
    {
        $source = $this->suchakReviewSnapshotSource($intake);
        $sections = [];
        $fieldCount = 0;

        foreach ($source['snapshot'] as $sectionKey => $sectionValue) {
            $fields = $this->suchakReviewSnapshotFields($sectionValue, [$sectionKey], $qualitySignals);
            if ($fields === []) {
                continue;
            }

            $fieldCount += count($fields);
            $sections[] = [
                'key' => (string) $sectionKey,
                'label' => $this->suchakReviewSnapshotLabel((string) $sectionKey),
                'fields' => $fields,
            ];
        }

        return [
            'source' => $source['source'],
            'available' => $fieldCount > 0,
            'can_save' => ! (bool) $intake->approved_by_user && ! (bool) $intake->intake_locked,
            'field_count' => $fieldCount,
            'sections' => $sections,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function filterEditorReviewSnapshot(array $snapshot): array
    {
        $allowed = array_flip(self::REVIEW_EDITOR_TOP_LEVEL_KEYS);
        $filtered = [];
        foreach ($snapshot as $key => $value) {
            if (isset($allowed[$key])) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    /**
     * @param  list<string|int>  $path
     * @param  array<string, mixed>  $qualitySignals
     * @return list<array<string, mixed>>
     */
    private function suchakReviewSnapshotFields(mixed $value, array $path, array $qualitySignals): array
    {
        if (is_array($value)) {
            $fields = [];
            foreach ($value as $key => $nestedValue) {
                $fields = array_merge(
                    $fields,
                    $this->suchakReviewSnapshotFields($nestedValue, array_merge($path, [$key]), $qualitySignals)
                );
            }

            return $fields;
        }

        if (! $this->hasSuchakReviewDisplayValue($value)) {
            return [];
        }

        $formValue = $this->suchakReviewFormValue($value);
        $leaf = end($path);
        $pathKey = implode('.', array_map('strval', $path));

        return [[
            'name' => $this->suchakReviewInputName($path),
            'old_key' => 'reviewed_snapshot.'.$pathKey,
            'path' => $pathKey,
            'label' => $this->suchakReviewSnapshotLabel((string) $leaf),
            'value' => $formValue,
            'multiline' => str_contains($formValue, "\n") || mb_strlen($formValue) > 90,
            'confidence' => $this->confidenceSignalForPath($path, $qualitySignals),
        ]];
    }

    private function hasSuchakReviewDisplayValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_bool($value) || is_int($value) || is_float($value)) {
            return true;
        }
        if (is_string($value)) {
            return trim($value) !== '';
        }

        return false;
    }

    private function suchakReviewFormValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return trim((string) $value);
    }

    /**
     * @param  list<string|int>  $path
     */
    private function suchakReviewInputName(array $path): string
    {
        $name = 'reviewed_snapshot';
        foreach ($path as $segment) {
            $name .= '['.$segment.']';
        }

        return $name;
    }

    private function suchakReviewSnapshotLabel(string $key): string
    {
        if (ctype_digit($key)) {
            return 'Row '.((int) $key + 1);
        }

        return ucwords(str_replace(['_', '-'], ' ', $key));
    }

    /**
     * @return array{
     *     has_any: bool,
     *     quality_summary: ?array,
     *     failure_codes: list<string>,
     *     field_confidence_by_key: array<string, array<string, mixed>>,
     *     field_confidence_by_path: array<string, array<string, mixed>>,
     *     low_confidence_fields: list<array<string, mixed>>,
     *     low_confidence_threshold: float
     * }
     */
    private function suchakQualitySignals(BiodataIntake $intake): array
    {
        $qualitySummary = is_array($intake->quality_summary_json) ? $intake->quality_summary_json : null;
        $failureCodes = is_array($intake->failure_codes_json)
            ? array_values(array_filter(array_map(static fn (mixed $code): string => trim((string) $code), $intake->failure_codes_json)))
            : [];
        $fieldConfidence = is_array($intake->field_confidence_json) ? $intake->field_confidence_json : [];
        $threshold = 0.65;
        $byKey = [];
        $byPath = [];
        $lowFields = [];

        foreach ($fieldConfidence as $fieldKey => $row) {
            if (! is_array($row)) {
                continue;
            }

            $score = isset($row['score']) && is_numeric($row['score']) ? round((float) $row['score'], 3) : null;
            $present = array_key_exists('present', $row) ? (bool) $row['present'] : null;
            $status = strtolower(trim((string) ($row['status'] ?? '')));
            $isLow = ($score !== null && $score < $threshold)
                || $present === false
                || in_array($status, ['low', 'missing', 'failed'], true);
            $sourcePath = trim((string) ($row['source_path'] ?? '')) ?: null;
            $signal = [
                'key' => (string) $fieldKey,
                'label' => $this->suchakReviewSnapshotLabel((string) $fieldKey),
                'score' => $score,
                'present' => $present,
                'source_path' => $sourcePath,
                'reason' => trim((string) ($row['reason'] ?? $status)) ?: null,
                'is_low' => $isLow,
            ];

            if (is_string($sourcePath) && $sourcePath !== '') {
                $byPath[$sourcePath] = $signal;
                $byPath[$this->nonIndexedPath($sourcePath)] = $signal;
            }

            $byKey[(string) $fieldKey] = $signal;
            if ($isLow) {
                $lowFields[] = $signal;
            }
        }

        return [
            'has_any' => $qualitySummary !== null || $failureCodes !== [] || $fieldConfidence !== [],
            'quality_summary' => $qualitySummary,
            'failure_codes' => $failureCodes,
            'field_confidence_by_key' => $byKey,
            'field_confidence_by_path' => $byPath,
            'low_confidence_fields' => $lowFields,
            'low_confidence_threshold' => $threshold,
        ];
    }

    /**
     * @param  list<string|int>  $path
     * @param  array<string, mixed>  $qualitySignals
     * @return array<string, mixed>|null
     */
    private function confidenceSignalForPath(array $path, array $qualitySignals): ?array
    {
        $pathKey = implode('.', array_map('strval', $path));
        $nonIndexedPath = $this->nonIndexedPath($pathKey);
        $segments = array_values(array_filter(explode('.', $nonIndexedPath), static fn (string $segment): bool => $segment !== ''));
        $leaf = $segments !== [] ? (string) end($segments) : $pathKey;
        $candidates = array_values(array_unique(array_filter([$pathKey, $nonIndexedPath, $leaf])));
        $byPath = is_array($qualitySignals['field_confidence_by_path'] ?? null) ? $qualitySignals['field_confidence_by_path'] : [];
        $byKey = is_array($qualitySignals['field_confidence_by_key'] ?? null) ? $qualitySignals['field_confidence_by_key'] : [];

        foreach ([$pathKey, $nonIndexedPath] as $candidatePath) {
            if (isset($byPath[$candidatePath]) && is_array($byPath[$candidatePath])) {
                return $byPath[$candidatePath];
            }
        }

        foreach ($byKey as $signalKey => $signal) {
            if (! is_array($signal)) {
                continue;
            }

            $aliases = self::FIELD_CONFIDENCE_ALIASES[(string) $signalKey] ?? [];
            $sourcePath = is_string($signal['source_path'] ?? null) ? $signal['source_path'] : null;
            $sourceNonIndexed = is_string($sourcePath) ? $this->nonIndexedPath($sourcePath) : null;

            if (
                in_array((string) $signalKey, $candidates, true)
                || in_array($leaf, $aliases, true)
                || (is_string($sourcePath) && in_array($sourcePath, $candidates, true))
                || (is_string($sourceNonIndexed) && in_array($sourceNonIndexed, $candidates, true))
            ) {
                return $signal;
            }
        }

        return null;
    }

    private function nonIndexedPath(string $path): string
    {
        return implode('.', array_values(array_filter(
            array_map('trim', explode('.', $path)),
            static fn (string $segment): bool => $segment !== '' && ! ctype_digit($segment)
        )));
    }

    private function errorResponse(
        Request $request,
        BiodataIntake $intake,
        string $message,
        int $status,
    ): JsonResponse|RedirectResponse {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => $message,
            ], $status);
        }

        return redirect()
            ->route('suchak.intakes.review-snapshot.edit', $intake)
            ->with('error', $message);
    }
}
