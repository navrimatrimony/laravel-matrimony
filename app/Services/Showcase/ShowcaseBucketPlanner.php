<?php

namespace App\Services\Showcase;

use App\Models\MasterMaritalStatus;
use App\Models\Religion;

/**
 * Photo-pool-first planner for admin bulk showcase creation.
 *
 * Old flow: random attributes -> derive eng/ folder -> folder missing -> profile skipped.
 * This flow: take eng/ buckets that still have unused photos, keep only the ones the admin
 * bulk policy allows, then narrow that policy per profile so
 * {@see \App\Services\ShowcaseProfileDefaultsService::fullAttributesForShowcaseProfile}
 * generates a profile that matches the bucket it was drawn from.
 *
 * Admin settings stay authoritative: a bucket the policy forbids is never used, it is
 * reported as blocked instead (so the admin can see why a photo is unreachable).
 */
final class ShowcaseBucketPlanner
{
    /** @var array<string, array{0: int, 1: int}> */
    public const AGE_BUCKET_RANGES = [
        '18-24' => [18, 24],
        '25-30' => [25, 30],
        '31-35' => [31, 35],
        '36-45' => [36, 45],
        '46-plus' => [46, 80],
    ];

    public const BLOCKED_GENDER = 'gender';

    public const BLOCKED_RELIGION = 'religion';

    public const BLOCKED_MARITAL = 'marital';

    public const BLOCKED_AGE = 'age';

    /** Minimum showcase age per gender (mirrors the member onboarding rule: F18 / M21). */
    private const MIN_AGE_BY_GENDER = ['male' => 21, 'female' => 18];

    public function __construct(private readonly ShowcasePhotoPoolService $pool) {}

    /**
     * @param  array<string, mixed>  $policy  normalized {@see ShowcaseBulkCreateSettings::normalize}
     * @return array{buckets: list<array<string, mixed>>, blocked: list<array<string, mixed>>, available: int, pool_unused: int}
     */
    public function plan(array $policy, ?string $genderOverride = null): array
    {
        $religionIdByFolderKey = $this->religionIdsByFolderKey();
        $maritalIdByFolderKey = $this->maritalStatusIdsByFolderKey();

        $allowedReligionIds = array_flip($policy['religion_ids'] ?? []);
        $allowedMaritalIds = array_flip($policy['marital_status_ids'] ?? []);
        $policyAgeMin = (int) ($policy['age_min'] ?? 18);
        $policyAgeMax = (int) ($policy['age_max'] ?? 80);

        // Engine parity: an empty marital multiselect means "never_married only" (see
        // ShowcaseProfileDefaultsService::fullAttributesForShowcaseProfile).
        $neverMarriedId = $maritalIdByFolderKey['never_married'] ?? null;

        $buckets = [];
        $blocked = [];
        $poolUnused = 0;

        foreach ($this->pool->coverageMatrix() as $row) {
            $unused = (int) ($row['unused'] ?? 0);
            if ($unused <= 0) {
                continue;
            }
            $poolUnused += $unused;

            $gender = (string) ($row['gender'] ?? '');
            $folder = (string) ($row['folder'] ?? '');
            $ageBucket = (string) ($row['age_bucket'] ?? '');
            $religionId = $religionIdByFolderKey[(string) ($row['religion_key'] ?? '')] ?? null;
            $maritalId = $maritalIdByFolderKey[(string) ($row['marital_key'] ?? '')] ?? null;

            if ($genderOverride !== null && $gender !== $genderOverride) {
                $blocked[] = $this->blockedRow($folder, $unused, self::BLOCKED_GENDER);

                continue;
            }
            if ($religionId === null || ($allowedReligionIds !== [] && ! isset($allowedReligionIds[$religionId]))) {
                $blocked[] = $this->blockedRow($folder, $unused, self::BLOCKED_RELIGION);

                continue;
            }
            if ($maritalId === null) {
                $blocked[] = $this->blockedRow($folder, $unused, self::BLOCKED_MARITAL);

                continue;
            }
            if ($allowedMaritalIds !== []) {
                if (! isset($allowedMaritalIds[$maritalId])) {
                    $blocked[] = $this->blockedRow($folder, $unused, self::BLOCKED_MARITAL);

                    continue;
                }
            } elseif ($neverMarriedId === null || $maritalId !== $neverMarriedId) {
                $blocked[] = $this->blockedRow($folder, $unused, self::BLOCKED_MARITAL);

                continue;
            }

            $range = self::AGE_BUCKET_RANGES[$ageBucket] ?? null;
            if ($range === null) {
                $blocked[] = $this->blockedRow($folder, $unused, self::BLOCKED_AGE);

                continue;
            }
            $ageMin = max($range[0], $policyAgeMin, self::MIN_AGE_BY_GENDER[$gender] ?? 18);
            $ageMax = min($range[1], $policyAgeMax);
            if ($ageMin > $ageMax) {
                $blocked[] = $this->blockedRow($folder, $unused, self::BLOCKED_AGE);

                continue;
            }

            $buckets[] = [
                'folder' => $folder,
                'gender' => $gender,
                'religion_id' => $religionId,
                'marital_status_id' => $maritalId,
                'age_min' => $ageMin,
                'age_max' => $ageMax,
                'unused' => $unused,
            ];
        }

        usort($blocked, static fn (array $a, array $b): int => ($b['unused'] <=> $a['unused']) ?: strcmp((string) $a['folder'], (string) $b['folder']));

        return [
            'buckets' => $buckets,
            'blocked' => $blocked,
            'available' => (int) array_sum(array_column($buckets, 'unused')),
            'pool_unused' => $poolUnused,
        ];
    }

    /**
     * Draw up to $count creation slots, never more than a bucket has unused photos.
     *
     * @param  array{buckets: list<array<string, mixed>>, available: int, ...}  $plan
     * @param  array<string, mixed>  $policy  normalized base policy
     * @return list<array{gender: string, folder: string, policy: array<string, mixed>}>
     */
    public function drawSlots(array $plan, array $policy, int $count): array
    {
        $buckets = $plan['buckets'] ?? [];
        if ($buckets === [] || $count <= 0) {
            return [];
        }

        // One ticket per unused photo => proportional draw that can never over-draw a bucket.
        $tickets = [];
        foreach ($buckets as $index => $bucket) {
            for ($i = 0; $i < (int) $bucket['unused']; $i++) {
                $tickets[] = $index;
            }
        }
        shuffle($tickets);
        $tickets = array_slice($tickets, 0, $count);

        $slots = [];
        foreach ($tickets as $index) {
            $bucket = $buckets[$index];
            $slots[] = [
                'gender' => (string) $bucket['gender'],
                'folder' => (string) $bucket['folder'],
                'policy' => array_merge($policy, [
                    'religion_ids' => [(int) $bucket['religion_id']],
                    'marital_status_ids' => [(int) $bucket['marital_status_id']],
                    'age_min' => (int) $bucket['age_min'],
                    'age_max' => (int) $bucket['age_max'],
                ]),
            ];
        }

        return $slots;
    }

    /**
     * @return array<string, string>
     */
    public static function blockedReasonLabels(): array
    {
        return [
            self::BLOCKED_GENDER => __('showcase_bulk.blocked_gender'),
            self::BLOCKED_RELIGION => __('showcase_bulk.blocked_religion'),
            self::BLOCKED_MARITAL => __('showcase_bulk.blocked_marital'),
            self::BLOCKED_AGE => __('showcase_bulk.blocked_age'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function blockedRow(string $folder, int $unused, string $reason): array
    {
        return ['folder' => $folder, 'unused' => $unused, 'reason' => $reason];
    }

    /**
     * @return array<string, int>
     */
    private function religionIdsByFolderKey(): array
    {
        $map = [];
        $rows = Religion::query()
            ->where(function ($q) {
                $q->where('is_active', true)->orWhereNull('is_active');
            })
            ->get(['id', 'key']);
        foreach ($rows as $row) {
            $key = ShowcasePhotoPoolService::folderKeyFor($row->key);
            if ($key !== null && ! isset($map[$key])) {
                $map[$key] = (int) $row->id;
            }
        }

        return $map;
    }

    /**
     * @return array<string, int>
     */
    private function maritalStatusIdsByFolderKey(): array
    {
        $map = [];
        foreach (MasterMaritalStatus::query()->where('is_active', true)->get(['id', 'key']) as $row) {
            $key = ShowcasePhotoPoolService::folderKeyFor($row->key);
            if ($key !== null && ! isset($map[$key])) {
                $map[$key] = (int) $row->id;
            }
        }

        return $map;
    }
}
