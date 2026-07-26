<?php

namespace App\Services\Matching;

/**
 * Selects which universe of profiles {@see MatchingService} is allowed to draw candidates from.
 *
 * The engine itself (hard filters → mutual preference gate → weighted score) is identical for every
 * strategy; only the availability predicate in {@see MatchingService::applyBaseCandidateFilters()}
 * and whether actor-specific adjustments apply differ.
 *
 * {@see self::members()} is the default and reproduces the historical member-facing pool exactly —
 * member accounts, non-showcase, lifecycle_state = active, not suspended. Any new pool must be
 * opt-in so the member surfaces never shift.
 */
final class CandidatePoolStrategy
{
    /** Member-facing pool: self-registered, activated, non-suspended member accounts only. */
    public const MODE_MEMBERS = 'members';

    /**
     * Suchak-facing combined universe: platform members PLUS candidates represented by any Suchak
     * (publicly routable) PLUS the calling Suchak's own represented candidates — including those still
     * awaiting manual activation (`is_suspended = true`) or still in a pre-active lifecycle state.
     */
    public const MODE_SUCHAK_UNIVERSE = 'suchak_universe';

    private function __construct(
        public readonly string $mode,
        public readonly ?int $suchakAccountId = null,
    ) {}

    public static function members(): self
    {
        return new self(self::MODE_MEMBERS);
    }

    /**
     * @param  int|null  $suchakAccountId  The acting Suchak account; its own represented candidates
     *                                     join the pool even when they are not publicly routable.
     */
    public static function suchakUniverse(?int $suchakAccountId = null): self
    {
        return new self(self::MODE_SUCHAK_UNIVERSE, $suchakAccountId > 0 ? $suchakAccountId : null);
    }

    public function isMembers(): bool
    {
        return $this->mode === self::MODE_MEMBERS;
    }

    /**
     * Boost / behaviour adjustments are keyed to a real member's own activity. A Suchak-initiated run
     * scores a dormant, Suchak-created account that has no behaviour of its own, and the Suchak's
     * activity says nothing about this candidate's compatibility — so the field score stands alone.
     */
    public function appliesActorAdjustments(): bool
    {
        return $this->isMembers();
    }
}
