<?php

namespace App\Services\Gunamilan;

use App\Models\MatrimonyProfile;

/**
 * Per-run memo that makes गुणमिलन safe to ask inside the matching feed.
 *
 * {@see GunamilanService::calculate()} is the SINGLE-PAIR entry point: it re-flattens both profiles
 * on every call, so asking it once per candidate would re-derive the seeker's own koota key for
 * every row in the pool. The bulk contract is the other pair of methods —
 * {@see GunamilanService::kootaKeyFor()} once per PROFILE, then
 * {@see GunamilanService::compare()} per PAIR, which is pure array maths over the in-memory master
 * snapshot and issues zero queries.
 *
 * This class is that contract, memoised:
 *
 *  - {@see keyFor()} flattens a profile at most once per run. When the caller already eager-loaded
 *    `horoscope` (the feed does — see {@see \App\Services\Matching\MatchingService::eagerLoadMatchingRelations()})
 *    it costs no queries at all; otherwise it costs ONE query for that profile, ever, not one per pair.
 *  - {@see verdictFor()} compares an unordered pair at most once per run, so the two directional
 *    preference builds plus the score component share a single comparison.
 *
 * Net effect on the feed: one extra query per RUN for the candidate `profile_horoscope_data` rows,
 * and ZERO extra queries per candidate.
 *
 * Bride/groom direction is resolved from the flattened `genderKey`, exactly as
 * {@see GunamilanService::calculate()} resolves it from the models — Ashta-Koota is not symmetric
 * (Varna and Tara both read the direction), so getting it from anywhere else would silently score
 * the wrong way round.
 *
 * {@see flush()} is called at the start of every matching run through
 * {@see \App\Services\ProfilePreferenceMatchService::flushRuntimeCaches()}, so nothing here outlives
 * the run that built it.
 */
final class GunamilanPairEvaluator
{
    /** @var array<int, GunamilanKootaKey> */
    private static array $keyCache = [];

    /** @var array<string, array<string, mixed>> */
    private static array $verdictCache = [];

    public static function flush(): void
    {
        self::$keyCache = [];
        self::$verdictCache = [];
    }

    /**
     * The flattened koota key for one profile, resolved once per run.
     */
    public static function keyFor(MatrimonyProfile $profile): GunamilanKootaKey
    {
        $pid = (int) $profile->getKey();
        if ($pid <= 0) {
            return app(GunamilanService::class)->kootaKeyFor($profile);
        }
        if (isset(self::$keyCache[$pid])) {
            return self::$keyCache[$pid];
        }

        return self::$keyCache[$pid] = app(GunamilanService::class)->kootaKeyFor($profile);
    }

    /**
     * The full 36-guna + Mangal verdict for a pair, memoised on the UNORDERED pair.
     *
     * The result is the identical payload {@see GunamilanService::calculate()} returns, so every
     * consumer (preference row, score component, Suchak payload) reads exactly one shape:
     * `computable`, `state`, `total_points`, `max_points`, `threshold`, `is_compatible` (null when
     * not computable), the eight `sections`, `nadi_dosha`, `bhakoot_dosha`, `mangal`,
     * `missing_fields`.
     *
     * @return array<string, mixed>
     */
    public static function verdictFor(MatrimonyProfile $a, MatrimonyProfile $b): array
    {
        $ida = (int) $a->getKey();
        $idb = (int) $b->getKey();
        $cacheKey = $ida > 0 && $idb > 0
            ? ($ida < $idb ? $ida.'|'.$idb : $idb.'|'.$ida)
            : null;

        if ($cacheKey !== null && isset(self::$verdictCache[$cacheKey])) {
            return self::$verdictCache[$cacheKey];
        }

        $verdict = self::compareKeys(self::keyFor($a), self::keyFor($b));

        if ($cacheKey !== null) {
            self::$verdictCache[$cacheKey] = $verdict;
        }

        return $verdict;
    }

    /**
     * @return array<string, mixed>
     */
    private static function compareKeys(GunamilanKootaKey $one, GunamilanKootaKey $two): array
    {
        $genderOne = $one->genderKey !== null ? strtolower($one->genderKey) : '';
        $genderTwo = $two->genderKey !== null ? strtolower($two->genderKey) : '';

        if ($genderOne === 'female' && $genderTwo === 'male') {
            return app(GunamilanService::class)->compare($one, $two, true);
        }
        if ($genderOne === 'male' && $genderTwo === 'female') {
            return app(GunamilanService::class)->compare($two, $one, true);
        }

        // Direction unresolved (same gender, or a profile with no gender row). The engine reports
        // this as NOT computable — which every caller must read as "no signal", never as a rejection.
        return app(GunamilanService::class)->compare($one, $two, false);
    }

    /**
     * `26/36` style, always in Latin digits (frozen workspace rule) and without a trailing `.0`.
     */
    public static function formatPoints(float $points): string
    {
        $formatted = number_format($points, 1, '.', '');

        return str_ends_with($formatted, '.0') ? substr($formatted, 0, -2) : $formatted;
    }
}
