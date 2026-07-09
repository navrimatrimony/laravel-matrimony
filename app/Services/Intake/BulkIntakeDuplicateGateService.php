<?php

namespace App\Services\Intake;

use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatchItem;

class BulkIntakeDuplicateGateService
{
    public const OVERRIDE_PROCEED = 'proceed';

    public function __construct(
        private readonly BulkIntakeIdentityHistoryService $identityHistoryService,
        private readonly IntakeDuplicateFieldMatchEvaluator $fieldMatchEvaluator,
    ) {}

    /**
     * @param  list<array<string, mixed>>|null  $duplicateHints
     * @return array{
     *     auto_blocked: bool,
     *     override_active: bool,
     *     blocks: list<array{code: string, label: string, source: string}>,
     *     history_blocks: list<array<string, mixed>>,
     *     primary_block_code: string|null
     * }
     */
    public function evaluateForItem(BulkIntakeBatchItem $item, ?array $duplicateHints = null): array
    {
        $meta = is_array($item->item_meta_json) ? $item->item_meta_json : [];
        $overrideActive = $this->hasActiveOverride($meta);
        $blocks = [];

        if ((string) data_get($meta, 'duplicate_review.status') === 'manual_duplicate') {
            $blocks[] = [
                'code' => 'manual_duplicate',
                'label' => 'Manual duplicate',
                'source' => 'manual',
            ];
        }

        $historyBlocks = $this->identityHistoryService->blockingHistoriesForItem($item);
        if (! $overrideActive) {
            foreach ($historyBlocks as $historyBlock) {
                $code = (string) ($historyBlock['reason_code'] ?? '');
                if ($code === '') {
                    continue;
                }

                $blocks[] = [
                    'code' => $code,
                    'label' => (string) ($historyBlock['label'] ?? $this->identityHistoryService->reasonCodeLabel($code)),
                    'source' => 'history',
                ];
            }
        }

        if ($duplicateHints === null) {
            $duplicateHints = [];
        }

        if (! $overrideActive) {
            foreach ($this->autoDuplicateBlocksFromHints($item, $duplicateHints) as $block) {
                $blocks[] = $block;
            }
        }

        $blocks = $this->uniqueBlocks($blocks);
        $autoBlocked = $blocks !== [] && ! $overrideActive;

        return [
            'auto_blocked' => $autoBlocked,
            'override_active' => $overrideActive,
            'blocks' => $blocks,
            'history_blocks' => $historyBlocks,
            'primary_block_code' => $blocks[0]['code'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function hasActiveOverride(array $meta): bool
    {
        return (string) data_get($meta, 'duplicate_gate_override.status') === self::OVERRIDE_PROCEED
            && data_get($meta, 'duplicate_gate_override.cleared_at') === null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function activeOverrideForItem(BulkIntakeBatchItem $item): ?array
    {
        $meta = is_array($item->item_meta_json) ? $item->item_meta_json : [];
        $override = data_get($meta, 'duplicate_gate_override');
        if (! is_array($override)) {
            return null;
        }

        if ((string) ($override['status'] ?? '') !== self::OVERRIDE_PROCEED) {
            return null;
        }

        if (filled($override['cleared_at'] ?? null)) {
            return null;
        }

        return $override;
    }

    /**
     * @param  list<array<string, mixed>>  $duplicateHints
     * @return list<array{code: string, label: string, source: string}>
     */
    private function autoDuplicateBlocksFromHints(BulkIntakeBatchItem $item, array $duplicateHints): array
    {
        $blocks = [];
        $currentIntake = $this->intakeForMatch($item);

        foreach ($duplicateHints as $hint) {
            if (! is_array($hint)) {
                continue;
            }

            $type = (string) ($hint['type'] ?? '');
            if ($type === 'same_profile_mobile') {
                $blocks[] = [
                    'code' => 'duplicate_existing_profile',
                    'label' => 'Already on website',
                    'source' => 'auto_duplicate',
                ];
                continue;
            }

            if (! in_array($type, ['same_mobile', 'same_name_dob'], true)) {
                continue;
            }

            $matchedIntakeId = (int) ($hint['matched_intake_id'] ?? 0);
            if ($matchedIntakeId <= 0 || ! $currentIntake instanceof BiodataIntake) {
                continue;
            }

            $reference = BiodataIntake::query()->find($matchedIntakeId, [
                'id',
                'parsed_json',
                'approval_snapshot_json',
            ]);
            if (! $reference instanceof BiodataIntake) {
                continue;
            }

            $fieldMatch = $this->fieldMatchEvaluator->evaluate($currentIntake, $reference);

            if ($type === 'same_mobile') {
                if (! (bool) ($fieldMatch['duplicate_field_match_eligible'] ?? false)) {
                    continue;
                }

                $blocks[] = [
                    'code' => 'auto_duplicate_intake',
                    'label' => 'Same mobile — already seen',
                    'source' => 'auto_duplicate',
                ];
                continue;
            }

            if ($type === 'same_name_dob' && $this->confirmsNameDobAsDuplicate($currentIntake, $reference, $fieldMatch)) {
                $blocks[] = [
                    'code' => 'auto_duplicate_intake',
                    'label' => 'Same identity — already seen',
                    'source' => 'auto_duplicate',
                ];
            }
        }

        return $blocks;
    }

    /**
     * Double-check filter: same name + DOB alone is not enough.
     * Requires secondary confirmation via mobile, or gender plus height/education.
     *
     * @param  array<string, mixed>  $fieldMatch
     */
    public function confirmsNameDobAsDuplicate(BiodataIntake $current, BiodataIntake $reference, array $fieldMatch): bool
    {
        if (($fieldMatch['current_reference_name_match'] ?? '') !== 'yes') {
            return false;
        }

        if (($fieldMatch['current_reference_dob_match'] ?? '') !== 'yes') {
            return false;
        }

        if (($fieldMatch['current_reference_contact_match'] ?? '') === 'yes') {
            return true;
        }

        if ($this->genderMatches($current, $reference) && (
            $this->educationMatches($current, $reference)
            || $this->heightMatches($current, $reference)
        )) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $fieldMatch
     * @return array{confirmed: bool, secondary_matches: list<string>}
     */
    public function nameDobDuplicateConfirmation(BiodataIntake $current, BiodataIntake $reference, array $fieldMatch): array
    {
        $secondary = [];

        if (($fieldMatch['current_reference_contact_match'] ?? '') === 'yes') {
            $secondary[] = 'mobile';
        }
        if ($this->genderMatches($current, $reference)) {
            $secondary[] = 'gender';
        }
        if ($this->educationMatches($current, $reference)) {
            $secondary[] = 'education';
        }
        if ($this->heightMatches($current, $reference)) {
            $secondary[] = 'height';
        }

        return [
            'confirmed' => $this->confirmsNameDobAsDuplicate($current, $reference, $fieldMatch),
            'secondary_matches' => $secondary,
        ];
    }

    private function genderMatches(BiodataIntake $current, BiodataIntake $reference): bool
    {
        $currentGender = strtolower((string) ($this->snapshotScalar($current, [
            'core.gender',
            'gender',
            'candidate.gender',
        ]) ?? ''));
        $referenceGender = strtolower((string) ($this->snapshotScalar($reference, [
            'core.gender',
            'gender',
            'candidate.gender',
        ]) ?? ''));

        return in_array($currentGender, ['male', 'female'], true)
            && $currentGender === $referenceGender;
    }

    private function educationMatches(BiodataIntake $current, BiodataIntake $reference): bool
    {
        $current = $this->normalizeCompareText($this->snapshotScalar($current, [
            'core.highest_education',
            'core.education',
            'education_history.0.degree',
        ]));
        $reference = $this->normalizeCompareText($this->snapshotScalar($reference, [
            'core.highest_education',
            'core.education',
            'education_history.0.degree',
        ]));

        return $current !== null && $reference !== null && $current === $reference;
    }

    private function heightMatches(BiodataIntake $current, BiodataIntake $reference): bool
    {
        $currentCm = $this->heightCm($current);
        $referenceCm = $this->heightCm($reference);

        return $currentCm !== null && $referenceCm !== null && $currentCm === $referenceCm;
    }

    private function heightCm(BiodataIntake $intake): ?int
    {
        $cm = $this->snapshotScalar($intake, ['core.height_cm', 'height_cm']);
        if (is_numeric($cm)) {
            return (int) $cm;
        }

        $text = $this->normalizeCompareText($this->snapshotScalar($intake, [
            'core.height',
            'core.height_text',
            'height',
        ]));
        if ($text === null) {
            return null;
        }

        if (preg_match('/(\d+)\s*(?:ft|feet|f)/i', $text, $feet) === 1) {
            $inches = 0;
            if (preg_match('/(\d+)\s*(?:in|inch)/i', $text, $inchMatch) === 1) {
                $inches = (int) $inchMatch[1];
            }

            return (int) round((((int) $feet[1] * 12) + $inches) * 2.54);
        }

        if (preg_match('/(\d{3})/', $text, $digits) === 1) {
            return (int) $digits[1];
        }

        return null;
    }

    /**
     * @param  list<string>  $paths
     */
    private function snapshotScalar(BiodataIntake $intake, array $paths): mixed
    {
        $data = $this->snapshotData($intake);
        foreach ($paths as $path) {
            $value = data_get($data, $path);
            if (is_scalar($value) && trim((string) $value) !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotData(BiodataIntake $intake): array
    {
        $approval = is_array($intake->approval_snapshot_json) ? $intake->approval_snapshot_json : [];
        if ($approval !== []) {
            return $approval;
        }

        return is_array($intake->parsed_json) ? $intake->parsed_json : [];
    }

    private function normalizeCompareText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return null;
        }

        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * @param  list<array{code: string, label: string, source: string}>  $blocks
     * @return list<array{code: string, label: string, source: string}>
     */
    private function uniqueBlocks(array $blocks): array
    {
        $seen = [];
        $out = [];

        foreach ($blocks as $block) {
            $key = (string) ($block['code'] ?? '');
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $block;
        }

        return $out;
    }

    private function intakeForMatch(BulkIntakeBatchItem $item): ?BiodataIntake
    {
        $loaded = $item->relationLoaded('biodataIntake') ? $item->biodataIntake : null;
        if ($loaded instanceof BiodataIntake) {
            return $loaded;
        }

        $intakeId = $item->biodata_intake_id ?? $loaded?->id;
        if ($intakeId === null) {
            return null;
        }

        return BiodataIntake::query()->find((int) $intakeId, [
            'id',
            'parsed_json',
            'approval_snapshot_json',
        ]);
    }
}
