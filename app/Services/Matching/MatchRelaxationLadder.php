<?php

namespace App\Services\Matching;

/**
 * The tiered relaxation ladder (PO decision 2026-07-26).
 *
 * {@see MatchingService::findMatchesForTab()} walks the tiers in order and stops at the FIRST tier
 * whose surviving candidate count reaches {@see self::floor()}. Every returned row carries the tier
 * it was admitted at, and the run reports the highest tier reached plus which fields were loosened —
 * so the Suchak UI (and the member app) can say exactly what was relaxed instead of silently
 * widening the feed.
 *
 * NEVER RELAXED at any tier — these are enforced unconditionally in
 * {@see MatchingService::applyBaseCandidateFilters()} and are deliberately absent from every tier's
 * relaxed-field list:
 *  - opposite gender,
 *  - the legal minimum marriage age ({@see \App\Support\MarriageAgePolicy}),
 *  - suspended / married / archived lifecycle exclusions,
 *  - repeatedly-skipped (explicitly rejected) pairs.
 *
 * The ladder itself lives in `config/matching.relaxation.tiers` so it stays tunable without a deploy.
 */
final class MatchRelaxationLadder
{
    public const TIER_STRICT = 0;

    /** Income and height stop excluding and become scored penalties with a visible warning. */
    public const TIER_SOFT_INCOME_HEIGHT = 1;

    /** District/taluka widens to geographically nearby geography. */
    public const TIER_WIDER_GEOGRAPHY = 2;

    /** Caste relaxes. Religion stays locked. */
    public const TIER_RELAXED_CASTE = 3;

    /**
     * Minimum surviving candidates before the ladder stops widening.
     */
    public static function floor(): int
    {
        return max(1, (int) config('matching.relaxation.floor', 12));
    }

    /**
     * Ordered tier levels, lowest (strictest) first.
     *
     * @return list<int>
     */
    public static function tiers(): array
    {
        $levels = array_map('intval', array_keys(self::tierMap()));
        sort($levels);

        return array_values($levels);
    }

    public static function maxTier(): int
    {
        $tiers = self::tiers();

        return $tiers === [] ? self::TIER_STRICT : (int) end($tiers);
    }

    /**
     * Preference row ids whose `not_matched` verdict is tolerated (scored, not excluded) at this tier.
     * Cumulative — tier N includes everything relaxed by tiers below it.
     *
     * @return list<string>
     */
    public static function relaxedFieldsUpTo(int $tier): array
    {
        $out = [];
        foreach (self::tierMap() as $level => $fields) {
            if ((int) $level > $tier) {
                continue;
            }
            foreach ($fields as $field) {
                $out[(string) $field] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * Religion never relaxes, even at the top tier: the PO ruling loosens caste only.
     */
    public static function religionEverRelaxes(): bool
    {
        return false;
    }

    /**
     * @return array<int, list<string>>
     */
    private static function tierMap(): array
    {
        $configured = config('matching.relaxation.tiers');
        if (! is_array($configured) || $configured === []) {
            return [
                self::TIER_STRICT => [],
                self::TIER_SOFT_INCOME_HEIGHT => ['income', 'height'],
                self::TIER_WIDER_GEOGRAPHY => ['location'],
                self::TIER_RELAXED_CASTE => ['caste'],
            ];
        }

        $out = [];
        foreach ($configured as $level => $fields) {
            $out[(int) $level] = array_values(array_map('strval', (array) $fields));
        }
        ksort($out);

        return $out;
    }
}
