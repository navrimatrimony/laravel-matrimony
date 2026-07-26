<?php

namespace App\Modules\Suchak\Services;

use App\Models\MatrimonyProfile;
use App\Models\SuchakProfileRepresentation;
use App\Services\Matching\MatchingConfigService;
use App\Services\Matching\MatchingService;
use Illuminate\Support\Collection;

/**
 * The one place a Suchak surface is allowed to answer "do these two fit, and how well?".
 *
 * It owns no scoring logic of its own — it delegates eligibility and the weighted 0-100 score to
 * {@see MatchingService}, the same engine that powers the member match feed, and only translates the
 * engine's breakdown into the reasons / warnings / fit-label shape the Suchak surfaces already render.
 *
 * This replaced three separate boolean heuristics (same caste / same district / age gap <= 8) that
 * previously lived in SuchakCrossSearchService, SuchakDailyOpportunityService and
 * SuchakCollaborationService. Do not reintroduce one.
 */
class SuchakMatchFitService
{
    public function __construct(
        private readonly MatchingService $matching,
        private readonly MatchingConfigService $matchingConfig,
    ) {}

    /**
     * @return array{
     *     reasons: list<string>,
     *     warnings: list<string>,
     *     fit_label: string,
     *     fit_summary: string,
     *     reason: string,
     *     match_score: int,
     *     match_base_score: int,
     *     match_field_points: array<string, int>
     * }|null  Null when the pair is ineligible (same gender, self, hard preference conflict) or scores
     *         below the configured surfacing floor.
     */
    public function fit(MatrimonyProfile $seeker, MatrimonyProfile $candidate): ?array
    {
        if (! $this->matching->isEligiblePair($seeker, $candidate)) {
            return null;
        }

        // Suchak-initiated: no ACTOR boost / behaviour layer (decision C — the represented candidate's
        // dormant account has no activity, and the Suchak's own activity is not this candidate's).
        // The candidate-intrinsic quality delta (verified / photo / complete / recently touched) still
        // applies — see {@see MatchingService::computeMatchBreakdown()} — because it describes the
        // candidate, not the actor, and a Suchak must not see an empty card tied with a verified one.
        $breakdown = $this->matching->computeMatchBreakdown($seeker, $candidate, false);

        $score = (int) ($breakdown['final_score'] ?? 0);
        if ($score < $this->matchingConfig->suchakMinFitScore()) {
            return null;
        }

        /** @var array<string, int> $fieldPoints */
        $fieldPoints = $breakdown['field_points'] ?? [];

        $reasons = $this->reasonsFrom($breakdown);
        $warnings = $this->warningsFrom($fieldPoints);
        $fitLabel = $this->fitLabel($score);
        $fitSummary = $this->fitSummary($fitLabel, $score, count($reasons), count($warnings));

        return [
            'reasons' => $reasons,
            'warnings' => $warnings,
            'fit_label' => $fitLabel,
            'fit_summary' => $fitSummary,
            'reason' => $reasons[0] ?? $fitSummary,
            'match_score' => $score,
            'match_base_score' => (int) ($breakdown['before_boost'] ?? $score),
            'match_field_points' => $fieldPoints,
        ];
    }

    /**
     * Best-scoring pairing between the Suchak's own represented candidates and one target
     * representation. Unlike the heuristic it replaces, this ranks rather than taking the first hit.
     *
     * @param  Collection<int, SuchakProfileRepresentation>|iterable<SuchakProfileRepresentation>  $ownRepresentations
     * @return array<string, mixed>|null  The {@see self::fit()} payload plus `own_representation`.
     */
    public function bestFitAmong(iterable $ownRepresentations, SuchakProfileRepresentation $candidate): ?array
    {
        $candidateProfile = $candidate->matrimonyProfile;
        if (! $candidateProfile instanceof MatrimonyProfile) {
            return null;
        }

        $best = null;
        foreach ($ownRepresentations as $ownRepresentation) {
            if (! $ownRepresentation instanceof SuchakProfileRepresentation) {
                continue;
            }

            $ownProfile = $ownRepresentation->matrimonyProfile;
            if (! $ownProfile instanceof MatrimonyProfile) {
                continue;
            }

            $fit = $this->fit($ownProfile, $candidateProfile);
            if ($fit === null) {
                continue;
            }

            if ($best === null || $fit['match_score'] > $best['match_score']) {
                $best = array_merge(['own_representation' => $ownRepresentation], $fit);
            }
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>  $breakdown
     * @return list<string>
     */
    private function reasonsFrom(array $breakdown): array
    {
        $reasons = [];
        foreach ($breakdown['field_parts'] ?? [] as $part) {
            foreach ($part['reasons'] ?? [] as $reason) {
                $reason = trim((string) $reason);
                if ($reason !== '') {
                    $reasons[] = $reason;
                }
            }
        }

        // "Why is this on top" must stay truthful: the quality delta moved the score, so its signals
        // are listed alongside the field reasons. They are already trimmed to the aggregate cap, so
        // the listed points sum to exactly the delta that was applied.
        foreach ($breakdown['quality_signals'] ?? [] as $signal) {
            $reason = trim((string) ($signal['reason'] ?? ''));
            if ($reason !== '' && (int) ($signal['points'] ?? 0) > 0) {
                $reasons[] = $reason;
            }
        }

        return array_values(array_unique($reasons));
    }

    /**
     * A field earning far less than its configured weight is the Suchak's cue to look closer.
     *
     * @param  array<string, int>  $fieldPoints
     * @return list<string>
     */
    private function warningsFrom(array $fieldPoints): array
    {
        $threshold = $this->matchingConfig->suchakWeakSignalPercent();
        $warnings = [];

        foreach ($fieldPoints as $fieldKey => $points) {
            if (! $this->matchingConfig->fieldEnabled((string) $fieldKey)) {
                continue;
            }

            $weight = $this->matchingConfig->weightFor((string) $fieldKey);
            if ($weight <= 0) {
                continue;
            }

            if (((int) $points) * 100 < $weight * $threshold) {
                $warnings[] = __('matching.suchak_weak_signal', [
                    'field' => __('matching.field_'.$fieldKey),
                ]);
            }
        }

        return array_values(array_unique($warnings));
    }

    private function fitLabel(int $score): string
    {
        return match (true) {
            $score >= $this->matchingConfig->suchakStrongFitScore() => __('matching.suchak_fit_strong'),
            $score >= $this->matchingConfig->suchakPossibleFitScore() => __('matching.suchak_fit_possible'),
            default => __('matching.suchak_fit_review'),
        };
    }

    private function fitSummary(string $fitLabel, int $score, int $reasonCount, int $warningCount): string
    {
        // Latin digits only (frozen workspace rule) — plain int interpolation, no locale-aware formatter.
        $summary = $fitLabel.' · '.__('matching.score_percent', ['n' => $score]);

        if ($reasonCount > 0) {
            $summary .= ' · '.trans_choice('matching.suchak_fit_signals', $reasonCount, ['n' => $reasonCount]);
        }

        if ($warningCount > 0) {
            $summary .= ' · '.trans_choice('matching.suchak_fit_notes', $warningCount, ['n' => $warningCount]);
        }

        return $summary;
    }
}
