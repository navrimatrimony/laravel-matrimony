<?php

namespace App\Modules\Suchak\Services;

use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakCollaborationStageEvent;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakCustomerPlan;
use App\Support\MoneyFormat;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * THE 12-MONTH ANTI-CIRCUMVENTION CLAUSE (blueprint D11, D21, 9a A5/A6/A13).
 *
 * The rule, in one sentence:
 *
 *   When the FAMILY records `viewed` on a candidate proposed through their Suchak, a marriage to
 *   that candidate within 12 MONTHS of that view still owes that Suchak the success fee — however
 *   the later contact happened, and even if the engagement, the agreement or the whole relationship
 *   ended in the meantime — unless the family declared at view time that they already knew that
 *   family.
 *
 * Where each half of that sentence comes from:
 *
 *  - "from `viewed`, never from merely suggested"     D11, and CLAUSE_ANCHOR_STAGE binds to it
 *  - "12 months"                                      D11
 *  - "even if the relationship ended"                 D21 — "Leaving does not void the clause.
 *                                                     Ending the engagement stops future fees, but
 *                                                     a marriage within 12 months to a profile the
 *                                                     customer viewed still owes the success fee"
 *  - "however the later contact happened"             9a A13 — the family going back to that family
 *                                                     directly is NOT chargeable as contact; what
 *                                                     protects the Suchak on the real prize is this
 *                                                     clause
 *  - "unless they already knew them"                  9a A6
 *  - the monthly cap below                            9a A5
 *
 * ── WHAT THIS SERVICE IS, AND WHAT IT IS NOT ─────────────────────────────────────────────────
 *
 * It is the RECORD and the QUESTION. Whether the success fee is actually collected — the marriage
 * outcome, success attribution, owed-vs-paid — is Phase 4 (blueprint 11), and nothing here moves,
 * schedules, holds or invoices a rupee. `successFee()` reports the frozen figure so the answer to
 * "is a share owed" can say how much; it does not create a debt.
 *
 * It is also NOT a timer. There is no queued job and no scheduled sweep — production may not run
 * `schedule:run` at all, and the notifications and governance queues have had no worker since
 * 2026-06-17. Lapse is computed on the read path from `claimed_at`, the way
 * SuchakRequestPipelineService and SuchakMarketplaceChallengeService already hedge. A clause whose
 * expiry depended on a cron that does not fire would keep binding forever.
 *
 * ── WHY THE QUESTION IS KEYED ON THE CUSTOMER CONTEXT ────────────────────────────────────────
 *
 * D21 is the whole reason. The clause outlives the engagement AND the agreement revision, so it
 * cannot be keyed on either: a superseded, expired or declined agreement's `viewed` row still
 * binds. `suchak_customer_contexts` is the one object that is stable across every revision — it is
 * what `suchak_customer_agreements.customer_context_id` and `suchak_customer_portal_links.
 * customer_context_id` both point at, and it names both halves of "a customer": the family's
 * candidate (`candidate_matrimony_profile_id`) and the Suchak who holds them
 * (`suchak_account_id`).
 */
class SuchakTwelveMonthClauseService
{
    /**
     * D11's own words. Read from the ladder, never re-typed: SuchakCollaborationStageEvent
     * declares which rung the clause attaches to, and this service owns everything after that.
     */
    public const ANCHOR_STAGE = SuchakCollaborationStageEvent::CLAUSE_ANCHOR_STAGE;

    /** D11 / D21. The clause runs this many months from the `viewed` row's `claimed_at`. */
    public const BINDING_MONTHS = 12;

    /**
     * 9a A5 — "Dumping profiles so any later marriage triggers the 12-month clause" is closed by
     * "D11 — the clause binds from Viewed, plus a monthly cap on binding views".
     *
     * Views past this many in one CALENDAR MONTH, for one customer, are still recorded — the family
     * really did look — but they do not bind. Counting is per customer rather than per helper on
     * purpose: the attack works the same whether one Suchak dumps sixty profiles or six dump ten
     * each, and the obligation is owed by one family either way.
     *
     * ⚠ THE BLUEPRINT DOES NOT FIX THIS NUMBER. A5 says "a monthly cap" and stops there, unlike
     * 7.2's stop-loss which names "2 claims, or ₹5,000" outright. 10 is a value chosen to be far
     * above what a family searching in earnest does in a month and far below a dump; it is the one
     * product number in this file and it is deliberately a single constant so the product owner can
     * move it in one line. Releasing a genuine obligation is the expensive direction of a wrong
     * value, which is why it is set generously.
     */
    public const BINDING_VIEWS_PER_CALENDAR_MONTH = 10;

    /** 9a A6 — the family said at view time that they already knew this family. */
    public const RELEASE_PRIOR_ACQUAINTANCE = 'prior_acquaintance';

    /** 9a A5 — this view sat past the monthly cap, so it never bound. */
    public const RELEASE_MONTHLY_CAP = 'monthly_cap';

    /** D11 — the 12 months ran out. The record stays; the binding does not. */
    public const RELEASE_LAPSED = 'lapsed';

    /** No `viewed` row exists for this candidate at all, so nothing ever bound. */
    public const RELEASE_NEVER_VIEWED = 'never_viewed';

    /**
     * Every `viewed` row this customer has, in ladder-anchor order, each with its verdict.
     *
     * D21 IS ENFORCED BY AN ABSENCE, so it is stated out loud: there is deliberately NO filter on
     * `suchak_collaboration_requests.status` and NO filter on the agreement's `terms_status`. A
     * rejected, expired or cancelled engagement, and a superseded or declined agreement revision,
     * all keep their binding — that is exactly what "leaving does not void the clause" means. Adding
     * either filter here would delete D21 silently, and no test outside this file would notice.
     *
     * @return list<array<string, mixed>>
     */
    public function bindingsForCustomer(SuchakCustomerContext $customerContext, ?CarbonInterface $asOf = null): array
    {
        $asOf = $asOf === null ? Carbon::now() : Carbon::parse($asOf);

        $events = SuchakCollaborationStageEvent::query()
            ->where('stage_key', self::ANCHOR_STAGE)
            ->whereNotNull(SuchakCollaborationStageEvent::OWNER_COLUMN_COLLABORATION_REQUEST)
            ->whereHas(
                'collaborationRequest.commissionAgreement.customerAgreement',
                fn (Builder $query) => $query
                    ->where('customer_context_id', $customerContext->id)
                    ->where('suchak_account_id', $customerContext->suchak_account_id),
            )
            ->with([
                'collaborationRequest.commissionAgreement.customerAgreement.servicePackage',
                'collaborationRequest.requestingMatrimonyProfile',
                'collaborationRequest.targetMatrimonyProfile',
            ])
            // The cap is an ORDINAL within a calendar month, so the order it is computed in has to
            // be the order the acts happened in. `id` breaks a same-second tie deterministically —
            // without it two views in one second could swap places between two reads and move the
            // cap boundary under a live obligation.
            ->orderBy('claimed_at')
            ->orderBy('id')
            ->get();

        /** @var array<string, int> $bindingViewsInMonth */
        $bindingViewsInMonth = [];
        $rows = [];

        foreach ($events as $event) {
            $collaboration = $event->collaborationRequest;
            if (! $collaboration instanceof SuchakCollaborationRequest) {
                continue;
            }

            $viewedAt = $event->claimed_at === null ? null : Carbon::instance($event->claimed_at);
            if ($viewedAt === null) {
                continue;
            }

            $bindsUntil = $viewedAt->copy()->addMonths(self::BINDING_MONTHS);
            $releaseReason = null;
            $ordinalInMonth = null;

            if ((bool) $event->prior_acquaintance_declared) {
                // A6 first, and it does NOT consume a cap slot. A view that never bound must not
                // push a genuine one over the A5 line — that would let the family's own honesty
                // release somebody else's obligation.
                $releaseReason = self::RELEASE_PRIOR_ACQUAINTANCE;
            } else {
                $monthKey = $viewedAt->format('Y-m');
                $ordinalInMonth = ($bindingViewsInMonth[$monthKey] ?? 0) + 1;
                $bindingViewsInMonth[$monthKey] = $ordinalInMonth;

                if ($ordinalInMonth > self::BINDING_VIEWS_PER_CALENDAR_MONTH) {
                    $releaseReason = self::RELEASE_MONTHLY_CAP;
                } elseif ($asOf->greaterThan($bindsUntil)) {
                    $releaseReason = self::RELEASE_LAPSED;
                }
            }

            $rows[] = $this->row(
                $customerContext,
                $collaboration,
                $viewedAt,
                $bindsUntil,
                $releaseReason,
                $ordinalInMonth,
                $asOf,
            );
        }

        return $rows;
    }

    /**
     * THE QUESTION, for one pair: given this customer and this candidate, is a share owed under the
     * clause — and until when?
     *
     * Always answers. A candidate the family never viewed comes back `binds => false` with
     * `release_reason => never_viewed` rather than as an empty result, because "no" and "I have no
     * record" are the same sentence to a caller who only checks for null, and this is the read a
     * Phase 4 marriage will hang a ₹1,00,000 attribution off.
     *
     * When the same candidate was viewed through more than one engagement (two helpers can propose
     * the same person), the LONGEST live binding wins — a second view cannot shorten a clause the
     * first one already started, and a release on one engagement cannot cancel a binding on
     * another.
     *
     * @return array<string, mixed>
     */
    public function verdictFor(
        SuchakCustomerContext $customerContext,
        int $candidateMatrimonyProfileId,
        ?CarbonInterface $asOf = null,
    ): array {
        $asOf = $asOf === null ? Carbon::now() : Carbon::parse($asOf);

        $matches = array_values(array_filter(
            $this->bindingsForCustomer($customerContext, $asOf),
            static fn (array $row): bool => $row['candidate_matrimony_profile_id'] === $candidateMatrimonyProfileId,
        ));

        if ($matches === []) {
            return [
                'customer_context_id' => (int) $customerContext->id,
                'candidate_matrimony_profile_id' => $candidateMatrimonyProfileId,
                'binds' => false,
                'release_reason' => self::RELEASE_NEVER_VIEWED,
                'viewed_at' => null,
                'binds_until' => null,
                'days_remaining' => 0,
                'owed_to_suchak_account_id' => (int) $customerContext->suchak_account_id,
                'collaboration_request_id' => null,
                'customer_agreement_id' => null,
                'success_fee' => null,
                'success_fee_mode' => null,
                'binding_view_ordinal_in_month' => null,
            ];
        }

        $binding = null;
        foreach ($matches as $row) {
            if ($row['binds'] !== true) {
                continue;
            }
            if ($binding === null
                || Carbon::parse($row['binds_until'])->greaterThan(Carbon::parse($binding['binds_until']))) {
                $binding = $row;
            }
        }

        // Nothing live: report the most recent view and the reason it does not bind, which is what
        // a dispute a year later actually needs to read.
        return $binding ?? $matches[count($matches) - 1];
    }

    /**
     * The plain yes/no, for callers that only branch on it.
     */
    public function isShareOwed(
        SuchakCustomerContext $customerContext,
        int $candidateMatrimonyProfileId,
        ?CarbonInterface $asOf = null,
    ): bool {
        return $this->verdictFor($customerContext, $candidateMatrimonyProfileId, $asOf)['binds'] === true;
    }

    /**
     * The same bindings keyed by engagement — what the CUSTOMER's own portal page needs, so it can
     * print "binds until <date>" beside the proposal the family is looking at.
     *
     * Computed from the one list rather than per engagement, because the A5 cap is a fact about the
     * customer's whole month and cannot be evaluated one row at a time.
     *
     * @return array<int, array<string, mixed>>
     */
    public function bindingsByEngagement(SuchakCustomerContext $customerContext, ?CarbonInterface $asOf = null): array
    {
        $keyed = [];
        foreach ($this->bindingsForCustomer($customerContext, $asOf) as $row) {
            $keyed[(int) $row['collaboration_request_id']] = $row;
        }

        return $keyed;
    }

    /**
     * The clause's own terms, so a caller (or a screen) can state them without re-typing them.
     *
     * @return array<string, mixed>
     */
    public function terms(): array
    {
        return [
            'anchor_stage' => self::ANCHOR_STAGE,
            'anchor_stage_label' => SuchakCollaborationStageEvent::stageLabel(self::ANCHOR_STAGE),
            'binding_months' => self::BINDING_MONTHS,
            'binding_views_per_calendar_month' => self::BINDING_VIEWS_PER_CALENDAR_MONTH,
            // Stated because it is the half of D21 a reader will not assume.
            'survives_engagement_end' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(
        SuchakCustomerContext $customerContext,
        SuchakCollaborationRequest $collaboration,
        Carbon $viewedAt,
        Carbon $bindsUntil,
        ?string $releaseReason,
        ?int $ordinalInMonth,
        Carbon $asOf,
    ): array {
        $agreement = $collaboration->commissionAgreement?->customerAgreement;
        $candidateProfile = $this->proposedCandidate($customerContext, $collaboration);

        return [
            'customer_context_id' => (int) $customerContext->id,
            'candidate_matrimony_profile_id' => $candidateProfile === null ? null : (int) $candidateProfile['id'],
            'candidate_name' => $candidateProfile['full_name'] ?? null,
            'collaboration_request_id' => (int) $collaboration->id,
            'customer_agreement_id' => $agreement === null ? null : (int) $agreement->id,
            // WHO the share is owed to. The customer context names the Suchak who holds the family,
            // and D21 makes that the answer even after the engagement is gone — reading it off the
            // engagement's role label would return nothing once the engagement lapsed.
            'owed_to_suchak_account_id' => (int) $customerContext->suchak_account_id,
            'viewed_at' => $viewedAt->toIso8601String(),
            'binds_until' => $bindsUntil->toIso8601String(),
            'binds' => $releaseReason === null,
            'release_reason' => $releaseReason,
            'days_remaining' => $releaseReason === null ? (int) max(0, (int) $asOf->diffInDays($bindsUntil, false)) : 0,
            'binding_view_ordinal_in_month' => $ordinalInMonth,
            // The frozen figure the clause is ABOUT. Reporting it is not collecting it — the
            // marriage outcome and the owed-vs-paid ledger are Phase 4.
            'success_fee' => $this->successFee($agreement),
            'success_fee_mode' => $agreement?->servicePackage?->post_marriage_fee_mode,
        ];
    }

    /**
     * The candidate the clause is ABOUT: the OTHER family's profile on this engagement.
     *
     * The engagement stores its pair by DIRECTION (requesting / target) and in the marketplace the
     * responder is the requester (blueprint 5.2), so neither column can be assumed to be the
     * customer's own. The ROLE label answers it — `customer_owner_side` names the slot holding the
     * family, so the other slot holds the person proposed to them. That is one recorded fact read
     * once, not a position guessed here.
     *
     * IT USED TO BE GUESSED, AND THAT IS THE BUG THIS METHOD IS NAMED IN. The old form asked "is
     * the requesting profile the customer's own? then the answer is the target, else the
     * requesting" — which quietly returns the REQUESTING profile whenever the customer's candidate
     * is on neither side. A twelve-month success fee then bound to a person the family had never
     * seen. SuchakCollaborationService::assertCustomerCandidateSitsOnSide() now refuses to create
     * such an engagement at either end; this returns NULL for any that already exists, so a legacy
     * row reports a binding with no candidate — visible, and matching no candidate in verdictFor()
     * — rather than naming a stranger.
     *
     * @return array{id: int, full_name: string|null}|null
     */
    private function proposedCandidate(
        SuchakCustomerContext $customerContext,
        SuchakCollaborationRequest $collaboration,
    ): ?array {
        $ownCandidateId = $customerContext->candidate_matrimony_profile_id;

        if ($ownCandidateId === null
            || $collaboration->customerOwnerMatrimonyProfileId() !== (int) $ownCandidateId) {
            return null;
        }

        $profile = $collaboration->helpingMatrimonyProfileId() === (int) $collaboration->requesting_matrimony_profile_id
            ? $collaboration->requestingMatrimonyProfile
            : $collaboration->targetMatrimonyProfile;

        if ($profile === null) {
            return null;
        }

        return ['id' => (int) $profile->id, 'full_name' => $profile->full_name];
    }

    /**
     * The success fee frozen for this customer, as text.
     *
     * `MODE_NONE` already encodes D5 ("a Suchak who declared nothing owes nothing") on the customer
     * side too: a package with no success fee has no success fee to owe, and printing ₹0 for it
     * would read as a quoted price of zero rather than as "this was never part of the deal".
     */
    private function successFee(?SuchakCustomerAgreement $agreement): ?string
    {
        $package = $agreement?->servicePackage;
        if ($package === null || $package->post_marriage_fee_mode === SuchakCustomerPlan::MODE_NONE) {
            return null;
        }

        return MoneyFormat::amount($package->post_marriage_fee_amount, (string) ($package->currency ?? 'INR'));
    }
}
