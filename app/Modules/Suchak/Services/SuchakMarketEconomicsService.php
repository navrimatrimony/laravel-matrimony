<?php

namespace App\Modules\Suchak\Services;

use App\Models\SuchakAccount;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakMarketplaceChallenge;
use App\Models\SuchakMarriageOutcome;
use App\Support\MoneyFormat;
use App\Support\PercentDisplay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * WHAT THE MARKETPLACE LOOKS LIKE TO A SUCHAK DECIDING WHETHER TO PUBLISH (blueprint phase 5).
 *
 * Section 9's visibility matrix is what makes this read legal at all: *"another customer's fees —
 * other verified Suchaks: ✅ (market economics)"*. It is the only line in that matrix that grants a
 * Suchak anything about a customer who is not his, and it grants it for one purpose — deciding
 * whether to open his own customer to competitors, and at what share. Phase 5's gate is *"the
 * market can sort itself"*, and a market cannot sort itself while every publisher is guessing what
 * a normal share is.
 *
 * ── AGGREGATE MEANS AGGREGATE ───────────────────────────────────────────────────────────────────
 *
 * Every figure below is a statement about other people's private terms, so the arithmetic is the
 * disclosure. "The typical share is 30%" computed over one challenge is that publisher's declared
 * share republished under a different sentence; over two, it names both. Three rules close that,
 * and they are enforced rather than documented:
 *
 *  1. THE VIEWER'S OWN ROWS ARE EXCLUDED FROM EVERY SET. A reader who knows his own contribution
 *     can subtract it, so he is never in the denominator. It also makes the read mean the right
 *     thing — "the market I would be publishing into" — and matches browse(), which has always
 *     excluded own challenges.
 *  2. A FIGURE IS WITHHELD BELOW {@see self::MIN_OBSERVATIONS} observations OR
 *     {@see self::MIN_DISTINCT_PUBLISHERS} distinct publishing accounts, whichever bites first.
 *     Both are needed and neither is enough: fifty challenges from one Suchak are one Suchak's
 *     terms, and five publishers with one challenge each are five figures a reader could line up
 *     against five browsable listings. Each block carries its OWN counts and its own verdict,
 *     because the sets differ — a market can have plenty of published challenges and almost no
 *     answered ones.
 *  3. THE MEDIAN, AND NOTHING ABOUT THE SPREAD. No minimum, no maximum, no quartiles. At n = 5 a
 *     quartile IS an individual Suchak's declared share, printed exactly; the median of an even
 *     count is the mean of the two middles and is a figure nobody declared. Publishing a range
 *     would undo the threshold from the other end.
 *
 * ── DERIVED, NEVER STORED ───────────────────────────────────────────────────────────────────────
 *
 * Nothing here is a column and nothing here is cached. Every figure is recomputed from
 * `suchak_marketplace_challenges`, `suchak_collaboration_requests` and `suchak_marriage_outcomes` on
 * each read, because a stored "answered count" is wrong the moment a proposal is withdrawn and a
 * stored share median is wrong the moment a challenge is republished at a new rate.
 *
 * THE COST, NAMED RATHER THAN ENGINEERED AWAY: this is one bounded row load plus four aggregate
 * queries. The bound is {@see self::WINDOW_DAYS} and it is a PRODUCT bound, not a performance
 * dodge — a share declared two years ago is not evidence about today's market — but it is also what
 * keeps the row load proportional to recent activity rather than to all history. `open_now` is
 * deliberately outside the window: a challenge published fourteen months ago and still open is
 * still competing for helpers today.
 */
class SuchakMarketEconomicsService
{
    /**
     * The smallest number of observations a published figure may rest on.
     *
     * FIVE, and five for the reason the rest of this codebase already uses five —
     * {@see SuchakReputationService::MIN_RATE_DENOMINATOR} suppresses a behavioural rate under the
     * same count, and one platform should not have two answers to "how thin is too thin". It is
     * also the conventional small-cell threshold in disclosure control: below five, a reader who
     * knows one participant can narrow the rest by arithmetic; at five, knowing your own value
     * still leaves four unknowns and a median cannot be inverted.
     */
    public const MIN_OBSERVATIONS = 5;

    /**
     * The smallest number of DISTINCT publishing accounts a published figure may rest on.
     *
     * The second half of the same rule, and the half that actually bites: observations are cheap to
     * manufacture — one Suchak can publish twenty challenges — and a threshold counted only in rows
     * would let him talk the market average onto his own terms while the figure still read as
     * "typical". The population being protected is people, so it is counted in people.
     */
    public const MIN_DISTINCT_PUBLISHERS = 5;

    /**
     * How far back an observation counts. Twelve months.
     *
     * A product decision, stated in the payload rather than hidden: rates move, and last year's
     * declared shares are not evidence about this year's market. It is also the only bound on the
     * row load — see the cost note on the class.
     */
    public const WINDOW_DAYS = 365;

    /** The one refusal sentence for a figure the market is too thin to support. */
    public const REFUSAL_TOO_THIN = 'बाजारपेठ अजून लहान आहे; ही आकडेवारी जाहीर करता येणार नाही.';

    public function __construct(
        private readonly SuchakMarketplaceChallengeService $challengeService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function marketFor(SuchakAccount $viewer, ?Carbon $at = null): array
    {
        $viewer->refresh();

        // D18. §9 grants "another customer's fees" to VERIFIED Suchaks and to nobody else, and A10
        // is why: an unverified second account is the cheap way to read the market without ever
        // being part of it. Same gate as browse(), spelled in the same one place.
        $this->challengeService->assertMarketplaceViewer($viewer);

        // The same sweep browse() runs, for the same reason: "open" must mean the same thing on
        // both screens, and there may be no scheduler on this production.
        $this->challengeService->expireDue();

        $at = $at === null ? now() : $at->copy();
        $since = $at->copy()->subDays(self::WINDOW_DAYS);

        $published = $this->publishedInWindow($viewer, $since, $at);
        $challengeIds = $published->map(static fn (SuchakMarketplaceChallenge $c): int => (int) $c->id)->all();
        $proposalFacts = $this->proposalFacts($challengeIds);
        $marriedChallengeIds = $this->challengeIdsWithRecordedMarriage($challengeIds);

        return [
            'as_of' => $at->toIso8601String(),
            'window' => [
                'days' => self::WINDOW_DAYS,
                'from' => $since->toIso8601String(),
                'to' => $at->toIso8601String(),
            ],
            // Published so a client can word its own "too thin" state, and so the threshold is a
            // fact of the API rather than a rule the reader has to infer from nulls.
            'minimum_population' => [
                'observations' => self::MIN_OBSERVATIONS,
                'distinct_publishers' => self::MIN_DISTINCT_PUBLISHERS,
                'reason' => self::REFUSAL_TOO_THIN,
            ],
            'open_now' => $this->openNow($viewer),
            'supply' => $this->supply($published),
            'response' => [
                'answered' => $this->answered($published, $proposalFacts),
                'speed' => $this->speed($published, $proposalFacts),
                'depth' => $this->depth($published, $proposalFacts),
            ],
            'declared_share' => [
                'percent' => $this->declaredSharePercent($published),
                'value_by_currency' => $this->declaredShareValues($published),
            ],
            'outcomes' => [
                'marriage' => $this->marriageOutcomes($published, $marriedChallengeIds),
            ],
        ];
    }

    // ── The sets ──────────────────────────────────────────────────────────────────────────────

    /**
     * Every challenge, in any state, published by SOMEBODY ELSE inside the window.
     *
     * Withdrawn, expired and fulfilled ones are all here on purpose. A market described only by its
     * live listings is a market with no failures in it, and "how often does a published challenge
     * end in a marriage" is a question about the closed ones.
     *
     * @return Collection<int, SuchakMarketplaceChallenge>
     */
    private function publishedInWindow(SuchakAccount $viewer, Carbon $since, Carbon $at): Collection
    {
        return SuchakMarketplaceChallenge::query()
            ->where('suchak_account_id', '!=', (int) $viewer->id)
            ->whereNotNull('published_at')
            ->whereBetween('published_at', [$since, $at])
            // Needed by declaredShareTotal() and declaredShareCurrency(); eager so the median is
            // one query plus arithmetic rather than one query per challenge.
            ->with('customerAgreement.servicePackage')
            ->orderBy('id')
            ->get();
    }

    /**
     * Proposal count and first-answer timestamp per challenge, in ONE grouped query.
     *
     * `MIN(requested_at)` rather than the first row by id: a proposal's `requested_at` is what the
     * ladder and the collaboration list both read, and ordering by id would answer "which row was
     * inserted first", which is a different question the day a backfill exists.
     *
     * @param  list<int>  $challengeIds
     * @return array<int, array{count: int, first_requested_at: ?string}>
     */
    private function proposalFacts(array $challengeIds): array
    {
        if ($challengeIds === []) {
            return [];
        }

        return SuchakCollaborationRequest::query()
            ->whereIn('marketplace_challenge_id', $challengeIds)
            ->selectRaw('marketplace_challenge_id, COUNT(*) as proposal_count, MIN(requested_at) as first_requested_at')
            ->groupBy('marketplace_challenge_id')
            ->get()
            ->mapWithKeys(static fn ($row): array => [
                (int) $row->marketplace_challenge_id => [
                    'count' => (int) $row->proposal_count,
                    'first_requested_at' => $row->first_requested_at === null
                        ? null
                        : (string) $row->first_requested_at,
                ],
            ])
            ->all();
    }

    /**
     * The challenges that produced a marriage somebody actually recorded.
     *
     * A JOIN rather than whereHas + a second pass, and the model's own live-rows-only global scope
     * does the rest: a claim an admin set aside is evidence of what was claimed and must not count
     * as an outcome. The scope qualifies its column with the table name, so it survives the join.
     *
     * NOT filtered on confirmation, and the key name says so (`recorded`). D26 makes a terminal
     * stage claim-then-confirm, and a market statistic that waited for every family to confirm
     * would under-report by exactly the confirmations still outstanding. What is published is what
     * the platform holds a record of; the strength of that record is the reputation read's subject,
     * not this one's.
     *
     * @param  list<int>  $challengeIds
     * @return list<int>
     */
    private function challengeIdsWithRecordedMarriage(array $challengeIds): array
    {
        if ($challengeIds === []) {
            return [];
        }

        return SuchakMarriageOutcome::query()
            ->join(
                'suchak_collaboration_requests as marketplace_engagement',
                'marketplace_engagement.id',
                '=',
                'suchak_marriage_outcomes.collaboration_request_id',
            )
            ->whereIn('marketplace_engagement.marketplace_challenge_id', $challengeIds)
            ->distinct()
            ->pluck('marketplace_engagement.marketplace_challenge_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    // ── The figures ───────────────────────────────────────────────────────────────────────────

    /**
     * How much of the market is live right now.
     *
     * THE ONE BLOCK THAT IS NEVER WITHHELD, and the reason is specific rather than an exception:
     * this predicate is browse()'s, exactly — live, audience-admitting, consent still valid, not
     * the viewer's own — so every row counted here is a row this same viewer can already page
     * through one by one, publisher name and declared share included. Withholding a count of what
     * the reader is already looking at protects nobody and leaves him unable to tell an empty
     * market from a broken screen.
     *
     * The audience list comes from {@see SuchakMarketplaceChallengeService::audiencesAdmitting()},
     * which is computed from the model's own rule, so this count and browse() cannot drift apart.
     *
     * @return array<string, mixed>
     */
    private function openNow(SuchakAccount $viewer): array
    {
        $rows = SuchakMarketplaceChallenge::query()
            ->live()
            ->whereIn('audience', $this->challengeService->audiencesAdmitting($viewer))
            ->where('suchak_account_id', '!=', (int) $viewer->id)
            ->whereHas('representation', fn (Builder $query) => $query->withValidConsent())
            ->pluck('suchak_account_id');

        return [
            'challenges' => $rows->count(),
            'publishers' => $rows->unique()->count(),
            // Stated rather than left to be inferred from the absence of the key: this block obeys
            // a different rule from every other one, and a client must not treat it as a bug.
            'is_withheld' => false,
            'withheld_reason' => null,
        ];
    }

    /**
     * What was published in the window, and how it ended.
     *
     * Thresholded, unlike `open_now`, because the closed states are NOT browsable: nobody but the
     * publisher can see that he withdrew a challenge, and a withdrawal count of 1 over 1 publisher
     * says which Suchak pulled his customer back out of the market.
     *
     * @param  Collection<int, SuchakMarketplaceChallenge>  $published
     * @return array<string, mixed>
     */
    private function supply(Collection $published): array
    {
        $byStatus = static fn (string $status): int => $published
            ->filter(static fn (SuchakMarketplaceChallenge $c): bool => $c->status === $status)
            ->count();

        return $this->block($this->publisherIds($published), [
            'published_challenges' => $published->count(),
            'open' => $byStatus(SuchakMarketplaceChallenge::STATUS_OPEN),
            'withdrawn' => $byStatus(SuchakMarketplaceChallenge::STATUS_WITHDRAWN),
            'expired' => $byStatus(SuchakMarketplaceChallenge::STATUS_EXPIRED),
            'fulfilled' => $byStatus(SuchakMarketplaceChallenge::STATUS_FULFILLED),
        ]);
    }

    /**
     * How often a published challenge gets any answer at all — A12's honest half.
     *
     * The denominator is every published challenge in the window, so a Suchak weighing publication
     * reads the real odds rather than the odds among the ones that worked.
     *
     * @param  Collection<int, SuchakMarketplaceChallenge>  $published
     * @param  array<int, array{count: int, first_requested_at: ?string}>  $proposalFacts
     * @return array<string, mixed>
     */
    private function answered(Collection $published, array $proposalFacts): array
    {
        $answered = $this->answeredChallenges($published, $proposalFacts);

        return $this->block($this->publisherIds($published), [
            'answered_challenges' => $answered->count(),
            'answered_rate_percent' => PercentDisplay::rate($answered->count(), $published->count()),
            'answered_rate_display' => PercentDisplay::display(
                $published->count() > 0 ? $answered->count() / $published->count() * 100 : null,
            ),
        ]);
    }

    /**
     * How fast the first answer arrives, over the challenges that got one.
     *
     * Its own set and its own verdict: a market can publish plenty and answer almost nothing, and a
     * speed figure resting on the two challenges that were answered would name both publishers.
     *
     * A duration is neither a rupee nor a percentage, so it is NOT wrapped in a display sentence
     * here — the frozen digit rule is satisfied by construction (no locale-aware formatter touches
     * these numbers) and the unit word ("तास" / "दिवस") belongs to the client's own localized
     * strings rather than to a Marathi sentence baked into the API.
     *
     * @param  Collection<int, SuchakMarketplaceChallenge>  $published
     * @param  array<int, array{count: int, first_requested_at: ?string}>  $proposalFacts
     * @return array<string, mixed>
     */
    private function speed(Collection $published, array $proposalFacts): array
    {
        $timed = $this->answeredChallenges($published, $proposalFacts)
            ->map(function (SuchakMarketplaceChallenge $challenge) use ($proposalFacts): ?array {
                $first = $proposalFacts[(int) $challenge->id]['first_requested_at'] ?? null;
                if ($first === null || $challenge->published_at === null) {
                    return null;
                }

                try {
                    $firstAt = Carbon::parse($first);
                } catch (\Throwable) {
                    return null;
                }

                // A first answer timestamped before its own challenge was published is not a fast
                // market, it is a broken row. Dropped rather than clamped to zero, which would drag
                // the median down and read as speed.
                if ($firstAt->lessThan($challenge->published_at)) {
                    return null;
                }

                return [
                    'publisher' => (int) $challenge->suchak_account_id,
                    'minutes' => (float) $challenge->published_at->diffInMinutes($firstAt),
                ];
            })
            ->filter()
            ->values();

        $medianMinutes = $this->median($timed->pluck('minutes')->all());

        return $this->block($timed->pluck('publisher')->all(), [
            'median_hours_to_first_proposal' => $medianMinutes === null
                ? null
                : (int) round($medianMinutes / 60),
            'median_days_to_first_proposal' => $medianMinutes === null
                ? null
                : number_format($medianMinutes / 1440, 1, '.', ''),
        ]);
    }

    /**
     * How many answers an answered challenge tends to draw.
     *
     * The median over ANSWERED challenges, not over all of them: mixing in the unanswered ones
     * would produce a median of 0 for most young markets, which is true and useless — the question
     * a publisher is asking here is "if it works, how much choice do I get", and the "if it works"
     * half is the `answered` block above.
     *
     * @param  Collection<int, SuchakMarketplaceChallenge>  $published
     * @param  array<int, array{count: int, first_requested_at: ?string}>  $proposalFacts
     * @return array<string, mixed>
     */
    private function depth(Collection $published, array $proposalFacts): array
    {
        $answered = $this->answeredChallenges($published, $proposalFacts);
        $counts = $answered
            ->map(static fn (SuchakMarketplaceChallenge $c): float => (float) ($proposalFacts[(int) $c->id]['count'] ?? 0))
            ->all();

        $median = $this->median(array_values($counts));

        return $this->block($this->publisherIds($answered), [
            'median_proposals_per_answered_challenge' => $median === null
                ? null
                : number_format($median, 1, '.', ''),
        ]);
    }

    /**
     * The typical DECLARED SHARE, as a percentage.
     *
     * The headline figure of this whole read, and the one §9 exists to permit: a Suchak deciding
     * what to declare is otherwise guessing against a market he cannot see. Only `custom_percent`
     * challenges are in the set — a fixed rupee declaration has no percentage, and inventing one by
     * dividing it by that customer's success fee would publish the success fee sideways.
     *
     * @param  Collection<int, SuchakMarketplaceChallenge>  $published
     * @return array<string, mixed>
     */
    private function declaredSharePercent(Collection $published): array
    {
        $rows = $published->filter(
            static fn (SuchakMarketplaceChallenge $c): bool => $c->declared_share_percent !== null,
        );

        $median = $this->median(
            $rows->map(static fn (SuchakMarketplaceChallenge $c): float => (float) $c->declared_share_percent)->all(),
        );

        return $this->block($this->publisherIds($rows), [
            'median_percent' => PercentDisplay::value($median, PercentDisplay::DECIMALS_DECLARED),
            'median_percent_display' => PercentDisplay::display($median, PercentDisplay::DECIMALS_DECLARED),
        ]);
    }

    /**
     * The typical share IN MONEY, per currency, each currency thresholded on its own.
     *
     * PER CURRENCY because a median across currencies is not a number — the same rule
     * {@see SuchakCrossSuchakObligationService::declarerRatio()} applies to its ratio, and the
     * marketplace has already been bitten once by a share rendered in the wrong money. The currency
     * is read through `declaredShareCurrency()`, the agreement's, never a value on the challenge
     * row.
     *
     * The figure itself is `declaredShareTotal()` — the ONE owner of "how much is this share worth",
     * so the median and the listing quote the same arithmetic. It is null for a percent declared
     * against a customer whose success fee is `as_wished` or `none`; those challenges simply have no
     * rupee observation and drop out rather than counting as zero.
     *
     * @param  Collection<int, SuchakMarketplaceChallenge>  $published
     * @return list<array<string, mixed>>
     */
    private function declaredShareValues(Collection $published): array
    {
        $byCurrency = [];

        foreach ($published as $challenge) {
            $total = $challenge->declaredShareTotal();
            if ($total === null) {
                continue;
            }

            $currency = $challenge->declaredShareCurrency();
            $byCurrency[$currency] ??= ['amounts' => [], 'publishers' => []];
            $byCurrency[$currency]['amounts'][] = (float) $total;
            $byCurrency[$currency]['publishers'][] = (int) $challenge->suchak_account_id;
        }

        ksort($byCurrency);

        $out = [];
        foreach ($byCurrency as $currency => $group) {
            $median = $this->median($group['amounts']);

            $out[] = ['currency' => $currency] + $this->block($group['publishers'], [
                // Preformatted server-side, Latin digits and Indian grouping, so no client ever
                // re-derives money. MoneyFormat is the one money formatter.
                'median_share_display' => MoneyFormat::amount($median, $currency),
            ]);
        }

        return $out;
    }

    /**
     * How often publishing ends in a marriage.
     *
     * The denominator is every published challenge in the window — including the ones still open,
     * which is deliberate and is why the key is a RATE and not a conversion of completed ones. A
     * Suchak deciding whether to publish is choosing against everything that has been published,
     * not against the subset that has finished.
     *
     * @param  Collection<int, SuchakMarketplaceChallenge>  $published
     * @param  list<int>  $marriedChallengeIds
     * @return array<string, mixed>
     */
    private function marriageOutcomes(Collection $published, array $marriedChallengeIds): array
    {
        $married = $published
            ->filter(static fn (SuchakMarketplaceChallenge $c): bool => in_array((int) $c->id, $marriedChallengeIds, true))
            ->count();

        return $this->block($this->publisherIds($published), [
            'challenges_with_recorded_marriage' => $married,
            'marriage_rate_percent' => PercentDisplay::rate($married, $published->count()),
            'marriage_rate_display' => PercentDisplay::display(
                $published->count() > 0 ? $married / $published->count() * 100 : null,
            ),
        ]);
    }

    // ── The rule ──────────────────────────────────────────────────────────────────────────────

    /**
     * ONE block, ONE population verdict — the enforcement point for rule 2 on the class docblock.
     *
     * Every figure in this service goes through here and there is no path around it. The keys are
     * kept and the VALUES are nulled when the set is too thin, rather than the block disappearing:
     * a client with a stable shape can say "too few" in its own words, while a vanishing key reads
     * as a server error and invites a fallback that computes the figure itself.
     *
     * `observations` and `publishers` survive the withholding on purpose. They are counts of
     * participation, not terms — they say how many people are in the market, never what any of them
     * agreed to — and without them "withheld" is indistinguishable from "empty", which is exactly
     * the confusion D13 forbids for a new Suchak's card.
     *
     * @param  list<int>  $publisherIds  one entry per observation; duplicates are the point
     * @param  array<string, mixed>  $figures
     * @return array<string, mixed>
     */
    private function block(array $publisherIds, array $figures): array
    {
        $observations = count($publisherIds);
        $publishers = count(array_unique($publisherIds));
        $withheld = $observations < self::MIN_OBSERVATIONS || $publishers < self::MIN_DISTINCT_PUBLISHERS;

        if ($withheld) {
            $figures = array_map(static fn (): null => null, $figures);
        }

        return [
            'observations' => $observations,
            'publishers' => $publishers,
            'is_withheld' => $withheld,
            'withheld_reason' => $withheld ? self::REFUSAL_TOO_THIN : null,
        ] + $figures;
    }

    /**
     * The challenges that drew at least one proposal.
     *
     * @param  Collection<int, SuchakMarketplaceChallenge>  $published
     * @param  array<int, array{count: int, first_requested_at: ?string}>  $proposalFacts
     * @return Collection<int, SuchakMarketplaceChallenge>
     */
    private function answeredChallenges(Collection $published, array $proposalFacts): Collection
    {
        return $published->filter(
            static fn (SuchakMarketplaceChallenge $c): bool => ($proposalFacts[(int) $c->id]['count'] ?? 0) > 0,
        )->values();
    }

    /**
     * @param  Collection<int, SuchakMarketplaceChallenge>  $challenges
     * @return list<int>
     */
    private function publisherIds(Collection $challenges): array
    {
        return $challenges
            ->map(static fn (SuchakMarketplaceChallenge $c): int => (int) $c->suchak_account_id)
            ->values()
            ->all();
    }

    /**
     * The median, and deliberately not the mean.
     *
     * A mean is invertible: over five values, a reader who knows four recovers the fifth exactly.
     * A median is not, and on an even count it is the average of the two middles — a figure nobody
     * declared, which is the correct thing to publish about other people's private terms.
     *
     * @param  list<float>  $values
     */
    private function median(array $values): ?float
    {
        $values = array_values(array_filter($values, static fn ($v): bool => is_numeric($v)));
        $count = count($values);

        if ($count === 0) {
            return null;
        }

        sort($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? (float) $values[$middle]
            : ((float) $values[$middle - 1] + (float) $values[$middle]) / 2;
    }
}
