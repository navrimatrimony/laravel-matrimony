<?php

namespace App\Modules\Suchak\Services;

use App\Models\MatrimonyProfile;
use App\Models\SuchakProfileRepresentation;
use App\Services\Gunamilan\GunamilanPairEvaluator;
use App\Services\Gunamilan\MangalCompatibility;
use App\Services\Matching\MatchingConfigService;
use App\Services\Matching\MatchingService;
use App\Services\ProfilePreferenceMatchService;
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
 *
 * IT IS ALSO THE D19a BOUNDARY FOR SCORING, and that is why the boundary is here and not in the
 * engine. MatchingService is shared with the MEMBER feed, and D19b says in as many words that the
 * member surface is NOT covered by D19a — a member choosing for themselves must keep the exact
 * village tier. So the engine only offers the cap as a parameter; the DECISION to use it is taken
 * here, on the Suchak side of the fence, from the one owner of the reveal rule
 * ({@see SuchakCandidateMaskingService::revealsVillage()}). Nothing in the member path reaches this
 * class, so nothing in the member path can be degraded by it.
 */
class SuchakMatchFitService
{
    public function __construct(
        private readonly MatchingService $matching,
        private readonly MatchingConfigService $matchingConfig,
        private readonly SuchakCandidateMaskingService $masking,
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
     *     match_field_points: array<string, int>,
     *     gunamilan: array<string, mixed>
     * }|null  Null when the pair is ineligible (same gender, self, hard preference conflict) or scores
     *         below the configured surfacing floor.
     *
     * ADDITIVE ONLY — two Flutter apps consume this shape. `gunamilan` and the new `gunamilan` entry
     * inside `match_field_points` are new keys; nothing existing was renamed, retyped or removed.
     *
     * @param  SuchakProfileRepresentation|null  $maskedSideRepresentation
     *   The representation of whichever side of this pair the reader sees THROUGH THE MASK — i.e. the
     *   one that was passed to {@see SuchakCandidateMaskingService::maskedSummary()} for the card this
     *   explanation is printed beside. Usually the candidate; on the marketplace's own-candidate
     *   picker it is the SEEKER (the challenge's candidate), because there it is the seeker that
     *   belongs to another Suchak.
     *
     *   It exists because the explanation was confirming the very village the card withholds: an
     *   exact `location_id` match scored the full location weight and said "same city", a taluka-only
     *   match scored 90% and said "same taluka", so one probe candidate per village under the shown
     *   taluka read D19a's hidden value straight out of `match_field_points['location']`. The fix is
     *   NOT to remove the location signal — D19a's whole argument is that a matchmaker who cannot see
     *   enough cannot propose a match — it is to stop the signal resolving finer than the card. The
     *   exact-match tier collapses into the taluka tier; where `shares_village` is set the village is
     *   on the card anyway and full precision is correct and kept.
     *
     *   NULL means nothing is revealed (an unrepresented platform member, or a caller that did not
     *   say). The default is therefore the SAFE answer, not the precise one — a future caller that
     *   forgets this argument loses precision, it does not leak.
     */
    public function fit(
        MatrimonyProfile $seeker,
        MatrimonyProfile $candidate,
        ?SuchakProfileRepresentation $maskedSideRepresentation = null,
    ): ?array {
        if (! $this->matching->isEligiblePair($seeker, $candidate)) {
            return null;
        }

        // Suchak-initiated: no ACTOR boost / behaviour layer (decision C — the represented candidate's
        // dormant account has no activity, and the Suchak's own activity is not this candidate's).
        // The candidate-intrinsic quality delta (verified / photo / complete / recently touched) still
        // applies — see {@see MatchingService::computeMatchBreakdown()} — because it describes the
        // candidate, not the actor, and a Suchak must not see an empty card tied with a verified one.
        // The reveal rule is READ, never restated: SuchakCandidateMaskingService is the one place
        // `shares_village` is interpreted, and the card and the score now take the same answer from it.
        $breakdown = $this->matching->computeMatchBreakdown(
            $seeker,
            $candidate,
            false,
            ! $this->masking->revealsVillage($maskedSideRepresentation),
        );

        $score = (int) ($breakdown['final_score'] ?? 0);
        if ($score < $this->matchingConfig->suchakMinFitScore()) {
            return null;
        }

        /** @var array<string, int> $fieldPoints */
        $fieldPoints = $breakdown['field_points'] ?? [];

        $gunamilan = $this->gunamilanPayload($seeker, $candidate);

        $reasons = $this->reasonsFrom($breakdown);
        $warnings = $this->warningsFrom($fieldPoints, $seeker, $candidate);
        // The गुणमिलन note is worded by verdict, never derived from "low points" — a Suchak must
        // never read missing patrika data as a rejection, which is exactly what the generic
        // weak-signal rule would have said (see warningsFrom()).
        $gunamilanWarning = $this->gunamilanWarning($gunamilan);
        if ($gunamilanWarning !== null) {
            $warnings[] = $gunamilanWarning;
            $warnings = array_values(array_unique($warnings));
        }

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
            'gunamilan' => $gunamilan,
        ];
    }

    /**
     * The full गुणमिलन breakdown for one pair — everything a family needs so they do not have to
     * take the patrika to a pandit: the total out of 36, all eight kootas with their own points and
     * note, the Nadi and Bhakoot dosha flags, and the separate Mangal verdict.
     *
     * Three verdicts, and they are deliberately three, not two:
     *  - `compatible`     — computed, 18 or more of 36 (inclusive).
     *  - `not_compatible` — computed, under 18.
     *  - `unknown`        — NOT computed. One or both sides have no patrika data on file. This is the
     *                       normal state for ~87% of profiles and must never be shown, worded or
     *                       counted as a rejection.
     *
     * `total_points` is only meaningful when `computable` is true; when it is false the number is 0.0
     * as an artefact and `is_compatible` is null. Consumers branch on `verdict` / `computable`.
     *
     * @return array<string, mixed>
     */
    private function gunamilanPayload(MatrimonyProfile $seeker, MatrimonyProfile $candidate): array
    {
        $verdict = GunamilanPairEvaluator::verdictFor($seeker, $candidate);

        $computable = ($verdict['computable'] ?? false) === true;
        $isCompatible = $verdict['is_compatible'] ?? null;

        $state = match (true) {
            ! $computable => 'unknown',
            $isCompatible === true => 'compatible',
            default => 'not_compatible',
        };

        $totalPoints = (float) ($verdict['total_points'] ?? 0.0);
        $maxPoints = (float) ($verdict['max_points'] ?? 36.0);
        // Latin digits only (frozen workspace rule): "26/36", "18" — never Devanagari numerals and
        // never a locale-aware number formatter.
        $pointsLabel = GunamilanPairEvaluator::formatPoints($totalPoints).'/'.GunamilanPairEvaluator::formatPoints($maxPoints);

        $mangal = is_array($verdict['mangal'] ?? null) ? $verdict['mangal'] : [];
        $mangalState = match ($mangal['status'] ?? MangalCompatibility::STATUS_NOT_COMPUTABLE) {
            MangalCompatibility::STATUS_COMPATIBLE => 'compatible',
            MangalCompatibility::STATUS_NOT_COMPATIBLE => 'not_compatible',
            default => 'unknown',
        };

        return [
            'label' => __('matching.gunamilan_label'),
            'required_by_seeker' => $this->gunamilanRequiredBy($seeker),
            'state' => $state,
            'computable' => $computable,
            'is_compatible' => $isCompatible,
            'verdict_label' => __('matching.gunamilan_verdict_'.$state),
            'total_points' => $totalPoints,
            'max_points' => $maxPoints,
            'threshold' => (float) ($verdict['threshold'] ?? 18.0),
            'points_label' => $computable ? $pointsLabel : null,
            'summary' => $computable
                ? __('matching.gunamilan_summary', ['points' => $pointsLabel, 'verdict' => __('matching.gunamilan_verdict_'.$state)])
                : __('matching.gunamilan_verdict_unknown'),
            // All eight kootas, each with its own points / max / bride value / groom value / note, so
            // the app can render the whole table rather than a single number.
            'sections' => $verdict['sections'] ?? [],
            'nadi_dosha' => $verdict['nadi_dosha'] ?? null,
            'bhakoot_dosha' => $verdict['bhakoot_dosha'] ?? null,
            'mangal' => array_merge($mangal, [
                'state' => $mangalState,
                'verdict_label' => __('matching.gunamilan_mangal_verdict_'.$mangalState),
            ]),
            'missing_fields' => $verdict['missing_fields'] ?? [],
        ];
    }

    /**
     * Did this seeker actually ask for गुणमिलन? Read straight off the preference row that
     * {@see ProfilePreferenceMatchService} filters on, so the payload and the filter can never drift.
     */
    private function gunamilanRequiredBy(MatrimonyProfile $seeker): bool
    {
        $seeker->loadMissing('preferenceCriteria');

        return (bool) ($seeker->preferenceCriteria?->gunamilan_required ?? false);
    }

    /**
     * @param  array<string, mixed>  $gunamilan
     */
    private function gunamilanWarning(array $gunamilan): ?string
    {
        // `unknown` is NOT a warning. Missing patrika data is the normal state, not a defect, and a
        // review note here would be read as "these two do not match".
        if (($gunamilan['state'] ?? null) !== 'not_compatible') {
            return null;
        }

        return __('matching.gunamilan_review_note', [
            'points' => (string) ($gunamilan['points_label'] ?? ''),
        ]);
    }

    /**
     * Best-scoring pairing between the Suchak's own represented candidates and one target
     * representation. Unlike the heuristic it replaces, this ranks rather than taking the first hit.
     *
     * The target IS the masked side — both callers (SuchakCollaborationService,
     * SuchakDailyOpportunityService) draw it with `where suchak_account_id != <caller>` — so its
     * representation is what governs the location precision of every comparison below. Nothing to
     * decide here; the row is already in hand.
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

            $fit = $this->fit($ownProfile, $candidateProfile, $candidate);
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
    private function warningsFrom(
        array $fieldPoints,
        MatrimonyProfile $seeker,
        MatrimonyProfile $candidate,
    ): array {
        $threshold = $this->matchingConfig->suchakWeakSignalPercent();
        $warnings = [];

        // Location, like गुणमिलन, has two very different ways of scoring zero.
        // "They live far apart" is a real weak signal. "No village was ever
        // entered" is a data gap — and saying "location proximity needs review"
        // for it sends the Suchak looking at the wrong thing. Name the gap and
        // whose it is, so the fix is obvious.
        $seekerPlaced = $this->matching->residenceIsKnown($seeker);
        $candidatePlaced = $this->matching->residenceIsKnown($candidate);
        $locationUnknown = ! $seekerPlaced || ! $candidatePlaced;
        if ($locationUnknown) {
            $warnings[] = $seekerPlaced
                ? __('matching.location_missing_candidate')
                : __('matching.location_missing_seeker');
        }

        foreach ($fieldPoints as $fieldKey => $points) {
            if ($locationUnknown && (string) $fieldKey === 'location') {
                continue;
            }
            // गुणमिलन is exempt from the generic "earned less than :threshold% of its weight" rule.
            // A pair with no patrika data scores 0 by design and would trip it on ~87% of profiles,
            // telling the Suchak that missing data "needs review" — i.e. reading absent data as a
            // failed check. Its note is worded from the VERDICT instead, in gunamilanWarning().
            if ((string) $fieldKey === ProfilePreferenceMatchService::ROW_GUNAMILAN) {
                continue;
            }
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
