<?php

namespace App\Services\Intake;

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;

class IntakeRoutingTelemetryService
{
    /**
     * @return array<string, mixed>
     */
    public function forIntake(BiodataIntake $intake): array
    {
        $attempts = $intake->ocrAttempts()
            ->latest('id')
            ->get([
                'id',
                'engine',
                'status',
                'failure_code',
                'quality_score',
                'layout_score',
                'duration_ms',
                'cost_units',
            ]);

        $providerAttempts = $attempts->filter(fn (BiodataIntakeOcrAttempt $attempt): bool => in_array($attempt->engine, [
            BiodataIntakeOcrAttempt::ENGINE_SARVAM_AI_VISION,
            BiodataIntakeOcrAttempt::ENGINE_OPENAI_AI_VISION,
        ], true));

        $failedProviders = $providerAttempts->filter(
            fn (BiodataIntakeOcrAttempt $attempt): bool => $attempt->status === BiodataIntakeOcrAttempt::STATUS_FAILED
        );

        $qualitySummary = is_array($intake->quality_summary_json) ? $intake->quality_summary_json : [];
        $latestQualityAttempt = $attempts->first(fn (BiodataIntakeOcrAttempt $attempt): bool => $attempt->quality_score !== null);
        $latestLayoutAttempt = $attempts->first(fn (BiodataIntakeOcrAttempt $attempt): bool => $attempt->layout_score !== null);
        $latestDurationAttempt = $attempts->first(fn (BiodataIntakeOcrAttempt $attempt): bool => $attempt->duration_ms !== null);
        $costAttempts = $attempts->filter(fn (BiodataIntakeOcrAttempt $attempt): bool => $attempt->cost_units !== null);

        return [
            'mode' => 'dry_run',
            'sarvam_attempt_count' => $attempts
                ->where('engine', BiodataIntakeOcrAttempt::ENGINE_SARVAM_AI_VISION)
                ->count(),
            'cheap_ocr_attempt_count' => $attempts
                ->whereIn('engine', [
                    BiodataIntakeOcrAttempt::ENGINE_ML_KIT_FLUTTER,
                    BiodataIntakeOcrAttempt::ENGINE_LARAVEL_NATIVE_OCR,
                ])
                ->count(),
            'failed_provider_count' => $failedProviders->count(),
            'reuse_candidate_found' => $this->reuseCandidateFound($intake),
            'last_provider_failure_code' => $failedProviders->first()?->failure_code,
            'last_quality_score' => $this->nullableFloat($qualitySummary['score'] ?? $latestQualityAttempt?->quality_score),
            'last_layout_score' => $this->nullableFloat($qualitySummary['layout_score'] ?? $latestLayoutAttempt?->layout_score),
            'duration_ms' => $this->nullableInt($intake->parse_duration_ms ?? $latestDurationAttempt?->duration_ms),
            'cost_units' => $costAttempts->isEmpty() ? null : $this->nullableFloat($costAttempts->sum('cost_units')),
        ];
    }

    public function reuseCandidateFound(BiodataIntake $intake): bool
    {
        if ($intake->ocrAttempts()
            ->where('engine', BiodataIntakeOcrAttempt::ENGINE_REUSED_TRANSCRIPT)
            ->exists()) {
            return true;
        }

        $contentHash = trim((string) ($intake->content_hash ?? ''));
        if ($contentHash === '') {
            return false;
        }

        return BiodataIntake::query()
            ->whereKeyNot($intake->id)
            ->where('content_hash', $contentHash)
            ->exists();
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 4);
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
