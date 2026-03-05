<?php

namespace App\Services;

use App\Models\BiodataIntake;
use App\Models\OcrCorrectionLog;
use App\Models\OcrCorrectionPattern;
use App\Models\OcrPatternConflict;
use App\Services\Ocr\OcrNormalize;
use Illuminate\Support\Facades\DB;

class IntakeApprovalService
{
    /**
     * @param  array<string, mixed>|null  $snapshot  Edited snapshot from preview; when null use parsed_json.
     * @return array{mutation_success: bool, conflict_detected: bool, profile_id: int|null}
     */
    public function approve(BiodataIntake $intake, int $userId, ?array $snapshot = null): array
    {
        if ($intake->intake_locked === true) {
            return [
                'mutation_success' => true,
                'conflict_detected' => false,
                'profile_id' => $intake->matrimony_profile_id,
                'already_applied' => true,
            ];
        }

        if ($intake->parse_status !== 'parsed') {
            throw new \RuntimeException('Intake must be parsed before approval.');
        }

        // Phase-5C: If already approved but not yet applied → allow apply trigger
if ($intake->approved_by_user === true) {

    if ($intake->intake_locked === true || $intake->intake_status === 'applied') {
        throw new \RuntimeException('Intake is already fully applied.');
    }

    // Already approved but not applied → directly trigger apply pipeline
    return app(\App\Services\MutationService::class)
        ->applyApprovedIntake($intake->id);
}

        $approvalSnapshot = $snapshot !== null ? $snapshot : $intake->parsed_json;
        if (!is_array($approvalSnapshot)) {
            $approvalSnapshot = [];
        }

        DB::transaction(function () use ($intake, $approvalSnapshot, $userId): void {
            $parsedCore = $intake->parsed_json['core'] ?? [];
            $approvedCore = $approvalSnapshot['core'] ?? [];

            $manualEdits = 0;

            foreach ($approvedCore as $field => $newValue) {
                $oldValue = $parsedCore[$field] ?? null;

                $normalizedOld = $this->normalizeForComparison($oldValue);
                $normalizedNew = $this->normalizeForComparison($newValue);

                if ($normalizedOld !== $normalizedNew) {
                    $manualEdits++;

                    $log = OcrCorrectionLog::create([
                        'intake_id'     => $intake->id,
                        'field_key'     => $field,
                        'original_value' => $normalizedOld,
                        'corrected_value' => $normalizedNew,
                    ]);
                    DB::table('ocr_correction_logs_actor_archive')->insert([
                        'ocr_correction_log_id' => $log->id,
                        'corrected_by' => $userId,
                        'created_at' => now(),
                    ]);

                    // Learning: after same (field_key, X->Y) observed 5+ times, strengthen pattern for better suggestions/autofill
                    $this->strengthenPatternIfThreshold($field, $normalizedOld, $normalizedNew);
                }
            }

            $intake->approved_by_user = true;
            $intake->approved_at = now();
            $intake->approval_snapshot_json = $approvalSnapshot;
            $intake->snapshot_schema_version = 1;
            $intake->intake_status = 'approved';

            // Metrics: manual vs auto-filled fields (approximation).
            $intake->fields_manually_edited_count = $manualEdits;
            $nonEmptyApproved = 0;
            foreach ($approvedCore as $value) {
                $norm = $this->normalizeForComparison($value);
                if ($norm !== null && $norm !== '') {
                    $nonEmptyApproved++;
                }
            }
            $autoFilled = max(0, $nonEmptyApproved - $manualEdits);
            $intake->fields_auto_filled_count = $autoFilled;

            $intake->save();
        });

// After approval commit → trigger Apply Pipeline based on admin policy.
$requireAdmin = \App\Models\AdminSetting::getBool('intake_require_admin_before_attach', false);
if ($requireAdmin) {
    // Admin must attach/trigger mutation from admin panel.
    return [
        'mutation_success' => false,
        'conflict_detected' => false,
        'profile_id' => $intake->matrimony_profile_id,
        'awaiting_admin' => true,
    ];
}

return app(\App\Services\MutationService::class)
    ->applyApprovedIntake($intake->id);
        
    }

    private function normalizeForComparison($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return json_encode($value);
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    /**
     * When the same correction (field_key, X -> Y) has been observed 5+ times:
     * - If pattern exists with same corrected_value Y: only bump usage_count.
     * - If pattern exists with different corrected_value Z: record conflict, do NOT overwrite.
     * - If no pattern: create candidate with is_active only when sanity check passes.
     */
    private function strengthenPatternIfThreshold(string $fieldKey, ?string $originalValue, ?string $correctedValue): void
    {
        if ($originalValue === null || $correctedValue === null) {
            return;
        }

        $count = OcrCorrectionLog::where('field_key', $fieldKey)
            ->where('original_value', $originalValue)
            ->where('corrected_value', $correctedValue)
            ->count();

        if ($count < 5) {
            return;
        }

        $existing = OcrCorrectionPattern::where('field_key', $fieldKey)
            ->where('wrong_pattern', $originalValue)
            ->where('source', 'frequency_rule')
            ->first();

        if ($existing) {
            if ((string) $existing->corrected_value === (string) $correctedValue) {
                $existing->update([
                    'usage_count' => $count,
                    'pattern_confidence' => 0.80,
                    'is_active' => true,
                    'updated_at' => now(),
                ]);
                return;
            }
            // Conflict: do not overwrite; record for review (no silent overwrite)
            $conflict = OcrPatternConflict::firstOrNew([
                'field_key' => $fieldKey,
                'wrong_pattern' => $originalValue,
                'proposed_corrected_value' => $correctedValue,
            ]);
            $conflict->existing_corrected_value = $existing->corrected_value;
            $conflict->observation_count = max((int) $conflict->observation_count, $count);
            $conflict->save();
            return;
        }

        $isActive = OcrNormalize::sanityCheckLearnedValue($fieldKey, $correctedValue);

        OcrCorrectionPattern::create([
            'field_key' => $fieldKey,
            'wrong_pattern' => $originalValue,
            'corrected_value' => $correctedValue,
            'source' => 'frequency_rule',
            'usage_count' => $count,
            'pattern_confidence' => 0.80,
            'is_active' => $isActive,
            'updated_at' => now(),
        ]);
    }
}