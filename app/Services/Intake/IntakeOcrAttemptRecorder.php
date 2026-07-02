<?php

namespace App\Services\Intake;

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Services\Ocr\OcrNormalize;
use App\Services\Ocr\OcrQualityEvaluator;
use Illuminate\Support\Facades\DB;

class IntakeOcrAttemptRecorder
{
    public const SELECTION_POLICY_VERSION = 'phase1_evidence_v1';

    /** @var list<string> */
    private const ALLOWED_ACTOR_TYPES = [
        BiodataIntakeOcrAttempt::ACTOR_ADMIN,
        BiodataIntakeOcrAttempt::ACTOR_PROFILE_USER,
        BiodataIntakeOcrAttempt::ACTOR_SUCHAK,
        BiodataIntakeOcrAttempt::ACTOR_SYSTEM,
    ];

    /** @var list<string> */
    private const ALLOWED_SOURCE_SURFACES = [
        BiodataIntakeOcrAttempt::SURFACE_MOBILE_APP,
        BiodataIntakeOcrAttempt::SURFACE_WEBSITE,
        BiodataIntakeOcrAttempt::SURFACE_ADMIN_PANEL,
        BiodataIntakeOcrAttempt::SURFACE_SERVER,
        BiodataIntakeOcrAttempt::SURFACE_API,
    ];

    public function __construct(
        private readonly OcrQualityEvaluator $qualityEvaluator,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function record(BiodataIntake $intake, array $attributes): BiodataIntakeOcrAttempt
    {
        $selectAsPrimary = ! empty($attributes['is_primary']);
        $payload = $this->payload($intake, $attributes);
        if ($selectAsPrimary) {
            $payload['is_primary'] = false;
        }

        $attempt = BiodataIntakeOcrAttempt::query()->create(
            $payload
        );

        if ($selectAsPrimary) {
            $this->selectPrimary(
                $attempt,
                (string) ($attributes['selected_reason'] ?? 'selected_as_primary'),
                (string) ($attributes['selected_policy'] ?? self::SELECTION_POLICY_VERSION),
                (string) ($attributes['selected_by'] ?? 'system'),
                $this->nullableInt($attributes['selected_by_user_id'] ?? null),
                $this->actorType($attributes['selected_by_actor_type'] ?? $attributes['created_by_actor_type'] ?? null),
            );
            $attempt->refresh();
        }

        return $attempt;
    }

    /**
     * Select an existing matching evidence row when possible; otherwise create a
     * new row. Selection metadata may change, but OCR evidence text remains append-only.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function recordOrSelectPrimary(BiodataIntake $intake, array $attributes): BiodataIntakeOcrAttempt
    {
        $payload = $this->payload($intake, $attributes);
        $attempt = null;
        $normalizedHash = $payload['normalized_text_hash'] ?? null;

        if (is_string($normalizedHash) && $normalizedHash !== '') {
            $attempt = BiodataIntakeOcrAttempt::query()
                ->where('intake_id', $intake->id)
                ->where('engine', $payload['engine'])
                ->where('normalized_text_hash', $normalizedHash)
                ->oldest()
                ->first();
        }

        if (! $attempt) {
            $attempt = BiodataIntakeOcrAttempt::query()->create(
                array_merge($payload, ['is_primary' => false])
            );
        }

        $this->selectPrimary(
            $attempt,
            (string) ($attributes['selected_reason'] ?? 'selected_parse_input'),
            (string) ($attributes['selected_policy'] ?? self::SELECTION_POLICY_VERSION),
            (string) ($attributes['selected_by'] ?? 'system'),
            $this->nullableInt($attributes['selected_by_user_id'] ?? null),
            $this->actorType($attributes['selected_by_actor_type'] ?? $attributes['created_by_actor_type'] ?? null),
        );
        $attempt->refresh();

        return $attempt;
    }

    public function selectPrimary(
        BiodataIntakeOcrAttempt $attempt,
        string $reason,
        string $policy = self::SELECTION_POLICY_VERSION,
        string $selectedBy = 'system',
        ?int $selectedByUserId = null,
        ?string $selectedByActorType = null,
    ): void {
        DB::transaction(function () use ($attempt, $reason, $policy, $selectedBy, $selectedByUserId, $selectedByActorType): void {
            $lockedAttempt = BiodataIntakeOcrAttempt::query()
                ->whereKey($attempt->id)
                ->lockForUpdate()
                ->firstOrFail();

            $previousPrimary = BiodataIntakeOcrAttempt::query()
                ->where('intake_id', $lockedAttempt->intake_id)
                ->where('is_primary', true)
                ->whereKeyNot($lockedAttempt->id)
                ->lockForUpdate()
                ->first();

            BiodataIntakeOcrAttempt::query()
                ->where('intake_id', $lockedAttempt->intake_id)
                ->where('is_primary', true)
                ->update(['is_primary' => false]);

            BiodataIntakeOcrAttempt::query()->whereKey($lockedAttempt->id)->update([
                'is_primary' => true,
                'selected_by' => $selectedBy,
                'selected_by_user_id' => $selectedByUserId,
                'selected_by_actor_type' => $this->actorType($selectedByActorType),
                'selected_at' => now(),
                'selected_policy' => $policy,
                'selected_reason' => $reason,
                'previous_primary_attempt_id' => $previousPrimary?->id,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function payload(BiodataIntake $intake, array $attributes): array
    {
        $rawText = $this->nullableString($attributes['raw_text'] ?? null);
        $normalizedText = $this->nullableString($attributes['normalized_text'] ?? null);
        if ($normalizedText === null && $rawText !== null) {
            $normalizedText = OcrNormalize::normalizeRawTextForParsing($rawText);
        }

        $qualityScore = $attributes['quality_score'] ?? null;
        if ($qualityScore === null && $rawText !== null && trim($rawText) !== '') {
            $quality = $this->qualityEvaluator->evaluate($rawText);
            $qualityScore = $quality['score'] ?? null;
        }

        return [
            'intake_id' => $intake->id,
            'engine' => (string) ($attributes['engine'] ?? ''),
            'source' => $this->nullableString($attributes['source'] ?? null),
            'created_by_user_id' => $this->nullableInt($attributes['created_by_user_id'] ?? null),
            'created_by_actor_type' => $this->actorType($attributes['created_by_actor_type'] ?? null),
            'source_surface' => $this->sourceSurface($attributes['source_surface'] ?? null),
            'status' => (string) ($attributes['status'] ?? BiodataIntakeOcrAttempt::STATUS_SUCCESS),
            'raw_text' => $rawText,
            'normalized_text' => $normalizedText,
            'text_hash' => $rawText !== null ? hash('sha256', $rawText) : null,
            'normalized_text_hash' => $normalizedText !== null ? hash('sha256', $normalizedText) : null,
            'image_hash' => $this->nullableString($attributes['image_hash'] ?? null),
            'perceptual_hash' => $this->nullableString($attributes['perceptual_hash'] ?? null),
            'quality_score' => $this->nullableFloat($qualityScore),
            'layout_score' => $this->nullableFloat($attributes['layout_score'] ?? null),
            'field_scores_json' => $this->nullableArray($attributes['field_scores_json'] ?? null),
            'failure_code' => $this->nullableString($attributes['failure_code'] ?? null),
            'failure_message' => $this->nullableString($attributes['failure_message'] ?? null),
            'raw_blocks_json' => $this->nullableArray($attributes['raw_blocks_json'] ?? null),
            'raw_lines_json' => $this->nullableArray($attributes['raw_lines_json'] ?? null),
            'layout_meta_json' => $this->nullableArray($attributes['layout_meta_json'] ?? null),
            'engine_meta_json' => $this->nullableArray($attributes['engine_meta_json'] ?? null),
            'parser_version' => $this->nullableString($attributes['parser_version'] ?? null),
            'prompt_version' => $this->nullableString($attributes['prompt_version'] ?? null),
            'preprocessing_version' => $this->nullableString($attributes['preprocessing_version'] ?? null),
            'selection_policy_version' => $this->nullableString($attributes['selection_policy_version'] ?? null),
            'duration_ms' => $this->nullableInt($attributes['duration_ms'] ?? null),
            'cost_units' => $this->nullableFloat($attributes['cost_units'] ?? null),
            'provider_request_id' => $this->nullableString($attributes['provider_request_id'] ?? null),
            'provider_response_id' => $this->nullableString($attributes['provider_response_id'] ?? null),
            'is_primary' => (bool) ($attributes['is_primary'] ?? false),
            'selected_by' => $this->nullableString($attributes['selected_by'] ?? null),
            'selected_by_user_id' => $this->nullableInt($attributes['selected_by_user_id'] ?? null),
            'selected_by_actor_type' => $this->actorType($attributes['selected_by_actor_type'] ?? null),
            'selected_at' => $attributes['selected_at'] ?? null,
            'selected_policy' => $this->nullableString($attributes['selected_policy'] ?? null),
            'selected_reason' => $this->nullableString($attributes['selected_reason'] ?? null),
            'previous_primary_attempt_id' => $this->nullableInt($attributes['previous_primary_attempt_id'] ?? null),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round(max(0.0, min(99.999, (float) $value)), 3);
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return max(0, (int) $value);
    }

    private function actorType(mixed $value): ?string
    {
        $actorType = $this->nullableString($value);
        if ($actorType === null) {
            return null;
        }

        return in_array($actorType, self::ALLOWED_ACTOR_TYPES, true) ? $actorType : null;
    }

    private function sourceSurface(mixed $value): ?string
    {
        $surface = $this->nullableString($value);
        if ($surface === null) {
            return null;
        }

        return in_array($surface, self::ALLOWED_SOURCE_SURFACES, true) ? $surface : null;
    }

    /**
     * @return array<string, mixed>|list<mixed>|null
     */
    private function nullableArray(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }
}
