<?php

namespace App\Services\Intake;

use App\Models\MatrimonyProfile;
use App\Services\Ocr\OcrNormalize;
use App\Services\Parsing\IntakeControlledFieldNormalizer;
use App\Services\Parsing\IntakeDictionaryMapper;
use App\Services\Parsing\IntakeParsedJsonSectionBuilder;
use App\Services\Parsing\IntakeParsedJsonUtf8Sanitizer;
use App\Services\Parsing\IntakeParsedSnapshotSkeleton;

/**
 * Central orchestration for intake snapshot shaping and pending suggestion storage.
 * Does not apply profile core mutations (that remains MutationService / user actions).
 *
 * Pending JSON shape matches MutationService::mergeSuggestionPayloads buckets.
 */
class IntakePipelineService
{
    public function __construct(
        private IntakeControlledFieldNormalizer $controlledFieldNormalizer,
        private IntakeParsedSnapshotSkeleton $snapshotSkeleton,
        private IntakeParsedJsonSectionBuilder $sectionBuilder,
        private IntakeDictionaryMapper $dictionaryMapper,
        private IntakeSuggestionDiffBuilder $suggestionDiffBuilder,
    ) {}

    /**
     * @param  array<string, mixed>  $input  Snapshot-shaped array or wrapper with `snapshot` key
     * @param  array<string, mixed>  $context  Optional: parsed_snapshot, suggestion_delta, store_pending, persist_built_suggestions,
     *                                         preview_suggestions, compute_diff_suggestions, source_intake_id
     * @return array{parsed: array, normalized: array, mapped: array, suggestions: array}
     */
    public function process(MatrimonyProfile $profile, array $input, array $context = []): array
    {
        $parsed = $this->parse($input, $context);
        $suggestedByUserId = array_key_exists('suggested_by_user_id', $context)
            ? ($context['suggested_by_user_id'] !== null && (int) $context['suggested_by_user_id'] > 0
                ? (int) $context['suggested_by_user_id']
                : null)
            : (($profile->user_id !== null && (int) $profile->user_id > 0) ? (int) $profile->user_id : null);
        $normalized = $this->normalize($parsed, $suggestedByUserId);
        $mapped = $this->applyDictionary($normalized);

        $explicitDelta = array_key_exists('suggestion_delta', $context);
        $delta = $explicitDelta
            ? $context['suggestion_delta']
            : $this->buildSuggestions($profile, $mapped, $context);

        $willStore = $delta !== [] && ($context['store_pending'] ?? true);
        if ($willStore && ! $explicitDelta && ! ($context['persist_built_suggestions'] ?? false)) {
            $willStore = false;
        }
        if ($willStore) {
            $this->storePending($profile, $delta);
        }

        return [
            'parsed' => $parsed,
            'normalized' => $normalized,
            'mapped' => $mapped,
            'suggestions' => $delta,
        ];
    }

    /**
     * Post-parse SSOT skeleton + UTF-8 scrub before persisting parsed_json (ParseIntakeJob).
     *
     * @param  array<string, mixed>  $parsed
     * @param  array<string, int>|null  $utf8Stats
     * @return array<string, mixed>
     */
    public function finalizeParsedSnapshotForStorage(array $parsed, ?array &$utf8Stats = null, ?int $suggestedByUserId = null): array
    {
        $ssot = OcrNormalize::normalizeDigitsDeep($this->snapshotSkeleton->ensure($parsed));
        $ssot = $this->controlledFieldNormalizer->normalizeSnapshot($ssot, $suggestedByUserId);
        $stats = [];
        $out = IntakeParsedJsonUtf8Sanitizer::sanitize($ssot, $stats);
        $out = $this->controlledFieldNormalizer->finalizePostSsotSnapshot(
            $this->snapshotSkeleton->ensure($out),
            $suggestedByUserId
        );
        $out = OcrNormalize::normalizeDigitsDeep($this->snapshotSkeleton->ensure($out));
        $out = $this->snapshotSkeleton->ensure(array_replace(
            $out,
            $this->sectionBuilder->build($out)
        ));
        if ($utf8Stats !== null) {
            $utf8Stats = $stats;
        }

        return $this->snapshotSkeleton->ensure($out);
    }

    /**
     * Deterministic controlled-field normalization + safe full_name cleanup (approval / storage).
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function normalizeApprovedSnapshot(array $snapshot, ?int $suggestedByUserId = null): array
    {
        $snapshot = $this->mergeParentsAddressesIntoCanonicalAddresses($snapshot);
        $out = $this->controlledFieldNormalizer->normalizeSnapshot($snapshot, $suggestedByUserId);
        if (isset($out['core']['full_name']) && is_string($out['core']['full_name'])) {
            $cleaned = preg_replace('/\s*तपासा\s*/u', ' ', $out['core']['full_name']);
            $cleaned = preg_replace('/\s+/u', ' ', trim((string) $cleaned));
            if ($cleaned !== $out['core']['full_name']) {
                $out['core']['full_name'] = $cleaned;
            }
        }

        return $out;
    }

    /**
     * Intake parsing keeps parent rows separate for wizard section parity.
     * MutationService persists all profile-owned address rows through the canonical addresses entity.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function mergeParentsAddressesIntoCanonicalAddresses(array $snapshot): array
    {
        $addresses = is_array($snapshot['addresses'] ?? null) ? array_values($snapshot['addresses']) : [];
        $parents = is_array($snapshot['parents_addresses'] ?? null) ? $snapshot['parents_addresses'] : [];
        $seen = [];

        foreach ($addresses as $row) {
            if (is_array($row)) {
                $seen[$this->addressIdentity($row)] = true;
            }
        }

        foreach ($parents as $row) {
            if (! is_array($row)) {
                continue;
            }

            $line = trim((string) ($row['address_line'] ?? $row['raw'] ?? ''));
            $locationId = (int) ($row['location_id'] ?? $row['city_id'] ?? 0);
            if ($line === '' && $locationId < 1) {
                continue;
            }

            $type = trim((string) ($row['address_type'] ?? $row['address_type_key'] ?? 'permanent'));
            if (! in_array($type, ['current', 'permanent', 'work', 'other', 'native'], true)) {
                $type = 'permanent';
            }

            $canonical = $row;
            $canonical['address_scope'] = 'parents';
            $canonical['address_type'] = $type;
            $canonical['address_line'] = $line !== '' ? $line : null;
            unset($canonical['type'], $canonical['address_type_key'], $canonical['raw']);

            $identity = $this->addressIdentity($canonical);
            if (isset($seen[$identity])) {
                continue;
            }

            $addresses[] = $canonical;
            $seen[$identity] = true;
        }

        $snapshot['addresses'] = $addresses;
        unset($snapshot['parents_addresses']);

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function addressIdentity(array $row): string
    {
        $scope = trim((string) ($row['address_scope'] ?? 'self'));
        $type = trim((string) ($row['address_type'] ?? $row['address_type_key'] ?? $row['type'] ?? ''));
        $line = mb_strtolower(trim((string) ($row['address_line'] ?? $row['raw'] ?? '')));
        $locationId = (int) ($row['location_id'] ?? $row['city_id'] ?? 0);

        return implode('|', [$scope, $type, $line, $locationId]);
    }

    /**
     * Same normalization path as approval, for preview → stored approval snapshot.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function normalizeSnapshotForStorage(array $snapshot, ?int $suggestedByUserId = null): array
    {
        return $this->normalizeApprovedSnapshot($snapshot, $suggestedByUserId);
    }

    /**
     * Fast storage path for admin bulk 7-field correction only.
     * Applies core controlled-field normalization without walking relatives, addresses,
     * career rows, or open-place suggestion writes on large parsed snapshots.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function normalizeBulkCandidateCorrectionSnapshot(array $snapshot, ?int $suggestedByUserId = null): array
    {
        if (! is_array($snapshot['core'] ?? null)) {
            $snapshot['core'] = [];
        }

        $snapshot['core'] = $this->controlledFieldNormalizer->normalizeCore($snapshot['core']);

        return $snapshot;
    }

    /**
     * Merge suggestion buckets into profile.pending_intake_suggestions_json (same rules as MutationService).
     *
     * @param  array<string, mixed>  $delta
     */
    public function mergePendingSuggestions(MatrimonyProfile $profile, array $delta): void
    {
        $delta = $this->pruneEmptySuggestionBuckets($delta);
        if ($delta === []) {
            return;
        }
        $prev = $profile->pending_intake_suggestions_json;
        if (! is_array($prev)) {
            $prev = [];
        }
        $profile->pending_intake_suggestions_json = $this->mergeSuggestionPayloads($prev, $delta);
        $profile->save();
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function parse(array $input, array $context): array
    {
        if (! empty($context['parsed_snapshot']) && is_array($context['parsed_snapshot'])) {
            return $context['parsed_snapshot'];
        }
        if (isset($input['snapshot']) && is_array($input['snapshot'])) {
            return $input['snapshot'];
        }

        return $input;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data, ?int $suggestedByUserId = null): array
    {
        if ($data === []) {
            return [];
        }

        return $this->normalizeApprovedSnapshot($data, $suggestedByUserId);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyDictionary(array $data): array
    {
        return $this->dictionaryMapper->mapSnapshot($data);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function buildSuggestions(MatrimonyProfile $profile, array $mapped, array $context): array
    {
        $shouldCompute = ($context['compute_diff_suggestions'] ?? false)
            || ($context['persist_built_suggestions'] ?? false)
            || ($context['preview_suggestions'] ?? false);

        if (! $shouldCompute) {
            return [];
        }

        $sourceIntakeId = array_key_exists('source_intake_id', $context) ? $context['source_intake_id'] : null;
        $sourceIntakeId = $sourceIntakeId !== null ? (int) $sourceIntakeId : null;

        return $this->suggestionDiffBuilder->build($profile, $mapped, $sourceIntakeId);
    }

    /**
     * @param  array<string, mixed>  $suggestions
     */
    private function storePending(MatrimonyProfile $profile, array $suggestions): void
    {
        $this->mergePendingSuggestions($profile, $suggestions);
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    private function mergeSuggestionPayloads(array $a, array $b): array
    {
        $out = $a;
        foreach (['core', 'extended', 'entities'] as $k) {
            if (! empty($b[$k]) && is_array($b[$k])) {
                $out[$k] = array_merge($out[$k] ?? [], $b[$k]);
            }
        }
        foreach (['birth_place', 'native_place', 'contacts', 'preferences', 'extended_narrative', 'other_relatives_text'] as $k) {
            if (array_key_exists($k, $b) && $b[$k] !== null && $b[$k] !== [] && $b[$k] !== '') {
                $out[$k] = $b[$k];
            }
        }
        if (! empty($b['core_field_suggestions']) && is_array($b['core_field_suggestions'])) {
            $out['core_field_suggestions'] = array_values(array_merge($out['core_field_suggestions'] ?? [], $b['core_field_suggestions']));
        }

        return $this->pruneEmptySuggestionBuckets($out);
    }

    /**
     * @param  array<string, mixed>  $suggestions
     * @return array<string, mixed>
     */
    private function pruneEmptySuggestionBuckets(array $suggestions): array
    {
        foreach ($suggestions as $k => $v) {
            if ($v === null || $v === [] || $v === '') {
                unset($suggestions[$k]);
            }
            if (is_array($v) && $v === []) {
                unset($suggestions[$k]);
            }
        }

        return $suggestions;
    }
}
