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
 * Reuse notes (one-engine rule): mobile-store lookups mirror
 * DuplicateDetectionService's profile_contacts pattern extended with the
 * parent slots and sibling numbers documented in PRODUCT_MAP §5; name
 * fuzzing lives in the shared App\Support\NameMatcher.
 */
final class SuchakCandidateDuplicateCheckService
{
    public const CONFIDENCE_CONFIRMED = 'confirmed';

    public const CONFIDENCE_HIGH = 'high';

    public const CONFIDENCE_MEDIUM = 'medium';

    private const MAX_MATCHES = 5;

    private const IDENTITY_SCAN_LIMIT = 300;

    /**
     * @return array{matches: array<int, array<string, mixed>>, match_count: int}
     */
    public function check(
        string $normalizedMobile,
        string $candidateName,
        ?string $dateOfBirth,
        ?string $genderKey,
        SuchakAccount $account,
    ): array {
        /** @var array<int, array<string, mixed>> $rows profile_id => working row */
        $rows = [];

        foreach ($this->mobileHits($normalizedMobile) as $profileId => $sources) {
            $rows[$profileId] = [
                'mobile_sources' => $sources,
                'name_match' => NameMatcher::LEVEL_NONE,
                'dob_match' => 'none',
                'gender_match' => null,
            ];
        }

        foreach ($this->identityCandidates($dateOfBirth, $genderKey) as $candidate) {
            $level = NameMatcher::matchLevel($candidateName, (string) $candidate->full_name);
            if ($level === NameMatcher::LEVEL_NONE) {
                continue;
            }
            $profileId = (int) $candidate->id;
            $rows[$profileId] ??= [
                'mobile_sources' => [],
                'name_match' => NameMatcher::LEVEL_NONE,
                'dob_match' => 'none',
                'gender_match' => null,
            ];
            $rows[$profileId]['name_match'] = $level;
            $rows[$profileId]['dob_match'] = $candidate->dob_match;
        }

        if ($rows === []) {
            return ['matches' => [], 'match_count' => 0];
        }

        $profiles = MatrimonyProfile::query()
            ->with(['gender', 'location.parent.parent.parent'])
            ->whereIn('id', array_keys($rows))
            ->get()
            ->keyBy('id');

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
            if ($row['dob_match'] === 'none' && $dateOfBirth !== null && $profile->date_of_birth !== null) {
                $storedDob = substr((string) $profile->date_of_birth, 0, 10);
                if ($storedDob === $dateOfBirth) {
                    $row['dob_match'] = 'exact';
                } elseif (substr($storedDob, 0, 7) === substr($dateOfBirth, 0, 7)) {
                    $row['dob_match'] = 'year_month';
                }
            }
            $storedGenderKey = $genderIdToKey[(int) $profile->gender_id] ?? null;
            $row['gender_match'] = ($genderKey !== null && $storedGenderKey !== null)
                ? ($genderKey === $storedGenderKey)
                : null;

            $confidence = $this->confidence($row);
            if ($confidence === null) {
                continue;
            }

            $representation = SuchakProfileRepresentation::query()
                ->where('suchak_account_id', $account->id)
                ->where('matrimony_profile_id', $profileId)
                ->first();

            $matches[] = [
                'profile_id' => $profileId,
                'display_name' => $this->maskName((string) $profile->full_name),
                'age_years' => $this->ageYears($profile->date_of_birth),
                'gender' => $storedGenderKey,
                'location_label' => trim((string) $profile->residenceLocationDisplayLine()) ?: null,
                'confidence' => $confidence,
                'signals' => [
                    'mobile' => $row['mobile_sources'] !== [],
                    'mobile_sources' => array_values(array_unique($row['mobile_sources'])),
                    'name' => $row['name_match'],
                    'dob' => $row['dob_match'],
                    'gender' => $row['gender_match'],
                ],
                // Shared family number warning: the number matched, but not as
                // the candidate's own login mobile — could be a sibling/parent.
                'shared_number_possible' => $row['mobile_sources'] !== []
                    && ! in_array('self_mobile', $row['mobile_sources'], true),
                'already_represented_by_me' => $representation !== null,
                'representation_id' => $representation?->id,
                // The 409 use_existing_profile link flow only applies when the
                // typed mobile is the candidate's own account mobile.
                'can_link_existing' => in_array('self_mobile', $row['mobile_sources'], true),
            ];
        }

        usort($matches, static function (array $a, array $b): int {
            $rank = [self::CONFIDENCE_CONFIRMED => 0, self::CONFIDENCE_HIGH => 1, self::CONFIDENCE_MEDIUM => 2];

            return ($rank[$a['confidence']] ?? 9) <=> ($rank[$b['confidence']] ?? 9);
        });
        $matches = array_slice($matches, 0, self::MAX_MATCHES);

        return ['matches' => $matches, 'match_count' => count($matches)];
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
            foreach (DB::table('profile_contacts')
                ->where('phone_number', $mobile)
                ->get(['profile_id', 'relation_type']) as $contact) {
                $push((int) $contact->profile_id, 'contact:'.(string) ($contact->relation_type ?: 'unknown'));
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
     * DOB(+gender)-bounded candidate scan for fuzzy-name scoring.
     *
     * @return array<int, object{id: int|string, full_name: ?string, dob_match: string}>
     */
    private function identityCandidates(?string $dateOfBirth, ?string $genderKey): array
    {
        if ($dateOfBirth === null || $dateOfBirth === '') {
            return [];
        }

        // Same-month window (biodata DOBs are often approximate). Expressed as a
        // date RANGE, not DATE_FORMAT — the latter is MySQL-only and blows up on
        // the SQLite test connection.
        try {
            $monthStart = \Illuminate\Support\Carbon::parse($dateOfBirth)->startOfMonth();
        } catch (\Throwable) {
            return [];
        }
        $monthEnd = (clone $monthStart)->endOfMonth();

        $query = DB::table('matrimony_profiles')
            ->select(['id', 'full_name', 'date_of_birth'])
            ->whereNotNull('full_name')
            ->whereBetween('date_of_birth', [$monthStart->format('Y-m-d'), $monthEnd->format('Y-m-d 23:59:59')]);

        if ($genderKey !== null) {
            $genderId = array_search($genderKey, $this->genderKeyMap(), true);
            if ($genderId !== false) {
                $query->where('gender_id', $genderId);
            }
        }

        return $query->limit(self::IDENTITY_SCAN_LIMIT)->get()->map(static function (object $row) use ($dateOfBirth): object {
            $storedDob = substr((string) $row->date_of_birth, 0, 10);
            $row->dob_match = $storedDob === $dateOfBirth ? 'exact' : 'year_month';

            return $row;
        })->all();
    }

    private function confidence(array $row): ?string
    {
        $hasMobile = $row['mobile_sources'] !== [];
        $nameStrong = in_array($row['name_match'], [NameMatcher::LEVEL_EXACT, NameMatcher::LEVEL_STRONG], true);
        $dobHit = $row['dob_match'] !== 'none';
        $genderOk = $row['gender_match'] !== false; // null (unknown) does not veto

        if ($hasMobile && $nameStrong && $dobHit && $genderOk) {
            return self::CONFIDENCE_CONFIRMED;
        }
        if ($hasMobile) {
            return self::CONFIDENCE_HIGH;
        }
        if ($nameStrong && $dobHit && $genderOk) {
            return self::CONFIDENCE_HIGH;
        }
        if ($row['name_match'] === NameMatcher::LEVEL_PARTIAL && $row['dob_match'] === 'exact' && $genderOk) {
            return self::CONFIDENCE_MEDIUM;
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

    /** @return array<int, string> gender id => key */
    private function genderKeyMap(): array
    {
        static $map = null;
        if ($map === null) {
            $map = DB::table('master_genders')->pluck('key', 'id')
                ->map(static fn ($key): string => (string) $key)
                ->all();
        }

        return $map;
    }
}
