<?php

namespace App\Modules\Suchak\Services;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakMatchSuggestion;
use App\Models\SuchakProfileRepresentation;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * The append-only IMPRESSION + DECISION log for Suchak match suggestions.
 *
 * Why it exists: to LEARN from what a Suchak actually picks we must keep both
 * halves — what the system SHOWED, and what the Suchak DID about it. Nothing in
 * the codebase kept the first half:
 *   - `profile_matches`          replace-on-write cache of current top matches
 *   - `user_match_behaviors`     member-actor actions, no Suchak, no impression
 *   - `profile_match_tab_skips`  skip signal only
 *   - `interests` / `shortlists` positive outcomes only
 *
 * Contract of this table: rows are NEVER deleted or replaced wholesale. Recording
 * the same (seeker, candidate) inside the same run is idempotent; recording it in
 * a LATER run adds a new row, which is exactly how a candidate re-surfaces after
 * the cooling period. A decision only ever updates its own row.
 *
 * This service records; it does not decide what to suggest and changes no
 * existing matching behaviour.
 */
class SuchakMatchSuggestionLogService
{
    /**
     * Record a batch of suggestions for one seeker. Idempotent per
     * (seeker, candidate, run_key): re-showing the same pair in the same run
     * refreshes the score/reason snapshot instead of adding a second row, and
     * never touches an already-recorded decision.
     *
     * @param  list<array{candidate_profile_id?: int|string, candidate_profile?: MatrimonyProfile, score?: int|null, reasons?: array<int|string, mixed>|null}>  $suggestions
     * @return int Number of pairs recorded (inserted or refreshed).
     */
    public function recordSuggestions(
        SuchakAccount|int $account,
        MatrimonyProfile|int $seekerProfile,
        array $suggestions,
        ?string $runKey = null,
        SuchakProfileRepresentation|int|null $representation = null,
        ?Carbon $at = null,
    ): int {
        $accountId = $this->idOf($account);
        $seekerId = $this->idOf($seekerProfile);
        $representationId = $representation === null ? null : $this->idOf($representation);
        $at = $at ? $at->copy() : now();
        $runKey = $this->normalizeRunKey($runKey, $at);

        $rows = [];
        $seenCandidateIds = [];

        foreach ($suggestions as $suggestion) {
            $candidateId = $this->resolveCandidateId($suggestion);

            if ($candidateId === null || $candidateId === $seekerId) {
                continue;
            }

            // Guard the in-payload duplicate too — an upsert with two identical
            // unique keys in one statement is a DB error on some drivers.
            if (isset($seenCandidateIds[$candidateId])) {
                continue;
            }
            $seenCandidateIds[$candidateId] = true;

            $reasons = $suggestion['reasons'] ?? null;

            $rows[] = [
                'suchak_account_id' => $accountId,
                'representation_id' => $representationId,
                'seeker_profile_id' => $seekerId,
                'candidate_profile_id' => $candidateId,
                'run_key' => $runKey,
                'score' => isset($suggestion['score']) ? (int) $suggestion['score'] : null,
                'reasons_json' => is_array($reasons) ? json_encode($reasons) : null,
                'suggested_at' => $at,
                'decision' => SuchakMatchSuggestion::DECISION_PENDING,
                'rejection_reason_code' => null,
                'rejection_note' => null,
                'decided_at' => null,
                'created_at' => $at,
                'updated_at' => $at,
            ];
        }

        if ($rows === []) {
            return 0;
        }

        // Only the snapshot columns are refreshed on conflict. suggested_at,
        // decision, rejection_* and decided_at are deliberately NOT in the update
        // list so a re-render can never rewrite history or erase a decision.
        SuchakMatchSuggestion::query()->upsert(
            $rows,
            ['seeker_profile_id', 'candidate_profile_id', 'run_key'],
            ['score', 'reasons_json', 'updated_at']
        );

        return count($rows);
    }

    /**
     * Record what the Suchak did about one suggestion row.
     *
     * @param  string  $decision  one of SuchakMatchSuggestion::DECIDED_DECISIONS
     */
    public function recordDecision(
        SuchakMatchSuggestion|int $suggestion,
        string $decision,
        ?string $rejectionReasonCode = null,
        ?string $rejectionNote = null,
        ?Carbon $at = null,
    ): SuchakMatchSuggestion {
        $row = $suggestion instanceof SuchakMatchSuggestion
            ? $suggestion
            : SuchakMatchSuggestion::query()->findOrFail($suggestion);

        if (! in_array($decision, SuchakMatchSuggestion::DECIDED_DECISIONS, true)) {
            throw new InvalidArgumentException("Unsupported suggestion decision [{$decision}].");
        }

        $reasonCode = null;
        $note = null;

        if ($decision === SuchakMatchSuggestion::DECISION_REJECTED) {
            $reasonCode = $rejectionReasonCode ?? SuchakMatchSuggestion::REJECTION_OTHER;

            if (! in_array($reasonCode, SuchakMatchSuggestion::REJECTION_REASON_CODES, true)) {
                throw new InvalidArgumentException("Unsupported rejection reason code [{$reasonCode}].");
            }

            $note = $this->text($rejectionNote);
        }

        $row->forceFill([
            'decision' => $decision,
            'rejection_reason_code' => $reasonCode,
            'rejection_note' => $note,
            'decided_at' => $at ? $at->copy() : now(),
        ])->save();

        return $row;
    }

    /**
     * Decide on the most recent suggestion of a (seeker, candidate) pair — the
     * shape an API endpoint will actually use, since a client holds profile ids,
     * not log-row ids. Returns null when that pair was never suggested.
     */
    public function recordDecisionForPair(
        MatrimonyProfile|int $seekerProfile,
        MatrimonyProfile|int $candidateProfile,
        string $decision,
        ?string $rejectionReasonCode = null,
        ?string $rejectionNote = null,
        ?Carbon $at = null,
    ): ?SuchakMatchSuggestion {
        $row = SuchakMatchSuggestion::query()
            ->forSeeker($seekerProfile)
            ->where('candidate_profile_id', $this->idOf($candidateProfile))
            ->orderByDesc('suggested_at')
            ->orderByDesc('id')
            ->first();

        if ($row === null) {
            return null;
        }

        return $this->recordDecision($row, $decision, $rejectionReasonCode, $rejectionNote, $at);
    }

    /**
     * Every candidate ever shown for this seeker. Used to EXCLUDE repeats while
     * fresh candidates still exist.
     *
     * @return list<int>
     */
    public function alreadySuggestedCandidateIds(MatrimonyProfile|int $seekerProfile): array
    {
        return SuchakMatchSuggestion::query()
            ->forSeeker($seekerProfile)
            ->distinct()
            ->pluck('candidate_profile_id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Candidates shown to this seeker inside the cooling window — the set that is
     * still "too soon" to repeat. Anything in alreadySuggestedCandidateIds() but
     * NOT here has cooled off and may be re-surfaced once nothing new is left.
     *
     * @return list<int>
     */
    public function suggestedRecently(
        MatrimonyProfile|int $seekerProfile,
        int $days = SuchakMatchSuggestion::DEFAULT_COOLING_PERIOD_DAYS,
        ?Carbon $at = null,
    ): array {
        $at = $at ? $at->copy() : now();
        $since = $at->copy()->subDays(max(0, $days));

        return SuchakMatchSuggestion::query()
            ->forSeeker($seekerProfile)
            ->suggestedSince($since)
            ->distinct()
            ->pluck('candidate_profile_id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Candidates previously shown whose cooling period has elapsed — safe to
     * re-surface. Convenience over the two lists above.
     *
     * @return list<int>
     */
    public function cooledOffCandidateIds(
        MatrimonyProfile|int $seekerProfile,
        int $days = SuchakMatchSuggestion::DEFAULT_COOLING_PERIOD_DAYS,
        ?Carbon $at = null,
    ): array {
        $recent = $this->suggestedRecently($seekerProfile, $days, $at);

        return array_values(array_diff($this->alreadySuggestedCandidateIds($seekerProfile), $recent));
    }

    /**
     * Per-day bucket by default: showing the same pair twice in one day is one
     * impression, while a later day is a genuinely new suggestion.
     */
    private function normalizeRunKey(?string $runKey, Carbon $at): string
    {
        $runKey = is_string($runKey) ? trim($runKey) : '';

        if ($runKey === '') {
            return 'd:'.$at->toDateString();
        }

        return mb_substr($runKey, 0, 64);
    }

    /**
     * @param  array<string, mixed>  $suggestion
     */
    private function resolveCandidateId(array $suggestion): ?int
    {
        $candidate = $suggestion['candidate_profile'] ?? null;

        if ($candidate instanceof MatrimonyProfile) {
            return (int) $candidate->getKey();
        }

        $id = $suggestion['candidate_profile_id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    private function idOf(object|int $value): int
    {
        return is_int($value) ? $value : (int) $value->getKey();
    }

    private function text(?string $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }
}
