<?php

namespace App\Services\Intake;

use App\Models\MatrimonyProfile;
use App\Services\ExtendedFieldService;
use App\Services\MutationService;
use App\Services\ProfileFieldLockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Applies or clears profile.pending_intake_suggestions_json using MutationService (admin mode)
 * and IntakePipelineService normalization. Skips locked core/extended keys and verified primary contact paths.
 */
class IntakeApprovalService
{
    /** Top-level snapshot keys stored in pending merge (besides core / extended / core_field_suggestions / entities). */
    private const PENDING_SNAPSHOT_BLOBS = [
        'birth_place',
        'native_place',
        'contacts',
        'preferences',
        'extended_narrative',
        'other_relatives_text',
    ];

    /** @var list<string> */
    private const ENTITY_KEYS = [
        'children',
        'siblings',
        'marriages',
        'relatives',
        'alliance_networks',
        'education_history',
        'career_history',
        'addresses',
        'property_summary',
        'property_assets',
        'horoscope',
    ];

    public function __construct(
        private IntakePipelineService $pipeline,
        private MutationService $mutation,
    ) {}

    /**
     * @param  list<string>  $tokens  Row ids: core::key, extended::key, cfs::key, blob::birth_place, entity::siblings, …
     * @return array{applied: int, skipped: list<string>, errors: list<string>}
     */
    public function applyApprovedFields(MatrimonyProfile $profile, array $tokens, int $actorUserId): array
    {
        $tokens = $this->uniqueTokens($tokens);
        $applied = 0;
        $skipped = [];
        $errors = [];

        DB::transaction(function () use ($profile, $tokens, $actorUserId, &$applied, &$skipped, &$errors): void {
            $profile->refresh();
            $pending = $profile->pending_intake_suggestions_json;
            if (! is_array($pending) || $pending === []) {
                return;
            }

            foreach ($tokens as $token) {
                $token = (string) $token;
                $r = $this->applyOneToken($profile, $pending, $token, $actorUserId);
                if ($r['ok']) {
                    $applied++;
                } elseif ($r['skip'] ?? false) {
                    $skipped[] = $r['message'] ?? $token;
                } else {
                    $errors[] = $r['message'] ?? "Failed: {$token}";
                }
            }

            $profile->pending_intake_suggestions_json = $pending === [] ? null : $pending;
            $profile->save();
        });

        return ['applied' => $applied, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * @return array{applied: int, skipped: list<string>, errors: list<string>}
     */
    public function applyAll(MatrimonyProfile $profile, int $actorUserId): array
    {
        $rows = $this->buildReviewRows($profile);

        return $this->applyApprovedFields($profile, array_column($rows, 'id'), $actorUserId);
    }

    /**
     * @param  list<string>  $tokens
     * @return array{removed: int, errors: list<string>}
     */
    public function rejectFields(MatrimonyProfile $profile, array $tokens): array
    {
        $tokens = $this->uniqueTokens($tokens);
        $removed = 0;
        $errors = [];

        DB::transaction(function () use ($profile, $tokens, &$removed, &$errors): void {
            $profile->refresh();
            $pending = $profile->pending_intake_suggestions_json;
            if (! is_array($pending)) {
                return;
            }

            foreach ($tokens as $token) {
                $token = (string) $token;
                if ($this->removeTokenFromPending($pending, $token)) {
                    $removed++;
                } else {
                    $errors[] = "Unknown or already removed: {$token}";
                }
            }

            $profile->pending_intake_suggestions_json = $pending === [] ? null : $pending;
            $profile->save();
        });

        return ['removed' => $removed, 'errors' => $errors];
    }

    public function clearAll(MatrimonyProfile $profile): void
    {
        DB::transaction(function () use ($profile): void {
            $profile->refresh();
            $profile->pending_intake_suggestions_json = null;
            $profile->save();
        });
    }

    public function countPendingSuggestions(MatrimonyProfile $profile): int
    {
        return count($this->buildReviewRows($profile));
    }

    /**
     * @return list<array{id: string, group: string, group_label: string, field_key: string, old_display: string, new_display: string, locked: bool, verified_skip: bool}>
     */
    public function buildReviewRows(MatrimonyProfile $profile): array
    {
        $pending = $profile->pending_intake_suggestions_json;
        if (! is_array($pending) || $pending === []) {
            return [];
        }

        $extendedCurrent = ExtendedFieldService::getValuesForProfile($profile);
        $rows = [];
        $seenCore = [];

        if (isset($pending['core']) && is_array($pending['core'])) {
            foreach ($pending['core'] as $key => $val) {
                $k = (string) $key;
                $seenCore[$k] = true;
                $cfs = $this->findCoreFieldSuggestionRow($pending, $k);
                $oldDisp = $this->scalarDisplay($this->profileCoreDisplay($profile, $k));
                if ($oldDisp === '' && $cfs !== null) {
                    $oldDisp = $this->scalarDisplay($cfs['old_value'] ?? null);
                }
                $newDisp = $this->scalarDisplay($val);
                $rows[] = $this->makeRow(
                    'core::'.$k,
                    'core',
                    'Core',
                    $k,
                    $oldDisp,
                    $newDisp,
                    $profile
                );
            }
        }

        if (isset($pending['core_field_suggestions']) && is_array($pending['core_field_suggestions'])) {
            foreach ($pending['core_field_suggestions'] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $fk = (string) ($row['field'] ?? '');
                if ($fk === '' || isset($seenCore[$fk])) {
                    continue;
                }
                $oldDisp = $this->scalarDisplay($this->profileCoreDisplay($profile, $fk));
                if ($oldDisp === '') {
                    $oldDisp = $this->scalarDisplay($row['old_value'] ?? null);
                }
                $newDisp = $this->scalarDisplay($row['new_value'] ?? null);
                $rows[] = $this->makeRow(
                    'cfs::'.$fk,
                    'core',
                    'Core (suggestion)',
                    $fk,
                    $oldDisp,
                    $newDisp,
                    $profile
                );
            }
        }

        if (isset($pending['extended']) && is_array($pending['extended'])) {
            foreach ($pending['extended'] as $key => $val) {
                $k = (string) $key;
                $cur = $extendedCurrent[$k] ?? null;
                $rows[] = $this->makeRow(
                    'extended::'.$k,
                    'extended',
                    'Extended',
                    $k,
                    $this->scalarDisplay($cur),
                    $this->scalarDisplay($val),
                    $profile
                );
            }
        }

        foreach (self::PENDING_SNAPSHOT_BLOBS as $blobKey) {
            if (! array_key_exists($blobKey, $pending) || $pending[$blobKey] === null || $pending[$blobKey] === '' || $pending[$blobKey] === []) {
                continue;
            }
            $oldDisp = $this->blobOldDisplay($profile, $blobKey);
            $newDisp = $this->scalarDisplay($pending[$blobKey]);
            $rows[] = $this->makeRow(
                'blob::'.$blobKey,
                'snapshot',
                'Snapshot',
                $blobKey,
                $oldDisp,
                $newDisp,
                $profile
            );
        }

        if (isset($pending['entities']) && is_array($pending['entities'])) {
            foreach (self::ENTITY_KEYS as $entityKey) {
                if (! array_key_exists($entityKey, $pending['entities']) || ! is_array($pending['entities'][$entityKey]) || $pending['entities'][$entityKey] === []) {
                    continue;
                }
                $payload = $pending['entities'][$entityKey];
                $rows[] = $this->makeRow(
                    'entity::'.$entityKey,
                    'entities',
                    'Entities',
                    $entityKey,
                    '—',
                    $this->scalarDisplay($payload),
                    $profile
                );
            }
        }

        return $rows;
    }

    /**
     * @return array{id: string, group: string, group_label: string, field_key: string, old_display: string, new_display: string, locked: bool, verified_skip: bool}
     */
    private function makeRow(
        string $id,
        string $group,
        string $groupLabel,
        string $fieldKey,
        string $oldDisplay,
        string $newDisplay,
        MatrimonyProfile $profile,
    ): array {
        $locked = $this->isTokenLocked($profile, $id);
        $verifiedSkip = $this->isTokenVerifiedBlocked($profile, $id);

        return [
            'id' => $id,
            'group' => $group,
            'group_label' => $groupLabel,
            'field_key' => $fieldKey,
            'old_display' => $oldDisplay,
            'new_display' => $newDisplay,
            'locked' => $locked,
            'verified_skip' => $verifiedSkip,
        ];
    }

    private function isTokenLocked(MatrimonyProfile $profile, string $token): bool
    {
        $parsed = $this->parseToken($token);
        if ($parsed === null) {
            return false;
        }
        [$kind, $key] = $parsed;
        if ($kind === 'core' || $kind === 'cfs') {
            return ProfileFieldLockService::isLocked($profile, $key);
        }
        if ($kind === 'extended') {
            return ProfileFieldLockService::isLocked($profile, $key);
        }

        return false;
    }

    private function isTokenVerifiedBlocked(MatrimonyProfile $profile, string $token): bool
    {
        $parsed = $this->parseToken($token);
        if ($parsed === null) {
            return false;
        }
        [$kind, $key] = $parsed;
        if (! $this->isPrimaryContactVerified((int) $profile->id)) {
            return false;
        }
        if ($kind === 'blob' && $key === 'contacts') {
            return true;
        }
        if (($kind === 'core' || $kind === 'cfs') && $key === 'primary_contact_number') {
            return true;
        }

        return false;
    }

    private function isPrimaryContactVerified(int $profileId): bool
    {
        if (! Schema::hasTable('profile_contacts')) {
            return false;
        }
        $row = DB::table('profile_contacts')
            ->where('profile_id', $profileId)
            ->where('is_primary', true)
            ->first();

        return $row !== null && (bool) ($row->verified_status ?? false);
    }

    /**
     * @param  array<string, mixed>  $pending
     * @return array{ok: bool, skip?: bool, message?: string}
     */
    private function applyOneToken(MatrimonyProfile $profile, array &$pending, string $token, int $actorUserId): array
    {
        if ($this->isTokenLocked($profile, $token)) {
            return ['ok' => false, 'skip' => true, 'message' => "Skipped locked: {$token}"];
        }
        if ($this->isTokenVerifiedBlocked($profile, $token)) {
            return ['ok' => false, 'skip' => true, 'message' => "Skipped verified contact: {$token}"];
        }

        $parsed = $this->parseToken($token);
        if ($parsed === null) {
            return ['ok' => false, 'message' => 'Invalid token'];
        }

        [$kind, $key] = $parsed;
        $allowedCore = $this->mutation->coreFieldKeysAllowedForIntakeSuggestionApply();

        try {
            if ($kind === 'core') {
                if (! isset($pending['core'][$key])) {
                    return ['ok' => false, 'message' => "No pending core value: {$key}"];
                }
                if (! in_array($key, $allowedCore, true)) {
                    return ['ok' => false, 'message' => "Core field not allowed for intake apply: {$key}"];
                }
                $rawSnapshot = [
                    'snapshot_schema_version' => 1,
                    'core' => [$key => $pending['core'][$key]],
                ];
                $snapshot = $this->pipeline->normalizeSnapshotForStorage($rawSnapshot, $actorUserId);
                $coreOut = $snapshot['core'] ?? [];
                if (! array_key_exists($key, $coreOut)) {
                    return ['ok' => false, 'message' => "Normalize dropped core key: {$key}"];
                }
                $applySnapshot = [
                    'snapshot_schema_version' => 1,
                    'core' => [$key => $coreOut[$key]],
                ];
            } elseif ($kind === 'cfs') {
                $row = $this->findCoreFieldSuggestionRow($pending, $key);
                if ($row === null) {
                    return ['ok' => false, 'message' => "No cfs row: {$key}"];
                }
                if (! in_array($key, $allowedCore, true)) {
                    return ['ok' => false, 'message' => "Core field not allowed for intake apply: {$key}"];
                }
                $incoming = $row['new_value'] ?? ($pending['core'][$key] ?? null);
                $rawSnapshot = [
                    'snapshot_schema_version' => 1,
                    'core' => [$key => $incoming],
                ];
                $snapshot = $this->pipeline->normalizeSnapshotForStorage($rawSnapshot, $actorUserId);
                $coreOut = $snapshot['core'] ?? [];
                if (! array_key_exists($key, $coreOut)) {
                    return ['ok' => false, 'message' => "Normalize dropped cfs key: {$key}"];
                }
                $applySnapshot = [
                    'snapshot_schema_version' => 1,
                    'core' => [$key => $coreOut[$key]],
                ];
            } elseif ($kind === 'extended') {
                if (! isset($pending['extended']) || ! is_array($pending['extended']) || ! array_key_exists($key, $pending['extended'])) {
                    return ['ok' => false, 'message' => "No pending extended: {$key}"];
                }
                $applySnapshot = [
                    'snapshot_schema_version' => 1,
                    'extended_fields' => [$key => $pending['extended'][$key]],
                ];
            } elseif ($kind === 'blob') {
                if (! in_array($key, self::PENDING_SNAPSHOT_BLOBS, true) || ! array_key_exists($key, $pending)) {
                    return ['ok' => false, 'message' => "No pending blob: {$key}"];
                }
                if ($key === 'other_relatives_text') {
                    $raw = [
                        'snapshot_schema_version' => 1,
                        'core' => ['other_relatives_text' => $pending[$key]],
                    ];
                    $normalized = $this->pipeline->normalizeSnapshotForStorage($raw, $actorUserId);
                    $val = $normalized['core']['other_relatives_text'] ?? $pending[$key];
                    $applySnapshot = [
                        'snapshot_schema_version' => 1,
                        'core' => ['other_relatives_text' => $val],
                    ];
                } else {
                    $raw = [
                        'snapshot_schema_version' => 1,
                        $key => $pending[$key],
                    ];
                    $normalized = $this->pipeline->normalizeSnapshotForStorage($raw, $actorUserId);
                    $val = $normalized[$key] ?? $pending[$key];
                    $applySnapshot = [
                        'snapshot_schema_version' => 1,
                        $key => $val,
                    ];
                }
            } elseif ($kind === 'entity') {
                if (! isset($pending['entities']) || ! is_array($pending['entities']) || ! isset($pending['entities'][$key])) {
                    return ['ok' => false, 'message' => "No pending entity: {$key}"];
                }
                if (! in_array($key, self::ENTITY_KEYS, true)) {
                    return ['ok' => false, 'message' => "Unknown entity key: {$key}"];
                }
                $raw = [
                    'snapshot_schema_version' => 1,
                    $key => $pending['entities'][$key],
                ];
                $normalized = $this->pipeline->normalizeSnapshotForStorage($raw, $actorUserId);
                $val = $normalized[$key] ?? $pending['entities'][$key];
                $applySnapshot = [
                    'snapshot_schema_version' => 1,
                    $key => $val,
                ];
            } else {
                return ['ok' => false, 'message' => 'Unsupported token kind'];
            }

            $result = $this->mutation->applyManualSnapshot($profile, $applySnapshot, $actorUserId, 'admin');
            if (($result['mutation_success'] ?? false) !== true || ($result['conflict_detected'] ?? false) === true) {
                return ['ok' => false, 'message' => "Mutation blocked or conflict for {$token}"];
            }

            $profile->refresh();
            $this->removeTokenFromPending($pending, $token);

            return ['ok' => true];
        } catch (\Throwable $e) {
            Log::warning('IntakeApprovalService::applyOneToken failed', [
                'profile_id' => $profile->id,
                'token' => $token,
                'message' => $e->getMessage(),
            ]);

            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @param  array<string, mixed>  $pending
     */
    private function removeTokenFromPending(array &$pending, string $token): bool
    {
        $parsed = $this->parseToken($token);
        if ($parsed === null) {
            return false;
        }
        [$kind, $key] = $parsed;

        if ($kind === 'core' || $kind === 'cfs') {
            return $this->removeCoreLike($pending, $key);
        }
        if ($kind === 'extended') {
            if (! isset($pending['extended'][$key])) {
                return false;
            }
            unset($pending['extended'][$key]);
            if ($pending['extended'] === []) {
                unset($pending['extended']);
            }

            return true;
        }
        if ($kind === 'blob') {
            if (! array_key_exists($key, $pending)) {
                return false;
            }
            unset($pending[$key]);

            return true;
        }
        if ($kind === 'entity') {
            if (! isset($pending['entities']) || ! is_array($pending['entities']) || ! array_key_exists($key, $pending['entities'])) {
                return false;
            }
            unset($pending['entities'][$key]);
            if ($pending['entities'] === []) {
                unset($pending['entities']);
            }

            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $pending
     */
    private function removeCoreLike(array &$pending, string $fieldKey): bool
    {
        $removed = false;
        if (isset($pending['core'][$fieldKey])) {
            unset($pending['core'][$fieldKey]);
            if ($pending['core'] === []) {
                unset($pending['core']);
            }
            $removed = true;
        }
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

        return $removed;
    }

    /** @return array{0: string, 1: string}|null */
    private function parseToken(string $token): ?array
    {
        $parts = explode('::', $token, 2);
        if (count($parts) !== 2 || $parts[1] === '') {
            return null;
        }
        $kind = $parts[0];
        if (! in_array($kind, ['core', 'extended', 'cfs', 'blob', 'entity'], true)) {
            return null;
        }

        return [$kind, $parts[1]];
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

    private function blobOldDisplay(MatrimonyProfile $profile, string $blobKey): string
    {
        return match ($blobKey) {
            'birth_place' => $this->scalarDisplay([
                'birth_city_id' => $profile->birth_city_id,
                'birth_taluka_id' => $profile->birth_taluka_id,
                'birth_district_id' => $profile->birth_district_id,
                'birth_state_id' => $profile->birth_state_id,
                'text' => $profile->birth_place_text,
            ]),
            'native_place' => $this->scalarDisplay([
                'native_city_id' => $profile->native_city_id,
                'native_taluka_id' => $profile->native_taluka_id,
                'native_district_id' => $profile->native_district_id,
                'native_state_id' => $profile->native_state_id,
            ]),
            'other_relatives_text' => $this->scalarDisplay($profile->other_relatives_text ?? ''),
            default => '—',
        };
    }

    /**
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private function uniqueTokens(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $t) {
            $t = (string) $t;
            if ($t !== '' && ! in_array($t, $out, true)) {
                $out[] = $t;
            }
        }

        return $out;
    }
}
