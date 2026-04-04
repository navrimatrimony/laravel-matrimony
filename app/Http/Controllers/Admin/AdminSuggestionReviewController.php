<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminSetting;
use App\Models\BiodataIntake;
use App\Models\ConflictRecord;
use App\Models\MatrimonyProfile;
use App\Services\Core\ConflictPolicy;
use App\Services\ExtendedFieldService;
use App\Services\MutationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase-2: Admin suggestion review (pending_intake_suggestions_json) with governed apply via MutationService.
 * No direct profile biodata updates except pending_intake_suggestions_json maintenance (same pattern as member flow).
 */
class AdminSuggestionReviewController extends Controller
{
    public function show(BiodataIntake $intake)
    {
        $this->authorizeAdmin();

        $intake->load(['profile']);
        $profile = $intake->profile;
        if (! $profile) {
            return redirect()
                ->route('admin.biodata-intakes.show', $intake)
                ->with('error', 'Attach a profile before reviewing suggestions.');
        }

        $pending = $profile->pending_intake_suggestions_json;
        if (! is_array($pending) || $pending === []) {
            return redirect()
                ->route('admin.biodata-intakes.show', $intake)
                ->with('info', 'No pending suggestions on this profile.');
        }

        $confidenceMap = [];
        $parsed = $intake->parsed_json;
        if (is_array($parsed) && isset($parsed['confidence_map']) && is_array($parsed['confidence_map'])) {
            $confidenceMap = $parsed['confidence_map'];
        }

        $conflictFieldNames = ConflictRecord::query()
            ->where('profile_id', $profile->id)
            ->where('resolution_status', 'PENDING')
            ->pluck('field_name')
            ->map(static fn ($f) => (string) $f)
            ->unique()
            ->all();

        $safeThreshold = (float) AdminSetting::getValue('intake_confidence_high_threshold', '0.85');
        if ($safeThreshold <= 0 || $safeThreshold >= 1) {
            $safeThreshold = 0.85;
        }

        $extendedCurrent = ExtendedFieldService::getValuesForProfile($profile);
        $reviewFields = $this->buildReviewFieldRows($pending, $profile, $extendedCurrent, $confidenceMap, $conflictFieldNames);

        return view('admin.suggestions.review', [
            'intake' => $intake,
            'profile' => $profile,
            'reviewFields' => $reviewFields,
            'safeConfidenceThreshold' => $safeThreshold,
        ]);
    }

    public function apply(Request $request, BiodataIntake $intake, MutationService $mutation)
    {
        $this->authorizeAdmin();

        $validated = $request->validate([
            'review_payload' => ['required', 'string'],
        ]);
        $payload = json_decode($validated['review_payload'], true);
        if (! is_array($payload)) {
            return redirect()
                ->route('admin.suggestions.review', $intake)
                ->with('error', 'Invalid review payload.');
        }
        $decisions = $payload['decisions'] ?? [];
        if (! is_array($decisions) || $decisions === []) {
            return redirect()
                ->route('admin.suggestions.review', $intake)
                ->with('error', 'No decisions submitted.');
        }
        foreach ($decisions as $rowId => $data) {
            if (! is_array($data)) {
                abort(422, 'Invalid decision payload.');
            }
            if (! array_key_exists('expected_current', $data)) {
                abort(422, 'Missing expected_current');
            }
            $decision = $data['decision'] ?? null;
            if (! in_array($decision, ['accept', 'reject', 'flag'], true)) {
                abort(422, 'Invalid decision');
            }
        }

        $intake->load(['profile']);
        $profile = $intake->profile;
        if (! $profile) {
            return redirect()
                ->route('admin.biodata-intakes.show', $intake)
                ->with('error', 'No attached profile.');
        }

        $pending = $profile->pending_intake_suggestions_json;
        if (! is_array($pending)) {
            return redirect()
                ->route('admin.suggestions.review', $intake)
                ->with('error', 'No pending suggestions.');
        }

        $allowedCore = $mutation->coreFieldKeysAllowedForIntakeSuggestionApply();
        $actorId = (int) $request->user()->id;

        $applied = 0;
        $rejected = 0;
        $flagged = 0;
        $skippedStale = 0;
        $errors = [];

        DB::transaction(function () use (
            $decisions,
            &$profile,
            &$pending,
            $mutation,
            $allowedCore,
            $actorId,
            &$applied,
            &$rejected,
            &$flagged,
            &$skippedStale,
            &$errors
        ): void {
            foreach ($decisions as $rowId => $rowPayload) {
                /** @var array<string, mixed> $rowPayload */
                $decision = $rowPayload['decision'];
                $expectedRaw = $rowPayload['expected_current'];
                $expected = is_string($expectedRaw) || is_numeric($expectedRaw)
                    ? (string) $expectedRaw
                    : '';

                $parsed = $this->parseRowId((string) $rowId);
                if ($parsed === null) {
                    $errors[] = "Invalid row id: {$rowId}";

                    continue;
                }

                [$bucket, $fieldKey] = $parsed;

                $profile->refresh();
                $freshCurrent = $this->currentValueString($profile, $bucket, $fieldKey);
                $incomingForStale = $this->resolveIncomingValue($pending, $bucket, $fieldKey);

                if ($this->normalizeCompare($freshCurrent) !== $this->normalizeCompare($expected)) {
                    ConflictPolicy::create([
                        'profile_id' => $profile->id,
                        'field_name' => $fieldKey,
                        'field_type' => $bucket === 'extended' ? 'EXTENDED' : 'CORE',
                        'old_value' => $freshCurrent,
                        'new_value' => $this->scalarToConflictString($incomingForStale),
                        'source' => 'SYSTEM',
                        'detected_at' => now(),
                        'resolution_status' => 'PENDING',
                    ]);
                    $skippedStale++;

                    continue;
                }

                if ($decision === 'reject') {
                    if ($this->removePendingEntry($pending, $bucket, $fieldKey)) {
                        $rejected++;
                    }

                    continue;
                }

                if ($decision === 'flag') {
                    $incoming = $incomingForStale;
                    if ($incoming === null) {
                        $errors[] = "Missing suggestion for {$rowId}";

                        continue;
                    }
                    $old = $this->currentValueString($profile, $bucket, $fieldKey);
                    ConflictPolicy::create([
                        'profile_id' => $profile->id,
                        'field_name' => $fieldKey,
                        'field_type' => $bucket === 'extended' ? 'EXTENDED' : 'CORE',
                        'old_value' => $old,
                        'new_value' => $this->scalarToConflictString($incoming),
                        'source' => 'ADMIN',
                        'detected_at' => now(),
                        'resolution_status' => 'PENDING',
                    ]);
                    $this->removePendingEntry($pending, $bucket, $fieldKey);
                    $flagged++;

                    continue;
                }

                // accept
                if ($bucket === 'extended') {
                    if (! $this->pendingHasExtended($pending, $fieldKey)) {
                        $errors[] = "No extended suggestion: {$fieldKey}";

                        continue;
                    }
                    $incoming = $pending['extended'][$fieldKey];
                } elseif ($bucket === 'cfs') {
                    $row = $this->findCoreFieldSuggestionRow($pending, $fieldKey);
                    if ($row === null) {
                        $errors[] = "No core_field_suggestions row: {$fieldKey}";

                        continue;
                    }
                    $incoming = $row['new_value'] ?? null;
                } else {
                    if (! isset($pending['core'][$fieldKey])) {
                        $errors[] = "No core suggestion: {$fieldKey}";

                        continue;
                    }
                    $incoming = $pending['core'][$fieldKey];
                    if (! in_array($fieldKey, $allowedCore, true)) {
                        $errors[] = "Field not allowed for apply: {$fieldKey}";

                        continue;
                    }
                }

                $snapshot = [
                    'snapshot_schema_version' => 1,
                ];
                if ($bucket === 'extended') {
                    $snapshot['extended_fields'] = [$fieldKey => $incoming];
                } else {
                    $snapshot['core'] = [$fieldKey => $incoming];
                }

                try {
                    $result = $mutation->applyManualSnapshot($profile, $snapshot, $actorId, 'admin');
                    if (($result['mutation_success'] ?? false) !== true || ($result['conflict_detected'] ?? false) === true) {
                        $errors[] = "Apply blocked or conflict for {$fieldKey} (governed path; suggestion left pending).";

                        continue;
                    }
                } catch (\Throwable $e) {
                    Log::warning('AdminSuggestionReviewController::apply mutation failed', [
                        'profile_id' => $profile->id,
                        'field' => $fieldKey,
                        'message' => $e->getMessage(),
                    ]);
                    $errors[] = "Mutation failed for {$fieldKey}";

                    continue;
                }

                $profile->refresh();
                $this->removePendingEntry($pending, $bucket, $fieldKey);
                $applied++;
            }

            $profile->pending_intake_suggestions_json = $pending === [] ? null : $pending;
            $profile->save();
        });

        $msg = "Applied: {$applied}, rejected: {$rejected}, flagged: {$flagged}";
        if ($skippedStale > 0) {
            $msg .= ", stale conflicts created: {$skippedStale}";
        }
        if ($errors !== []) {
            $msg .= '. '.implode(' ', $errors);
        }

        return redirect()
            ->route('admin.suggestions.review', $intake)
            ->with($applied > 0 || $rejected > 0 || $flagged > 0 ? 'success' : 'info', $msg);
    }

    private function authorizeAdmin(): void
    {
        $u = auth()->user();
        if (! $u || ! $u->is_admin) {
            abort(403, 'Admin access required');
        }
    }

    /**
     * @param  array<string, mixed>  $pending
     * @param  array<string, float|int|string>  $confidenceMap
     * @param  list<string>  $conflictFieldNames
     * @return list<array<string, mixed>>
     */
    private function buildReviewFieldRows(
        array $pending,
        MatrimonyProfile $profile,
        array $extendedCurrent,
        array $confidenceMap,
        array $conflictFieldNames,
    ): array {
        $rows = [];

        if (isset($pending['core']) && is_array($pending['core'])) {
            foreach ($pending['core'] as $key => $val) {
                $k = (string) $key;
                $id = 'core::'.$k;
                $cur = $this->profileCoreDisplay($profile, $k);
                $inc = $this->scalarDisplay($val);
                $conf = $this->confidenceForField($confidenceMap, $k);
                $rows[] = [
                    'id' => $id,
                    'scope_label' => 'Core',
                    'field_key' => $k,
                    'current_display' => $cur,
                    'incoming_display' => $inc,
                    'confidence' => $conf,
                    'has_conflict' => in_array($k, $conflictFieldNames, true),
                    'identical' => $this->normalizeCompare($cur) === $this->normalizeCompare($inc),
                ];
            }
        }

        if (isset($pending['extended']) && is_array($pending['extended'])) {
            foreach ($pending['extended'] as $key => $val) {
                $k = (string) $key;
                $id = 'extended::'.$k;
                $cur = $this->scalarDisplay($extendedCurrent[$k] ?? '');
                $inc = $this->scalarDisplay($val);
                $conf = $this->confidenceForField($confidenceMap, $k);
                $rows[] = [
                    'id' => $id,
                    'scope_label' => 'Extended',
                    'field_key' => $k,
                    'current_display' => $cur,
                    'incoming_display' => $inc,
                    'confidence' => $conf,
                    'has_conflict' => in_array($k, $conflictFieldNames, true),
                    'identical' => $this->normalizeCompare($cur) === $this->normalizeCompare($inc),
                ];
            }
        }

        if (isset($pending['core_field_suggestions']) && is_array($pending['core_field_suggestions'])) {
            foreach ($pending['core_field_suggestions'] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $fk = (string) ($row['field'] ?? '');
                if ($fk === '') {
                    continue;
                }
                $id = 'cfs::'.$fk;
                $cur = $this->profileCoreDisplay($profile, $fk);
                if ($cur === '' && isset($row['old_value'])) {
                    $cur = $this->scalarDisplay($row['old_value']);
                }
                $inc = $this->scalarDisplay($row['new_value'] ?? null);
                $conf = $this->confidenceForField($confidenceMap, $fk);
                $rows[] = [
                    'id' => $id,
                    'scope_label' => 'Core (suggestion)',
                    'field_key' => $fk,
                    'current_display' => $cur,
                    'incoming_display' => $inc,
                    'confidence' => $conf,
                    'has_conflict' => in_array($fk, $conflictFieldNames, true),
                    'identical' => $this->normalizeCompare($cur) === $this->normalizeCompare($inc),
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, float|int|string>  $confidenceMap
     */
    private function confidenceForField(array $confidenceMap, string $fieldKey): ?float
    {
        $candidates = ['core.'.$fieldKey, $fieldKey, 'core/'.$fieldKey];
        foreach ($candidates as $c) {
            if (array_key_exists($c, $confidenceMap)) {
                return (float) $confidenceMap[$c];
            }
        }

        return null;
    }

    private function profileCoreDisplay(MatrimonyProfile $profile, string $key): string
    {
        if (! array_key_exists($key, $profile->getAttributes())) {
            return '';
        }

        return $this->scalarDisplay($profile->getAttribute($key));
    }

    private function scalarDisplay(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (is_scalar($v)) {
            return trim((string) $v);
        }
        $enc = json_encode($v, JSON_UNESCAPED_UNICODE);

        return is_string($enc) ? $enc : '';
    }

    private function normalizeCompare(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    }

    /** @return array{0: string, 1: string}|null */
    private function parseRowId(string $rowId): ?array
    {
        $parts = explode('::', $rowId, 2);
        if (count($parts) !== 2) {
            return null;
        }
        $bucket = $parts[0];
        $field = $parts[1];
        if (! in_array($bucket, ['core', 'extended', 'cfs'], true) || $field === '') {
            return null;
        }

        return [$bucket, $field];
    }

    /**
     * @param  array<string, mixed>  $pending
     */
    private function pendingHasExtended(array $pending, string $fieldKey): bool
    {
        return isset($pending['extended']) && is_array($pending['extended'])
            && array_key_exists($fieldKey, $pending['extended']);
    }

    /**
     * @param  array<string, mixed>  $pending
     * @return array<string, mixed>|null
     */
    private function findCoreFieldSuggestionRow(array $pending, string $fieldKey): ?array
    {
        $rows = $pending['core_field_suggestions'] ?? [];
        if (! is_array($rows)) {
            return null;
        }
        foreach ($rows as $row) {
            if (is_array($row) && (string) ($row['field'] ?? '') === $fieldKey) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $pending
     */
    private function resolveIncomingValue(array $pending, string $bucket, string $fieldKey): mixed
    {
        if ($bucket === 'extended' && $this->pendingHasExtended($pending, $fieldKey)) {
            return $pending['extended'][$fieldKey];
        }
        if ($bucket === 'core' && isset($pending['core'][$fieldKey])) {
            return $pending['core'][$fieldKey];
        }
        if ($bucket === 'cfs') {
            $r = $this->findCoreFieldSuggestionRow($pending, $fieldKey);

            return $r['new_value'] ?? null;
        }

        return null;
    }

    private function currentValueString(MatrimonyProfile $profile, string $bucket, string $fieldKey): string
    {
        if ($bucket === 'extended') {
            $vals = ExtendedFieldService::getValuesForProfile($profile);

            return $this->scalarDisplay($vals[$fieldKey] ?? '');
        }

        return $this->profileCoreDisplay($profile, $fieldKey);
    }

    private function scalarToConflictString(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }

        return $this->scalarDisplay($v);
    }

    /**
     * @param  array<string, mixed>  $pending
     */
    private function removePendingEntry(array &$pending, string $bucket, string $fieldKey): bool
    {
        $removed = false;
        if ($bucket === 'core' && isset($pending['core'][$fieldKey])) {
            unset($pending['core'][$fieldKey]);
            if ($pending['core'] === []) {
                unset($pending['core']);
            }
            $removed = true;
        } elseif ($bucket === 'extended' && isset($pending['extended'][$fieldKey])) {
            unset($pending['extended'][$fieldKey]);
            if ($pending['extended'] === []) {
                unset($pending['extended']);
            }
            $removed = true;
        } elseif ($bucket === 'cfs') {
            if (isset($pending['core_field_suggestions']) && is_array($pending['core_field_suggestions'])) {
                $before = count($pending['core_field_suggestions']);
                $pending['core_field_suggestions'] = array_values(array_filter(
                    $pending['core_field_suggestions'],
                    static fn ($row) => ! is_array($row) || (string) ($row['field'] ?? '') !== $fieldKey
                ));
                if (count($pending['core_field_suggestions']) < $before) {
                    $removed = true;
                }
                if ($pending['core_field_suggestions'] === []) {
                    unset($pending['core_field_suggestions']);
                }
            }
            if (isset($pending['core'][$fieldKey])) {
                unset($pending['core'][$fieldKey]);
                if ($pending['core'] === []) {
                    unset($pending['core']);
                }
                $removed = true;
            }
        }

        return $removed;
    }
}
