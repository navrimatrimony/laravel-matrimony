<?php

namespace App\Modules\Suchak\Services;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakMarketplaceChallenge;
use App\Models\SuchakProfileRepresentation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * ONE OF MY CANDIDATES, AND EVERY ANSWER THE MARKET GAVE FOR HIM (blueprint phase 5).
 *
 * `SuchakMarketplaceChallengeService::proposalsFor()` already reads the proposals standing against
 * ONE challenge. That is the right door for accepting or rejecting, and the wrong one for deciding:
 * a candidate who has been published twice — a first challenge that expired, a second at a better
 * share — has his answers split across two lists, and the Suchak compares them by remembering the
 * first one. This read inverts the axis: the candidate is the subject, the challenges are the
 * columns, and every proposal anyone has ever made against him is on one page.
 *
 * ── FOUR THINGS THIS SERVICE REFUSES TO OWN ─────────────────────────────────────────────────────
 *
 *  - The proposal row. {@see SuchakMarketplaceChallengeService::proposalPayload()} is the one shape
 *    a proposal has, and it is emitted VERBATIM. It is also what carries the masking: the proposed
 *    candidate goes through {@see SuchakCandidateMaskingService::maskedSummary()} in there, so D19a's
 *    four defaults and the proposing Suchak's per-candidate reveals apply here the instant he
 *    changes them, and there is no second cross-Suchak presenter in this file. Nothing below
 *    assembles a candidate payload by hand.
 *  - The badge. {@see SuchakMarketplaceChallengeService::assertMarketplaceViewer()} — D18 gates
 *    every marketplace surface that shows another Suchak's candidate, and owning the challenge is
 *    not a substitute for holding the badge (the exact hole proposalsFor() records having had).
 *  - The candidate filters. {@see SuchakCrossSearchService::applyCrossSuchakProfileFilters()} —
 *    the ONE filter owner, in its cross-Suchak mode. See the oracle note below.
 *  - The score. {@see SuchakMatchFitService::fit()}, the real engine, called with the PROPOSED
 *    candidate's representation as the masked side so the explanation cannot resolve finer than the
 *    card it is printed beside (duplicate-trap 14).
 *
 * ── THE ORACLE, WHICH IS THE WHOLE DIFFICULTY ───────────────────────────────────────────────────
 *
 * Every row here is a cross-Suchak disclosure, so every way of NARROWING the page is a way of
 * asking a question about a hidden value and reading the answer off the row count. This class
 * writes no new rule for that and must never write one — the two that exist are
 * {@see SuchakCrossSearchService::OWN_BOOK_ONLY_FILTERS} (name and the income bounds are refused
 * unless the rows are the caller's own) and
 * {@see SuchakCrossSearchService::CROSS_SEARCH_NARROWEST_FILTERABLE_HIERARCHY} (a location id no
 * deeper than the taluka the masked card already prints). Both arrive through the single
 * pass-through above, silently, exactly as they behave on `/suchak/search`.
 *
 * SORTING IS THE SAME QUESTION ASKED SIDEWAYS, and it has its own allow-list ({@see self::SORTS})
 * for that reason. An ordering is a comparison, and a comparison over a hidden attribute leaks it
 * without ever filtering: sort a page by income and the sequence names who earns more even though
 * no figure is printed; sort by village and the grouping names the villages. So the allow-list
 * carries only facts the masked card has ALREADY printed — `requested_at` (a proposal fact, not a
 * candidate one), `age_years` (printed under `basic`) and the capped `match_score` (published
 * beside every masked card on `/suchak/search` today). There is deliberately no sort by location,
 * by income or by name, and the next sort added here must answer the owner's question first: could
 * the reader have READ this value on this surface?
 *
 * `match_field_points` is deliberately NOT emitted, although the cap makes it safe and
 * SuchakCrossSearchService publishes it. This read does not need per-field arithmetic to let a
 * Suchak compare four answers, and the narrowest payload that does the job is the one that cannot
 * leak the next time somebody widens the engine.
 */
class SuchakCandidateProposalInboxService
{
    /**
     * The orderings this inbox offers, and the whole list of them.
     *
     * Each one orders by a fact the masked card already prints — see the oracle note on the class.
     * `recent` is the default because a proposal is an event and the newest answer is the one a
     * Suchak has not read yet.
     *
     * @var list<string>
     */
    public const SORTS = [
        'recent',
        'oldest',
        'fit_desc',
        'age_asc',
        'age_desc',
    ];

    public const DEFAULT_SORT = 'recent';

    /**
     * The bound on the set that is scored and sorted in memory.
     *
     * The corpus is the proposals made against ONE candidate, which is small by construction — a
     * challenge that drew two hundred answers is not a scenario this product has. The cap exists to
     * bound the fit loop the way {@see SuchakMarketplaceChallengeService::MAX_RANKED_OWN_CANDIDATES}
     * does, and the ordering is decided over the whole capped set BEFORE the page is sliced, so the
     * best answer is never stranded on page four.
     */
    public const MAX_INBOX_PROPOSALS = 200;

    public function __construct(
        private readonly SuchakMarketplaceChallengeService $challengeService,
        private readonly SuchakCrossSearchService $crossSearchService,
        private readonly SuchakMatchFitService $matchFitService,
        private readonly SuchakCandidateMaskingService $maskingService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $filters  the cross-Suchak candidate filters (see
     *                                         SuchakCrossSearchService::applyProfileFilters), plus
     *                                         `status`, `challenge_id` and `sort`
     * @return array<string, mixed>
     */
    public function inboxFor(
        SuchakProfileRepresentation $representation,
        SuchakAccount $account,
        array $filters = [],
        int $perPage = 20,
        int $page = 1,
    ): array {
        $account->refresh();
        $representation->refresh();

        // D18, and NOT ownership. Ownership is checked immediately below and is a different
        // question: these rows are other Suchaks' candidates whatever the challenge belongs to, so
        // the badge gate that protects browse() and proposalsFor() protects this too.
        $this->challengeService->assertMarketplaceViewer($account);
        $this->assertOwnCandidate($representation, $account);

        $this->challengeService->expireDue($account);

        $challenges = $this->challengesFor($representation);
        $challengeIds = $challenges->map(static fn (SuchakMarketplaceChallenge $c): int => (int) $c->id)->all();

        $sort = $this->normalizeSort($filters['sort'] ?? null);
        $rows = $this->rows($challengeIds, $representation, $filters);
        $ordered = $this->order($rows, $sort);

        $perPage = max(1, min(50, $perPage));
        $page = max(1, $page);
        $total = $ordered->count();

        return [
            'candidate' => $this->candidateHeader($representation, $challenges),
            'totals' => $this->totals($challengeIds),
            'challenges' => $this->challengeColumns($challenges, $challengeIds),
            'proposals' => $ordered->slice(($page - 1) * $perPage, $perPage)->values()->all(),
            'meta' => [
                'sort' => $sort,
                'available_sorts' => self::SORTS,
                'total' => $total,
                'current_page' => $page,
                'per_page' => $perPage,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                // Said out loud rather than discovered: the ranked set is capped, and a Suchak past
                // the cap must filter. Silence here is how a missing answer reads as "nobody
                // proposed" instead of as "the page stopped".
                'ranked_cap' => self::MAX_INBOX_PROPOSALS,
            ],
        ];
    }

    /**
     * Every challenge ever published FOR THIS CANDIDATE, in every state.
     *
     * Withdrawn and expired ones included, and that is the point of the read: A8 makes the share
     * stick to candidates already suggested under a challenge for twelve months, so a proposal made
     * under a challenge the publisher has since pulled is still an answer he received and still
     * carries terms he owes. Dropping the closed challenges would drop their proposals with them.
     *
     * @return Collection<int, SuchakMarketplaceChallenge>
     */
    private function challengesFor(SuchakProfileRepresentation $representation): Collection
    {
        return SuchakMarketplaceChallenge::query()
            ->where('representation_id', (int) $representation->id)
            ->with(['suchakAccount', 'representation.matrimonyProfile', 'customerAgreement.servicePackage'])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The proposal rows, filtered and scored.
     *
     * @param  list<int>  $challengeIds
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(
        array $challengeIds,
        SuchakProfileRepresentation $ownRepresentation,
        array $filters,
    ): Collection {
        if ($challengeIds === []) {
            return collect();
        }

        $ownProfile = $ownRepresentation->matrimonyProfile;

        return $this->baseQuery($challengeIds, $filters)
            ->when(
                $this->statusFilter($filters),
                fn (Builder $query, string $status): Builder => $query->where('status', $status),
            )
            ->when(
                $this->challengeFilter($filters, $challengeIds),
                fn (Builder $query, int $challengeId): Builder => $query->where('marketplace_challenge_id', $challengeId),
            )
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->limit(self::MAX_INBOX_PROPOSALS)
            ->get()
            ->map(fn (SuchakCollaborationRequest $request): array => $this->row($request, $ownProfile));
    }

    /**
     * The corpus predicate, shared by the page and by the unfiltered totals beside it.
     *
     * The candidate filters go through the ONE owner in cross-Suchak mode; passing `[]` therefore
     * still applies its active-profile scope, which is what keeps `totals` counting the same corpus
     * the page is a slice of. A totals figure computed over a wider corpus than the list is a
     * discrepancy the Suchak cannot explain and would read as a lost proposal.
     *
     * @param  list<int>  $challengeIds
     * @param  array<string, mixed>  $filters
     * @return Builder<SuchakCollaborationRequest>
     */
    private function baseQuery(array $challengeIds, array $filters): Builder
    {
        return SuchakCollaborationRequest::query()
            ->whereIn('marketplace_challenge_id', $challengeIds)
            ->with([
                'requestingSuchakAccount',
                'requestingRepresentation.matrimonyProfile',
                'commissionAgreement',
            ])
            ->whereHas(
                'requestingRepresentation.matrimonyProfile',
                function (Builder $query) use ($filters): void {
                    $this->crossSearchService->applyCrossSuchakProfileFilters($query, $filters);
                },
            );
    }

    /**
     * One proposal.
     *
     * `proposalPayload()` verbatim — the same keys `GET /marketplace/challenges/{id}/proposals`
     * returns, so the two doors cannot disagree about what a proposal is or about what of the
     * proposed candidate is visible. Three keys are added on top and each is a fact this axis needs
     * and that axis does not:
     *
     *  - `responded_at`: when the publisher answered. On a single-challenge list every row is at the
     *    same stage of one decision; across challenges they are not.
     *  - `candidate_consent_valid`: whether the PROPOSING Suchak's candidate still holds live
     *    consent. Recorded, not enforced: the row stays and the payload is unchanged, because a
     *    proposal a publisher must still reject is not made to disappear by a lapse on the other
     *    side. It is here so he can see the reason without opening anything.
     *  - `fit`: the capped fit against HIS OWN candidate, which is the comparison this whole read
     *    exists for.
     *
     * The DECLARED SHARE is deliberately not repeated per row. proposalsFor() records why — it is
     * the challenge's, one per listing, and printing it on every row invites the two to disagree —
     * and this read has more reason to obey that than any other, because its rows genuinely come
     * from different challenges at different shares. It is published once per challenge, in
     * `challenges`, read from the same listing payload the marketplace prints.
     *
     * @return array<string, mixed>
     */
    private function row(SuchakCollaborationRequest $request, ?MatrimonyProfile $ownProfile): array
    {
        $representation = $request->requestingRepresentation;
        $profile = $representation?->matrimonyProfile;

        return $this->challengeService->proposalPayload($request) + [
            'responded_at' => $request->responded_at?->toIso8601String(),
            'candidate_consent_valid' => $representation?->hasValidConsent() === true,
            'fit' => $this->fit($ownProfile, $profile, $representation),
        ];
    }

    /**
     * How well this proposed candidate answers MY candidate.
     *
     * Direction: seeker = my candidate, candidate = the proposed one, which is the shape every
     * other caller of fit() uses (the row being ranked is always the second argument). The third
     * argument is the PROPOSED candidate's representation, because that is the side seen through
     * the mask here — passing it is what collapses the exact-location tier and the lat/lng the
     * nearby-taluka reason measures with, so the score cannot resolve a village the card withheld
     * (duplicate-trap 14). A caller that forgot it would lose precision, never leak.
     *
     * A null fit — an ineligible pair, or a score under the surfacing floor — is scored 0 and KEPT.
     * The publisher may accept or reject any proposal made to him; hiding one behind a floor he was
     * never shown and cannot turn off would be a filter pretending to be an absence.
     *
     * @return array<string, mixed>
     */
    private function fit(
        ?MatrimonyProfile $ownProfile,
        ?MatrimonyProfile $proposedProfile,
        ?SuchakProfileRepresentation $proposedRepresentation,
    ): array {
        $fit = $ownProfile instanceof MatrimonyProfile && $proposedProfile instanceof MatrimonyProfile
            ? $this->matchFitService->fit($ownProfile, $proposedProfile, $proposedRepresentation)
            : null;

        return [
            'match_score' => (int) ($fit['match_score'] ?? 0),
            'fit_label' => $fit['fit_label'] ?? __('matching.suchak_fit_none'),
            'reasons' => array_values($fit['reasons'] ?? []),
            'warnings' => array_values($fit['warnings'] ?? []),
        ];
    }

    /**
     * The requested ordering, over the whole capped set, before the page is sliced.
     *
     * Every tie breaks on `collaboration_id` descending so two proposals with the same age or the
     * same score have ONE order and page 2 never repeats a row from page 1. An explicit comparator
     * rather than sortBy([...]) for the reason ownCandidatesFor() records: a one-argument closure
     * inside a sortBy array is silently called as a two-argument comparator.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function order(Collection $rows, string $sort): Collection
    {
        $tie = static fn (array $row): int => (int) ($row['collaboration_id'] ?? 0);

        // A missing age sorts LAST in both directions rather than first in one of them: "not
        // recorded" is not "youngest", and a card with no age is not an answer to an age question.
        $age = static fn (array $row): int => (int) ($row['proposed_candidate']['basic']['age_years'] ?? PHP_INT_MAX);

        return match ($sort) {
            'oldest' => $rows->sort(static fn (array $a, array $b): int => [
                (string) ($a['requested_at'] ?? ''), -$tie($a),
            ] <=> [
                (string) ($b['requested_at'] ?? ''), -$tie($b),
            ])->values(),
            'fit_desc' => $rows->sort(static fn (array $a, array $b): int => [
                (int) $b['fit']['match_score'], $tie($b),
            ] <=> [
                (int) $a['fit']['match_score'], $tie($a),
            ])->values(),
            'age_asc' => $rows->sort(static fn (array $a, array $b): int => [
                $age($a), -$tie($a),
            ] <=> [
                $age($b), -$tie($b),
            ])->values(),
            'age_desc' => $rows->sort(static fn (array $a, array $b): int => [
                $age($a) === PHP_INT_MAX ? PHP_INT_MAX : -$age($a), -$tie($a),
            ] <=> [
                $age($b) === PHP_INT_MAX ? PHP_INT_MAX : -$age($b), -$tie($b),
            ])->values(),
            default => $rows->sort(static fn (array $a, array $b): int => [
                (string) ($b['requested_at'] ?? ''), $tie($b),
            ] <=> [
                (string) ($a['requested_at'] ?? ''), $tie($a),
            ])->values(),
        };
    }

    /**
     * MY candidate, unmasked, because he is mine.
     *
     * Masking is what one Suchak may see of ANOTHER Suchak's candidate (D19a); this is the caller's
     * own row, already on his own customer list under his own name. The three facts are read
     * through SuchakCandidateMaskingService's SHARED public readers — the one age rule and the one
     * lookup-label rule — so this header states them exactly as every other Suchak surface does.
     *
     * @param  Collection<int, SuchakMarketplaceChallenge>  $challenges
     * @return array<string, mixed>
     */
    private function candidateHeader(
        SuchakProfileRepresentation $representation,
        Collection $challenges,
    ): array {
        $profile = $representation->matrimonyProfile;

        return [
            'representation_id' => (int) $representation->id,
            'candidate_profile_id' => $profile === null ? null : (int) $profile->id,
            'display_name' => trim((string) ($profile->full_name ?? '')) ?: null,
            'age_years' => $this->maskingService->ageYears($profile?->date_of_birth),
            'gender' => $this->maskingService->masterLabel($profile?->gender),
            'challenges_published' => $challenges->count(),
            'open_challenges' => $challenges
                ->filter(static fn (SuchakMarketplaceChallenge $c): bool => $c->isOpen() && ! $c->isPastExpiry())
                ->count(),
        ];
    }

    /**
     * What the market did, before any filter the reader typed.
     *
     * Counted over the base corpus — the same active-profile scope the page uses and none of the
     * reader's own filters — so a Suchak who filters down to two rows can still see he received
     * eleven. `proposing_suchaks` is the figure A12's adverse-selection argument turns on: five
     * proposals from one Suchak is not five answers.
     *
     * @param  list<int>  $challengeIds
     * @return array<string, int>
     */
    private function totals(array $challengeIds): array
    {
        if ($challengeIds === []) {
            return [
                'proposals' => 0,
                'pending' => 0,
                'accepted' => 0,
                'rejected' => 0,
                'other' => 0,
                'proposing_suchaks' => 0,
            ];
        }

        $rows = $this->baseQuery($challengeIds, [])
            ->get(['id', 'status', 'requesting_suchak_account_id']);

        $byStatus = static fn (string $status): int => $rows
            ->where('status', $status)
            ->count();

        $pending = $byStatus(SuchakCollaborationRequest::STATUS_PENDING);
        $accepted = $byStatus(SuchakCollaborationRequest::STATUS_ACCEPTED);
        $rejected = $byStatus(SuchakCollaborationRequest::STATUS_REJECTED);

        return [
            'proposals' => $rows->count(),
            'pending' => $pending,
            'accepted' => $accepted,
            'rejected' => $rejected,
            'other' => $rows->count() - $pending - $accepted - $rejected,
            'proposing_suchaks' => $rows->pluck('requesting_suchak_account_id')->unique()->count(),
        ];
    }

    /**
     * One column per challenge: the terms the answers underneath it were given under.
     *
     * `declared_share` is `listingPayload()`'s, read rather than recomputed — the same block the
     * marketplace prints, so the share a helper accepted and the share the publisher reads here are
     * one number. The candidate is stripped out of it: it is the reader's own candidate, it is
     * already in `candidate` above, and republishing the masked version of his own row beside the
     * unmasked one would be two answers to one question.
     *
     * @param  Collection<int, SuchakMarketplaceChallenge>  $challenges
     * @param  list<int>  $challengeIds
     * @return list<array<string, mixed>>
     */
    private function challengeColumns(Collection $challenges, array $challengeIds): array
    {
        $counts = $challengeIds === []
            ? collect()
            : SuchakCollaborationRequest::query()
                ->whereIn('marketplace_challenge_id', $challengeIds)
                ->selectRaw('marketplace_challenge_id, COUNT(*) as proposal_count')
                ->groupBy('marketplace_challenge_id')
                ->pluck('proposal_count', 'marketplace_challenge_id');

        return $challenges
            ->map(function (SuchakMarketplaceChallenge $challenge) use ($counts): array {
                $listing = $this->challengeService->listingPayload($challenge);

                return [
                    'challenge_id' => (int) $challenge->id,
                    'status' => $challenge->status,
                    'published_at' => $challenge->published_at?->toIso8601String(),
                    'expires_at' => $challenge->expires_at?->toIso8601String(),
                    'expires_never' => $challenge->expires_at === null,
                    'withdrawn_at' => $challenge->withdrawn_at?->toIso8601String(),
                    'withdrawn_reason' => $challenge->withdrawn_reason,
                    'fulfilled_at' => $challenge->fulfilled_at?->toIso8601String(),
                    'publisher_note' => $challenge->publisher_note,
                    'declared_share' => $listing['declared_share'],
                    'proposal_count' => (int) ($counts[$challenge->id] ?? 0),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function statusFilter(array $filters): ?string
    {
        $status = trim((string) ($filters['status'] ?? ''));

        return in_array($status, SuchakCollaborationRequest::STATUSES, true) ? $status : null;
    }

    /**
     * A challenge id the reader may narrow to — but only one of HIS OWN candidate's challenges.
     *
     * An id outside that set narrows nothing rather than 404ing, the same silent refusal the
     * location and name filters get: a refusal that distinguishes "not yours" from "does not exist"
     * is a membership oracle over the challenge table.
     *
     * @param  array<string, mixed>  $filters
     * @param  list<int>  $challengeIds
     */
    private function challengeFilter(array $filters, array $challengeIds): ?int
    {
        $challengeId = (int) ($filters['challenge_id'] ?? 0);

        return $challengeId > 0 && in_array($challengeId, $challengeIds, true) ? $challengeId : null;
    }

    private function normalizeSort(mixed $value): string
    {
        $sort = trim((string) ($value ?? ''));

        return in_array($sort, self::SORTS, true) ? $sort : self::DEFAULT_SORT;
    }

    /**
     * This inbox is about ONE Suchak's own candidate, and there is no cross-account read of it.
     *
     * The proposals inside belong to other Suchaks, but the QUESTION — "what did the market answer
     * for my customer" — is the customer-owning Suchak's alone. Same refusal proposalsFor() makes
     * about a challenge, one axis up.
     */
    private function assertOwnCandidate(
        SuchakProfileRepresentation $representation,
        SuchakAccount $account,
    ): void {
        if ((int) $representation->suchak_account_id !== (int) $account->id) {
            throw new InvalidArgumentException('हे स्थळ तुमच्या खात्याचे नाही.');
        }
    }
}
