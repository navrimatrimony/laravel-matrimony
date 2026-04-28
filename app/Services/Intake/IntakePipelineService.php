<?php

namespace App\Services\Intake;

use App\Models\MatrimonyProfile;
use App\Services\Parsing\IntakeControlledFieldNormalizer;
use App\Services\Parsing\IntakeDictionaryMapper;
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
    public function finalizeParsedSnapshotForStorage(array $parsed, ?array &$utf8Stats = null): array
    {
        $ssot = $this->snapshotSkeleton->ensure($parsed);
        $stats = [];
        $out = IntakeParsedJsonUtf8Sanitizer::sanitize($ssot, $stats);
        if ($utf8Stats !== null) {
            $utf8Stats = $stats;
        }

        return $out;
    }

    /**
     * Deterministic controlled-field normalization + safe full_name cleanup (approval / storage).
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function normalizeApprovedSnapshot(array $snapshot, ?int $suggestedByUserId = null): array
    {
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
