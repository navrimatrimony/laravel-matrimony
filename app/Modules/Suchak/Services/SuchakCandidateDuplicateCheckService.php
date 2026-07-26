<?php

namespace App\Modules\Suchak\Services;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakProfileRepresentation;
use App\Support\NameMatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-create duplicate check for the Suchak Add-Customer flow
 * (PO decision 2026-07-22).
 *
 * Scoring model, per the approved decisions:
 * - Mobile alone is NOT decisive — rural candidates share a father's/brother's
 *   number, so three sisters may legitimately sit on one number. A mobile hit
 *   is therefore combined with name+DOB+gender before calling it "confirmed".
 * - name(fuzzy) + DOB + gender together ≈ 80% duplicate likelihood → 'high'.
 * - DOB+gender with NO name overlap is deliberately dropped: in a large pool
 *   same-day-same-gender strangers are common and would flood the Suchak with
 *   noise. (The step-4 recheck with village+caste is the planned second net.)
 * - The service only reports; it NEVER blocks — the Suchak decides.
 *
 * Confidence tiers (contract with the app):
 * - confirmed / high  → strong enough for the app to HARD-STOP onboarding and
 *                       offer the consent-on-existing-profile branch.
 * - medium / low      → advisory only; the app shows the evidence and lets the
 *                       Suchak continue as a different person.
 *
 * Ownership (2026-07-26): every match also reports WHO holds the profile —
 * mine / other_suchak / platform_member / unrepresented — so the app can pick
 * the right branch (open my customer, request consent, collaborate).
 *
 * Reuse notes (one-engine rule): mobile-store lookups mirror
 * DuplicateDetectionService's profile_contacts pattern extended with the
 * parent slots and sibling numbers documented in PRODUCT_MAP §5; name
 * fuzzing lives in the shared App\Support\NameMatcher; "actively represented"
 * and "may reveal the other Suchak" reuse the model scopes withValidConsent()
 * and publiclyRoutable() (same predicates as SuchakCrossSearchService), not a
 * private copy.
 */
final class SuchakCandidateDuplicateCheckService
{
    public const CONFIDENCE_CONFIRMED = 'confirmed';

    public const CONFIDENCE_HIGH = 'high';

    public const CONFIDENCE_MEDIUM = 'medium';

    public const CONFIDENCE_LOW = 'low';

    /** Tiers the app may hard-stop onboarding on. */
    public const HARD_STOP_CONFIDENCES = [
        self::CONFIDENCE_CONFIRMED,
        self::CONFIDENCE_HIGH,
    ];

    public const OWNER_MINE = 'mine';

    public const OWNER_OTHER_SUCHAK = 'other_suchak';

    public const OWNER_PLATFORM_MEMBER = 'platform_member';

    public const OWNER_UNREPRESENTED = 'unrepresented';

    private const MAX_MATCHES = 5;

    private const IDENTITY_SCAN_LIMIT = 300;

    /**
     * @param  array{location_id?: int|null, caste_id?: int|null}  $options
     *                                                                       Weak secondary signals (village/caste). Optional — they only ever
     *                                                                       upgrade a DOB-less name hit from 'low' to 'medium'.
     * @return array{matches: array<int, array<string, mixed>>, match_count: int, hard_stop: bool}
     */
    public function check(
        string $normalizedMobile,
        string $candidateName,
        ?string $dateOfBirth,
        ?string $genderKey,
        SuchakAccount $account,
        array $options = [],
    ): array {
        // Both are optional columns in this schema — guard, never assume.
        $locationId = isset($options['location_id']) && Schema::hasColumn('matrimony_profiles', 'location_id')
            ? (int) $options['location_id']
            : null;
        $casteId = isset($options['caste_id']) && Schema::hasColumn('matrimony_profiles', 'caste_id')
            ? (int) $options['caste_id']
            : null;

        /** @var array<int, array<string, mixed>> $rows profile_id => working row */
        $rows = [];

        foreach ($this->mobileHits($normalizedMobile) as $profileId => $sources) {
            $rows[$profileId] = $this->emptyRow($sources);
        }

        foreach ($this->identityCandidates($candidateName, $dateOfBirth, $genderKey, $locationId, $casteId) as $candidate) {
            $level = NameMatcher::matchLevel($candidateName, (string) $candidate->full_name);
            if ($level === NameMatcher::LEVEL_NONE) {
                continue;
            }
            $profileId = (int) $candidate->id;
            $rows[$profileId] ??= $this->emptyRow([]);
            $rows[$profileId]['name_match'] = $level;
            $rows[$profileId]['dob_match'] = (string) $candidate->dob_match;
        }

        if ($rows === []) {
            return ['matches' => [], 'match_count' => 0, 'hard_stop' => false];
        }

        $profiles = MatrimonyProfile::query()
            ->with(['gender', 'location.parent.parent.parent'])
            ->whereIn('id', array_keys($rows))
            ->get()
            ->keyBy('id');

        $ownership = $this->ownershipMap(array_keys($rows), $account);

        $genderIdToKey = $this->genderKeyMap();
        $matches = [];
        foreach ($rows as $profileId => $row) {
            $profile = $profiles->get($profileId);
            if ($profile === null) {
                continue;
            }

            // Fill identity signals for pure mobile hits too.
            if ($row['name_match'] === NameMatcher::LEVEL_NONE) {
                $row['name_match'] = NameMatcher::matchLevel($candidateName, (string) $profile->full_name);
            }
            if ($row['dob_match'] === 'none') {
                $row['dob_match'] = $this->dobMatchLevel($dateOfBirth, $profile->date_of_birth);
            }
            $storedGenderKey = $genderIdToKey[(int) $profile->gender_id] ?? null;
            $row['gender_match'] = ($genderKey !== null && $storedGenderKey !== null)
                ? ($genderKey === $storedGenderKey)
                : null;
            $row['soft_match'] = ($locationId !== null && (int) $profile->location_id === $locationId)
                || ($casteId !== null && (int) $profile->caste_id === $casteId);

            $confidence = $this->confidence($row);
            if ($confidence === null) {
                continue;
            }

            /** @var array<string, mixed> $owner */
            $owner = $ownership[$profileId] ?? $this->unknownOwner();

            $matches[] = [
                'profile_id' => $profileId,
                'display_name' => $this->maskName((string) $profile->full_name),
                'age_years' => $this->ageYears($profile->date_of_birth),
                'gender' => $storedGenderKey,
                // A profile actively held by another Suchak only reveals its
                // broad location when that representation is publicly routable
                // (same gate SuchakCrossSearchService uses).
                'location_label' => $owner['owner_type'] === self::OWNER_OTHER_SUCHAK && $owner['owner_is_public'] !== true
                    ? null
                    : (trim((string) $profile->residenceLocationDisplayLine()) ?: null),
                'confidence' => $confidence,
                'is_hard_stop' => in_array($confidence, self::HARD_STOP_CONFIDENCES, true),
                'signals' => [
                    'mobile' => $row['mobile_sources'] !== [],
                    'mobile_sources' => array_values(array_unique($row['mobile_sources'])),
                    'name' => $row['name_match'],
                    'dob' => $row['dob_match'],
                    'gender' => $row['gender_match'],
                    'soft' => $row['soft_match'],
                ],
                // Shared family number warning: the number matched, but not as
                // the candidate's own login mobile — could be a sibling/parent.
                'shared_number_possible' => $row['mobile_sources'] !== []
                    && ! in_array('self_mobile', $row['mobile_sources'], true),
                'owner_type' => $owner['owner_type'],
                'owner_suchak_name' => $owner['owner_suchak_name'],
                'already_represented_by_me' => $owner['already_represented_by_me'],
                'representation_id' => $owner['representation_id'],
                // CONSENT-FIRST (2026-07-26): this now means "the app may offer
                // the REQUEST-CONSENT action", not "link this person now" —
                // nothing links until they accept. Requires the typed mobile to
                // be the candidate's own account mobile (consent is delivered
                // there), is never true for a customer another Suchak actively
                // holds, and is pointless once this Suchak already holds a live
                // consented link.
                'can_link_existing' => in_array('self_mobile', $row['mobile_sources'], true)
                    && $owner['owner_type'] !== self::OWNER_OTHER_SUCHAK
                    && $owner['mine_has_valid_consent'] !== true,
            ];
        }

        usort($matches, static function (array $a, array $b): int {
            $rank = [
                self::CONFIDENCE_CONFIRMED => 0,
                self::CONFIDENCE_HIGH => 1,
                self::CONFIDENCE_MEDIUM => 2,
                self::CONFIDENCE_LOW => 3,
            ];

            return [$rank[$a['confidence']] ?? 9, -$a['profile_id']]
                <=> [$rank[$b['confidence']] ?? 9, -$b['profile_id']];
        });
        $matches = array_slice($matches, 0, self::MAX_MATCHES);

        return [
            'matches' => $matches,
            'match_count' => count($matches),
            'hard_stop' => collect($matches)->contains(static fn (array $m): bool => $m['is_hard_stop'] === true),
        ];
    }

    /**
     * @param  array<int, string>  $mobileSources
     * @return array<string, mixed>
     */
    private function emptyRow(array $mobileSources): array
    {
        return [
            'mobile_sources' => $mobileSources,
            'name_match' => NameMatcher::LEVEL_NONE,
            'dob_match' => 'none',
            'gender_match' => null,
            'soft_match' => false,
        ];
    }

    /**
     * Who holds each matched profile.
     *
     * mine             — this Suchak already has a representation on it.
     * other_suchak     — a DIFFERENT Suchak holds an ACTIVE, consented
     *                    representation (scopeWithValidConsent — same predicate
     *                    the rest of the Suchak domain uses). The other Suchak's
     *                    name is revealed only when that representation is
     *                    publiclyRoutable(), i.e. already discoverable in cross
     *                    search; otherwise the app gets the flag alone.
     * platform_member  — nobody represents the profile and the account behind it
     *                    verified itself (self-registered member).
     * unrepresented    — profile exists, nobody represents it, no self signup.
     *
     * @param  array<int, int>  $profileIds
     * @return array<int, array<string, mixed>>
     */
    private function ownershipMap(array $profileIds, SuchakAccount $account): array
    {
        if ($profileIds === []) {
            return [];
        }

        $mine = SuchakProfileRepresentation::query()
            ->where('suchak_account_id', $account->id)
            ->whereIn('matrimony_profile_id', $profileIds)
            ->get()
            ->keyBy('matrimony_profile_id');

        $otherActive = SuchakProfileRepresentation::query()
            ->withValidConsent()
            ->where('suchak_account_id', '!=', $account->id)
            ->whereIn('matrimony_profile_id', $profileIds)
            ->with('suchakAccount')
            ->get()
            ->keyBy('matrimony_profile_id');

        $otherPublic = SuchakProfileRepresentation::query()
            ->publiclyRoutable()
            ->where('suchak_account_id', '!=', $account->id)
            ->whereIn('matrimony_profile_id', $profileIds)
            ->pluck('matrimony_profile_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $anyRepresentation = SuchakProfileRepresentation::query()
            ->whereIn('matrimony_profile_id', $profileIds)
            ->pluck('matrimony_profile_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $selfRegistered = $this->selfRegisteredProfileIds($profileIds);

        $map = [];
        foreach ($profileIds as $profileId) {
            $profileId = (int) $profileId;

            /** @var SuchakProfileRepresentation|null $myRepresentation */
            $myRepresentation = $mine->get($profileId);
            if ($myRepresentation !== null) {
                $map[$profileId] = [
                    'owner_type' => self::OWNER_MINE,
                    'owner_suchak_name' => null,
                    'owner_is_public' => null,
                    'already_represented_by_me' => true,
                    // A row of mine WITHOUT valid consent is only a pending
                    // claim, not a link — the app may still ask for consent.
                    'mine_has_valid_consent' => $myRepresentation->hasValidConsent(),
                    'representation_id' => (int) $myRepresentation->id,
                ];

                continue;
            }

            /** @var SuchakProfileRepresentation|null $other */
            $other = $otherActive->get($profileId);
            if ($other !== null) {
                $isPublic = in_array($profileId, $otherPublic, true);
                $map[$profileId] = [
                    'owner_type' => self::OWNER_OTHER_SUCHAK,
                    'owner_suchak_name' => $isPublic
                        ? (trim((string) ($other->suchakAccount?->suchak_name ?: '')) ?: 'Public Suchak')
                        : null,
                    'owner_is_public' => $isPublic,
                    'already_represented_by_me' => false,
                    'mine_has_valid_consent' => false,
                    'representation_id' => null,
                ];

                continue;
            }

            $ownerType = in_array($profileId, $anyRepresentation, true)
                // Represented, but not actively/consented by anyone else — treat
                // as unclaimed rather than leaking a pending rival claim.
                ? self::OWNER_UNREPRESENTED
                : (in_array($profileId, $selfRegistered, true)
                    ? self::OWNER_PLATFORM_MEMBER
                    : self::OWNER_UNREPRESENTED);

            $map[$profileId] = [
                'owner_type' => $ownerType,
                'owner_suchak_name' => null,
                'owner_is_public' => null,
                'already_represented_by_me' => false,
                'mine_has_valid_consent' => false,
                'representation_id' => null,
            ];
        }

        return $map;
    }

    /**
     * Profiles whose account verified itself — the marker that separates a real
     * self-registered member from a shell user a Suchak created for a manual
     * profile (those never verify a mobile/email of their own).
     *
     * @param  array<int, int>  $profileIds
     * @return array<int, int>
     */
    private function selfRegisteredProfileIds(array $profileIds): array
    {
        $columns = array_values(array_filter(
            ['mobile_verified_at', 'email_verified_at'],
            static fn (string $column): bool => Schema::hasColumn('users', $column),
        ));

        if ($columns === []) {
            return [];
        }

        return DB::table('matrimony_profiles')
            ->join('users', 'users.id', '=', 'matrimony_profiles.user_id')
            ->whereIn('matrimony_profiles.id', $profileIds)
            ->where(function ($query) use ($columns): void {
                foreach ($columns as $column) {
                    $query->orWhereNotNull('users.'.$column);
                }
            })
            ->pluck('matrimony_profiles.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    private function unknownOwner(): array
    {
        return [
            'owner_type' => self::OWNER_UNREPRESENTED,
            'owner_suchak_name' => null,
            'owner_is_public' => null,
            'already_represented_by_me' => false,
            'mine_has_valid_consent' => false,
            'representation_id' => null,
        ];
    }

    /**
     * All profiles storing this number anywhere, with the owner-slot label.
     *
     * @return array<int, array<int, string>> profile_id => source labels
     */
    private function mobileHits(string $mobile): array
    {
        $hits = [];
        $push = static function (int $profileId, string $source) use (&$hits): void {
            $hits[$profileId][] = $source;
        };

        foreach (DB::table('matrimony_profiles')
            ->join('users', 'users.id', '=', 'matrimony_profiles.user_id')
            ->where('users.mobile', $mobile)
            ->pluck('matrimony_profiles.id') as $id) {
            $push((int) $id, 'self_mobile');
        }

        if (Schema::hasTable('profile_contacts')) {
            // The relation is a FK to the contact-relation master, not a string
            // column; guard it so a schema without it still yields the hit.
            $hasRelation = Schema::hasColumn('profile_contacts', 'contact_relation_id');
            $columns = $hasRelation ? ['profile_id', 'contact_relation_id'] : ['profile_id'];

            foreach (DB::table('profile_contacts')
                ->where('phone_number', $mobile)
                ->get($columns) as $contact) {
                $relation = $hasRelation && $contact->contact_relation_id !== null
                    ? 'relation_'.(int) $contact->contact_relation_id
                    : 'unknown';
                $push((int) $contact->profile_id, 'contact:'.$relation);
            }
        }

        $parentColumns = array_values(array_filter(
            ['father_contact_1', 'father_contact_2', 'mother_contact_1', 'mother_contact_2'],
            static fn (string $column): bool => Schema::hasColumn('matrimony_profiles', $column),
        ));
        if ($parentColumns !== []) {
            $query = DB::table('matrimony_profiles')->where(function ($q) use ($parentColumns, $mobile): void {
                foreach ($parentColumns as $column) {
                    $q->orWhere($column, $mobile);
                }
            });
            foreach ($query->get(array_merge(['id'], $parentColumns)) as $row) {
                foreach ($parentColumns as $column) {
                    if ((string) $row->{$column} === $mobile) {
                        $push((int) $row->id, str_starts_with($column, 'father') ? 'father' : 'mother');
                    }
                }
            }
        }

        if (Schema::hasTable('profile_siblings')) {
            $siblingQuery = DB::table('profile_siblings')->where(function ($q) use ($mobile): void {
                foreach (['contact_number', 'contact_number_2', 'contact_number_3'] as $column) {
                    $q->orWhere($column, $mobile);
                }
            });
            if (Schema::hasColumn('profile_siblings', 'deleted_at')) {
                $siblingQuery->whereNull('deleted_at');
            }
            foreach ($siblingQuery->pluck('profile_id') as $id) {
                $push((int) $id, 'sibling');
            }
        }

        return $hits;
    }

    /**
     * Candidate scan for fuzzy-name scoring.
     *
     * Ordering matters as much as the window: the scan is capped, so the
     * strongest signal must be read FIRST or a cap can silently drop the real
     * duplicate (fixed 2026-07-26). Passes, in order:
     *   1. exact DOB day (when a DOB was typed)
     *   2. literal name-token hits — catches a same-person row whose stored DOB
     *      is different or missing, and is the ONLY pass when no DOB is typed
     *   3. birth-year ±1 window — Suchak-entered DOBs are approximate, so the
     *      old same-MONTH window missed real duplicates
     *   4. village/caste narrowed recency sweep (weak signal, DOB-less only)
     * Each pass is ordered by id DESC (most recently created first) so the
     * result is deterministic across runs.
     *
     * @return array<int, object{id: int|string, full_name: ?string, dob_match: string}>
     */
    private function identityCandidates(
        string $candidateName,
        ?string $dateOfBirth,
        ?string $genderKey,
        ?int $locationId,
        ?int $casteId,
    ): array {
        $genderId = null;
        if ($genderKey !== null) {
            $found = array_search($genderKey, $this->genderKeyMap(), true);
            $genderId = $found === false ? null : (int) $found;
        }

        $base = function () use ($genderId) {
            $query = DB::table('matrimony_profiles')
                ->select(['id', 'full_name', 'date_of_birth'])
                ->whereNotNull('full_name');
            if ($genderId !== null) {
                $query->where('gender_id', $genderId);
            }

            return $query;
        };

        /** @var array<int, object> $collected */
        $collected = [];
        $remaining = self::IDENTITY_SCAN_LIMIT;

        $absorb = function ($rows) use (&$collected, &$remaining): void {
            foreach ($rows as $row) {
                $id = (int) $row->id;
                if (isset($collected[$id])) {
                    continue;
                }
                $collected[$id] = $row;
                $remaining--;
            }
        };

        $day = $this->parseDate($dateOfBirth);

        // Pass 1 — exact DOB day, never truncated away by the cap.
        if ($day !== null && $remaining > 0) {
            $absorb($base()
                ->whereBetween('date_of_birth', [
                    $day->copy()->startOfDay()->format('Y-m-d H:i:s'),
                    $day->copy()->endOfDay()->format('Y-m-d H:i:s'),
                ])
                ->orderByDesc('id')
                ->limit($remaining)
                ->get());
        }

        // Pass 2 — literal name tokens (works with or without a DOB).
        $tokens = $this->searchableNameTokens($candidateName);
        if ($tokens !== [] && $remaining > 0) {
            $absorb($base()
                ->where(function ($query) use ($tokens): void {
                    foreach ($tokens as $token) {
                        $query->orWhere('full_name', 'like', '%'.$token.'%');
                    }
                })
                ->orderByDesc('id')
                ->limit($remaining)
                ->get());
        }

        // Pass 3 — birth year ±1 (approximate DOBs are the norm here).
        if ($day !== null && $remaining > 0) {
            $absorb($base()
                ->whereBetween('date_of_birth', [
                    $day->copy()->subYear()->startOfYear()->format('Y-m-d H:i:s'),
                    $day->copy()->addYear()->endOfYear()->format('Y-m-d H:i:s'),
                ])
                ->orderByDesc('id')
                ->limit($remaining)
                ->get());
        }

        // Pass 4 — DOB-less fallback: village/caste narrowed recency sweep.
        if ($day === null && $remaining > 0 && ($locationId !== null || $casteId !== null)) {
            $absorb($base()
                ->where(function ($query) use ($locationId, $casteId): void {
                    if ($locationId !== null) {
                        $query->orWhere('location_id', $locationId);
                    }
                    if ($casteId !== null) {
                        $query->orWhere('caste_id', $casteId);
                    }
                })
                ->orderByDesc('id')
                ->limit($remaining)
                ->get());
        }

        foreach ($collected as $row) {
            $row->dob_match = $this->dobMatchLevel($dateOfBirth, $row->date_of_birth);
        }

        return array_values($collected);
    }

    /**
     * Literal tokens worth a LIKE pass. Transliteration variants are caught by
     * NameMatcher afterwards; this only has to narrow the scan cheaply.
     *
     * @return array<int, string>
     */
    private function searchableNameTokens(string $candidateName): array
    {
        $normalized = NameMatcher::normalize($candidateName);
        if ($normalized === null) {
            return [];
        }

        $tokens = array_values(array_filter(
            explode(' ', $normalized),
            static fn (string $token): bool => mb_strlen($token) >= 3,
        ));

        return array_slice(array_unique($tokens), 0, 4);
    }

    /**
     * exact      — same calendar day.
     * year_month — same year and month.
     * year       — same year.
     * near_year  — within ±1 year (approximate DOB tolerance).
     * none       — no DOB on either side, or further apart than that.
     */
    private function dobMatchLevel(?string $typedDob, mixed $storedDob): string
    {
        if ($typedDob === null || $typedDob === '' || $storedDob === null || $storedDob === '') {
            return 'none';
        }

        $stored = substr((string) $storedDob, 0, 10);
        $typed = substr($typedDob, 0, 10);
        if ($stored === '' || $typed === '') {
            return 'none';
        }
        if ($stored === $typed) {
            return 'exact';
        }
        if (substr($stored, 0, 7) === substr($typed, 0, 7)) {
            return 'year_month';
        }
        if (substr($stored, 0, 4) === substr($typed, 0, 4)) {
            return 'year';
        }

        $storedYear = (int) substr($stored, 0, 4);
        $typedYear = (int) substr($typed, 0, 4);
        if ($storedYear > 0 && $typedYear > 0 && abs($storedYear - $typedYear) <= 1) {
            return 'near_year';
        }

        return 'none';
    }

    private function parseDate(?string $value): ?\Illuminate\Support\Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return \Illuminate\Support\Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function confidence(array $row): ?string
    {
        $hasMobile = $row['mobile_sources'] !== [];
        $nameStrong = in_array($row['name_match'], [NameMatcher::LEVEL_EXACT, NameMatcher::LEVEL_STRONG], true);
        $dobStrong = in_array($row['dob_match'], ['exact', 'year_month'], true);
        $dobWeak = in_array($row['dob_match'], ['year', 'near_year'], true);
        $genderOk = $row['gender_match'] !== false; // null (unknown) does not veto
        $soft = $row['soft_match'] === true;

        if ($hasMobile && $nameStrong && $dobStrong && $genderOk) {
            return self::CONFIDENCE_CONFIRMED;
        }
        if ($hasMobile) {
            return self::CONFIDENCE_HIGH;
        }
        if ($nameStrong && $dobStrong && $genderOk) {
            return self::CONFIDENCE_HIGH;
        }
        // Approximate DOB (±1 year) is deliberately advisory, never a hard stop.
        if ($nameStrong && $dobWeak && $genderOk) {
            return self::CONFIDENCE_MEDIUM;
        }
        // No DOB anywhere: name(+gender) alone can only ever be a hint. A
        // matching village/caste lifts it one notch, still advisory.
        if ($nameStrong && $row['dob_match'] === 'none' && $genderOk) {
            return $soft ? self::CONFIDENCE_MEDIUM : self::CONFIDENCE_LOW;
        }
        if ($row['name_match'] === NameMatcher::LEVEL_PARTIAL && $row['dob_match'] === 'exact' && $genderOk) {
            return self::CONFIDENCE_MEDIUM;
        }
        if ($row['name_match'] === NameMatcher::LEVEL_PARTIAL && $row['dob_match'] === 'none' && $genderOk && $soft) {
            return self::CONFIDENCE_LOW;
        }

        return null;
    }

    /** "Shriram Kadam" → "Shriram K." — enough to recognise, not to harvest. */
    private function maskName(string $fullName): string
    {
        $tokens = preg_split('/\s+/u', trim($fullName)) ?: [];
        if ($tokens === [] || $tokens[0] === '') {
            return '—';
        }
        $masked = [mb_convert_case($tokens[0], MB_CASE_TITLE, 'UTF-8')];
        foreach (array_slice($tokens, 1) as $token) {
            if ($token !== '') {
                $masked[] = mb_strtoupper(mb_substr($token, 0, 1)).'.';
            }
        }

        return implode(' ', $masked);
    }

    private function ageYears(mixed $dateOfBirth): ?int
    {
        if ($dateOfBirth === null) {
            return null;
        }
        try {
            $age = (int) floor(\Illuminate\Support\Carbon::parse((string) $dateOfBirth)->diffInYears(now()));
        } catch (\Throwable) {
            return null;
        }

        return ($age >= 18 && $age <= 100) ? $age : null;
    }

    /**
     * Per-instance (not static) cache: the service is resolved per request, and
     * a process-wide static went stale between RefreshDatabase test cases.
     *
     * @var array<int, string>|null
     */
    private ?array $genderKeyMap = null;

    /** @return array<int, string> gender id => key */
    private function genderKeyMap(): array
    {
        if ($this->genderKeyMap === null) {
            $this->genderKeyMap = DB::table('master_genders')->pluck('key', 'id')
                ->map(static fn ($key): string => (string) $key)
                ->all();
        }

        return $this->genderKeyMap;
    }
}
