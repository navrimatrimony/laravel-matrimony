<?php

namespace App\Services\Matching;

use App\Support\SchemaPresence;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a seeker's community intent — caste-locked / religion-locked / open (PO ruling 2026-07-26).
 *
 * `profile_partner_community_flags.interested_in_intercaste` and
 * `partner_preference_metadata.strictness_json` were written by onboarding but read by nobody in the
 * matching engine, so a refusal of intercaste marriage had no effect on the feed. This resolves that
 * intent once, in bulk, and {@see MatchingService} applies it as a hard filter while
 * {@see \App\Services\ProfilePreferenceMatchService} mirrors it on the soft path.
 *
 * ⚠️ The flag column is `boolean default(false)`, so "never asked" is byte-identical to "said no".
 * A lock is therefore raised ONLY on an explicit signal:
 *   1. the flag row EXISTS and `interested_in_intercaste = false`;
 *   2. `strictness_json` marks caste (or religion) as required — either the enum shape
 *      (`caste => 'required'`) or the legacy boolean shape (`same_caste_expected => true`);
 *   3. `profile_preferred_castes` contains only the seeker's own caste — but see the guard below.
 *
 * Signal 3 needs the guard because registration auto-seeds `profile_preferred_castes` with the
 * seeker's own caste at strictness `preferred`. Honouring it blindly would caste-lock essentially the
 * entire base, which is exactly the failure mode this class exists to avoid. It is therefore ignored
 * whenever the metadata explicitly says that caste strictness is `preferred` or `open`.
 *
 * An absent row, or a false-by-default row, never locks anyone.
 */
final class CommunityLockResolver
{
    public const SIGNAL_INTERCASTE_REFUSAL = 'explicit_intercaste_refusal';

    public const SIGNAL_STRICTNESS_CASTE = 'strictness_caste_required';

    public const SIGNAL_STRICTNESS_RELIGION = 'strictness_religion_required';

    public const SIGNAL_OWN_CASTE_ONLY = 'own_caste_only_pivot';

    /**
     * Shape of a resolved intent. `allowed_*_ids` is the set a locked seeker may still be shown.
     *
     * @return array{caste_locked: bool, religion_locked: bool, allowed_caste_ids: list<int>, allowed_religion_ids: list<int>, signals: list<string>}
     */
    public static function open(): array
    {
        return [
            'caste_locked' => false,
            'religion_locked' => false,
            'allowed_caste_ids' => [],
            'allowed_religion_ids' => [],
            'signals' => [],
        ];
    }

    public static function enabled(): bool
    {
        return (bool) config('matching.community_lock.enabled', true);
    }

    private static function signalEnabled(string $signal): bool
    {
        return (bool) config('matching.community_lock.signals.'.$signal, true);
    }

    /**
     * Bulk resolution — one query per source table for the whole candidate set, never per candidate.
     *
     * @param  list<int>  $profileIds
     * @param  array<int, list<int>>  $preferredCasteIdsByProfile  Already loaded by the caller's bulk preference loader.
     * @param  array<int, list<int>>  $preferredReligionIdsByProfile
     * @return array<int, array{caste_locked: bool, religion_locked: bool, allowed_caste_ids: list<int>, allowed_religion_ids: list<int>, signals: list<string>}>
     */
    public static function resolveMany(
        array $profileIds,
        array $preferredCasteIdsByProfile = [],
        array $preferredReligionIdsByProfile = [],
    ): array {
        $profileIds = array_values(array_unique(array_filter(array_map('intval', $profileIds))));

        $out = [];
        foreach ($profileIds as $id) {
            $out[$id] = self::open();
        }
        if ($profileIds === [] || ! self::enabled()) {
            return $out;
        }

        $ownCommunity = self::ownCommunityByProfile($profileIds);
        $flagRows = self::intercasteFlagByProfile($profileIds);
        $strictness = self::strictnessByProfile($profileIds);

        foreach ($profileIds as $id) {
            $ownCasteId = (int) ($ownCommunity[$id]['caste_id'] ?? 0);
            $ownReligionId = (int) ($ownCommunity[$id]['religion_id'] ?? 0);
            $preferredCasteIds = self::intList($preferredCasteIdsByProfile[$id] ?? []);
            $preferredReligionIds = self::intList($preferredReligionIdsByProfile[$id] ?? []);

            $signals = [];
            $casteLocked = false;
            $religionLocked = false;

            // Signal 1 — the seeker was asked and said no. Row must EXIST; absence is silence.
            if (self::signalEnabled(self::SIGNAL_INTERCASTE_REFUSAL)
                && array_key_exists($id, $flagRows)
                && $flagRows[$id] === false) {
                $casteLocked = true;
                $signals[] = self::SIGNAL_INTERCASTE_REFUSAL;
            }

            // Signal 2 — the seeker's own strictness answer.
            if (self::signalEnabled(self::SIGNAL_STRICTNESS_CASTE)
                && self::strictnessRequires($strictness[$id] ?? null, 'caste', 'same_caste_expected')) {
                $casteLocked = true;
                $signals[] = self::SIGNAL_STRICTNESS_CASTE;
            }
            if (self::signalEnabled(self::SIGNAL_STRICTNESS_RELIGION)
                && self::strictnessRequires($strictness[$id] ?? null, 'religion', 'same_religion_expected')) {
                $religionLocked = true;
                $signals[] = self::SIGNAL_STRICTNESS_RELIGION;
            }

            // Signal 3 — pivot narrowed to the seeker's own caste, and metadata does not contradict it.
            if (self::signalEnabled(self::SIGNAL_OWN_CASTE_ONLY)
                && $ownCasteId > 0
                && $preferredCasteIds === [$ownCasteId]
                && ! self::strictnessLooserThanRequired($strictness[$id] ?? null, 'caste', 'same_caste_expected')) {
                $casteLocked = true;
                $signals[] = self::SIGNAL_OWN_CASTE_ONLY;
            }

            // A caste lock implies its religion: you cannot demand the same caste across religions.
            if ($casteLocked) {
                $religionLocked = true;
            }

            if (! $casteLocked && ! $religionLocked) {
                continue;
            }

            $allowedCasteIds = $preferredCasteIds !== []
                ? $preferredCasteIds
                : ($ownCasteId > 0 ? [$ownCasteId] : []);
            $allowedReligionIds = $preferredReligionIds !== []
                ? $preferredReligionIds
                : ($ownReligionId > 0 ? [$ownReligionId] : []);

            // A lock we cannot express (seeker's own community unknown) must not empty the feed.
            if ($casteLocked && $allowedCasteIds === []) {
                $casteLocked = false;
            }
            if ($religionLocked && $allowedReligionIds === []) {
                $religionLocked = false;
            }

            $out[$id] = [
                'caste_locked' => $casteLocked,
                'religion_locked' => $religionLocked,
                'allowed_caste_ids' => $allowedCasteIds,
                'allowed_religion_ids' => $allowedReligionIds,
                'signals' => array_values(array_unique($signals)),
            ];
        }

        return $out;
    }

    /**
     * Single-profile convenience for the non-batch path
     * ({@see \App\Services\ProfilePreferenceMatchService::loadTargetPreferences()}).
     *
     * @param  list<int>  $preferredCasteIds
     * @param  list<int>  $preferredReligionIds
     * @return array{caste_locked: bool, religion_locked: bool, allowed_caste_ids: list<int>, allowed_religion_ids: list<int>, signals: list<string>}
     */
    public static function resolveOne(int $profileId, array $preferredCasteIds = [], array $preferredReligionIds = []): array
    {
        $map = self::resolveMany(
            [$profileId],
            [$profileId => $preferredCasteIds],
            [$profileId => $preferredReligionIds],
        );

        return $map[$profileId] ?? self::open();
    }

    /**
     * @param  list<int>  $profileIds
     * @return array<int, array{caste_id: int, religion_id: int}>
     */
    private static function ownCommunityByProfile(array $profileIds): array
    {
        $out = [];
        if (! SchemaPresence::hasTable('matrimony_profiles')) {
            return $out;
        }
        $rows = DB::table('matrimony_profiles')
            ->select(['id', 'caste_id', 'religion_id'])
            ->whereIn('id', $profileIds)
            ->get();
        foreach ($rows as $row) {
            $out[(int) $row->id] = [
                'caste_id' => (int) ($row->caste_id ?? 0),
                'religion_id' => (int) ($row->religion_id ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Only profiles that actually have a flag row appear in the result — a missing key means the
     * seeker was never asked, which is NOT a refusal.
     *
     * @param  list<int>  $profileIds
     * @return array<int, bool>
     */
    private static function intercasteFlagByProfile(array $profileIds): array
    {
        $out = [];
        if (! SchemaPresence::hasTable('profile_partner_community_flags')) {
            return $out;
        }
        $rows = DB::table('profile_partner_community_flags')
            ->select(['profile_id', 'interested_in_intercaste'])
            ->whereIn('profile_id', $profileIds)
            ->get();
        foreach ($rows as $row) {
            $out[(int) $row->profile_id] = (bool) $row->interested_in_intercaste;
        }

        return $out;
    }

    /**
     * Declared preference strictness per profile, straight from `partner_preference_metadata`.
     * Exposed so callers that already need it (income / height "must match" checks) do not repeat
     * the query.
     *
     * @param  list<int>  $profileIds
     * @return array<int, array<string, mixed>>
     */
    public static function strictnessMapFor(array $profileIds): array
    {
        return self::strictnessByProfile(array_values(array_unique(array_filter(array_map('intval', $profileIds)))));
    }

    /**
     * True when the seeker explicitly declared this preference field as must-match. Used to keep a
     * field excludable at the strict tier even after {@see MatchRelaxationLadder} makes it soft.
     *
     * @param  array<string, mixed>|null  $strictness
     */
    public static function declaredMustMatch(?array $strictness, string $field): bool
    {
        if ($strictness === null) {
            return false;
        }

        $value = $strictness[$field] ?? null;
        if (is_bool($value)) {
            return $value === true;
        }

        return is_string($value) && in_array(strtolower(trim($value)), ['required', 'must_match'], true);
    }

    /**
     * @param  list<int>  $profileIds
     * @return array<int, array<string, mixed>>
     */
    private static function strictnessByProfile(array $profileIds): array
    {
        $out = [];
        if (! SchemaPresence::hasTable('partner_preference_metadata')) {
            return $out;
        }
        $rows = DB::table('partner_preference_metadata')
            ->select(['matrimony_profile_id', 'strictness_json'])
            ->whereIn('matrimony_profile_id', $profileIds)
            ->get();
        foreach ($rows as $row) {
            $raw = $row->strictness_json ?? null;
            $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
            $out[(int) $row->matrimony_profile_id] = is_array($decoded) ? $decoded : [];
        }

        return $out;
    }

    /**
     * True when the metadata explicitly demands the same community. Supports both shapes that exist
     * in the wild: the enum (`caste => 'required'`) and the legacy boolean (`same_caste_expected`).
     *
     * @param  array<string, mixed>|null  $strictness
     */
    private static function strictnessRequires(?array $strictness, string $enumKey, string $legacyKey): bool
    {
        if ($strictness === null) {
            return false;
        }
        if (array_key_exists($legacyKey, $strictness) && $strictness[$legacyKey] === true) {
            return true;
        }

        $enum = $strictness[$enumKey] ?? null;

        return is_string($enum) && in_array(strtolower(trim($enum)), ['required', 'must_match'], true);
    }

    /**
     * True when the metadata explicitly says this community field is NOT required — the guard that
     * stops the auto-seeded `preferred` pivot from locking the whole base.
     *
     * @param  array<string, mixed>|null  $strictness
     */
    private static function strictnessLooserThanRequired(?array $strictness, string $enumKey, string $legacyKey): bool
    {
        if ($strictness === null) {
            return false;
        }
        if (array_key_exists($legacyKey, $strictness) && $strictness[$legacyKey] === false) {
            return true;
        }

        $enum = $strictness[$enumKey] ?? null;

        return is_string($enum) && in_array(strtolower(trim($enum)), ['preferred', 'open'], true);
    }

    /**
     * @param  iterable<mixed>  $ids
     * @return list<int>
     */
    private static function intList(iterable $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $v = (int) $id;
            if ($v > 0) {
                $out[$v] = true;
            }
        }

        return array_keys($out);
    }
}
