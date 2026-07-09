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
            if (! (bool) ($fieldMatch['duplicate_field_match_eligible'] ?? false)) {
                continue;
            }

            $score = is_numeric($fieldMatch['duplicate_field_match_score'] ?? null)
                ? (float) $fieldMatch['duplicate_field_match_score']
                : 0.0;

            if ($type === 'same_mobile' || $score >= 0.66) {
                $blocks[] = [
                    'code' => 'auto_duplicate_intake',
                    'label' => $type === 'same_mobile' ? 'Same mobile — already seen' : 'Same identity — already seen',
                    'source' => 'auto_duplicate',
                ];
            }
        }

        return $blocks;
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
